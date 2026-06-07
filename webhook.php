<?php
/**
 * Monnify Webhook Handler — Adildata
 * URL: https://api.adildata.com.ng/webhook.php
 *
 * FIX: $walletOk previously captured mysqli_affected_rows() AFTER the history
 *      INSERT ran, so it reflected the INSERT result, not the wallet UPDATE.
 *      Now we capture affected_rows immediately after the UPDATE query.
 */

$raw_request   = file_get_contents('php://input');
$request_array = json_decode($raw_request, true);

// ── File logging (always, before anything else) ───────────────────────────────
$log_file = __DIR__ . '/logs/webhook_monnify.log';
@file_put_contents($log_file,
    date('Y-m-d H:i:s')
    . ' | METHOD=' . $_SERVER['REQUEST_METHOD']
    . ' | SIG=' . substr($_SERVER['HTTP_MONNIFY_SIGNATURE'] ?? 'NONE', 0, 16) . '...'
    . ' | EVENT=' . ($request_array['eventType'] ?? 'UNKNOWN')
    . ' | EMAIL=' . ($request_array['eventData']['customer']['email'] ?? 'NONE')
    . ' | AMT=' . ($request_array['eventData']['amountPaid'] ?? '0')
    . ' | REF=' . ($request_array['eventData']['transactionReference'] ?? 'NONE')
    . ' | BODY=' . substr($raw_request, 0, 400)
    . PHP_EOL, FILE_APPEND | LOCK_EX);

// ── DB connection ─────────────────────────────────────────────────────────────
$connect = mysqli_connect('localhost', 'adiliqgs_adildata', 'adildata2026', 'adiliqgs_adildata', 3306);
include_once __DIR__ . '/fcm_helper.php';
if (!$connect) {
    http_response_code(500);
    @file_put_contents($log_file, date('Y-m-d H:i:s') . " | DB_CONNECT_FAILED\n", FILE_APPEND | LOCK_EX);
    die(json_encode(['status' => 'DB_ERROR']));
}

// ── Load Monnify secret from DB settings ─────────────────────────────────────
$sk_row    = mysqli_query($connect, "SELECT setting_value FROM edutech_settings WHERE setting_key = 'MONNIFY_API_SECRET' LIMIT 1");
$SECRET_KEY = '881J3RXH6Z6LDVJWG76P1YHW8VCECAE5';
if ($sk_row && mysqli_num_rows($sk_row) > 0) {
    $sk_data = mysqli_fetch_assoc($sk_row);
    if (!empty($sk_data['setting_value'])) $SECRET_KEY = $sk_data['setting_value'];
}

// ── Verify Monnify signature ──────────────────────────────────────────────────
$signature    = $_SERVER['HTTP_MONNIFY_SIGNATURE'] ?? '';
$computedHash = hash_hmac('sha512', $raw_request, $SECRET_KEY);

@file_put_contents($log_file,
    date('Y-m-d H:i:s') . " | COMPUTED=" . substr($computedHash,0,20) . "... | RECEIVED=" . substr($signature,0,20) . "...\n",
    FILE_APPEND | LOCK_EX);

if (empty($signature) || !hash_equals($computedHash, $signature)) {
    http_response_code(401);
    die(json_encode(['status' => 'INVALID_SIGNATURE']));
}

// ── Extract event fields ──────────────────────────────────────────────────────
$event_type = $request_array['eventType'] ?? '';
$email      = $request_array['eventData']['customer']['email'] ?? '';
$amt_paid   = floatval($request_array['eventData']['amountPaid'] ?? 0);
$reference  = $request_array['eventData']['transactionReference'] ?? '';

// Credit the FULL amount paid — no platform charge deduction
$amount_paid = $amt_paid;

if ($event_type === 'SUCCESSFUL_TRANSACTION' && !empty($email) && !empty($reference)) {

    // ── Duplicate protection ──────────────────────────────────────────────────
    $refSafe = mysqli_real_escape_string($connect, $reference);
    $exist   = mysqli_query($connect, "SELECT id FROM wallet_history_tbl WHERE trans_id = '$refSafe' LIMIT 1");
    if (mysqli_num_rows($exist) > 0) {
        @file_put_contents($log_file, date('Y-m-d H:i:s') . " | ALREADY_PROCESSED ref=$reference\n", FILE_APPEND | LOCK_EX);
        echo json_encode(['status' => 'ALREADY_PROCESSED']);
        exit;
    }

    $emailSafe = mysqli_real_escape_string($connect, $email);

    // ── Get or create wallet record ───────────────────────────────────────────
    $bal_row         = mysqli_query($connect, "SELECT balance FROM wallet_tbl WHERE user_id = '$emailSafe' LIMIT 1");
    $current_balance = 0;
    if ($bal_row && mysqli_num_rows($bal_row) > 0) {
        $current_balance = floatval(mysqli_fetch_assoc($bal_row)['balance']);
    } else {
        mysqli_query($connect, "INSERT INTO wallet_tbl(user_id, balance, status) VALUES('$emailSafe', 0, 1)");
    }

    $amount_to_add = floatval($amount_paid);
    $new_balance   = $current_balance + $amount_to_add;

    // ── Update wallet balance ─────────────────────────────────────────────────
    $updateWallet = mysqli_query($connect,
        "UPDATE wallet_tbl SET balance = balance + $amount_to_add, last_transanction = NOW() WHERE user_id = '$emailSafe'"
    );
    // IMPORTANT: capture affected_rows IMMEDIATELY after the UPDATE,
    // before any other query runs (previously this was checked after insertHistory).
    $walletAffected = ($updateWallet !== false) ? mysqli_affected_rows($connect) : -1;
    $walletOk       = ($updateWallet !== false) && ($walletAffected >= 0);

    // ── Insert wallet history ─────────────────────────────────────────────────
    $insertHistory = mysqli_query($connect,
        "INSERT INTO wallet_history_tbl
         (trans_id, email, trans_amount, available_balance, wallet_status, trans_date, status, super_admin)
         VALUES
         ('$refSafe', '$emailSafe', $amount_to_add, $new_balance, 'credit', NOW(), 1, 1)"
    );
    $historyOk = (bool)$insertHistory;

    // ── Mark payment history ──────────────────────────────────────────────────
    mysqli_query($connect,
        "UPDATE payment_history_tbl SET status = 1, reason = 'monnify_success' WHERE trans_id = '$refSafe' LIMIT 1"
    );

    @file_put_contents($log_file,
        date('Y-m-d H:i:s')
        . " | PROCESSED email=$email amt_paid=$amt_paid credited=$amount_to_add"
        . " wallet_ok=" . ($walletOk ? 'Y' : 'N')
        . " wallet_affected=$walletAffected"
        . " history_ok=" . ($historyOk ? 'Y' : 'N')
        . " new_balance=$new_balance\n",
        FILE_APPEND | LOCK_EX);

    if ($walletOk && $historyOk) {
        // Send push notification to user's device(s)
        $pushTokensQ = mysqli_query($connect, "SELECT fcm_token FROM device_tokens WHERE email='$emailSafe'");
        if ($pushTokensQ && mysqli_num_rows($pushTokensQ) > 0) {
            $pushTokens = [];
            while ($pushRow = mysqli_fetch_assoc($pushTokensQ)) { $pushTokens[] = $pushRow['fcm_token']; }
            $fmtAmt = '\u20a6' . number_format($amount_to_add, 2);
            $fmtBal = '\u20a6' . number_format($new_balance, 2);
            fcm_send_to_tokens($pushTokens, '\ud83d\udcb0 Wallet Funded!',
                "Your wallet has been credited with {$fmtAmt}. New balance: {$fmtBal}.",
                ['type' => 'wallet_credit', 'amount' => (string)$amount_to_add,
                 'new_balance' => (string)$new_balance, 'reference' => $reference, 'screen' => 'Wallet']);
            @file_put_contents($log_file, date('Y-m-d H:i:s') . " | PUSH_SENT tokens=" . count($pushTokens) . "\n", FILE_APPEND | LOCK_EX);
        }
        echo json_encode(['status' => 'OK', 'credited' => $amount_to_add, 'new_balance' => $new_balance]);
    } else {
        http_response_code(500);
        echo json_encode([
            'status'           => 'DB_WRITE_ERROR',
            'wallet_ok'        => $walletOk,
            'wallet_affected'  => $walletAffected,
            'history_ok'       => $historyOk,
        ]);
    }

} elseif (in_array($event_type, ['FAILED_TRANSACTION', 'REVERSED_TRANSACTION', 'EXPIRED_TRANSACTION'])) {
    $refSafe = mysqli_real_escape_string($connect, $reference);
    mysqli_query($connect,
        "UPDATE payment_history_tbl SET status = 2, reason = '" . strtolower($event_type) . "' WHERE trans_id = '$refSafe' LIMIT 1"
    );
    @file_put_contents($log_file, date('Y-m-d H:i:s') . " | $event_type ref=$reference\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['status' => 'OK']);

} else {
    @file_put_contents($log_file, date('Y-m-d H:i:s') . " | IGNORED event=$event_type\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['status' => 'IGNORED', 'event' => $event_type]);
}

mysqli_close($connect);
?>
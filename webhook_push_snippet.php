<?php
/**
 * WEBHOOK UPDATE — Add this to your existing webhook.php
 *
 * After the wallet is credited successfully (inside the $walletOk && $historyOk block),
 * add the push notification call below.
 *
 * In your webhook.php, find this block (around line 90):
 *
 *   if ($walletOk && $historyOk) {
 *       echo json_encode(['status' => 'OK', ...]);
 *   }
 *
 * Replace it with the version below:
 */

// ── At the top of webhook.php, add this include (after conn.php include) ──────
// include_once __DIR__ . '/fcm_helper.php';

// ── Replace the success response block with this ──────────────────────────────
if ($walletOk && $historyOk) {
    // ── Send push notification to user's device(s) ────────────────────────────
    $tokensQ = mysqli_query($connect,
        "SELECT fcm_token FROM device_tokens WHERE email='$emailSafe'"
    );
    if ($tokensQ && mysqli_num_rows($tokensQ) > 0) {
        $tokens = [];
        while ($row = mysqli_fetch_assoc($tokensQ)) {
            $tokens[] = $row['fcm_token'];
        }
        $formattedAmt = '₦' . number_format($amount_to_add, 2);
        $newBal       = '₦' . number_format($new_balance, 2);

        fcm_send_to_tokens(
            $tokens,
            '💰 Wallet Funded!',
            "Your wallet has been credited with {$formattedAmt}. New balance: {$newBal}.",
            [
                'type'        => 'wallet_credit',
                'amount'      => (string)$amount_to_add,
                'new_balance' => (string)$new_balance,
                'reference'   => $reference,
                'screen'      => 'Wallet',   // Expo app navigates to Wallet screen
            ]
        );

        @file_put_contents($log_file,
            date('Y-m-d H:i:s') . " | PUSH_SENT tokens=" . count($tokens) . "\n",
            FILE_APPEND | LOCK_EX);
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

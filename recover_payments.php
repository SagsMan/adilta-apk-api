<?php
/**
 * recover_payments.php — Admin: Recover missed Monnify wallet credits for ALL users
 * URL: https://api.adildata.com.ng/recover_payments.php
 *
 * Modes:
 *   ?mode=status              — Show all users, balances, discrepancies
 *   ?mode=verify_ref&ref=MNFY|...|... — Verify & credit one transaction reference
 *   ?mode=auto_recover        — Auto-credit using stored monnify_account_ref (new accounts)
 *   ?mode=scan_discrepancies  — Check totalAmount vs wallet for all users with accountRef
 *   ?mode=set_account_ref&email=...&ref=... — Manually store an accountRef for a user
 *
 * Auth: Pass ?pass=AdilRecov3r2026! or X-Admin-Pass header
 */

$ADMIN_PASS = 'AdilRecov3r2026!';
$pass = $_GET['pass'] ?? $_POST['pass'] ?? ($_SERVER['HTTP_X_ADMIN_PASS'] ?? '');
if ($pass !== $ADMIN_PASS) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized. Add ?pass=AdilRecov3r2026! to the URL.']));
}

header('Content-Type: application/json');
set_time_limit(120);

$conn = mysqli_connect('localhost', 'adiliqgs_adildata', 'adildata2026', 'adiliqgs_adildata');
if (!$conn) die(json_encode(['error' => 'DB connect failed']));

// ── Monnify helpers ────────────────────────────────────────────────────────────
function monnify_auth($apiKey, $apiSecret) {
    $ch = curl_init('https://api.monnify.com/api/v1/auth/login');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . base64_encode("$apiKey:$apiSecret")],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true)['responseBody']['accessToken'] ?? null;
}

function monnify_verify_ref($token, $ref) {
    $enc = urlencode($ref);
    $ch = curl_init("https://api.monnify.com/api/v2/merchant/transactions/query?transactionReference=$enc");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true);
}

function monnify_get_account($token, $accountRef) {
    $ch = curl_init("https://api.monnify.com/api/v2/bank-transfer/reserved-accounts/$accountRef");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true);
}

function credit_wallet($conn, $email, $amount, $ref, $paidOn) {
    $email  = mysqli_real_escape_string($conn, $email);
    $ref    = mysqli_real_escape_string($conn, $ref);
    $amount = floatval($amount);
    $ex = mysqli_query($conn, "SELECT id FROM wallet_history_tbl WHERE trans_id='$ref' LIMIT 1");
    if (mysqli_num_rows($ex) > 0) return ['status' => 'ALREADY_CREDITED'];

    $bq  = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$email' LIMIT 1");
    $cur = ($bq && mysqli_num_rows($bq) > 0) ? floatval(mysqli_fetch_assoc($bq)['balance']) : 0;
    if (mysqli_num_rows($bq) === 0) mysqli_query($conn, "INSERT INTO wallet_tbl(user_id,balance,status) VALUES('$email',0,1)");
    $newBal = $cur + $amount;
    $paidOn = mysqli_real_escape_string($conn, $paidOn ?: date('Y-m-d H:i:s'));

    $upd = mysqli_query($conn, "UPDATE wallet_tbl SET balance=balance+$amount, last_transanction=NOW() WHERE user_id='$email'");
    $aff = mysqli_affected_rows($conn);
    $ins = mysqli_query($conn, "INSERT INTO wallet_history_tbl (trans_id,email,trans_amount,available_balance,wallet_status,trans_date,status,super_admin) VALUES ('$ref','$email',$amount,$newBal,'credit','$paidOn',1,1)");
    return ['status' => ($aff >= 0 && $ins) ? 'CREDITED' : 'DB_ERROR', 'credited' => $amount, 'new_balance' => $newBal];
}

// ── Load Monnify creds ─────────────────────────────────────────────────────────
$sk = mysqli_query($conn, "SELECT setting_key,setting_value FROM edutech_settings WHERE setting_key LIKE 'MONNIFY_%'");
$cfg = [];
while ($r = mysqli_fetch_assoc($sk)) $cfg[$r['setting_key']] = $r['setting_value'];
$apiKey    = $cfg['MONNIFY_API_KEY']    ?? 'MK_PROD_3JMNVXHKW3';
$apiSecret = $cfg['MONNIFY_API_SECRET'] ?? '881J3RXH6Z6LDVJWG76P1YHW8VCECAE5';

$mode = $_GET['mode'] ?? $_POST['mode'] ?? 'status';

// ═══════════════════════════════════════════════════════════════════════════
// MODE: status
// ═══════════════════════════════════════════════════════════════════════════
if ($mode === 'status') {
    $q = mysqli_query($conn,
        "SELECT u.id, u.email, u.monnify_account_ref, u.monnify_account_details,
                COALESCE(w.balance,0) AS wallet_balance,
                (SELECT COUNT(*) FROM wallet_history_tbl WHERE email=u.email AND wallet_status='credit' AND status=1) AS credit_count,
                (SELECT COALESCE(SUM(trans_amount),0) FROM wallet_history_tbl WHERE email=u.email AND wallet_status='credit' AND status=1) AS total_credited
           FROM users_tbl u
           LEFT JOIN wallet_tbl w ON w.user_id = u.email
          WHERE u.monnify_account_details IS NOT NULL AND u.monnify_account_details != ''
          ORDER BY u.id ASC");
    $users = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $parts = explode(' - ', $r['monnify_account_details'] ?? '');
        $users[] = [
            'id'              => $r['id'],
            'email'           => $r['email'],
            'account_number'  => trim($parts[1] ?? ''),
            'account_ref'     => $r['monnify_account_ref'] ?: null,
            'wallet_balance'  => floatval($r['wallet_balance']),
            'credit_count'    => intval($r['credit_count']),
            'total_credited'  => floatval($r['total_credited']),
            'can_auto_recover'=> !empty($r['monnify_account_ref']),
        ];
    }
    echo json_encode(['status' => 'OK', 'total_users' => count($users), 'users' => $users,
        'instructions' => [
            'verify_one'   => '?mode=verify_ref&ref=MNFY|25|YYYYMMDDHHMMSS|NNNNNN',
            'scan_gaps'    => '?mode=scan_discrepancies (for users with accountRef stored)',
            'store_ref'    => '?mode=set_account_ref&email=X&ref=MONNIFY_ACCOUNT_REF',
            'auto_recover' => '?mode=auto_recover (for users with accountRef stored)',
        ]], JSON_PRETTY_PRINT);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// MODE: verify_ref — verify + credit a single MNFY|... reference
// ═══════════════════════════════════════════════════════════════════════════
if ($mode === 'verify_ref') {
    $ref = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
    if (empty($ref)) die(json_encode(['error' => 'ref param required. Example: ?ref=MNFY|25|20260604111204|016517']));

    $token = monnify_auth($apiKey, $apiSecret);
    if (!$token) die(json_encode(['error' => 'Monnify auth failed']));

    $txn  = monnify_verify_ref($token, $ref);
    $body = $txn['responseBody'] ?? null;

    if (!($txn['requestSuccessful'] ?? false) || !$body) {
        die(json_encode(['error' => 'Monnify lookup failed', 'message' => $txn['responseMessage'] ?? 'unknown', 'ref' => $ref]));
    }
    if (($body['paymentStatus'] ?? '') !== 'PAID') {
        die(json_encode(['status' => 'NOT_PAID', 'paymentStatus' => $body['paymentStatus'], 'ref' => $ref]));
    }

    $email  = $body['customer']['email'] ?? '';
    $amount = floatval($body['amountPaid'] ?? 0);
    $paidOn = $body['paidOn'] ?? date('Y-m-d H:i:s');

    if (empty($email) || $amount <= 0) die(json_encode(['error' => 'Missing email or amount from Monnify', 'body' => $body]));

    $em = mysqli_real_escape_string($conn, $email);
    $wq = mysqli_query($conn, "SELECT user_id FROM wallet_tbl WHERE user_id='$em' LIMIT 1");
    if (mysqli_num_rows($wq) === 0) mysqli_query($conn, "INSERT INTO wallet_tbl(user_id,balance,status) VALUES('$em',0,1)");

    $result = credit_wallet($conn, $email, $amount, $ref, $paidOn);
    echo json_encode(array_merge($result, ['ref' => $ref, 'email' => $email, 'amount' => $amount, 'paidOn' => $paidOn]), JSON_PRETTY_PRINT);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// MODE: scan_discrepancies — compare Monnify totalAmount vs wallet for users with accountRef
// ═══════════════════════════════════════════════════════════════════════════
if ($mode === 'scan_discrepancies') {
    $token = monnify_auth($apiKey, $apiSecret);
    if (!$token) die(json_encode(['error' => 'Monnify auth failed']));

    $q = mysqli_query($conn,
        "SELECT u.email, u.monnify_account_ref, COALESCE(w.balance,0) AS wallet_balance,
                (SELECT COALESCE(SUM(trans_amount),0) FROM wallet_history_tbl WHERE email=u.email AND wallet_status='credit' AND status=1) AS total_credited,
                (SELECT COUNT(*) FROM wallet_history_tbl WHERE email=u.email AND wallet_status='credit') AS credit_count
           FROM users_tbl u LEFT JOIN wallet_tbl w ON w.user_id=u.email
          WHERE u.monnify_account_ref IS NOT NULL AND u.monnify_account_ref != ''");

    $results = [];
    while ($u = mysqli_fetch_assoc($q)) {
        $acctData   = monnify_get_account($token, $u['monnify_account_ref']);
        $acctBody   = $acctData['responseBody'] ?? null;
        $monnifyTotal   = floatval($acctBody['totalAmount'] ?? 0);
        $monnifyCount   = intval($acctBody['transactionCount'] ?? 0);
        $walletTotal    = floatval($u['total_credited']);
        $gap            = $monnifyTotal - $walletTotal;

        $results[] = [
            'email'          => $u['email'],
            'account_ref'    => $u['monnify_account_ref'],
            'wallet_balance' => floatval($u['wallet_balance']),
            'total_credited' => $walletTotal,
            'monnify_total'  => $monnifyTotal,
            'monnify_txns'   => $monnifyCount,
            'db_txns'        => intval($u['credit_count']),
            'gap_naira'      => $gap,
            'has_gap'        => ($gap > 0),
            'status'         => $gap > 0 ? 'MISSING_PAYMENTS' : ($gap < 0 ? 'OVERCREDITED' : 'OK'),
        ];
    }
    echo json_encode(['status' => 'OK', 'note' => 'Use verify_ref mode to credit any missing transactions', 'users' => $results], JSON_PRETTY_PRINT);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// MODE: set_account_ref — store accountRef for a user
// ═══════════════════════════════════════════════════════════════════════════
if ($mode === 'set_account_ref') {
    $email = trim($_GET['email'] ?? $_POST['email'] ?? '');
    $ref   = trim($_GET['ref']   ?? $_POST['ref']   ?? '');
    if (empty($email) || empty($ref)) die(json_encode(['error' => 'email and ref required']));
    $em = mysqli_real_escape_string($conn, $email);
    $rr = mysqli_real_escape_string($conn, $ref);
    mysqli_query($conn, "UPDATE users_tbl SET monnify_account_ref='$rr' WHERE email='$em'");
    echo json_encode(['status' => 'OK', 'email' => $email, 'ref' => $ref, 'rows' => mysqli_affected_rows($conn)]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// MODE: auto_recover — credit all missed txns for users with stored accountRef
// ═══════════════════════════════════════════════════════════════════════════
if ($mode === 'auto_recover') {
    echo json_encode([
        'status' => 'LIMITED',
        'message' => 'Monnify API does not expose a bulk transaction list endpoint. Use scan_discrepancies to find gaps, then verify_ref to credit each MNFY|... reference.',
        'how_to' => [
            'step1' => '?mode=scan_discrepancies — see which users have uncredited amounts',
            'step2' => '?mode=verify_ref&ref=MNFY|25|YYYYMMDDHHMMSS|NNNNNN — credit each missed ref',
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['error' => 'Unknown mode', 'modes' => ['status', 'verify_ref', 'scan_discrepancies', 'set_account_ref', 'auto_recover']]);
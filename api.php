<?php
/**
 * Adildata REST API — Mobile App Backend
 * Deploy to: api.adildata.com.ng/api.php
 * Usage: https://api.adildata.com.ng/api.php?action=XXX
 *
 * Monnify is the ONLY account provider. No PaymentPoint or legacy fallbacks.
 * All virtual accounts are now generated and served via Monnify only.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── DB connection ─────────────────────────────────────────────────────────────
function db_connect() {
    $conn = mysqli_connect('localhost', 'adiliqgs_adildata', 'adildata2026', 'adiliqgs_adildata');
    if (!$conn) {
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }
    return $conn;
}

// ── Response helpers ──────────────────────────────────────────────────────────
function api_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $code === 200 ? 'success' : 'error', 'data' => $data]);
    exit;
}

function api_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

// ── Token verification ────────────────────────────────────────────────────────
function verify_token($conn, $incoming_token) {
    if (empty($incoming_token)) return null;
    $q = mysqli_query($conn, "SELECT * FROM users_tbl WHERE status = 1 AND token IS NOT NULL AND token != ''");
    while ($row = mysqli_fetch_assoc($q)) {
        if (password_verify($incoming_token, $row['token'])) return $row;
        if ($incoming_token === $row['token']) return $row; // legacy plain token
    }
    return null;
}

function get_token_from_request() {
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    return $_SERVER['HTTP_X_API_TOKEN']
        ?? $_GET['token']
        ?? $_POST['token']
        ?? ($body['token'] ?? '');
}

function require_auth($conn) {
    $token = get_token_from_request();
    if (empty($token)) api_error('Unauthorized: token required', 401);
    $user = verify_token($conn, $token);
    if (!$user) api_error('Unauthorized: invalid or expired token', 401);
    return $user;
}

// ── Monnify helpers ───────────────────────────────────────────────────────────
function monnify_get_credentials($conn) {
    $q    = mysqli_query($conn, "SELECT setting_key, setting_value FROM edutech_settings WHERE setting_key LIKE 'MONNIFY_%'");
    $keys = [];
    while ($r = mysqli_fetch_assoc($q)) $keys[$r['setting_key']] = $r['setting_value'];
    return [
        'api_key'    => $keys['MONNIFY_API_KEY']      ?? '',
        'api_secret' => $keys['MONNIFY_API_SECRET']   ?? '881J3RXH6Z6LDVJWG76P1YHW8VCECAE5',
        'base_url'   => rtrim($keys['MONNIFY_BASE_URL'] ?? 'https://api.monnify.com', '/'),
        'contract'   => $keys['MONNIFY_API_CONTRACT']  ?? '',
    ];
}

function monnify_login($api_key, $api_secret, $base_url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $base_url . '/api/v1/auth/login',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode("$api_key:$api_secret"),
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['responseBody']['accessToken'] ?? null;
}

/**
 * Create a Monnify reserved account for a user.
 * Returns ['success' => bool, 'accounts' => array, 'raw' => string, 'message' => string]
 * $bvn — optional BVN string (required by some Monnify contracts in production)
 */
function monnify_create_reserved_account($conn, $email, $fullName, $userId, $bvn = '') {
    $creds = monnify_get_credentials($conn);
    if (empty($creds['api_key']) || empty($creds['contract'])) {
        return ['success' => false, 'message' => 'Monnify credentials not configured'];
    }

    $token = monnify_login($creds['api_key'], $creds['api_secret'], $creds['base_url']);
    if (!$token) {
        return ['success' => false, 'message' => 'Monnify authentication failed'];
    }

    $accountRef  = 'ADIL_' . $userId . '_' . time();
    $payloadData = [
        'accountReference'    => $accountRef,
        'accountName'         => $fullName,
        'currencyCode'        => 'NGN',
        'contractCode'        => $creds['contract'],
        'customerEmail'       => $email,
        'customerName'        => $fullName,
        'getAllAvailableBanks' => true,
    ];
    // Production Monnify requires BVN or NIN for reserved account creation
    if (!empty($bvn) && strlen(preg_replace('/\D/', '', $bvn)) === 11) {
        $payloadData['bvn'] = preg_replace('/\D/', '', $bvn);
    }
    $payload = json_encode($payloadData);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $creds['base_url'] . '/api/v2/bank-transfer/reserved-accounts',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['success' => false, 'message' => 'cURL error: ' . $err];

    $data = json_decode($resp, true);
    if (empty($data['requestSuccessful'])) {
        return ['success' => false, 'message' => $data['responseMessage'] ?? 'Monnify account creation failed', 'raw_response' => $resp];
    }

    $body     = $data['responseBody'] ?? [];
    $accounts = $body['accounts'] ?? [];

    // Build the stored string format: "Bank Name - AccountNumber - AccountName"
    $parts = [];
    foreach ($accounts as $acct) {
        $bankName = $acct['bankName'] ?? '';
        $accNum   = $acct['accountNumber'] ?? '';
        $accName  = $body['accountName'] ?? $fullName;
        if ($bankName && $accNum) {
            $parts[] = "$bankName - $accNum - $accName";
        }
    }

    $detailsStr = implode(', ', $parts);
    $refStr     = $body['reservationReference'] ?? $accountRef;

    // Save to DB (monnify_account_details is the only column available)
    $em   = mysqli_real_escape_string($conn, $email);
    $ds   = mysqli_real_escape_string($conn, $detailsStr);
    mysqli_query($conn, "UPDATE users_tbl SET monnify_account_details='$ds' WHERE email='$em'");

    return [
        'success'  => !empty($parts),
        'accounts' => $accounts,
        'raw'      => $detailsStr,
        'message'  => !empty($parts) ? 'Account generated' : 'No accounts returned by Monnify',
    ];
}

/**
 * Parse monnify_account_details string into structured array.
 * Format: "BankName - AccountNumber - AccountName, ..."
 */
function parse_monnify_accounts($rawStr) {
    $accounts = [];
    if (empty($rawStr)) return $accounts;
    foreach (explode(', ', $rawStr) as $acct) {
        $p = explode(' - ', trim($acct));
        if (count($p) >= 2) {
            $accounts[] = [
                'provider'       => 'Monnify',
                'bank_name'      => trim($p[0]),
                'account_number' => trim($p[1]),
                'account_name'   => trim($p[2] ?? ''),
            ];
        }
    }
    return $accounts;
}

// ─────────────────────────────────────────────────────────────────────────────
$body   = json_decode(@file_get_contents('php://input'), true) ?? [];
$action = strtolower(trim(
    $_GET['action'] ?? $_POST['action'] ?? ($body['action'] ?? '')
));
$conn = db_connect();

switch ($action) {

// ── HEALTH ────────────────────────────────────────────────────────────────────
case 'health':
case 'ping':
    api_response(['message' => 'Adildata API is running', 'version' => '3.0', 'provider' => 'Monnify', 'time' => date('Y-m-d H:i:s')]);
    break;

// ── LOGIN ─────────────────────────────────────────────────────────────────────
case 'login':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('POST required', 405);
    $body     = json_decode(@file_get_contents('php://input'), true) ?? [];
    $email    = trim($body['email']    ?? $_POST['email']    ?? '');
    $password = trim($body['password'] ?? $_POST['password'] ?? '');
    if (empty($email) || empty($password)) api_error('Email and password required');

    $em = mysqli_real_escape_string($conn, $email);
    $r  = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email = '$em' AND status = 1 LIMIT 1");
    if (!$r || mysqli_num_rows($r) === 0) api_error('Invalid credentials', 401);
    $user = mysqli_fetch_assoc($r);
    if (!password_verify($password, $user['password'])) api_error('Invalid credentials', 401);

    $api_token = bin2hex(random_bytes(32));
    $tokenHash = password_hash($api_token, PASSWORD_DEFAULT);
    $ts = mysqli_real_escape_string($conn, $tokenHash);
    mysqli_query($conn, "UPDATE users_tbl SET token = '$ts' WHERE id = " . intval($user['id']));

    $wq  = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id = '$em' LIMIT 1");
    $bal = ($wq && mysqli_num_rows($wq) > 0) ? intval(mysqli_fetch_assoc($wq)['balance']) : 0;

    // Auto-generate Monnify account if user doesn't have one (pass BVN if available)
    if (empty($user['monnify_account_details']) && !empty($user['bvn'])) {
        $fullName = trim($user['sname'] . ' ' . $user['oname']);
        monnify_create_reserved_account($conn, $user['email'], $fullName, $user['id'], $user['bvn']);
    }

    api_response([
        'token'          => $api_token,
        'id'             => $user['id'],
        'email'          => $user['email'],
        'sname'          => $user['sname'],
        'oname'          => $user['oname'],
        'phone'          => $user['phone'],
        'admin_role'     => $user['admin_role'],
        'wallet_balance' => $bal,
    ]);
    break;

// ── REGISTER ─────────────────────────────────────────────────────────────────
case 'register':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('POST required', 405);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $body = array_merge($_POST, $body);

    // Support both fullName (APK format) and sname/oname (web format)
    if (!empty($body['fullName']) && (empty($body['sname']) || empty($body['oname']))) {
        $nameParts    = explode(' ', trim($body['fullName']), 2);
        $body['sname'] = $nameParts[0];
        $body['oname'] = $nameParts[1] ?? '';
    }

    foreach (['email', 'password', 'sname', 'phone'] as $f) {
        if (empty(trim($body[$f] ?? ''))) api_error("$f is required");
    }

    $em    = mysqli_real_escape_string($conn, trim($body['email']));
    $ex    = mysqli_query($conn, "SELECT id FROM users_tbl WHERE email = '$em' LIMIT 1");
    if ($ex && mysqli_num_rows($ex) > 0) api_error('Email already registered');

    $pass  = password_hash(trim($body['password']), PASSWORD_DEFAULT);
    $sname = mysqli_real_escape_string($conn, trim($body['sname']));
    $oname = mysqli_real_escape_string($conn, trim($body['oname'] ?? ''));
    $phone = mysqli_real_escape_string($conn, trim($body['phone']));
    $pin   = md5(trim($body['pin'] ?? '0000'));
    $state = mysqli_real_escape_string($conn, trim($body['state'] ?? ''));
    $ref   = md5(trim($body['email']));
    $refBy = mysqli_real_escape_string($conn, trim($body['referal'] ?? ''));

    $ins = mysqli_query($conn,
        "INSERT INTO users_tbl(sname,oname,password,email,phone,referal_token,pin,state)
         VALUES('$sname','$oname','$pass','$em','$phone','$ref','$pin','$state')"
    );
    if (!$ins) api_error('Registration failed: ' . mysqli_error($conn));

    $newUserId = mysqli_insert_id($conn);

    // Create wallet
    mysqli_query($conn, "INSERT INTO wallet_tbl(user_id, balance, status) VALUES('$em', 0, 1)");

    // Handle referral
    if (!empty($refBy)) {
        mysqli_query($conn, "INSERT INTO referal_tbl(referal, referee) VALUES('$refBy', '$ref')");
    }

    // ── AUTO-GENERATE MONNIFY ACCOUNT ────────────────────────────────────────
    $fullName = trim("$sname $oname");
    $monnify  = monnify_create_reserved_account($conn, trim($body['email']), $fullName, $newUserId);

    $responseData = ['message' => 'Registration successful. Please login.'];
    if ($monnify['success'] && !empty($monnify['raw'])) {
        $responseData['monnify_account'] = $monnify['raw'];
    }

    api_response($responseData);
    break;

// ── PROFILE ───────────────────────────────────────────────────────────────────
case 'profile':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $wq   = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id = '$em' LIMIT 1");
    $bal  = ($wq && mysqli_num_rows($wq) > 0) ? intval(mysqli_fetch_assoc($wq)['balance']) : 0;

    // Auto-generate Monnify account if missing (BVN required by Monnify production)
    if (empty($user['monnify_account_details']) && !empty($user['bvn'])) {
        $fullName = trim($user['sname'] . ' ' . $user['oname']);
        monnify_create_reserved_account($conn, $user['email'], $fullName, $user['id'], $user['bvn']);
        // Re-fetch user to get updated details
        $uq   = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email = '$em' LIMIT 1");
        $user = $uq ? mysqli_fetch_assoc($uq) : $user;
    }

    $mAccounts = parse_monnify_accounts($user['monnify_account_details'] ?? '');
    $primary   = $mAccounts[0] ?? null;

    api_response([
        'id'             => $user['id'],
        'email'          => $user['email'],
        'sname'          => $user['sname'],
        'oname'          => $user['oname'],
        'phone'          => $user['phone'],
        'state'          => $user['state'],
        'admin_role'     => $user['admin_role'],
        'super_admin'    => $user['super_admin'],
        'referral_code'  => $user['referal_token'],
        'referral_link'  => 'https://adildata.com.ng/easyfinder/dashboard/register?join_with_referal=' . $user['referal_token'],
        'wallet_balance' => $bal,
        'has_monnify'    => !empty($user['monnify_account_details']),
        'acc_no'         => $primary['account_number'] ?? '',
        'bank_name'      => $primary['bank_name'] ?? '',
        'acc_name'       => $primary['account_name'] ?? '',
        'bvn'            => !empty($user['bvn']) ? '****' . substr($user['bvn'], -4) : null,
    ]);
    break;

// ── WALLET BALANCE ────────────────────────────────────────────────────────────
case 'wallet':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $wq   = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id = '$em' LIMIT 1");
    $bal  = ($wq && mysqli_num_rows($wq) > 0) ? intval(mysqli_fetch_assoc($wq)['balance']) : 0;
    api_response(['balance' => $bal, 'email' => $user['email']]);
    break;

// ── WALLET HISTORY ────────────────────────────────────────────────────────────
case 'wallet_history':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $q    = mysqli_query($conn, "SELECT * FROM wallet_history_tbl WHERE email = '$em' ORDER BY id DESC LIMIT 50");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    api_response(['transactions' => $rows]);
    break;

// ── TRANSACTION HISTORY ───────────────────────────────────────────────────────
case 'transactions':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $q    = mysqli_query($conn, "SELECT * FROM transactions_tbl WHERE email = '$em' ORDER BY id DESC LIMIT 50");
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = [
            'id'         => $row['id'],
            'title'      => $row['product_name'] ?? 'Transaction',
            'phone'      => $row['phone'] ?? '-',
            'date'       => $row['transaction_date'] ?? '-',
            'subtitle'   => ($row['status'] == 1) ? 'Successful' : 'Failed / Refunded',
            'amount'     => number_format($row['amount'], 0),
            'status'     => intval($row['status']),
            'negative'   => $row['status'] == 1,
            'request_id' => $row['request_id'] ?? '',
        ];
    }
    api_response(['transactions' => $rows]);
    break;

// ── DASHBOARD STATS ───────────────────────────────────────────────────────────
case 'dashboard_stats':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);

    $wq  = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id = '$em' LIMIT 1");
    $bal = ($wq && mysqli_num_rows($wq) > 0) ? intval(mysqli_fetch_assoc($wq)['balance']) : 0;

    $tq = mysqli_query($conn,
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status=0 THEN 1 ELSE 0 END) as failed
         FROM transactions_tbl WHERE email='$em'"
    );
    $ts = $tq ? mysqli_fetch_assoc($tq) : ['total' => 0, 'success' => 0, 'failed' => 0];

    $nq = mysqli_query($conn,
        "SELECT COUNT(*) as cnt FROM notifications_tbl WHERE status=1 AND (target='all' OR target_email='$em')"
    );
    $nc = $nq ? intval(mysqli_fetch_assoc($nq)['cnt']) : 0;

    $rq = mysqli_query($conn,
        "SELECT COUNT(*) as cnt FROM referal_tbl WHERE referal=(SELECT referal_token FROM users_tbl WHERE email='$em' LIMIT 1)"
    );
    $rc = $rq ? intval(mysqli_fetch_assoc($rq)['cnt']) : 0;

    // Auto-generate Monnify if missing (requires BVN)
    if (empty($user['monnify_account_details']) && !empty($user['bvn'])) {
        $fullName = trim($user['sname'] . ' ' . $user['oname']);
        monnify_create_reserved_account($conn, $user['email'], $fullName, $user['id'], $user['bvn']);
        $uq   = mysqli_query($conn, "SELECT monnify_account_details FROM users_tbl WHERE email='$em' LIMIT 1");
        if ($uq) { $ur = mysqli_fetch_assoc($uq); $user['monnify_account_details'] = $ur['monnify_account_details'] ?? ''; }
    }

    $mAccounts = parse_monnify_accounts($user['monnify_account_details'] ?? '');
    $primary   = $mAccounts[0] ?? null;

    api_response([
        'wallet_balance'        => $bal,
        'total_transactions'    => intval($ts['total']),
        'success_transactions'  => intval($ts['success']),
        'failed_transactions'   => intval($ts['failed']),
        'notifications_count'   => $nc,
        'referral_count'        => $rc,
        'has_monnify'           => !empty($user['monnify_account_details']),
        'acc_no'                => $primary['account_number'] ?? '',
        'bank_name'             => $primary['bank_name'] ?? '',
        'acc_name'              => $primary['account_name'] ?? '',
    ]);
    break;

// ── FUNDING ACCOUNTS ──────────────────────────────────────────────────────────
case 'funding_accounts':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);

    // Auto-generate Monnify account if user has BVN but no Monnify account yet
    if (empty($user['monnify_account_details']) && !empty($user['bvn'])) {
        $fullName = trim($user['sname'] . ' ' . $user['oname']);
        monnify_create_reserved_account($conn, $user['email'], $fullName, $user['id'], $user['bvn']);
        $uq = mysqli_query($conn, "SELECT monnify_account_details FROM users_tbl WHERE email='$em' LIMIT 1");
        if ($uq) { $ur = mysqli_fetch_assoc($uq); $user['monnify_account_details'] = $ur['monnify_account_details'] ?? ''; }
    }

    $monnifyRaw = $user['monnify_account_details'] ?? '';
    $accounts   = parse_monnify_accounts($monnifyRaw);
    $primary    = $accounts[0] ?? null;

    $needsBvn = empty($accounts) && empty($user['bvn']);
    api_response([
        'accounts'        => $accounts,
        'has_accounts'    => count($accounts) > 0,
        'has_monnify'     => count($accounts) > 0,
        'monnify_raw'     => $monnifyRaw,
        'acc_no'          => $primary['account_number'] ?? '',
        'bank_name'       => $primary['bank_name'] ?? '',
        'acc_name'        => $primary['account_name'] ?? '',
        'account_number'  => $primary['account_number'] ?? '',
        'account_name'    => $primary['account_name'] ?? '',
        'provider'        => 'Monnify',
        'needs_bvn'       => $needsBvn,
        'setup_message'   => $needsBvn ? 'Please submit your BVN via the KYC section to activate your virtual account.' : '',
    ]);
    break;

// ── GENERATE MONNIFY ACCOUNT ──────────────────────────────────────────────────
case 'generate_monnify':
    $user = require_auth($conn);

    // If already exists, return existing
    if (!empty($user['monnify_account_details'])) {
        $accounts = parse_monnify_accounts($user['monnify_account_details']);
        $primary  = $accounts[0] ?? null;
        api_response([
            'message'        => 'Account already exists',
            'accounts'       => $accounts,
            'acc_no'         => $primary['account_number'] ?? '',
            'bank_name'      => $primary['bank_name'] ?? '',
            'acc_name'       => $primary['account_name'] ?? '',
            'account_number' => $primary['account_number'] ?? '',
            'account_name'   => $primary['account_name'] ?? '',
            'monnify_raw'    => $user['monnify_account_details'],
        ]);
    }

    // BVN is required by Monnify production API
    if (empty($user['bvn'])) {
        api_error('BVN is required to generate a virtual account. Please submit your BVN first via the KYC section.');
    }

    $fullName = trim($user['sname'] . ' ' . $user['oname']);
    $result   = monnify_create_reserved_account($conn, $user['email'], $fullName, $user['id'], $user['bvn']);

    if (!$result['success']) {
        api_error('Failed to generate Monnify account: ' . ($result['message'] ?? 'Unknown error'));
    }

    $accounts = parse_monnify_accounts($result['raw']);
    $primary  = $accounts[0] ?? null;

    api_response([
        'message'        => 'Monnify account generated successfully',
        'accounts'       => $accounts,
        'acc_no'         => $primary['account_number'] ?? '',
        'bank_name'      => $primary['bank_name'] ?? '',
        'acc_name'       => $primary['account_name'] ?? '',
        'account_number' => $primary['account_number'] ?? '',
        'account_name'   => $primary['account_name'] ?? '',
        'monnify_raw'    => $result['raw'],
    ]);
    break;

// ── VERIFY MONNIFY (kept for webhook/manual checks) ───────────────────────────
case 'verify_monnify':
    $user    = require_auth($conn);
    $em      = mysqli_real_escape_string($conn, $user['email']);
    $accounts = parse_monnify_accounts($user['monnify_account_details'] ?? '');
    $primary  = $accounts[0] ?? null;

    api_response([
        'has_monnify'    => count($accounts) > 0,
        'accounts'       => $accounts,
        'acc_no'         => $primary['account_number'] ?? '',
        'bank_name'      => $primary['bank_name'] ?? '',
        'acc_name'       => $primary['account_name'] ?? '',
        'account_number' => $primary['account_number'] ?? '',
        'account_name'   => $primary['account_name'] ?? '',
        'monnify_raw'    => $user['monnify_account_details'] ?? '',
    ]);
    break;

// ── BUY AIRTIME ───────────────────────────────────────────────────────────────
case 'buy_airtime':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $amount  = intval($body['amount']  ?? $_POST['amount']  ?? 0);
    $number  = trim($body['number']   ?? $_POST['number']   ?? '');
    $network = strtolower(trim($body['network'] ?? $_POST['network'] ?? ''));
    $pin     = trim($body['pin']      ?? $_POST['pin']      ?? '');

    if (!$amount || !$number || !$network || !$pin) api_error('amount, number, network and pin are required');
    if ($pin !== 'fingerprint' && md5($pin) !== $user['pin']) api_error('Invalid PIN');

    $em = mysqli_real_escape_string($conn, $user['email']);
    $wq = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$em' LIMIT 1");
    if (!$wq || mysqli_num_rows($wq) === 0) api_error('Wallet not found');
    $wallet = mysqli_fetch_assoc($wq);
    if ($wallet['balance'] < $amount) api_error('Insufficient balance');

    $newBalance = $wallet['balance'] - $amount;
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='$newBalance' WHERE user_id='$em'");

    $apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name='vtpass' LIMIT 1");
    if (!$apiQ || mysqli_num_rows($apiQ) === 0) {
        mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$em'");
        api_error('Service not configured');
    }
    $api = mysqli_fetch_assoc($apiQ);

    $requestId = uniqid('AIRTIME_');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => rtrim($api['api_url'], '/') . '/api/pay',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'request_id' => $requestId,
            'serviceID'  => $network,
            'amount'     => $amount,
            'phone'      => $number,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: '     . $api['api_key'],
            'secret-key: '  . $api['secret'],
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $apiResp  = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $res    = json_decode($apiResp, true);
    $status = !$curlErr && $res && strtolower($res['code'] ?? '') === '000';
    if (!$status) mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$em'");

    $txId = $res['content']['transactions']['transactionId'] ?? null;
    $nm   = mysqli_real_escape_string($conn, $number);
    $resJ = mysqli_real_escape_string($conn, json_encode($res));
    $rid  = mysqli_real_escape_string($conn, $requestId);
    mysqli_query($conn,
        "INSERT INTO transactions_tbl(unique_element,amount,real_amount,email,phone,transaction_id,request_id,product_name,response_description,status,transaction_date,is_bill,our_commission)
         VALUES('$nm','$amount','$amount','$em','$nm','" . mysqli_real_escape_string($conn, $txId ?? '') . "','$rid','" . strtoupper($network) . " Airtime','$resJ'," . ($status?1:0) . ",NOW(),1,0)"
    );

    api_response([
        'success' => $status,
        'message' => $status ? 'Airtime purchase successful' : 'Transaction failed, refunded',
        'balance' => $status ? $newBalance : $wallet['balance'],
    ]);
    break;

// ── BUY DATA ──────────────────────────────────────────────────────────────────
case 'buy_data':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $amount    = intval($body['amount']    ?? $_POST['amount']    ?? 0);
    $number    = trim($body['number']     ?? $_POST['number']     ?? '');
    $serviceID = trim($body['serviceID']  ?? $_POST['serviceID']  ?? '');
    $variation = trim($body['variation']  ?? $_POST['variation']  ?? '');
    $pin       = trim($body['pin']        ?? $_POST['pin']        ?? '');

    if (!$amount || !$number || !$serviceID || !$variation || !$pin) {
        api_error('amount, number, serviceID, variation and pin are required');
    }
    if ($pin !== 'fingerprint' && md5($pin) !== $user['pin']) api_error('Invalid PIN');

    $em = mysqli_real_escape_string($conn, $user['email']);
    $wq = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$em' LIMIT 1");
    if (!$wq || mysqli_num_rows($wq) === 0) api_error('Wallet not found');
    $wallet = mysqli_fetch_assoc($wq);
    if ($wallet['balance'] < $amount) api_error('Insufficient balance');

    $newBalance = $wallet['balance'] - $amount;
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='$newBalance' WHERE user_id='$em'");

    $apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name='vtpass' LIMIT 1");
    if (!$apiQ || mysqli_num_rows($apiQ) === 0) {
        mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$em'");
        api_error('Service not configured');
    }
    $api = mysqli_fetch_assoc($apiQ);

    $requestId = uniqid('DATA_');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => rtrim($api['api_url'], '/') . '/api/pay',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'request_id'     => $requestId,
            'serviceID'      => strtolower($serviceID),
            'billersCode'    => $number,
            'variation_code' => $variation,
            'amount'         => $amount,
            'phone'          => $number,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: '    . $api['api_key'],
            'secret-key: ' . $api['secret'],
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $apiResp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $res    = json_decode($apiResp, true);
    $status = !$curlErr && $res && strtolower($res['code'] ?? '') === '000';
    if (!$status) mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$em'");

    $txId = $res['content']['transactions']['transactionId'] ?? null;
    $nm   = mysqli_real_escape_string($conn, $number);
    $resJ = mysqli_real_escape_string($conn, json_encode($res));
    $rid  = mysqli_real_escape_string($conn, $requestId);
    $pn   = mysqli_real_escape_string($conn, $res['content']['transactions']['product_name'] ?? 'Data Purchase');
    mysqli_query($conn,
        "INSERT INTO transactions_tbl(unique_element,amount,real_amount,email,phone,transaction_id,request_id,product_name,response_description,status,transaction_date,is_bill,our_commission)
         VALUES('$nm','$amount','$amount','$em','$nm','" . mysqli_real_escape_string($conn, $txId ?? '') . "','$rid','$pn','$resJ'," . ($status?1:0) . ",NOW(),1,0)"
    );

    api_response([
        'success' => $status,
        'message' => $status ? 'Data purchase successful' : 'Transaction failed, refunded',
        'balance' => $status ? $newBalance : $wallet['balance'],
    ]);
    break;

// ── DATA PLANS ────────────────────────────────────────────────────────────────
case 'data_plans':
    $body      = json_decode(@file_get_contents('php://input'), true) ?? [];
    $serviceID = trim($_GET['serviceID'] ?? $_POST['serviceID'] ?? ($body['serviceID'] ?? ''));
    if (empty($serviceID)) api_error('serviceID required');

    $apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name='vtpass' LIMIT 1");
    $api  = ($apiQ && mysqli_num_rows($apiQ) > 0) ? mysqli_fetch_assoc($apiQ) : null;
    $url  = $api
        ? rtrim($api['api_url'], '/') . '/api/service-variations?serviceID=' . urlencode(strtolower($serviceID))
        : 'https://vtpass.com/api/service-variations?serviceID=' . urlencode(strtolower($serviceID));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    if ($api) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api-key: '    . $api['api_key'],
            'secret-key: ' . $api['secret'],
        ]);
    }
    $resp = curl_exec($ch);
    curl_close($ch);

    $data  = json_decode($resp, true);
    $plans = [];
    foreach (($data['content']['variations'] ?? []) as $p) {
        $plans[] = [
            'plan_id' => $p['variation_code'],
            'name'    => $p['name'],
            'amount'  => $p['variation_amount'],
        ];
    }
    api_response(['plans' => $plans]);
    break;

// ── NOTIFICATIONS ─────────────────────────────────────────────────────────────
case 'notifications':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $q    = mysqli_query($conn,
        "SELECT * FROM notifications_tbl WHERE status=1 AND (target='all' OR target_email='$em') ORDER BY id DESC LIMIT 50"
    );
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $readers     = json_decode($row['is_read_by'] ?: '[]', true);
        $row['read'] = in_array($user['email'], $readers);
        unset($row['is_read_by']);
        $rows[] = $row;
    }
    api_response(['notifications' => $rows]);
    break;

// ── MARK NOTIFICATION READ ────────────────────────────────────────────────────
case 'mark_notification_read':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $id   = intval($body['id'] ?? $_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) api_error('Notification ID required');
    $q = mysqli_query($conn, "SELECT is_read_by FROM notifications_tbl WHERE id=$id AND status=1 LIMIT 1");
    if (!$q || mysqli_num_rows($q) === 0) api_error('Notification not found', 404);
    $row     = mysqli_fetch_assoc($q);
    $readers = json_decode($row['is_read_by'] ?: '[]', true);
    if (!in_array($user['email'], $readers)) {
        $readers[] = $user['email'];
        $rj = mysqli_real_escape_string($conn, json_encode($readers));
        mysqli_query($conn, "UPDATE notifications_tbl SET is_read_by='$rj' WHERE id=$id");
    }
    api_response(['message' => 'Marked as read']);
    break;

// ── REFERRAL ──────────────────────────────────────────────────────────────────
case 'referral':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $rq   = mysqli_query($conn,
        "SELECT u.sname, u.oname, u.email, u.date_join FROM referal_tbl rt
         JOIN users_tbl u ON u.email=(SELECT email FROM users_tbl WHERE MD5(email)=rt.referee LIMIT 1)
         WHERE rt.referal=(SELECT referal_token FROM users_tbl WHERE email='$em' LIMIT 1)
         ORDER BY rt.id DESC"
    );
    $referred = [];
    while ($r = mysqli_fetch_assoc($rq)) $referred[] = $r;
    $tq   = mysqli_query($conn,
        "SELECT COALESCE(SUM(earn_amount),0) as total FROM referal_earn_transaction_tbl WHERE referal_email='$em'"
    );
    $total = intval(mysqli_fetch_assoc($tq)['total'] ?? 0);
    api_response([
        'referral_code'  => $user['referal_token'],
        'referral_link'  => 'https://adildata.com.ng/easyfinder/dashboard/register?join_with_referal=' . $user['referal_token'],
        'total_earnings' => $total,
        'referred_users' => $referred,
    ]);
    break;

// ── CHANGE PASSWORD ───────────────────────────────────────────────────────────
case 'change_password':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $old  = trim($body['old_password'] ?? $_POST['old_password'] ?? '');
    $new  = trim($body['new_password'] ?? $_POST['new_password'] ?? '');
    if (empty($old) || empty($new)) api_error('old_password and new_password required');
    if (!password_verify($old, $user['password'])) api_error('Current password is incorrect');
    $hash = mysqli_real_escape_string($conn, password_hash($new, PASSWORD_DEFAULT));
    $em   = mysqli_real_escape_string($conn, $user['email']);
    mysqli_query($conn, "UPDATE users_tbl SET password='$hash' WHERE email='$em'");
    api_response(['message' => 'Password changed successfully']);
    break;

// ── CHANGE PIN ────────────────────────────────────────────────────────────────
case 'change_pin':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $old  = trim($body['old_pin'] ?? $_POST['old_pin'] ?? '');
    $new  = trim($body['new_pin'] ?? $_POST['new_pin'] ?? '');
    if (empty($old) || empty($new)) api_error('old_pin and new_pin required');
    if (md5($old) !== $user['pin']) api_error('Current PIN is incorrect');
    $newPin = mysqli_real_escape_string($conn, md5($new));
    $em     = mysqli_real_escape_string($conn, $user['email']);
    mysqli_query($conn, "UPDATE users_tbl SET pin='$newPin' WHERE email='$em'");
    api_response(['message' => 'PIN changed successfully']);
    break;

// ── SUBMIT KYC — auto-generates Monnify account after BVN submission ──────────
case 'submit_kyc':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $bvn  = preg_replace('/\D/', '', trim($body['bvn'] ?? $_POST['bvn'] ?? ''));
    $nin  = preg_replace('/\D/', '', trim($body['nin'] ?? $_POST['nin'] ?? ''));
    if (empty($bvn) && empty($nin)) api_error('BVN or NIN is required');
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $sets = [];
    if (!empty($bvn) && strlen($bvn) === 11) { $sets[] = "bvn='" . mysqli_real_escape_string($conn, $bvn) . "'"; }
    if (!empty($nin) && strlen($nin) === 11) { $sets[] = "nin='" . mysqli_real_escape_string($conn, $nin) . "'"; }
    if (empty($sets)) api_error('BVN and NIN must be 11 digits');
    mysqli_query($conn, "UPDATE users_tbl SET " . implode(', ', $sets) . " WHERE email='$em'");

    // Auto-generate Monnify account after BVN submission if not already created
    $monnifyResult = null;
    if (!empty($bvn) && empty($user['monnify_account_details'])) {
        $fullName      = trim($user['sname'] . ' ' . $user['oname']);
        $monnifyResult = monnify_create_reserved_account($conn, $user['email'], $fullName, $user['id'], $bvn);
    }

    $responseData = ['message' => 'KYC submitted successfully'];
    if ($monnifyResult && $monnifyResult['success']) {
        $accounts = parse_monnify_accounts($monnifyResult['raw']);
        $primary  = $accounts[0] ?? null;
        $responseData['monnify_generated'] = true;
        $responseData['acc_no']            = $primary['account_number'] ?? '';
        $responseData['bank_name']         = $primary['bank_name'] ?? '';
        $responseData['acc_name']          = $primary['account_name'] ?? '';
        $responseData['account_number']    = $primary['account_number'] ?? '';
        $responseData['account_name']      = $primary['account_name'] ?? '';
        $responseData['accounts']          = $accounts;
    }
    api_response($responseData);
    break;


  // ── GET KYC STATUS (APK) ──────────────────────────────────────────────────────
  case 'get_kyc_status':
      $user = require_auth($conn);
      $hasBvn     = !empty($user['bvn']);
      $hasNin     = !empty($user['nin'] ?? '');
      $hasMonnify = !empty($user['monnify_account_details']);

      $accounts   = parse_monnify_accounts($user['monnify_account_details'] ?? '');
      $primary    = $accounts[0] ?? null;

      api_response([
          'kyc_complete'     => ($hasBvn || $hasNin),
          'has_bvn'          => $hasBvn,
          'has_nin'          => $hasNin,
          'has_monnify'      => $hasMonnify,
          'needs_bvn'        => !$hasBvn && !$hasNin,
          'account_ready'    => $hasMonnify,
          'account_number'   => $primary['account_number'] ?? '',
          'bank_name'        => $primary['bank_name']      ?? '',
          'account_name'     => $primary['account_name']   ?? '',
          'acc_no'           => $primary['account_number'] ?? '',
          'acc_name'         => $primary['account_name']   ?? '',
          'accounts'         => $accounts,
          'setup_message'    => (!$hasBvn && !$hasNin)
              ? 'Submit your BVN or NIN to activate your virtual account.'
              : ($hasMonnify ? '' : 'Your account is being set up. Please check back shortly.'),
      ]);
      break;


  // ── GET NOTIFICATIONS (APK) ──────────────────────────────────────────────────
  case 'get_notifications':
      $user = require_auth($conn);
      $emailSafe = mysqli_real_escape_string($conn, $user['email']);

      mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notifications_tbl (
          id INT AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(255) NOT NULL,
          message TEXT NOT NULL,
          type ENUM('info','success','warning','danger') DEFAULT 'info',
          target ENUM('all','specific') DEFAULT 'all',
          target_email VARCHAR(255) NULL,
          created_by VARCHAR(255) NULL,
          is_read_by LONGTEXT NULL DEFAULT '[]',
          status TINYINT(1) DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $nots = [];
      $rn = mysqli_query($conn,
          "SELECT id, title, message, type, target, target_email, is_read_by, created_at
           FROM notifications_tbl
           WHERE status = 1 AND (target = 'all' OR target_email = '$emailSafe')
           ORDER BY id DESC LIMIT 50");
      if ($rn) {
          while ($nrow = mysqli_fetch_assoc($rn)) {
              $readers = json_decode($nrow['is_read_by'] ?: '[]', true);
              if (!is_array($readers)) $readers = [];
              $nrow['is_read'] = in_array($user['email'], $readers);
              unset($nrow['is_read_by']);
              $nots[] = $nrow;
          }
      }
      $unread_cnt = count(array_filter($nots, fn($n) => !$n['is_read']));
      api_response(['notifications' => $nots, 'unread_count' => $unread_cnt]);
      break;

  // ── MARK NOTIFICATION READ (APK) ─────────────────────────────────────────────
  case 'mark_notification_read':
      $user = require_auth($conn);
      $body_n = json_decode(@file_get_contents('php://input'), true) ?? [];
      $nid = intval($body_n['notification_id'] ?? $_POST['notification_id'] ?? $_GET['notification_id'] ?? 0);
      if (!$nid) api_error('notification_id required');
      $rr = mysqli_query($conn, "SELECT is_read_by FROM notifications_tbl WHERE id = $nid AND status = 1 LIMIT 1");
      if (!$rr || mysqli_num_rows($rr) === 0) api_error('Notification not found', 404);
      $nrow = mysqli_fetch_assoc($rr);
      $readers = json_decode($nrow['is_read_by'] ?: '[]', true);
      if (!is_array($readers)) $readers = [];
      if (!in_array($user['email'], $readers)) {
          $readers[] = $user['email'];
          $rj = mysqli_real_escape_string($conn, json_encode($readers));
          mysqli_query($conn, "UPDATE notifications_tbl SET is_read_by = '$rj' WHERE id = $nid");
      }
      api_response(['message' => 'Marked as read']);
      break;

  // ── MARK ALL NOTIFICATIONS READ (APK) ────────────────────────────────────────
  case 'mark_all_notifications_read':
      $user = require_auth($conn);
      $es2 = mysqli_real_escape_string($conn, $user['email']);
      $all = mysqli_query($conn,
          "SELECT id, is_read_by FROM notifications_tbl WHERE status = 1 AND (target = 'all' OR target_email = '$es2')");
      if ($all) {
          while ($arow = mysqli_fetch_assoc($all)) {
              $readers = json_decode($arow['is_read_by'] ?: '[]', true);
              if (!is_array($readers)) $readers = [];
              if (!in_array($user['email'], $readers)) {
                  $readers[] = $user['email'];
                  $rj2 = mysqli_real_escape_string($conn, json_encode($readers));
                  mysqli_query($conn, "UPDATE notifications_tbl SET is_read_by = '$rj2' WHERE id = " . intval($arow['id']));
              }
          }
      }
      api_response(['message' => 'All notifications marked as read']);
      break;
  
default:
    api_error("Unknown action: '$action'. Available: health, login, register, profile, wallet, wallet_history, transactions, dashboard_stats, funding_accounts, generate_monnify, verify_monnify, buy_airtime, buy_data, data_plans, notifications, mark_notification_read, referral, change_password, change_pin, submit_kyc", 404);
}

mysqli_close($conn);
?>

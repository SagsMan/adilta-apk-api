<?php
/**
 * getAccountDetails.php — Fetch Monnify virtual account for a user (web side).
 * Returns success:true always so the frontend never spins indefinitely.
 * Uses monnify_account_details column (replaces old PaymentPoint acc_no columns).
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$conn = mysqli_connect("localhost", "adiliqgs_adildata", "adildata2026", "adiliqgs_adildata");
if (!$conn) {
    echo json_encode(["success" => true, "status" => "error", "message" => "Database error",
        "account_number" => "", "bank_name" => "", "account_name" => ""]);
    exit;
}

// ── Read token ────────────────────────────────────────────────────────────────
$data          = json_decode(@file_get_contents("php://input"), true) ?? [];
$incomingToken = $data['token'] ?? $_POST['token'] ?? $_GET['token'] ?? '';

if (empty($incomingToken)) {
    echo json_encode(["success" => true, "status" => "unauthenticated", "message" => "Token required",
        "account_number" => "", "bank_name" => "", "account_name" => ""]);
    exit;
}

// ── Verify token — fast direct lookup, then legacy bcrypt fallback ─────────────
$ts   = mysqli_real_escape_string($conn, $incomingToken);
$q    = mysqli_query($conn, "SELECT id, email, sname, oname, phone, token, monnify_account_details, bvn FROM users_tbl WHERE token='$ts' AND status=1 LIMIT 1");
$user = null;
if ($q && mysqli_num_rows($q) > 0) {
    $user = mysqli_fetch_assoc($q);

}

// ── Legacy bcrypt fallback (old sessions) — LIMIT 300 caps delay at ~3-5s ─
if (!$user) {
    $q2 = mysqli_query($conn,
        "SELECT id, email, sname, oname, phone, token, monnify_account_details, bvn
           FROM users_tbl
          WHERE token LIKE '$2y$%' AND status=1
          ORDER BY id DESC LIMIT 200");
    if ($q2) {
        while ($row = mysqli_fetch_assoc($q2)) {
            if (password_verify($incomingToken, $row['token'])) {
                $user = $row;
                break;
            }
        }
    }
}

if (!$user) {
    echo json_encode(["success" => true, "status" => "unauthenticated", "message" => "Invalid token",
        "account_number" => "", "bank_name" => "", "account_name" => ""]);
    exit;
}

// ── STEP 1: Return Monnify account from DB if it already exists ───────────────
if (!empty($user['monnify_account_details'])) {
    $parts    = explode(', ', $user['monnify_account_details']);
    $first    = explode(' - ', trim($parts[0]));
    $allAccts = array_map(function($a) {
        $p = explode(' - ', trim($a));
        return [
            "bank_name"      => trim($p[0] ?? ''),
            "account_number" => trim($p[1] ?? ''),
            "account_name"   => trim($p[2] ?? ''),
            "provider"       => "Monnify",
        ];
    }, $parts);
    echo json_encode([
        "success"        => true,
        "status"         => "active",
        "account_number" => trim($first[1] ?? ''),
        "bank_name"      => trim($first[0] ?? ''),
        "account_name"   => trim($first[2] ?? ''),
        "provider"       => "Monnify",
        "all_accounts"   => $allAccts,
    ]);
    mysqli_close($conn);
    exit;
}

// ── STEP 2: No account — BVN required to generate ─────────────────────────────
if (empty($user['bvn'])) {
    echo json_encode([
        "success"        => true,
        "status"         => "bvn_required",
        "message"        => "Submit your BVN in the KYC section to activate your virtual account.",
        "needs_bvn"      => true,
        "account_number" => "",
        "bank_name"      => "",
        "account_name"   => "",
        "provider"       => "Monnify",
        "all_accounts"   => [],
    ]);
    mysqli_close($conn);
    exit;
}

// ── STEP 3: BVN exists — attempt Monnify reserved account creation ─────────────
$fullName = trim($user['sname'] . ' ' . $user['oname']);
$bvn      = $user['bvn'];

$credQ = mysqli_query($conn, "SELECT setting_key, setting_value FROM edutech_settings WHERE setting_key LIKE 'MONNIFY_%'");
$keys  = [];
while ($r = mysqli_fetch_assoc($credQ)) $keys[$r['setting_key']] = $r['setting_value'];

$apiKey    = $keys['MONNIFY_API_KEY']      ?? '';
$apiSecret = $keys['MONNIFY_API_SECRET']   ?? '881J3RXH6Z6LDVJWG76P1YHW8VCECAE5';
$baseUrl   = rtrim($keys['MONNIFY_BASE_URL'] ?? 'https://api.monnify.com', '/');
$contract  = $keys['MONNIFY_API_CONTRACT'] ?? '';

if (empty($apiKey) || empty($contract)) {
    echo json_encode([
        "success"        => true,
        "status"         => "config_error",
        "message"        => "Payment provider not configured. Contact support.",
        "account_number" => "",
        "bank_name"      => "",
        "account_name"   => "",
        "provider"       => "Monnify",
        "all_accounts"   => [],
    ]);
    mysqli_close($conn);
    exit;
}

// Authenticate
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $baseUrl . '/api/v1/auth/login',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => '',
    CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . base64_encode("$apiKey:$apiSecret"), 'Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$authResp  = curl_exec($ch);
curl_close($ch);
$authData  = json_decode($authResp, true);
$authToken = $authData['responseBody']['accessToken'] ?? null;

if (!$authToken) {
    echo json_encode([
        "success"        => true,
        "status"         => "pending",
        "message"        => "Account setup in progress. Please try again shortly.",
        "account_number" => "",
        "bank_name"      => "",
        "account_name"   => "",
        "provider"       => "Monnify",
        "all_accounts"   => [],
    ]);
    mysqli_close($conn);
    exit;
}

// Create reserved account
$accountRef = 'ADIL_' . intval($user['id']) . '_' . time();
$payload    = json_encode([
    'accountReference'    => $accountRef,
    'accountName'         => $fullName,
    'currencyCode'        => 'NGN',
    'contractCode'        => $contract,
    'customerEmail'       => $user['email'],
    'customerName'        => $fullName,
    'getAllAvailableBanks' => true,
    'bvn'                 => $bvn,
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $baseUrl . '/api/v2/bank-transfer/reserved-accounts',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $authToken, 'Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode([
        "success"        => true,
        "status"         => "pending",
        "message"        => "Network issue. Account setup pending. Please try again.",
        "account_number" => "",
        "bank_name"      => "",
        "account_name"   => "",
        "provider"       => "Monnify",
        "all_accounts"   => [],
    ]);
    mysqli_close($conn);
    exit;
}

$result = json_decode($resp, true);
if (empty($result['requestSuccessful'])) {
    $errMsg = $result['responseMessage'] ?? 'Account creation failed';
    echo json_encode([
        "success"        => true,
        "status"         => "error",
        "message"        => $errMsg,
        "account_number" => "",
        "bank_name"      => "",
        "account_name"   => "",
        "provider"       => "Monnify",
        "all_accounts"   => [],
    ]);
    mysqli_close($conn);
    exit;
}

// ── Save to DB and return ─────────────────────────────────────────────────────
$body     = $result['responseBody'] ?? [];
$accounts = $body['accounts'] ?? [];
$accName  = $body['accountName'] ?? $fullName;

$parts    = [];
$allAccts = [];
foreach ($accounts as $acct) {
    $bn = $acct['bankName']      ?? '';
    $an = $acct['accountNumber'] ?? '';
    if ($bn && $an) {
        $parts[]    = "$bn - $an - $accName";
        $allAccts[] = ['bank_name' => $bn, 'account_number' => $an, 'account_name' => $accName, 'provider' => 'Monnify'];
    }
}

if (empty($parts)) {
    echo json_encode([
        "success"        => true,
        "status"         => "pending",
        "message"        => "Account is being set up. Please try again shortly.",
        "account_number" => "",
        "bank_name"      => "",
        "account_name"   => "",
        "provider"       => "Monnify",
        "all_accounts"   => [],
    ]);
    mysqli_close($conn);
    exit;
}

$detailsStr = implode(', ', $parts);
$em         = mysqli_real_escape_string($conn, $user['email']);
$ds         = mysqli_real_escape_string($conn, $detailsStr);
$ar = mysqli_real_escape_string($conn, $body['accountReference'] ?? '');
mysqli_query($conn, "UPDATE users_tbl SET monnify_account_details='$ds', monnify_account_ref='$ar' WHERE email='$em'");

$primary = $allAccts[0];
echo json_encode([
    "success"        => true,
    "status"         => "active",
    "account_number" => $primary['account_number'],
    "bank_name"      => $primary['bank_name'],
    "account_name"   => $primary['account_name'],
    "provider"       => "Monnify",
    "all_accounts"   => $allAccts,
]);

mysqli_close($conn);
?>
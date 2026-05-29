<?php
/**
 * getAccountDetails.php — Fetch/generate Monnify account for a user.
 * ALWAYS returns success:true so the APK never loops.
 * When account isn't ready, returns status field explaining why.
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
    echo json_encode(["success" => true, "status" => "error", "message" => "Database error", "account_number" => "", "bank_name" => "", "account_name" => ""]);
    exit;
}

// ── Read token ────────────────────────────────────────────────────────────────
$data          = json_decode(@file_get_contents("php://input"), true) ?? [];
$incomingToken = $data['token'] ?? $_POST['token'] ?? $_GET['token'] ?? '';

if (empty($incomingToken)) {
    echo json_encode(["success" => true, "status" => "unauthenticated", "message" => "Token required", "account_number" => "", "bank_name" => "", "account_name" => ""]);
    exit;
}

// ── Verify token — include bvn and monnify_account_details ────────────────────
$q    = mysqli_query($conn, "SELECT id, email, sname, oname, phone, token, monnify_account_details, bvn FROM users_tbl WHERE status=1 AND token IS NOT NULL AND token != ''");
$user = null;
while ($row = mysqli_fetch_assoc($q)) {
    if (password_verify($incomingToken, $row['token']) || $incomingToken === $row['token']) {
        $user = $row;
        break;
    }
}

if (!$user) {
    echo json_encode(["success" => true, "status" => "unauthenticated", "message" => "Invalid token", "account_number" => "", "bank_name" => "", "account_name" => ""]);
    exit;
}

// ── STEP 1: Return account from DB if it already exists ───────────────────────
if (!empty($user['monnify_account_details'])) {
    $parts   = explode(', ', $user['monnify_account_details']);
    $first   = explode(' - ', trim($parts[0]));
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

// ── STEP 2: No account in DB yet. Need BVN to generate one. ──────────────────
if (empty($user['bvn'])) {
    // Cannot generate without BVN — return success:true so APK stops spinning
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

// ── STEP 3: BVN exists — attempt to create Monnify reserved account ───────────
$fullName = trim($user['sname'] . ' ' . $user['oname']);
$bvn      = $user['bvn'];

// Fetch Monnify credentials from DB
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
        "message"        => "Payment provider not configured.",
        "account_number" => "",
        "bank_name"      => "",
        "account_name"   => "",
        "provider"       => "Monnify",
        "all_accounts"   => [],
    ]);
    mysqli_close($conn);
    exit;
}

// Authenticate with Monnify
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
$authResp = curl_exec($ch);
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

// Create reserved account with BVN
$accountRef = 'ADIL_' . $user['id'] . '_' . time();
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
        "message"        => "Network issue. Account setup pending.",
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

// ── Success — save to DB and return ──────────────────────────────────────────
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
mysqli_query($conn, "UPDATE users_tbl SET monnify_account_details='$ds' WHERE email='$em'");

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

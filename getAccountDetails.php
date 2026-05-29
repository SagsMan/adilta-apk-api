<?php
/**
 * getAccountDetails.php — Fetch Monnify account for a user.
 * Returns the primary Monnify reserved account in APK-compatible format.
 * PaymentPoint has been fully removed.
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
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ── Read token ────────────────────────────────────────────────────────────────
$data         = json_decode(file_get_contents("php://input"), true) ?? [];
$incomingToken = $data['token'] ?? $_POST['token'] ?? $_GET['token'] ?? '';

if (empty($incomingToken)) {
    echo json_encode(["success" => false, "message" => "Token required"]);
    exit;
}

// ── Verify token ──────────────────────────────────────────────────────────────
$q    = mysqli_query($conn, "SELECT id, email, sname, oname, phone, token, monnify_account_details FROM users_tbl WHERE status=1 AND token IS NOT NULL AND token != ''");
$user = null;
while ($row = mysqli_fetch_assoc($q)) {
    if (password_verify($incomingToken, $row['token']) || $incomingToken === $row['token']) {
        $user = $row;
        break;
    }
}

if (!$user) {
    echo json_encode(["success" => false, "message" => "Invalid token"]);
    exit;
}

// ── Check for existing Monnify account ────────────────────────────────────────
if (!empty($user['monnify_account_details'])) {
    $parts = explode(', ', $user['monnify_account_details']);
    $first = explode(' - ', trim($parts[0]));
    echo json_encode([
        "success"        => true,
        "account_number" => trim($first[1] ?? ''),
        "bank_name"      => trim($first[0] ?? ''),
        "account_name"   => trim($first[2] ?? ''),
        "provider"       => "Monnify",
        "all_accounts"   => array_map(function($a) {
            $p = explode(' - ', trim($a));
            return [
                "bank_name"      => trim($p[0] ?? ''),
                "account_number" => trim($p[1] ?? ''),
                "account_name"   => trim($p[2] ?? ''),
                "provider"       => "Monnify",
            ];
        }, $parts),
    ]);
    exit;
}

// ── Generate new Monnify account ──────────────────────────────────────────────
$fullName = trim($user['sname'] . ' ' . $user['oname']);

// Fetch Monnify credentials from DB
$credQ = mysqli_query($conn, "SELECT setting_key, setting_value FROM edutech_settings WHERE setting_key LIKE 'MONNIFY_%'");
$keys  = [];
while ($r = mysqli_fetch_assoc($credQ)) $keys[$r['setting_key']] = $r['setting_value'];

$apiKey    = $keys['MONNIFY_API_KEY']     ?? '';
$apiSecret = $keys['MONNIFY_API_SECRET']  ?? '881J3RXH6Z6LDVJWG76P1YHW8VCECAE5';
$baseUrl   = rtrim($keys['MONNIFY_BASE_URL'] ?? 'https://api.monnify.com', '/');
$contract  = $keys['MONNIFY_API_CONTRACT'] ?? '';

if (empty($apiKey) || empty($contract)) {
    echo json_encode(["success" => false, "message" => "Monnify credentials not configured"]);
    exit;
}

// Step 1: Authenticate
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
$authData = json_decode($authResp, true);
$token    = $authData['responseBody']['accessToken'] ?? null;

if (!$token) {
    echo json_encode(["success" => false, "message" => "Monnify authentication failed"]);
    exit;
}

// BVN is required by Monnify production API
if (empty($user['bvn'])) {
    echo json_encode([
        "success"       => false,
        "message"       => "BVN required",
        "needs_bvn"     => true,
        "setup_message" => "Please submit your BVN in the KYC section to activate your virtual account.",
    ]);
    exit;
}

// Step 2: Create reserved account
$accountRef = 'ADIL_' . $user['id'] . '_' . time();
$payload    = json_encode([
    'accountReference'    => $accountRef,
    'accountName'         => $fullName,
    'currencyCode'        => 'NGN',
    'contractCode'        => $contract,
    'customerEmail'       => $user['email'],
    'customerName'        => $fullName,
    'getAllAvailableBanks' => true,
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $baseUrl . '/api/v2/bank-transfer/reserved-accounts',
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
$resp  = curl_exec($ch);
$err   = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(["success" => false, "message" => "Network error: $err"]);
    exit;
}

$result = json_decode($resp, true);
if (empty($result['requestSuccessful'])) {
    echo json_encode(["success" => false, "message" => $result['responseMessage'] ?? 'Account creation failed', "raw" => $resp]);
    exit;
}

$body     = $result['responseBody'] ?? [];
$accounts = $body['accounts'] ?? [];
$accName  = $body['accountName'] ?? $fullName;

// Build details string and save to DB
$parts    = [];
$allAccts = [];
foreach ($accounts as $acct) {
    $bn = $acct['bankName'] ?? '';
    $an = $acct['accountNumber'] ?? '';
    if ($bn && $an) {
        $parts[]    = "$bn - $an - $accName";
        $allAccts[] = ['bank_name' => $bn, 'account_number' => $an, 'account_name' => $accName, 'provider' => 'Monnify'];
    }
}

if (empty($parts)) {
    echo json_encode(["success" => false, "message" => "No accounts returned by Monnify"]);
    exit;
}

$detailsStr = implode(', ', $parts);
$em         = mysqli_real_escape_string($conn, $user['email']);
$ds         = mysqli_real_escape_string($conn, $detailsStr);
mysqli_query($conn, "UPDATE users_tbl SET monnify_account_details='$ds' WHERE email='$em'");

$primary = $allAccts[0];
echo json_encode([
    "success"        => true,
    "account_number" => $primary['account_number'],
    "bank_name"      => $primary['bank_name'],
    "account_name"   => $primary['account_name'],
    "provider"       => "Monnify",
    "all_accounts"   => $allAccts,
]);

mysqli_close($conn);
?>

<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

include_once 'conn.php';

$data     = json_decode(file_get_contents("php://input"), true);
$response = ["success" => false, "message" => ""];

if (empty($data['email']) || empty($data['password'])) {
    $response['message'] = "Email and password required";
    echo json_encode($response); exit;
}

$email    = mysqli_real_escape_string($conn, $data['email']);
$password = $data['password'];

$query = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$email' LIMIT 1");
if (mysqli_num_rows($query) === 0) {
    $response['message'] = "Invalid credentials";
    echo json_encode($response); exit;
}

$user = mysqli_fetch_assoc($query);
if (!password_verify($password, $user['password'])) {
    $response['message'] = "Invalid credentials";
    echo json_encode($response); exit;
}

// Store plain hex token — fast-path verifiable by verifyToken.php and api.php
$rawToken = bin2hex(random_bytes(32));
$ts       = mysqli_real_escape_string($conn, $rawToken);
mysqli_query($conn, "UPDATE users_tbl SET token='$ts' WHERE id=" . intval($user['id']));

// Wipe any old non-Monnify account data silently
$bn = strtolower($user['bank_name'] ?? '');
$an = strtolower($user['acc_name']  ?? '');
if (
    !empty($user['acc_no']) && (
        strpos($bn, 'palmpay') !== false ||
        strpos($bn, 'opay')    !== false ||
        strpos($an, 'rahausub') !== false
    )
) {
    mysqli_query($conn, "UPDATE users_tbl SET acc_no='', acc_name='', bank_name='', acc_no2='', acc_name2='', bank_name2='' WHERE id=" . intval($user['id']));
    $user['acc_no'] = $user['acc_name'] = $user['bank_name'] = '';
}

// Parse Monnify account details (stored as "BankName - AccountNumber - AccountName, ...")
function parse_monnify_str($raw) {
    $accounts = [];
    if (empty($raw)) return $accounts;
    foreach (explode(', ', $raw) as $acct) {
        $p = explode(' - ', trim($acct));
        if (count($p) >= 2) {
            $accounts[] = [
                'bank_name'      => trim($p[0]),
                'account_number' => trim($p[1]),
                'account_name'   => trim($p[2] ?? ''),
            ];
        }
    }
    return $accounts;
}

$accNo = $accName = $bankName = '';
$monnifyRaw = $user['monnify_account_details'] ?? '';
$mAccounts  = parse_monnify_str($monnifyRaw);

if (!empty($mAccounts)) {
    $accNo    = $mAccounts[0]['account_number'];
    $accName  = $mAccounts[0]['account_name'];
    $bankName = $mAccounts[0]['bank_name'];
} elseif (!empty($user['acc_no'])) {
    $accNo    = $user['acc_no'];
    $accName  = $user['acc_name'];
    $bankName = $user['bank_name'];
}

echo json_encode([
    "success"        => true,
    "message"        => "Login successful",
    "token"          => $rawToken,
    "finger"         => (bool)(int)$user['finger'],
    "account_status" => !empty($accNo) ? "active" : "pending",
    "account_number" => $accNo,
    "account_name"   => $accName,
    "bank_name"      => $bankName,
    "user"           => [
        "id"     => $user['id'],
        "email"  => $user['email'],
        "haspin" => !empty($user['pin']),
        "phone"  => $user['phone'],
        "name"   => trim($user['sname'] . ' ' . $user['oname']),
    ],
]);
?>

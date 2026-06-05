<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

include_once 'conn.php';
require_once 'generateBankAccount.php';

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

$rawToken = bin2hex(random_bytes(32));
$token    = password_hash($rawToken, PASSWORD_BCRYPT);
mysqli_query($conn, "UPDATE users_tbl SET token='$token' WHERE id=" . $user['id']);

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
    mysqli_query($conn, "UPDATE users_tbl SET acc_no='', acc_name='', bank_name='', acc_no2='', acc_name2='', bank_name2='' WHERE id=" . $user['id']);
    $user['acc_no'] = $user['acc_name'] = $user['bank_name'] = '';
}

// Read Monnify account
$accNo = $accName = $bankName = '';
$md = json_decode($user['monnify_account_details'] ?? '', true);
if (!empty($md['accounts'])) {
    $accNo    = $md['accounts'][0]['accountNumber'] ?? '';
    $accName  = $md['accountName'] ?? '';
    $bankName = $md['accounts'][0]['bankName'] ?? '';
} elseif (!empty($user['acc_no'])) {
    $accNo    = $user['acc_no'];
    $accName  = $user['acc_name'];
    $bankName = $user['bank_name'];
}

// Try generating if still empty
if (empty($accNo)) {
    $fullName  = trim($user['sname'] . ' ' . $user['oname']);
    $generated = generateBankAccount($user['email'], $fullName, $user['phone']);
    if ($generated['success']) {
        $fresh = mysqli_fetch_assoc(mysqli_query($conn, "SELECT acc_no, acc_name, bank_name, monnify_account_details FROM users_tbl WHERE id=" . $user['id']));
        $md2   = json_decode($fresh['monnify_account_details'] ?? '', true);
        if (!empty($md2['accounts'])) {
            $accNo    = $md2['accounts'][0]['accountNumber'] ?? '';
            $accName  = $md2['accountName'] ?? '';
            $bankName = $md2['accounts'][0]['bankName'] ?? '';
        } else {
            $accNo    = $fresh['acc_no']    ?? '';
            $accName  = $fresh['acc_name']  ?? '';
            $bankName = $fresh['bank_name'] ?? '';
        }
    }
}

echo json_encode([
    "success"        => true,
    "message"        => "Login successful",
    "token"          => $rawToken,
    "finger"         => $user['finger'],
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

<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json");
include_once 'conn.php';
require_once 'generateBankAccount.php';

$data = json_decode(file_get_contents("php://input"), true);

$response = [
    "success" => false,
    "message" => "",
    "errors"  => []
];

if (
    empty($data['fullName']) ||
    empty($data['email'])    ||
    empty($data['phone'])    ||
    empty($data['password']) ||
    empty($data['state'])
) {
    $response['message'] = "All fields are required";
    echo json_encode($response);
    exit;
}

// Split full name into sname + oname
$names = explode(" ", trim($data['fullName']), 2);
$sname = $names[0];
$oname = $names[1] ?? '';

$email    = mysqli_real_escape_string($conn, $data['email']);
$phone    = mysqli_real_escape_string($conn, $data['phone']);
$state    = mysqli_real_escape_string($conn, $data['state']);
$snameSafe = mysqli_real_escape_string($conn, $sname);
$onameSafe = mysqli_real_escape_string($conn, $oname);
$password = password_hash($data['password'], PASSWORD_DEFAULT);

// Check duplicate
$check = mysqli_query($conn, "SELECT id FROM users_tbl WHERE email='$email' OR phone='$phone'");
if (mysqli_num_rows($check) > 0) {
    $response['message'] = "User already registered";
    echo json_encode($response);
    exit;
}

// Insert user
$query = "INSERT INTO users_tbl (sname, oname, password, email, phone, state)
          VALUES ('$snameSafe', '$onameSafe', '$password', '$email', '$phone', '$state')";

if (!mysqli_query($conn, $query)) {
    $response['message'] = "Database error: " . mysqli_error($conn);
    echo json_encode($response);
    exit;
}

$newUserId = mysqli_insert_id($conn);

// Auto-generate Monnify reserved account immediately after signup
$accNo    = '';
$accName  = '';
$bankName = '';

$fullName  = trim($sname . ' ' . $oname);
$generated = generateBankAccount($data['email'], $fullName, $data['phone']);

if ($generated['success']) {
    $fresh = mysqli_query($conn, "SELECT acc_no, acc_name, bank_name FROM users_tbl WHERE id=$newUserId");
    if ($fresh && mysqli_num_rows($fresh) > 0) {
        $freshRow = mysqli_fetch_assoc($fresh);
        $accNo    = $freshRow['acc_no'];
        $accName  = $freshRow['acc_name'];
        $bankName = $freshRow['bank_name'];
    }
}

$response['success']        = true;
$response['message']        = "Registration successful";
$response['account_number'] = $accNo;
$response['account_name']   = $accName;
$response['bank_name']      = $bankName;

echo json_encode($response);
?>

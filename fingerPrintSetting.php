<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';
require_once 'transactionToken.php';

// 🔹 Get input
$data = json_decode(file_get_contents("php://input"), true);

$token = $data['token'] ?? '';

if (empty($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Token is required"
    ]);
    exit;
}

// 🔐 Verify user
$verify = verifyUserToken($conn, $token);
if (!$verify['success']) {
    echo json_encode($verify);
    exit;
}

$user = $verify['user'];
$userId = $user['email'];

// 🔍 Get current fingerprint status
$query = mysqli_query($conn, "SELECT finger FROM users_tbl WHERE email='$userId'");
$row = mysqli_fetch_assoc($query);

if (!$row) {
    echo json_encode([
        "success" => false,
        "message" => "User not found"
    ]);
    exit;
}

// 🔁 Toggle value
$current = (int)$row['finger'];
$newValue = $current === 1 ? 0 : 1;

// 🔄 Update
$update = mysqli_query($conn, "UPDATE users_tbl SET finger='$newValue' WHERE email='$userId'");

if (!$update) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to update fingerprint setting"
    ]);
    exit;
}

// ✅ Response
echo json_encode([
    "success" => true,
    "message" => $newValue ? "Fingerprint enabled" : "Fingerprint disabled",
    "finger" => (bool)$newValue
]);
?>
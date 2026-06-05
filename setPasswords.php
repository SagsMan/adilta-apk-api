<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';
require_once 'transactionToken.php';

$data = json_decode(file_get_contents("php://input"), true);

// 🔹 Inputs
$token    = $data['token'] ?? '';
$type     = $data['type'] ?? ''; // loginPassword | transactionPin
$value    = $data['value'] ?? ''; // new password or pin

if (empty($token) || empty($type) || empty($value)) {
    echo json_encode([
        "success" => false,
        "message" => "All fields required"
    ]);
    exit;
}

// 🔐 Verify user
$verify = verifyUserToken($conn, $token);
if (!$verify['success']) {
    echo json_encode($verify);
    exit;
}

$user   = $verify['user'];
$userId = $user['email'];

// 🔍 Validate type
if ($type !== "loginPassword" && $type !== "transactionPin") {
    echo json_encode([
        "success" => false,
        "message" => "Invalid type"
    ]);
    exit;
}

// 🔐 LOGIN PASSWORD UPDATE
if ($type === "loginPassword") {

    if (strlen($value) < 6) {
        echo json_encode([
            "success" => false,
            "message" => "Password must be at least 6 characters"
        ]);
        exit;
    }

    $hashed = password_hash($value, PASSWORD_DEFAULT);

    $update = mysqli_query($conn, "
        UPDATE users_tbl 
        SET password='$hashed' 
        WHERE email='$userId'
    ");

    if (!$update) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to update password"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Password updated successfully"
    ]);
    exit;
}

// 🔢 TRANSACTION PIN UPDATE
if ($type === "transactionPin") {

    if (!preg_match('/^[0-9]{4,6}$/', $value)) {
        echo json_encode([
            "success" => false,
            "message" => "PIN must be 4–6 digits"
        ]);
        exit;
    }

    $hashedPin = md5($value);

    $update = mysqli_query($conn, "
        UPDATE users_tbl 
        SET pin='$hashedPin' 
        WHERE email='$userId'
    ");

    if (!$update) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to update PIN"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Transaction PIN updated successfully"
    ]);
    exit;
}
?>
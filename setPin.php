<?php
header("Content-Type: application/json");

// include your DB connection
include_once "conn.php"; // make sure this connects to $conn (mysqli or PDO)

// get raw JSON input
$data = json_decode(file_get_contents("php://input"), true);

// validate input
if (!isset($data['token']) || !isset($data['pin'])) {
    echo json_encode([
        "success" => false,
        "message" => "Missing token or PIN"
    ]);
    exit;
}

$token = trim($data['token']);
$pin   = trim($data['pin']);

// validate PIN (must be exactly 4 digits)
if (!preg_match('/^\d{4}$/', $pin)) {
    echo json_encode([
        "success" => false,
        "message" => "PIN must be 4 digits"
    ]);
    exit;
}

// 🔐 hash the PIN (matching your web system)
$hashedPin = md5($pin);

// find user by token
$result = $conn->query("SELECT id, token FROM users_tbl");

$userId = null;

while ($row = $result->fetch_assoc()) {
    if (password_verify($token, $row['token'])) {
        $userId = $row['id'];
        break;
    }
}

// find user by token
$result = $conn->query("SELECT id, token FROM users_tbl");

$userId = null;

while ($row = $result->fetch_assoc()) {
    if (password_verify($token, $row['token'])) {
        $userId = $row['id'];
        break;
    }
}

if (!$userId) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid user"
    ]);
    exit;
}

// update PIN
$update = $conn->prepare("UPDATE users_tbl SET pin = ? WHERE id = ?");
$update->bind_param("si", $hashedPin, $userId);

if ($update->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "PIN set successfully"
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Failed to update PIN"
    ]);
}

?>
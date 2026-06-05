<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include_once 'conn.php';

$data = json_decode(file_get_contents("php://input"), true);

$response = [
    "success" => false,
];

// ✅ Check token
if (empty($data['token'])) {
    $response['message'] = "Token required";
    echo json_encode($response);
    exit;
}

$incomingToken = $data['token'];

// 🔍 Verify token → get email
$query = mysqli_query($conn, "SELECT email, token FROM users_tbl WHERE token IS NOT NULL");

$email = null;

while ($row = mysqli_fetch_assoc($query)) {
    if (password_verify($incomingToken, $row['token'])) {
        $email = $row['email'];
        break;
    }
}

// ❌ Invalid token
if (!$email) {
    $response['message'] = "Invalid token";
    echo json_encode($response);
    exit;
}

// 💰 Check wallet
$stmt = $conn->prepare("SELECT balance FROM wallet_tbl WHERE user_id = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

$balance = 0;

if ($result && $result->num_rows > 0) {
    // ✅ Wallet exists
    $wallet = $result->fetch_assoc();
    $balance = $wallet['balance'];

} else {
    // 🚀 Wallet NOT found → create it
    $insert = $conn->prepare("
        INSERT INTO wallet_tbl (user_id, balance, status, last_transanction)
        VALUES (?, 0, 1, NOW())
    ");
    $insert->bind_param("s", $email);
    $insert->execute();

    $balance = 0;
}

// ✅ Response
$response['success'] = true;
$response['email'] = $email;
$response['balance'] = $balance;

echo json_encode($response);
?>
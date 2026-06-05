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

if (empty($data['token'])) {
    echo json_encode($response);
    exit;
}

$incomingToken = $data['token'];

// Get all users with tokens
$query = mysqli_query($conn, "SELECT id, token FROM users_tbl WHERE token IS NOT NULL");

while ($row = mysqli_fetch_assoc($query)) {

    // 🔐 Verify hashed token
    if (password_verify($incomingToken, $row['token'])) {

        $response['success'] = true;
        $response['user_id'] = $row['id'];

        echo json_encode($response);
        exit;
    }
}

// If no match
echo json_encode($response);
?>
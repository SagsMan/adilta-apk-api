<?php
header("Content-Type: application/json");
include_once 'conn.php';
require_once 'transactionToken.php';

$data = json_decode(file_get_contents("php://input"), true);
$token = $data['token'] ?? '';

if (!$token) {
    echo json_encode(["success" => false, "message" => "Token required"]);
    exit;
}

// Verify user
$verify = verifyUserToken($conn, $token);
if (!$verify['success']) {
    echo json_encode($verify);
    exit;
}

$user = $verify['user'];
$email = $user['email'];

// Fetch transactions
$q = mysqli_query($conn, "SELECT * FROM transactions_tbl WHERE email='$email' ORDER BY id DESC LIMIT 50");
$transactions = [];

while ($row = mysqli_fetch_assoc($q)) {
    $response = $row['response_description'];
    $fullReceipt = null;
    
    if ($response) {
        $decoded = json_decode($response, true);
        if ($decoded) $fullReceipt = $decoded;
    }

    $transactions[] = [
        "id" => $row['id'],
        "title" => $row['product_name'] ?? "Transaction",
        "phone" => $row['phone'] ?? "-",
        "date" => $row['transaction_date'] ?? "-",
        "subtitle" => ($row['status'] == 1) ? "Purchase Successfully" : "Failed / Refunded",
        "amount" => (($row['status'] == 1 ? "- " : "+ ") . "N" . number_format($row['amount'], 0)),
        "negative" => $row['status'] == 1,
        "fullReceipt" => $fullReceipt
    ];
}

echo json_encode([
    "success" => true,
    "transactions" => $transactions
]);
?>
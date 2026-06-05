<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';
require_once 'transactionToken.php';

$data = json_decode(file_get_contents("php://input"), true);

$response = ["success" => false];

// 🔹 Inputs
$token       = $data['token'] ?? '';
$amount      = $data['amount'] ?? 0;
$number      = $data['number'] ?? '';
$serviceID   = $data['serviceID'] ?? '';   // ✅ FIXED
$variation   = $data['variation'] ?? '';   // ✅ FIXED
$pin         = $data['pin'] ?? '';

if (!$token || !$amount || !$number || !$serviceID || !$variation || !$pin) {
    echo json_encode(["success" => false, "message" => "All fields required"]);
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
$email  = $user['email'];
$userPhone   = $number;

// 🔐 Verify PIN
if ($pin !== "fingerprint") {
    if (md5($pin) !== $user['pin']) {
        echo json_encode(["success" => false, "message" => "Invalid PIN"]);
        exit;
    }
}

// 💰 Check wallet
$walletQ = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$userId'");
$wallet  = mysqli_fetch_assoc($walletQ);

if (!$wallet || $wallet['balance'] < $amount) {
    echo json_encode(["success" => false, "message" => "Insufficient balance"]);
    exit;
}

// 🔽 Deduct first
$newBalance = $wallet['balance'] - $amount;
mysqli_query($conn, "UPDATE wallet_tbl SET balance='$newBalance' WHERE user_id='$userId'");

// 🔥 GET ACTIVE API
$apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name = 'vtpass'");
$api  = mysqli_fetch_assoc($apiQ);

if (!$api) {
    echo json_encode(["success" => false, "message" => "No active API"]);
    exit;
}

$apiUrl = rtrim($api['api_url'], '/') . "/api/pay";
$apiKey = $api['api_key'];
$secret = $api['secret'];

// 🔥 Generate request ID
$requestId = "DATA_" . time() . "_" . rand(1000,9999);


// 🔥 VTpass Request Body
$params = [
    "request_id"     => $requestId,
    "serviceID"      => strtolower($serviceID),
    "billersCode"    => $number,      // recipient
    "variation_code" => $variation,
    "amount"         => $amount,
    "phone"          => $userPhone    // logged-in user
];

// 🔥 cURL
$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($params),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "api-key: $apiKey",
        "secret-key: $secret"
    ],
    CURLOPT_TIMEOUT => 30,
]);

$apiResponse = curl_exec($curl);
$curlError   = curl_error($curl);
curl_close($curl);

$res = json_decode($apiResponse, true);

// ❌ Error handling
if ($curlError || !$res) {
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");
    echo json_encode(["success" => false, "message" => "API Error"]);
    exit;
}

// 🔍 Success check
$status = strtolower($res['code'] ?? '') === "000";

if (!$status) {
    // rollback
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");
}

// Extract
$transactionId = $res['content']['transactions']['transactionId'] ?? null;
$productName   = $res['content']['transactions']['product_name'] ?? "Data Purchase";

// Save transaction
mysqli_query($conn, "
    INSERT INTO transactions_tbl 
    (unique_element, amount, real_amount, email, phone, transaction_id, request_id, product_name, response_description, status, transaction_date, is_bill, our_commission)
    VALUES 
    ('$number', '$amount', '$amount', '$email', '$number', '$transactionId', '$requestId', '$productName', '".json_encode($res)."', '".($status ? 1 : 0)."', NOW(), 1, 0)
");

// Response
echo json_encode([
    "success" => $status,
    "message" => $status ? "Data purchase successful" : "Transaction failed, refunded",
    "balance" => $status ? $newBalance : $wallet['balance'],
    "api_response" => $res
]);
?>
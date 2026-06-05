<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';
require_once 'transactionToken.php';

$data = json_decode(file_get_contents("php://input"), true);

$response = ["success" => false];
// 🔹 Inputs
$token     = $data['token'] ?? '';
$amount    = $data['amount'] ?? 0;
$number    = $data['number'] ?? '';
$serviceID = $data['network'] ?? ''; // now using serviceID (mtn, glo, airtel)
$pin       = $data['pin'] ?? '';

if (!$token || !$amount || !$number || !$serviceID || !$pin) {
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

$apiUrl   = rtrim($api['api_url'], '/') . "/api/pay";
$apiKey   = $api['api_key'];
$secret   = $api['secret'];

// 🔥 Generate request ID
$requestId = uniqid("airtime_");

// 🔥 VTpass Request Body
$params = [
    "request_id" => $requestId,
    "serviceID"  => strtolower($serviceID), // mtn, glo, airtel, 9mobile
    "amount"     => $amount,
    "phone"      => $number
];

// 🔥 cURL Request
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
$httpCode    = curl_getinfo($curl, CURLINFO_HTTP_CODE);

curl_close($curl);

$res = json_decode($apiResponse, true);

// ❌ cURL Error
if ($curlError) {
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");
    echo json_encode(["success" => false, "message" => $curlError]);
    exit;
}

// ❌ Invalid response
if (!$res) {
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");
    echo json_encode(["success" => false, "message" => "Invalid API response"]);
    exit;
}

// 🔍 VTpass success check
$status = strtolower($res['code'] ?? '') === "000";

if (!$status) {
    // rollback
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");
}

// Extract data
$transactionId = $res['content']['transactions']['transactionId'] ?? null;
$productName   = strtoupper($serviceID) . " Airtime";
$responseDesc  = json_encode($res);

// Save transaction
mysqli_query($conn, "
    INSERT INTO transactions_tbl 
    (unique_element, amount, real_amount, email, phone, transaction_id, request_id, product_name, response_description, status, transaction_date, is_bill, our_commission)
    VALUES 
    ('$number', '$amount', '$amount', '$email', '$number', '$transactionId', '$requestId', '$productName', '$responseDesc', '".($status ? 1 : 0)."', NOW(), 1, 0)
");

// Final response
echo json_encode([
    "success" => $status,
    "message" => $status ? "Airtime successful" : "Transaction failed, refunded",
    "balance" => $status ? $newBalance : $wallet['balance'],
    "api_response" => $res
]);
?>
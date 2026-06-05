<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';
require_once 'transactionToken.php';

/* =======================
   🔥 LOGGER FUNCTION
======================= */
function logToFile($message) {
    $file = __DIR__ . '/api_debug.log';
    $time = date("Y-m-d H:i:s");
    file_put_contents($file, "[$time] " . $message . PHP_EOL, FILE_APPEND);
}

/* =======================
   🔹 INPUT
======================= */
$data = json_decode(file_get_contents("php://input"), true);

$token   = $data['token'] ?? '';
$number  = $data['number'] ?? '';
$planId  = $data['plan_id'] ?? '';
$pin     = $data['pin'] ?? '';

if (empty($token) || empty($number) || empty($planId) || empty($pin)) {
    echo json_encode(["success" => false, "message" => "All fields required"]);
    exit;
}

/* =======================
   🔐 VERIFY USER
======================= */
$verify = verifyUserToken($conn, $token);
if (!$verify['success']) {
    echo json_encode($verify);
    exit;
}

$user   = $verify['user'];
$userId = $user['email'];
$email  = $user['email'];

/* =======================
   🔐 PIN CHECK
======================= */
if ($pin !== "fingerprint") {
    if (md5($pin) !== $user['pin']) {
        echo json_encode(["success" => false, "message" => "Invalid PIN"]);
        exit;
    }
}

/* =======================
   🔍 GET PLAN
======================= */
$planQ = mysqli_query($conn, "
    SELECT id, plan_id, plan, price, api_id, network_id
    FROM plans 
    WHERE id = '$planId'
");

$plan = mysqli_fetch_assoc($planQ);

if (!$plan) {
    echo json_encode(["success" => false, "message" => "Invalid plan"]);
    exit;
}

$amount = $plan['price'];
$api_id = $plan['api_id'];

logToFile("==== NEW REQUEST ====");
logToFile("User: $userId | PlanID: $planId | Phone: $number");

/* =======================
   💰 WALLET CHECK
======================= */
$walletQ = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$userId'");
$wallet  = mysqli_fetch_assoc($walletQ);

if (!$wallet || $wallet['balance'] < $amount) {
    echo json_encode(["success" => false, "message" => "Insufficient balance"]);
    exit;
}

/* =======================
   🔽 DEDUCT WALLET
======================= */
$newBalance = $wallet['balance'] - $amount;
mysqli_query($conn, "UPDATE wallet_tbl SET balance='$newBalance' WHERE user_id='$userId'");

/* =======================
   🔥 GET API
======================= */
$apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE id='$api_id' AND is_active=1");
$api  = mysqli_fetch_assoc($apiQ);

if (!$api) {
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");
    echo json_encode(["success" => false, "message" => "API not available"]);
    exit;
}

/* =======================
   🚀 PREPARE REQUEST
======================= */
$payload = json_encode([
    "network"       => $plan['network_id'],
    "mobile_number" => $number,
    "plan"          => $plan['plan_id'],
    "Ported_number" => true
]);

logToFile("API URL: " . $api['api_url']);
logToFile("Payload: " . $payload);

/* =======================
   🔥 CURL REQUEST
======================= */
$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => $api['api_url'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        "Authorization: Token " . $api['api_key'],
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($curl);
$error = curl_error($curl);
curl_close($curl);

logToFile("RAW RESPONSE: " . $response);
logToFile("CURL ERROR: " . $error);

$res = json_decode($response, true);

if ($error || !$res) {
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");
    echo json_encode(["success" => false, "message" => "API Error"]);
    exit;
}

logToFile("DECODED RESPONSE: " . json_encode($res));

/* =======================
   🔍 SUCCESS DETECTION (FIXED)
======================= */
$status = false;

if (isset($res['Status'])) {
    $status = strtolower($res['Status']) === "successful" || strtolower($res['Status']) === "success";
}
elseif (isset($res['status'])) {
    $status = strtolower($res['status']) === "success" || strtolower($res['status']) === "successful";
}
elseif (isset($res['code'])) {
    $status = $res['code'] == 200 || strtolower($res['code']) === "success";
}

logToFile("STATUS RESULT: " . ($status ? "SUCCESS" : "FAILED"));

/* =======================
   🔁 ROLLBACK IF FAILED
======================= */
if (!$status) {
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");
}

/* =======================
   🧾 TRANSACTION ID
======================= */
$transactionId = $res['id'] ?? $res['transaction_id'] ?? uniqid("txn_");

/* =======================
   💾 SAVE TRANSACTION (FIXED SQL)
======================= */
$responseData = mysqli_real_escape_string($conn, json_encode($res));
$planName     = mysqli_real_escape_string($conn, $plan['plan']);

mysqli_query($conn, "
    INSERT INTO transactions_tbl 
    (unique_element, amount, real_amount, email, phone, transaction_id, request_id, product_name, response_description, status, transaction_date, is_bill, our_commission)
    VALUES 
    ('$number', '$amount', '$amount', '$email', '$number', '$transactionId', '".uniqid("plan_")."', '$planName', '$responseData', '".($status ? 1 : 0)."', NOW(), 1, 0)
");

/* =======================
   ✅ FINAL RESPONSE
======================= */
$responseOut = [
    "success" => $status,
    "message" => $status ? "Purchase successful" : "Transaction failed",
    "balance" => $status ? $newBalance : $wallet['balance'],
    "api_used" => $api['api_name'] ?? $api_id,
    "api_response" => $res
];

logToFile("FINAL RESPONSE: " . json_encode($responseOut));

echo json_encode($responseOut);
?>
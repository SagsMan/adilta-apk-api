<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';
require_once 'transactionToken.php';

// 🔥 LOGGER
function writeLog($filename, $data) {
    $logDir = __DIR__ . "/logs/";
    if (!file_exists($logDir)) mkdir($logDir, 0777, true);
    file_put_contents($logDir . $filename, "[" . date("Y-m-d H:i:s") . "] " . $data . PHP_EOL, FILE_APPEND);
}

$data = json_decode(file_get_contents("php://input"), true);

writeLog("electricity.log", "PURCHASE REQUEST: " . json_encode($data));

// 🔹 Inputs
$token      = $data['token'] ?? '';
$meter      = $data['meter'] ?? '';
$serviceID  = $data['serviceID'] ?? '';
$type       = $data['type'] ?? '';
$amount     = $data['amount'] ?? 0;
$phone      = $data['phone'] ?? '';
$pin        = $data['pin'] ?? '';

if (!$token || !$meter || !$serviceID || !$type || !$amount || !$pin) {
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
$userPhone = $user['phone'];

// 🔐 PIN
if ($pin !== "fingerprint") {
    if (md5($pin) !== $user['pin']) {
        echo json_encode(["success" => false, "message" => "Invalid PIN"]);
        exit;
    }
}

// 💰 Wallet
$walletQ = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$userId'");
$wallet = mysqli_fetch_assoc($walletQ);

if (!$wallet || $wallet['balance'] < $amount) {
    echo json_encode(["success" => false, "message" => "Insufficient balance"]);
    exit;
}

// 🔥 API config
$apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name='vtpass'");
$api = mysqli_fetch_assoc($apiQ);

$url = rtrim($api['api_url'], '/') . "/api/pay";

$requestId = "ELEC_" . time() . "_" . rand(1000,9999);

// 💸 Deduct
$newBalance = $wallet['balance'] - $amount;
mysqli_query($conn, "UPDATE wallet_tbl SET balance='$newBalance' WHERE user_id='$userId'");

// 🔥 Payload
$params = [
    "request_id"  => $requestId,
    "serviceID"   => strtolower($serviceID),
    "billersCode" => $meter,
    "variation_code" => strtolower($type),
    "amount"      => $amount,
    "phone"       => $phone ?: $userPhone
];

// 🔥 cURL
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($params),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "api-key: " . $api['api_key'],
        "secret-key: " . $api['secret']
    ],
]);

$response = curl_exec($curl);
$err      = curl_error($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

curl_close($curl);

// 🔥 Decode
$res = json_decode($response, true);

// 🔥 FULL LOGGING
writeLog("electricity.log", "==============================");
writeLog("electricity.log", "REQUEST ID: $requestId");
writeLog("electricity.log", "REQUEST DATA: " . json_encode($params));
writeLog("electricity.log", "RAW RESPONSE: " . $response);
writeLog("electricity.log", "DECODED RESPONSE: " . json_encode($res));
writeLog("electricity.log", "HTTP CODE: " . $httpCode);
writeLog("electricity.log", "CURL ERROR: " . ($err ?: "NONE"));
writeLog("electricity.log", "==============================");


// ❌ fail → rollback
if ($err || !$res || ($res['code'] ?? '') !== "000") {
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");

    echo json_encode([
        "success" => false,
        "message" => "Transaction failed, refunded"
    ]);
    exit;
}

// 🔥 GET RAW TOKEN FROM CORRECT LOCATION
$rawToken = $res['token'] 
         ?? $res['purchased_code'] 
         ?? '';

// 🔥 CLEAN TOKEN (remove "Token : ")
$tokenCode = preg_replace('/\D/', '', $rawToken);

if (!$tokenCode) {
    $tokenCode = "N/A";
}


// 💾 Save
mysqli_query($conn, "
INSERT INTO transactions_tbl 
(unique_element, amount, email, phone, transaction_id, request_id, product_name, response_description, status, transaction_date, is_bill)
VALUES 
('$meter', '$amount', '$email', '$meter', '$tokenCode', '$requestId', 'Electricity', '".mysqli_real_escape_string($conn, json_encode($res))."', 1, NOW(), 1)
");

// ✅ response
echo json_encode([
    "success" => true,
    "message" => "Electricity purchase successful",
    "token" => $tokenCode,
    "balance" => $newBalance
]);
?>
<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';
require_once 'transactionToken.php';

$data = json_decode(file_get_contents("php://input"), true);

$response = ["success" => false];

// 🔹 Inputs
$token        = $data['token'] ?? '';
$amount       = $data['amount'] ?? 0;
$smartcard    = $data['smartcard'] ?? '';
$serviceID    = $data['serviceID'] ?? '';      // gotv, dstv, startimes
$variation    = $data['variation'] ?? '';      // bouquet code
$pin          = $data['pin'] ?? '';
$phone        = $data['phone'] ?? '';

// 🔴 Validation
if (!$token || !$amount || !$smartcard || !$serviceID || !$variation || !$pin) {
    echo json_encode(["success" => false, "message" => "All fields required"]);
    exit;
}

// 🔐 Verify user token
$verify = verifyUserToken($conn, $token);
if (!$verify['success']) {
    echo json_encode($verify);
    exit;
}

$user   = $verify['user'];
$userId = $user['email'];
$email  = $user['email'];
$userPhone = $user['phone'];

// 🔐 PIN check
if ($pin !== "fingerprint") {
    if (md5($pin) !== $user['pin']) {
        echo json_encode(["success" => false, "message" => "Invalid PIN"]);
        exit;
    }
}

// 💰 Wallet check
$walletQ = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$userId'");
$wallet  = mysqli_fetch_assoc($walletQ);

if (!$wallet || $wallet['balance'] < $amount) {
    echo json_encode(["success" => false, "message" => "Insufficient balance"]);
    exit;
}

// 🔥 Get API config
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
$requestId = "TV_" . time() . "_" . rand(1000,9999);

// 🔥 VTpass payload
$params = [
    "request_id"     => $requestId,
    "serviceID"      => strtolower($serviceID),
    "billersCode"    => $smartcard,
    "variation_code" => $variation,
    "amount"         => $amount,
    "phone"          => $phone ?: $userPhone
];

// 💸 Deduct wallet BEFORE request
$newBalance = $wallet['balance'] - $amount;
mysqli_query($conn, "UPDATE wallet_tbl SET balance='$newBalance' WHERE user_id='$userId'");

// 🔥 cURL request
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

// ❌ API failure → rollback
if ($curlError || !$res) {
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");

    echo json_encode([
        "success" => false,
        "message" => "API Error, transaction reversed"
    ]);
    exit;
}

// 🔍 success check (VTpass standard code = 000)
$status = strtolower($res['code'] ?? '') === "000";

// ❌ failed → rollback
if (!$status) {
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$userId'");
}

// 🔥 Extract response safely
$transactionId = $res['content']['transactions']['transactionId'] ?? null;
$productName   = $res['content']['transactions']['product_name'] ?? "TV Subscription";

// 💾 Save transaction
mysqli_query($conn, "
    INSERT INTO transactions_tbl 
    (unique_element, amount, real_amount, email, phone, transaction_id, request_id, product_name, response_description, status, transaction_date, is_bill, our_commission)
    VALUES 
    ('$smartcard', '$amount', '$amount', '$email', '$smartcard', '$transactionId', '$requestId', '$productName', '".mysqli_real_escape_string($conn, json_encode($res))."', '".($status ? 1 : 0)."', NOW(), 1, 0)
");

// 📤 Response
echo json_encode([
    "success" => $status,
    "message" => $status ? "TV subscription successful" : "Transaction failed, refunded",
    "balance" => $status ? $newBalance : $wallet['balance'],
    "request_id" => $requestId,
    "transaction_id" => $transactionId,
    "api_response" => $res
]);
?>

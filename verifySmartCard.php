<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';

$data = json_decode(file_get_contents("php://input"), true);

$smartcard = $data['smartcard'] ?? '';
$serviceID = $data['serviceID'] ?? '';

if (!$smartcard || !$serviceID) {
    echo json_encode([
        "success" => false,
        "message" => "smartcard and serviceID are required"
    ]);
    exit;
}

// 🔥 Get API settings
$apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name = 'vtpass'");
$api  = mysqli_fetch_assoc($apiQ);

if (!$api) {
    echo json_encode([
        "success" => false,
        "message" => "API not configured"
    ]);
    exit;
}

$apiUrl = rtrim($api['api_url'], '/') . "/api/merchant-verify";
$apiKey = $api['api_key'];
$secret = $api['secret'];

// 🔥 Payload
$params = [
    "billersCode" => $smartcard,
    "serviceID"   => strtolower($serviceID)
];

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

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

$res = json_decode($response, true);

// ❌ error handling
if ($err || !$res) {
    echo json_encode([
        "success" => false,
        "message" => "Verification failed",
        "error" => $err
    ]);
    exit;
}

// 🔍 extract useful info (VTpass structure safe parsing)
$customerName = $res['content']['Customer_Name'] ?? null;
$status       = $res['code'] ?? null;

// ❌ invalid smartcard
if (!$customerName) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid smartcard or unable to verify",
        "api_response" => $res
    ]);
    exit;
}

// ✅ success response
echo json_encode([
    "success" => true,
    "message" => "Verification successful",
    "data" => [
        "customer_name" => $customerName,
        "status" => $status,
        "raw" => $res
    ]
]);
?>
<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';

// 🔥 LOGGER
function writeLog($filename, $data) {
    $logDir = __DIR__ . "/logs/";
    if (!file_exists($logDir)) mkdir($logDir, 0777, true);
    file_put_contents($logDir . $filename, "[" . date("Y-m-d H:i:s") . "] " . $data . PHP_EOL, FILE_APPEND);
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

writeLog("electricity.log", "VERIFY REQUEST: " . $rawInput);

$meter = $data['meter'] ?? '';
$serviceID = $data['serviceID'] ?? ''; // e.g. jos-electric
$type = $data['type'] ?? ''; // prepaid or postpaid

if (!$meter || !$serviceID || !$type) {
    echo json_encode([
        "code" => "999",
        "message" => "meter, serviceID and type required"
    ]);
    exit;
}

// 🔥 API config
$apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name='vtpass'");
$api = mysqli_fetch_assoc($apiQ);

$url = rtrim($api['api_url'], '/') . "/api/merchant-verify";

$params = [
    "billersCode" => $meter,
    "serviceID"   => strtolower($serviceID),
    "type"        => strtolower($type)
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
$err = curl_error($curl);
curl_close($curl);

writeLog("electricity.log", "VERIFY RESPONSE: " . $response);

// ❌ error
if ($err || !$response) {
    echo json_encode([
        "code" => "999",
        "message" => "Verification failed"
    ]);
    exit;
}

// ✅ RETURN RAW (frontend already expects VTpass style)
echo $response;
?>
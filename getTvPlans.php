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

// 🔽 INPUT
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);



$serviceID = $data['serviceID'] ?? '';

if (!$serviceID) {

    echo json_encode([
        "response_description" => "999",
        "message" => "serviceID is required"
    ]);
    exit;
}

// 🔥 GET API SETTINGS
$apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name = 'vtpass'");
$api  = mysqli_fetch_assoc($apiQ);

if (!$api) {
    writeLog("tvPlans.log", "ERROR: API not configured");

    echo json_encode([
        "response_description" => "999",
        "message" => "API not configured"
    ]);
    exit;
}

// 🔥 BUILD URL
$url = rtrim($api['api_url'], '/') . "/api/service-variations?serviceID=" . strtolower($serviceID);



// 🔥 cURL
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "api-key: " . $api['api_key'],
        "secret-key: " . $api['secret']
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

// ❌ ERROR
if ($err || !$response) {
  

    echo json_encode([
        "response_description" => "999",
        "message" => "API error"
    ]);
    exit;
}


// ✅ RETURN EXACT RESPONSE (NO CHANGE)
echo $response;
?>
<?php
header("Content-Type: application/json");

// Allow POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        "status" => false,
        "message" => "Invalid request method"
    ]);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['phone']) || empty($input['phone'])) {
    echo json_encode([
        "status" => false,
        "message" => "Phone number is required"
    ]);
    exit;
}

$phone = preg_replace('/\D/', '', $input['phone']); // clean number
$country = "NG"; // Nigeria

// 🔑 STEP 1: Generate Reloadly Access Token
function getReloadlyToken() {
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://auth.reloadly.com/oauth/token",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode([
            "client_id" => "M0GVocnJSKfJjkQ1CdQHMNyKug92ejbS",
            "client_secret" => "CmxPdndqLZ-UYu2R3Kd0ug7XM1o94k-yHaOwnc2ODH6DZ69vP7tEyBo3M2Fa1sk",
            "grant_type" => "client_credentials",
            "audience" => "https://topups.reloadly.com"
        ]),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
    ]);

    $response = curl_exec($curl);

    if (curl_error($curl)) {
        curl_close($curl);
        return null;
    }

    curl_close($curl);

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

// 📡 STEP 2: Detect Network
function detectNetwork($phone, $country, $token) {
    $url = "https://topups.reloadly.com/operators/auto-detect/phone/$phone/countries/$country";

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Accept: application/com.reloadly.topups-v1+json"
        ],
    ]);

    $response = curl_exec($curl);

    if (curl_error($curl)) {
        curl_close($curl);
        return null;
    }

    curl_close($curl);

    return json_decode($response, true);
}

// 🚀 RUN PROCESS
$token = getReloadlyToken();

if (!$token) {
    echo json_encode([
        "status" => false,
        "message" => "Failed to generate API token"
    ]);
    exit;
}

$result = detectNetwork($phone, $country, $token);

if (!$result || isset($result['errorCode'])) {
    echo json_encode([
        "status" => false,
        "message" => "Network detection failed"
    ]);
    exit;
}

// 🎯 Format response for your frontend
echo json_encode([
    "status" => true,
    "network" => $result['name'] ?? null,
    "raw" => $result // optional full response
]);
?>
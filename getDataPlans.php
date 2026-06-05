<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$serviceID = $_GET['network'] ?? ''; // mtn-data, glo-data

if (!$serviceID) {
    echo json_encode(["success" => false, "message" => "Network required"]);
    exit;
}


// 🔥 VTpass endpoint
$url = "https://vtpass.com/api/service-variations?serviceID=" . $serviceID;

$response = file_get_contents($url);
$data = json_decode($response, true);

if (!$data || $data['response_description'] != "000") {
    echo json_encode(["success" => false, "message" => "Failed to fetch plans"]);
    exit;
}

$plans = [];

foreach ($data['content']['variations'] as $plan) {
    $plans[] = [
        "plan_id" => $plan['variation_code'],
        "name"    => $plan['name'],
        "amount"  => $plan['variation_amount']
    ];
}

echo json_encode([
    "success" => true,
    "plans" => $plans
]);
?>
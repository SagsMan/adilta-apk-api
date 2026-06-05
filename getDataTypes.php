<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';
require_once 'transactionToken.php';

$data = json_decode(file_get_contents("php://input"), true);

// 🔹 Inputs
$token     = $data['token'] ?? '';
$serviceID = strtolower($data['serviceID'] ?? ''); // e.g mtn-data

if (empty($token) || empty($serviceID)) {
    echo json_encode([
        "success" => false,
        "message" => "Token and serviceID are required"
    ]);
    exit;
}

// 🔐 Verify user
$verify = verifyUserToken($conn, $token);
if (!$verify['success']) {
    echo json_encode($verify);
    exit;
}

// 🔄 Extract network from serviceID (mtn-data → mtn)
$parts = explode('-', $serviceID);
$network = $parts[0] ?? '';

if (empty($network)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid serviceID"
    ]);
    exit;
}

// 🔄 Map to DB network_id
$networkMap = [
    "mtn"      => 1,
    "glo"      => 2,
    "etisalat" => 3,
    "airtel"   => 4
];

if (!isset($networkMap[$network])) {
    echo json_encode([
        "success" => false,
        "message" => "Unsupported network"
    ]);
    exit;
}

$network_id = $networkMap[$network];

// 🔍 Fetch types
$types = [];

$query = mysqli_query($conn, "
    SELECT id, data_type, title 
    FROM plan_types 
    WHERE network_id = '$network_id' AND status = 1
");

if ($query && mysqli_num_rows($query) > 0) {
    while ($row = mysqli_fetch_assoc($query)) {
        $types[] = [
            "id"   => $row['id'],
            "name" => $row['title'],      // display name
            "code" => $row['data_type']  // internal code
        ];
    }
}

// ❌ No result
if (empty($types)) {
    echo json_encode([
        "success" => false,
        "message" => "No data types found"
    ]);
    exit;
}

// ✅ Response
echo json_encode([
    "success" => true,
    "types" => $types
]);
?>
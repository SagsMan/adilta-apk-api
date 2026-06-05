<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once 'conn.php';
require_once 'transactionToken.php';

$data = json_decode(file_get_contents("php://input"), true);

// 🔹 Inputs
$token      = $data['token'] ?? '';
$planTypeId = $data['plan_id'] ?? ''; // this is plan_types.id

if (empty($token) || empty($planTypeId)) {
    echo json_encode([
        "success" => false,
        "message" => "Token and plan_id are required"
    ]);
    exit;
}

// 🔐 Verify user
$verify = verifyUserToken($conn, $token);
if (!$verify['success']) {
    echo json_encode($verify);
    exit;
}

// 🔍 Fetch plans
$plans = [];

$query = mysqli_query($conn, "
    SELECT 
        p.id,
        p.api_id,
        p.plan_id,
        p.plan,
        p.validity,
        p.price,
        p.reseller,
        p.topuser
    FROM plans p
    INNER JOIN api_settings a 
        ON p.api_id = a.id
    WHERE p.plan_type_id = '$planTypeId'
    AND a.is_active = 1
");

if ($query && mysqli_num_rows($query) > 0) {

    while ($row = mysqli_fetch_assoc($query)) {

        // 💰 Choose price logic
        $finalPrice = $row['price'];
        // $finalPrice = $row['reseller'];
        // $finalPrice = $row['topuser'];

        $plans[] = [
            "id"        => $row['id'],
            "plan_id"   => $row['plan_id'],
            "api_id"    => $row['api_id'],
            "name"      => $row['plan']." (".$row['validity'].")",
            "validity"  => $row['validity'],
            "amount"    => (float)$finalPrice
        ];
    }

}

// ❌ No plans
if (empty($plans)) {
    echo json_encode([
        "success" => false,
        "message" => "No plans found"
    ]);
    exit;
}

// ✅ Response
echo json_encode([
    "success" => true,
    "plans" => $plans
]);
?>
<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once "conn.php";
require_once "transactionToken.php";

// 🔥 READ JSON INPUT
$data = json_decode(file_get_contents("php://input"), true);

$token = $data['token'] ?? '';

if (!$token) {
    echo json_encode(["success" => false, "message" => "Token required"]);
    exit;
}

// 🔐 VERIFY USER
$verify = verifyUserToken($conn, $token);
if (!$verify['success']) {
    echo json_encode($verify);
    exit;
}

$email = $verify['user']['email'];

// 📥 FETCH DATA
$query = mysqli_query($conn, "
    SELECT 
       *
    FROM cac_registration_tbl
    WHERE email='$email'
    ORDER BY id DESC
");

$dataArr = [];

while ($row = mysqli_fetch_assoc($query)) {
    $dataArr[] = $row;
}

// ✅ RESPONSE
echo json_encode([
    "success" => true,
    "data" => $dataArr
]);
?>
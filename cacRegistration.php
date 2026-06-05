<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once "conn.php";
require_once "transactionToken.php";

// 🔥 LOGGER
function writeLog($data) {
    $file = __DIR__ . "/logs/cac.log";
    file_put_contents($file, "[" . date("Y-m-d H:i:s") . "] " . $data . PHP_EOL, FILE_APPEND);
}

// 🔐 VERIFY TOKEN
$token = $_POST['token'] ?? '';
if (!$token) {
    echo json_encode(["success" => false, "message" => "Token required"]);
    exit;
}

$verify = verifyUserToken($conn, $token);
if (!$verify['success']) {
    echo json_encode($verify);
    exit;
}

$user = $verify['user'];
$email = $user['email'];

// 🔹 GET INPUTS
$sname = $_POST['sname'] ?? '';
$phone = $_POST['proprietor_phone'] ?? '';
$pemail = $_POST['proprietor_email'] ?? '';
$business_address = $_POST['business_address'] ?? '';
$nature = $_POST['nature_of_business'] ?? '';
$pname1 = $_POST['proposed_name_1'] ?? '';
$pname2 = $_POST['proposed_name_2'] ?? '';
$prop_address = $_POST['proprietor_address'] ?? '';

// 🔍 VALIDATION
if (!$sname || !$phone || !$pemail || !$business_address || !$nature || !$pname1 || !$pname2 || !$prop_address) {
    echo json_encode(["success" => false, "message" => "All fields are required"]);
    exit;
}

// 📁 UPLOAD DIR (MAIN DOMAIN)
$uploadDir = "/home/eduowrav/rahausub.com.ng/easyfinder/uploads/";

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// 🔥 FILE UPLOAD FUNCTION
function uploadFile($fileKey, $prefix) {
    global $uploadDir;

    if (!isset($_FILES[$fileKey])) {
        writeLog("❌ File not found: $fileKey");
        return null;
    }

    $file = $_FILES[$fileKey];

    if ($file['error'] !== 0) {
        writeLog("❌ Upload error ($fileKey): " . $file['error']);
        return null;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);

    // 👇 YOUR REQUIRED FORMAT
    $filename = "Abdulazeez_" . $prefix . "_" . time() . "." . $ext;

    $destination = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        writeLog("✅ Uploaded to: " . $destination);

        // 👇 STORE LIKE YOU WANT
        return "../uploads/" . $filename;
    } else {
        writeLog("❌ Move failed: " . $destination);
        return null;
    }
}


// 📤 UPLOAD FILES
$passport = uploadFile("proprietor_passport", "passport");
$signature = uploadFile("proprietor_signature", "signature");
$nin = uploadFile("nin", "nin");

if (!$passport || !$signature || !$nin) {
    echo json_encode(["success" => false, "message" => "File upload failed"]);
    exit;
}

$amount = 19000;

// 🔍 GET WALLET
$getWallet = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$email'");

if (!$getWallet || mysqli_num_rows($getWallet) == 0) {
    echo json_encode(["success" => false, "message" => "Wallet not found"]);
    exit;
}

$wallet = mysqli_fetch_assoc($getWallet);
$currentBalance = (float)$wallet['balance'];

// ❌ INSUFFICIENT
if ($currentBalance < $amount) {
    echo json_encode([
        "success" => false,
        "message" => "Insufficient balance"
    ]);
    exit;
}

// 💸 DEBIT
$newBalance = $currentBalance - $amount;

$debit = mysqli_query($conn, "
    UPDATE wallet_tbl 
    SET balance='$newBalance' 
    WHERE user_id='$email'
");

if (!$debit) {
    echo json_encode(["success" => false, "message" => "Failed to debit wallet"]);
    exit;
}

writeLog("CAC SUBMITTED: $email");

// ✅ SUCCESS
echo json_encode([
    "success" => true,
    "message" => "CAC registration submitted successfully"
]);
?>

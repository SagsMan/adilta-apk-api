<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once "conn.php";
require_once "transactionToken.php";

// 🔥 LOGGER
function writeLog($data) {
    $file = __DIR__ . "/logs/company_cac.log";
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

$user  = $verify['user'];
$email = $user['email'];

// 🔹 GET INPUTS
$pname1 = $_POST['proposed_name_1'] ?? '';
$pname2 = $_POST['proposed_name_2'] ?? '';
$class  = $_POST['classification'] ?? '';
$nature = $_POST['nature_of_company'] ?? '';
$address = $_POST['company_address'] ?? '';

$p1Name = $_POST['proprietor_1_name'] ?? '';
$p1Addr = $_POST['proprietor_1_address'] ?? '';
$p1Phone= $_POST['proprietor_1_phone'] ?? '';
$p1Email= $_POST['proprietor_1_email'] ?? '';

$p2Name = $_POST['proprietor_2_name'] ?? '';
$p2Addr = $_POST['proprietor_2_address'] ?? '';
$p2Phone= $_POST['proprietor_2_phone'] ?? '';
$p2Email= $_POST['proprietor_2_email'] ?? '';

$date = date("Y-m-d");

// 🔍 VALIDATION
if (
    !$pname1 || !$pname2 || !$class || !$nature || !$address ||
    !$p1Name || !$p1Addr || !$p1Phone || !$p1Email ||
    !$p2Name || !$p2Addr || !$p2Phone || !$p2Email
) {
    echo json_encode(["success" => false, "message" => "All fields required"]);
    exit;
}

// 📁 UPLOAD DIR (MAIN DOMAIN)
$uploadDir = "/home/eduowrav/rahausub.com.ng/easyfinder/uploads/";

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// 📤 UPLOAD FUNCTION
function uploadFile($key, $prefix) {
    global $uploadDir;

    if (!isset($_FILES[$key])) return null;

    $file = $_FILES[$key];
    if ($file['error'] !== 0) return null;

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = "Abdulazeez_" . $prefix . "_" . time() . rand(1000,9999) . "." . $ext;

    $destination = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return "../uploads/" . $filename;
    }

    return null;
}

// 📤 UPLOAD FILES
$p1Passport  = uploadFile("proprietor_1_passport", "p1_passport");
$p1Nin       = uploadFile("proprietor_1_nin", "p1_nin");
$p1Sign      = uploadFile("proprietor_1_signature", "p1_sign");

$p2Passport  = uploadFile("proprietor_2_passport", "p2_passport");
$p2Nin       = uploadFile("proprietor_2_nin", "p2_nin");
$p2Sign      = uploadFile("proprietor_2_signature", "p2_sign");

if (!$p1Passport || !$p1Nin || !$p1Sign || !$p2Passport || !$p2Nin || !$p2Sign) {
    echo json_encode(["success" => false, "message" => "File upload failed"]);
    exit;
}

// 💰 AMOUNT
$amount = 45000;

// 🔍 WALLET
$getWallet = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$email'");

if (!$getWallet || mysqli_num_rows($getWallet) == 0) {
    echo json_encode(["success" => false, "message" => "Wallet not found"]);
    exit;
}

$wallet = mysqli_fetch_assoc($getWallet);
$oldBalance = (float)$wallet['balance'];

if ($oldBalance < $amount) {
    echo json_encode(["success" => false, "message" => "Insufficient balance"]);
    exit;
}

// 💸 DEBIT
$newBalance = $oldBalance - $amount;

$debit = mysqli_query($conn, "
    UPDATE wallet_tbl SET balance='$newBalance' WHERE user_id='$email'
");

if (!$debit) {
    echo json_encode(["success" => false, "message" => "Wallet debit failed"]);
    exit;
}

// 💾 INSERT
$query = mysqli_query($conn, "
INSERT INTO company_cac_registration_tbl (
email,
proposed_name_1,
proposed_name_2,
classification,
nature_of_company,
company_address,

proprietor_1_name,
proprietor_1_address,
proprietor_1_phone,
proprietor_1_email,
proprietor_1_passport,
proprietor_1_nin,
proprietor_1_signature,

proprietor_2_name,
proprietor_2_address,
proprietor_2_phone,
proprietor_2_email,
proprietor_2_passport,
proprietor_2_nin,
proprietor_2_signature,

date_submitted,
status
) VALUES (
'$email',
'$pname1',
'$pname2',
'$class',
'$nature',
'$address',

'$p1Name',
'$p1Addr',
'$p1Phone',
'$p1Email',
'$p1Passport',
'$p1Nin',
'$p1Sign',

'$p2Name',
'$p2Addr',
'$p2Phone',
'$p2Email',
'$p2Passport',
'$p2Nin',
'$p2Sign',

'$date',
0
)
");

// ❌ rollback if fail
if (!$query) {
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='$oldBalance' WHERE user_id='$email'");
    writeLog("DB ERROR: " . mysqli_error($conn));

    echo json_encode(["success" => false, "message" => "Database error"]);
    exit;
}

writeLog("COMPANY CAC SUCCESS: $email");

// ✅ SUCCESS
echo json_encode([
    "success" => true,
    "message" => "Company CAC submitted successfully",
    "balance" => $newBalance
]);
?>
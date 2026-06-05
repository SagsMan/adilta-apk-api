<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include_once "conn.php";

// ✅ INCLUDE FROM MAIN DOMAIN (same as upload logic)
require_once "/home/eduowrav/rahausub.com.ng/easyfinder/app/EmailNotification.php";

use EduTech\EmailNotification;

// 📥 GET INPUT
$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "Invalid email"]);
    exit;
}

// 🔍 CHECK USER
$q = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$email'");

if (!$q || mysqli_num_rows($q) == 0) {
    echo json_encode([
        "success" => false,
        "message" => "Email not found"
    ]);
    exit;
}

$user = mysqli_fetch_assoc($q);
$name = $user['name'] ?? 'User';

// 🔐 GENERATE TOKEN
$token = bin2hex(random_bytes(16));
$expiry = date("Y-m-d H:i:s", strtotime("+30 minutes"));

// 💾 SAVE
mysqli_query($conn, "
UPDATE users_tbl 
SET reset_token='$token', reset_expiry='$expiry' 
WHERE email='$email'
");

// 🔗 RESET LINK (MAIN DOMAIN PAGE)
$resetLink = "https://rahausub.com.ng/reset-password.php?token=$token";

// 📧 EMAIL
$subject = "Password Reset Request";

$body = "
<h3>Password Reset</h3>
<p>Hello $name,</p>
<p>Click below to reset your password:</p>
<p><a href='$resetLink'>Reset Password</a></p>
<p>This link expires in 30 minutes.</p>
";

// 📤 SEND
EmailNotification::Send($email, $name, $subject, $body);

// ✅ RESPONSE
echo json_encode([
    "success" => true,
    "message" => "Reset link sent to your email"
]);
?>
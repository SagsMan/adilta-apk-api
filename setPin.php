<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

include_once 'conn.php';

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['token']) || empty($data['pin'])) {
    echo json_encode(["success" => false, "message" => "Token and PIN are required"]);
    exit;
}

$token = trim($data['token']);
$pin   = trim($data['pin']);

if (!preg_match('/^\d{4,6}$/', $pin)) {
    echo json_encode(["success" => false, "message" => "PIN must be 4–6 digits"]);
    exit;
}

$ts = mysqli_real_escape_string($conn, $token);

// ── Fast path: plain token lookup ─────────────────────────────────────────────
$q = mysqli_query($conn, "SELECT id, email FROM users_tbl WHERE token='$ts' AND status=1 LIMIT 1");

$userId = null;
if ($q && mysqli_num_rows($q) > 0) {
    $row    = mysqli_fetch_assoc($q);
    $userId = $row['id'];
}

// ── Legacy fallback: bcrypt tokens ────────────────────────────────────────────
if (!$userId) {
    $q2 = mysqli_query($conn,
        "SELECT id, email, token FROM users_tbl WHERE token LIKE '\$2y\$%' AND status=1 ORDER BY id DESC LIMIT 200");
    if ($q2) {
        while ($row = mysqli_fetch_assoc($q2)) {
            if (password_verify($token, $row['token'])) {
                $userId = $row['id'];
                // Migrate to plain token
                mysqli_query($conn, "UPDATE users_tbl SET token='$ts' WHERE id=" . intval($userId));
                break;
            }
        }
    }
}

if (!$userId) {
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

$hashedPin = md5($pin);
$uid       = intval($userId);
$update    = mysqli_query($conn, "UPDATE users_tbl SET pin='$hashedPin' WHERE id=$uid");

if ($update) {
    echo json_encode(["success" => true, "message" => "PIN set successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to update PIN"]);
}
?>

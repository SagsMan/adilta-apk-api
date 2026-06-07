<?php
/**
 * Send a push notification to a specific user (by email or user_id).
 * Admin-only endpoint — protect with a secret admin key.
 *
 * POST body (JSON):
 * {
 *   "admin_key": "YOUR_ADMIN_SECRET_KEY",
 *   "email":     "user@example.com",       (one of email OR user_id required)
 *   "user_id":   123,
 *   "title":     "Notification title",
 *   "body":      "Notification message",
 *   "data":      { "screen": "Wallet" }    (optional, opens screen in app)
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

header("Content-Type: application/json");
include_once 'conn.php';
include_once 'fcm_helper.php';

define('ADMIN_SECRET_KEY', 'ada211ba7f6ee3bdb9814d174fae1520c1265d10775b27c5f2cf7fc7b167e3f0'); // Set a strong secret!

$data     = json_decode(file_get_contents("php://input"), true);
$response = ["success" => false, "message" => ""];

// ── Admin auth ────────────────────────────────────────────────────────────────
if (($data['admin_key'] ?? '') !== ADMIN_SECRET_KEY) {
    http_response_code(403);
    $response['message'] = "Forbidden";
    echo json_encode($response); exit;
}

// ── Resolve target user ───────────────────────────────────────────────────────
$title  = trim($data['title'] ?? '');
$body   = trim($data['body']  ?? '');
$extra  = $data['data'] ?? [];

if (empty($title) || empty($body)) {
    $response['message'] = "title and body are required";
    echo json_encode($response); exit;
}

// Build WHERE clause
if (!empty($data['email'])) {
    $emailSafe = mysqli_real_escape_string($conn, $data['email']);
    $where = "email='$emailSafe'";
} elseif (!empty($data['user_id'])) {
    $uid   = intval($data['user_id']);
    $where = "user_id=$uid";
} else {
    $response['message'] = "email or user_id required";
    echo json_encode($response); exit;
}

// ── Fetch tokens ──────────────────────────────────────────────────────────────
$tokensQ = mysqli_query($conn, "SELECT fcm_token FROM device_tokens WHERE $where");
if (!$tokensQ || mysqli_num_rows($tokensQ) === 0) {
    $response['message'] = "No device tokens found for this user";
    echo json_encode($response); exit;
}

$tokens = [];
while ($row = mysqli_fetch_assoc($tokensQ)) {
    $tokens[] = $row['fcm_token'];
}

// ── Send ──────────────────────────────────────────────────────────────────────
$result = fcm_send_to_tokens($tokens, $title, $body, $extra);

$response['success'] = $result['sent'] > 0;
$response['message'] = "Sent: {$result['sent']}, Failed: {$result['failed']}";
$response['detail']  = $result;
echo json_encode($response);

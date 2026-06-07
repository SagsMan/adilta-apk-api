<?php
/**
 * Save or update a user's FCM device token.
 * Called by the Expo app each time the user logs in or the token refreshes.
 *
 * POST body (JSON):
 * {
 *   "token":     "Bearer <user_auth_token>",
 *   "fcm_token": "<expo/firebase push token>",
 *   "platform":  "android" | "ios"      (optional, default: android)
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

header("Content-Type: application/json");
include_once 'conn.php';

$data     = json_decode(file_get_contents("php://input"), true);
$response = ["success" => false, "message" => ""];

// ── Auth: resolve user from token ─────────────────────────────────────────────
$authHeader = $data['token'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$rawToken   = str_replace('Bearer ', '', $authHeader);

if (empty($rawToken)) {
    $response['message'] = "Unauthorized";
    echo json_encode($response); exit;
}

$tokenSafe = mysqli_real_escape_string($conn, $rawToken);
$userQ = mysqli_query($conn, "SELECT id, email FROM users_tbl WHERE token='$tokenSafe' LIMIT 1");
if (!$userQ || mysqli_num_rows($userQ) === 0) {
    $response['message'] = "Unauthorized";
    echo json_encode($response); exit;
}
$user = mysqli_fetch_assoc($userQ);

// ── Validate FCM token ────────────────────────────────────────────────────────
$fcmToken = trim($data['fcm_token'] ?? '');
if (empty($fcmToken)) {
    $response['message'] = "fcm_token is required";
    echo json_encode($response); exit;
}

$platform     = in_array($data['platform'] ?? '', ['android', 'ios']) ? $data['platform'] : 'android';
$userId       = intval($user['id']);
$email        = mysqli_real_escape_string($conn, $user['email']);
$fcmTokenSafe = mysqli_real_escape_string($conn, $fcmToken);

// ── Upsert: if token exists update user, else insert ─────────────────────────
$exists = mysqli_query($conn, "SELECT id FROM device_tokens WHERE fcm_token='$fcmTokenSafe' LIMIT 1");

if (mysqli_num_rows($exists) > 0) {
    mysqli_query($conn,
        "UPDATE device_tokens SET user_id=$userId, email='$email', platform='$platform', updated_at=NOW()
         WHERE fcm_token='$fcmTokenSafe'"
    );
} else {
    mysqli_query($conn,
        "INSERT INTO device_tokens (user_id, email, fcm_token, platform, created_at, updated_at)
         VALUES ($userId, '$email', '$fcmTokenSafe', '$platform', NOW(), NOW())"
    );
}

$response['success'] = true;
$response['message'] = "Device token saved";
echo json_encode($response);

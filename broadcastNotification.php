<?php
/**
 * Broadcast a push notification to ALL registered users (or filtered by platform).
 * Admin-only endpoint.
 *
 * POST body (JSON):
 * {
 *   "admin_key": "YOUR_ADMIN_SECRET_KEY",
 *   "title":     "🔥 New Data Deal!",
 *   "body":      "MTN 1GB is now ₦200 — grab it now!",
 *   "platform":  "all" | "android" | "ios",   (optional, default: all)
 *   "data":      { "screen": "DataPlans" }     (optional)
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

header("Content-Type: application/json");
include_once 'conn.php';
include_once 'fcm_helper.php';

define('ADMIN_SECRET_KEY', '123456'); // Must match sendPushToUser.php

$data     = json_decode(file_get_contents("php://input"), true);
$response = ["success" => false, "message" => ""];

// ── Admin auth ────────────────────────────────────────────────────────────────
if (($data['admin_key'] ?? '') !== ADMIN_SECRET_KEY) {
    http_response_code(403);
    $response['message'] = "Forbidden";
    echo json_encode($response); exit;
}

$title    = trim($data['title'] ?? '');
$body     = trim($data['body']  ?? '');
$platform = $data['platform'] ?? 'all';
$extra    = $data['data'] ?? [];

if (empty($title) || empty($body)) {
    $response['message'] = "title and body are required";
    echo json_encode($response); exit;
}

// ── Fetch all tokens ──────────────────────────────────────────────────────────
$where = '';
if (in_array($platform, ['android', 'ios'])) {
    $platSafe = mysqli_real_escape_string($conn, $platform);
    $where    = "WHERE platform='$platSafe'";
}

$tokensQ = mysqli_query($conn, "SELECT DISTINCT fcm_token FROM device_tokens $where");

$tokens = [];
while ($row = mysqli_fetch_assoc($tokensQ)) {
    $tokens[] = $row['fcm_token'];
}

if (empty($tokens)) {
    $response['message'] = "No device tokens found";
    echo json_encode($response); exit;
}

// ── Log broadcast ─────────────────────────────────────────────────────────────
$log_file = __DIR__ . '/logs/push_broadcast.log';
@file_put_contents($log_file,
    date('Y-m-d H:i:s') . " | BROADCAST platform=$platform total_tokens=" . count($tokens) . " title=$title\n",
    FILE_APPEND | LOCK_EX);

// ── Send in batches of 100 (to avoid timeout on shared hosting) ───────────────
$batches   = array_chunk($tokens, 100);
$totalSent = 0;
$totalFail = 0;

foreach ($batches as $batch) {
    $r = fcm_send_to_tokens($batch, $title, $body, $extra);
    $totalSent += $r['sent'];
    $totalFail += $r['failed'];
}

@file_put_contents($log_file,
    date('Y-m-d H:i:s') . " | DONE sent=$totalSent failed=$totalFail\n",
    FILE_APPEND | LOCK_EX);

$response['success'] = $totalSent > 0;
$response['message'] = "Sent: $totalSent, Failed: $totalFail";
$response['total_devices'] = count($tokens);
echo json_encode($response);

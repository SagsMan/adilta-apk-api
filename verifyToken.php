<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include_once 'conn.php';

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['token'])) {
    echo json_encode(["success" => false, "message" => "Token is required"]);
    exit;
}

$incomingToken = $data['token'];
$ts = mysqli_real_escape_string($conn, $incomingToken);

// ── Fast path: plain token direct lookup (covers all new logins) ──────────────
$q = mysqli_query($conn,
    "SELECT id, sname, oname, email, phone, pin, finger
       FROM users_tbl
      WHERE token = '$ts' AND status = 1 LIMIT 1");

if ($q && mysqli_num_rows($q) > 0) {
    $row = mysqli_fetch_assoc($q);
    echo json_encode([
        "success"  => true,
        "user_id"  => $row['id'],
        "email"    => $row['email'],
        "name"     => trim($row['sname'] . ' ' . $row['oname']),
        "phone"    => $row['phone'],
        "haspin"   => !empty($row['pin']),
        "finger"   => (bool)$row['finger'],
    ]);
    exit;
}

// ── Legacy fallback: bcrypt-hashed tokens (old sessions only) ─────────────────
// Only rows whose token starts with $2y$ are bcrypt — plain hex tokens skip this entirely.
$q2 = mysqli_query($conn,
    "SELECT id, sname, oname, email, phone, pin, finger
       FROM users_tbl
      WHERE token LIKE '\$2y\$%' AND status = 1
      ORDER BY id DESC LIMIT 200");

if ($q2) {
    while ($row = mysqli_fetch_assoc($q2)) {
        if (password_verify($incomingToken, $row['token'])) {
            // Migrate: replace bcrypt hash with plain token for future fast-path lookups
            mysqli_query($conn, "UPDATE users_tbl SET token = '$ts' WHERE id = " . intval($row['id']));
            echo json_encode([
                "success"  => true,
                "user_id"  => $row['id'],
                "email"    => $row['email'],
                "name"     => trim($row['sname'] . ' ' . $row['oname']),
                "phone"    => $row['phone'],
                "haspin"   => !empty($row['pin']),
                "finger"   => (bool)$row['finger'],
            ]);
            exit;
        }
    }
}

echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
?>

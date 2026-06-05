<?php

/**
 * Token verification helper — fast lookup + smart bcrypt fallback.
 *
 * Fast path:   direct indexed WHERE token = ?                (< 1ms)
 * Smart fallback: ONLY scans users whose stored token is a bcrypt hash
 *              (token LIKE '$2y$%'). Plain hex stale tokens fail instantly
 *              without touching bcrypt. LIMIT 200 caps worst-case at ~3s.
 * haspin is derived from the 'pin' column.
 */
function verifyUserToken($conn, $incomingToken) {

    if (empty($incomingToken)) {
        return ["success" => false, "message" => "Token required"];
    }

    // ── Fast path: plain token indexed lookup ──────────────────────────────
    $ts = mysqli_real_escape_string($conn, $incomingToken);
    $q  = mysqli_query($conn,
        "SELECT id, sname, oname, email, phone, pin, token
           FROM users_tbl
          WHERE token = '$ts' AND status = 1 LIMIT 1");

    if ($q && mysqli_num_rows($q) > 0) {
        $row = mysqli_fetch_assoc($q);
        return [
            "success" => true,
            "user" => [
                "id"     => $row['id'],
                "name"   => $row['sname'] . " " . $row['oname'],
                "email"  => $row['email'],
                "phone"  => $row['phone'],
                "pin"    => $row['pin'],
                "haspin" => !empty($row['pin']),
            ]
        ];
    }

    // ── Smart legacy fallback: ONLY bcrypt-hashed tokens ─────────────────
    // Filters to token LIKE '$2y$%' so plain hex stale tokens skip this
    // entirely (password_verify on non-bcrypt is instant-false but we still
    // avoid fetching thousands of rows). LIMIT 200 caps worst-case at ~3s.
    $q2 = mysqli_query($conn,
        "SELECT id, sname, oname, email, phone, pin, token
           FROM users_tbl
          WHERE token LIKE '\$2y\$%' AND status = 1
          ORDER BY id DESC LIMIT 200");

    if ($q2) {
        while ($row = mysqli_fetch_assoc($q2)) {
            if (password_verify($incomingToken, $row['token'])) {
                return [
                    "success" => true,
                    "user" => [
                        "id"     => $row['id'],
                        "name"   => $row['sname'] . " " . $row['oname'],
                        "email"  => $row['email'],
                        "phone"  => $row['phone'],
                        "pin"    => $row['pin'],
                        "haspin" => !empty($row['pin']),
                    ]
                ];
            }
        }
    }

    return ["success" => false, "message" => "Invalid or expired token"];
}
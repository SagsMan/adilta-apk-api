<?php
/**
 * generateBankAccount.php — Monnify reserved account generator.
 * PaymentPoint has been fully removed. This file now generates Monnify accounts only.
 *
 * Usage: include this file, then call generateBankAccount($email, $name, $phone)
 * Returns: ['success' => bool, 'message' => string]
 */

function generateBankAccount($email, $name, $phone) {
    include_once __DIR__ . '/conn.php';
    global $conn;

    if (!$conn) {
        return ["success" => false, "message" => "DB Connection failed"];
    }

    $emailSafe = mysqli_real_escape_string($conn, $email);

    // If user already has a Monnify account, skip
    $check = mysqli_query($conn, "SELECT id, monnify_account_details FROM users_tbl WHERE email='$emailSafe' LIMIT 1");
    if (!$check || mysqli_num_rows($check) < 1) {
        return ["success" => false, "message" => "User not found"];
    }
    $current = mysqli_fetch_assoc($check);
    if (!empty($current['monnify_account_details'])) {
        return ["success" => true, "message" => "already_has_account"];
    }
    $userId = $current['id'];

    // Fetch Monnify credentials
    $credQ  = mysqli_query($conn, "SELECT setting_key, setting_value FROM edutech_settings WHERE setting_key LIKE 'MONNIFY_%'");
    $keys   = [];
    while ($r = mysqli_fetch_assoc($credQ)) $keys[$r['setting_key']] = $r['setting_value'];

    $apiKey    = $keys['MONNIFY_API_KEY']      ?? '';
    $apiSecret = $keys['MONNIFY_API_SECRET']   ?? '881J3RXH6Z6LDVJWG76P1YHW8VCECAE5';
    $baseUrl   = rtrim($keys['MONNIFY_BASE_URL'] ?? 'https://api.monnify.com', '/');
    $contract  = $keys['MONNIFY_API_CONTRACT']  ?? '';

    if (empty($apiKey) || empty($contract)) {
        return ["success" => false, "message" => "Monnify credentials not configured"];
    }

    // Step 1: Authenticate
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $baseUrl . '/api/v1/auth/login',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode("$apiKey:$apiSecret"),
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $authResp = curl_exec($ch);
    curl_close($ch);
    $authData  = json_decode($authResp, true);
    $authToken = $authData['responseBody']['accessToken'] ?? null;

    if (!$authToken) {
        return ["success" => false, "message" => "Monnify authentication failed"];
    }

    // Fetch BVN for this user (required by Monnify production)
    $userBvnQ  = mysqli_query($conn, "SELECT bvn FROM users_tbl WHERE email='$emailSafe' LIMIT 1");
    $userBvnRow = $userBvnQ ? mysqli_fetch_assoc($userBvnQ) : [];
    $userBvn   = $userBvnRow['bvn'] ?? '';

    // Step 2: Create reserved account
    $accountRef  = 'ADIL_' . $userId . '_' . time();
    $payloadData = [
        'accountReference'    => $accountRef,
        'accountName'         => $name,
        'currencyCode'        => 'NGN',
        'contractCode'        => $contract,
        'customerEmail'       => $email,
        'customerName'        => $name,
        'getAllAvailableBanks' => true,
    ];
    if (!empty($userBvn)) $payloadData['bvn'] = $userBvn;
    $payload = json_encode($payloadData);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $baseUrl . '/api/v2/bank-transfer/reserved-accounts',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $authToken,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ["success" => false, "message" => "cURL error: $err"];
    }

    $result = json_decode($resp, true);
    if (empty($result['requestSuccessful'])) {
        return ["success" => false, "message" => $result['responseMessage'] ?? 'Account creation failed'];
    }

    $body     = $result['responseBody'] ?? [];
    $accounts = $body['accounts'] ?? [];
    $accName  = $body['accountName'] ?? $name;

    $parts = [];
    foreach ($accounts as $acct) {
        $bn = $acct['bankName'] ?? '';
        $an = $acct['accountNumber'] ?? '';
        if ($bn && $an) {
            $parts[] = "$bn - $an - $accName";
        }
    }

    if (empty($parts)) {
        return ["success" => false, "message" => "No accounts returned by Monnify"];
    }

    $detailsStr = implode(', ', $parts);
    $ds         = mysqli_real_escape_string($conn, $detailsStr);
    $updateOk   = mysqli_query($conn, "UPDATE users_tbl SET monnify_account_details='$ds' WHERE email='$emailSafe'");

    if ($updateOk) {
        return ["success" => true, "message" => "updated", "details" => $detailsStr];
    }
    return ["success" => false, "message" => "DB Error: " . mysqli_error($conn)];
}
?>

<?php

function getMonnifyToken() {
    $credentials = base64_encode('MK_PROD_3JMNVXHKW3' . ':' . '881J3RXH6Z6LDVJWG76P1YHW8VCECAE5');
    $ch = curl_init('https://api.monnify.com/api/v1/auth/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    return $data['responseBody']['accessToken'] ?? null;
}

function generateBankAccount($email, $name, $phone) {
    include_once 'conn.php';
    global $conn;

    if (!$conn) return ["success" => false, "message" => "DB connection failed"];

    $emailSafe = mysqli_real_escape_string($conn, $email);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, acc_no, acc_no2, monnify_account_details FROM users_tbl WHERE email='$emailSafe' LIMIT 1"));

    if (!$row) return ["success" => false, "message" => "User not found"];

    // Already has Monnify account saved
    $md = json_decode($row['monnify_account_details'] ?? '', true);
    if (!empty($md['accounts'])) return ["success" => true, "message" => "exists"];

    $token = getMonnifyToken();
    if (!$token) return ["success" => false, "message" => "Monnify authentication failed"];

    $accountName      = strtoupper(trim($name));
    $accountReference = 'ADIL_' . $row['id'] . '_' . time();

    $payload = json_encode([
        'accountReference'        => $accountReference,
        'accountName'             => $accountName,
        'currencyCode'            => 'NGN',
        'contractCode'            => '283194331365',
        'customerEmail'           => $email,
        'customerName'            => $accountName,
        'getAllowedPaymentSources' => false,
    ]);

    $ch = curl_init('https://api.monnify.com/api/v2/bank-transfer/reserved-accounts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    file_put_contents($logDir . '/monnify_generate.log',
        date('[Y-m-d H:i:s]') . " id={$row['id']} httpCode=$httpCode response=$res\n",
        FILE_APPEND
    );

    $result = json_decode($res, true);
    if ($httpCode !== 200 || empty($result['responseBody']['accounts'])) {
        return ["success" => false, "message" => $result['responseMessage'] ?? 'Monnify error'];
    }

    $rb       = $result['responseBody'];
    $accounts = $rb['accounts'];
    $accName  = $rb['accountName'] ?? $accountName;

    $updates = [];
    if (isset($accounts[0])) {
        $n = mysqli_real_escape_string($conn, $accounts[0]['accountNumber']);
        $b = mysqli_real_escape_string($conn, $accounts[0]['bankName']);
        $a = mysqli_real_escape_string($conn, $accName);
        $updates[] = "acc_no='$n', acc_name='$a', bank_name='$b'";
    }
    if (isset($accounts[1])) {
        $n2 = mysqli_real_escape_string($conn, $accounts[1]['accountNumber']);
        $b2 = mysqli_real_escape_string($conn, $accounts[1]['bankName']);
        $a2 = mysqli_real_escape_string($conn, $accName);
        $updates[] = "acc_no2='$n2', acc_name2='$a2', bank_name2='$b2'";
    }

    $fullJson = mysqli_real_escape_string($conn, json_encode([
        'accountName'      => $accName,
        'accountReference' => $accountReference,
        'accounts'         => $accounts,
    ]));
    $updates[] = "monnify_account_details='$fullJson'";

    mysqli_query($conn, "UPDATE users_tbl SET " . implode(", ", $updates) . " WHERE email='$emailSafe'");
    return ["success" => true, "message" => "created"];
}
?>

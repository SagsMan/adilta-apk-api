<?php
  header('Content-Type: application/json');
  header('Access-Control-Allow-Origin: *');
  include_once 'conn.php';

  $nin   = preg_replace('/\D/', '', trim($_GET['nin']   ?? ''));
  $email = trim($_GET['email'] ?? '');

  if (empty($nin) && empty($email)) {
      echo json_encode(['error' => 'Pass ?nin=XXXXXXXXXXX or ?email=user@email.com']);
      exit;
  }

  // Find user by NIN or email
  if (!empty($nin)) {
      $safe = mysqli_real_escape_string($conn, $nin);
      $q    = mysqli_query($conn, "SELECT token, email, bvn, nin FROM users_tbl WHERE nin='$safe' LIMIT 1");
  } else {
      $safe = mysqli_real_escape_string($conn, $email);
      $q    = mysqli_query($conn, "SELECT token, email, bvn, nin FROM users_tbl WHERE email='$safe' LIMIT 1");
  }

  if (!$q || mysqli_num_rows($q) === 0) {
      echo json_encode(['error' => 'No user found with that NIN or email', 'nin' => $nin, 'email' => $email]);
      exit;
  }

  $row   = mysqli_fetch_assoc($q);
  $token = $row['token'];

  // Call the real api.php get_kyc_status
  $ch = curl_init('https://api.adildata.com.ng/api.php?action=get_kyc_status');
  curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS     => json_encode(['token' => $token]),
  ]);
  $raw  = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $result = json_decode($raw, true);
  $result['_lookup']    = ['email' => $row['email'], 'has_nin' => !empty($row['nin']), 'has_bvn' => !empty($row['bvn'])];
  $result['_http_code'] = $code;

  echo json_encode($result, JSON_PRETTY_PRINT);
  
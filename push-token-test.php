<?php
  header('Content-Type: application/json');
  header('Access-Control-Allow-Origin: *');

  $BASE = 'https://api.adildata.com.ng/api.php';
  $email = 'sagirugarba24@gmail.com';
  $pass  = '123456';

  function post($url, $body) {
      $start = microtime(true);
      $ch = curl_init($url);
      curl_setopt_array($ch, [
          CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
          CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
          CURLOPT_POSTFIELDS=>json_encode($body),
      ]);
      $raw  = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      return ['http'=>$code, 'ms'=>round((microtime(true)-$start)*1000), 'data'=>json_decode($raw,true), 'raw'=>$raw];
  }

  // Step 1: Login
  $login = post("$BASE?action=login", ['email'=>$email,'password'=>$pass]);
  $token = $login['data']['data']['token'] ?? $login['data']['token'] ?? null;

  // Step 2: Save device token
  $save = null;
  if ($token) {
      $save = post('https://api.adildata.com.ng/saveDeviceToken.php', [
          'token'     => $token,
          'fcm_token' => 'ExponentPushToken[TEST-sagirugarba24-' . date('His') . ']',
          'platform'  => 'android',
      ]);
  }

  echo json_encode([
      'step_1_login' => [
          'url'       => "$BASE?action=login",
          'http_code' => $login['http'],
          'ms'        => $login['ms'],
          'status'    => $login['data']['status'] ?? 'unknown',
          'token'     => $token ? substr($token,0,30).'...' : null,
      ],
      'step_2_save_device_token' => $save ? [
          'url'       => 'https://api.adildata.com.ng/saveDeviceToken.php',
          'http_code' => $save['http'],
          'ms'        => $save['ms'],
          'result'    => $save['data'],
      ] : ['error'=>'Login failed — no token returned'],
  ], JSON_PRETTY_PRINT);
  
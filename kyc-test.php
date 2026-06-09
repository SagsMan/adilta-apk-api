<?php
  include_once 'conn.php';

  // Get user's live token + BVN + NIN straight from DB
  $q   = mysqli_query($conn, "SELECT token, bvn, nin FROM users_tbl WHERE email='sagirugarba24@gmail.com' LIMIT 1");
  $row = mysqli_fetch_assoc($q);
  $token = $row['token'];
  $bvn   = $row['bvn']   ?: 'NOT SET';
  $nin   = $row['nin']   ?: 'NOT SET';

  $BASE = 'https://api.adildata.com.ng/api.php';

  // ---- helper: POST to real api.php and capture full timing ----
  function callApi($url, $payload) {
      $start = microtime(true);
      $ch = curl_init($url);
      curl_setopt_array($ch, [
          CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
          CURLOPT_POSTFIELDS => json_encode($payload),
      ]);
      $raw  = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      return ['http_code' => $code, 'ms' => round((microtime(true)-$start)*1000), 'raw' => $raw, 'decoded' => json_decode($raw,true)];
  }

  $kycStatus  = callApi("$BASE?action=get_kyc_status", ['token' => $token]);
  $submitNin  = callApi("$BASE?action=submit_kyc",     ['token' => $token, 'nin' => $nin]);
  $submitBvn  = callApi("$BASE?action=submit_kyc",     ['token' => $token, 'bvn' => $bvn]);

  function badge($ok) { return $ok ? '<span class="ok">✅ SUCCESS</span>' : '<span class="fail">❌ FAILED</span>'; }
  function statusBadge($v) { return $v ? '<span class="ok">✅ YES</span>' : '<span class="fail">❌ NO</span>'; }
  ?><!DOCTYPE html>
  <html>
  <head><meta charset="utf-8">
  
  <title>AdilData — Live KYC API Test</title>
  <style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Courier New',monospace;background:#0d1117;color:#c9d1d9;padding:24px;max-width:900px;margin:auto}
  h1{color:#58a6ff;font-size:18px;margin-bottom:4px}
  .sub{color:#8b949e;font-size:12px;margin-bottom:24px}
  h2{color:#f0883e;font-size:13px;text-transform:uppercase;letter-spacing:1px;margin:28px 0 10px}
  .card{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px;margin-bottom:12px}
  .url-box{background:#0d1117;border:1px solid #388bfd;border-radius:6px;padding:10px 14px;margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .url-label{color:#8b949e;font-size:11px;min-width:60px}
  .url-val{color:#79c0ff;font-size:12px;word-break:break-all}
  .method{background:#238636;color:#fff;font-size:10px;padding:2px 7px;border-radius:4px;font-weight:bold}
  .row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #21262d;font-size:13px;gap:10px}
  .row:last-child{border:none}
  .lbl{color:#8b949e}
  .val{color:#f0f6fc;text-align:right}
  .ok{background:#1a4731;color:#56d364;padding:2px 9px;border-radius:12px;font-size:12px}
  .fail{background:#3d1c1c;color:#f85149;padding:2px 9px;border-radius:12px;font-size:12px}
  .payload{background:#0d1117;border-radius:6px;padding:12px;font-size:11px;color:#7ee787;margin-top:10px;white-space:pre-wrap;word-break:break-all}
  .response{background:#0d1117;border-radius:6px;padding:12px;font-size:11px;color:#79c0ff;margin-top:8px;white-space:pre-wrap;word-break:break-all}
  .http-ok{color:#56d364;font-weight:bold}
  .http-err{color:#f85149;font-weight:bold}
  .ts{color:#484f58;font-size:11px;margin-top:20px}
  a{color:#58a6ff}
  </style>
  </head>
  <body>

  <h1>🪪 AdilData — Live KYC API Test</h1>
  <div class="sub">User: sagirugarba24@gmail.com &nbsp;|&nbsp; Real calls to <strong>api.php</strong> &nbsp;|&nbsp; <?= date('D d M Y, H:i:s') ?> WAT</div>

  <!-- DB values -->
  <h2>📦 Values pulled from database</h2>
  <div class="card">
    <div class="row"><span class="lbl">Email</span><span class="val">sagirugarba24@gmail.com</span></div>
    <div class="row"><span class="lbl">BVN (from DB)</span><span class="val"><?= htmlspecialchars($bvn) ?></span></div>
    <div class="row"><span class="lbl">NIN (from DB)</span><span class="val"><?= htmlspecialchars($nin) ?></span></div>
    <div class="row"><span class="lbl">Auth Token</span><span class="val"><?= htmlspecialchars(substr($token,0,24)) ?>...</span></div>
  </div>

  <!-- TEST 1: get_kyc_status -->
  <h2>🔍 Test 1 — GET KYC STATUS</h2>
  <div class="url-box">
    <span class="method">POST</span>
    <span class="url-label">URL:</span>
    <span class="url-val">https://api.adildata.com.ng/api.php?action=get_kyc_status</span>
  </div>
  <div class="card">
    <div class="row"><span class="lbl">HTTP Code</span><span class="val"><span class="<?= $kycStatus['http_code']==200?'http-ok':'http-err' ?>"><?= $kycStatus['http_code'] ?></span></span></div>
    <div class="row"><span class="lbl">Response Time</span><span class="val"><?= $kycStatus['ms'] ?>ms</span></div>
    <div class="row"><span class="lbl">Status</span><span class="val"><?= badge($kycStatus['decoded']['status']==='success') ?></span></div>
    <div class="row"><span class="lbl">KYC Complete</span><span class="val"><?= statusBadge($kycStatus['decoded']['data']['kyc_complete']??false) ?></span></div>
    <div class="row"><span class="lbl">Has BVN</span><span class="val"><?= statusBadge($kycStatus['decoded']['data']['has_bvn']??false) ?></span></div>
    <div class="row"><span class="lbl">Has NIN</span><span class="val"><?= statusBadge($kycStatus['decoded']['data']['has_nin']??false) ?></span></div>
    <div class="row"><span class="lbl">Account Number</span><span class="val"><?= htmlspecialchars($kycStatus['decoded']['data']['account_number']??'N/A') ?></span></div>
    <div class="row"><span class="lbl">Bank</span><span class="val"><?= htmlspecialchars($kycStatus['decoded']['data']['bank_name']??'N/A') ?></span></div>
    <div class="lbl" style="margin-top:12px;font-size:11px">Request payload sent:</div>
    <div class="payload"><?= json_encode(['token'=>substr($token,0,24).'...'], JSON_PRETTY_PRINT) ?></div>
    <div class="lbl" style="margin-top:10px;font-size:11px">Raw response from api.php:</div>
    <div class="response"><?= json_encode($kycStatus['decoded'], JSON_PRETTY_PRINT) ?></div>
  </div>

  <!-- TEST 2: submit_kyc with NIN -->
  <h2>📤 Test 2 — SUBMIT NIN</h2>
  <div class="url-box">
    <span class="method">POST</span>
    <span class="url-label">URL:</span>
    <span class="url-val">https://api.adildata.com.ng/api.php?action=submit_kyc</span>
  </div>
  <div class="card">
    <div class="row"><span class="lbl">HTTP Code</span><span class="val"><span class="<?= $submitNin['http_code']==200?'http-ok':'http-err' ?>"><?= $submitNin['http_code'] ?></span></span></div>
    <div class="row"><span class="lbl">Response Time</span><span class="val"><?= $submitNin['ms'] ?>ms</span></div>
    <div class="row"><span class="lbl">NIN Sent</span><span class="val"><?= htmlspecialchars($nin) ?></span></div>
    <div class="row"><span class="lbl">Result</span><span class="val"><?= badge($submitNin['decoded']['status']==='success') ?></span></div>
    <div class="lbl" style="margin-top:12px;font-size:11px">Request payload sent:</div>
    <div class="payload"><?= json_encode(['token'=>substr($token,0,24).'...','nin'=>$nin], JSON_PRETTY_PRINT) ?></div>
    <div class="lbl" style="margin-top:10px;font-size:11px">Raw response from api.php:</div>
    <div class="response"><?= json_encode($submitNin['decoded'], JSON_PRETTY_PRINT) ?></div>
  </div>

  <!-- TEST 3: submit_kyc with BVN -->
  <h2>📤 Test 3 — SUBMIT BVN</h2>
  <div class="url-box">
    <span class="method">POST</span>
    <span class="url-label">URL:</span>
    <span class="url-val">https://api.adildata.com.ng/api.php?action=submit_kyc</span>
  </div>
  <div class="card">
    <div class="row"><span class="lbl">HTTP Code</span><span class="val"><span class="<?= $submitBvn['http_code']==200?'http-ok':'http-err' ?>"><?= $submitBvn['http_code'] ?></span></span></div>
    <div class="row"><span class="lbl">Response Time</span><span class="val"><?= $submitBvn['ms'] ?>ms</span></div>
    <div class="row"><span class="lbl">BVN Sent</span><span class="val"><?= htmlspecialchars($bvn) ?></span></div>
    <div class="row"><span class="lbl">Result</span><span class="val"><?= badge($submitBvn['decoded']['status']==='success') ?></span></div>
    <div class="lbl" style="margin-top:12px;font-size:11px">Request payload sent:</div>
    <div class="payload"><?= json_encode(['token'=>substr($token,0,24).'...','bvn'=>$bvn], JSON_PRETTY_PRINT) ?></div>
    <div class="lbl" style="margin-top:10px;font-size:11px">Raw response from api.php:</div>
    <div class="response"><?= json_encode($submitBvn['decoded'], JSON_PRETTY_PRINT) ?></div>
  </div>

  <div class="ts">Page auto-refreshes with live data every visit. Real calls to api.php — no mocks.</div>
  </body>
  </html>
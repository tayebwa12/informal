<?php
declare(strict_types=1);

function egosms_normalize_msisdn(string $phone): string {
  $p = preg_replace('/\D+/', '', $phone) ?: '';
  // Uganda-friendly normalization:
  //  - 07XXXXXXXX => 2567XXXXXXXX
  //  - 2567XXXXXXXX stays
  //  - 7XXXXXXXX (rare) => 2567XXXXXXXX
  if ($p === '') return '';
  if (str_starts_with($p, '0') && strlen($p) === 10) return '256' . substr($p, 1);
  if (str_starts_with($p, '7')  && strlen($p) === 9)  return '256' . $p;
  return $p;
}

function egosms_send_one(array $cfg, string $to, string $message): array {
  // IMPORTANT:
  // EgoSMS has different API formats per plan/account.
  // This function is written to be "endpoint-agnostic":
  // You set EGOSMS_BASE_URL to the *exact send endpoint* provided by EgoSMS,
  // and you adjust the payload keys below once (if needed) to match their docs.

  if ($cfg['base_url'] === '' || $cfg['username'] === '' || $cfg['password'] === '') {
    return ['ok' => false, 'error' => 'EgoSMS config missing (base_url/username/password).'];
  }

  $to = egosms_normalize_msisdn($to);
  if ($to === '') return ['ok' => false, 'error' => 'Invalid phone number'];

  $payload = [
    // 👇 These keys are the most common pattern; if EgoSMS uses different keys,
    // change them ONCE here after checking EgoSMS API docs inside your dashboard.
    'username' => $cfg['username'],
    'password' => $cfg['password'],
    'sender'   => $cfg['sender'],
    'message'  => $message,
    'phone'    => $to,
  ];

  $ch = curl_init($cfg['base_url']);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => (int)$cfg['timeout'],
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => http_build_query($payload),
  ]);

  $raw  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) {
    return ['ok' => false, 'http_code' => $code, 'error' => $err ?: 'cURL failed'];
  }

  // Some APIs return JSON, some return plain text.
  $json = json_decode($raw, true);
  if (is_array($json)) {
    // Adjust success detection after you see EgoSMS actual response structure.
    $success = ($code >= 200 && $code < 300);
    return ['ok' => $success, 'http_code' => $code, 'response' => $json, 'raw' => $raw];
  }

  $success = ($code >= 200 && $code < 300);
  return ['ok' => $success, 'http_code' => $code, 'raw' => $raw];
}

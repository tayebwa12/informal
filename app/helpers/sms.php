<?php
declare(strict_types=1);

function sms_normalize_ug(string $phone): ?string {
  $p = trim($phone);
  if ($p === '') return null;

  // keep digits and +
  $p = preg_replace('/[^\d\+]/', '', $p);

  // Convert common UG formats to +2567XXXXXXXX
  if (str_starts_with($p, '0') && strlen($p) === 10) {
    $p = '+256' . substr($p, 1);
  } elseif (str_starts_with($p, '256') && strlen($p) === 12) {
    $p = '+' . $p;
  } elseif (str_starts_with($p, '+256') && strlen($p) === 13) {
    // ok
  } else {
    // If you want to allow other formats, relax this.
    // For now, reject unknown format to avoid wasting SMS credits.
    return null;
  }

  return $p;
}

function sms_send_thinkx(string $apiKey, string $endpoint, string $to, string $message): array {
  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_POSTFIELDS => http_build_query([
      'api_key' => $apiKey,
      'number'  => $to,
      'message' => $message,
    ]),
  ]);

  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) {
    return ['ok' => false, 'http' => $code, 'error' => $err ?: 'cURL error', 'raw' => null];
  }

  $json = json_decode($raw, true);
  return ['ok' => ($code >= 200 && $code < 300), 'http' => $code, 'error' => null, 'raw' => $raw, 'json' => $json];
}

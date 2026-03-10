<?php
declare(strict_types=1);

function sms_get_settings(PDO $pdo): ?array {
  $st = $pdo->query("SELECT * FROM sms_settings WHERE is_active=1 ORDER BY id DESC LIMIT 1");
  $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
  return $row ?: null;
}

function sms_send_egosms(array $settings, array $payload): array {
  $url = (string)($settings['api_url'] ?? 'https://comms.egosms.co/api/v1/json/');

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 40,
  ]);

  $body = curl_exec($ch);
  if ($body === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException("cURL error: " . ($err ?: 'Unknown'));
  }

  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return ['http_code' => $httpCode, 'body' => (string)$body];
}

/**
 * ✅ Strict: only true when the JSON indicates success/queued/accepted
 * (We’ll tune this after you paste a real response.)
 */
function sms_is_success(string $body): bool {
  $j = json_decode($body, true);
  if (!is_array($j)) return false;

  $status = strtolower((string)($j['status'] ?? $j['Status'] ?? $j['response'] ?? ''));
  if (in_array($status, ['success','ok','queued','accepted','sent'], true)) return true;

  if (isset($j['success']) && $j['success'] === true) return true;

  // if it has error/message that looks like error
  if (!empty($j['error'])) return false;
  if (!empty($j['message']) && preg_match('/invalid|error|fail|balance|insufficient|denied/i', (string)$j['message'])) return false;

  return false;
}
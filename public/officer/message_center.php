<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$me = require_officer();
$userId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** ------------------- FLASH (PRG) ------------------- */
function flash_set(string $key, string $val): void {
  $_SESSION['flash'][$key] = $val;
}
function flash_get(string $key): ?string {
  if (!isset($_SESSION['flash'][$key])) return null;
  $v = (string)$_SESSION['flash'][$key];
  unset($_SESSION['flash'][$key]);
  return $v;
}

/**
 * ✅ Redirect back to GET (prevents resubmission / ERR_CACHE_MISS)
 * Keeps q + page + (optional) anchor.
 */
function redirect_self(array $extraQuery = [], string $anchor = ''): void {
  $base = strtok($_SERVER['REQUEST_URI'], '?') ?: '';
  $q = trim((string)($_GET['q'] ?? ''));
  $page = max(1, (int)($_GET['page'] ?? 1));

  $qs = array_merge(['q' => $q, 'page' => $page], $extraQuery);
  // Clean empties (optional)
  if (($qs['q'] ?? '') === '') unset($qs['q']);
  if (($qs['page'] ?? 1) === 1) unset($qs['page']);

  $url = $base;
  if (!empty($qs)) $url .= '?' . http_build_query($qs);
  if ($anchor !== '') $url .= '#' . ltrim($anchor, '#');

  header("Location: {$url}");
  exit;
}

// ✅ MariaDB-safe + cached
function tableExists(PDO $pdo, string $table): bool {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  try {
    $sql = "SHOW TABLES LIKE " . $pdo->quote($table);
    $st = $pdo->query($sql);
    $cache[$table] = (bool)$st->fetchColumn();
    return $cache[$table];
  } catch (Throwable $e) {
    $cache[$table] = false;
    return false;
  }
}

function normalize_phone(?string $raw): ?string {
  $p = preg_replace('/\D+/', '', (string)$raw);
  if ($p === '') return null;

  if (strlen($p) === 9 && str_starts_with($p, '7')) return '256' . $p;
  if (strlen($p) === 10 && str_starts_with($p, '0')) return '256' . substr($p, 1);
  if (str_starts_with($p, '256') && strlen($p) >= 12) return $p;

  return $p;
}

/**
 * ✅ SERIES RULE:
 * Block ONLY if status is SENT or QUEUED.
 * FAILED can be resent.
 */
function already_touched_in_series(PDO $pdo, int $seriesId, string $phoneNorm): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM sms_sent_log
    WHERE exam_series_id=? AND phone_normalized=?
      AND status IN ('sent','queued')
    LIMIT 1
  ");
  $st->execute([$seriesId, $phoneNorm]);
  return (bool)$st->fetchColumn();
}

function sms_log_status(PDO $pdo, int $seriesId, string $phoneNorm): ?string {
  $st = $pdo->prepare("SELECT status FROM sms_sent_log WHERE exam_series_id=? AND phone_normalized=? LIMIT 1");
  $st->execute([$seriesId, $phoneNorm]);
  $s = $st->fetchColumn();
  return $s !== false ? (string)$s : null;
}

/**
 * ✅ If failed exists -> reuse it by setting queued + new message.
 * ✅ If no row exists -> insert queued.
 * ✅ If sent/queued exists -> return false (blocked).
 */
function sms_log_lock_or_requeue(
  PDO $pdo,
  int $seriesId,
  int $regionId,
  int $userId,
  int $examinerId,
  string $phoneNorm,
  string $message
): bool {
  $status = sms_log_status($pdo, $seriesId, $phoneNorm);

  if ($status === 'sent' || $status === 'queued') return false;

  if ($status === 'failed') {
    $pdo->prepare("
      UPDATE sms_sent_log
      SET status='queued', message=?, api_response=NULL, sent_by_user_id=?, examiner_id=?, region_id=?
      WHERE exam_series_id=? AND phone_normalized=? AND status='failed'
      LIMIT 1
    ")->execute([$message, $userId, $examinerId, $regionId, $seriesId, $phoneNorm]);
    return true;
  }

  $pdo->prepare("
    INSERT INTO sms_sent_log (exam_series_id, region_id, sent_by_user_id, examiner_id, phone_normalized, message, status)
    VALUES (?, ?, ?, ?, ?, ?, 'queued')
  ")->execute([$seriesId, $regionId, $userId, $examinerId, $phoneNorm, $message]);

  return true;
}

// ------------------- CONFIG: EgoSMS -------------------
const EGOSMS_URL      = 'https://comms.egosms.co/api/v1/json/';
const EGOSMS_USERNAME = 'tayebwayonah';
const EGOSMS_APIKEY   = 'f27de288a5ceb4f85141ef95af6806e6e0a5e54367596c11';
const EGOSMS_SENDERID = 'UVTAB';
const EGOSMS_PRIORITY = '0';
const BATCH_SIZE      = 100;

function egosms_send(array $payload): array {
  $ch = curl_init(EGOSMS_URL);

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

function egosms_send_retry(array $payload, int $retries = 1): array {
  $last = null;
  for ($i=0; $i <= $retries; $i++) {
    try { return egosms_send($payload); }
    catch (Throwable $e) { $last = $e; usleep(250000); }
  }
  throw $last ?: new RuntimeException("Unknown SMS error");
}

function egosms_is_success(string $body): bool {
  $j = json_decode($body, true);
  if (!is_array($j)) return false;

  $status = strtolower((string)($j['Status'] ?? $j['status'] ?? ''));
  if (in_array($status, ['success','ok','queued','accepted','sent'], true)) return true;
  if (isset($j['success']) && $j['success'] === true) return true;

  return false;
}

function personalize_message(string $template, string $name, bool $personalize): string {
  $template = trim($template);
  $name = trim($name);

  if (!$personalize || $name === '') return $template;

  $hasPlaceholder = (strpos($template, '{name}') !== false) || (strpos($template, '{{name}}') !== false);
  if ($hasPlaceholder) return str_replace(['{{name}}', '{name}'], $name, $template);

  return "Hello {$name}, " . $template;
}

function egosms_test_credentials(): array {
  $payload = [
    "method" => "SendSms",
    "userdata" => ["username" => EGOSMS_USERNAME, "password" => EGOSMS_APIKEY],
    "msgdata" => [[
      "number"   => "256700000000",
      "message"  => "TEST",
      "senderid" => EGOSMS_SENDERID,
      "priority" => EGOSMS_PRIORITY
    ]]
  ];
  return egosms_send_retry($payload, 0);
}

/**
 * ✅ Correct recipients loader:
 * - Base: users (region_id lock + name/phone)
 * - Status: latest examiner_applications row per user_id
 */
function load_recipients_users_latest_app(
  PDO $pdo,
  array $statusList,
  int $regionId,
  string $q,
  int $page,
  int $perPage
): array {
  $offset = ($page - 1) * $perPage;

  $statusList = array_values(array_filter(array_map(fn($s) => strtolower(trim((string)$s)), $statusList)));
  if (!$statusList) {
    return [
      'recipients' => [],
      'previewCount' => 0,
      'totalPages' => 1,
      'page' => 1,
      'dataSql' => '',
      'dataParams' => [],
    ];
  }

  $stIn = implode(',', array_fill(0, count($statusList), '?'));

  $searchSql = "";
  $searchParams = [];
  if ($q !== '') {
    $searchSql = " AND (u.full_name LIKE ? OR u.phone LIKE ?) ";
    $like = "%{$q}%";
    $searchParams = [$like, $like];
  }

  // COUNT
  $countSql = "
    SELECT COUNT(*)
    FROM users u
    JOIN (
      SELECT user_id, MAX(id) AS last_app_id
      FROM examiner_applications
      GROUP BY user_id
    ) la ON la.user_id = u.id
    JOIN examiner_applications ea ON ea.id = la.last_app_id
    WHERE u.region_id = ?
      AND LOWER(TRIM(ea.status)) IN ($stIn)
      $searchSql
  ";

  $countParams = array_merge([$regionId], $statusList, $searchParams);
  $stc = $pdo->prepare($countSql);
  $stc->execute($countParams);
  $previewCount = (int)$stc->fetchColumn();

  $totalPages = max(1, (int)ceil($previewCount / $perPage));
  if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
  }

  // DATA
  $dataSql = "
    SELECT
      u.id AS examiner_id,
      u.full_name AS full_name,
      u.phone AS phone,
      LOWER(TRIM(ea.status)) AS app_status,
      r.name AS region_name
    FROM users u
    JOIN regions r ON r.id = u.region_id
    JOIN (
      SELECT user_id, MAX(id) AS last_app_id
      FROM examiner_applications
      GROUP BY user_id
    ) la ON la.user_id = u.id
    JOIN examiner_applications ea ON ea.id = la.last_app_id
    WHERE u.region_id = ?
      AND LOWER(TRIM(ea.status)) IN ($stIn)
      $searchSql
    ORDER BY u.full_name ASC
    LIMIT $perPage OFFSET $offset
  ";

  $dataParams = $countParams;
  $st = $pdo->prepare($dataSql);
  $st->execute($dataParams);
  $recipients = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  return [
    'recipients'   => $recipients,
    'previewCount' => $previewCount,
    'totalPages'   => $totalPages,
    'page'         => $page,
    'dataSql'      => $dataSql,
    'dataParams'   => $dataParams,
  ];
}

/** ✅ Flash messages restored after redirect */
$msg = flash_get('msg');
$err = flash_get('err');

// ✅ Required tables
if (!tableExists($pdo, 'users')) die("Missing table: users");
if (!tableExists($pdo, 'examiner_applications')) die("Missing table: examiner_applications");
if (!tableExists($pdo, 'regions')) die("Missing table: regions");
if (!tableExists($pdo, 'officer_assignments')) die("Missing table: officer_assignments");
if (!tableExists($pdo, 'exam_series')) die("Missing table: exam_series");
if (!tableExists($pdo, 'sms_sent_log')) die("Missing table: sms_sent_log (create it first)");

// ------------------- ACTIVE SERIES -------------------
$activeSeriesId = 0;
try {
  $st = $pdo->query("SELECT id FROM exam_series WHERE status='active' ORDER BY id DESC LIMIT 1");
  $activeSeriesId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) { $activeSeriesId = 0; }

if ($activeSeriesId <= 0) die("❌ No ACTIVE exam series found. Please activate an exam series first.");

// ------------------- OFFICER REGION LOCK -------------------
$officerRegionId = 0;
try {
  $st = $pdo->prepare("
    SELECT region_id
    FROM officer_assignments
    WHERE user_id = ?
      AND exam_series_id = ?
      AND status = 'active'
    ORDER BY assigned_at DESC, id DESC
    LIMIT 1
  ");
  $st->execute([$userId, $activeSeriesId]);
  $officerRegionId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) { $officerRegionId = 0; }

if ($officerRegionId <= 0) die("❌ You are not assigned to any ACTIVE region for the current exam series. Contact admin.");

$regions = [];
try {
  $st = $pdo->prepare("SELECT id, name FROM regions WHERE id=? LIMIT 1");
  $st->execute([$officerRegionId]);
  $regions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $regions = []; }

if (!$regions) die("❌ Assigned region not found in regions table. Contact admin.");

// ------------------- UI STATE -------------------
$messageText = '';
$includeApproved = true;
$includePending  = true;
$includeRejected = false;
$personalize = true;

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$q = trim((string)($_GET['q'] ?? ''));

$recipients = [];
$previewCount = 0;
$totalPages = 1;

// ✅ Preload series phone status map
$phoneStatus = [];
try {
  $st = $pdo->prepare("SELECT phone_normalized, status FROM sms_sent_log WHERE exam_series_id=?");
  $st->execute([$activeSeriesId]);
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $phoneStatus[(string)$row['phone_normalized']] = (string)$row['status'];
  }
} catch (Throwable $e) { $phoneStatus = []; }


// ------------------- POST HANDLING -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  // ✅ Test Credentials (redirect after)
  if (isset($_POST['test_sms']) && $_POST['test_sms'] === '1') {
    try {
      $res = egosms_test_credentials();
      flash_set('msg', "✅ Gateway test response (HTTP " . (int)$res['http_code'] . "): " . $res['body']);
      flash_set('err', '');
    } catch (Throwable $e) {
      flash_set('err', "❌ Gateway test failed: " . $e->getMessage());
      flash_set('msg', '');
    }
    redirect_self([], 'top');
  }

  $messageText = trim((string)($_POST['message'] ?? ''));
  $personalize = isset($_POST['personalize']) && $_POST['personalize'] === '1';

  if ($messageText !== '') {
    $_SESSION['sms_last_message'] = $messageText;
    $_SESSION['sms_last_personalize'] = $personalize ? '1' : '0';
  }

  $includeApproved = isset($_POST['st_approved']);
  $includePending  = isset($_POST['st_pending']);
  $includeRejected = isset($_POST['st_rejected']);

  $statusList = [];
  if ($includeApproved) $statusList[] = 'approved';
  if ($includePending)  $statusList[] = 'pending';
  if ($includeRejected) $statusList[] = 'rejected';

  // ------------------- SINGLE SEND -------------------
  if (isset($_POST['single_send']) && $_POST['single_send'] === '1') {
    $targetPhoneRaw = (string)($_POST['target_phone'] ?? '');
    $targetExaminerId = (int)($_POST['target_examiner_id'] ?? 0);
    $targetName = trim((string)($_POST['target_name'] ?? ''));

    $phoneNorm = normalize_phone($targetPhoneRaw);

    if ($messageText === '' && !empty($_SESSION['sms_last_message'])) {
      $messageText = (string)$_SESSION['sms_last_message'];
      $personalize = ((string)($_SESSION['sms_last_personalize'] ?? '0') === '1');
    }

    $localMsg = '';
    $localErr = '';

    if ($messageText === '') {
      $localErr = "Message is required.";
    } elseif (mb_strlen($messageText) > 480) {
      $localErr = "Message too long. Keep it under 480 characters.";
    } elseif (!$phoneNorm) {
      $localErr = "Invalid phone number.";
    } elseif (already_touched_in_series($pdo, $activeSeriesId, $phoneNorm)) {
      $localErr = "❌ Blocked: This number is already SENT/QUEUED in this series.";
    } else {
      try {
        $finalMessage = personalize_message($messageText, $targetName, $personalize);

        $ok = sms_log_lock_or_requeue(
          $pdo,
          $activeSeriesId,
          $officerRegionId,
          $userId,
          $targetExaminerId,
          $phoneNorm,
          $finalMessage
        );
        if (!$ok) throw new RuntimeException("Blocked: already SENT/QUEUED in this series.");

        $payload = [
          "method" => "SendSms",
          "userdata" => ["username" => EGOSMS_USERNAME, "password" => EGOSMS_APIKEY],
          "msgdata" => [[
            "number"   => $phoneNorm,
            "message"  => $finalMessage,
            "senderid" => EGOSMS_SENDERID,
            "priority" => EGOSMS_PRIORITY
          ]]
        ];

        $res = egosms_send_retry($payload, 1);
        $http = (int)$res['http_code'];
        $body = (string)$res['body'];

        if ($http !== 200) throw new RuntimeException("HTTP {$http}: {$body}");
        if (!egosms_is_success($body)) throw new RuntimeException("Gateway rejected: {$body}");

        $pdo->prepare("
          UPDATE sms_sent_log
          SET status='sent', api_response=?
          WHERE exam_series_id=? AND phone_normalized=? LIMIT 1
        ")->execute([$body, $activeSeriesId, $phoneNorm]);

        $localMsg = "✅ SMS accepted by gateway for {$phoneNorm}.";

      } catch (Throwable $e) {
        try {
          if (!empty($phoneNorm)) {
            $pdo->prepare("
              UPDATE sms_sent_log
              SET status='failed', api_response=?
              WHERE exam_series_id=? AND phone_normalized=? AND status='queued'
              LIMIT 1
            ")->execute([$e->getMessage(), $activeSeriesId, $phoneNorm]);
          }
        } catch (Throwable $x) {}
        $localErr = "❌ Error sending SMS: " . $e->getMessage();
      }
    }

    flash_set('msg', $localMsg);
    flash_set('err', $localErr);
    redirect_self([], 'top');
  }

  // ------------------- SEND SELECTED (CURRENT PAGE) -------------------
  if (isset($_POST['selected_send']) && $_POST['selected_send'] === '1') {

    if ($messageText === '' && !empty($_SESSION['sms_last_message'])) {
      $messageText = (string)$_SESSION['sms_last_message'];
      $personalize = ((string)($_SESSION['sms_last_personalize'] ?? '0') === '1');
    }

    $localMsg = '';
    $localErr = '';

    if ($messageText === '') {
      $localErr = "Message is required.";
    } elseif (mb_strlen($messageText) > 480) {
      $localErr = "Message too long. Keep it under 480 characters.";
    } elseif (!$statusList) {
      $localErr = "Select at least one application status (approved/pending/rejected).";
    } else {
      $loaded = load_recipients_users_latest_app($pdo, $statusList, $officerRegionId, $q, $page, $perPage);
      $recipients = $loaded['recipients'];

      $selected = $_POST['selected_phones'] ?? [];
      if (!is_array($selected) || count($selected) === 0) {
        $localErr = "Select at least one examiner on this page.";
      } else {
        $map = [];
        foreach ($recipients as $r) {
          $pn = normalize_phone((string)($r['phone'] ?? ''));
          if ($pn) $map[$pn] = $r;
        }

        $msgdata = [];
        $numbersThisCall = [];
        $skipped = 0;

        foreach ($selected as $pnRaw) {
          $pn = normalize_phone((string)$pnRaw);
          if (!$pn) { $skipped++; continue; }
          if (already_touched_in_series($pdo, $activeSeriesId, $pn)) { $skipped++; continue; }

          $r = $map[$pn] ?? null;
          $name = $r ? (string)($r['full_name'] ?? '') : '';
          $examinerId = $r ? (int)($r['examiner_id'] ?? 0) : 0;

          $finalMessage = personalize_message($messageText, $name, $personalize);

          try {
            $ok = sms_log_lock_or_requeue($pdo, $activeSeriesId, $officerRegionId, $userId, $examinerId, $pn, $finalMessage);
            if (!$ok) { $skipped++; continue; }

            $msgdata[] = [
              "number"   => $pn,
              "message"  => $finalMessage,
              "senderid" => EGOSMS_SENDERID,
              "priority" => EGOSMS_PRIORITY
            ];
            $numbersThisCall[] = $pn;

          } catch (Throwable $e) {
            $skipped++;
          }
        }

        if (!$msgdata) {
          $localErr = "Nothing to send (all selected were blocked/invalid).";
        } else {
          try {
            $payload = [
              "method" => "SendSms",
              "userdata" => ["username" => EGOSMS_USERNAME, "password" => EGOSMS_APIKEY],
              "msgdata" => $msgdata
            ];

            $res = egosms_send_retry($payload, 1);
            $http = (int)$res['http_code'];
            $body = (string)$res['body'];

            if ($http !== 200) throw new RuntimeException("HTTP {$http}: {$body}");
            if (!egosms_is_success($body)) throw new RuntimeException("Gateway rejected: {$body}");

            $upd = $pdo->prepare("
              UPDATE sms_sent_log
              SET status='sent', api_response=?
              WHERE exam_series_id=? AND phone_normalized=? LIMIT 1
            ");
            foreach ($numbersThisCall as $num) {
              $upd->execute([$body, $activeSeriesId, $num]);
            }

            $localMsg = "✅ Selected send done. Sent: " . count($numbersThisCall) . " • Skipped: {$skipped}";

          } catch (Throwable $e) {
            $updFail = $pdo->prepare("
              UPDATE sms_sent_log
              SET status='failed', api_response=?
              WHERE exam_series_id=? AND phone_normalized=? AND status='queued' LIMIT 1
            ");
            foreach ($numbersThisCall as $num) {
              $updFail->execute([$e->getMessage(), $activeSeriesId, $num]);
            }
            $localErr = "❌ Selected send failed: " . $e->getMessage();
          }
        }
      }
    }

    flash_set('msg', $localMsg);
    flash_set('err', $localErr);
    redirect_self([], 'top');
  }

  // ------------------- BULK PREVIEW / BULK SEND -------------------
  // (Preview stays on same request; DoSend redirects after sending)
  if (!isset($_POST['single_send']) && !isset($_POST['selected_send'])) {

    // restore message from session if needed (for preview)
    if ($messageText === '' && !empty($_SESSION['sms_last_message'])) {
      $messageText = (string)$_SESSION['sms_last_message'];
      $personalize = ((string)($_SESSION['sms_last_personalize'] ?? '0') === '1');
    }

    if ($messageText === '') {
      $err = $err ?: "Message is required.";
    } elseif (mb_strlen($messageText) > 480) {
      $err = "Message too long. Keep it under 480 characters.";
    } elseif (!$statusList) {
      $err = "Select at least one application status (approved/pending/rejected).";
    } else {
      try {
        $loaded = load_recipients_users_latest_app($pdo, $statusList, $officerRegionId, $q, $page, $perPage);
        $recipients = $loaded['recipients'];
        $previewCount = $loaded['previewCount'];
        $totalPages = $loaded['totalPages'];
        $page = $loaded['page'];
        $dataSql = $loaded['dataSql'];
        $dataParams = $loaded['dataParams'];

        if ($previewCount <= 0) {
          $err = "No examiners found for your region with the selected statuses.";
        } else {
          $doSend = isset($_POST['do_send']) && $_POST['do_send'] === '1';

          if ($doSend) {
            $sentTotal = 0;
            $skippedTotal = 0;
            $failedTotal = 0;

            $scanLimit = 1000;
            $scanOffset = 0;

            $updSent = $pdo->prepare("
              UPDATE sms_sent_log
              SET status='sent', api_response=?
              WHERE exam_series_id=? AND phone_normalized=? LIMIT 1
            ");
            $updFail = $pdo->prepare("
              UPDATE sms_sent_log
              SET status='failed', api_response=?
              WHERE exam_series_id=? AND phone_normalized=? AND status='queued' LIMIT 1
            ");

            $bulkErr = '';

            while (true) {
              $scanSql = preg_replace('/LIMIT\s+\d+\s+OFFSET\s+\d+/i', "LIMIT $scanLimit OFFSET $scanOffset", $dataSql);
              $scanSt = $pdo->prepare($scanSql);
              $scanSt->execute($dataParams);
              $batchRows = $scanSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
              if (!$batchRows) break;

              $chunks = array_chunk($batchRows, BATCH_SIZE);
              foreach ($chunks as $batch) {
                $msgdata = [];
                $numbersThisCall = [];

                foreach ($batch as $r) {
                  $phoneNorm = normalize_phone((string)($r['phone'] ?? ''));
                  if (!$phoneNorm || strlen($phoneNorm) < 9) continue;

                  if (already_touched_in_series($pdo, $activeSeriesId, $phoneNorm)) {
                    $skippedTotal++;
                    continue;
                  }

                  $name = (string)($r['full_name'] ?? '');
                  $finalMessage = personalize_message($messageText, $name, $personalize);

                  try {
                    $ok = sms_log_lock_or_requeue(
                      $pdo,
                      $activeSeriesId,
                      $officerRegionId,
                      $userId,
                      (int)($r['examiner_id'] ?? 0),
                      $phoneNorm,
                      $finalMessage
                    );
                    if (!$ok) { $skippedTotal++; continue; }
                  } catch (Throwable $e) {
                    $skippedTotal++;
                    continue;
                  }

                  $msgdata[] = [
                    "number"   => $phoneNorm,
                    "message"  => $finalMessage,
                    "senderid" => EGOSMS_SENDERID,
                    "priority" => EGOSMS_PRIORITY
                  ];
                  $numbersThisCall[] = $phoneNorm;
                }

                if (!$msgdata) continue;

                $payload = [
                  "method" => "SendSms",
                  "userdata" => ["username" => EGOSMS_USERNAME, "password" => EGOSMS_APIKEY],
                  "msgdata" => $msgdata
                ];

                try {
                  $res = egosms_send_retry($payload, 1);
                  $http = (int)$res['http_code'];
                  $body = (string)$res['body'];

                  if ($http !== 200) throw new RuntimeException("HTTP {$http}: {$body}");
                  if (!egosms_is_success($body)) throw new RuntimeException("Gateway rejected batch: {$body}");

                  $sentTotal += count($numbersThisCall);

                  foreach ($numbersThisCall as $num) {
                    $updSent->execute([$body, $activeSeriesId, $num]);
                  }

                } catch (Throwable $e) {
                  $bulkErr = "❌ Bulk batch failed: " . $e->getMessage();
                  $failedTotal += count($numbersThisCall);

                  foreach ($numbersThisCall as $num) {
                    $updFail->execute([$e->getMessage(), $activeSeriesId, $num]);
                  }
                }
              }

              $scanOffset += $scanLimit;
              if ($scanOffset > 20000) break;
            }

            $finalMsg = $bulkErr
              ? "✅ Bulk finished. Sent: {$sentTotal} • Skipped: {$skippedTotal} • Failed: {$failedTotal}"
              : "✅ Bulk done. Sent: {$sentTotal} • Skipped (sent/queued): {$skippedTotal} • Failed: {$failedTotal}";

            flash_set('msg', $finalMsg);
            flash_set('err', $bulkErr);
            redirect_self([], 'top');
          }
        }

      } catch (Throwable $e) {
        $err = "❌ Error: " . $e->getMessage();
      }
    }
  }
}

/** ------------------- GET Preview State ------------------- */
/** If user wants recipients shown after preview, they must click Preview. */
$recipients = $recipients ?? [];
$previewCount = $previewCount ?? 0;
$totalPages = $totalPages ?? 1;

// ✅ Preload series phone status map (again not needed, already loaded above)
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Message Center</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../assets/css/main.css">
  <style>
    .pill{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:900;font-size:12px;background:#eef2ff}
    .pill.ok{background:#ecfdf5}
    .pill.bad{background:#fee2e2}
    .pill.warn{background:#fffbeb}
    .mini{font-size:13px;color:#64748b}
    .box{border:1px solid #e5e7eb;border-radius:14px;padding:12px}
    .checks{display:flex;gap:14px;flex-wrap:wrap;margin:10px 0}
    .checks label{display:flex;gap:8px;align-items:center}
    textarea{width:100%}
    .ro{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb}
    .searchbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .searchbar input{flex:1;background:#fff}
    .actions{display:flex;gap:10px;flex-wrap:wrap}
    .hint{padding:10px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;margin-top:10px}
    .sticky-actions{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:10px 0}
  </style>
</head>
<body>
<a id="top"></a>
<div class="container">

  <div class="card">
    <div class="card-title">
      <div>
        <h2 style="margin:0;">Message Center (SMS)</h2>
        <p class="muted" style="margin:6px 0 0;">
          Series: <b>#<?= (int)$activeSeriesId ?></b>
          • Region: <b><?= h($regions[0]['name'] ?? '') ?></b>
          • Region ID: <b><?= (int)$officerRegionId ?></b>
        </p>
        <p class="mini" style="margin:6px 0 0;">
          <b>Series Rule:</b> Only <b>SENT</b> and <b>QUEUED</b> are blocked. <b>FAILED</b> can be resent.
        </p>
      </div>
      <div class="flex gap-10">
        <a class="btn btn-outline" href="dashboard.php">← Back</a>
        <a class="btn btn-outline" href="../logout.php">Logout</a>
      </div>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <form method="post" style="margin-bottom:12px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">
      <button class="btn btn-outline" type="submit" name="test_sms" value="1">Test SMS Credentials</button>
      <span class="mini" style="margin-left:10px;">Shows if EgoSMS accepts your username/API key.</span>
    </form>

    <div class="box">
      <form method="get" class="searchbar">
        <div style="min-width:220px;">
          <label style="margin-top:0;">Search Examiner (Name or Phone)</label>
          <input class="ro" style="background:#fff" type="text" name="q" value="<?= h($q) ?>" placeholder="e.g. John / 2567...">
        </div>
        <div style="padding-top:22px;" class="actions">
          <button class="btn btn-outline" type="submit">Search</button>
          <?php if ($q !== ''): ?>
            <a class="btn btn-outline" href="<?= h(strtok($_SERVER["REQUEST_URI"], '?')) ?>">Reset</a>
          <?php endif; ?>
        </div>
      </form>
      <div class="mini">Search is locked to your region (users.region_id) and current active series.</div>
    </div>
  </div>

  <div class="card">
    <form method="post" id="bulkForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">

      <div class="grid grid-2">
        <div class="box">
          <label style="margin-top:0;">Your Assigned Region (Locked)</label>
          <input class="ro" type="text" value="<?= h($regions[0]['name'] ?? '') ?>" readonly>

          <div class="hint mini">
            ✅ Personalization:
            Use <b>{name}</b> in the message to insert the examiner name.<br>
            Example: <b>Dear {name}, please check your status.</b>
          </div>
        </div>

        <div class="box">
          <label style="margin-top:0;">Include which statuses? (from <code>examiner_applications.status</code>)</label>
          <div class="checks">
            <label><input type="checkbox" name="st_approved" <?= $includeApproved ? 'checked' : '' ?>> Approved</label>
            <label><input type="checkbox" name="st_pending"  <?= $includePending  ? 'checked' : '' ?>> Pending (Imported)</label>
            <label><input type="checkbox" name="st_rejected" <?= $includeRejected ? 'checked' : '' ?>> Rejected</label>
          </div>

          <div class="checks" style="margin-top:0;">
            <label>
              <input type="checkbox" name="personalize" value="1" <?= $personalize ? 'checked' : '' ?>>
              Personalize message with examiner name
            </label>
          </div>

          <label>Message</label>
          <textarea id="messageBox" name="message" rows="6" placeholder="Type your SMS message... (use {name} if you want)" required><?= h($messageText) ?></textarea>

          <div class="actions" style="margin-top:12px;">
            <button class="btn btn-outline" type="submit" name="preview" value="1">Preview</button>
            <button class="btn btn-primary" type="submit" name="do_send" value="1"
              onclick="return confirm('Send bulk SMS? SENT/QUEUED are blocked. FAILED will be resent.');">
              Send Bulk SMS
            </button>
          </div>

          <div class="mini" style="margin-top:10px;">
            Recipients are from <b>users</b> (region_id locked), but filtering uses the <b>latest examiner_applications.status</b>.
          </div>
        </div>
      </div>
    </form>
  </div>

<?php
// ✅ Show recipients ONLY after Preview POST (non-redirect) OR you can manually load on GET if you want.
// Current behavior: Only shows when $recipients is populated (i.e., after preview POST).
?>

  <?php if (!empty($recipients)): ?>
    <div class="card">
      <div class="card-title" style="align-items:flex-end;">
        <div>
          <h3 style="margin:0;">Recipients</h3>
          <div class="mini">Total matches: <?= (int)$previewCount ?> • Page <?= (int)$page ?> of <?= (int)$totalPages ?></div>
        </div>
        <div class="mini">Showing <?= (int)count($recipients) ?> records</div>
      </div>

      <form method="post" id="selectedSendForm" style="margin:0;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">
        <input type="hidden" name="selected_send" value="1">
        <input type="hidden" name="message" id="selectedMessage" value="">
        <input type="hidden" name="personalize" id="selectedPersonalize" value="0">

        <?php if ($includeApproved): ?><input type="hidden" name="st_approved" value="1"><?php endif; ?>
        <?php if ($includePending): ?><input type="hidden" name="st_pending" value="1"><?php endif; ?>
        <?php if ($includeRejected): ?><input type="hidden" name="st_rejected" value="1"><?php endif; ?>

        <div class="sticky-actions">
          <div class="actions">
            <button class="btn btn-primary" type="submit"
              onclick="return confirm('Send SMS to SELECTED examiners on this page? FAILED will retry.');">
              Send Selected (This Page)
            </button>
            <button class="btn btn-outline" type="button" id="selectAllBtn">Select All</button>
            <button class="btn btn-outline" type="button" id="clearAllBtn">Clear</button>
          </div>
          <div class="mini">Tip: Selection works per page (pagination). Use Next/Prev to select more on other pages.</div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
            <tr>
              <th>Select</th>
              <th>Region</th>
              <th>Status</th>
              <th>Examiner</th>
              <th>Phone</th>
              <th>Series SMS</th>
              <th>Send One-by-One</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recipients as $r): ?>
              <?php
                $pn = normalize_phone((string)($r['phone'] ?? ''));
                $st = ($pn && isset($phoneStatus[$pn])) ? $phoneStatus[$pn] : null;

                $label = 'NOT SENT';
                $cls = 'ok';
                if ($st === 'sent') { $label = 'SENT'; $cls = 'bad'; }
                elseif ($st === 'failed') { $label = 'FAILED'; $cls = 'warn'; }
                elseif ($st === 'queued') { $label = 'QUEUED'; $cls = 'warn'; }

                $blocked = ($pn && isset($phoneStatus[$pn]) && in_array($phoneStatus[$pn], ['sent','queued'], true));
                $fullName = (string)($r['full_name'] ?? '');
              ?>
              <tr>
                <td>
                  <?php if ($pn && !$blocked): ?>
                    <input type="checkbox" class="pick" name="selected_phones[]" value="<?= h($pn) ?>">
                  <?php else: ?>
                    <span class="mini">-</span>
                  <?php endif; ?>
                </td>
                <td><?= h((string)$r['region_name']) ?></td>
                <td><span class="pill"><?= h(strtoupper((string)$r['app_status'])) ?></span></td>
                <td><?= h($fullName) ?></td>
                <td><?= h((string)($r['phone'] ?? '')) ?></td>
                <td><span class="pill <?= h($cls) ?>"><?= h($label) ?></span></td>
                <td>
                  <?php if ($blocked): ?>
                    <span class="mini">Blocked</span>
                  <?php else: ?>
                    <form method="post" class="singleSendForm" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">
                      <input type="hidden" name="single_send" value="1">
                      <input type="hidden" name="target_phone" value="<?= h((string)($r['phone'] ?? '')) ?>">
                      <input type="hidden" name="target_examiner_id" value="<?= (int)($r['examiner_id'] ?? 0) ?>">
                      <input type="hidden" name="target_name" value="<?= h($fullName) ?>">

                      <input type="hidden" name="message" class="msgHidden" value="">
                      <input type="hidden" name="personalize" class="persHidden" value="0">

                      <button class="btn btn-primary" type="submit"
                        onclick="return confirm('Send SMS to <?= h($fullName) ?>? SENT/QUEUED blocks. FAILED can retry.');">
                        <?= ($st === 'failed') ? 'Resend' : 'Send' ?>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </form>

      <?php
        $base = strtok($_SERVER["REQUEST_URI"], '?');
        $qs = $_GET;
      ?>
      <div class="flex" style="justify-content:space-between;align-items:center;margin-top:12px;flex-wrap:wrap;">
        <div class="mini">Page <?= (int)$page ?> of <?= (int)$totalPages ?></div>
        <div class="actions">
          <?php if ($page > 1): ?>
            <?php $qs['page'] = $page - 1; ?>
            <a class="btn btn-outline" href="<?= h($base . '?' . http_build_query($qs)) ?>">← Prev</a>
          <?php endif; ?>

          <?php if ($page < $totalPages): ?>
            <?php $qs['page'] = $page + 1; ?>
            <a class="btn btn-outline" href="<?= h($base . '?' . http_build_query($qs)) ?>">Next →</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
(function () {
  const msgBox = document.getElementById('messageBox');
  const bulkForm = document.getElementById('bulkForm');

  function isPersonalizeOn() {
    const p = bulkForm ? bulkForm.querySelector('input[name="personalize"]') : null;
    return !!(p && p.checked);
  }

  document.querySelectorAll('.singleSendForm').forEach(form => {
    form.addEventListener('submit', function () {
      const msgHidden = form.querySelector('.msgHidden');
      const persHidden = form.querySelector('.persHidden');
      if (msgHidden) msgHidden.value = msgBox ? msgBox.value : '';
      if (persHidden) persHidden.value = isPersonalizeOn() ? '1' : '0';
    });
  });

  const selForm = document.getElementById('selectedSendForm');
  if (selForm) {
    selForm.addEventListener('submit', function () {
      const m = document.getElementById('selectedMessage');
      const p = document.getElementById('selectedPersonalize');
      if (m) m.value = msgBox ? msgBox.value : '';
      if (p) p.value = isPersonalizeOn() ? '1' : '0';
    });

    const selectAllBtn = document.getElementById('selectAllBtn');
    const clearAllBtn = document.getElementById('clearAllBtn');

    if (selectAllBtn) {
      selectAllBtn.addEventListener('click', function () {
        document.querySelectorAll('input.pick[type="checkbox"]').forEach(cb => cb.checked = true);
      });
    }
    if (clearAllBtn) {
      clearAllBtn.addEventListener('click', function () {
        document.querySelectorAll('input.pick[type="checkbox"]').forEach(cb => cb.checked = false);
      });
    }
  }
})();
</script>

</body>
</html>
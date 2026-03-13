<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_admin();

$msg = $_GET['msg'] ?? null;
$err = null;
$warnings = $_SESSION['import_warnings'] ?? [];
unset($_SESSION['import_warnings']);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function norm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}

function tableExists(PDO $pdo, string $table): bool {
  try {
    $q = $pdo->quote($table);
    $sql = "SHOW TABLES LIKE $q";
    return (bool)$pdo->query($sql)->fetchColumn();
  } catch (Throwable $e) { return false; }
}

function columnExists(PDO $pdo, string $table, string $col): bool {
  try {
    $tableSafe = str_replace('`', '``', $table);
    $q = $pdo->quote($col);
    $sql = "SHOW COLUMNS FROM `$tableSafe` LIKE $q";
    return (bool)$pdo->query($sql)->fetchColumn();
  } catch (Throwable $e) { return false; }
}

function cleanHeader(string $s): string {
  $s = trim($s);
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
  $s = str_replace("\xC2\xA0", ' ', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  $s = str_replace(' ', '_', $s);
  $s = str_replace('-', '_', $s);
  return strtolower(trim($s));
}

function excelSerialToDate(float $serial): ?string {
  if ($serial <= 0) return null;
  $base = new DateTimeImmutable('1899-12-30');
  $days = (int) floor($serial);
  return $base->modify("+{$days} days")->format('Y-m-d');
}

/**
 * Fixed date normalization:
 * - handles DateTime objects
 * - handles Excel serial values
 * - correctly parses 24/03/2026
 * - supports ., -, / separators
 * - handles PHP versions where DateTime::getLastErrors() returns false on success
 */
function normalizeDateFlexible($raw): ?string {
  if ($raw === null) return null;

  if ($raw instanceof DateTimeInterface) {
    return $raw->format('Y-m-d');
  }

  // Excel numeric serial date
  if (
    is_int($raw) ||
    is_float($raw) ||
    (is_string($raw) && preg_match('/^\d+(\.\d+)?$/', trim($raw)))
  ) {
    $num = (float)$raw;
    if ($num > 30000) {
      return excelSerialToDate($num);
    }
  }

  $raw = trim((string)$raw);
  if ($raw === '') return null;

  // Already normalized
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
    return $raw;
  }

  $raw = str_replace("\xC2\xA0", ' ', $raw);
  $raw = trim($raw);
  $raw = preg_replace('/\s+/', ' ', $raw);
  $raw = str_replace(['.', '-'], '/', $raw);

  $formats = [
    '!d/m/Y',
    '!j/n/Y',
    '!d/m/y',
    '!j/n/y',
    '!Y/m/d',
    '!m/d/Y',
    '!n/j/Y',
  ];

  foreach ($formats as $format) {
    $dt = DateTime::createFromFormat($format, $raw);
    if ($dt === false) {
      continue;
    }

    $errors = DateTime::getLastErrors();

    if (
      $errors === false ||
      (
        ($errors['warning_count'] ?? 0) === 0 &&
        ($errors['error_count'] ?? 0) === 0
      )
    ) {
      return $dt->format('Y-m-d');
    }
  }

  $ts = strtotime($raw);
  if ($ts !== false) {
    return date('Y-m-d', $ts);
  }

  return null;
}

function normalizeTimeFlexible($raw): ?string {
  if ($raw === null) return null;
  if ($raw instanceof DateTimeInterface) return $raw->format('H:i:s');

  if (is_int($raw) || is_float($raw)) {
    $num = (float)$raw;
    if ($num > 0 && $num < 1) {
      $seconds = (int) round($num * 86400) % 86400;
      return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
    if ($num >= 0 && $num < 24 && floor($num) == $num) return sprintf('%02d:00:00', (int)$num);
    if ($num >= 0 && $num < 24) {
      $totalMinutes = (int) round($num * 60);
      return sprintf('%02d:%02d:00', intdiv($totalMinutes, 60), $totalMinutes % 60);
    }
    return null;
  }

  $raw = trim((string)$raw);
  if ($raw === '') return null;

  if (preg_match('/^\d+(\.\d+)?$/', $raw)) {
    $num = (float)$raw;
    if ($num > 0 && $num < 1) {
      $seconds = (int) round($num * 86400) % 86400;
      return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
    if ($num >= 0 && $num < 24 && floor($num) == $num) return sprintf('%02d:00:00', (int)$num);
    if ($num >= 0 && $num < 24) {
      $totalMinutes = (int) round($num * 60);
      return sprintf('%02d:%02d:00', intdiv($totalMinutes, 60), $totalMinutes % 60);
    }
    return null;
  }

  if (preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
    [$h, $m] = explode(':', $raw, 2);
    $h = (int)$h; $m = (int)$m;
    if ($h < 0 || $h > 23 || $m < 0 || $m > 59) return null;
    return sprintf('%02d:%02d:00', $h, $m);
  }

  if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $raw)) {
    [$h, $m, $s] = explode(':', $raw, 3);
    $h = (int)$h; $m = (int)$m; $s = (int)$s;
    if ($h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59) return null;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
  }

  if (preg_match('/^\d{1,2}$/', $raw)) {
    $h = (int)$raw;
    if ($h < 0 || $h > 23) return null;
    return sprintf('%02d:00:00', $h);
  }

  return null;
}

function readTabularFile(string $tmpPath, string $ext): array {
  $ext = strtolower($ext);

  if ($ext === 'csv') {
    $fh = fopen($tmpPath, 'r');
    if (!$fh) throw new RuntimeException("Unable to read uploaded CSV file.");

    $header = fgetcsv($fh);
    if (!$header) throw new RuntimeException("CSV file is empty.");

    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
      $rows[] = $r;
    }
    fclose($fh);

    return ['header' => $header, 'rows' => $rows];
  }

  if ($ext === 'xlsx') {
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException("XLSX import requires PhpSpreadsheet.");

    require_once $autoload;
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);

    $spreadsheet = $reader->load($tmpPath);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, true, false);

    if (!$data || !isset($data[0])) {
      throw new RuntimeException("XLSX file is empty.");
    }

    $header = (array)$data[0];
    $rows = [];
    for ($i = 1; $i < count($data); $i++) {
      $rows[] = (array)$data[$i];
    }

    return ['header' => $header, 'rows' => $rows];
  }

  throw new RuntimeException("Unsupported file type.");
}

if (!tableExists($pdo, 'timetable_sessions')) die("❌ Missing table: timetable_sessions");
$has_series_id_col = columnExists($pdo, 'timetable_sessions', 'exam_series_id');
$has_series_col    = columnExists($pdo, 'timetable_sessions', 'exam_series');
if (!$has_series_id_col && !$has_series_col) die("❌ Missing required columns.");

$seriesCol = $has_series_id_col ? 'exam_series_id' : 'exam_series';

/* ---------------- FILTER DATA ---------------- */
$series = $pdo->query("SELECT id, name, status FROM exam_series ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$occupations = $pdo->query("SELECT id, code, name FROM occupations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$filter_series_id = (int)($_GET['series_id'] ?? 0);
$filter_occ_id    = (int)($_GET['occ_id'] ?? 0);
$filter_status    = $_GET['status'] ?? 'active';
if (!in_array($filter_status, ['active', 'inactive', 'all'], true)) {
  $filter_status = 'active';
}

$form_series_id   = (int)($_GET['form_series_id'] ?? 0);
if ($form_series_id <= 0 && $filter_series_id > 0) $form_series_id = $filter_series_id;

function buildWhere(array &$params, int $seriesId, int $occId, string $seriesCol, string $status = 'active'): string {
  $params = [];
  $parts  = [];

  if ($seriesId > 0) {
    $parts[] = "ts.$seriesCol = ?";
    $params[] = $seriesId;
  }

  if ($occId > 0) {
    $parts[] = "ts.occupation_id = ?";
    $params[] = $occId;
  }

  if ($status === 'active' || $status === 'inactive') {
    $parts[] = "ts.status = ?";
    $params[] = $status;
  }

  return $parts ? ("WHERE " . implode(" AND ", $parts)) : "";
}

/** EXPORT **/
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="timetable_' . date('Ymd_His') . '.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['Series','Center Number','Center Name','Date','Start Time','End Time','Occupation Code','Occupation','Candidates','Status']);

  $params = [];
  $where = buildWhere($params, $filter_series_id, $filter_occ_id, $seriesCol, $filter_status);

  $st = $pdo->prepare("
    SELECT es.name AS exam_series_name,
           c.center_number, c.center_name,
           ts.session_date, ts.start_time, ts.end_time,
           o.code AS occ_code, o.name AS occ_name,
           ts.candidate_count, ts.status
    FROM timetable_sessions ts
    JOIN exam_series es ON es.id = ts.$seriesCol
    JOIN centers c ON c.id = ts.center_id
    JOIN occupations o ON o.id = ts.occupation_id
    $where
    ORDER BY es.name, c.center_number, ts.session_date, ts.start_time
  ");
  $st->execute($params);

  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $r['exam_series_name'],
      $r['center_number'],
      $r['center_name'],
      $r['session_date'],
      $r['start_time'],
      $r['end_time'],
      $r['occ_code'],
      $r['occ_name'],
      $r['candidate_count'],
      $r['status']
    ]);
  }

  fclose($out);
  exit;
}

function resolveSeriesId(PDO $pdo, string $raw): int {
  $raw = norm($raw);
  if ($raw === '') return 0;

  if (ctype_digit($raw)) {
    $st = $pdo->prepare("SELECT id FROM exam_series WHERE id=? LIMIT 1");
    $st->execute([(int)$raw]);
    return (int)$st->fetchColumn();
  }

  $st = $pdo->prepare("SELECT id FROM exam_series WHERE name=? LIMIT 1");
  $st->execute([$raw]);
  return (int)$st->fetchColumn();
}

/** IMPORT **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
  csrf_validate($_POST['csrf'] ?? null);

  if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $err = "Please choose a valid file.";
  } else {
    $tmp = $_FILES['csv_file']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
    $selectedSeriesId = (int)($_POST['import_series_id'] ?? 0);

    try {
      $tab = readTabularFile($tmp, $ext);
      $header = $tab['header'];
      $rows = $tab['rows'];
      $map = [];

      foreach ($header as $i => $col) {
        $key = cleanHeader((string)$col);
        if ($key !== '') $map[$key] = $i;
      }

      $seriesAliases = ['exam_series','examseries','series'];
      $seriesKey = null;
      foreach ($seriesAliases as $alias) {
        if (isset($map[cleanHeader($alias)])) {
          $seriesKey = cleanHeader($alias);
          break;
        }
      }

      $required = ['center_number','center_name','occupation_code','occupation_name','session_date','start_time','end_time','candidate_count'];
      foreach ($required as $r) {
        if (!array_key_exists($r, $map)) {
          throw new RuntimeException("Missing column: {$r}");
        }
      }

      if ($seriesKey === null && $selectedSeriesId <= 0) {
        throw new RuntimeException("Series context required.");
      }

      $inserted = 0;
      $skipped = 0;
      $line = 1;
      $importWarnings = [];

      $pdo->beginTransaction();
      try {
        $stCenter = $pdo->prepare("SELECT id, center_name FROM centers WHERE center_number=? LIMIT 1");
        $stOcc = $pdo->prepare("SELECT id, name FROM occupations WHERE code=? LIMIT 1");

        $stDup = $pdo->prepare("
          SELECT id FROM timetable_sessions
          WHERE $seriesCol=? AND center_id=? AND session_date=? AND start_time=? AND end_time=? AND occupation_id=? AND status='active'
          LIMIT 1
        ");

        $sqlIns = ($has_series_id_col && $has_series_col)
          ? "INSERT INTO timetable_sessions (exam_series, exam_series_id, session_date, start_time, end_time, occupation_id, center_id, candidate_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')"
          : "INSERT INTO timetable_sessions ($seriesCol, session_date, start_time, end_time, occupation_id, center_id, candidate_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')";

        $stIns = $pdo->prepare($sqlIns);

        foreach ($rows as $row) {
          $line++;

          if (trim(implode('', (array)$row)) === '') continue;

          $exam_series_raw = $seriesKey ? (string)($row[$map[$seriesKey]] ?? '') : '';
          $center_number = norm((string)($row[$map['center_number']] ?? ''));
          $occ_code = norm((string)($row[$map['occupation_code']] ?? ''));
          $rawDate = (string)($row[$map['session_date']] ?? '');
          $rawStart = (string)($row[$map['start_time']] ?? '');
          $rawEnd = (string)($row[$map['end_time']] ?? '');
          $date = normalizeDateFlexible($rawDate);
          $start = normalizeTimeFlexible($rawStart);
          $end = normalizeTimeFlexible($rawEnd);
          $count = (int)($row[$map['candidate_count']] ?? 0);

          if (!$center_number || !$occ_code || !$date || !$start || !$end) {
            $missing = [];
            if (!$center_number) $missing[] = 'center_number';
            if (!$occ_code) $missing[] = 'occupation_code';
            if (!$date) $missing[] = "session_date (raw: {$rawDate})";
            if (!$start) $missing[] = "start_time (raw: {$rawStart})";
            if (!$end) $missing[] = "end_time (raw: {$rawEnd})";

            $importWarnings[] = "Line {$line}: Invalid data in " . implode(', ', $missing) . ". Skipped.";
            $skipped++;
            continue;
          }

          if (strtotime($end) <= strtotime($start)) {
            $importWarnings[] = "Line {$line}: Time mismatch. Skipped.";
            $skipped++;
            continue;
          }

          $sid = (trim($exam_series_raw) !== '') ? resolveSeriesId($pdo, $exam_series_raw) : $selectedSeriesId;
          if ($sid <= 0) {
            $importWarnings[] = "Line {$line}: Series not found for value '{$exam_series_raw}'. Skipped.";
            $skipped++;
            continue;
          }

          $stCenter->execute([$center_number]);
          $c = $stCenter->fetch(PDO::FETCH_ASSOC);
          if (!$c) {
            $importWarnings[] = "Line {$line}: Center not found for center_number '{$center_number}'. Skipped.";
            $skipped++;
            continue;
          }

          $stOcc->execute([$occ_code]);
          $o = $stOcc->fetch(PDO::FETCH_ASSOC);
          if (!$o) {
            $importWarnings[] = "Line {$line}: Occupation not found for occupation_code '{$occ_code}'. Skipped.";
            $skipped++;
            continue;
          }

          $stDup->execute([$sid, (int)$c['id'], $date, $start, $end, (int)$o['id']]);
          if ($stDup->fetchColumn()) {
            $importWarnings[] = "Line {$line}: Active duplicate exists for center '{$center_number}', occupation '{$occ_code}', date '{$date}', time '{$start} - {$end}'. Skipped.";
            $skipped++;
            continue;
          }

          if ($has_series_id_col && $has_series_col) {
            $stIns->execute([$sid, $sid, $date, $start, $end, $o['id'], $c['id'], $count]);
          } else {
            $stIns->execute([$sid, $date, $start, $end, $o['id'], $c['id'], $count]);
          }

          $inserted++;
        }

        $pdo->commit();

        $_SESSION['import_warnings'] = $importWarnings;

        $qs = $_GET;
        unset($qs['edit_id']);
        if (!isset($qs['status']) || $qs['status'] === '') {
          $qs['status'] = 'active';
        }
        $qs['msg'] = "Import complete: {$inserted} inserted, {$skipped} skipped.";
        header("Location: ?" . http_build_query($qs));
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = "Import failed: " . $e->getMessage();
      }
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

/** ADD MANUAL **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
  csrf_validate($_POST['csrf'] ?? null);

  $sid = (int)($_POST['series_id'] ?? 0);
  $cn = norm((string)($_POST['center_number'] ?? ''));
  $oc = norm((string)($_POST['occupation_code'] ?? ''));
  $date = normalizeDateFlexible($_POST['session_date'] ?? '');
  $start = normalizeTimeFlexible($_POST['start_time'] ?? '');
  $end = normalizeTimeFlexible($_POST['end_time'] ?? '');
  $count = (int)($_POST['candidate_count'] ?? 0);
  $status = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';

  if ($sid <= 0 || !$cn || !$oc || !$date || !$start || !$end) {
    $err = "All fields are required.";
  } elseif (strtotime($end) <= strtotime($start)) {
    $err = "End time must be after start time.";
  } else {
    try {
      $st = $pdo->prepare("SELECT id FROM centers WHERE center_number=? LIMIT 1");
      $st->execute([$cn]);
      $cid = (int)$st->fetchColumn();

      $st = $pdo->prepare("SELECT id FROM occupations WHERE code=? LIMIT 1");
      $st->execute([$oc]);
      $oid = (int)$st->fetchColumn();

      if ($cid <= 0 || $oid <= 0) {
        throw new RuntimeException("Center or Occupation not found.");
      }

      $stDup = $pdo->prepare("
        SELECT id FROM timetable_sessions
        WHERE $seriesCol=? AND center_id=? AND session_date=? AND start_time=? AND end_time=? AND occupation_id=? AND status='active'
        LIMIT 1
      ");
      $stDup->execute([$sid, $cid, $date, $start, $end, $oid]);

      if ($stDup->fetchColumn()) {
        throw new RuntimeException("An active session with the same details already exists.");
      }

      if ($has_series_id_col && $has_series_col) {
        $st = $pdo->prepare("INSERT INTO timetable_sessions (exam_series, exam_series_id, session_date, start_time, end_time, occupation_id, center_id, candidate_count, status) VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute([$sid, $sid, $date, $start, $end, $oid, $cid, $count, $status]);
      } else {
        $st = $pdo->prepare("INSERT INTO timetable_sessions ($seriesCol, session_date, start_time, end_time, occupation_id, center_id, candidate_count, status) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([$sid, $date, $start, $end, $oid, $cid, $count, $status]);
      }

      $qs = $_GET;
      unset($qs['edit_id']);
      if (!isset($qs['status']) || $qs['status'] === '') {
        $qs['status'] = 'active';
      }
      $qs['msg'] = 'Session added successfully.';
      header("Location: ?" . http_build_query($qs));
      exit;
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

/** UPDATE **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
  csrf_validate($_POST['csrf'] ?? null);

  $id    = (int)($_POST['id'] ?? 0);
  $date  = normalizeDateFlexible($_POST['session_date'] ?? '');
  $start = normalizeTimeFlexible($_POST['start_time'] ?? '');
  $end   = normalizeTimeFlexible($_POST['end_time'] ?? '');
  $oc    = norm((string)($_POST['occupation_code'] ?? ''));
  $count = (int)($_POST['candidate_count'] ?? 0);
  $status = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';

  if ($id <= 0 || !$date || !$start || !$end || !$oc) {
    $err = "All fields are required.";
  } elseif (strtotime($end) <= strtotime($start)) {
    $err = "End time must be after start time.";
  } else {
    try {
      $st = $pdo->prepare("SELECT $seriesCol AS sid, center_id FROM timetable_sessions WHERE id=? LIMIT 1");
      $st->execute([$id]);
      $cur = $st->fetch(PDO::FETCH_ASSOC);
      if (!$cur) throw new RuntimeException("Session not found.");

      $sid = (int)$cur['sid'];
      $center_id = (int)$cur['center_id'];

      $st = $pdo->prepare("SELECT id FROM occupations WHERE code=? LIMIT 1");
      $st->execute([$oc]);
      $oid = (int)$st->fetchColumn();
      if ($oid <= 0) throw new RuntimeException("Occupation not found.");

      $stDup = $pdo->prepare("
        SELECT id FROM timetable_sessions
        WHERE $seriesCol=? AND center_id=? AND session_date=? AND start_time=? AND end_time=? AND occupation_id=?
          AND status='active'
          AND id <> ?
        LIMIT 1
      ");
      $stDup->execute([$sid, $center_id, $date, $start, $end, $oid, $id]);
      if ($stDup->fetchColumn()) throw new RuntimeException("Another active session with the same details already exists.");

      $stUp = $pdo->prepare("
        UPDATE timetable_sessions
        SET session_date=?, start_time=?, end_time=?, occupation_id=?, candidate_count=?, status=?
        WHERE id=?
      ");
      $stUp->execute([$date, $start, $end, $oid, $count, $status, $id]);

      $qs = $_GET;
      unset($qs['edit_id']);
      if (!isset($qs['status']) || $qs['status'] === '') {
        $qs['status'] = 'active';
      }
      $qs['msg'] = "Session updated successfully.";
      header("Location: ?" . http_build_query($qs));
      exit;
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

/** DEACTIVATE / ACTIVATE **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
  csrf_validate($_POST['csrf'] ?? null);

  $id = (int)($_POST['id'] ?? 0);
  $newStatus = ($_POST['new_status'] ?? '') === 'inactive' ? 'inactive' : 'active';

  if ($id <= 0) {
    $err = "Invalid session ID.";
  } else {
    try {
      $st = $pdo->prepare("UPDATE timetable_sessions SET status = ? WHERE id = ?");
      $st->execute([$newStatus, $id]);

      $qs = $_GET;
      unset($qs['edit_id']);

      if (!isset($qs['status']) || $qs['status'] === '' || $qs['status'] === 'all') {
        $qs['status'] = 'active';
      }

      $qs['msg'] = $newStatus === 'inactive'
        ? 'Session deactivated successfully.'
        : 'Session activated successfully.';

      header("Location: ?" . http_build_query($qs));
      exit;
    } catch (Throwable $e) {
      $err = "Status update failed: " . $e->getMessage();
    }
  }
}

/** PAGINATION & FETCH **/
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$params = [];
$where = buildWhere($params, $filter_series_id, $filter_occ_id, $seriesCol, $filter_status);

$stCount = $pdo->prepare("SELECT COUNT(*) FROM timetable_sessions ts $where");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sessions = $pdo->prepare("
  SELECT ts.id, es.name AS exam_series_name,
         ts.session_date, ts.start_time, ts.end_time,
         ts.candidate_count, ts.status,
         o.code AS occ_code, o.name AS occ_name,
         c.center_number, c.center_name
  FROM timetable_sessions ts
  JOIN exam_series es ON es.id = ts.$seriesCol
  JOIN occupations o ON o.id = ts.occupation_id
  JOIN centers c ON c.id = ts.center_id
  $where
  ORDER BY es.name, c.center_number, ts.session_date, ts.start_time
  LIMIT $perPage OFFSET $offset
");
$sessions->execute($params);
$rows = $sessions->fetchAll(PDO::FETCH_ASSOC);

/** EDIT FETCH **/
$edit_id = (int)($_GET['edit_id'] ?? 0);
$editRow = null;

if ($edit_id > 0) {
  $st = $pdo->prepare("
    SELECT ts.id, ts.session_date, ts.start_time, ts.end_time, ts.candidate_count, ts.status,
           o.code AS occ_code,
           c.center_number,
           ts.$seriesCol AS series_id
    FROM timetable_sessions ts
    JOIN occupations o ON o.id = ts.occupation_id
    JOIN centers c ON c.id = ts.center_id
    WHERE ts.id = ?
    LIMIT 1
  ");
  $st->execute([$edit_id]);
  $editRow = $st->fetch(PDO::FETCH_ASSOC);

  if (!$editRow) {
    $edit_id = 0;
    $warnings[] = "Selected session for edit was not found (it may have been deleted).";
  }
}

function buildPageUrl(int $p): string {
  $qs = $_GET;
  $qs['page'] = $p;
  return '?' . http_build_query($qs);
}

function seriesNameById(array $series, int $id): string {
  foreach ($series as $s) {
    if ((int)$s['id'] === $id) return (string)$s['name'];
  }
  return '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Timetable | Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #2563eb;
      --primary-dark: #1d4ed8;
      --bg: #f8fafc;
      --card-bg: #ffffff;
      --text-main: #1e293b;
      --text-muted: #64748b;
      --border: #e2e8f0;
      --danger: #ef4444;
      --success: #22c55e;
    }

    * { box-sizing: border-box; }

    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background: var(--bg);
      color: var(--text-main);
      margin: 0;
      display: flex;
      min-height: 100vh;
    }

    .sidebar {
      width: 260px;
      background: #0f172a;
      color: #fff;
      padding: 24px;
      display: flex;
      flex-direction: column;
    }

    .main-content {
      flex: 1;
      padding: 40px;
      overflow-x: hidden;
    }

    .card {
      background: var(--card-bg);
      border-radius: 12px;
      border: 1px solid var(--border);
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
      margin-bottom: 24px;
    }

    .card-header {
      padding: 20px 24px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .card-body { padding: 24px; }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
    }

    label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 6px;
      color: var(--text-main);
    }

    input, select {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 14px;
      box-sizing: border-box;
      background: #fff;
    }

    input:focus, select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .btn {
      cursor: pointer;
      padding: 10px 16px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 14px;
      border: none;
      transition: 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      white-space: nowrap;
    }

    .btn-primary {
      background: var(--primary);
      color: white;
    }

    .btn-primary:hover {
      background: var(--primary-dark);
    }

    .btn-outline {
      background: white;
      border: 1px solid var(--border);
      color: var(--text-main);
    }

    .btn-outline:hover {
      background: #f1f5f9;
    }

    .btn-danger {
      background: #fef2f2;
      color: var(--danger);
      border: 1px solid #fecaca;
    }

    .btn-danger:hover {
      background: var(--danger);
      color: white;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th {
      background: #f8fafc;
      text-align: left;
      padding: 14px 16px;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
      border-bottom: 2px solid var(--border);
    }

    td {
      padding: 16px;
      border-bottom: 1px solid var(--border);
      font-size: 14px;
      vertical-align: top;
    }

    tr:hover {
      background: #fcfcfd;
    }

    .pill {
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      display: inline-block;
    }

    .pill-active { background: #dcfce7; color: #166534; }
    .pill-inactive { background: #f1f5f9; color: #475569; }

    .alert {
      padding: 16px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-size: 14px;
      font-weight: 500;
    }

    .alert-success {
      background: #dcfce7;
      color: #166534;
      border-left: 4px solid var(--success);
    }

    .alert-error {
      background: #fee2e2;
      color: #991b1b;
      border-left: 4px solid var(--danger);
    }

    .alert-warn {
      background: #fef3c7;
      color: #92400e;
      border-left: 4px solid #f59e0b;
    }

    .pagination {
      display: flex;
      gap: 4px;
      margin-top: 20px;
    }

    .mini {
      font-size: 12px;
      color: var(--text-muted);
    }

    .filter-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
    }

    .filter-row > * {
      min-width: 180px;
      flex: 1;
    }

    .filter-row .btn {
      flex: 0 0 auto;
    }

    @media (max-width: 1100px) {
      body { flex-direction: column; }
      .sidebar { width: 100%; }
      .main-content { padding: 20px; }
    }

    @media (max-width: 900px) {
      .layout-two { grid-template-columns: 1fr !important; }
    }
  </style>
</head>
<body>

<aside class="sidebar">
  <div style="font-size: 20px; font-weight: 800; margin-bottom: 40px; display: flex; align-items: center; gap: 10px;">
    <i class="fa-solid fa-calendar-check" style="color: var(--primary);"></i> TIMETABLE
  </div>
  <nav>
    <a href="dashboard.php" class="btn btn-outline" style="width:100%; border:none; color:#cbd5e1; justify-content:flex-start; background:transparent;">
      <i class="fa-solid fa-house"></i> Dashboard
    </a>
  </nav>
</aside>

<main class="main-content">
  <header style="margin-bottom: 32px; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap;">
    <div>
      <h1 style="margin: 0 0 4px; font-size: 28px; font-weight: 800;">Assessment Sessions</h1>
      <p style="margin: 0; color: var(--text-muted);">Manage timetable schedules and import session data</p>
    </div>
    <div class="mini">
      Using Database Column:
      <code style="background:#e2e8f0; padding:2px 6px; border-radius:4px;"><?php echo h($seriesCol); ?></code>
    </div>
  </header>

  <?php if ($msg): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo h($msg); ?></div>
  <?php endif; ?>

  <?php if ($err): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> <?php echo h($err); ?></div>
  <?php endif; ?>

  <?php foreach ($warnings as $w): ?>
    <div class="alert alert-warn"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo h($w); ?></div>
  <?php endforeach; ?>

  <div class="layout-two" style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 24px; align-items: start;">

    <div>
      <section class="card">
        <div class="card-header">
          <h3 style="margin:0; font-size:16px;"><i class="fa-solid fa-filter"></i> View & Export</h3>
        </div>
        <div class="card-body">
          <label>Filter</label>

          <div class="filter-row">
            <select id="seriesFilter">
              <option value="0">-- All Series --</option>
              <?php foreach ($series as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo ($filter_series_id === (int)$s['id'] ? 'selected' : ''); ?>>
                  <?php echo h($s['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>

            <select id="occFilter">
              <option value="0">-- All Occupations --</option>
              <?php foreach ($occupations as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>" <?php echo ($filter_occ_id === (int)$o['id'] ? 'selected' : ''); ?>>
                  <?php echo h($o['code'] . ' — ' . $o['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>

            <select id="statusFilter">
              <option value="active" <?php echo ($filter_status === 'active' ? 'selected' : ''); ?>>Active Only</option>
              <option value="inactive" <?php echo ($filter_status === 'inactive' ? 'selected' : ''); ?>>Inactive Only</option>
              <option value="all" <?php echo ($filter_status === 'all' ? 'selected' : ''); ?>>All Sessions</option>
            </select>

            <a class="btn btn-outline" href="<?php
              $qs = $_GET;
              $qs['export'] = 'csv';
              echo h('?' . http_build_query($qs));
            ?>">
              <i class="fa-solid fa-download"></i> Export
            </a>
          </div>

          <script>
            const seriesFilter = document.getElementById('seriesFilter');
            const occFilter = document.getElementById('occFilter');
            const statusFilter = document.getElementById('statusFilter');

            function applyFilters() {
              const url = new URL(window.location.href);
              url.searchParams.set('series_id', seriesFilter.value);
              url.searchParams.set('occ_id', occFilter.value);
              url.searchParams.set('status', statusFilter.value);
              url.searchParams.set('page', '1');
              window.location.href = url.toString();
            }

            seriesFilter.addEventListener('change', applyFilters);
            occFilter.addEventListener('change', applyFilters);
            statusFilter.addEventListener('change', applyFilters);
          </script>
        </div>
      </section>

      <section class="card">
        <div class="card-header">
          <h3 style="margin:0; font-size:16px;"><i class="fa-solid fa-file-import"></i> Bulk Import</h3>
        </div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">

            <label>Series Context <span class="mini">(fallback)</span></label>
            <select name="import_series_id" style="margin-bottom:16px;">
              <option value="0">-- Extract from File --</option>
              <?php foreach ($series as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['name']); ?></option>
              <?php endforeach; ?>
            </select>

            <label>Choose File (CSV or XLSX)</label>
            <input
              type="file"
              name="csv_file"
              accept=".csv,.xlsx"
              required
              style="border-style: dashed; padding: 20px; text-align: center; background: #fafafa;"
            >

            <div class="mini" style="margin-top:12px; line-height:1.5;">
              <i class="fa-solid fa-circle-info"></i>
              Required headers:
              <i>center_number, occupation_code, session_date, start_time, end_time, candidate_count</i>
            </div>

            <button type="submit" name="import_csv" value="1" class="btn btn-primary" style="margin-top:20px; width:100%; justify-content:center;">
              Start Import Process
            </button>
          </form>
        </div>
      </section>
    </div>

    <section class="card">
      <div class="card-header">
        <h3 style="margin:0; font-size:16px;">
          <i class="fa-solid <?php echo $edit_id > 0 ? 'fa-pen-to-square' : 'fa-plus'; ?>"></i>
          <?php echo $edit_id > 0 ? 'Edit Session' : 'Add Session Manually'; ?>
        </h3>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <?php if ($edit_id > 0): ?>
            <input type="hidden" name="id" value="<?php echo (int)$edit_id; ?>">
          <?php endif; ?>

          <div class="grid">
            <div>
              <label>Examination Series</label>
              <?php if ($edit_id > 0 && $editRow): ?>
                <input type="text" value="<?php echo h(seriesNameById($series, (int)$editRow['series_id'])); ?>" readonly>
              <?php else: ?>
                <select name="series_id" required>
                  <option value="0">-- Select --</option>
                  <?php foreach ($series as $s): ?>
                    <option value="<?php echo (int)$s['id']; ?>" <?php echo ($form_series_id === (int)$s['id'] ? 'selected' : ''); ?>>
                      <?php echo h($s['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>

            <div>
              <label>Center Number</label>
              <input
                type="text"
                name="center_number"
                placeholder="e.g. UVT883"
                value="<?php echo ($edit_id > 0 && $editRow) ? h($editRow['center_number']) : ''; ?>"
                <?php echo ($edit_id > 0) ? 'readonly' : 'required'; ?>
              >
            </div>
          </div>

          <div class="grid" style="margin-top:16px;">
            <div>
              <label>Occupation Code</label>
              <input
                type="text"
                name="occupation_code"
                placeholder="e.g. BD"
                required
                value="<?php echo ($edit_id > 0 && $editRow) ? h($editRow['occ_code']) : ''; ?>"
              >
            </div>

            <div>
              <label>Date</label>
              <input
                type="date"
                name="session_date"
                required
                value="<?php echo ($edit_id > 0 && $editRow) ? h($editRow['session_date']) : ''; ?>"
              >
            </div>
          </div>

          <div class="grid" style="margin-top:16px;">
            <div>
              <label>Start Time</label>
              <input
                type="time"
                name="start_time"
                required
                value="<?php echo ($edit_id > 0 && $editRow) ? h(substr((string)$editRow['start_time'], 0, 5)) : ''; ?>"
              >
            </div>

            <div>
              <label>End Time</label>
              <input
                type="time"
                name="end_time"
                required
                value="<?php echo ($edit_id > 0 && $editRow) ? h(substr((string)$editRow['end_time'], 0, 5)) : ''; ?>"
              >
            </div>
          </div>

          <div class="grid" style="margin-top:16px;">
            <div>
              <label>Candidates</label>
              <input
                type="number"
                name="candidate_count"
                min="0"
                required
                value="<?php echo ($edit_id > 0 && $editRow) ? (int)$editRow['candidate_count'] : 0; ?>"
              >
            </div>

            <div>
              <label>Status</label>
              <select name="status">
                <option value="active" <?php echo ($edit_id > 0 && $editRow && $editRow['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo ($edit_id > 0 && $editRow && $editRow['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
              </select>
            </div>
          </div>

          <div style="display:flex; gap:10px; margin-top:24px; align-items:center; flex-wrap:wrap;">
            <button
              name="<?php echo $edit_id > 0 ? 'update' : 'add'; ?>"
              value="1"
              type="submit"
              class="btn btn-primary"
            >
              <?php if ($edit_id > 0): ?>
                <i class="fa-solid fa-save"></i> Update Session
              <?php else: ?>
                Create Session Schedule
              <?php endif; ?>
            </button>

            <?php if ($edit_id > 0): ?>
              <a class="btn btn-outline" href="<?php
                $qs = $_GET;
                unset($qs['edit_id']);
                echo h('?' . http_build_query($qs));
              ?>">
                <i class="fa-solid fa-xmark"></i> Cancel
              </a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>
  </div>

  <section class="card">
    <div class="card-header">
      <h3 style="margin:0; font-size:16px;">
        Existing Schedules
        <span class="mini" style="font-weight:normal; margin-left:8px;">
          Showing <?php echo count($rows); ?> of <?php echo $totalRows; ?> sessions
        </span>
      </h3>

      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <?php
          $activeQS = $_GET;
          $activeQS['status'] = 'active';
          $activeQS['page'] = 1;

          $inactiveQS = $_GET;
          $inactiveQS['status'] = 'inactive';
          $inactiveQS['page'] = 1;

          $allQS = $_GET;
          $allQS['status'] = 'all';
          $allQS['page'] = 1;
        ?>

        <a class="btn btn-outline" href="<?php echo h('?' . http_build_query($activeQS)); ?>">
          <i class="fa-solid fa-circle-check"></i> Active
        </a>

        <a class="btn btn-outline" href="<?php echo h('?' . http_build_query($inactiveQS)); ?>">
          <i class="fa-solid fa-ban"></i> Deactivated
        </a>

        <a class="btn btn-outline" href="<?php echo h('?' . http_build_query($allQS)); ?>">
          <i class="fa-solid fa-list"></i> All
        </a>

        <div class="pagination" style="margin-top:0;">
          <?php if ($page > 1): ?>
            <a class="btn btn-outline" style="padding: 5px 10px;" href="<?php echo h(buildPageUrl($page - 1)); ?>">
              <i class="fa-solid fa-chevron-left"></i>
            </a>
          <?php endif; ?>

          <span class="btn btn-outline" style="padding: 5px 12px; pointer-events:none;">
            Page <?php echo $page; ?> / <?php echo $totalPages; ?>
          </span>

          <?php if ($page < $totalPages): ?>
            <a class="btn btn-outline" style="padding: 5px 10px;" href="<?php echo h(buildPageUrl($page + 1)); ?>">
              <i class="fa-solid fa-chevron-right"></i>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div style="overflow-x: auto;">
      <table>
        <thead>
          <tr>
            <th>Series</th>
            <th>Center Details</th>
            <th>Date & Time</th>
            <th>Occupation</th>
            <th>Cands.</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $s): ?>
            <tr>
              <td style="font-weight:600;"><?php echo h($s['exam_series_name']); ?></td>
              <td>
                <div style="font-weight:700; color:var(--primary);"><?php echo h($s['center_number']); ?></div>
                <div class="mini"><?php echo h($s['center_name']); ?></div>
              </td>
              <td>
                <div style="font-weight:600;"><?php echo h($s['session_date']); ?></div>
                <div class="mini">
                  <?php echo h(date("H:i", strtotime($s['start_time']))); ?> -
                  <?php echo h(date("H:i", strtotime($s['end_time']))); ?>
                </div>
              </td>
              <td>
                <code><?php echo h($s['occ_code']); ?></code>
                <span class="mini">— <?php echo h($s['occ_name']); ?></span>
              </td>
              <td><?php echo (int)$s['candidate_count']; ?></td>
              <td>
                <span class="pill <?php echo ($s['status'] === 'active' ? 'pill-active' : 'pill-inactive'); ?>">
                  <?php echo h($s['status']); ?>
                </span>
              </td>
              <td style="text-align:right;">
                <div style="display:inline-flex; gap:8px; align-items:center; flex-wrap:wrap;">
                  <a
                    class="btn btn-outline"
                    style="padding: 6px 10px;"
                    href="<?php
                      $qs = $_GET;
                      $qs['edit_id'] = (int)$s['id'];
                      echo h('?' . http_build_query($qs));
                    ?>"
                    title="Edit"
                  >
                    <i class="fa-solid fa-pen-to-square"></i>
                  </a>

                  <form
                    method="post"
                    onsubmit="return confirm('<?php echo $s['status'] === 'active' ? 'Deactivate this session?' : 'Activate this session?'; ?>');"
                    style="display:inline;"
                  >
                    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                    <input type="hidden" name="new_status" value="<?php echo $s['status'] === 'active' ? 'inactive' : 'active'; ?>">

                    <button
                      class="btn <?php echo $s['status'] === 'active' ? 'btn-danger' : 'btn-outline'; ?>"
                      style="padding: 6px 10px;"
                      name="toggle_status"
                      value="1"
                      type="submit"
                      title="<?php echo $s['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>"
                    >
                      <i class="fa-solid <?php echo $s['status'] === 'active' ? 'fa-ban' : 'fa-rotate-left'; ?>"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="7" style="text-align:center; padding:40px; color:var(--text-muted);">
                No sessions found for this filter.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

</body>
</html>
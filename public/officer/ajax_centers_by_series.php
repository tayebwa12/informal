<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
$officerId = (int)($me['id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');

function out(bool $ok, array $items = [], string $error = ''): void {
  echo json_encode([
    'ok' => $ok,
    'items' => $items,
    'error' => $error,
  ]);
  exit;
}

$seriesId = (int)($_GET['exam_series_id'] ?? 0);
if ($seriesId <= 0) out(false, [], 'Missing exam_series_id');

/* ---------------- officer region ---------------- */
$regionId = 0;
try {
  $st = $pdo->prepare("
    SELECT oa.region_id
    FROM officer_assignments oa
    WHERE oa.user_id=? AND oa.status='active'
    ORDER BY oa.id DESC
    LIMIT 1
  ");
  $st->execute([$officerId]);
  $regionId = (int)$st->fetchColumn();
} catch (Throwable $e) {
  out(false, [], 'Officer assignment lookup failed: ' . $e->getMessage());
}

if ($regionId <= 0) out(false, [], 'No active officer assignment');

/* ---------------- detect timetable table + columns ----------------
   We try common table names and column variants automatically.
   Priority:
   - exam_timetable
   - timetable_sessions
   - exam_sessions
   - sessions
*/
$possibleTables = ['exam_timetable', 'timetable_sessions', 'exam_sessions', 'sessions'];
$table = null;

foreach ($possibleTables as $t) {
  try {
    $chk = $pdo->prepare("SHOW TABLES LIKE ?");
    $chk->execute([$t]);
    if ($chk->fetchColumn()) { $table = $t; break; }
  } catch (Throwable $e) {}
}

if (!$table) {
  out(false, [], "No sessions table found. Expected one of: " . implode(', ', $possibleTables));
}

/* columns discovery */
$cols = [];
try {
  $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as $r) {
    $f = (string)($r['Field'] ?? '');
    if ($f !== '') $cols[$f] = true;
  }
} catch (Throwable $e) {
  out(false, [], "Failed to read columns for $table: " . $e->getMessage());
}

/* series column candidates */
$seriesCol = null;
foreach (['exam_series_id', 'series_id', 'exam_series'] as $c) {
  if (isset($cols[$c])) { $seriesCol = $c; break; }
}
if (!$seriesCol) {
  out(false, [], "Table `$table` has no exam series column. Expected: exam_series_id / series_id / exam_series");
}

/* center column candidates */
$centerJoinMode = null; // 'id' or 'number'
$centerCol = null;

foreach (['center_id'] as $c) {
  if (isset($cols[$c])) { $centerCol = $c; $centerJoinMode = 'id'; break; }
}
if (!$centerCol) {
  foreach (['center_number', 'centre_number', 'center_no', 'centre_no'] as $c) {
    if (isset($cols[$c])) { $centerCol = $c; $centerJoinMode = 'number'; break; }
  }
}
if (!$centerCol) {
  out(false, [], "Table `$table` has no center reference column. Expected: center_id OR center_number/centre_number");
}

/* ---------------- build query (region + has sessions in series) ---------------- */
try {
  if ($centerJoinMode === 'id') {
    $sql = "
      SELECT DISTINCT c.id, c.center_number, c.center_name
      FROM `$table` t
      JOIN centers c   ON c.id = t.`$centerCol`
      JOIN districts d ON d.id = c.district_id
      WHERE t.`$seriesCol` = ?
        AND d.region_id = ?
      ORDER BY c.center_number ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$seriesId, $regionId]);
  } else {
    // sessions table stores a center number; join on centers.center_number
    $sql = "
      SELECT DISTINCT c.id, c.center_number, c.center_name
      FROM `$table` t
      JOIN centers c   ON c.center_number = t.`$centerCol`
      JOIN districts d ON d.id = c.district_id
      WHERE t.`$seriesCol` = ?
        AND d.region_id = ?
      ORDER BY c.center_number ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$seriesId, $regionId]);
  }

  $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  out(true, $items, '');

} catch (Throwable $e) {
  out(false, [], "Query failed on table `$table`: " . $e->getMessage());
}

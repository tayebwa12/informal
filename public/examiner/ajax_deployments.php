<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_examiner.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_examiner();
$userId = (int)($me['id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function json_out(array $data): void {
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

$mode = (string)($_GET['mode'] ?? 'summary'); // summary | list
$since = (int)($_GET['since'] ?? 0);          // last known deployment "version"

/**
 * A cheap "version" number:
 * - if you have deployments.updated_at, use MAX(UNIX_TIMESTAMP(updated_at))
 * - else, use MAX(id) as fallback
 */
try {
  // Prefer updated_at if it exists. If not, fallback to MAX(id).
  // If your table DOES NOT have updated_at, keep MAX(id) only.
  $st = $pdo->prepare("SELECT MAX(id) AS v FROM deployments WHERE examiner_user_id=?");
  $st->execute([$userId]);
  $version = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $version = 0;
}

if ($mode === 'summary') {
  // Fast stats (single query)
  try {
    $st = $pdo->prepare("
      SELECT
        COUNT(*) AS total_all,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed_all,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_all
      FROM deployments
      WHERE examiner_user_id = ?
        AND status <> 'cancelled'
    ");
    $st->execute([$userId]);
    $stats = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $stats = ['total_all'=>0,'completed_all'=>0,'active_all'=>0];
  }

  json_out([
    'ok' => true,
    'version' => $version,
    'changed' => ($version !== $since),
    'stats' => [
      'total_all' => (int)($stats['total_all'] ?? 0),
      'completed_all' => (int)($stats['completed_all'] ?? 0),
      'active_all' => (int)($stats['active_all'] ?? 0),
    ],
  ]);
}

// mode=list => return deployments table HTML data
try {
  $st = $pdo->prepare("
    SELECT
      d.id AS deployment_id,
      d.status AS deploy_status,
      ts.session_date,
      ts.start_time,
      ts.end_time,
      COALESCE(es.name,'') AS series_name,
      c.center_number,
      c.center_name,
      dist.name AS district_name,
      reg.name AS region_name,
      o.code AS occupation_code,
      o.name AS occupation_name,
      depby.full_name AS deployed_by_name
    FROM deployments d
    JOIN timetable_sessions ts ON ts.id = d.timetable_session_id
    LEFT JOIN exam_series es ON es.id = ts.exam_series
    LEFT JOIN centers c ON c.id = ts.center_id
    LEFT JOIN districts dist ON dist.id = c.district_id
    LEFT JOIN regions reg ON reg.id = dist.region_id
    LEFT JOIN occupations o ON o.id = ts.occupation_id
    LEFT JOIN users depby ON depby.id = d.deployed_by_user_id
    WHERE d.examiner_user_id = ?
      AND d.status <> 'cancelled'
    ORDER BY ts.session_date ASC, ts.start_time ASC
    LIMIT 400
  ");
  $st->execute([$userId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>'Failed to load deployments']);
}

// Build tiny HTML table body rows (fast client update)
$tbody = '';
foreach ($rows as $d) {
  $date = htmlspecialchars((string)($d['session_date'] ?? '—'), ENT_QUOTES, 'UTF-8');
  $time = htmlspecialchars(trim((string)($d['start_time'] ?? '—') . ' - ' . (string)($d['end_time'] ?? '—')), ENT_QUOTES, 'UTF-8');
  $series = htmlspecialchars((string)($d['series_name'] ?? ''), ENT_QUOTES, 'UTF-8');

  $centre = trim((string)($d['center_number'] ?? '') . ' — ' . (string)($d['center_name'] ?? ''));
  $centre = htmlspecialchars($centre, ENT_QUOTES, 'UTF-8');

  $loc = trim((string)($d['district_name'] ?? '') . ((string)($d['region_name'] ?? '') ? ' / ' . (string)$d['region_name'] : ''));
  $loc = htmlspecialchars($loc, ENT_QUOTES, 'UTF-8');

  $paper = trim((string)($d['occupation_code'] ?? '') . ' — ' . (string)($d['occupation_name'] ?? ''));
  $paper = htmlspecialchars($paper, ENT_QUOTES, 'UTF-8');

  $by = htmlspecialchars((string)($d['deployed_by_name'] ?? '—'), ENT_QUOTES, 'UTF-8');

  $status = strtoupper((string)($d['deploy_status'] ?? 'active'));
  $statusSafe = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
  $cls = 'pill active';
  if (strtolower($status) === 'completed') $cls = 'pill completed';
  if (strtolower($status) === 'cancelled') $cls = 'pill cancelled';

  $tbody .= "<tr>
    <td>{$date}</td>
    <td>{$time}</td>
    <td>{$series}</td>
    <td><div><b>{$centre}</b></div><div class=\"muted\">{$loc}</div></td>
    <td>{$paper}</td>
    <td>{$by}</td>
    <td><span class=\"{$cls}\">{$statusSafe}</span></td>
  </tr>";
}

json_out([
  'ok' => true,
  'version' => $version,
  'tbody' => $tbody,
  'count' => count($rows),
]);

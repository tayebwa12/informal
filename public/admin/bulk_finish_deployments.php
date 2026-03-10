<?php
declare(strict_types=1);

// ✅ Admin page: Bulk mark deployed examiners as "completed" (sets deployments.completed_at)
// OPTIONAL: also bulk set timetable_sessions.deployment_finished = 1

ini_set('display_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

require_admin();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['csrf_token'];

function csrf_check(string $tokenFromPost): void {
  $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
  if ($sessionToken === '' || !hash_equals($sessionToken, $tokenFromPost)) {
    http_response_code(403);
    exit("Invalid CSRF token");
  }
}

function inPlaceholders(int $n): string {
  return implode(',', array_fill(0, max(1, $n), '?'));
}

$msg = $err = null;

/* ---------------- Filters ----------------
   You may store exam_series as:
   - timetable_sessions.exam_series (string)
   OR
   - timetable_sessions.exam_series_id (int) joined to exam_series table.
   Your current code uses ts.exam_series as string.
*/
$series = trim((string)($_GET['series'] ?? ''));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo   = trim((string)($_GET['to'] ?? ''));

if ($dateFrom === '') $dateFrom = date('Y-m-d');
if ($dateTo === '')   $dateTo   = date('Y-m-d');

/* ---------------- POST: bulk complete ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check((string)($_POST['csrf'] ?? ''));

  $action = (string)($_POST['action'] ?? '');
  $sessionIds = $_POST['session_ids'] ?? [];
  $alsoFinishSessions = (int)($_POST['also_finish_sessions'] ?? 0);

  if (!is_array($sessionIds) || !$sessionIds) {
    $err = "❌ Select at least one session.";
  } else {
    $ids = array_values(array_unique(array_filter(array_map('intval', $sessionIds))));
    if (!$ids) {
      $err = "❌ Invalid session selection.";
    } else {
      try {
        $pdo->beginTransaction();

        if ($action === 'bulk_complete_deployments') {
          // ✅ Mark deployments as completed ONLY for "active" deployments
          // (status not cancelled, response_status not cancelled, completed_at is NULL)
          $ph = inPlaceholders(count($ids));

          $sql = "
            UPDATE deployments d
            SET
              d.completed_at = NOW(),
              d.response_status = CASE
                WHEN COALESCE(NULLIF(d.response_status,''),'') = '' THEN 'completed'
                ELSE d.response_status
              END
            WHERE d.timetable_session_id IN ($ph)
              AND d.status <> 'cancelled'
              AND COALESCE(d.response_status,'') <> 'cancelled'
              AND d.completed_at IS NULL
          ";
          $st = $pdo->prepare($sql);
          $st->execute($ids);
          $affected = $st->rowCount();

          // Optional: also set timetable_sessions.deployment_finished=1
          if ($alsoFinishSessions === 1) {
            $sql2 = "UPDATE timetable_sessions SET deployment_finished=1 WHERE id IN ($ph) LIMIT 100000";
            $st2 = $pdo->prepare($sql2);
            $st2->execute($ids);
          }

          $pdo->commit();
          $msg = "✅ Done. Marked {$affected} deployment(s) as completed." . ($alsoFinishSessions ? " Sessions also set to deployment_finished=1." : "");
        } else {
          $pdo->rollBack();
          $err = "❌ Unknown action.";
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = "❌ " . $e->getMessage();
      }
    }
  }

  // Redirect back (keeps filters)
  $qs = http_build_query([
    'series' => $series,
    'from'   => $dateFrom,
    'to'     => $dateTo
  ]);
  header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?" . $qs . ($msg ? "&msg=" . urlencode($msg) : "") . ($err ? "&err=" . urlencode($err) : ""));
  exit;
}

/* ---------------- messages from redirect ---------------- */
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
if (isset($_GET['err'])) $err = (string)$_GET['err'];

/* ---------------- Load series list (distinct strings) ---------------- */
$seriesList = [];
try {
  $seriesList = $pdo->query("
    SELECT DISTINCT TRIM(exam_series) AS series
    FROM timetable_sessions
    WHERE exam_series IS NOT NULL AND TRIM(exam_series) <> ''
    ORDER BY series ASC
  ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $seriesList = []; }

/* ---------------- Fetch sessions for table ---------------- */
$params = [];
$where = " WHERE 1=1 ";

if ($series !== '') {
  $where .= " AND ts.exam_series = ? ";
  $params[] = $series;
}

if ($dateFrom !== '' && $dateTo !== '') {
  $where .= " AND ts.session_date BETWEEN ? AND ? ";
  $params[] = $dateFrom;
  $params[] = $dateTo;
}

$sql = "
  SELECT
    ts.id,
    ts.exam_series,
    ts.session_date,
    ts.start_time,
    ts.deployment_finished,
    ts.candidate_count,
    c.center_number,
    c.center_name,
    o.name AS occupation_name,

    /* Active deployed count for THIS session */
    (
      SELECT COUNT(*)
      FROM deployments d
      WHERE d.timetable_session_id = ts.id
        AND d.status <> 'cancelled'
        AND COALESCE(d.response_status,'') <> 'cancelled'
        AND d.completed_at IS NULL
    ) AS active_deployed,

    /* Completed deployments count for THIS session */
    (
      SELECT COUNT(*)
      FROM deployments d
      WHERE d.timetable_session_id = ts.id
        AND d.status <> 'cancelled'
        AND COALESCE(d.response_status,'') <> 'cancelled'
        AND d.completed_at IS NOT NULL
    ) AS completed_deployed
  FROM timetable_sessions ts
  JOIN centers c ON c.id = ts.center_id
  LEFT JOIN occupations o ON o.id = ts.occupation_id
  $where
  ORDER BY ts.session_date DESC, ts.start_time DESC, c.center_number ASC
  LIMIT 500
";
$st = $pdo->prepare($sql);
$st->execute($params);
$sessions = $st->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin • Bulk Finish Deployments</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{--p:#2563eb;--bg:#f8fafc;--bd:#e2e8f0;--tx:#0f172a;--mut:#64748b;--ok:#10b981;--bad:#ef4444;}
    body{font-family:system-ui;background:var(--bg);margin:0;color:var(--tx);}
    .wrap{max-width:1200px;margin:24px auto;padding:0 16px;}
    .top{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;}
    .card{background:#fff;border:1px solid var(--bd);border-radius:16px;box-shadow:0 6px 18px rgba(0,0,0,.05);overflow:hidden;}
    .hdr{padding:14px 16px;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid var(--bd);background:#fff;color:var(--tx);font-weight:800;text-decoration:none;cursor:pointer;}
    .btnp{background:var(--p);border-color:var(--p);color:#fff;}
    .btng{background:var(--ok);border-color:var(--ok);color:#fff;}
    .muted{color:var(--mut);font-size:13px;}
    .msg{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px 14px;border-radius:14px;font-weight:800;margin:10px 0;}
    .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:14px;font-weight:800;margin:10px 0;}
    form.filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0;}
    select,input{padding:10px 12px;border:1px solid var(--bd);border-radius:12px;font-weight:700;}
    table{width:100%;border-collapse:collapse;}
    th,td{padding:12px 12px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:middle;font-size:14px;}
    th{background:#f8fafc;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.06em;}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:900;font-size:12px;background:#f1f5f9;border:1px solid var(--bd);}
    .pill.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46;}
    .pill.bad{background:#fef2f2;border-color:#fecaca;color:#991b1b;}
    .actions{padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
    .checkline{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .small{font-size:12px;color:var(--mut);}
  </style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <div>
      <h2 style="margin:0;">Bulk Finish Deployments</h2>
      <div class="muted">Marks deployed examiners as completed (sets <b>deployments.completed_at</b>). Optionally also sets <b>timetable_sessions.deployment_finished=1</b>.</div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn" href="dashboard.php">← Back</a>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <div class="hdr">
      <div style="font-weight:900;">Filter sessions</div>
      <form class="filters" method="GET">
        <select name="series">
          <option value="">All series</option>
          <?php foreach ($seriesList as $s): ?>
            <option value="<?= h($s) ?>" <?= ($series === $s ? 'selected' : '') ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= h($dateFrom) ?>">
        <input type="date" name="to" value="<?= h($dateTo) ?>">
        <button class="btn btnp" type="submit">Apply</button>
      </form>
    </div>

    <form method="POST" onsubmit="return confirm('Mark selected session deployments as COMPLETED?');">
      <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="action" value="bulk_complete_deployments">

      <div class="actions">
        <div class="checkline">
          <button class="btn" type="button" onclick="toggleAll(true)">Select all</button>
          <button class="btn" type="button" onclick="toggleAll(false)">Clear</button>
          <button class="btn" type="button" onclick="selectFinishedOnly()">Select only deployment_finished=1</button>
          <button class="btn" type="button" onclick="selectPastOnly()">Select only past sessions</button>
        </div>

        <div class="checkline">
          <label class="small" style="display:flex;gap:8px;align-items:center;">
            <input type="checkbox" name="also_finish_sessions" value="1">
            Also set timetable_sessions.deployment_finished = 1
          </label>
          <button class="btn btng" type="submit">✅ Bulk Complete Selected</button>
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th style="width:50px;">Pick</th>
            <th>Series</th>
            <th>Date</th>
            <th>Time</th>
            <th>Center</th>
            <th>Occupation</th>
            <th>Active deployed</th>
            <th>Completed</th>
            <th>Deployment finished</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$sessions): ?>
          <tr><td colspan="9" class="muted" style="text-align:center;padding:22px;">No sessions found for the selected filter.</td></tr>
        <?php endif; ?>

        <?php foreach ($sessions as $row): ?>
          <?php
            $active = (int)$row['active_deployed'];
            $finished = (int)$row['deployment_finished'];
            $isPast = false;
            try {
              $isPast = strtotime((string)$row['session_date'].' '.substr((string)$row['start_time'],0,5)) < time();
            } catch (Throwable $e) { $isPast = false; }
          ?>
          <tr data-finished="<?= $finished ?>" data-past="<?= $isPast ? 1 : 0 ?>">
            <td>
              <input type="checkbox" name="session_ids[]" value="<?= (int)$row['id'] ?>">
            </td>
            <td><?= h($row['exam_series'] ?? '') ?></td>
            <td><?= h($row['session_date'] ?? '') ?></td>
            <td><?= h(substr((string)($row['start_time'] ?? ''),0,5)) ?></td>
            <td>
              <b><?= h($row['center_number'] ?? '') ?></b><br>
              <span class="muted"><?= h($row['center_name'] ?? '') ?></span>
            </td>
            <td><?= h($row['occupation_name'] ?? 'N/A') ?></td>
            <td>
              <?php if ($active > 0): ?>
                <span class="pill ok"><?= $active ?></span>
              <?php else: ?>
                <span class="pill"><?= $active ?></span>
              <?php endif; ?>
            </td>
            <td><span class="pill"><?= (int)$row['completed_deployed'] ?></span></td>
            <td>
              <?php if ($finished === 1): ?>
                <span class="pill ok">YES</span>
              <?php else: ?>
                <span class="pill bad">NO</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="actions">
        <div class="muted">
          ✅ “Active deployed” = deployments where <b>completed_at IS NULL</b> and not cancelled. Bulk complete will set them completed.
        </div>
        <button class="btn btng" type="submit">✅ Bulk Complete Selected</button>
      </div>
    </form>
  </div>
</div>

<script>
  function toggleAll(state){
    document.querySelectorAll('input[type="checkbox"][name="session_ids[]"]').forEach(cb => cb.checked = state);
  }
  function selectFinishedOnly(){
    document.querySelectorAll('tbody tr').forEach(tr => {
      const cb = tr.querySelector('input[type="checkbox"][name="session_ids[]"]');
      if (!cb) return;
      cb.checked = (tr.getAttribute('data-finished') === '1');
    });
  }
  function selectPastOnly(){
    document.querySelectorAll('tbody tr').forEach(tr => {
      const cb = tr.querySelector('input[type="checkbox"][name="session_ids[]"]');
      if (!cb) return;
      cb.checked = (tr.getAttribute('data-past') === '1');
    });
  }
</script>

</body>
</html>
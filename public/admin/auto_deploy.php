<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';
require_once __DIR__ . '/../../app/services/auto_deploy_service.php';

require_admin();

$msg = $err = null;
$results = [];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tableExists(PDO $pdo, string $table): bool {
  try {
    $table = str_replace(['`', '%', '_'], ['', '\%', '\_'], $table);
    $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

if (!tableExists($pdo, 'deployments')) {
  $err = "❌ Missing table: deployments (create it first).";
}
if (!tableExists($pdo, 'timetable_sessions')) {
  $err = ($err ? $err . " " : "") . "❌ Missing table: timetable_sessions.";
}

$series = [];
try {
  $series = $pdo->query("SELECT id, name, status FROM exam_series ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $series = []; }

$regions = [];
try {
  $regions = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $regions = []; }

/* ----------------- POST ACTIONS ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
  csrf_validate($_POST['csrf'] ?? null);

  $ratio = (int)($_POST['ratio'] ?? 20);
  if ($ratio < 1) $ratio = 20;

  // Deploy a single session
  if (isset($_POST['deploy_one'])) {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    if ($sessionId < 1) {
      $err = "❌ Invalid session id.";
    } else {
      $res = auto_deploy_for_session($pdo, $sessionId, $ratio);
      if (!($res['ok'] ?? false)) $err = "❌ " . ($res['message'] ?? 'Failed.');
      else $msg = "✅ " . ($res['message'] ?? 'Done.');
      $results[] = ['session_id' => $sessionId, 'message' => ($res['message'] ?? '')];
    }
  }

  // Deploy all sessions in a series (optionally for one region)
  if (isset($_POST['deploy_series'])) {
    $seriesId = (int)($_POST['exam_series_id'] ?? 0);
    $regionId = (int)($_POST['region_id'] ?? 0); // optional

    if ($seriesId < 1) {
      $err = "❌ Please select exam series.";
    } else {
      try {
        $sql = "
          SELECT ts.id
          FROM timetable_sessions ts
          JOIN centers c ON c.id = ts.center_id
          JOIN districts d ON d.id = c.district_id
          WHERE ts.exam_series_id = ?
            AND COALESCE(ts.status,'active') = 'active'
        ";
        $params = [$seriesId];

        if ($regionId > 0) {
          $sql .= " AND d.region_id = ? ";
          $params[] = $regionId;
        }

        $sql .= " ORDER BY ts.session_date ASC, ts.start_time ASC, ts.id ASC ";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $sessionIds = $st->fetchAll(PDO::FETCH_COLUMN);

        if (!$sessionIds) {
          $msg = "✅ No sessions found for that series/region.";
        } else {
          $addedTotal = 0;
          $okCount = 0;
          $failCount = 0;

          foreach ($sessionIds as $sid) {
            $sid = (int)$sid;
            $res = auto_deploy_for_session($pdo, $sid, $ratio);

            if (($res['ok'] ?? false) === true) {
              $okCount++;
              $addedTotal += (int)($res['added'] ?? 0);
            } else {
              $failCount++;
            }

            $results[] = [
              'session_id' => $sid,
              'message' => (string)($res['message'] ?? '')
            ];
          }

          $msg = "✅ Auto-deploy finished. Sessions: {$okCount} ok, {$failCount} failed. Total examiners added: {$addedTotal}.";
        }

      } catch (Throwable $e) {
        $err = "❌ " . $e->getMessage();
      }
    }
  }
}

/* ----------------- SESSION LIST FOR QUICK TEST ----------------- */
$recentSessions = [];
try {
  $recentSessions = $pdo->query("
    SELECT ts.id, ts.session_date, ts.start_time, ts.end_time, ts.candidate_count,
           c.center_number, c.center_name
    FROM timetable_sessions ts
    JOIN centers c ON c.id = ts.center_id
    WHERE COALESCE(ts.status,'active')='active'
    ORDER BY ts.id DESC
    LIMIT 30
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentSessions = []; }
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Auto Deploy Examiners</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui;background:#f6f7fb;margin:0;padding:18px}
    .card{background:#fff;border-radius:14px;padding:14px;box-shadow:0 8px 24px rgba(0,0,0,.06);margin-bottom:12px}
    .msg{color:green;font-weight:900}
    .err{color:#b00020;font-weight:900}
    label{font-weight:900;display:block;margin:10px 0 6px}
    select,input{padding:10px;border:1px solid #ddd;border-radius:10px;width:100%}
    button,a.btn{display:inline-block;padding:10px 12px;border-radius:10px;border:0;background:#1b5cff;color:#fff;text-decoration:none;font-weight:900;cursor:pointer}
    .btn2{background:#fff;color:#1b5cff;border:2px solid #1b5cff}
    table{width:100%;border-collapse:collapse;background:#fff}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;font-size:14px;vertical-align:top}
    th{background:#f2f5ff}
    .muted{color:#666;font-size:13px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:900px){.row{grid-template-columns:1fr}}
  </style>
</head>
<body>

<h2>Auto Deploy Examiners</h2>
<p><a class="btn btn2" href="dashboard.php">← Back</a></p>

<?php if ($msg) echo "<p class='msg'>".h($msg)."</p>"; ?>
<?php if ($err) echo "<p class='err'>".h($err)."</p>"; ?>

<div class="row">

  <div class="card">
    <h3 style="margin:0 0 10px;">Deploy All Sessions (by Series)</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">

      <label>Exam Series</label>
      <select name="exam_series_id" required>
        <option value="">Select series…</option>
        <?php foreach ($series as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>">
            <?php echo h($s['name']); ?> (<?php echo h($s['status']); ?>)
          </option>
        <?php endforeach; ?>
      </select>

      <label>Region (optional)</label>
      <select name="region_id">
        <option value="0">All Regions</option>
        <?php foreach ($regions as $r): ?>
          <option value="<?php echo (int)$r['id']; ?>"><?php echo h($r['name']); ?></option>
        <?php endforeach; ?>
      </select>

      <label>Ratio (Candidates per Examiner)</label>
      <input type="number" name="ratio" value="20" min="1">

      <div style="margin-top:12px;">
        <button name="deploy_series" value="1" type="submit"
          onclick="return confirm('Auto-deploy for ALL sessions in this series?');">
          Auto Deploy (Series)
        </button>
      </div>
      <p class="muted" style="margin:10px 0 0;">
        Needed examiners = <b>ceil(candidates / ratio)</b>
      </p>
    </form>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px;">Deploy One Session (quick test)</h3>

    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">

      <label>Select a Session</label>
      <select name="session_id" required>
        <option value="">Select session…</option>
        <?php foreach ($recentSessions as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>">
            #<?php echo (int)$s['id']; ?> —
            <?php echo h($s['center_number']); ?> —
            <?php echo h($s['session_date']); ?> <?php echo h(substr((string)$s['start_time'],0,5)); ?>
            (<?php echo (int)$s['candidate_count']; ?> candidates)
          </option>
        <?php endforeach; ?>
      </select>

      <label>Ratio (Candidates per Examiner)</label>
      <input type="number" name="ratio" value="20" min="1">

      <div style="margin-top:12px;">
        <button name="deploy_one" value="1" type="submit">Deploy Session</button>
      </div>
    </form>

    <p class="muted" style="margin:10px 0 0;">
      Use this to confirm the system assigns correctly before deploying the whole series.
    </p>
  </div>

</div>

<?php if ($results): ?>
  <div class="card">
    <h3 style="margin:0 0 10px;">Deployment Results</h3>
    <table>
      <tr><th>Session ID</th><th>Result</th></tr>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?php echo (int)$r['session_id']; ?></td>
          <td class="muted"><?php echo h($r['message']); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

</body>
</html>

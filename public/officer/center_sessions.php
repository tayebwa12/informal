<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
$officerId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$centerId = (int)($_GET['center_id'] ?? 0);
$msg = $err = null;

/* ---------- helper: check if a column exists (so page won't crash) ---------- */
function col_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

$hasCompletedAt = col_exists($pdo, 'deployments', 'completed_at');
$hasCompletedBy = col_exists($pdo, 'deployments', 'completed_by_user_id');

/* ---------------- OFFICER ASSIGNMENT (series + region) ---------------- */
$assignment = null;
try {
  $st = $pdo->prepare("
    SELECT
      oa.exam_series_id,
      oa.region_id,
      oa.status AS assignment_status,
      es.name AS series_name,
      es.status AS series_status,
      r.name AS region_name
    FROM officer_assignments oa
    JOIN exam_series es ON es.id = oa.exam_series_id
    JOIN regions r ON r.id = oa.region_id
    WHERE oa.user_id = ?
      AND oa.status = 'active'
    ORDER BY oa.id DESC
    LIMIT 1
  ");
  $st->execute([$officerId]);
  $assignment = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $assignment = null;
}

if (!$assignment) {
  http_response_code(403);
  exit("No active assignment found. Ask admin to assign you a Series + Region.");
}

if ($centerId <= 0) {
  http_response_code(400);
  exit("Missing center_id");
}

/* ---------------- Validate center belongs to officer region ---------------- */
$center = null;
try {
  $st = $pdo->prepare("
    SELECT
      c.id, c.center_number, c.center_name, c.location_name,
      d.name AS district_name,
      r.id AS region_id, r.name AS region_name
    FROM centers c
    JOIN districts d ON d.id = c.district_id
    JOIN regions r ON r.id = d.region_id
    WHERE c.id = ?
    LIMIT 1
  ");
  $st->execute([$centerId]);
  $center = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $center = null;
}

if (!$center) {
  http_response_code(404);
  exit("Center not found.");
}

if ((int)$center['region_id'] !== (int)$assignment['region_id']) {
  http_response_code(403);
  exit("Forbidden: this center is not in your assigned region.");
}

/* ---------------- Load sessions for this center + assigned series ---------------- */
$sessions = [];
try {
  $st = $pdo->prepare("
    SELECT
      ts.id,
      ts.session_date,
      ts.start_time,
      ts.end_time,
      ts.candidate_count,
      COALESCE(ts.status,'active') AS session_status,
      o.code AS occupation_code,
      o.name AS occupation_name
    FROM timetable_sessions ts
    LEFT JOIN occupations o ON o.id = ts.occupation_id
    WHERE ts.center_id = ?
      AND ts.exam_series = ?
      AND COALESCE(ts.status,'active') <> 'cancelled'
    ORDER BY ts.session_date ASC, ts.start_time ASC
  ");
  $st->execute([$centerId, (int)$assignment['exam_series_id']]);
  $sessions = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $sessions = [];
}

/* ---------------- Load examiners (active) ----------------
   NOTE: Your DB has no examiner-region mapping yet.
   So we list active examiners; later we can add examiner_regions table for true region filtering.
*/
$examiners = [];
try {
  $st = $pdo->prepare("
    SELECT u.id, u.full_name, u.phone
    FROM users u
    WHERE u.role='examiner'
      AND COALESCE(u.status,'active')='active'
    ORDER BY u.full_name
  ");
  $st->execute();
  $examiners = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $examiners = [];
}

/* ---------------- Existing deployments for these sessions ---------------- */
$deploymentsBySession = [];
if ($sessions) {
  $ids = array_map(fn($x) => (int)$x['id'], $sessions);
  $in  = implode(',', array_fill(0, count($ids), '?'));

  try {
    $st = $pdo->prepare("
      SELECT
        d.id AS deployment_id,
        d.timetable_session_id,
        d.examiner_user_id,
        COALESCE(d.status,'active') AS deploy_status,
        " . ($hasCompletedAt ? "d.completed_at," : "NULL AS completed_at,") . "
        u.full_name,
        u.phone
      FROM deployments d
      JOIN users u ON u.id = d.examiner_user_id
      WHERE d.timetable_session_id IN ($in)
        AND COALESCE(d.status,'active') <> 'cancelled'
      ORDER BY d.id DESC
    ");
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $sid = (int)$r['timetable_session_id'];
      $deploymentsBySession[$sid][] = $r;
    }
  } catch (Throwable $e) {
    $deploymentsBySession = [];
  }
}

/* ---------------- POST: Assign examiner ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_examiner'])) {
  csrf_validate($_POST['csrf'] ?? null);

  $sessionId  = (int)($_POST['session_id'] ?? 0);
  $examinerId = (int)($_POST['examiner_user_id'] ?? 0);

  try {
    if ($sessionId <= 0 || $examinerId <= 0) {
      throw new RuntimeException("Select a session and an examiner.");
    }

    // Validate session belongs to this center & officer series
    $st = $pdo->prepare("
      SELECT id
      FROM timetable_sessions
      WHERE id=? AND center_id=? AND exam_series=? AND COALESCE(status,'active') <> 'cancelled'
      LIMIT 1
    ");
    $st->execute([$sessionId, $centerId, (int)$assignment['exam_series_id']]);
    if (!$st->fetchColumn()) {
      throw new RuntimeException("Session not found for this center/series.");
    }

    // Validate examiner
    $st = $pdo->prepare("
      SELECT id
      FROM users
      WHERE id=? AND role='examiner' AND COALESCE(status,'active')='active'
      LIMIT 1
    ");
    $st->execute([$examinerId]);
    if (!$st->fetchColumn()) {
      throw new RuntimeException("Examiner not found or inactive.");
    }

    // Prevent duplicate deployment
    $st = $pdo->prepare("
      SELECT id
      FROM deployments
      WHERE timetable_session_id=? AND examiner_user_id=? AND COALESCE(status,'active') <> 'cancelled'
      LIMIT 1
    ");
    $st->execute([$sessionId, $examinerId]);
    if ($st->fetchColumn()) {
      throw new RuntimeException("This examiner is already assigned to that session.");
    }

    // Insert deployment (correct columns)
    $st = $pdo->prepare("
      INSERT INTO deployments (timetable_session_id, examiner_user_id, deployed_by_user_id, status)
      VALUES (?, ?, ?, 'active')
    ");
    $st->execute([$sessionId, $examinerId, $officerId]);

    header("Location: center_sessions.php?center_id=" . $centerId);
    exit;

  } catch (Throwable $e) {
    $err = "❌ " . $e->getMessage();
  }
}

/* ---------------- POST: Mark deployment completed (unlock claim) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
  csrf_validate($_POST['csrf'] ?? null);

  $deploymentId = (int)($_POST['deployment_id'] ?? 0);

  try {
    if ($deploymentId <= 0) throw new RuntimeException("Invalid deployment id.");

    // Ensure this deployment belongs to one of this center's sessions in officer series
    $st = $pdo->prepare("
      SELECT d.id
      FROM deployments d
      JOIN timetable_sessions ts ON ts.id = d.timetable_session_id
      WHERE d.id = ?
        AND ts.center_id = ?
        AND ts.exam_series = ?
      LIMIT 1
    ");
    $st->execute([$deploymentId, $centerId, (int)$assignment['exam_series_id']]);
    if (!$st->fetchColumn()) {
      throw new RuntimeException("Deployment not found for this center/series.");
    }

    // Build update safely (only include columns that exist)
    $set = ["status='completed'"];
    $params = [];

    if ($hasCompletedAt) $set[] = "completed_at=NOW()";
    if ($hasCompletedBy) { $set[] = "completed_by_user_id=?"; $params[] = $officerId; }

    $params[] = $deploymentId;

    $sql = "UPDATE deployments SET " . implode(", ", $set) . " WHERE id=?";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    header("Location: center_sessions.php?center_id=" . $centerId);
    exit;

  } catch (Throwable $e) {
    $err = "❌ " . $e->getMessage();
  }
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Center Sessions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui;background:#f6f7fb;margin:0;padding:18px}
    .card{background:#fff;border-radius:14px;padding:14px;margin-bottom:14px;box-shadow:0 8px 24px rgba(0,0,0,.06)}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
    th{background:#f2f5ff}
    .muted{color:#666;font-size:13px}
    .msg{color:green;font-weight:800}
    .err{color:#b00020;font-weight:800}
    select,input{padding:10px;border:1px solid #ddd;border-radius:10px}
    button,a.btn{display:inline-block;padding:10px 12px;border-radius:10px;background:#1b5cff;color:#fff;text-decoration:none;font-weight:800;border:0;cursor:pointer}
    a.btn2{background:#fff;color:#1b5cff;border:2px solid #1b5cff}
    .pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:800}
    .pill.active{background:#e8fff1;color:#0b6b2f}
    .pill.completed{background:#eef3ff;color:#2442a8}
  </style>
</head>
<body>

<p><a class="btn btn2" href="dashboard.php">← Back</a></p>

<div class="card">
  <h2><?php echo h($center['center_number']); ?> — <?php echo h($center['center_name']); ?></h2>
  <p class="muted">
    <?php echo h($center['district_name']); ?> / <?php echo h($center['region_name']); ?>
    <?php if (!empty($center['location_name'])): ?> • <?php echo h($center['location_name']); ?><?php endif; ?>
  </p>
  <p class="muted">
    <b>Officer Series:</b> <?php echo h($assignment['series_name']); ?> • <b>Region:</b> <?php echo h($assignment['region_name']); ?>
  </p>

  <?php if ($msg) echo "<p class='msg'>".h($msg)."</p>"; ?>
  <?php if ($err) echo "<p class='err'>".h($err)."</p>"; ?>
</div>

<div class="card">
  <h3>Sessions (Papers/Occupations & Dates)</h3>

  <?php if (!$sessions): ?>
    <p class="muted">No sessions found for this center in your assigned series.</p>
  <?php else: ?>
    <table>
      <tr>
        <th>Date</th>
        <th>Time</th>
        <th>Paper / Occupation</th>
        <th>Candidates</th>
        <th>Assigned Examiners</th>
        <th>Assign New</th>
      </tr>

      <?php foreach ($sessions as $s): ?>
        <?php
          $sid = (int)$s['id'];
          $deps = $deploymentsBySession[$sid] ?? [];
        ?>
        <tr>
          <td><?php echo h($s['session_date']); ?></td>
          <td><?php echo h($s['start_time'] . ' - ' . $s['end_time']); ?></td>
          <td><b><?php echo h(($s['occupation_code'] ?? '') . ' — ' . ($s['occupation_name'] ?? '')); ?></b></td>
          <td><?php echo (int)$s['candidate_count']; ?></td>

          <td>
            <?php if (!$deps): ?>
              <span class="muted">None</span>
            <?php else: ?>
              <?php foreach ($deps as $d): ?>
                <div style="margin-bottom:8px;">
                  <b><?php echo h($d['full_name']); ?></b>
                  <?php if (!empty($d['phone'])): ?> • <?php echo h($d['phone']); ?><?php endif; ?>

                  <?php
                    $st = strtolower((string)$d['deploy_status']);
                    $pill = $st === 'completed' ? 'completed' : 'active';
                  ?>
                  <span class="pill <?php echo h($pill); ?>"><?php echo h(strtoupper($st)); ?></span>

                  <?php if ($st !== 'completed'): ?>
                    <form method="post" style="display:inline;margin-left:8px;">
                      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
                      <input type="hidden" name="deployment_id" value="<?php echo (int)$d['deployment_id']; ?>">
                      <button type="submit" name="mark_completed" value="1"
                              onclick="return confirm('Mark this deployment as COMPLETED? This will enable claim for the examiner.');"
                              style="background:#2442a8;">
                        Mark Finished
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>

          <td>
            <form method="post">
              <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="session_id" value="<?php echo $sid; ?>">

              <select name="examiner_user_id" required style="min-width:240px;">
                <option value="">-- Select Examiner --</option>
                <?php foreach ($examiners as $ex): ?>
                  <option value="<?php echo (int)$ex['id']; ?>">
                    <?php echo h($ex['full_name']); ?><?php echo !empty($ex['phone']) ? ' • '.$ex['phone'] : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <button type="submit" name="assign_examiner" value="1" style="margin-top:8px;">
                Assign
              </button>

              <div class="muted" style="margin-top:8px;">
                Note: examiner-region filtering needs a mapping table; currently shows all active examiners.
              </div>
            </form>
          </td>

        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

</body>
</html>

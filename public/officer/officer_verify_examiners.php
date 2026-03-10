<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
$officerId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * We verify examiners "in officer region" using:
 * officer_assignments (active) -> region_id
 * examiner_center_assignments (active, active series) -> center -> district -> region_id
 *
 * We verify on latest examiner_applications row:
 * officer_verified, officer_verified_at, officer_verified_by
 */

$msg = null;
$err = null;

/* ---------------- Active series (used to match center assignments) ---------------- */
$activeSeries = null;
try {
  $st = $pdo->query("SELECT id, name FROM exam_series WHERE status='active' ORDER BY id DESC LIMIT 1");
  $activeSeries = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $activeSeries = null; }
$activeSeriesId = (int)($activeSeries['id'] ?? 0);

/* ---------------- Officer regions ---------------- */
$officerRegions = [];
try {
  $st = $pdo->prepare("
    SELECT oa.region_id, r.name AS region_name
    FROM officer_assignments oa
    JOIN regions r ON r.id = oa.region_id
    WHERE oa.user_id = ?
      AND oa.status = 'active'
    ORDER BY r.name ASC
  ");
  $st->execute([$officerId]);
  $officerRegions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $officerRegions = [];
}

if (!$officerRegions) {
  http_response_code(403);
  exit("You have no active region assignment. Ask admin to assign you a region.");
}

/* ---------------- Helpers: fetch examiners in officer regions ---------------- */
function fetchExaminersInOfficerRegions(PDO $pdo, int $activeSeriesId, int $officerId): array {
  // This returns examiners assigned to a center in officer region for ACTIVE series
  // + latest application info (status + verification flag)
  $st = $pdo->prepare("
    SELECT
      u.id AS examiner_id,
      u.full_name,
      u.phone,
      u.status AS user_status,

      r.name AS region_name,
      c_home.center_number AS home_center_number,
      c_home.center_name   AS home_center_name,

      ea.id AS application_id,
      ea.status AS app_status,
      ea.submitted_at,
      ea.officer_verified,
      ea.officer_verified_at

    FROM users u

    -- officer regions
    JOIN officer_assignments oa
      ON oa.user_id = ?
     AND oa.status = 'active'

    JOIN regions r ON r.id = oa.region_id

    -- examiner home center assignment (active series + active assignment)
    JOIN examiner_center_assignments eca
      ON eca.user_id = u.id
     AND eca.is_active = 1
     AND eca.exam_series_id = ?

    JOIN centers c_home      ON c_home.id = eca.center_id
    JOIN districts d_home    ON d_home.id = c_home.district_id

    -- match officer region
    WHERE d_home.region_id = oa.region_id
      AND u.role = 'examiner'
    -- latest application per examiner
      AND EXISTS (
        SELECT 1
        FROM examiner_applications eaX
        WHERE eaX.user_id = u.id
      )

    -- Join latest app row using subquery
    LEFT JOIN examiner_applications ea
      ON ea.id = (
        SELECT ea2.id
        FROM examiner_applications ea2
        WHERE ea2.user_id = u.id
        ORDER BY ea2.id DESC
        LIMIT 1
      )

    ORDER BY r.name ASC, u.full_name ASC
    LIMIT 2000
  ");
  $st->execute([$officerId, $activeSeriesId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ---------------- Actions (bulk verify + bulk message) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  $action = (string)($_POST['action'] ?? '');
  $ids = $_POST['examiner_ids'] ?? [];
  if (!is_array($ids)) $ids = [];
  $examinerIds = array_values(array_unique(array_map('intval', $ids)));
  $examinerIds = array_values(array_filter($examinerIds, fn($x) => $x > 0));

  if (!$examinerIds) {
    $err = "Select at least one examiner.";
  } else {
    // Confirm selected examiners belong to officer regions (server-side safety)
    // We build a temp set from current list.
    $all = fetchExaminersInOfficerRegions($pdo, $activeSeriesId, $officerId);
    $allowed = [];
    foreach ($all as $row) $allowed[(int)$row['examiner_id']] = true;

    $examinerIds = array_values(array_filter($examinerIds, fn($id) => isset($allowed[$id])));

    if (!$examinerIds) {
      $err = "Selected examiners are not in your region (or no longer available).";
    } else {

      if ($action === 'verify_selected') {
        try {
          $pdo->beginTransaction();

          // Update latest application row per user
          // We update by joining to latest id subquery per user
          $in = implode(',', array_fill(0, count($examinerIds), '?'));
          $params = $examinerIds;

          $sql = "
            UPDATE examiner_applications ea
            JOIN (
              SELECT user_id, MAX(id) AS max_id
              FROM examiner_applications
              WHERE user_id IN ($in)
              GROUP BY user_id
            ) x ON x.max_id = ea.id
            SET
              ea.officer_verified = 1,
              ea.officer_verified_at = NOW(),
              ea.officer_verified_by = ?
          ";
          $st = $pdo->prepare($sql);
          $st->execute(array_merge($params, [$officerId]));

          $pdo->commit();
          $msg = "✅ Verified " . count($examinerIds) . " examiner(s) in your region.";
        } catch (Throwable $e) {
          $pdo->rollBack();
          $err = "❌ Verification failed: " . $e->getMessage();
        }
      }

      if ($action === 'send_bulk_message') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body    = trim((string)($_POST['body'] ?? ''));

        if ($body === '') {
          $err = "Message body is required.";
        } else {
          try {
            $pdo->beginTransaction();

            // Create message
            $st = $pdo->prepare("
              INSERT INTO messages (sender_user_id, sender_role, target_role, region_id, subject, body, created_at)
              VALUES (?, 'officer', 'examiner', NULL, ?, ?, NOW())
            ");
            $st->execute([$officerId, $subject !== '' ? $subject : null, $body]);
            $messageId = (int)$pdo->lastInsertId();

            // Recipients
            $st2 = $pdo->prepare("
              INSERT INTO message_recipients (message_id, user_id, is_read, delivered_at)
              VALUES (?, ?, 0, NOW())
            ");
            foreach ($examinerIds as $eid) {
              $st2->execute([$messageId, $eid]);
            }

            $pdo->commit();
            $msg = "✅ Message sent to " . count($examinerIds) . " examiner(s).";
          } catch (Throwable $e) {
            $pdo->rollBack();
            $err = "❌ Sending failed: " . $e->getMessage();
          }
        }
      }
    }
  }
}

/* ---------------- Load list ---------------- */
$examiners = fetchExaminersInOfficerRegions($pdo, $activeSeriesId, $officerId);

/* quick region badge text */
$regionNames = array_map(fn($r) => (string)$r['region_name'], $officerRegions);
$regionText = implode(', ', $regionNames);

/* UI helpers */
function badgeClassForApp(string $status): string {
  $s = strtolower(trim($status));
  if ($s === 'approved') return 'badge ok';
  if ($s === 'rejected') return 'badge bad';
  return 'badge warn';
}
function badgeText(string $status): string {
  $s = trim($status);
  return $s === '' ? 'PENDING' : strtoupper($s);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Verify Examiners (Officer)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../assets/css/main.css">
  <style>
    .page-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .muted{color:#6b7280}
    .badge{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;font-weight:900;font-size:12px;border:1px solid #e5e7eb;background:#fff}
    .badge.ok{border-color:#bbf7d0;background:#ecfdf3;color:#166534}
    .badge.warn{border-color:#fde68a;background:#fffbeb;color:#92400e}
    .badge.bad{border-color:#fecaca;background:#fff1f2;color:#991b1b}
    .badge.info{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8}
    table{width:100%;border-collapse:collapse}
    th,td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
    th{background:#f8fafc;font-weight:1000}
    .table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:16px}
    .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}
    .actions{display:flex;gap:10px;flex-wrap:wrap}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;font-weight:900;font-size:12px}
    .pill.yes{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}
    .pill.no{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
    .formbox{border:1px solid #e5e7eb;border-radius:16px;padding:14px;background:#fff}
    textarea{min-height:110px}
    .tabs{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
    .tab-btn{cursor:pointer;border:1px solid #e5e7eb;padding:10px 12px;border-radius:12px;background:#fff;font-weight:900}
    .tab-btn.active{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
    .tab-panel{display:none;margin-top:12px}
    .tab-panel.active{display:block}
  </style>
</head>
<body>

<div class="container">

  <div class="card">
    <div class="page-title">
      <div>
        <h2 style="margin:0;">Verify Examiners (My Region)</h2>
        <div class="muted" style="margin-top:6px;">
          Regions: <span class="badge info"><?= h($regionText) ?></span>
          <?php if ($activeSeriesId > 0): ?>
            <span class="badge info">Series: <?= h((string)$activeSeries['name']) ?></span>
          <?php else: ?>
            <span class="badge warn">No active series found</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="actions">
        <a class="btn btn-outline" href="officer_dashboard.php">← Back</a>
        <a class="btn btn-outline" href="../logout.php">Logout</a>
      </div>
    </div>
  </div>

  <div class="card">
    <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

    <div class="tabs" role="tablist">
      <button type="button" class="tab-btn active" data-tab="list">Examiners List</button>
      <button type="button" class="tab-btn" data-tab="message">Bulk Message</button>
    </div>

    <!-- TAB 1: LIST + BULK VERIFY -->
    <div class="tab-panel active" id="tab-list">

      <div class="toolbar">
        <span class="badge info">Total: <?= (int)count($examiners) ?></span>
        <button type="button" class="btn btn-outline" onclick="toggleAll(true)">Select All</button>
        <button type="button" class="btn btn-outline" onclick="toggleAll(false)">Unselect All</button>
      </div>

      <form method="post" class="mt-14">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">
        <input type="hidden" name="action" value="verify_selected">

        <div class="table-wrap mt-10">
          <table>
            <thead>
              <tr>
                <th style="width:56px;">Pick</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Region</th>
                <th>Home Center</th>
                <th>Application</th>
                <th>Officer Verified</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$examiners): ?>
                <tr><td colspan="7" class="muted">No examiners found in your region for the active series.</td></tr>
              <?php endif; ?>

              <?php foreach ($examiners as $e): ?>
                <?php
                  $appStatus = (string)($e['app_status'] ?? 'pending');
                  $verified  = (int)($e['officer_verified'] ?? 0) === 1;
                  $userActive = strtolower((string)($e['user_status'] ?? 'active')) === 'active';
                ?>
                <tr>
                  <td>
                    <input type="checkbox" class="pick" name="examiner_ids[]" value="<?= (int)$e['examiner_id'] ?>" <?= !$userActive ? 'disabled' : '' ?>>
                  </td>
                  <td><b><?= h((string)$e['full_name']) ?></b></td>
                  <td><?= h((string)$e['phone']) ?></td>
                  <td><?= h((string)$e['region_name']) ?></td>
                  <td>
                    <?= h((string)$e['home_center_number']) ?> — <?= h((string)$e['home_center_name']) ?>
                    <?php if (!$userActive): ?>
                      <div class="badge bad" style="margin-top:6px;">USER DEACTIVATED</div>
                    <?php endif; ?>
                  </td>
                  <td><span class="<?= h(badgeClassForApp($appStatus)) ?>"><?= h(badgeText($appStatus)) ?></span></td>
                  <td>
                    <?php if ($verified): ?>
                      <span class="pill yes">✅ VERIFIED</span>
                      <div class="muted" style="margin-top:6px;font-size:12px;"><?= h((string)$e['officer_verified_at']) ?></div>
                    <?php else: ?>
                      <span class="pill no">⏳ NOT VERIFIED</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="mt-14">
          <button class="btn btn-primary" type="submit" onclick="return confirm('Verify selected examiners?');">
            Verify Selected
          </button>
          <div class="muted" style="margin-top:8px;">
            Verification is saved on the examiner’s <b>latest</b> application record.
          </div>
        </div>
      </form>
    </div>

    <!-- TAB 2: BULK MESSAGE (uses same checkboxes on this page) -->
    <div class="tab-panel" id="tab-message">
      <div class="formbox">
        <b>Send Bulk Message to Selected Examiners</b>
        <div class="muted" style="margin-top:6px;">
          Tip: go to “Examiners List” tab, select examiners, then come back here to send message.
        </div>

        <form method="post" class="mt-14" onsubmit="return confirm('Send this message to the selected examiners?');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">
          <input type="hidden" name="action" value="send_bulk_message">

          <!-- the selected ids will be copied here by JS -->
          <div id="hiddenRecipients"></div>

          <label>Subject (optional)</label>
          <input type="text" name="subject" placeholder="e.g., Verification update">

          <label class="mt-10">Message</label>
          <textarea name="body" required placeholder="Type your message here..."></textarea>

          <div class="mt-14">
            <button class="btn btn-primary" type="submit">Send Bulk Message</button>
          </div>
        </form>
      </div>
    </div>

  </div>

</div>

<script>
  function toggleAll(on){
    document.querySelectorAll('.pick').forEach(cb => {
      if (!cb.disabled) cb.checked = !!on;
    });
  }

  // When switching to message tab, copy selected ids into hiddenRecipients so POST includes them
  (function(){
    const btns = document.querySelectorAll('.tab-btn');
    const panels = {
      list: document.getElementById('tab-list'),
      message: document.getElementById('tab-message')
    };
    const hidden = document.getElementById('hiddenRecipients');

    function setTab(tab){
      btns.forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
      Object.keys(panels).forEach(k => panels[k].classList.toggle('active', k === tab));

      if (tab === 'message') {
        // build hidden fields from selected checkboxes
        const selected = Array.from(document.querySelectorAll('.pick'))
          .filter(cb => cb.checked && !cb.disabled)
          .map(cb => cb.value);

        hidden.innerHTML = selected.map(v =>
          '<input type="hidden" name="examiner_ids[]" value="'+ String(v).replace(/"/g,'&quot;') +'">'
        ).join('');

        if (selected.length === 0) {
          alert('Select at least one examiner in the Examiners List tab first.');
          setTab('list');
        }
      }
    }

    btns.forEach(b => b.addEventListener('click', () => setTab(b.dataset.tab)));
    setTab('list');
  })();
</script>

</body>
</html>

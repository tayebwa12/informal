<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';
require_once __DIR__ . '/../../app/helpers/sms.php';

$me = require_officer();
$officerId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$apiKey   = (string)($_ENV['SMS_API_KEY'] ?? '');
$endpoint = (string)($_ENV['SMS_ENDPOINT'] ?? 'https://sms.thinkxcloud.com/api/send-message');

$msg = null;
$err = null;
$results = [];
$countTargets = 0;

/**
 * Fetch examiners in officer's region(s) who are:
 * - users.role = examiner
 * - users.status = active
 * - latest examiner_applications.status = approved
 * - and their assigned center is in officer region (via examiner_center_assignments -> centers -> districts -> region)
 */
function fetchRegionExaminers(PDO $pdo, int $officerId): array {
  $st = $pdo->prepare("
    SELECT DISTINCT
      u.id AS examiner_id,
      u.full_name,
      u.phone,
      homec.center_number AS home_center_number,
      homec.center_name AS home_center_name,
      r.name AS region_name
    FROM officer_assignments oa
    JOIN regions r ON r.id = oa.region_id

    -- examiner must be in a center within the region for the series assignment (home center)
    JOIN examiner_center_assignments eca ON eca.is_active = 1
    JOIN centers homec ON homec.id = eca.center_id
    JOIN districts d ON d.id = homec.district_id

    JOIN users u ON u.id = eca.user_id

    -- latest approved application
    JOIN examiner_applications ea
      ON ea.id = (
        SELECT ea2.id
        FROM examiner_applications ea2
        WHERE ea2.user_id = u.id
        ORDER BY ea2.id DESC
        LIMIT 1
      )

    WHERE oa.user_id = ?
      AND oa.status = 'active'
      AND d.region_id = oa.region_id
      AND u.role = 'examiner'
      AND u.status = 'active'
      AND ea.status = 'approved'
    ORDER BY u.full_name ASC
  ");
  $st->execute([$officerId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$targets = fetchRegionExaminers($pdo, $officerId);
$countTargets = count($targets);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  if ($apiKey === '') {
    $err = "❌ SMS_API_KEY is missing in your env/config.";
  } else {
    $text = trim((string)($_POST['message'] ?? ''));
    if ($text === '') {
      $err = "❌ Message cannot be empty.";
    } elseif (!$targets) {
      $err = "❌ No examiners found in your region to message.";
    } else {
      // OPTIONAL: create a simple log table first (recommended)
      // CREATE TABLE sms_logs (
      //   id INT AUTO_INCREMENT PRIMARY KEY,
      //   officer_id INT NOT NULL,
      //   examiner_id INT NULL,
      //   phone VARCHAR(32) NOT NULL,
      //   message TEXT NOT NULL,
      //   http_code INT NULL,
      //   ok TINYINT(1) NOT NULL DEFAULT 0,
      //   response TEXT NULL,
      //   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      // );

      $sent = 0; $failed = 0;

      foreach ($targets as $t) {
        $rawPhone = (string)($t['phone'] ?? '');
        $to = sms_normalize_ug($rawPhone);

        if ($to === null) {
          $failed++;
          $results[] = ['name'=>$t['full_name'], 'phone'=>$rawPhone, 'ok'=>false, 'info'=>'Invalid phone format'];
          continue;
        }

        $resp = sms_send_thinkx($apiKey, $endpoint, $to, $text); // Thinkx endpoint params :contentReference[oaicite:1]{index=1}
        $ok = (bool)($resp['ok'] ?? false);

        if ($ok) $sent++; else $failed++;

        // log
        try {
          $log = $pdo->prepare("
            INSERT INTO sms_logs (officer_id, examiner_id, phone, message, http_code, ok, response)
            VALUES (?, ?, ?, ?, ?, ?, ?)
          ");
          $log->execute([
            $officerId,
            (int)($t['examiner_id'] ?? 0),
            $to,
            $text,
            (int)($resp['http'] ?? 0),
            $ok ? 1 : 0,
            (string)($resp['raw'] ?? ''),
          ]);
        } catch (Throwable $e) {
          // if table doesn't exist yet, ignore (but you should create it)
        }

        $results[] = [
          'name' => (string)$t['full_name'],
          'phone'=> $to,
          'ok'   => $ok,
          'info' => $ok ? 'Sent' : ('Failed (HTTP ' . (int)($resp['http'] ?? 0) . ')'),
        ];

        // soft throttle to avoid gateway rate limits
        usleep(90000); // 0.09s
      }

      $msg = "✅ Bulk SMS done. Sent: {$sent}, Failed: {$failed}.";
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Bulk SMS — Examiners</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<div class="container">

  <div class="card">
    <div class="card-title">
      <div>
        <h2 style="margin:0;">Bulk SMS — Examiners in My Region</h2>
        <p class="muted" style="margin:6px 0 0;">
          Targets found: <b><?= (int)$countTargets ?></b>
        </p>
      </div>
      <div class="flex gap-10">
        <a class="btn btn-outline" href="dashboard.php">← Back</a>
        <a class="btn btn-outline" href="../logout.php">Logout</a>
      </div>
    </div>
  </div>

  <div class="card">
    <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">

      <label>Message</label>
      <textarea name="message" rows="4" required placeholder="Type SMS to all examiners in your region..."></textarea>

      <div class="help">Tip: Keep it under 160 characters for 1 SMS segment.</div>

      <div class="mt-14">
        <button class="btn btn-primary" type="submit" <?= $countTargets ? '' : 'disabled' ?>>
          Send Bulk SMS (<?= (int)$countTargets ?>)
        </button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Recipients</h3>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Phone</th>
            <th>Home Center</th>
            <th>Region</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$targets): ?>
          <tr><td colspan="4" class="muted">No examiners found in your region.</td></tr>
        <?php endif; ?>
        <?php foreach ($targets as $t): ?>
          <tr>
            <td><?= h((string)$t['full_name']) ?></td>
            <td><?= h((string)$t['phone']) ?></td>
            <td><?= h((string)$t['home_center_number'] . ' - ' . (string)$t['home_center_name']) ?></td>
            <td><?= h((string)$t['region_name']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($results): ?>
    <div class="card">
      <h3 style="margin-top:0;">Send Results</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Phone</th>
              <th>Result</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($results as $r): ?>
            <tr>
              <td><?= h((string)$r['name']) ?></td>
              <td><?= h((string)$r['phone']) ?></td>
              <td><?= h((string)$r['info']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>
</body>
</html>

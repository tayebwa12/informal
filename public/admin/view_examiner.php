<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

require_admin();

$msg = $err = null;

function norm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** ✅ Get admin user id from session reliably (FK safe) */
function getAdminId(PDO $pdo): int {
  $id = 0;
  if (!empty($_SESSION['user_id'])) $id = (int)$_SESSION['user_id'];
  elseif (!empty($_SESSION['user']['id'])) $id = (int)$_SESSION['user']['id'];
  elseif (!empty($_SESSION['admin_id'])) $id = (int)$_SESSION['admin_id'];

  if ($id < 1) return 0;

  $st = $pdo->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
  $st->execute([$id]);
  return $st->fetchColumn() ? $id : 0;
}

$appId = (int)($_GET['id'] ?? 0);
if ($appId < 1) { die("Invalid application."); }

/** ✅ Re-usable loader (prevents stale $app showing pending) */
function loadApplication(PDO $pdo, int $appId): array {
  $st = $pdo->prepare("
    SELECT
      ea.*,
      u.id AS user_id,
      u.role AS user_role,
      u.status AS user_status,
      u.is_imported,

      COALESCE(NULLIF(ea.full_name,''), NULLIF(u.full_name,'')) AS full_name_display,
      COALESCE(NULLIF(ea.phone,''), NULLIF(u.phone,'')) AS phone_display,
      COALESCE(NULLIF(ea.email,''), NULLIF(u.email,'')) AS email_display

    FROM examiner_applications ea
    LEFT JOIN users u ON u.id = ea.user_id
    WHERE ea.id = ?
    LIMIT 1
  ");
  $st->execute([$appId]);
  $app = $st->fetch(PDO::FETCH_ASSOC);
  if (!$app) die("Application not found.");
  return $app;
}

/** ✅ Load app initially */
$app = loadApplication($pdo, $appId);

/** ✅ actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  $action = (string)($_POST['action'] ?? '');
  $reason = norm((string)($_POST['reason'] ?? ''));

  $reviewedBy = getAdminId($pdo);
  if ($reviewedBy < 1) {
    $err = "❌ Admin session missing/invalid. Please logout and login again.";
  } else {
    try {
      $pdo->beginTransaction();

      if ($action === 'approve') {

        $up = $pdo->prepare("
          UPDATE examiner_applications
          SET status='approved',
              rejection_reason=NULL,
              reviewed_by=?,
              reviewed_at=NOW()
          WHERE id=?
        ");
        $up->execute([$reviewedBy, $appId]);

        if (!empty($app['user_id'])) {
          $u = $pdo->prepare("UPDATE users SET status='active', role='examiner' WHERE id=? LIMIT 1");
          $u->execute([(int)$app['user_id']]);
        }

        /** ✅ Verify the update actually saved */
        $chk = $pdo->prepare("SELECT status FROM examiner_applications WHERE id=? LIMIT 1");
        $chk->execute([$appId]);
        $now = (string)($chk->fetchColumn() ?? '');
        if ($now !== 'approved') {
          throw new RuntimeException("Approval failed. DB status is still: {$now}");
        }

        $pdo->commit();
        header("Location: view_examiner.php?id={$appId}&ok=1&t=" . time());
        exit;

      } elseif ($action === 'reject') {

        if ($reason === '') throw new RuntimeException("Rejection reason is required.");

        $up = $pdo->prepare("
          UPDATE examiner_applications
          SET status='rejected',
              rejection_reason=?,
              reviewed_by=?,
              reviewed_at=NOW()
          WHERE id=?
        ");
        $up->execute([$reason, $reviewedBy, $appId]);

        /** ✅ Verify the update actually saved */
        $chk = $pdo->prepare("SELECT status FROM examiner_applications WHERE id=? LIMIT 1");
        $chk->execute([$appId]);
        $now = (string)($chk->fetchColumn() ?? '');
        if ($now !== 'rejected') {
          throw new RuntimeException("Rejection failed. DB status is still: {$now}");
        }

        $pdo->commit();
        header("Location: view_examiner.php?id={$appId}&ok=1&t=" . time());
        exit;

      } elseif ($action === 'block') {

        if (!empty($app['user_id'])) {
          $u = $pdo->prepare("UPDATE users SET status='disabled' WHERE id=? LIMIT 1");
          $u->execute([(int)$app['user_id']]);
        }

        $up = $pdo->prepare("
          UPDATE examiner_applications
          SET status='rejected',
              rejection_reason='Blocked by admin',
              reviewed_by=?,
              reviewed_at=NOW()
          WHERE id=?
        ");
        $up->execute([$reviewedBy, $appId]);

        /** ✅ Verify the update actually saved */
        $chk = $pdo->prepare("SELECT status FROM examiner_applications WHERE id=? LIMIT 1");
        $chk->execute([$appId]);
        $now = (string)($chk->fetchColumn() ?? '');
        if ($now !== 'rejected') {
          throw new RuntimeException("Block failed. DB status is still: {$now}");
        }

        $pdo->commit();
        header("Location: view_examiner.php?id={$appId}&ok=1&t=" . time());
        exit;
      }

      $pdo->commit();

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = "❌ " . $e->getMessage();
    }
  }
}

/** ✅ IMPORTANT: after redirect (?ok=1) force fresh load so badge updates */
if (!empty($_GET['ok'])) {
  $app = loadApplication($pdo, $appId);
}

/** ✅ optional joins for names (after fresh load) */
$occName = null;
if (!empty($app['occupation_id'])) {
  $o = $pdo->prepare("SELECT name FROM occupations WHERE id=? LIMIT 1");
  $o->execute([(int)$app['occupation_id']]);
  $occName = $o->fetchColumn() ?: null;
}

$distName = null;
if (!empty($app['district_id'])) {
  $d = $pdo->prepare("SELECT name FROM districts WHERE id=? LIMIT 1");
  $d->execute([(int)$app['district_id']]);
  $distName = $d->fetchColumn() ?: null;
}

$centerName = null;
if (!empty($app['center_id'])) {
  $c = $pdo->prepare("SELECT CONCAT(center_number,' - ',center_name) FROM centers WHERE id=? LIMIT 1");
  $c->execute([(int)$app['center_id']]);
  $centerName = $c->fetchColumn() ?: null;
}

/** ✅ Use fallback-safe display fields */
$fullName = (string)($app['full_name_display'] ?? '');
$phone    = (string)($app['phone_display'] ?? '');
$email    = (string)($app['email_display'] ?? '');
$nin      = (string)($app['nin'] ?? '');

$qualPath = (string)($app['qualification_path'] ?? '');
$passPath = (string)($app['passport_photo_path'] ?? '');

$missingDocs = ($qualPath === '' || $passPath === '');
$appStatus = strtolower((string)($app['status'] ?? 'pending'));

$publicPrefix = "/practical-deployment-system/public/";
$passportUrl = $passPath !== '' ? $publicPrefix . ltrim($passPath, '/') : '';
$qualUrl     = $qualPath !== '' ? $publicPrefix . ltrim($qualPath, '/') : '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>View Examiner</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- ✅ Your CSS stays exactly the same -->
  <style>
    :root{
      --blue:#2f56c6; --blue2:#4b74e6; --bg:#f3f6ff; --card:#ffffff; --text:#0f172a;
      --muted:#64748b; --line:#e8eefc; --shadow:0 10px 30px rgba(15, 23, 42, .08);
      --ok:#16a34a; --warn:#f59e0b; --bad:#dc2626;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--text)}
    .header{background:linear-gradient(90deg,var(--blue),var(--blue2));color:#fff;padding:18px 18px 64px;}
    .headerInner{max-width:1150px;margin:0 auto;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
    .header h1{margin:0;font-size:22px;letter-spacing:.2px}
    .header .sub{margin-top:6px;opacity:.92;font-size:14px}
    .pillTop{display:inline-flex;align-items:center;gap:10px;padding:10px 14px;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18);font-weight:900;}
    .wrap{max-width:1150px;margin:-44px auto 30px;padding:0 14px}
    .grid{display:grid;grid-template-columns:1fr;gap:14px}
    @media(min-width:980px){.grid{grid-template-columns:1.15fr .85fr}}
    .card{background:var(--card);border-radius:18px;box-shadow:var(--shadow);border:1px solid var(--line);padding:14px;}
    .cardTitle{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
    .cardTitle h2,.cardTitle h3{margin:0;font-size:16px}
    .muted{color:var(--muted);font-size:13px}
    .kv{display:grid;grid-template-columns:170px 1fr;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)}
    .kv:last-child{border-bottom:none}
    .k{font-size:12px;font-weight:1000;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
    .v{font-size:14px}
    .badge{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;font-size:12px;font-weight:1000;border:1px solid var(--line);background:#fff;}
    .b-ok{color:var(--ok);border-color:rgba(22,163,74,.25);background:rgba(22,163,74,.08)}
    .b-warn{color:#8a4b00;border-color:rgba(245,158,11,.25);background:rgba(245,158,11,.10)}
    .b-bad{color:var(--bad);border-color:rgba(220,38,38,.25);background:rgba(220,38,38,.10)}
    .b-info{color:var(--blue);border-color:rgba(47,86,198,.20);background:rgba(47,86,198,.08)}
    .btnRow{display:flex;gap:10px;flex-wrap:wrap}
    a.btn,button.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:#fff;color:var(--blue);font-weight:1000;text-decoration:none;cursor:pointer;}
    a.btn.primary,button.btn.primary{border:0;color:#fff;background:linear-gradient(90deg,var(--blue),var(--blue2));}
    button.btn.danger{border:0;color:#fff;background:linear-gradient(90deg,#d7263d,#f14c4c);}
    .msg,.err{max-width:1150px;margin:12px auto 0;padding:12px 14px;border-radius:14px;border:1px solid var(--line);background:#fff}
    .msg{border-color:rgba(22,163,74,.25);background:rgba(22,163,74,.08);color:#0b6b2f;font-weight:900}
    .err{border-color:rgba(220,38,38,.25);background:rgba(220,38,38,.08);color:#7f1d1d;font-weight:900}
    .photoWrap{display:flex;align-items:center;justify-content:center}
    .avatar{width:150px;height:150px;border-radius:999px;background:linear-gradient(135deg,var(--blue),var(--blue2));padding:4px;box-shadow:0 12px 28px rgba(15,23,42,.18);}
    .avatar .inner{width:100%;height:100%;border-radius:999px;overflow:hidden;background:#fff;border:3px solid #fff;}
    .avatar img{width:100%;height:100%;object-fit:cover;display:block}
    .docCard{border:1px solid var(--line);border-radius:16px;padding:12px;background:#fff}
    input[type="text"]{padding:10px;border-radius:12px;border:1px solid var(--line);min-width:260px;outline:none;}
    input[type="text"]:focus{border-color:rgba(47,86,198,.35);box-shadow:0 0 0 4px rgba(47,86,198,.10)}
  </style>
</head>
<body>

  <div class="header">
    <div class="headerInner">
      <div>
        <h1>Examiner Application Review</h1>
        <div class="sub">Verify details, review documents, then approve / reject / block.</div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <div class="pillTop">
          <?php if ($appStatus === 'approved'): ?>
            <span class="badge b-ok">✅ APPROVED</span>
          <?php elseif ($appStatus === 'rejected'): ?>
            <span class="badge b-bad">❌ REJECTED</span>
          <?php else: ?>
            <span class="badge b-warn">⏳ PENDING</span>
          <?php endif; ?>
          <?php if ($missingDocs): ?><span class="badge b-info">📄 Missing Docs</span><?php endif; ?>
        </div>
        <a class="btn primary" href="manage_examiners.php">← Back</a>
      </div>
    </div>
  </div>

  <?php if (!empty($_GET['ok'])): ?>
    <div class="msg">✅ Updated successfully.</div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="err"><?php echo h($err); ?></div>
  <?php endif; ?>

  <div class="wrap">
    <div class="grid">

      <div class="card">
        <div class="cardTitle">
          <h2>Applicant Details</h2>
          <?php if ($phone !== ''): ?>
            <a class="btn" href="<?php echo 'sms:' . h($phone) . '?body=' . urlencode('Hello '.$fullName.', your UVTAB examiner application status is: '.$appStatus.'.'); ?>">
              📩 Send SMS
            </a>
          <?php endif; ?>
        </div>

        <div class="kv"><div class="k">Full Name</div><div class="v"><?php echo h($fullName); ?></div></div>
        <div class="kv"><div class="k">Phone</div><div class="v"><?php echo h($phone); ?></div></div>
        <div class="kv"><div class="k">Email</div><div class="v"><?php echo h($email); ?></div></div>
        <div class="kv"><div class="k">NIN</div><div class="v"><?php echo h($nin); ?></div></div>

        <div style="height:8px"></div>
        <div class="cardTitle"><h2>Application Info</h2></div>

        <div class="kv"><div class="k">Occupation</div><div class="v"><?php echo h($occName ?? ''); ?></div></div>
        <div class="kv"><div class="k">District</div><div class="v"><?php echo h($distName ?? ''); ?></div></div>
        <div class="kv"><div class="k">Center</div><div class="v"><?php echo h($centerName ?? ''); ?></div></div>
        <div class="kv"><div class="k">Organisation</div><div class="v"><?php echo h((string)($app['organisation_name'] ?? '')); ?></div></div>
        <div class="kv"><div class="k">Submitted</div><div class="v"><?php echo h((string)($app['submitted_at'] ?? '')); ?></div></div>

        <?php if (!empty($app['rejection_reason'])): ?>
          <div class="kv">
            <div class="k">Rejection Reason</div>
            <div class="v"><b><?php echo h((string)$app['rejection_reason']); ?></b></div>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="cardTitle">
          <h2>Documents & Photo</h2>
          <span class="muted">Verify before approving</span>
        </div>

        <div class="docCard">
          <div class="photoWrap" style="margin-bottom:12px;">
            <div class="avatar">
              <div class="inner">
                <?php if ($passportUrl): ?>
                  <img src="<?php echo h($passportUrl); ?>" alt="Passport photo">
                <?php else: ?>
                  <div style="height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);font-weight:1000;">
                    No Photo
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="btnRow" style="justify-content:center;">
            <?php if ($passportUrl): ?>
              <a class="btn" target="_blank" href="<?php echo h($passportUrl); ?>">📷 Open Photo</a>
              <a class="btn" download href="<?php echo h($passportUrl); ?>">⬇️ Download</a>
            <?php endif; ?>
          </div>

          <div style="height:10px"></div>

          <div class="cardTitle"><h3>Qualification PDF</h3></div>
          <?php if ($qualUrl): ?>
            <div class="btnRow">
              <a class="btn" target="_blank" href="<?php echo h($qualUrl); ?>">📄 View PDF</a>
              <a class="btn" download href="<?php echo h($qualUrl); ?>">⬇️ Download</a>
            </div>
            <div class="muted" style="margin-top:8px;"><?php echo h($qualPath); ?></div>
          <?php else: ?>
            <span class="badge b-warn">📄 Not Uploaded</span>
          <?php endif; ?>
        </div>

        <div style="height:12px"></div>

        <div class="docCard">
          <div class="cardTitle"><h3>Admin Actions</h3></div>

          <div class="btnRow">
            <form method="post" onsubmit="return confirm('Approve this examiner?');">
              <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="action" value="approve">
              <button class="btn primary" type="submit">✅ Approve</button>
            </form>

            <form method="post" onsubmit="return confirm('Reject this application?');" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
              <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="action" value="reject">
              <input type="text" name="reason" placeholder="Rejection reason..." required>
              <button class="btn danger" type="submit">❌ Reject</button>
            </form>

            <form method="post" onsubmit="return confirm('Block/disable this examiner account?');">
              <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="action" value="block">
              <button class="btn danger" type="submit">⛔ Block</button>
            </form>
          </div>

          <?php if ($missingDocs): ?>
            <div class="muted" style="margin-top:10px;">
              <b>Note:</b> This application has missing documents. Use “Send SMS” to remind the applicant.
            </div>
          <?php endif; ?>
        </div>

      </div>

    </div>
  </div>
</body>
</html>

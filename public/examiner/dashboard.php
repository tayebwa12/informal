<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_examiner.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_examiner();
$userId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ✅ Refresh user details from DB to avoid stale session display */
try {
  $st = $pdo->prepare("SELECT full_name, phone, email FROM users WHERE id=? LIMIT 1");
  $st->execute([$userId]);
  $fresh = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($fresh) $me = array_merge($me, $fresh);
} catch (Throwable $e) {}

/* =========================================================
   AJAX VCHECK (MUST BE BEFORE ANY HTML OUTPUT)
   ========================================================= */
if (isset($_GET['vcheck'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

  $oldVer = (int)($_GET['ver'] ?? 0);
  $newVer = 0;
  try {
    $st = $pdo->prepare("SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) FROM deployments WHERE examiner_user_id = ?");
    $st->execute([$userId]);
    $newVer = (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) { echo json_encode(['ok' => false]); exit; }

  $totalAll = 0; $completedAll = 0; $upcoming = 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) AS total_all, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed_all FROM deployments WHERE examiner_user_id = ? AND status IN ('active','completed')");
    $st->execute([$userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalAll = (int)($r['total_all'] ?? 0);
    $completedAll = (int)($r['completed_all'] ?? 0);

    $st = $pdo->prepare("SELECT COUNT(*) FROM deployments d JOIN timetable_sessions ts ON ts.id = d.timetable_session_id WHERE d.examiner_user_id = ? AND d.status IN ('active','completed') AND ts.session_date >= CURDATE()");
    $st->execute([$userId]);
    $upcoming = (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {}

  $eligibleClaimCount = 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM deployments d JOIN timetable_sessions ts ON ts.id = d.timetable_session_id WHERE d.examiner_user_id = ? AND d.status IN ('active','completed') AND ts.deployment_finished = 1");
    $st->execute([$userId]);
    $eligibleClaimCount = (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {}

  $changed = ($newVer !== $oldVer);
  $tbody = '';
  if ($changed) {
    try {
      $st = $pdo->prepare("SELECT d.status AS deploy_status, ts.session_date, ts.start_time, ts.end_time, COALESCE(es.name,'') AS series_name, c.center_number, c.center_name, c.location_name, dist.name AS district_name, reg.name AS region_name, o.code AS occupation_code, o.name AS occupation_name, depby.full_name AS deployed_by_name FROM deployments d JOIN timetable_sessions ts ON ts.id = d.timetable_session_id LEFT JOIN exam_series es ON es.id = ts.exam_series LEFT JOIN centers c ON c.id = ts.center_id LEFT JOIN districts dist ON dist.id = c.district_id LEFT JOIN regions reg ON reg.id = dist.region_id LEFT JOIN occupations o ON o.id = ts.occupation_id LEFT JOIN users depby ON depby.id = d.deployed_by_user_id WHERE d.examiner_user_id = ? AND d.status IN ('active','completed') ORDER BY ts.session_date ASC, ts.start_time ASC LIMIT 400");
      $st->execute([$userId]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      foreach ($rows as $d) {
        $pillClass = (strtolower((string)($d['deploy_status'] ?? '')) === 'completed') ? 'pill completed' : 'pill active';
        $loc2 = !empty($d['location_name']) ? '<div class="muted">'.h($d['location_name']).'</div>' : '';
        $tbody .= "<tr>
          <td>".h($d['session_date'])."</td>
          <td>".h(trim($d['start_time'].' - '.$d['end_time']))."</td>
          <td>".h($d['series_name'])."</td>
          <td><div><b>".h($d['center_number'].' — '.$d['center_name'])."</b></div><div class=\"muted\">".h($d['district_name'].' / '.$d['region_name'])."</div>{$loc2}</td>
          <td>".h($d['occupation_code'].' — '.$d['occupation_name'])."</td>
          <td>".h($d['deployed_by_name'])."</td>
          <td><span class=\"{$pillClass}\">".strtoupper($d['deploy_status'])."</span></td>
        </tr>";
      }
    } catch (Throwable $e) { $tbody = ''; }
  }

  echo json_encode([
    'ok' => true, 'version' => $newVer, 'changed' => $changed, 'tbody_html' => $tbody,
    'total_all' => $totalAll, 'completed_all' => $completedAll, 'upcoming' => $upcoming,
    'eligible_claim_count' => $eligibleClaimCount,
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

/* =========================================================
   AUTO RESUBMIT LOGIC
   ========================================================= */
function autoResubmitIfReady(PDO $pdo, int $userId): void {
  try {
    $st = $pdo->prepare("SELECT id, status, qualification_path, passport_photo_path FROM examiner_applications WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$userId]);
    $app = $st->fetch(PDO::FETCH_ASSOC);
    if (!$app) return;
    if (strtolower((string)$app['status']) !== 'rejected') return;
    if (trim((string)$app['qualification_path']) !== '' && trim((string)$app['passport_photo_path']) !== '') {
      $up = $pdo->prepare("UPDATE examiner_applications SET status='pending', rejection_reason=NULL, reviewed_by=NULL, reviewed_at=NULL, notes=CONCAT(COALESCE(notes,''), '\n[System] Auto re-submitted on ', NOW(), '.') WHERE id=? LIMIT 1");
      $up->execute([(int)$app['id']]);
    }
  } catch (Throwable $e) {}
}
autoResubmitIfReady($pdo, $userId);

/* =========================================================
   FETCH APP + REVIEWER + DETAILS
   ========================================================= */
$app = null; $reviewerName = '';
try {
  $st = $pdo->prepare("SELECT ea.*, ea.id AS app_id, ea.status AS app_status, o.code AS occupation_code, o.name AS occupation_name, c.center_number, c.center_name, dist.name AS district_name, reg.name AS region_name FROM examiner_applications ea LEFT JOIN occupations o ON o.id = ea.occupation_id LEFT JOIN centers c ON c.id = ea.center_id LEFT JOIN districts dist ON dist.id = c.district_id LEFT JOIN regions reg ON reg.id = dist.region_id WHERE ea.user_id=? ORDER BY ea.id DESC LIMIT 1");
  $st->execute([$userId]);
  $app = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($app && !empty($app['reviewed_by'])) {
    $rv = $pdo->prepare("SELECT full_name FROM users WHERE id=? LIMIT 1");
    $rv->execute([(int)$app['reviewed_by']]);
    $reviewerName = (string)($rv->fetchColumn() ?: '');
  }
} catch (Throwable $e) { $app = null; }

$appStatus = strtolower((string)($app['app_status'] ?? 'pending'));
$hasApp = (bool)$app;
$isRejected = ($appStatus === 'rejected');
$isApproved = ($appStatus === 'approved');
$isPending  = ($appStatus === 'pending' || $appStatus === '');

/* ACTIVE SERIES & STATS */
$activeSeries = null;
try {
  $st = $pdo->query("SELECT id, name FROM exam_series WHERE status='active' ORDER BY id DESC LIMIT 1");
  $activeSeries = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}

$stats = ['total_all' => 0, 'completed_all' => 0, 'upcoming' => 0, 'total_series' => 0, 'completed_series' => 0];
try {
  $st = $pdo->prepare("SELECT COUNT(*) AS total_all, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed_all FROM deployments WHERE examiner_user_id=? AND status IN ('active','completed')");
  $st->execute([$userId]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $stats['total_all'] = (int)($r['total_all'] ?? 0);
  $stats['completed_all'] = (int)($r['completed_all'] ?? 0);

  $st = $pdo->prepare("SELECT COUNT(*) FROM deployments d JOIN timetable_sessions ts ON ts.id = d.timetable_session_id WHERE d.examiner_user_id=? AND d.status IN ('active','completed') AND ts.session_date >= CURDATE()");
  $st->execute([$userId]);
  $stats['upcoming'] = (int)($st->fetchColumn() ?: 0);

  if ($activeSeries) {
    $st = $pdo->prepare("SELECT COUNT(*) AS total_series, SUM(CASE WHEN d.status='completed' THEN 1 ELSE 0 END) AS completed_series FROM deployments d JOIN timetable_sessions ts ON ts.id = d.timetable_session_id WHERE d.examiner_user_id=? AND d.status IN ('active','completed') AND ts.exam_series=?");
    $st->execute([$userId, (int)$activeSeries['id']]);
    $r2 = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['total_series'] = (int)($r2['total_series'] ?? 0);
    $stats['completed_series'] = (int)($r2['completed_series'] ?? 0);
  }
} catch (Throwable $e) {}

$eligibleClaimCount = 0;
try {
  $st = $pdo->prepare("SELECT COUNT(*) FROM deployments d JOIN timetable_sessions ts ON ts.id = d.timetable_session_id WHERE d.examiner_user_id = ? AND d.status IN ('active','completed') AND ts.deployment_finished = 1");
  $st->execute([$userId]);
  $eligibleClaimCount = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {}
$claimEnabled = ($isApproved && $eligibleClaimCount > 0);

/* DEPLOYMENTS LIST */
$deployments = [];
try {
  $st = $pdo->prepare("SELECT d.status AS deploy_status, ts.session_date, ts.start_time, ts.end_time, COALESCE(es.name,'') AS series_name, c.center_number, c.center_name, c.location_name, dist.name AS district_name, reg.name AS region_name, o.code AS occupation_code, o.name AS occupation_name, depby.full_name AS deployed_by_name FROM deployments d JOIN timetable_sessions ts ON ts.id = d.timetable_session_id LEFT JOIN exam_series es ON es.id = ts.exam_series LEFT JOIN centers c ON c.id = ts.center_id LEFT JOIN districts dist ON dist.id = c.district_id LEFT JOIN regions reg ON reg.id = dist.region_id LEFT JOIN occupations o ON o.id = ts.occupation_id LEFT JOIN users depby ON depby.id = d.deployed_by_user_id WHERE d.examiner_user_id=? AND d.status IN ('active','completed') ORDER BY ts.session_date ASC, ts.start_time ASC LIMIT 400");
  $st->execute([$userId]);
  $deployments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$deployVersion = 0;
try {
  $st = $pdo->prepare("SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) FROM deployments WHERE examiner_user_id=?");
  $st->execute([$userId]);
  $deployVersion = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {}

/* UI DATA PREP */
$publicPrefix = "/public/";
$passportUrl = !empty($app['passport_photo_path']) ? $publicPrefix . ltrim($app['passport_photo_path'], '/') : '';
$qualUrl = !empty($app['qualification_path']) ? $publicPrefix . ltrim($app['qualification_path'], '/') : '';
$nm = trim((string)($me['full_name'] ?? 'E'));
$initials = 'E';
if ($nm !== '') {
  $parts = preg_split('/\s+/', $nm);
  $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

$applyUrl = "/public/apply_examiner.php";
$pfName = (string)($app['app_full_name'] ?? ($me['full_name'] ?? '—'));
$pfPhone = (string)($app['app_phone'] ?? ($me['phone'] ?? '—'));
$pfEmail = (string)($app['app_email'] ?? ($me['email'] ?? '—'));
$pfNin = (string)($app['nin'] ?? '—');
$occ = trim((string)($app['occupation_code'] ?? '') . ' — ' . (string)($app['occupation_name'] ?? ''));
$pfOccupation = ($occ !== '—' && $occ !== '') ? $occ : (string)($app['occupation_name'] ?? '—');
$pfDistrict = (string)($app['district_name'] ?? '—') . (!empty($app['region_name']) ? ' / ' . $app['region_name'] : '');

$org = trim((string)($app['organisation_name'] ?? ''));
$cn = trim((string)($app['center_number'] ?? ''));
$nmC = trim((string)($app['center_name'] ?? ''));
$pfCenter = '—';
if ($org !== '') $pfCenter = 'World of Work — ' . $org;
elseif ($cn !== '' || $nmC !== '') $pfCenter = trim($cn . ' — ' . $nmC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Assessor Dashboard | Professional</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --primary: #2563eb; --primary-hover: #1d4ed8; --bg: #f1f5f9; --card: #ffffff;
      --text: #0f172a; --muted: #64748b; --success: #10b981; --danger: #ef4444;
      --warning: #f59e0b; --shadow: 0 10px 15px -3px rgba(0,0,0,0.1); --radius: 16px;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; }
    
    /* Header */
    .header { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: white; padding: 2rem 1.5rem; }
    .header-content { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.5rem; }
    .user-meta { display: flex; align-items: center; gap: 1.25rem; }
    .avatar { width: 70px; height: 70px; border-radius: 50%; border: 3px solid rgba(255,255,255,0.2); overflow: hidden; background: #334155; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 800; }
    .avatar img { width: 100%; height: 100%; object-fit: cover; }
    .header-title h1 { margin: 0; font-size: 1.75rem; font-weight: 800; letter-spacing: -0.025em; }
    .header-title p { margin: 4px 0 0; opacity: 0.8; font-weight: 500; }
    
    .btn { display: inline-flex; align-items: center; gap: 8px; padding: 0.75rem 1.25rem; border-radius: 10px; font-weight: 700; cursor: pointer; text-decoration: none; transition: all 0.2s; border: none; font-size: 0.9rem; }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); }
    .btn-outline { background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(255,255,255,0.2); }
    .btn-outline:hover { background: rgba(255,255,255,0.2); }

    /* Dashboard Grid */
    .container { max-width: 1200px; margin: -2rem auto 3rem; padding: 0 1.5rem; }
    .grid { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 1.5rem; }
    @media (max-width: 992px) { .grid { grid-template-columns: 1fr; } .container { margin-top: 1.5rem; } }

    .card { background: var(--card); border-radius: var(--radius); padding: 1.5rem; box-shadow: var(--shadow); border: 1px solid #e2e8f0; }
    .card-h3 { margin: 0 0 1.25rem; font-size: 1.1rem; font-weight: 800; display: flex; align-items: center; justify-content: space-between; }
    
    /* Stats */
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
    @media (max-width: 600px) { .stats-grid { grid-template-columns: 1fr; } }
    .stat-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 1.25rem; border-radius: 12px; }
    .stat-label { font-size: 0.75rem; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
    .stat-val { font-size: 1.75rem; font-weight: 900; color: var(--text); display: block; margin: 4px 0; }
    
    /* Badges */
    .badge { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; }
    .b-approved { background: #dcfce7; color: #166534; }
    .b-rejected { background: #fee2e2; color: #991b1b; }
    .b-pending { background: #fef3c7; color: #92400e; }

    /* Tables */
    .table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid #e2e8f0; margin-top: 1rem; }
    table { width: 100%; border-collapse: collapse; min-width: 900px; }
    th { background: #f8fafc; text-align: left; padding: 1rem; font-size: 0.75rem; font-weight: 700; color: var(--muted); text-transform: uppercase; }
    td { padding: 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
    .pill { padding: 4px 12px; border-radius: 99px; font-size: 0.7rem; font-weight: 800; }
    .pill.active { background: #e0f2fe; color: #0369a1; }
    .pill.completed { background: #dcfce7; color: #15803d; }

    /* Forms & KV */
    .kv { display: flex; justify-content: space-between; padding: 0.75rem 0; border-bottom: 1px solid #f1f5f9; }
    .kv:last-child { border-bottom: none; }
    .k { color: var(--muted); font-weight: 600; font-size: 0.85rem; }
    .v { font-weight: 700; text-align: right; font-size: 0.85rem; }
    
    .notice { padding: 1rem; border-radius: 12px; margin-top: 1rem; font-size: 0.9rem; border: 1px solid transparent; }
    .notice.rej { background: #fff1f2; border-color: #fecaca; color: #991b1b; }
    .notice.pend { background: #fffbeb; border-color: #fef3c7; color: #92400e; }
    .notice.ok { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }

    .toast { position: fixed; bottom: 2rem; right: 2rem; background: #1e293b; color: white; padding: 1rem 1.5rem; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); display: none; z-index: 1000; font-weight: 700; }
    .toast.show { display: block; animation: slideUp 0.3s ease-out; }
    @keyframes slideUp { from { transform: translateY(100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
  </style>
</head>
<body>

<div id="toast" class="toast">Update Received</div>

<header class="header">
  <div class="header-content">
    <div class="user-meta">
      <div class="avatar">
        <?php if($passportUrl): ?><img src="<?= h($passportUrl) ?>" alt="User"><?php else: ?><?= $initials ?><?php endif; ?>
      </div>
      <div class="header-title">
        <h1>Assessors Dashboard</h1>
        <p>Welcome back, <?= h($me['full_name'] ?? 'Assessor') ?></p>
      </div>
    </div>
    <div style="display: flex; gap: 10px;">
      <a href="<?= h($applyUrl) ?>" class="btn btn-outline"><?= $isApproved ? 'Update your Profile' : 'Complete Application' ?></a>
      <a href="../logout.php" class="btn btn-outline" style="background: var(--danger); border: none;">Logout</a>
    </div>
  </div>
</header>

<main class="container">
  <div class="grid">
    
    <section class="card">
      <div class="card-h3">
        <span>Application Profile</span>
        <span class="badge <?= $isApproved ? 'b-approved' : ($isRejected ? 'b-rejected' : 'b-pending') ?>">
          <?= strtoupper($appStatus) ?>
        </span>
      </div>

      <?php if(!$hasApp): ?>
        <div class="notice pend">No application found. Please complete your registration.</div>
      <?php else: ?>
        <div class="kv"><span class="k">Full Name</span><span class="v"><?= h($pfName) ?></span></div>
        <div class="kv"><span class="k">Phone</span><span class="v"><?= h($pfPhone) ?></span></div>
        <div class="kv"><span class="k">NIN</span><span class="v"><?= h($pfNin) ?></span></div>
        <div class="kv"><span class="k">Occupation</span><span class="v"><?= h($pfOccupation) ?></span></div>
        <div class="kv"><span class="k">Center / Org</span><span class="v"><?= h($pfCenter) ?></span></div>
        <div class="kv"><span class="k">Reviewer</span><span class="v"><?= h($reviewerName ?: 'System Pending') ?></span></div>

        <div style="margin-top: 1.5rem;">
          <label style="font-size: 0.75rem; font-weight: 800; color: var(--muted); text-transform: uppercase;">Admin Comments</label>
          <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; margin-top: 0.5rem; font-size: 0.85rem;">
            <?= !empty($app['notes']) ? nl2br(h($app['notes'])) : '<span class="muted">No admin notes yet.</span>' ?>
          </div>
        </div>

        <?php if($isRejected): ?>
          <div class="notice rej"><b>Rejected:</b> <?= h($app['rejection_reason'] ?? 'Please fix errors and resubmit.') ?></div>
        <?php elseif($isPending): ?>
          <div class="notice pend">Pending verification. You may edit your details in the meantime.</div>
        <?php else: ?>
          <div class="notice ok">Application Approved. You are eligible for deployment and claims.</div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <aside style="display: flex; flex-direction: column; gap: 1.5rem;">
      <section class="card">
        <div class="card-h3">Performance Stats</div>
        <div class="stats-grid">
          <div class="stat-box">
            <span class="stat-label">Total</span>
            <span id="stat_total" class="stat-val"><?= $stats['total_all'] ?></span>
          </div>
          <div class="stat-box">
            <span class="stat-label">Done</span>
            <span id="stat_completed" class="stat-val"><?= $stats['completed_all'] ?></span>
          </div>
          <div class="stat-box">
            <span class="stat-label">Upcoming</span>
            <span id="stat_upcoming" class="stat-val"><?= $stats['upcoming'] ?></span>
          </div>
        </div>
      </section>

      <section class="card" style="background: #f8fafc;">
        <div class="card-h3">Payments & Claims</div>
        <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 1.5rem;">
          Claim forms are generated automatically once your deployment is marked as <b>Finished</b> by the presiding officer.
        </p>
        <?php if($claimEnabled): ?>
          <a href="claim_form.php" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">Generate Claim Form</a>
        <?php else: ?>
          <button class="btn" style="width: 100%; justify-content: center; background: #e2e8f0; color: #94a3b8; cursor: not-allowed;" onclick="alert('Claim Locked: Application must be APPROVED and Officer must mark session as FINISHED.')">Claim Locked</button>
        <?php endif; ?>
      </aside>
    </aside>

    <section class="card" style="grid-column: 1 / -1;">
      <div class="card-h3">Deployment Schedule</div>
      <?php if(!$deployments): ?>
        <p class="muted">No deployments found for your account.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Time</th>
                <th>Series</th>
                <th>Center & Location</th>
                <th>Paper</th>
                <th>Assigned By</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="deploy_body">
              <?php foreach($deployments as $d): ?>
                <tr>
                  <td><b><?= h($d['session_date']) ?></b></td>
                  <td><?= h($d['start_time'].' - '.$d['end_time']) ?></td>
                  <td><?= h($d['series_name']) ?></td>
                  <td>
                    <div><b><?= h($d['center_number'].' — '.$d['center_name']) ?></b></div>
                    <div class="muted"><?= h($d['district_name'].' / '.$d['region_name']) ?></div>
                  </td>
                  <td><?= h($d['occupation_code'].' — '.$d['occupation_name']) ?></td>
                  <td><?= h($d['deployed_by_name'] ?? '—') ?></td>
                  <td><span class="pill <?= strtolower($d['deploy_status'])==='completed'?'completed':'active'?>"><?= strtoupper($d['deploy_status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>

<script>
(function(){
  const toast = document.getElementById('toast');
  const body = document.getElementById('deploy_body');
  let version = <?= (int)$deployVersion ?>;

  function showToast(msg){
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3500);
  }

  async function poll(){
    try {
      const res = await fetch(window.location.pathname + '?vcheck=1&ver=' + version);
      const data = await res.json();
      if(!data || !data.ok) return;

      if(document.getElementById('stat_total')) document.getElementById('stat_total').textContent = data.total_all;
      if(document.getElementById('stat_completed')) document.getElementById('stat_completed').textContent = data.completed_all;
      if(document.getElementById('stat_upcoming')) document.getElementById('stat_upcoming').textContent = data.upcoming;

      if(data.changed && data.version > version) {
        version = data.version;
        if(body) body.innerHTML = data.tbody_html;
        showToast('🔔 Deployment Schedule Updated');
      }
    } catch(e) {}
  }
  setInterval(poll, 15000);
})();
</script>
</body>
</html>
<?php
declare(strict_types=1);

// Debugging (Enable to see errors, disable for production)
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
$userId = (int)($me['id'] ?? 0);
$officerName = (string)($me['full_name'] ?? 'Officer');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tableExists(PDO $pdo, string $table): bool {
  $table = trim($table);
  if ($table === '') return false;
  if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
  $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
  return (bool)$st->fetchColumn();
}
function makeInPlaceholders(array $ids): string {
  return implode(',', array_fill(0, count($ids), '?'));
}

try {
  /** ---------------- REQUIRED TABLES ---------------- */
  $required = [
    'officer_assignments','exam_series','regions',
    'cluster_officers','district_clusters','cluster_districts',
    'centers','districts','timetable_sessions',
    'users','examiner_applications','deployments','occupations'
  ];
  $missing = [];
  foreach ($required as $t) if (!tableExists($pdo, $t)) $missing[] = $t;

  if ($missing) {
    header('Content-Type: text/html; charset=utf-8');
    die("<div style='padding:3rem;font-family:system-ui'>
      <h2 style='color:#b00020'>Missing required table(s)</h2>
      <p>Missing: <b>".h(implode(', ', $missing))."</b></p>
      <p>DB: <b>".h((string)$pdo->query('SELECT DATABASE()')->fetchColumn())."</b></p>
    </div>");
  }

  /** ---------------- OFFICER ASSIGNMENT ---------------- */
  $st = $pdo->prepare("
    SELECT oa.exam_series_id, oa.region_id, es.name AS series_name, r.name AS region_name
    FROM officer_assignments oa
    JOIN exam_series es ON es.id = oa.exam_series_id
    JOIN regions r ON r.id = oa.region_id
    WHERE oa.user_id = ? AND oa.status = 'active'
    ORDER BY oa.id DESC LIMIT 1
  ");
  $st->execute([$userId]);
  $assignment = $st->fetch(PDO::FETCH_ASSOC);

  if (!$assignment) {
    die("<div style='padding:4rem; text-align:center; font-family:sans-serif;'>
      <h2>No Active Assignment</h2>
      <p>Contact Admin to link your account to a region & series.</p>
    </div>");
  }

  /** ---------------- SERIES LIST (for dropdown) ---------------- */
  $seriesList = $pdo->query("SELECT id, name FROM exam_series ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

  /** ---------------- OFFICER CLUSTERS ---------------- */
  $st = $pdo->prepare("
    SELECT dc.id, dc.name
    FROM cluster_officers co
    JOIN district_clusters dc ON dc.id = co.cluster_id
    WHERE co.officer_user_id = ?
    ORDER BY dc.name ASC
  ");
  $st->execute([$userId]);
  $officerClusters = $st->fetchAll(PDO::FETCH_ASSOC);

  if (!$officerClusters) {
    die("<div style='padding:4rem; text-align:center; font-family:sans-serif;'>
      <h2>No Cluster Assigned</h2>
      <p>Contact Admin to assign you to a district cluster.</p>
    </div>");
  }

  $requestedClusterId = (int)($_GET['cluster_id'] ?? 0);
  $allowedClusterIds  = array_map('intval', array_column($officerClusters, 'id'));

  $activeClusterId = 0;
  if ($requestedClusterId > 0 && in_array($requestedClusterId, $allowedClusterIds, true)) {
    $activeClusterId = $requestedClusterId;
    $clusterIdsToUse = [$activeClusterId];
  } else {
    $clusterIdsToUse = $allowedClusterIds;
  }

  $inClusters = makeInPlaceholders($clusterIdsToUse);
  $st = $pdo->prepare("
    SELECT DISTINCT cd.district_id
    FROM cluster_districts cd
    WHERE cd.cluster_id IN ($inClusters)
  ");
  $st->execute($clusterIdsToUse);
  $districtIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

  if (!$districtIds) {
    die("<div style='padding:4rem; text-align:center; font-family:sans-serif;'>
      <h2>Cluster Has No Districts</h2>
      <p>The assigned cluster(s) have no districts yet. Ask Admin to add districts to your cluster.</p>
    </div>");
  }

  /** ---------------- VIEW SETTINGS ---------------- */
  $selectedSeriesId = (int)($_GET['series_id'] ?? (int)$assignment['exam_series_id']);
  $centerId = (int)($_GET['center_id'] ?? 0);
  $centersQ = trim((string)($_GET['centers_q'] ?? ''));

  $inDistricts = makeInPlaceholders($districtIds);

  /** ---------------- STATS ---------------- */
  $st = $pdo->prepare("
    SELECT COUNT(DISTINCT u.id)
    FROM users u
    LEFT JOIN examiner_applications ea ON (ea.user_id = u.id OR ea.phone = u.phone)
    WHERE u.role = 'examiner'
      AND u.status = 'active'
      AND ea.district_id IN ($inDistricts)
      AND (ea.status IS NULL OR ea.status <> 'blocked')
  ");
  $st->execute($districtIds);
  $totalClusterPool = (int)$st->fetchColumn();

  $totalGlobalApproved = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='examiner' AND status='active'")->fetchColumn();

  /** ---------------- CENTERS LIST (FIXED) ----------------
   * ✅ Uses exam_series_id
   * ✅ Shows centers with sessions even if not deployed
   */
  $params = array_merge($districtIds, [$selectedSeriesId]);

  $searchFilter = "";
  if ($centersQ !== '') {
    $searchFilter = " AND (c.center_number LIKE ? OR c.center_name LIKE ?)";
    $params[] = "%$centersQ%";
    $params[] = "%$centersQ%";
  }

  $st = $pdo->prepare("
    SELECT
      c.id,
      c.center_number,
      c.center_name,
      COUNT(ts.id) AS session_count
    FROM centers c
    JOIN timetable_sessions ts ON ts.center_id = c.id
    WHERE c.district_id IN ($inDistricts)
      AND ts.exam_series_id = ?
      AND COALESCE(ts.status,'active') = 'active'
      AND COALESCE(ts.deployment_finished,0) = 0
      $searchFilter
    GROUP BY c.id
    ORDER BY c.center_number ASC
  ");
  $st->execute($params);
  $centers = $st->fetchAll(PDO::FETCH_ASSOC);

  /** ---------------- DEBUG: sessions exist but not visible due to district coverage ---------------- */
  $hiddenCenters = [];
  $totalSeriesSessions = 0;

  // Count sessions in the whole series (no district filter)
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM timetable_sessions ts
    WHERE ts.exam_series_id = ?
      AND COALESCE(ts.status,'active') = 'active'
      AND COALESCE(ts.deployment_finished,0) = 0
  ");
  $st->execute([$selectedSeriesId]);
  $totalSeriesSessions = (int)$st->fetchColumn();

  // If user sees none, show sample of "hidden centers" outside their district coverage
  if ($totalSeriesSessions > 0 && !$centers) {
    $st = $pdo->prepare("
      SELECT
        c.id,
        c.center_number,
        c.center_name,
        c.district_id,
        COUNT(ts.id) AS session_count
      FROM centers c
      JOIN timetable_sessions ts ON ts.center_id = c.id
      WHERE ts.exam_series_id = ?
        AND COALESCE(ts.status,'active') = 'active'
        AND COALESCE(ts.deployment_finished,0) = 0
        AND c.district_id NOT IN ($inDistricts)
      GROUP BY c.id, c.district_id
      ORDER BY session_count DESC
      LIMIT 8
    ");
    $st->execute(array_merge([$selectedSeriesId], $districtIds));
    $hiddenCenters = $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /** ---------------- SESSIONS & DEPLOYMENTS (FIXED) ---------------- */
  $sessions = [];

  if ($centerId > 0) {
    // Safety: ensure selected center is within officer's cluster districts
    $st = $pdo->prepare("SELECT COUNT(*) FROM centers WHERE id=? AND district_id IN ($inDistricts) LIMIT 1");
    $st->execute(array_merge([$centerId], $districtIds));
    if ((int)$st->fetchColumn() !== 1) $centerId = 0;
  }

  if ($centerId > 0) {
    $st = $pdo->prepare("
      SELECT
        ts.*,
        o.name AS occupation_name,

        (
          SELECT COUNT(*)
          FROM deployments d
          WHERE d.timetable_session_id = ts.id
            AND d.status <> 'cancelled'
            AND COALESCE(d.response_status,'') <> 'cancelled'
            AND d.completed_at IS NULL
        ) AS active_deployed,

        (
          SELECT COUNT(DISTINCT u.id)
          FROM users u
          LEFT JOIN examiner_applications ea ON (ea.user_id = u.id OR ea.phone = u.phone)
          WHERE u.role = 'examiner'
            AND u.status = 'active'
            AND (ea.status IS NULL OR ea.status <> 'blocked')
            AND ea.occupation_id = ts.occupation_id
            AND ea.district_id IN ($inDistricts)
            AND u.id NOT IN (
              SELECT dep.examiner_user_id
              FROM deployments dep
              JOIN timetable_sessions ts2 ON ts2.id = dep.timetable_session_id
              WHERE ts2.exam_series_id = ?
                AND ts2.session_date = ts.session_date
                AND ts2.start_time = ts.start_time
                AND dep.status <> 'cancelled'
                AND COALESCE(dep.response_status,'') <> 'cancelled'
                AND dep.completed_at IS NULL
            )
        ) AS qualified_pool,

        (
          SELECT GROUP_CONCAT(COALESCE(u.full_name, ea.full_name) SEPARATOR ', ')
          FROM deployments dep
          LEFT JOIN users u ON u.id = dep.examiner_user_id
          LEFT JOIN examiner_applications ea ON (ea.user_id = u.id OR ea.phone = u.phone)
          WHERE dep.timetable_session_id = ts.id
            AND dep.status <> 'cancelled'
            AND COALESCE(dep.response_status,'') <> 'cancelled'
            AND dep.completed_at IS NULL
        ) AS assigned_names

      FROM timetable_sessions ts
      LEFT JOIN occupations o ON o.id = ts.occupation_id
      WHERE ts.center_id = ?
        AND ts.exam_series_id = ?
        AND COALESCE(ts.status,'active') = 'active'
        AND COALESCE(ts.deployment_finished,0) = 0
      ORDER BY ts.session_date ASC, ts.start_time ASC
    ");

    // Placeholder order in SQL:
    // - IN ($inDistricts) => districtIds...
    // - ts2.exam_series_id = ? => selectedSeriesId
    // - ts.center_id = ? => centerId
    // - ts.exam_series_id = ? => selectedSeriesId
    $st->execute(array_merge($districtIds, [$selectedSeriesId, $centerId, $selectedSeriesId]));
    $sessions = $st->fetchAll(PDO::FETCH_ASSOC);
  }

} catch (PDOException $e) {
  die("Database Error: " . h($e->getMessage()));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Officer Dashboard | UVTAB</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root { --primary:#4f46e5; --primary-light:#eef2ff; --bg:#f8fafc; --card:#ffffff; --border:#e2e8f0; --text:#1e293b; --success:#10b981; --radius:12px; }
    body { background: var(--bg); color: var(--text); font-family: 'Plus Jakarta Sans', sans-serif; margin: 0; -webkit-font-smoothing: antialiased; }
    .container { max-width: 1440px; margin: 0 auto; padding: 2rem; }
    .user-badge { background: var(--primary-light); color: var(--primary); padding: 6px 14px; border-radius: 99px; font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 0.5rem; }
    .grid-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.2rem; gap: 16px; flex-wrap: wrap; }
    .stats-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1rem; }
    .stat-card { background: var(--card); padding: 1.5rem; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); transition: transform 0.2s; }
    .stat-card:hover { transform: translateY(-2px); }
    .stat-val { font-size: 2.5rem; font-weight: 800; color: var(--text); margin: 5px 0; }

    .dashboard-grid { display: grid; grid-template-columns: 380px 1fr; gap: 2rem; }
    .card { background: #fff; border-radius: var(--radius); border: 1px solid var(--border); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .card-header { padding: 1.2rem; border-bottom: 1px solid var(--border); background: #fff; font-weight: 800; color: var(--text); display: flex; justify-content: space-between; align-items: center; }

    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; padding: 14px; background: #fcfdfe; font-size: 11px; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; border-bottom: 1px solid var(--border); }
    td { padding: 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }

    .center-row { cursor: pointer; transition: 0.2s; }
    .center-row:hover { background: #f8fafc; }
    .active-row { background: #f5f3ff !important; border-left: 4px solid var(--primary); }

    .badge-pool { background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 11px; border: 1px solid #bbf7d0; }
    .badge-empty { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 11px; border: 1px solid #fecaca; }
    .assigned-tag { display: inline-flex; align-items: center; background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; margin: 2px; border: 1px solid var(--border); }

    .btn { padding: 10px 20px; border-radius: 8px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; font-size: 13px; transition: 0.2s; cursor: pointer; border: none; }
    .btn-primary { background: var(--primary); color: white; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2); }
    .btn-primary:hover { background: #4338ca; }
    .btn-outline { background: white; border: 1px solid var(--border); color: var(--text); }
    .btn-outline:hover { background: #f8fafc; }
    .btn-success { background: var(--success); color: white; }

    .muted { color: #64748b; font-size: 0.85rem; }
    .search-input { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 14px; }
    .search-input:focus { outline: 2px solid var(--primary-light); border-color: var(--primary); }

    .cluster-select { padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 14px; background:#fff; }
    .cluster-wrap { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

    .warn {
      background: #fff7ed;
      border: 1px solid #fed7aa;
      color: #9a3412;
      padding: 14px 16px;
      border-radius: 12px;
      margin: 0 0 1rem 0;
      font-size: 13px;
    }
    .warn b { color:#7c2d12; }
    .mini { font-size: 12px; color:#7c2d12; margin-top:6px; }
    @media(max-width: 1100px){ .dashboard-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>

<div class="container">
  <header class="grid-header">
    <div>
      <div class="user-badge"><span style="font-size: 14px;">👋</span> Welcome back, <?= h($officerName) ?></div>
      <h1 style="margin:0; font-size: 2.2rem; font-weight: 800; letter-spacing: -1px; color: #0f172a;">Officer Dashboard</h1>

      <p class="muted">
        <span style="color: var(--primary); font-weight: 700;"><?= h($assignment['region_name']) ?></span>
        &bull; Assigned series: <b><?= h($assignment['series_name']) ?></b>
        <?php if ($activeClusterId > 0): ?>
          &bull; Cluster: <b><?= h((string)($officerClusters[array_search($activeClusterId, array_map('intval', array_column($officerClusters,'id')), true)]['name'] ?? 'Selected')) ?></b>
        <?php else: ?>
          &bull; Cluster: <b>All Assigned</b>
        <?php endif; ?>
      </p>

      <div class="cluster-wrap" style="margin-top:10px;">
        <!-- Cluster filter -->
        <form method="get" class="cluster-wrap">
          <input type="hidden" name="series_id" value="<?= (int)$selectedSeriesId ?>">
          <input type="hidden" name="centers_q" value="<?= h((string)($_GET['centers_q'] ?? '')) ?>">
          <input type="hidden" name="center_id" value="0">
          <label class="muted" style="font-weight:700;">Filter by Cluster:</label>
          <select name="cluster_id" class="cluster-select" onchange="this.form.submit()">
            <option value="0">All my clusters</option>
            <?php foreach ($officerClusters as $cl): ?>
              <option value="<?= (int)$cl['id'] ?>" <?= $activeClusterId === (int)$cl['id'] ? 'selected' : '' ?>>
                <?= h($cl['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <!-- Series selector -->
        <form method="get" class="cluster-wrap">
          <input type="hidden" name="cluster_id" value="<?= (int)$activeClusterId ?>">
          <input type="hidden" name="centers_q" value="<?= h($centersQ) ?>">
          <input type="hidden" name="center_id" value="0">
          <label class="muted" style="font-weight:700;">Series:</label>
          <select name="series_id" class="cluster-select" onchange="this.form.submit()">
            <?php foreach ($seriesList as $sx): ?>
              <option value="<?= (int)$sx['id'] ?>" <?= ((int)$sx['id'] === (int)$selectedSeriesId) ? 'selected' : '' ?>>
                <?= h($sx['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>

    <div style="display:flex; gap: 12px; flex-wrap:wrap;">
      <a href="export_deployed.php?series_id=<?= (int)$selectedSeriesId ?>" class="btn btn-success">📥 Export Deployed</a>
      <a href="message_center.php" class="btn btn-outline">Messages</a>
      <a href="view_applicants.php" class="btn btn-outline">Applicants</a>
      <a href="../logout.php" class="btn btn-outline" style="color: #dc2626; border-color: #fecaca; background: #fff1f2;">Logout</a>
    </div>
  </header>

  <?php if ($totalSeriesSessions > 0 && !$centers): ?>
    <div class="warn">
      <b>Why you are not seeing sessions:</b> There are <b><?= (int)$totalSeriesSessions ?></b> sessions in this series,
      but none belong to districts in your cluster coverage.
      <div class="mini">
        Your allowed district IDs: <b><?= h(implode(', ', $districtIds)) ?></b>
      </div>
      <?php if ($hiddenCenters): ?>
        <div class="mini" style="margin-top:10px;">
          Example centers with sessions (but outside your coverage):
          <ul style="margin:8px 0 0 18px; padding:0;">
            <?php foreach ($hiddenCenters as $hc): ?>
              <li>
                <b><?= h($hc['center_number']) ?></b> — <?= h($hc['center_name']) ?>
                (district_id: <?= (int)$hc['district_id'] ?>, sessions: <?= (int)$hc['session_count'] ?>)
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <div class="mini" style="margin-top:10px;">
        ✅ Fix: Add the missing district(s) into your cluster in <b>cluster_districts</b>, or correct the center’s district mapping in <b>centers</b>.
      </div>
    </div>
  <?php endif; ?>

  <div class="stats-strip">
    <div class="stat-card">
      <div class="muted" style="font-weight: 700; text-transform: uppercase; font-size: 10px; letter-spacing: 0.1em;">Cluster Examiner Pool</div>
      <div class="stat-val"><?= (int)$totalClusterPool ?></div>
      <a href="view_examiners_region.php" class="btn btn-primary" style="margin-top:10px; width:100%; justify-content:center;">Manage Pool</a>
      <div class="muted" style="margin-top:10px;">Counts examiners whose <b>application district</b> is inside your cluster coverage.</div>
    </div>

    <div class="stat-card">
      <div class="muted" style="font-weight: 700; text-transform: uppercase; font-size: 10px; letter-spacing: 0.1em;">Global Approved Pool</div>
      <div class="stat-val"><?= (int)$totalGlobalApproved ?></div>
      <div class="muted" style="margin-top:10px;">Total active examiners across all regions</div>
    </div>
  </div>

  <div class="dashboard-grid">
    <div class="card">
      <div class="card-header">Centers in Your Cluster Coverage</div>

      <div style="padding: 1rem; border-bottom: 1px solid var(--border);">
        <form method="GET">
          <input type="hidden" name="center_id" value="<?= (int)$centerId ?>">
          <input type="hidden" name="series_id" value="<?= (int)$selectedSeriesId ?>">
          <input type="hidden" name="cluster_id" value="<?= (int)$activeClusterId ?>">
          <input type="text" name="centers_q" value="<?= h($centersQ) ?>" class="search-input" placeholder="Find center by name or code...">
        </form>
      </div>

      <div style="max-height: 600px; overflow-y: auto;">
        <table>
          <thead><tr><th>Center Details</th><th style="text-align:right;">Pending Sessions</th></tr></thead>
          <tbody>
          <?php foreach ($centers as $c): ?>
            <tr class="center-row <?= $centerId === (int)$c['id'] ? 'active-row' : '' ?>"
                onclick="window.location.href='?center_id=<?= (int)$c['id'] ?>&centers_q=<?= h($centersQ) ?>&series_id=<?= (int)$selectedSeriesId ?>&cluster_id=<?= (int)$activeClusterId ?>'">
              <td>
                <strong style="color: #1e293b;"><?= h($c['center_number']) ?></strong><br>
                <small class="muted" style="font-size: 10px;"><?= h($c['center_name']) ?></small>
              </td>
              <td style="text-align:right;">
                <span style="background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-weight:800; font-size: 12px;"><?= (int)$c['session_count'] ?></span>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if(!$centers): ?>
            <tr><td colspan="2" class="muted" style="text-align:center; padding: 30px;">No matching centers found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <?php if ($centerId > 0): ?>
        <div class="card-header">
          <span>Timetable & Deployment Control</span>
          <span style="font-size: 12px; color: var(--primary); background: var(--primary-light); padding: 4px 10px; border-radius: 6px;">
            Center ID: <?= (int)$centerId ?>
          </span>
        </div>

        <table>
          <thead>
          <tr>
            <th>Date & Time</th>
            <th>Occupation</th>
            <th>Pool Status</th>
            <th>Current Staff</th>
            <th style="text-align:right;">Action</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($sessions as $s): ?>
            <tr>
              <td>
                <div style="font-weight: 800; color: #0f172a;"><?= date("d M Y", strtotime((string)$s['session_date'])) ?></div>
                <div class="muted" style="font-size: 11px; font-weight: 600;"><?= substr((string)$s['start_time'],0,5) ?> HRS</div>
              </td>
              <td>
                <div style="font-weight:700; font-size: 13px;"><?= h($s['occupation_name'] ?: 'N/A') ?></div>
              </td>
              <td>
                <?php if((int)$s['qualified_pool'] > 0): ?>
                  <span class="badge-pool"><?= (int)$s['qualified_pool'] ?> Available</span>
                <?php else: ?>
                  <span class="badge-empty">None Available</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if(!empty($s['assigned_names'])): ?>
                  <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                    <?php foreach(explode(', ', (string)$s['assigned_names']) as $name): ?>
                      <div class="assigned-tag"><span style="margin-right: 5px; opacity: 0.5;">👤</span> <?= h($name) ?></div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span style="color:#cbd5e1; font-size: 11px; font-style: italic;">Unstaffed</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <a href="deploy_session.php?session_id=<?= (int)$s['id'] ?>" class="btn btn-primary">Deploy</a>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if(!$sessions): ?>
            <tr><td colspan="5" class="muted" style="text-align:center; padding: 100px;">No pending sessions found for this center.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div style="padding: 140px 20px; text-align:center; color:#94a3b8;">
          <div style="font-size: 60px; margin-bottom: 20px; opacity: 0.3;">🏫</div>
          <h3 style="color: #64748b; margin-bottom: 5px;">Ready to Staff Centers</h3>
          <p>Please select a center from the left panel to begin examiner deployment.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>
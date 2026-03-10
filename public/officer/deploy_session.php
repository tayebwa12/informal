<?php
declare(strict_types=1);

// 1) Error Reporting (disable in production)
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
$officerId = (int)($me['id'] ?? 0);

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) { die("Invalid Session ID"); }

/** 0=STRICT (cluster only), 1=allow pool from all districts (fallback; only if still short) */
$showAllRegions = (int)($_GET['all_regions'] ?? 0);

/** 0=strict occupation, 1=show ALL occupations (cluster only) */
$showSimilar = (int)($_GET['similar'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tableExists(PDO $pdo, string $table): bool {
    $table = trim($table);
    if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
    return (bool)$st->fetchColumn();
}
function inPlaceholders(int $n): string {
    return implode(',', array_fill(0, max(1, $n), '?'));
}

/* ---------------- CSRF TOKEN ---------------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['csrf_token'];

/* Optional flash */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

try {
    /** ---------------- Required tables (cluster-aware) ---------------- */
    $required = [
        'timetable_sessions','centers','districts','regions','occupations',
        'users','deployments','examiner_applications',
        'officer_assignments',
        'district_clusters','cluster_districts','cluster_officers',
        'candidate_ratios'
    ];
    $missing = [];
    foreach ($required as $t) {
        if (!tableExists($pdo, $t)) $missing[] = $t;
    }
    if ($missing) {
        header('Content-Type: text/html; charset=utf-8');
        die("<div style='padding:3rem;font-family:system-ui'>
            <h2 style='color:#b00020'>Missing required table(s)</h2>
            <p>Missing: <b>".h(implode(', ', $missing))."</b></p>
            <p>DB: <b>".h((string)$pdo->query("SELECT DATABASE()")->fetchColumn())."</b></p>
        </div>");
    }

    /* ---------------- Officer series/region assignment (still required) ---------------- */
    $st = $pdo->prepare("
        SELECT oa.exam_series_id, oa.region_id
        FROM officer_assignments oa
        WHERE oa.user_id=? AND oa.status='active'
        ORDER BY oa.id DESC
        LIMIT 1
    ");
    $st->execute([$officerId]);
    $officerAssign = $st->fetch(PDO::FETCH_ASSOC);

    if (!$officerAssign) {
        die("<div style='padding:4rem; text-align:center; font-family:sans-serif;'>
            <h2>No Active Assignment</h2>
            <p>Contact Admin to assign you to a series/region.</p>
        </div>");
    }
    $officerRegionId = (int)($officerAssign['region_id'] ?? 0);

    /* ---------------- Officer clusters -> allowed districts ---------------- */
    $st = $pdo->prepare("
        SELECT dc.id, dc.name
        FROM cluster_officers co
        JOIN district_clusters dc ON dc.id = co.cluster_id
        WHERE co.officer_user_id = ?
        ORDER BY dc.name ASC
    ");
    $st->execute([$officerId]);
    $officerClusters = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$officerClusters) {
        die("<div style='padding:4rem; text-align:center; font-family:sans-serif;'>
            <h2>No Cluster Assigned</h2>
            <p>Contact Admin to assign you to a district cluster.</p>
        </div>");
    }

    $requestedClusterId = (int)($_GET['cluster_id'] ?? 0);
    $allowedClusterIds = array_map('intval', array_column($officerClusters, 'id'));

    $clusterIdsToUse = $allowedClusterIds;
    if ($requestedClusterId > 0 && in_array($requestedClusterId, $allowedClusterIds, true)) {
        $clusterIdsToUse = [$requestedClusterId];
    }

    $st = $pdo->prepare("
        SELECT DISTINCT cd.district_id
        FROM cluster_districts cd
        WHERE cd.cluster_id IN (" . inPlaceholders(count($clusterIdsToUse)) . ")
    ");
    $st->execute($clusterIdsToUse);
    $allowedDistrictIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if (!$allowedDistrictIds) {
        die("<div style='padding:4rem; text-align:center; font-family:sans-serif;'>
            <h2>Cluster Has No Districts</h2>
            <p>Your cluster(s) have no districts. Ask Admin to add districts.</p>
        </div>");
    }

    $inAllowedDistricts = inPlaceholders(count($allowedDistrictIds));

    /* ---------------- Fetch session & requirements ---------------- */
    $st = $pdo->prepare("
        SELECT ts.*,
               c.center_number, c.center_name, c.district_id,
               dist.region_id, dist.name AS district_name,
               r.name AS region_name,
               o.name AS occupation_name,
               COALESCE(cr.candidates_per_examiner, 20) AS ratio
        FROM timetable_sessions ts
        INNER JOIN centers c ON c.id = ts.center_id
        LEFT JOIN districts dist ON dist.id = c.district_id
        LEFT JOIN regions r ON r.id = dist.region_id
        INNER JOIN occupations o ON o.id = ts.occupation_id
        LEFT JOIN candidate_ratios cr ON cr.occupation_id = ts.occupation_id
        WHERE ts.id = ?
        LIMIT 1
    ");
    $st->execute([$sessionId]);
    $session = $st->fetch(PDO::FETCH_ASSOC);
    if (!$session) { die("Session Context Missing."); }

    $centerDistrictId    = (int)($session['district_id'] ?? 0);
    $regionId            = (int)($session['region_id'] ?? 0);
    $occId               = (int)($session['occupation_id'] ?? 0);
    $ratio               = (int)($session['ratio'] ?? 20);
    $candCnt             = (int)($session['candidate_count'] ?? 0);
    $requiredCount       = (int)ceil($candCnt / max($ratio, 1));
    $sessionDate         = (string)($session['session_date'] ?? '');
    $startTime           = (string)($session['start_time'] ?? '');
    $seriesId            = (int)($session['exam_series_id'] ?? 0);
    $deploymentFinished  = (int)($session['deployment_finished'] ?? 0);

    if ($seriesId <= 0) {
        die("Session has no exam_series_id set.");
    }

    /* ---------------- Access control: officer can only deploy for centers inside their cluster districts ---------------- */
    if ($centerDistrictId <= 0 || !in_array($centerDistrictId, $allowedDistrictIds, true)) {
        die("Access denied: This center is not in your assigned cluster coverage.");
    }

    /* Optional extra check: region assignment must match session region */
    if ($officerRegionId > 0 && $regionId > 0 && $officerRegionId !== $regionId) {
        die("Access denied: Regional assignment mismatch.");
    }

    /* ---------------- Deployed in this session (ACTIVE ONLY) ---------------- */
    $st = $pdo->prepare("
        SELECT d.id, u.full_name, u.phone, u.id as user_id
        FROM deployments d
        JOIN users u ON u.id = d.examiner_user_id
        WHERE d.timetable_session_id = ?
          AND d.status <> 'cancelled'
          AND COALESCE(d.response_status,'') <> 'cancelled'
          AND d.completed_at IS NULL
        ORDER BY d.id DESC
    ");
    $st->execute([$sessionId]);
    $deployedAssessors = $st->fetchAll(PDO::FETCH_ASSOC);
    $deployedCount = count($deployedAssessors);

    $canFinish = ($deployedCount >= $requiredCount) && ($deploymentFinished === 0);

    /* fallback allowed only if still short */
    $stillShort = ($deployedCount < $requiredCount);
    if (!$stillShort) $showAllRegions = 0;

    /* Similar is only for cluster mode (never global fallback) */
    if ($showAllRegions === 1) $showSimilar = 0;

    /* ---------------- Conflicts (busy at same time) ---------------- */
    $st = $pdo->prepare("
        SELECT u.full_name, c2.center_name
        FROM deployments d
        JOIN timetable_sessions ts_busy ON ts_busy.id = d.timetable_session_id
        JOIN centers c2 ON c2.id = ts_busy.center_id
        JOIN users u ON u.id = d.examiner_user_id

        /* latest APPROVED application by (user_id OR phone) */
        LEFT JOIN examiner_applications ea ON ea.id = (
            SELECT id
            FROM examiner_applications
            WHERE (user_id = u.id OR phone = u.phone)
              AND status = 'approved'
            ORDER BY id DESC
            LIMIT 1
        )
        WHERE ts_busy.session_date = ?
          AND ts_busy.start_time = ?
          AND ts_busy.exam_series_id = ?
          AND ts_busy.id <> ?
          AND d.status <> 'cancelled'
          AND COALESCE(d.response_status,'') <> 'cancelled'
          AND d.completed_at IS NULL
          AND ea.id IS NOT NULL
          AND ea.district_id IN ($inAllowedDistricts)
        ORDER BY u.full_name ASC
    ");
    $st->execute(array_merge([$sessionDate, $startTime, $seriesId, $sessionId], $allowedDistrictIds));
    $conflicts = $st->fetchAll(PDO::FETCH_ASSOC);

    /* ---------------- Assessor Pool ---------------- */
    $assessorPool = [];

    if ($occId > 0) {
        $districtWhere = $showAllRegions ? "" : " AND ea.district_id IN ($inAllowedDistricts) ";

        // ✅ OCCUPATION FALLBACK:
        // - match occupation_id when present
        // - else match by ea.occupation (text) against session occupation name
        $occWhere = ($showSimilar === 1 && $showAllRegions === 0)
            ? ""
            : " AND (
                    ea.occupation_id = ?
                 OR ((ea.occupation_id IS NULL OR ea.occupation_id = 0)
                      AND LOWER(TRIM(ea.occupation)) = LOWER(TRIM(?)))
              ) ";

        $sql = "
            SELECT DISTINCT
                u.id,
                u.full_name,
                u.phone,
                COALESCE(o2.name, ea.occupation, 'Unknown') AS examiner_occupation,
                r2.name AS examiner_region
            FROM users u

            /* latest APPROVED application by (user_id OR phone) */
            INNER JOIN examiner_applications ea ON ea.id = (
                SELECT id
                FROM examiner_applications
                WHERE (user_id = u.id OR phone = u.phone)
                  AND status = 'approved'
                ORDER BY id DESC
                LIMIT 1
            )

            /* ✅ LEFT JOIN because occupation_id may be NULL */
            LEFT JOIN occupations o2 ON o2.id = ea.occupation_id
            LEFT JOIN regions r2 ON r2.id = u.region_id

            WHERE u.role = 'examiner'
              AND u.status = 'active'
              $occWhere
              $districtWhere

              /* hide if deployed ANYWHERE (ACTIVE deployment) */
              AND u.id NOT IN (
                  SELECT examiner_user_id
                  FROM deployments
                  WHERE status <> 'cancelled'
                    AND COALESCE(response_status,'') <> 'cancelled'
                    AND completed_at IS NULL
              )

              /* hide if already ACTIVE in this session */
              AND u.id NOT IN (
                  SELECT examiner_user_id
                  FROM deployments
                  WHERE timetable_session_id = ?
                    AND status <> 'cancelled'
                    AND COALESCE(response_status,'') <> 'cancelled'
                    AND completed_at IS NULL
              )

              /* busy in same timeslot + SAME series_id */
              AND u.id NOT IN (
                  SELECT d2.examiner_user_id
                  FROM deployments d2
                  JOIN timetable_sessions ts2 ON ts2.id = d2.timetable_session_id
                  WHERE ts2.session_date = ?
                    AND ts2.start_time = ?
                    AND ts2.exam_series_id = ?
                    AND d2.status <> 'cancelled'
                    AND COALESCE(d2.response_status,'') <> 'cancelled'
                    AND d2.completed_at IS NULL
              )
            ORDER BY u.full_name ASC
        ";

        $st = $pdo->prepare($sql);
        $params = [];

        // occupation param(s) (if strict occupation)
        if (!($showSimilar === 1 && $showAllRegions === 0)) {
            $params[] = $occId;
            $params[] = (string)$session['occupation_name']; // fallback by name
        }

        // district params (if strict cluster)
        if (!$showAllRegions) {
            $params = array_merge($params, $allowedDistrictIds);
        }

        // always
        $params[] = $sessionId;
        $params[] = $sessionDate;
        $params[] = $startTime;
        $params[] = $seriesId;

        $st->execute($params);
        $assessorPool = $st->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    die("Database Error: " . h($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deployment Control | <?= h($session['center_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #0f172a; --accent: #2563eb; --success: #10b981; --danger: #ef4444; --warning: #f59e0b; --bg: #f8fafc; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; color: #334155; }
        .app-container { display: grid; grid-template-columns: 360px 1fr; min-height: 100vh; }
        .sidebar { background: #fff; border-right: 1px solid var(--border); padding: 40px 25px; overflow-y: auto; }
        .back-link { text-decoration: none; color: var(--accent); font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
        .section-tag { font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; display: block; }
        .deployed-card { background: #f1f5f9; border-radius: 12px; padding: 15px; margin-bottom: 10px; border: 1px solid var(--border); }
        .deployed-row { display: flex; justify-content: space-between; align-items: center; }
        .deployed-name { font-size: 14px; font-weight: 700; color: var(--primary); }
        .btn-remove { background: none; border: none; color: #94a3b8; cursor: pointer; padding: 5px; transition: 0.2s; }
        .btn-remove:hover { color: var(--danger); transform: scale(1.1); }
        .main-content { padding: 60px; max-width: 1000px; }
        .stat-group { display: flex; gap: 20px; margin-bottom: 26px; flex-wrap: wrap; }
        .stat-card { background: white; padding: 24px; border-radius: 16px; border: 1px solid var(--border); flex: 1; min-width: 220px; }
        .stat-val { font-size: 28px; font-weight: 800; color: var(--primary); display: block; margin-top: 5px; }
        .pool-card { background: #fff; border-radius: 20px; border: 1px solid var(--border); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); overflow: hidden; }
        .search-area { background: #f8fafc; padding: 25px; border-bottom: 1px solid var(--border); }
        .search-box { width: 100%; border: 2px solid #e2e8f0; padding: 14px 18px; border-radius: 12px; font-size: 15px; outline: none; }
        .assessor-list { width: 100%; border: none; font-size: 16px; outline: none; cursor: pointer; height: 400px; }
        .assessor-list option { padding: 16px 25px; border-bottom: 1px solid #f1f5f9; }
        .btn-deploy { background: var(--accent); color: white; border: none; width: 100%; padding: 22px; font-weight: 700; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 12px; }
        .conflict-alert { background: #fffbeb; border: 1px solid #fde68a; padding: 20px; border-radius: 12px; margin-bottom: 18px; }
        .conflict-item { font-size: 13px; color: #92400e; padding: 4px 0; border-bottom: 1px dashed #fde68a; }
        .finish-banner {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 14px 16px;
            border-radius: 14px;
            font-weight: 900;
            margin-bottom: 18px;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .btn-finish {
            width: 100%;
            margin-top: 12px;
            background: #10b981;
            color: #fff;
            border: none;
            padding: 18px;
            font-weight: 900;
            font-size: 15px;
            cursor: pointer;
            display:flex;
            align-items:center;
            justify-content:center;
            gap:10px;
        }
        .btn-finish:disabled { opacity: .55; cursor: not-allowed; }
        .hint { font-size: 12px; color: #64748b; margin-top: 8px; padding: 0 2px 14px 2px; line-height: 1.4; }
        .pad { padding: 0 25px 25px 25px; }
        .toggle-btn{
            display:inline-flex;
            align-items:center;
            gap:10px;
            background:#0f172a;
            color:#fff;
            padding:12px 16px;
            border-radius:12px;
            text-decoration:none;
            font-weight:800;
            font-size:13px;
        }
        .toggle-wrap{ display:flex; justify-content:flex-end; margin-bottom:14px; gap:10px; flex-wrap:wrap; }
        .toggle-note{ font-size:12px; color:#64748b; margin-top:6px; }
        .flash { border-radius: 14px; padding: 14px 16px; font-weight: 800; margin-bottom: 18px; border: 1px solid var(--border); background: #fff; }
        .flash.success { border-color:#a7f3d0; background:#ecfdf5; color:#065f46; }
        .flash.danger  { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
        .flash.warning { border-color:#fde68a; background:#fffbeb; color:#92400e; }
    </style>
</head>
<body>

<div class="app-container">
    <aside class="sidebar">
        <a href="dashboard.php" class="back-link"><i class="bi bi-arrow-left"></i> Return to Dashboard</a>

        <div style="margin-bottom:26px;">
            <span class="section-tag">Center Details</span>
            <div style="font-weight: 800; font-size: 18px; color: var(--primary);"><?= h($session['center_name']) ?></div>
            <div style="color: #64748b; font-size: 13px;"><?= h($session['center_number']) ?> &bull; <?= h($session['district_name']) ?></div>
        </div>

        <div>
            <span class="section-tag">Current Team (<?= (int)$deployedCount ?>)</span>
            <div id="deployedList">
                <?php foreach($deployedAssessors as $da): ?>
                    <div class="deployed-card">
                        <div class="deployed-row">
                            <span class="deployed-name"><?= h($da['full_name']) ?></span>
                            <form action="cancel_deployment.php" method="POST" onsubmit="return confirm('Remove this assessor?');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="cancel_deployment_id" value="<?= (int)$da['id'] ?>">
                                <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
                                <button type="submit" class="btn-remove" <?= $deploymentFinished === 1 ? 'disabled title="Deployment finished"' : '' ?>>
                                    <i class="bi bi-x-circle-fill"></i>
                                </button>
                            </form>
                        </div>
                        <div style="font-size:12px; color:#64748b; margin-top:4px;"><?= h($da['phone']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header style="margin-bottom: 40px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                <span style="background: #dbeafe; color: #1e40af; padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 800;"><?= h($session['occupation_name']) ?></span>
                <span style="background: #f1f5f9; color: #475569; padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 800;"><?= date('d M Y', strtotime($sessionDate)) ?> @ <?= substr($startTime, 0, 5) ?></span>
            </div>
            <h1 style="font-size: 36px; font-weight: 800; margin: 0; color: var(--primary);">Personnel Selection</h1>
        </header>

        <?php if (!empty($flash) && is_array($flash)): ?>
            <div class="flash <?= h($flash['type'] ?? 'success') ?>">
                <?= h($flash['msg'] ?? '') ?>
            </div>
        <?php endif; ?>

        <?php if($deploymentFinished === 1): ?>
            <div class="finish-banner">
                <i class="bi bi-check-circle-fill"></i>
                Deployment finished. Examiners can now generate the claim form.
            </div>

            <form action="reopen_deployment.php" method="POST"
                  onsubmit="return confirm('Reopen deployment for this session? This will allow changes again.');"
                  style="margin-bottom:18px;">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
                <button type="submit" class="btn-finish" style="background:#0f172a;">
                    <i class="bi bi-unlock-fill"></i> Reopen Deployment (Allow Remove/Edit)
                </button>
            </form>
        <?php endif; ?>

        <div class="stat-group">
            <div class="stat-card">
                <span class="section-tag">Required</span>
                <span class="stat-val"><?= (int)$requiredCount ?></span>
            </div>
            <div class="stat-card">
                <span class="section-tag">Deployed</span>
                <span class="stat-val"><?= (int)$deployedCount ?></span>
            </div>
            <div class="stat-card">
                <span class="section-tag">Pool Size</span>
                <span class="stat-val"><?= (int)count($assessorPool) ?></span>
            </div>
        </div>

        <?php if(!empty($conflicts)): ?>
            <div class="conflict-alert">
                <span class="section-tag" style="color: #92400e;"><i class="bi bi-exclamation-triangle-fill"></i> Unavailable (Busy at same timeslot)</span>
                <?php foreach($conflicts as $con): ?>
                    <div class="conflict-item">
                        <strong><?= h($con['full_name']) ?></strong> is currently deployed at <u><?= h($con['center_name']) ?></u>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if($deploymentFinished === 0): ?>
            <div class="toggle-wrap">
                <?php if($showAllRegions === 0): ?>
                    <?php if($showSimilar): ?>
                        <a class="toggle-btn" href="?session_id=<?= (int)$sessionId ?>">
                            <i class="bi bi-filter-circle"></i> Strict Only (<?= h($session['occupation_name']) ?>)
                        </a>
                    <?php else: ?>
                        <a class="toggle-btn" href="?session_id=<?= (int)$sessionId ?>&similar=1">
                            <i class="bi bi-people-fill"></i> Get All Occupations (Cluster)
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if($stillShort): ?>
                    <?php if($showAllRegions): ?>
                        <a class="toggle-btn" href="?session_id=<?= (int)$sessionId ?>">
                            <i class="bi bi-filter-circle"></i> Only My Cluster
                        </a>
                    <?php else: ?>
                        <a class="toggle-btn" href="?session_id=<?= (int)$sessionId ?>&all_regions=1">
                            <i class="bi bi-globe"></i> Get From Outside Cluster
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if($stillShort): ?>
                <div class="toggle-note">
                    Outside-cluster fallback is only allowed when still short. Required: <b><?= (int)$requiredCount ?></b>, Deployed: <b><?= (int)$deployedCount ?></b>.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="pool-card">
            <div class="search-area">
                <input type="text" id="filter" class="search-box" placeholder="Search available examiners..." <?= $deploymentFinished === 1 ? 'disabled' : 'autofocus' ?>>
            </div>

            <form action="save_deployment.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
                <input type="hidden" name="all_regions" value="<?= (int)$showAllRegions ?>">
                <input type="hidden" name="similar" value="<?= (int)$showSimilar ?>">

                <select name="examiner_user_id" id="pool" required size="12" class="assessor-list" <?= $deploymentFinished === 1 ? 'disabled' : '' ?>>
                    <?php foreach($assessorPool as $a): ?>
                        <option value="<?= (int)$a['id'] ?>">
                            <?= h($a['full_name']) ?> — <?= h($a['phone']) ?> — <?= h($a['examiner_occupation']) ?>
                            <?= $showAllRegions ? ' — ' . h($a['examiner_region']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn-deploy" <?= (count($assessorPool) === 0 || $deploymentFinished === 1) ? 'disabled' : '' ?>>
                    <i class="bi bi-person-plus-fill"></i> Confirm Deployment
                </button>
            </form>

            <form action="finish_deployment.php" method="POST" class="pad"
                  onsubmit="return confirm('Finish deployment for this session? This will allow examiners to generate the claim form.');">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">

                <button type="submit" class="btn-finish" <?= $canFinish ? '' : 'disabled' ?>>
                    <i class="bi bi-check2-circle"></i> Finish Deployment (Enable Claim Form)
                </button>

                <?php if($deploymentFinished === 0): ?>
                    <div class="hint">
                        Finish is enabled only after deploying at least <strong><?= (int)$requiredCount ?></strong> examiner(s).
                        Currently deployed: <strong><?= (int)$deployedCount ?></strong>.
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </main>
</div>

<script>
(function(){
    const filter = document.getElementById('filter');
    const pool = document.getElementById('pool');
    if (!filter || !pool) return;

    const original = Array.from(pool.options).map(o => ({ value: o.value, text: o.text }));

    filter.addEventListener('input', function() {
        const q = (filter.value || '').toLowerCase().trim();
        pool.innerHTML = '';
        for (const item of original) {
            if (!q || item.text.toLowerCase().includes(q)) {
                const opt = document.createElement('option');
                opt.value = item.value;
                opt.textContent = item.text;
                pool.appendChild(opt);
            }
        }
    });
})();
</script>

</body>
</html>
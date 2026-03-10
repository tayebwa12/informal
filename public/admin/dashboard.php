<?php
declare(strict_types=1);

// Error suppression for production (change to 1 for debugging)
ini_set('display_errors', '0');

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_admin();

/** Safe HTML Output */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
    /* ---------------- 1. BASIC SYSTEM COUNTS ---------------- */
    $counts = [
        'pending_apps' => (int)$pdo->query("SELECT COUNT(*) FROM examiner_applications WHERE status='pending'")->fetchColumn(),
        'examiners'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='examiner'")->fetchColumn(),
        'sessions'     => (int)$pdo->query("SELECT COUNT(*) FROM timetable_sessions")->fetchColumn(),
        'deployed'     => (int)$pdo->query("SELECT COUNT(*) FROM deployments WHERE status <> 'cancelled'")->fetchColumn(),
        'unfilled'     => (int)$pdo->query("
            SELECT COUNT(*) FROM timetable_sessions ts 
            WHERE NOT EXISTS (SELECT 1 FROM deployments d WHERE d.timetable_session_id = ts.id AND d.status <> 'cancelled')
        ")->fetchColumn(),
    ];

    $deploymentRate = $counts['sessions'] > 0 ? round(($counts['deployed'] / $counts['sessions']) * 100) : 0;

    /* ---------------- 2. REGIONAL BREAKDOWN ---------------- */
    $regionalStats = $pdo->query("
        SELECT 
            r.name as region_name,
            COUNT(ts.id) as total_sessions,
            COUNT(DISTINCT dep.timetable_session_id) as filled_sessions
        FROM regions r
        JOIN districts d ON d.region_id = r.id
        JOIN centers c ON c.district_id = d.id
        JOIN timetable_sessions ts ON ts.center_id = c.id
        LEFT JOIN deployments dep ON dep.timetable_session_id = ts.id AND dep.status <> 'cancelled'
        GROUP BY r.id, r.name
        ORDER BY (COUNT(ts.id) - COUNT(DISTINCT dep.timetable_session_id)) DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("<div style='padding:20px; background:#FEE2E2; color:#B91C1C; font-family:sans-serif;'><strong>Database Error:</strong> " . h($e->getMessage()) . "</div>");
}

$fullName = h($me['full_name'] ?? 'Admin');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>UVTAB | Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4F46E5; --bg: #F8FAFC; --surface: #FFFFFF;
            --text-main: #1E293B; --text-muted: #64748B; --border: #E2E8F0;
            --accent-green: #10B981; --accent-red: #EF4444; --accent-yellow: #F59E0B;
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); --radius: 14px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text-main); }
        
        /* Top Navigation */
        .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.75rem 2rem; position: sticky; top: 0; z-index: 100; }
        .topbar-inner { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .brand h1 { margin: 0; font-size: 1.1rem; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; }
        .top-actions { display: flex; align-items: center; gap: 12px; }
        .user-pill { background: #F1F5F9; padding: 0.5rem 1rem; border-radius: 99px; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .btn-logout { background: #FEE2E2; color: #DC2626; text-decoration: none; padding: 0.5rem 1.25rem; border-radius: 99px; font-size: 0.85rem; font-weight: 700; border: 1px solid #FECACA; transition: 0.2s; }
        .btn-logout:hover { background: #DC2626; color: white; }

        /* Container */
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 2rem; }
        .section-head { margin: 2.5rem 0 1.25rem; display: flex; align-items: center; gap: 12px; }
        .section-head h2 { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin: 0; }
        .line { flex: 1; height: 1px; background: var(--border); }

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem; }
        .stat-card { background: var(--surface); padding: 1.5rem; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); }
        .stat-val { font-size: 2.25rem; font-weight: 800; line-height: 1; margin: 0.5rem 0; }
        .progress-track { background: #E2E8F0; height: 8px; border-radius: 4px; margin-top: 10px; overflow: hidden; }
        .progress-fill { height: 100%; background: var(--accent-green); transition: width 0.8s ease; }

        /* Module Grid - 10 Modules */
        .action-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem; }
        .btn-tile { background: var(--surface); text-decoration: none; color: inherit; padding: 1.1rem; border-radius: var(--radius); border: 1px solid var(--border); display: flex; align-items: center; gap: 1rem; transition: 0.2s; box-shadow: 0 2px 4px rgb(0 0 0 / 0.02); }
        .btn-tile:hover { transform: translateY(-3px); border-color: var(--primary); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .icon-box { width: 42px; height: 42px; border-radius: 10px; display: grid; place-items: center; flex-shrink: 0; }
        .icon-box svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }

        /* Category Colors */
        .bg-blue { background: #E0F2FE; color: #0EA5E9; }
        .bg-cyan { background: #ECFEFF; color: #06B6D4; }
        .bg-green { background: #DCFCE7; color: #10B981; }
        .bg-yellow { background: #FEF3C7; color: #F59E0B; }
        .bg-red { background: #FEE2E2; color: #EF4444; }
        .bg-indigo { background: #EEF2FF; color: #6366F1; }

        /* Table */
        .table-wrap { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); overflow-x: auto; margin-top: 1rem; box-shadow: var(--shadow); }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th { background: #F8FAFC; text-align: left; padding: 14px; font-size: 0.75rem; color: var(--text-muted); border-bottom: 1px solid var(--border); text-transform: uppercase; }
        td { padding: 14px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        .badge { padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem; }
        .badge-warn { background: #FFFBEB; color: #92400E; }
        .badge-success { background: #DCFCE7; color: #166534; }
    </style>
</head>
<body>

<nav class="topbar">
    <div class="topbar-inner">
        <div class="brand"><h1>UVTAB Portal</h1></div>
        <div class="top-actions">
            <div class="user-pill">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <?php echo $fullName; ?>
            </div>
            <a href="../logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
</nav>

<div class="container">
    
    <div class="section-head"><h2>Deployment Health</h2><div class="line"></div></div>
    <div class="stats-grid">
        <div class="stat-card">
            <div style="font-size:0.7rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Deployment Progress</div>
            <div class="stat-val" style="color:var(--accent-green);"><?php echo $deploymentRate; ?>%</div>
            <div class="progress-track"><div class="progress-fill" style="width:<?php echo $deploymentRate; ?>%"></div></div>
        </div>
        <div class="stat-card">
            <div style="font-size:0.7rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Unfilled Gaps</div>
            <div class="stat-val" style="color:var(--accent-red);"><?php echo $counts['unfilled']; ?></div>
        </div>
        <div class="stat-card">
            <div style="font-size:0.7rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Pending Applicants</div>
            <div class="stat-val" style="color:var(--accent-yellow);"><?php echo $counts['pending_apps']; ?></div>
        </div>
    </div>

    <div class="section-head"><h2>Management Modules (10)</h2><div class="line"></div></div>
    <div class="action-grid">
        <a href="manage_regions.php" class="btn-tile">
            <div class="icon-box bg-blue"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h10M4 18h16"></path></svg></div>
            <div class="tile-text"><strong>Regions</strong><span>Setup zones</span></div>
        </a>
        <a href="map_districts.php" class="btn-tile">
            <div class="icon-box bg-blue"><svg viewBox="0 0 24 24"><path d="M12 21s-6-4.35-6-10a6 6 0 0 1 12 0c0 5.65-6 10-6 10z"></path></svg></div>
            <div class="tile-text"><strong>Districts</strong><span>Map districts</span></div>
        </a>
        <a href="manage_centers.php" class="btn-tile">
            <div class="icon-box bg-blue"><svg viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-8h6v8"></path></svg></div>
            <div class="tile-text"><strong>Centers</strong><span>Assessment sites</span></div>
        </a>
        <a href="bulk_finish_deployments.php" class="btn-tile">
            <div class="icon-box bg-blue"><svg viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-8h6v8"></path></svg></div>
            <div class="tile-text"><strong>Deployments</strong><span>finisnish on on click</span></div>
        </a>
         <a href="manage_series.php" class="btn-tile">
            <div class="icon-box bg-blue"><svg viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-8h6v8"></path></svg></div>
            <div class="tile-text"><strong>manage series</strong><span> manage</span></div>
        </a>
        <a href="manage_occupations.php" class="btn-tile">
            <div class="icon-box bg-cyan"><svg viewBox="0 0 24 24"><path d="M16 20v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="3"></circle></svg></div>
            <div class="tile-text"><strong>Occupations</strong><span>Trade categories</span></div>
        </a>
        <a href="manage_officers.php" class="btn-tile">
            <div class="icon-box bg-cyan"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></div>
            <div class="tile-text"><strong>Officers</strong><span>Regional staff</span></div>
        </a>
        <a href="manage_roles_users.php" class="btn-tile">
            <div class="icon-box bg-cyan"><svg viewBox="0 0 24 24"><path d="M12 11V7a4 4 0 0 1 8 0v4h3v10H9V11h3z"></path></svg></div>
            <div class="tile-text"><strong>Roles</strong><span>Access control</span></div>
        </a>
        <a href="timetable_sessions.php" class="btn-tile">
            <div class="icon-box bg-red"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M3 10h18"></path></svg></div>
            <div class="tile-text"><strong>Timetable</strong><span>Active sessions</span></div>
        </a>
        <a href="manage_examiners.php" class="btn-tile">
            <div class="icon-box bg-green"><svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"></path></svg></div>
            <div class="tile-text"><strong>Assessors</strong><span>Verified pool</span></div>
        </a>
        <a href="manage_ratios.php" class="btn-tile">
            <div class="icon-box bg-yellow"><svg viewBox="0 0 24 24"><path d="M3 3v18h18M7 14l3-3 3 3 5-6"></path></svg></div>
            <div class="tile-text"><strong>Analytics</strong><span>Staffing ratios</span></div>
        </a>
           <a href="manage_district_clusters.php" class="btn-tile">
            <div class="icon-box bg-yellow"><svg viewBox="0 0 24 24"><path d="M3 3v18h18M7 14l3-3 3 3 5-6"></path></svg></div>
            <div class="tile-text"><strong>assign cluters</strong><span>Staffing</span></div>
        </a>
        <a href="auto_deploy.php" class="btn-tile">
            <div class="icon-box bg-indigo"><svg viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg></div>
            <div class="tile-text"><strong>Auto Deploy</strong><span>Run algorithm</span></div>
        </a>
    </div>
       <a href="reset_examiner_password.php" class="btn-tile">
            <div class="icon-box bg-indigo"><svg viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg></div>
            <div class="tile-text"><strong>passwords</strong><span>Run algorithm</span></div>
        </a>
    </div>

    <div class="section-head"><h2>Regional Breakdown</h2><div class="line"></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Region</th><th>Total Sessions</th><th>Filled</th><th>Gap</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($regionalStats as $row): 
                    $gap = (int)$row['total_sessions'] - (int)$row['filled_sessions'];
                    $pc = $row['total_sessions'] > 0 ? round(($row['filled_sessions'] / $row['total_sessions']) * 100) : 0;
                ?>
                <tr>
                    <td><strong><?php echo h($row['region_name']); ?></strong></td>
                    <td><?php echo $row['total_sessions']; ?></td>
                    <td><?php echo $row['filled_sessions']; ?></td>
                    <td style="color:<?php echo $gap > 0 ? 'var(--accent-red)' : 'var(--accent-green)'; ?>; font-weight:bold;"><?php echo $gap; ?></td>
                    <td><span class="badge <?php echo $pc < 100 ? 'badge-warn' : 'badge-success'; ?>"><?php echo $pc; ?>%</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <footer style="margin-top: 4rem; text-align: center; padding-bottom: 2rem; color: var(--text-muted); font-size: 0.8rem;">
        &copy; <?php echo date('Y'); ?> <strong>UVTAB</strong> &bull; Unified Vocational Training Assessment Board
    </footer>
</div>
</body>
</html>
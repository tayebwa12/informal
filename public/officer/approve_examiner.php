<?php
declare(strict_types=1);

// 1. Error Reporting
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
$officerId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- OFFICER REGION LOCK ---------------- */
$st = $pdo->prepare("
    SELECT oa.region_id, r.name AS region_name
    FROM officer_assignments oa
    JOIN regions r ON r.id = oa.region_id
    WHERE oa.user_id = ? AND oa.status = 'active'
    ORDER BY oa.id DESC LIMIT 1
");
$st->execute([$officerId]);
$assignment = $st->fetch(PDO::FETCH_ASSOC);

if (!$assignment) die("Access Denied: No active regional assignment found.");

$regionId   = (int)$assignment['region_id'];
$regionName = (string)$assignment['region_name'];

/* ---------------- CSRF & TARGETS ---------------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

$targetUserId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
if ($targetUserId <= 0) die("Invalid User ID.");

/* ---------------- DATA FETCHING ---------------- */
// Load examiner
$st = $pdo->prepare("SELECT id, full_name, email, phone FROM users WHERE id = ? AND role = 'examiner' AND region_id = ?");
$st->execute([$targetUserId, $regionId]);
$examiner = $st->fetch(PDO::FETCH_ASSOC);
if (!$examiner) die("Examiner not found in your region.");

// Load application
$st = $pdo->prepare("SELECT id, status FROM examiner_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$st->execute([$targetUserId]);
$app = $st->fetch(PDO::FETCH_ASSOC);
$appId = (int)($app['id'] ?? 0);

/* ---------------- POST ACTIONS ---------------- */
$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) die("CSRF Mismatch.");
    
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $seriesId = (int)$_POST['exam_series_id'];
        $centerId = (int)($_POST['center_id'] ?? 0); // Now Optional

        try {
            $pdo->beginTransaction();

            // 1. Update Application Status
            $st = $pdo->prepare("UPDATE examiner_applications SET status='approved', reviewed_by=?, reviewed_at=NOW(), officer_verified=1 WHERE id=?");
            $st->execute([$officerId, $appId]);

            // 2. Activate User Profile
            $pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$targetUserId]);

            // 3. Optional Center Assignment
            if ($seriesId > 0 && $centerId > 0) {
                $pdo->prepare("DELETE FROM examiner_center_assignments WHERE user_id=? AND exam_series_id=?")->execute([$targetUserId, $seriesId]);
                $st = $pdo->prepare("INSERT INTO examiner_center_assignments (user_id, center_id, exam_series_id, is_active) VALUES (?, ?, ?, 1)");
                $st->execute([$targetUserId, $centerId, $seriesId]);
            }

            $pdo->commit();
            header("Location: view_examiners_region.php?msg=Approved"); exit;
        } catch (Exception $e) { $pdo->rollBack(); $msg = "Error: " . $e->getMessage(); }
    }

    if ($action === 'reject') {
        $reason = trim((string)$_POST['reason']);
        $st = $pdo->prepare("UPDATE examiner_applications SET status='rejected', rejection_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
        $st->execute([$reason, $officerId, $appId]);
        header("Location: view_examiners_region.php?msg=Rejected"); exit;
    }
}

// Fetch Occupations & Centers for the UI
$st = $pdo->prepare("SELECT o.name FROM examiner_occupations eo JOIN occupations o ON o.id = eo.occupation_id WHERE eo.application_id = ?");
$st->execute([$appId]);
$selectedOcc = $st->fetchAll(PDO::FETCH_ASSOC);

$seriesList = $pdo->query("SELECT id, name FROM exam_series ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$st = $pdo->prepare("SELECT c.id, c.center_number, c.center_name FROM centers c JOIN districts d ON d.id = c.district_id WHERE d.region_id = ? ORDER BY c.center_number ASC");
$st->execute([$regionId]);
$centers = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Review Examiner</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root { --blue: #2563eb; --red: #ef4444; --slate: #64748b; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #1e293b; padding: 40px 20px; margin: 0; }
        .container { max-width: 650px; margin: 0 auto; }
        .card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 24px; border: 1px solid #e2e8f0; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        .sub-text { color: var(--slate); font-size: 14px; margin-bottom: 20px; display: block; }
        .occ-tag { display: inline-block; background: #eff6ff; color: var(--blue); padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; margin-right: 6px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 13px; margin-top: 16px; }
        select, input[type="text"] { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; margin-bottom: 12px; }
        .btn { padding: 12px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; width: 100%; transition: 0.2s; }
        .btn-primary { background: var(--blue); color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .hint { font-size: 12px; color: var(--slate); margin-bottom: 20px; }
        .divider { height: 1px; background: #e2e8f0; margin: 24px 0; }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <h1><?= h($examiner['full_name']) ?></h1>
        <span class="sub-text"><?= h($regionName) ?> Region • <?= h($examiner['phone']) ?></span>

        <div style="margin-bottom: 24px;">
            <?php foreach($selectedOcc as $o): ?>
                <span class="occ-tag"><?= h($o['name']) ?></span>
            <?php endforeach; ?>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="approve">
            
            <label>Exam Series</label>
            <select name="exam_series_id" required>
                <?php foreach($seriesList as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Assign to Center (Optional)</label>
            <select name="center_id">
                <option value="0">-- Skip Assignment for Now --</option>
                <?php foreach($centers as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= h($c['center_number']) ?> - <?= h($c['center_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">If you skip assignment, the examiner will be approved but not linked to a specific center.</p>

            <button type="submit" class="btn btn-primary">Approve Application</button>
        </form>
    </div>

    <div class="card" style="border-top: 4px solid var(--red);">
        <h3 style="margin-top:0; font-size: 16px;">Reject Application</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="reject">
            <input type="text" name="reason" placeholder="Enter reason for rejection..." required>
            <button type="submit" class="btn" style="background: #fef2f2; color: #991b1b;">Decline Application</button>
        </form>
    </div>
</div>

</body>
</html>
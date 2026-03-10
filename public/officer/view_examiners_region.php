<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
$userId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- OFFICER ASSIGNMENT ---------------- */
$st = $pdo->prepare("
    SELECT oa.region_id, r.name AS region_name
    FROM officer_assignments oa
    JOIN regions r ON r.id = oa.region_id
    WHERE oa.user_id = ? AND oa.status = 'active'
    ORDER BY oa.id DESC LIMIT 1
");
$st->execute([$userId]);
$assignment = $st->fetch(PDO::FETCH_ASSOC);

if (!$assignment) die("Access Denied.");

$regionId   = (int)$assignment['region_id'];
$regionName = (string)$assignment['region_name'];

/* ---------------- CSRF & SESSION ---------------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------------- BULK ACTION HANDLER (LOGIC PRESERVED) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) die("CSRF Failure");

    $ids = array_map('intval', $_POST['selected_users'] ?? []);
    $action = (string)($_POST['bulk_action'] ?? '');

    if (!in_array($action, ['approve', 'reject'], true)) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid action'];
        header("Location: view_examiners_region.php?" . http_build_query($_GET));
        exit;
    }

    if (empty($ids)) {
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'No examiners selected.'];
        header("Location: view_examiners_region.php?" . http_build_query($_GET));
        exit;
    }

    $updated = 0;
    try {
        $pdo->beginTransaction();
        foreach ($ids as $examinerId) {
            $check = $pdo->prepare("SELECT id, phone FROM users WHERE id=? AND role='examiner' AND region_id=? LIMIT 1");
            $check->execute([$examinerId, $regionId]);
            $u = $check->fetch(PDO::FETCH_ASSOC);
            if (!$u) continue;

            $phone = (string)($u['phone'] ?? '');
            $appId = 0;

            $st = $pdo->prepare("SELECT id FROM examiner_applications WHERE user_id=? ORDER BY id DESC LIMIT 1");
            $st->execute([$examinerId]);
            $appId = (int)($st->fetchColumn() ?: 0);

            if ($appId <= 0 && $phone !== '') {
                $st = $pdo->prepare("SELECT id FROM examiner_applications WHERE phone=? ORDER BY id DESC LIMIT 1");
                $st->execute([$phone]);
                $appId = (int)($st->fetchColumn() ?: 0);
            }

            if ($appId <= 0) continue;

            if ($action === 'approve') {
                $pdo->prepare("
                    UPDATE examiner_applications
                    SET status='approved', reviewed_by=?, reviewed_at=NOW(), officer_verified=1, user_id=COALESCE(user_id, ?)
                    WHERE id=? LIMIT 1
                ")->execute([$userId, $examinerId, $appId]);
                $pdo->prepare("UPDATE users SET status='active' WHERE id=? LIMIT 1")->execute([$examinerId]);
                $updated++;
            } else { 
                $pdo->prepare("
                    UPDATE examiner_applications
                    SET status='rejected', reviewed_by=?, reviewed_at=NOW(), officer_verified=0, user_id=COALESCE(user_id, ?)
                    WHERE id=? LIMIT 1
                ")->execute([$userId, $examinerId, $appId]);
                $pdo->prepare("UPDATE users SET status='inactive' WHERE id=? LIMIT 1")->execute([$examinerId]);
                $updated++;
            }
        }
        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $updated . ' examiners updated.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Bulk update failed: ' . $e->getMessage()];
    }
    header("Location: view_examiners_region.php?" . http_build_query($_GET));
    exit;
}

/* ---------------- DATA FETCHING (LOGIC PRESERVED) ---------------- */
$q = trim((string)($_GET['q'] ?? ''));
$where = ["u.role = 'examiner'", "u.region_id = ?"];
$params = [$regionId];

if ($q !== '') {
    $where[] = "(u.full_name LIKE ? OR u.phone LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%"]);
}

$sql = "SELECT u.id, u.full_name, u.phone, ea.status as app_status, ea.officer_verified, o.name as occ_name
        FROM users u
        LEFT JOIN examiner_applications ea ON ea.id = (
            SELECT id FROM examiner_applications WHERE (user_id = u.id OR phone = u.phone) ORDER BY id DESC LIMIT 1
        )
        LEFT JOIN occupations o ON o.id = ea.occupation_id
        WHERE " . implode(" AND ", $where) . " ORDER BY u.full_name ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Region | Examiner Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1e40af;
            --success: #059669; --danger: #dc2626;
            --warning: #d97706; --gray: #64748b;
            --bg: #f8fafc; --border: #e2e8f0;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: #1e293b; margin: 0; padding: 20px; line-height: 1.5; }
        .container { max-width: 1100px; margin: 0 auto; }
        
        /* Header */
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
        .page-header h2 { margin: 0; font-size: 24px; font-weight: 700; color: #0f172a; }
        .muted { color: var(--gray); font-size: 14px; margin-top: 4px; }

        /* Toolbar */
        .toolbar { 
            background: #fff; padding: 12px 20px; border-radius: 12px; border: 1px solid var(--border);
            display: flex; align-items: center; gap: 16px; margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); position: sticky; top: 10px; z-index: 100;
        }
        .selection-count { font-size: 12px; font-weight: 600; color: var(--primary); background: #eff6ff; padding: 4px 12px; border-radius: 99px; display: none; }
        
        /* Buttons */
        .btn { padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: white; border-color: var(--border); color: #475569; }
        .btn-outline:hover { background: #f1f5f9; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }

        /* Table Card */
        .card { background: #fff; border-radius: 12px; border: 1px solid var(--border); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .table-custom { width: 100%; border-collapse: collapse; }
        .table-custom th { background: #f8fafc; text-align: left; padding: 12px 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--gray); letter-spacing: 0.025em; border-bottom: 1px solid var(--border); }
        .table-custom td { padding: 16px 20px; border-bottom: 1px solid var(--border); font-size: 14px; }
        tr.selected { background-color: #f0f7ff; }
        tr:last-child td { border-bottom: none; }

        /* Status Badges */
        .badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; display: inline-block; }
        .badge-info { background: #e0f2fe; color: #0369a1; }
        .badge-approved { background: #dcfce7; color: #15803d; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }
        .badge-pending { background: #fef9c3; color: #a16207; }

        /* Search */
        .search-box { padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); outline: none; width: 220px; transition: border 0.2s; }
        .search-box:focus { border-color: var(--primary); }

        /* Alerts */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 500; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }
    </style>
</head>
<body>

<div class="container">
    <div class="page-header">
        <div>
            <h2>Region: <?= h($regionName) ?></h2>
            <p class="muted">Review applications and perform regional administrative tasks</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline">Dashboard</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <div class="toolbar">
            <input type="checkbox" id="masterCheck" style="width: 18px; height: 18px; cursor: pointer;">
            <span class="selection-count" id="countLabel">0 Selected</span>

            <div style="flex:1; display:flex; gap:10px;">
                <button type="submit" name="bulk_action" value="approve" class="btn btn-primary btn-sm">Bulk Approve</button>
                <button type="submit" name="bulk_action" value="reject" class="btn btn-outline btn-sm" style="color:var(--danger)">Bulk Reject</button>
            </div>

            <div class="flex gap-10">
                <input type="text" class="search-box" placeholder="Search by name..." onkeyup="filterTable(this.value)">
            </div>
        </div>

        <div class="card">
            <table class="table-custom" id="examinerTable">
                <thead>
                    <tr>
                        <th style="width: 40px;"></th>
                        <th>Examiner Information</th>
                        <th>Occupation</th>
                        <th>Application Status</th>
                        <th style="text-align:right;">Management</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <?php $status = strtolower((string)($r['app_status'] ?? 'pending')); ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="selected_users[]" value="<?= (int)$r['id'] ?>" class="row-check" style="width:16px; height:16px;">
                        </td>
                        <td>
                            <div style="font-weight: 600; color: #0f172a;"><?= h($r['full_name']) ?></div>
                            <div class="muted"><?= h($r['phone']) ?></div>
                        </td>
                        <td><span class="badge badge-info"><?= h($r['occ_name'] ?? 'General') ?></span></td>
                        <td>
                            <span class="badge badge-<?= h($status) ?>">
                                <?= strtoupper(h($status)) ?>
                            </span>
                        </td>
                        <td style="text-align:right;">
                            <a href="approve_examiner.php?user_id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm">Review File</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script>
const masterCheck = document.getElementById('masterCheck');
const rowChecks = document.querySelectorAll('.row-check');
const countLabel = document.getElementById('countLabel');

// Master Toggle Logic
masterCheck.addEventListener('change', function() {
    rowChecks.forEach(cb => {
        cb.checked = this.checked;
        cb.closest('tr').classList.toggle('selected', this.checked);
    });
    updateCounter();
});

// Individual Check Logic
rowChecks.forEach(cb => {
    cb.addEventListener('change', function() {
        this.closest('tr').classList.toggle('selected', this.checked);
        updateCounter();
        masterCheck.checked = Array.from(rowChecks).every(c => c.checked);
        masterCheck.indeterminate = Array.from(rowChecks).some(c => c.checked) && !masterCheck.checked;
    });
});

function updateCounter() {
    const checkedCount = document.querySelectorAll('.row-check:checked').length;
    countLabel.textContent = checkedCount + ' Selected';
    countLabel.style.display = checkedCount > 0 ? 'inline-block' : 'none';
}

function filterTable(val) {
    const rows = document.querySelectorAll('#examinerTable tbody tr');
    val = val.toLowerCase();
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
}
</script>

</body>
</html>
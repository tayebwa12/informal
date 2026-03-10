<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

require_admin();

$msg = $err = null;

const DEFAULT_OFFICER_PASSWORD = 'uvtab';

function norm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}

/** Load Series + Regions */
$series = $pdo->query("SELECT id, name FROM exam_series ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$regions = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/** Filter by series */
$filter_series_id = (int)($_GET['series_id'] ?? 0);

/** EXPORT officers by series (CSV) */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="officers_export_' . date('Ymd_His') . '.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['Full Name','Phone','Email','Series','Region','Assignment Status','User Status']);

  $sql = "SELECT u.full_name, u.phone, u.email, s.name AS series_name, r.name AS region_name, oa.status AS assignment_status, u.status AS user_status
          FROM officer_assignments oa
          JOIN users u ON u.id = oa.user_id
          JOIN exam_series s ON s.id = oa.exam_series_id
          JOIN regions r ON r.id = oa.region_id ";
  
  if ($filter_series_id > 0) {
    $st = $pdo->prepare($sql . " WHERE oa.exam_series_id = ? ORDER BY r.name, u.full_name");
    $st->execute([$filter_series_id]);
  } else {
    $st = $pdo->query($sql . " ORDER BY s.name, r.name, u.full_name");
  }

  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [$row['full_name'], $row['phone'], $row['email'], $row['series_name'], $row['region_name'], $row['assignment_status'], $row['user_status']]);
  }
  fclose($out);
  exit;
}

/** POST actions - (Logic remains exactly as provided) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  if (isset($_POST['add'])) {
    $full_name = norm((string)($_POST['full_name'] ?? ''));
    $phone     = norm((string)($_POST['phone'] ?? ''));
    $email     = norm((string)($_POST['email'] ?? ''));
    $exam_series_id = (int)($_POST['exam_series_id'] ?? 0);
    $region_id      = (int)($_POST['region_id'] ?? 0);
    $status = strtolower(norm((string)($_POST['status'] ?? 'active')));
    if (!in_array($status, ['active','inactive'], true)) $status = 'active';

    if ($full_name === '') $err = "Full name required.";
    elseif ($phone === '') $err = "Phone required.";
    elseif ($exam_series_id <= 0) $err = "Select exam series.";
    elseif ($region_id <= 0) $err = "Select region.";
    else {
      try {
        $pdo->beginTransaction();
        $u = $pdo->prepare("SELECT id FROM users WHERE phone=? LIMIT 1");
        $u->execute([$phone]);
        $user_id = (int)($u->fetchColumn() ?: 0);

        $created = false;
        if ($user_id <= 0) {
          $hash = password_hash(DEFAULT_OFFICER_PASSWORD, PASSWORD_DEFAULT);
          $ins = $pdo->prepare("INSERT INTO users (full_name, phone, email, password_hash, role, status) VALUES (?, ?, ?, ?, 'officer', ?)");
          $ins->execute([$full_name, $phone, $email ?: null, $hash, $status]);
          $user_id = (int)$pdo->lastInsertId();
          $created = true;
        } else {
          $pdo->prepare("UPDATE users SET role='officer' WHERE id=?")->execute([$user_id]);
        }

        $as = $pdo->prepare("INSERT INTO officer_assignments (user_id, exam_series_id, region_id, status) VALUES (?, ?, ?, 'active') ON DUPLICATE KEY UPDATE region_id=VALUES(region_id), status='active'");
        $as->execute([$user_id, $exam_series_id, $region_id]);
        $pdo->commit();

        $msg = $created ? "✅ Officer added. Default password: " . DEFAULT_OFFICER_PASSWORD : "✅ Officer assigned/updated for selected series.";
      } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $err = "❌ " . $e->getMessage(); }
    }
  }

  if (isset($_POST['reset_password'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    try {
      $hash = password_hash(DEFAULT_OFFICER_PASSWORD, PASSWORD_DEFAULT);
      $st = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='officer'");
      $st->execute([$hash, $user_id]);
      if ($st->rowCount() > 0) $msg = "✅ Password reset to: " . DEFAULT_OFFICER_PASSWORD;
      else $err = "Officer not found.";
    } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
  }

  if (isset($_POST['update_assignment'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $exam_series_id = (int)($_POST['exam_series_id'] ?? 0);
    $region_id = (int)($_POST['region_id'] ?? 0);
    $a_status = in_array($_POST['a_status'], ['active','inactive']) ? $_POST['a_status'] : 'active';
    try {
      $st = $pdo->prepare("INSERT INTO officer_assignments (user_id, exam_series_id, region_id, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE region_id=VALUES(region_id), status=VALUES(status)");
      $st->execute([$user_id, $exam_series_id, $region_id, $a_status]);
      $msg = "✅ Assignment updated.";
    } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
  }

  if (isset($_POST['toggle_user'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $new = in_array($_POST['new_status'], ['active','inactive']) ? $_POST['new_status'] : 'inactive';
    try {
      $pdo->prepare("UPDATE users SET status=? WHERE id=? AND role='officer'")->execute([$new, $user_id]);
      $msg = "✅ Officer account status updated.";
    } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
  }
}

/** Load Data */
$sql_list = "SELECT u.id AS user_id, u.full_name, u.phone, u.email, u.status AS user_status, oa.exam_series_id, s.name AS series_name, oa.region_id, r.name AS region_name, oa.status AS assignment_status
             FROM officer_assignments oa
             JOIN users u ON u.id = oa.user_id
             JOIN exam_series s ON s.id = oa.exam_series_id
             JOIN regions r ON r.id = oa.region_id ";

if ($filter_series_id > 0) {
  $st = $pdo->prepare($sql_list . " WHERE oa.exam_series_id = ? ORDER BY r.name, u.full_name");
  $st->execute([$filter_series_id]);
} else {
  $st = $pdo->query($sql_list . " ORDER BY s.name, r.name, u.full_name");
}
$officers = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Monitoring Officers | UVTAB</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --brand: #1b5cff;
      --brand-soft: #eef2ff;
      --sidebar: #0f172a;
      --bg: #f8fafc;
      --surface: #ffffff;
      --text-main: #1e293b;
      --text-muted: #64748b;
      --border: #e2e8f0;
      --radius: 12px;
      --success: #10b981;
      --danger: #ef4444;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0; font-family: 'Plus Jakarta Sans', sans-serif;
      background: var(--bg); color: var(--text-main); font-size: 14px;
    }

    .layout { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }

    /* Sidebar - Matching previous designs */
    .sidebar { background: var(--sidebar); color: #94a3b8; padding: 1.5rem; position: sticky; top: 0; height: 100vh; }
    .sidebar-logo { color: white; font-size: 1.5rem; font-weight: 800; margin-bottom: 2.5rem; display: flex; align-items: center; gap: 10px; }
    .sidebar-logo span { color: var(--brand); }
    .nav-item {
      display: flex; align-items: center; padding: 0.85rem 1rem; color: #94a3b8;
      text-decoration: none; border-radius: 8px; margin-bottom: 0.25rem; transition: 0.2s; font-weight: 500;
    }
    .nav-item:hover { background: #1e293b; color: white; }
    .nav-item.active { background: var(--brand); color: white; }

    .main { padding: 2rem 3rem; }
    .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
    .header h1 { font-size: 1.8rem; font-weight: 800; margin: 0; letter-spacing: -0.02em; }

    /* Alerts */
    .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* Grid Layout */
    .content-grid { display: grid; grid-template-columns: 380px 1fr; gap: 2rem; align-items: flex-start; }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .card h3 { margin: 0 0 1.25rem; font-size: 1.1rem; font-weight: 700; }

    /* Form Styling */
    label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; }
    .form-group { margin-bottom: 1.25rem; }
    input, select {
      width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px;
      font-family: inherit; font-size: 0.9rem; transition: 0.2s;
    }
    input:focus, select:focus { outline: none; border-color: var(--brand); ring: 2px var(--brand-soft); }

    .btn {
      padding: 0.7rem 1.2rem; border-radius: 8px; font-weight: 700; cursor: pointer;
      border: 1px solid transparent; transition: 0.2s; font-size: 0.85rem;
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-primary { background: var(--brand); color: white; width: 100%; }
    .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
    .btn-outline { background: white; border-color: var(--border); color: var(--text-main); }
    .btn-danger { background: #fef2f2; color: var(--danger); border-color: #fee2e2; width: 100%; margin-top: 5px; }

    /* Table Styling */
    .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f8fafc; padding: 1rem; text-align: left; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); }
    td { padding: 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    
    .status-pill { padding: 3px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; }
    .status-active { background: #dcfce7; color: #166534; }
    .status-inactive { background: #f1f5f9; color: #64748b; }

    .action-box { display: flex; flex-direction: column; gap: 10px; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid var(--border); }
    .mini-txt { font-size: 0.75rem; color: var(--text-muted); line-height: 1.4; margin-top: 5px; }
  </style>
</head>
<body>

<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-logo">UVTAB<span>.Admin</span></div>
    <a href="dashboard.php" class="nav-item">Dashboard</a>
    <a href="manage_examiners.php" class="nav-item">Assessors List</a>
    <a href="manage_officers.php" class="nav-item active">Monitoring Officers</a>
    <a href="manage_centers.php" class="nav-item">Centers</a>
    <a href="manage_regions.php" class="nav-item">Geo Regions</a>
  </aside>

  <main class="main">
    <header class="header">
      <div>
        <h1>Monitoring Officers</h1>
        <p style="color:var(--text-muted); margin:4px 0 0;">Deploy and manage field monitoring staff</p>
      </div>
      <div style="display:flex; gap:10px;">
         <a href="?export=csv<?= $filter_series_id > 0 ? '&series_id='.$filter_series_id : '' ?>" class="btn btn-outline">Export Registry (CSV)</a>
      </div>
    </header>

    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="content-grid">
      
      <section>
        <div class="card" style="margin-bottom: 1.5rem;">
          <h3>Add New Officer</h3>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
            
            <div class="form-group">
              <label>Full Name</label>
              <input name="full_name" required placeholder="John Doe">
            </div>

            <div class="form-group">
              <label>Phone (Username)</label>
              <input name="phone" required placeholder="07XXXXXXXX">
            </div>

            <div class="form-group">
              <label>Email Address</label>
              <input name="email" type="email" placeholder="officer@uvtab.go.ug">
            </div>

            <div class="form-group">
              <label>Account Status</label>
              <select name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>

            <div style="background: var(--brand-soft); padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem;">
              <div class="form-group">
                <label>Assign to Series</label>
                <select name="exam_series_id" required>
                  <option value="">-- Select Series --</option>
                  <?php foreach ($series as $s): ?>
                    <option value="<?= (int)$s['id']; ?>"><?= htmlspecialchars($s['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group" style="margin-bottom:0;">
                <label>Region Assignment</label>
                <select name="region_id" required>
                  <option value="">-- Select Region --</option>
                  <?php foreach ($regions as $r): ?>
                    <option value="<?= (int)$r['id']; ?>"><?= htmlspecialchars($r['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <button name="add" value="1" type="submit" class="btn btn-primary">Register & Assign Officer</button>
            <p class="mini-txt">Initial Password: <strong><?= DEFAULT_OFFICER_PASSWORD ?></strong></p>
          </form>
        </div>

        <div class="card">
          <h3>View Filter</h3>
          <label>Filter by Exam Series</label>
          <select onchange="location.href='?series_id=' + encodeURIComponent(this.value)">
            <option value="">All Active Series</option>
            <?php foreach ($series as $s): ?>
              <option value="<?= (int)$s['id']; ?>" <?= ($filter_series_id===(int)$s['id']?'selected':''); ?>>
                <?= htmlspecialchars($s['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </section>

      <section>
        <div class="table-card">
          <div style="padding:1rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
             <span style="font-weight:700;">Deployed Officers (<?= count($officers) ?>)</span>
          </div>
          <table>
            <thead>
              <tr>
                <th>Officer Details</th>
                <th>Assignment</th>
                <th>User Status</th>
                <th style="text-align:right">Management</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($officers as $o): ?>
                <tr>
                  <td>
                    <div style="font-weight:700; color:var(--text-main);"><?= htmlspecialchars($o['full_name']) ?></div>
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-top:2px;"><?= htmlspecialchars($o['phone']) ?></div>
                    <div style="font-size:0.75rem; color:var(--brand);"><?= htmlspecialchars($o['email'] ?? '') ?></div>
                  </td>
                  <td>
                    <div style="font-weight:700; font-size:0.85rem;"><?= htmlspecialchars($o['series_name']) ?></div>
                    <div style="font-size:0.8rem; color:var(--text-muted);">Region: <?= htmlspecialchars($o['region_name']) ?></div>
                    <div class="status-pill status-<?= $o['assignment_status'] ?>" style="display:inline-block; margin-top:5px;">Assigned: <?= $o['assignment_status'] ?></div>
                  </td>
                  <td>
                    <span class="status-pill status-<?= $o['user_status'] ?>"><?= $o['user_status'] ?></span>
                  </td>
                  <td style="min-width:240px;">
                    <div class="action-box">
                      <form method="post">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="user_id" value="<?= (int)$o['user_id']; ?>">
                        
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:5px; margin-bottom:5px;">
                          <select name="exam_series_id" required style="padding:5px; font-size:11px;">
                            <?php foreach ($series as $s): ?>
                              <option value="<?= (int)$s['id']; ?>" <?= ((int)$o['exam_series_id']===(int)$s['id']?'selected':''); ?>>
                                <?= htmlspecialchars($s['name']); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <select name="region_id" required style="padding:5px; font-size:11px;">
                            <?php foreach ($regions as $r): ?>
                              <option value="<?= (int)$r['id']; ?>" <?= ((int)$o['region_id']===(int)$r['id']?'selected':''); ?>>
                                <?= htmlspecialchars($r['name']); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        
                        <div style="display:flex; gap:5px;">
                          <select name="a_status" style="padding:5px; font-size:11px; flex:1;">
                            <option value="active" <?= ($o['assignment_status']==='active'?'selected':''); ?>>Active</option>
                            <option value="inactive" <?= ($o['assignment_status']==='inactive'?'selected':''); ?>>Inactive</option>
                          </select>
                          <button name="update_assignment" value="1" type="submit" class="btn btn-outline" style="padding:5px 8px; font-size:11px;">Update</button>
                        </div>
                      </form>

                      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:5px; border-top:1px solid var(--border); padding-top:10px;">
                        <form method="post" onsubmit="return confirm('Reset password to default (uvtab)?');">
                          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                          <input type="hidden" name="user_id" value="<?= (int)$o['user_id']; ?>">
                          <button name="reset_password" value="1" type="submit" class="btn btn-outline" style="font-size:11px; width:100%;">Reset Pass</button>
                        </form>

                        <form method="post">
                          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                          <input type="hidden" name="user_id" value="<?= (int)$o['user_id']; ?>">
                          <?php if ($o['user_status'] === 'active'): ?>
                            <input type="hidden" name="new_status" value="inactive">
                            <button name="toggle_user" value="1" type="submit" class="btn btn-danger" style="font-size:11px; padding:6px; margin:0;">Disable</button>
                          <?php else: ?>
                            <input type="hidden" name="new_status" value="active">
                            <button name="toggle_user" value="1" type="submit" class="btn btn-primary" style="font-size:11px; padding:6px; margin:0;">Enable</button>
                          <?php endif; ?>
                        </form>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </main>
</div>

</body>
</html>
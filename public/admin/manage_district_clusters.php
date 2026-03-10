<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

require_admin();

/** ---------- DEBUG (optional) ---------- */
// ini_set('display_errors', '1'); ini_set('display_startup_errors', '1'); error_reporting(E_ALL);

$msg = $err = null;

function norm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}

/**
 * MariaDB-safe table exists check (NO ESCAPE, underscores are OK)
 */
function tableExists(PDO $pdo, string $table): bool {
  $table = trim($table);
  if ($table === '') return false;
  if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;

  $sql = "SHOW TABLES LIKE " . $pdo->quote($table);
  $st = $pdo->query($sql);
  return (bool)$st->fetchColumn();
}

/**
 * CSRF fallbacks (in case your csrf helpers are not loaded in this page)
 */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['_csrf'];
  }
}
if (!function_exists('csrf_validate')) {
  function csrf_validate(?string $token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $ok = isset($_SESSION['_csrf'], $token) && hash_equals((string)$_SESSION['_csrf'], (string)$token);
    if (!$ok) { http_response_code(400); exit("Invalid CSRF token."); }
  }
}

/** --------- REQUIRE TABLES --------- */
$required = [
  'regions',
  'districts',
  'district_clusters',
  'cluster_districts',
  'cluster_officers',
  'centers',
  'examiner_applications',
  'users',
];

$missing = [];
foreach ($required as $t) {
  if (!tableExists($pdo, $t)) $missing[] = $t;
}

if ($missing) {
  header('Content-Type: text/html; charset=utf-8');
  echo "<h2 style='font-family:system-ui;color:#b00020'>Missing required table(s)</h2>";
  echo "<p style='font-family:system-ui'>These tables are missing in this DB: <b>" . htmlspecialchars(implode(', ', $missing)) . "</b></p>";
  echo "<p style='font-family:system-ui'>Connected DB: <b>" . htmlspecialchars((string)$pdo->query("SELECT DATABASE()")->fetchColumn()) . "</b></p>";
  exit;
}

/** --------- Users: label expr --------- */
$userLabelExpr = "CONCAT('User #', u.id)";
try {
  $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $candidates = ['full_name','name','username','email','phone'];
  foreach ($candidates as $c) {
    if (in_array($c, $cols, true)) {
      $userLabelExpr = "COALESCE(NULLIF(u.$c,''), CONCAT('User #', u.id))";
      break;
    }
  }
} catch (Throwable $e) {
  // keep fallback
}

/** --------- Role column (assumed 'role') --------- */
$roleCol = 'role';
try {
  $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array($roleCol, $userCols, true) && in_array('user_role', $userCols, true)) {
    $roleCol = 'user_role';
  }
} catch (Throwable $e) {
  // keep default
}

/** --------- DATA --------- */
$regions = $pdo->query("SELECT id, name FROM regions ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$cluster_id = (int)($_GET['cluster_id'] ?? 0);

/** --------- OFFICERS LIST (ONLY role=officer) --------- */
$officers = [];
try {
  $st = $pdo->prepare("
    SELECT u.id, $userLabelExpr AS label
    FROM users u
    WHERE u.$roleCol = 'officer'
    ORDER BY u.id DESC
    LIMIT 500
  ");
  $st->execute();
  $officers = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $officers = [];
}

/** --------- POST ACTIONS --------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  // Create cluster
  if (isset($_POST['create_cluster'])) {
    $region_id = (int)($_POST['region_id'] ?? 0);
    $name = norm((string)($_POST['name'] ?? ''));

    if ($region_id < 1) $err = "❌ Please select a region.";
    elseif ($name === '') $err = "❌ Cluster name required.";
    else {
      try {
        $st = $pdo->prepare("INSERT INTO district_clusters (region_id, name) VALUES (?, ?)");
        $st->execute([$region_id, $name]);
        $msg = "✅ Cluster '{$name}' created.";
      } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
    }
  }

  // Delete cluster
  if (isset($_POST['delete_cluster'])) {
    $cid = (int)($_POST['cluster_id'] ?? 0);
    if ($cid < 1) $err = "❌ Invalid cluster.";
    else {
      try {
        $st = $pdo->prepare("DELETE FROM district_clusters WHERE id=? LIMIT 1");
        $st->execute([$cid]);
        $msg = "✅ Cluster deleted.";
        if ($cluster_id === $cid) $cluster_id = 0;
      } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
    }
  }

  // Save cluster districts (replace selections)
  if (isset($_POST['save_districts'])) {
    $cid = (int)($_POST['cluster_id'] ?? 0);
    $selected = $_POST['district_ids'] ?? [];

    if ($cid < 1) $err = "❌ Invalid cluster.";
    elseif (!is_array($selected)) $err = "❌ Invalid selection.";
    else {
      try {
        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM cluster_districts WHERE cluster_id=?")->execute([$cid]);

        $ins = $pdo->prepare("INSERT INTO cluster_districts (cluster_id, district_id) VALUES (?, ?)");
        $count = 0;
        foreach ($selected as $did) {
          $did = (int)$did;
          if ($did < 1) continue;
          $ins->execute([$cid, $did]);
          $count++;
        }

        $pdo->commit();
        $msg = "✅ Saved {$count} district(s) in this cluster.";
        $cluster_id = $cid;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = "❌ " . $e->getMessage();
      }
    }
  }

  // Assign officer to cluster (ONLY if user is officer)
  if (isset($_POST['assign_officer'])) {
    $cid = (int)($_POST['cluster_id'] ?? 0);
    $officer_user_id = (int)($_POST['officer_user_id'] ?? 0);

    if ($cid < 1) $err = "❌ Invalid cluster.";
    elseif ($officer_user_id < 1) $err = "❌ Select an officer.";
    else {
      try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND $roleCol='officer' LIMIT 1");
        $chk->execute([$officer_user_id]);
        if ((int)$chk->fetchColumn() !== 1) {
          $err = "❌ Selected user is not an officer.";
        } else {
          $st = $pdo->prepare("INSERT IGNORE INTO cluster_officers (cluster_id, officer_user_id) VALUES (?, ?)");
          $st->execute([$cid, $officer_user_id]);
          $msg = "✅ Officer assigned to cluster.";
          $cluster_id = $cid;
        }
      } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
    }
  }

  // Remove officer from cluster
  if (isset($_POST['remove_officer'])) {
    $cid = (int)($_POST['cluster_id'] ?? 0);
    $oid = (int)($_POST['officer_user_id'] ?? 0);

    if ($cid < 1 || $oid < 1) $err = "❌ Invalid request.";
    else {
      try {
        $st = $pdo->prepare("DELETE FROM cluster_officers WHERE cluster_id=? AND officer_user_id=? LIMIT 1");
        $st->execute([$cid, $oid]);
        $msg = "✅ Officer removed from cluster.";
        $cluster_id = $cid;
      } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
    }
  }

  header("Location: manage_district_clusters.php" . ($cluster_id ? ("?cluster_id=".(int)$cluster_id) : ""));
  exit;
}

/** --------- CLUSTERS LIST + COUNTS --------- */
$clusters = $pdo->query("
  SELECT
    c.id,
    c.name,
    r.name AS region_name,
    (
      SELECT COUNT(*) FROM cluster_districts cd
      WHERE cd.cluster_id = c.id
    ) AS districts_count,
    (
      SELECT COUNT(*)
      FROM centers ce
      JOIN cluster_districts cd2 ON cd2.district_id = ce.district_id
      WHERE cd2.cluster_id = c.id
    ) AS centers_count,
    (
      SELECT COUNT(*)
      FROM examiner_applications ea
      JOIN cluster_districts cd3 ON cd3.district_id = ea.district_id
      WHERE cd3.cluster_id = c.id
        AND ea.status = 'approved'
    ) AS approved_examiners_count
  FROM district_clusters c
  JOIN regions r ON r.id = c.region_id
  ORDER BY r.name ASC, c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/** --------- SELECTED CLUSTER --------- */
$selectedCluster = null;
if ($cluster_id > 0) {
  $st = $pdo->prepare("
    SELECT c.*, r.name AS region_name
    FROM district_clusters c
    JOIN regions r ON r.id = c.region_id
    WHERE c.id = ?
    LIMIT 1
  ");
  $st->execute([$cluster_id]);
  $selectedCluster = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** --------- DISTRICTS IN SELECTED REGION + CURRENT SELECTIONS --------- */
$allDistricts = [];
$clusterDistrictIds = [];
if ($selectedCluster) {
  $st = $pdo->prepare("
    SELECT d.id, d.name
    FROM districts d
    WHERE d.region_id = ?
    ORDER BY d.name ASC
  ");
  $st->execute([(int)$selectedCluster['region_id']]);
  $allDistricts = $st->fetchAll(PDO::FETCH_ASSOC);

  $st = $pdo->prepare("SELECT district_id FROM cluster_districts WHERE cluster_id = ?");
  $st->execute([(int)$cluster_id]);
  $clusterDistrictIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** --------- OFFICERS ASSIGNED (ONLY role=officer) --------- */
$assignedOfficers = [];
if ($selectedCluster) {
  try {
    $st = $pdo->prepare("
      SELECT co.officer_user_id,
             $userLabelExpr AS label
      FROM cluster_officers co
      JOIN users u ON u.id = co.officer_user_id
      WHERE co.cluster_id = ?
        AND u.$roleCol = 'officer'
      ORDER BY co.assigned_at DESC
    ");
    $st->execute([(int)$cluster_id]);
    $assignedOfficers = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $assignedOfficers = [];
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>District Clusters</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui;background:#f6f7fb;margin:0;padding:18px}
    .card{background:#fff;border-radius:14px;padding:14px;box-shadow:0 8px 24px rgba(0,0,0,.06);margin-bottom:12px}
    .msg{color:green;font-weight:900}
    .err{color:#b00020;font-weight:900}
    table{width:100%;border-collapse:collapse;background:#fff}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;font-size:14px;vertical-align:middle}
    th{background:#f2f5ff}
    input[type="text"], select{padding:9px 10px;border:1px solid #ddd;border-radius:10px}
    button,a.btn{display:inline-block;padding:10px 12px;border-radius:10px;border:0;background:#1b5cff;color:#fff;text-decoration:none;font-weight:900;cursor:pointer}
    .btn2{background:#fff;color:#1b5cff;border:2px solid #1b5cff}
    .muted{color:#666;font-size:13px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:900;font-size:12px;background:#eef2ff;color:#1b5cff}
    .grid{display:grid;grid-template-columns:1.2fr .8fr;gap:12px}
    @media(max-width:1000px){.grid{grid-template-columns:1fr}}
    .small{font-size:12px}
    .danger{background:#fee2e2;color:#991b1b}
  </style>
</head>
<body>

<h2>District Clusters (Auto-coordinates Centers + Examiners)</h2>
<p class="row">
  <a class="btn btn2" href="dashboard.php">← Back</a>
  <span class="muted">Cluster contains districts. Centers + approved examiners in those districts belong to the cluster.</span>
</p>

<?php if ($msg) echo "<p class='msg'>".htmlspecialchars($msg)."</p>"; ?>
<?php if ($err) echo "<p class='err'>".htmlspecialchars($err)."</p>"; ?>

<div class="grid">

  <!-- LEFT -->
  <div class="card">
    <h3 style="margin:0 0 10px 0;">Clusters</h3>

    <table>
      <tr>
        <th>Cluster</th>
        <th>Region</th>
        <th>Districts</th>
        <th>Centers</th>
        <th>Approved Examiners</th>
        <th>Open</th>
      </tr>

      <?php if (!$clusters): ?>
        <tr><td colspan="6" class="muted">No clusters yet.</td></tr>
      <?php endif; ?>

      <?php foreach ($clusters as $c): ?>
        <tr>
          <td><b><?= htmlspecialchars((string)$c['name']) ?></b></td>
          <td><?= htmlspecialchars((string)$c['region_name']) ?></td>
          <td><?= (int)$c['districts_count'] ?></td>
          <td><?= (int)$c['centers_count'] ?></td>
          <td><?= (int)$c['approved_examiners_count'] ?></td>
          <td><a class="btn" href="?cluster_id=<?= (int)$c['id'] ?>" style="padding:8px 10px;">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <hr style="border:none;border-top:1px solid #eee;margin:14px 0;">

    <h3 style="margin:0 0 10px 0;">Create Cluster</h3>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <select name="region_id" required>
        <option value="">Select region…</option>
        <?php foreach ($regions as $r): ?>
          <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars((string)$r['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="name" placeholder="e.g. West Cluster 1" required>
      <button type="submit" name="create_cluster" value="1">Create</button>
    </form>
  </div>

  <!-- RIGHT -->
  <div class="card">
    <h3 style="margin:0 0 10px 0;">Manage Selected Cluster</h3>

    <?php if (!$selectedCluster): ?>
      <p class="muted">Open a cluster on the left to manage its districts and officer assignment.</p>
    <?php else: ?>

      <div class="row" style="justify-content:space-between;">
        <div class="row">
          <span class="pill"><?= htmlspecialchars((string)$selectedCluster['name']) ?></span>
          <span class="muted">Region: <b><?= htmlspecialchars((string)$selectedCluster['region_name']) ?></b></span>
        </div>

        <form method="post" onsubmit="return confirm('Delete this cluster?');">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="cluster_id" value="<?= (int)$cluster_id ?>">
          <button class="danger" type="submit" name="delete_cluster" value="1">Delete</button>
        </form>
      </div>

      <hr style="border:none;border-top:1px solid #eee;margin:14px 0;">

      <h4 style="margin:0 0 8px 0;">1) Select Districts in this Cluster</h4>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="cluster_id" value="<?= (int)$cluster_id ?>">

        <div style="max-height:260px;overflow:auto;border:1px solid #eee;border-radius:12px;padding:10px;background:#fafbff;">
          <?php if (!$allDistricts): ?>
            <p class="muted">No districts found in this region.</p>
          <?php endif; ?>

          <?php foreach ($allDistricts as $d): $did=(int)$d['id']; ?>
            <label class="row" style="gap:8px;margin:6px 0;">
              <input type="checkbox" name="district_ids[]" value="<?= $did ?>"
                <?= in_array($did, $clusterDistrictIds, true) ? 'checked' : '' ?>>
              <span><b><?= htmlspecialchars((string)$d['name']) ?></b></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="row" style="margin-top:10px;">
          <button type="submit" name="save_districts" value="1">Save Districts</button>
        </div>
      </form>

      <hr style="border:none;border-top:1px solid #eee;margin:14px 0;">

      <h4 style="margin:0 0 8px 0;">2) Assign Officer (Coordinator)</h4>

      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="cluster_id" value="<?= (int)$cluster_id ?>">

        <?php if ($officers): ?>
          <select name="officer_user_id" required>
            <option value="">Select officer…</option>
            <?php foreach ($officers as $o): ?>
              <option value="<?= (int)$o['id'] ?>">
                <?= htmlspecialchars((string)$o['label']) ?> (ID: <?= (int)$o['id'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" name="officer_user_id" placeholder="Enter officer user ID (users.id)" required>
        <?php endif; ?>

        <button type="submit" name="assign_officer" value="1">Assign</button>
      </form>

      <?php if ($assignedOfficers): ?>
        <p class="muted small" style="margin-top:10px;">Assigned Officers:</p>
        <?php foreach ($assignedOfficers as $ao): ?>
          <form method="post" class="row" style="margin:6px 0;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="cluster_id" value="<?= (int)$cluster_id ?>">
            <input type="hidden" name="officer_user_id" value="<?= (int)$ao['officer_user_id'] ?>">
            <span class="pill"><?= htmlspecialchars((string)$ao['label']) ?> (ID: <?= (int)$ao['officer_user_id'] ?>)</span>
            <button class="btn2" type="submit" name="remove_officer" value="1" style="padding:8px 10px;">Remove</button>
          </form>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="muted small" style="margin-top:10px;">No officers assigned yet.</p>
      <?php endif; ?>

    <?php endif; ?>
  </div>

</div>
</body>
</html>
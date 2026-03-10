<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

require_admin();

$msg = $err = null;

/** ----------------- HELPERS ----------------- */
function norm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}

/**
 * MariaDB-safe table exists check:
 * NOTE: MariaDB does not support placeholders in SHOW TABLES LIKE ?
 */
function tableExists(PDO $pdo, string $table): bool {
  $table = trim($table);
  // Basic safety: strip characters that could affect LIKE or quoting
  $table = str_replace(['`', '"', "'", '\\', '%', '_'], '', $table);
  if ($table === '') return false;

  $sql = "SHOW TABLES LIKE " . $pdo->quote($table);
  $st = $pdo->query($sql);
  return (bool)$st->fetchColumn();
}

if (!tableExists($pdo, 'districts') || !tableExists($pdo, 'regions')) {
  die("Missing required table(s): districts and/or regions.");
}

/** ----------------- FETCH REGIONS ----------------- */
$regions = $pdo->query("SELECT id, name FROM regions ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/** ----------------- ACTION: SINGLE ASSIGN ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_one'])) {
  csrf_validate($_POST['csrf'] ?? null);

  $districtId = (int)($_POST['district_id'] ?? 0);
  $regionId   = (int)($_POST['region_id'] ?? 0); // 0 => NULL (unassign)

  if ($districtId < 1) {
    $err = "❌ Invalid district.";
  } else {
    try {
      $st = $pdo->prepare("UPDATE districts SET region_id=? WHERE id=? LIMIT 1");
      $st->execute([$regionId > 0 ? $regionId : null, $districtId]);
      $msg = "✅ District updated successfully.";
    } catch (Throwable $e) {
      $err = "❌ " . $e->getMessage();
    }
  }
}

/** ----------------- ACTION: BULK ASSIGN ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_bulk'])) {
  csrf_validate($_POST['csrf'] ?? null);

  $regionId = (int)($_POST['bulk_region_id'] ?? 0); // 0 => NULL (unassign)
  $selected = $_POST['district_ids'] ?? [];

  if (!is_array($selected) || count($selected) < 1) {
    $err = "❌ Please select at least one district.";
  } else {
    try {
      $pdo->beginTransaction();

      $st = $pdo->prepare("UPDATE districts SET region_id=? WHERE id=? LIMIT 1");
      $updated = 0;

      foreach ($selected as $id) {
        $did = (int)$id;
        if ($did < 1) continue;
        $st->execute([$regionId > 0 ? $regionId : null, $did]);
        $updated++;
      }

      $pdo->commit();
      $msg = "✅ Bulk update complete. Updated {$updated} district(s).";
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = "❌ " . $e->getMessage();
    }
  }
}

/** ----------------- FILTERS + PAGINATION ----------------- */
$q = norm((string)($_GET['q'] ?? ''));
$filter = norm((string)($_GET['filter'] ?? 'all')); // all | unassigned | assigned

$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$perPage = (int)($_GET['per_page'] ?? 50);
$allowedPer = [25, 50, 100, 200];
if (!in_array($perPage, $allowedPer, true)) $perPage = 50;

$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($q !== '') {
  $where[] = "d.name LIKE ?";
  $params[] = "%" . $q . "%";
}

if ($filter === 'unassigned') {
  $where[] = "d.region_id IS NULL";
} elseif ($filter === 'assigned') {
  $where[] = "d.region_id IS NOT NULL";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/** Count */
$cntSql = "
  SELECT COUNT(*) c
  FROM districts d
  $whereSql
";
$cntSt = $pdo->prepare($cntSql);
$cntSt->execute($params);
$total = (int)($cntSt->fetchColumn() ?: 0);

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;

/** List */
$listSql = "
  SELECT d.id, d.name AS district_name, d.region_id, r.name AS region_name
  FROM districts d
  LEFT JOIN regions r ON r.id = d.region_id
  $whereSql
  ORDER BY d.name ASC
  LIMIT $perPage OFFSET $offset
";
$listSt = $pdo->prepare($listSql);
$listSt->execute($params);
$districts = $listSt->fetchAll(PDO::FETCH_ASSOC);

function qurl(array $extra = []): string {
  $q = $_GET;
  foreach ($extra as $k => $v) $q[$k] = $v;
  return '?' . http_build_query($q);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Map Districts to Regions</title>
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
    .topbar{display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .pager{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .pagebtn{padding:8px 10px;border-radius:10px;border:1px solid #ddd;background:#fff;text-decoration:none;color:#111;font-weight:800}
    .pagebtn.active{background:#1b5cff;color:#fff;border-color:#1b5cff}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:900;font-size:12px}
    .p-un{background:#fff0f0;color:#8c1d18}
    .p-as{background:#e8fff1;color:#0b6b2f}
    .small{font-size:12px}
  </style>
</head>
<body>

<h2>Map Districts → Regions</h2>
<p><a class="btn btn2" href="dashboard.php">← Back</a></p>

<?php if ($msg) echo "<p class='msg'>".htmlspecialchars($msg)."</p>"; ?>
<?php if ($err) echo "<p class='err'>".htmlspecialchars($err)."</p>"; ?>

<div class="card">
  <div class="topbar">
    <form method="get" class="row">
      <input type="text" name="q" placeholder="Search district…" value="<?php echo htmlspecialchars($q); ?>">
      <select name="filter">
        <option value="all" <?php echo $filter==='all'?'selected':''; ?>>All</option>
        <option value="unassigned" <?php echo $filter==='unassigned'?'selected':''; ?>>Unassigned</option>
        <option value="assigned" <?php echo $filter==='assigned'?'selected':''; ?>>Assigned</option>
      </select>
      <select name="per_page">
        <?php foreach ([25,50,100,200] as $pp): ?>
          <option value="<?php echo $pp; ?>" <?php echo $pp===$perPage?'selected':''; ?>><?php echo $pp; ?> / page</option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Apply</button>
    </form>

    <div class="muted">
      Total: <b><?php echo (int)$total; ?></b> • Page <b><?php echo (int)$page; ?></b> / <b><?php echo (int)$totalPages; ?></b>
    </div>
  </div>

  <div class="muted small" style="margin-top:8px;">
    Tip: Use <b>Unassigned</b> filter, select many districts, then bulk assign to “West 1 / West 2 / …”
  </div>
</div>

<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">

  <div class="row" style="justify-content:space-between;">
    <div class="row">
      <label style="font-weight:900;">Bulk Assign Selected →</label>
      <select name="bulk_region_id" required>
        <option value="">Select region…</option>
        <?php foreach ($regions as $r): ?>
          <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars((string)$r['name']); ?></option>
        <?php endforeach; ?>
        <option value="0">Unassign (set NULL)</option>
      </select>
      <button type="submit" name="assign_bulk" value="1">Apply to Selected</button>
    </div>

    <div class="pager">
      <?php if ($page > 1): ?>
        <a class="pagebtn" href="<?php echo htmlspecialchars(qurl(['page' => $page-1])); ?>">← Prev</a>
      <?php endif; ?>
      <a class="pagebtn active" href="#"><?php echo (int)$page; ?></a>
      <?php if ($page < $totalPages): ?>
        <a class="pagebtn" href="<?php echo htmlspecialchars(qurl(['page' => $page+1])); ?>">Next →</a>
      <?php endif; ?>
    </div>
  </div>

  <table style="margin-top:12px;">
    <tr>
      <th style="width:40px;"><input type="checkbox" onclick="toggleAll(this)"></th>
      <th>District</th>
      <th>Current Region</th>
      <th style="width:320px;">Set Region</th>
      <th style="width:120px;">Action</th>
    </tr>

    <?php if (!$districts): ?>
      <tr><td colspan="5" class="muted">No districts found.</td></tr>
    <?php endif; ?>

    <?php foreach ($districts as $d): ?>
      <tr>
        <td>
          <input type="checkbox" name="district_ids[]" value="<?php echo (int)$d['id']; ?>">
        </td>
        <td><b><?php echo htmlspecialchars((string)$d['district_name']); ?></b></td>
        <td>
          <?php if (!empty($d['region_name'])): ?>
            <span class="pill p-as"><?php echo htmlspecialchars((string)$d['region_name']); ?></span>
          <?php else: ?>
            <span class="pill p-un">Unassigned</span>
          <?php endif; ?>
        </td>
        <td>
          <form method="post" style="display:flex;gap:10px;align-items:center;">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="district_id" value="<?php echo (int)$d['id']; ?>">
            <select name="region_id" required style="min-width:220px;">
              <option value="0">Unassign (NULL)</option>
              <?php foreach ($regions as $r): ?>
                <option value="<?php echo (int)$r['id']; ?>"
                  <?php echo ((int)($d['region_id'] ?? 0) === (int)$r['id']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars((string)$r['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" name="assign_one" value="1">Save</button>
          </form>
        </td>
        <td class="muted small">ID: <?php echo (int)$d['id']; ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</form>

<script>
function toggleAll(master){
  const boxes = document.querySelectorAll('input[type="checkbox"][name="district_ids[]"]');
  boxes.forEach(b => b.checked = master.checked);
}
</script>

</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

require_admin();

$msg = $err = null;

/**
 * ---------- URL helpers ----------
 */
function base_url(): string {
  if (defined('APP_URL') && is_string(APP_URL) && trim(APP_URL) !== '') {
    return rtrim(APP_URL, '/');
  }
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $pos = strpos($script, '/admin/');
  if ($pos !== false) return rtrim(substr($script, 0, $pos), '/');
  return '';
}
$BASE = base_url();

function admin_dashboard_url(string $BASE): string {
  return $BASE . '/public/admin/dashboard.php';
}

/**
 * ---------- Helpers ----------
 */
function norm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}

function makeLocationCode(string $centerNo, string $district, string $location): string {
  $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $centerNo));
  $d = strtoupper(substr(preg_replace('/[^A-Z]/i', '', $district), 0, 3));
  $l = strtoupper(substr(preg_replace('/[^A-Z]/i', '', $location), 0, 3));
  return trim($base . '-' . $d . '-' . $l, '-');
}

/** ---- AJAX: districts by region (optional use) ---- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'districts') {
  header('Content-Type: application/json; charset=utf-8');
  $regionId = (int)($_GET['region_id'] ?? 0);

  try {
    if ($regionId > 0) {
      $st = $pdo->prepare("SELECT id, name FROM districts WHERE region_id=? ORDER BY name");
      $st->execute([$regionId]);
    } else {
      $st = $pdo->query("SELECT id, name FROM districts ORDER BY name");
    }
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load districts']);
  }
  exit;
}

/** ---- AJAX: center lookup by number (for ADD form autofill) ---- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'center_by_number') {
  header('Content-Type: application/json; charset=utf-8');

  $cn = strtoupper(norm((string)($_GET['center_number'] ?? '')));
  if ($cn === '' || mb_strlen($cn) < 2) {
    echo json_encode(['found' => false]);
    exit;
  }

  try {
    $st = $pdo->prepare("
      SELECT center_name
      FROM centers
      WHERE center_number = ?
      LIMIT 1
    ");
    $st->execute([$cn]);
    $name = (string)($st->fetchColumn() ?: '');

    if ($name !== '') {
      echo json_encode(['found' => true, 'center_name' => $name]);
    } else {
      echo json_encode(['found' => false]);
    }
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to lookup centre']);
  }
  exit;
}

/** ---- AJAX: center autocomplete (DB) for SEARCH box ---- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'centers') {
  header('Content-Type: application/json; charset=utf-8');

  $q = norm((string)($_GET['q'] ?? ''));
  if ($q === '' || mb_strlen($q) < 1) {
    echo json_encode([]);
    exit;
  }

  try {
    $st = $pdo->prepare("
      SELECT c.center_number, c.center_name
      FROM centers c
      WHERE c.center_number LIKE ? OR c.center_name LIKE ?
      ORDER BY c.center_number
      LIMIT 20
    ");
    $like = '%' . $q . '%';
    $st->execute([$like, $like]);
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load centres']);
  }
  exit;
}

/** ---- EXPORT (CSV) ---- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="centers_export_' . date('Ymd_His') . '.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['Center Number','Center Name','Location','Region','District','Status']);

  $q = $pdo->query("
    SELECT
      c.center_number,
      c.center_name,
      c.location_name,
      COALESCE(r.name,'Unassigned') AS region_name,
      d.name AS district_name,
      c.status
    FROM centers c
    JOIN districts d ON d.id = c.district_id
    LEFT JOIN regions r ON r.id = d.region_id
    ORDER BY region_name, d.name, c.center_name
  ");

  while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $row['center_number'],
      $row['center_name'],
      $row['location_name'],
      $row['region_name'],
      $row['district_name'],
      $row['status'],
    ]);
  }
  fclose($out);
  exit;
}

/** ---- EXPORT (XLSX) ---- */
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
  require_once __DIR__ . '/../../vendor/autoload.php';

  $rows = $pdo->query("
    SELECT
      c.center_number,
      c.center_name,
      c.location_name,
      COALESCE(r.name,'Unassigned') AS region_name,
      d.name AS district_name,
      c.status
    FROM centers c
    JOIN districts d ON d.id = c.district_id
    LEFT JOIN regions r ON r.id = d.region_id
    ORDER BY region_name, d.name, c.center_name
  ")->fetchAll(PDO::FETCH_ASSOC);

  $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('Centers');

  $headers = ['Center Number','Center Name','Location','Region','District','Status'];
  $sheet->fromArray($headers, null, 'A1');

  $i = 2;
  foreach ($rows as $r) {
    $sheet->fromArray([
      $r['center_number'],
      $r['center_name'],
      $r['location_name'],
      $r['region_name'],
      $r['district_name'],
      $r['status'],
    ], null, 'A'.$i);
    $i++;
  }

  foreach (range('A','F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
  }

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="centers_export_' . date('Ymd_His') . '.xlsx"');
  header('Cache-Control: max-age=0');

  $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
  $writer->save('php://output');
  exit;
}

/** ---- POST actions ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  /** Add center */
  if (isset($_POST['add'])) {
    $center_number = strtoupper(norm((string)($_POST['center_number'] ?? '')));
    $center_name   = norm((string)($_POST['center_name'] ?? ''));
    $district_id   = (int)($_POST['district_id'] ?? 0);
    $status        = (string)($_POST['status'] ?? 'active');
    if (!in_array($status, ['active','inactive'], true)) $status = 'active';

    if ($center_number === '') $err = "Center number required.";
    elseif ($center_name === '') $err = "Center name required.";
    elseif ($district_id <= 0) $err = "Select a district.";
    else {
      try {
        $chk = $pdo->prepare("SELECT id FROM districts WHERE id=? LIMIT 1");
        $chk->execute([$district_id]);
        if (!$chk->fetchColumn()) throw new RuntimeException("Selected district not found.");

        $st = $pdo->prepare("
          INSERT INTO centers (center_number, center_name, location_name, location_code, district_id, status, created_at)
          VALUES (?,?,?,?,?,?,NOW())
        ");
        $st->execute([$center_number, $center_name, null, null, $district_id, $status]);

        $msg = "✅ Center added.";
      } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
    }
  }

  /** Delete center */
  if (isset($_POST['delete'])) {
    $id = (int)($_POST['id'] ?? 0);
    try {
      $st = $pdo->prepare("DELETE FROM centers WHERE id=?");
      $st->execute([$id]);
      $msg = "✅ Center deleted.";
    } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
  }

  /** Import centers */
  if (isset($_POST['import'])) {
    try {
      if (empty($_FILES['excel']['name']) || !is_uploaded_file($_FILES['excel']['tmp_name'])) {
        throw new RuntimeException("Please choose an Excel/CSV file to import.");
      }

      $tmp = $_FILES['excel']['tmp_name'];
      $ext = strtolower(pathinfo((string)$_FILES['excel']['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['xlsx','xls','csv'], true)) {
        throw new RuntimeException("Unsupported file type. Upload .xlsx, .xls or .csv");
      }

      $items = [];

      if ($ext === 'csv') {
        $fh = fopen($tmp, 'r');
        if (!$fh) throw new RuntimeException("Failed to read CSV.");

        $header = fgetcsv($fh);
        if (!$header) throw new RuntimeException("CSV is empty.");

        $h = array_map(fn($x) => mb_strtolower(norm((string)$x)), $header);

        $idxCenterNo = array_search('center number', $h, true);
        if ($idxCenterNo === false) $idxCenterNo = array_search('center_number', $h, true);

        $idxCenterName = array_search('center name', $h, true);
        if ($idxCenterName === false) $idxCenterName = array_search('center_name', $h, true);

        $idxDistrict = array_search('district', $h, true);
        $idxLocation = array_search('location', $h, true);

        if ($idxCenterNo === false || $idxCenterName === false || $idxDistrict === false || $idxLocation === false) {
          throw new RuntimeException("Missing columns. Required: Center Number, Center Name, District, Location");
        }

        while (($row = fgetcsv($fh)) !== false) {
          $items[] = [
            'center_number' => (string)($row[$idxCenterNo] ?? ''),
            'center_name'   => (string)($row[$idxCenterName] ?? ''),
            'district'      => (string)($row[$idxDistrict] ?? ''),
            'location'      => (string)($row[$idxLocation] ?? ''),
            'status'        => 'active',
          ];
        }
        fclose($fh);

      } else {
        require_once __DIR__ . '/../../vendor/autoload.php';

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (!$rows || count($rows) < 2) throw new RuntimeException("Excel seems empty.");

        $head = $rows[1] ?? [];
        $map = [];

        foreach ($head as $col => $val) {
          $v = mb_strtolower(norm((string)$val));
          if (in_array($v, ['center number','centre number','center_number','centre_number'], true)) $map['center_number'] = (string)$col;
          if (in_array($v, ['center name','centre name','center_name','centre_name','name'], true)) $map['center_name'] = (string)$col;
          if ($v === 'district') $map['district'] = (string)$col;
          if ($v === 'location') $map['location'] = (string)$col;
        }

        if (empty($map['center_number']) || empty($map['center_name']) || empty($map['district']) || empty($map['location'])) {
          throw new RuntimeException("Missing columns. Required: Center Number, Center Name, District, Location");
        }

        $maxRow = count($rows);
        for ($i = 2; $i <= $maxRow; $i++) {
          $items[] = [
            'center_number' => (string)($rows[$i][$map['center_number']] ?? ''),
            'center_name'   => (string)($rows[$i][$map['center_name']] ?? ''),
            'district'      => (string)($rows[$i][$map['district']] ?? ''),
            'location'      => (string)($rows[$i][$map['location']] ?? ''),
            'status'        => 'active',
          ];
        }
      }

      if (!$items) {
        $msg = "✅ Import complete: nothing to import.";
      } else {
        $findDistrict = $pdo->prepare("SELECT id FROM districts WHERE LOWER(name)=LOWER(?) LIMIT 1");
        $insDistrict  = $pdo->prepare("INSERT INTO districts (name, region_id) VALUES (?, NULL)");
        $existsCenter = $pdo->prepare("SELECT id FROM centers WHERE center_number=? LIMIT 1");

        $upsertCenter = $pdo->prepare("
          INSERT INTO centers (center_number, center_name, location_name, location_code, district_id, status, created_at)
          VALUES (?, ?, ?, ?, ?, ?, NOW())
          ON DUPLICATE KEY UPDATE
            center_name=VALUES(center_name),
            location_name=VALUES(location_name),
            location_code=VALUES(location_code),
            district_id=VALUES(district_id),
            status=VALUES(status)
        ");

        $pdo->beginTransaction();

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $failed   = 0;
        $samples  = [];

        foreach ($items as $it) {
          $cn = strtoupper(norm((string)$it['center_number']));
          $cname = norm((string)$it['center_name']);
          $districtName = norm((string)$it['district']);
          $locationName = norm((string)$it['location']);

          if ($cn === '' || $cname === '' || $districtName === '') { $skipped++; continue; }

          try {
            $findDistrict->execute([$districtName]);
            $districtId = (int)($findDistrict->fetchColumn() ?: 0);

            if ($districtId <= 0) {
              $insDistrict->execute([$districtName]);
              $districtId = (int)$pdo->lastInsertId();
            }

            $locCode = makeLocationCode($cn, $districtName, $locationName);

            $existsCenter->execute([$cn]);
            $had = (int)($existsCenter->fetchColumn() ?: 0);

            $upsertCenter->execute([
              $cn,
              $cname,
              $locationName !== '' ? $locationName : null,
              $locCode !== '' ? $locCode : null,
              $districtId,
              'active'
            ]);

            if ($had > 0) $updated++; else $inserted++;

          } catch (Throwable $e) {
            $failed++;
            if (count($samples) < 8) $samples[] = ($cn ?: 'UNKNOWN') . " => " . $e->getMessage();
          }
        }

        $pdo->commit();

        $msg = "✅ Import done: Inserted {$inserted}, Updated {$updated}, Skipped {$skipped}, Failed {$failed}.";
        if ($samples) $msg .= " Samples: " . implode(" | ", $samples);
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = "❌ " . $e->getMessage();
    }
  }
}

/** ---- load data for UI ---- */
$regions = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$allDistricts = $pdo->query("
  SELECT d.id, d.name
  FROM districts d
  ORDER BY d.name
")->fetchAll(PDO::FETCH_ASSOC);

/** ---- filters + pagination ---- */
$search = norm((string)($_GET['q'] ?? ''));
$f_status  = (string)($_GET['status'] ?? '');
$f_region  = (int)($_GET['region_id'] ?? 0);
$f_district = (int)($_GET['district_id'] ?? 0);

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($search !== '') {
  $where[] = "(c.center_number LIKE ? OR c.center_name LIKE ? OR d.name LIKE ? OR r.name LIKE ?)";
  $like = '%' . $search . '%';
  $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

if (in_array($f_status, ['active','inactive'], true)) {
  $where[] = "c.status = ?";
  $params[] = $f_status;
}

if ($f_region > 0) {
  $where[] = "d.region_id = ?";
  $params[] = $f_region;
}

if ($f_district > 0) {
  $where[] = "c.district_id = ?";
  $params[] = $f_district;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Total rows for pagination
$stCount = $pdo->prepare("
  SELECT COUNT(*)
  FROM centers c
  JOIN districts d ON d.id = c.district_id
  LEFT JOIN regions r ON r.id = d.region_id
  $whereSql
");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;

// Paged data
$st = $pdo->prepare("
  SELECT
    c.id,
    c.center_number,
    c.center_name,
    c.location_name,
    c.status,
    COALESCE(r.name,'Unassigned') AS region_name,
    d.name AS district_name
  FROM centers c
  JOIN districts d ON d.id = c.district_id
  LEFT JOIN regions r ON r.id = d.region_id
  $whereSql
  ORDER BY region_name, d.name, c.center_name
  LIMIT $perPage OFFSET $offset
");
$st->execute($params);
$centers = $st->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Manage Centres</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/public/assets/css/main.css">
</head>
<body>

  <h2>Manage Centres</h2>
  <p><a href="<?php echo htmlspecialchars(admin_dashboard_url($BASE)); ?>">← Back to Dashboard</a></p>

  <?php if ($msg) echo "<p class='msg'>".htmlspecialchars($msg)."</p>"; ?>
  <?php if ($err) echo "<p class='err'>".htmlspecialchars($err)."</p>"; ?>

  <div class="row">

    <!-- Add Centre -->
    <form class="card" method="post" style="max-width:520px;min-width:320px;">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <h3 style="margin:0 0 10px;">Add Centre</h3>

      <label>Center Number</label>
      <input id="addCenterNo" name="center_number" placeholder="e.g. UBT001" required>
      <div id="addLookupResult" class="live-lookup"></div>
      <div class="hint">Type a center number to see the existing center name (if already in database).</div>

      <label>Center Name</label>
      <input id="addCenterName" name="center_name" placeholder="e.g. Nakawa Vocational Training Institute" required>

      <label>District</label>
      <select name="district_id" required>
        <option value="">-- Select District --</option>
        <?php foreach ($allDistricts as $d): ?>
          <option value="<?php echo (int)$d['id']; ?>">
            <?php echo htmlspecialchars((string)$d['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>Status</label>
      <select name="status">
        <option value="active" selected>active</option>
        <option value="inactive">inactive</option>
      </select>

      <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
        <button name="add" value="1" type="submit">Add Centre</button>
      </div>

      <p class="muted" style="margin:10px 0 0;">
        Regions can be assigned later from <b>map_districts.php</b>. Centers will show region as <b>Unassigned</b> until then.
      </p>
    </form>

    <!-- Import / Export -->
    <div class="card" style="max-width:520px;min-width:320px;">
      <h3 style="margin:0 0 10px;">Import / Export</h3>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <label>Import Centres (Excel/CSV)</label>
        <input type="file" name="excel" accept=".xlsx,.xls,.csv" required>

        <p style="margin:8px 0;color:#444;font-size:13px;">
          Required columns: <b>Center Number</b>, <b>Center Name</b>, <b>District</b>, <b>Location</b>.
        </p>

        <p class="muted" style="margin:8px 0;">
          Districts will be auto-created if missing (region remains unassigned). Location will be stored in <b>location_name</b>.
        </p>

        <button name="import" value="1" type="submit">Import</button>
      </form>

      <div class="tools">
        <a class="btn2" href="?export=csv">Export CSV</a>
        <a class="btn2" href="?export=xlsx">Export Excel</a>
      </div>

      <p class="muted" style="margin-top:10px;">
        If import says “Failed”, check the <b>Samples</b> message for the exact error.
      </p>
    </div>

  </div>

  <!-- Filters + Search -->
  <div class="card" style="margin-top:18px;">
    <form method="get" class="row" style="gap:10px;align-items:flex-end;">
      <div style="min-width:260px;flex:1;">
        <label>Search Centre (Number / Name)</label>
        <input id="centerSearch" name="q" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Start typing e.g. UBT001 or Nakawa..." autocomplete="off">
        <div id="suggestBox" class="card suggest"></div>
        <div class="muted">Selecting a suggestion fills the <b>Center Number</b>.</div>
      </div>

      <div style="min-width:200px;">
        <label>Status</label>
        <select name="status">
          <option value="">All</option>
          <option value="active" <?php if($f_status==='active') echo 'selected'; ?>>active</option>
          <option value="inactive" <?php if($f_status==='inactive') echo 'selected'; ?>>inactive</option>
        </select>
      </div>

      <div style="min-width:220px;">
        <label>Region</label>
        <select name="region_id" id="regionSelect">
          <option value="0">All / Unassigned</option>
          <?php foreach ($regions as $r): ?>
            <option value="<?php echo (int)$r['id']; ?>" <?php if($f_region===(int)$r['id']) echo 'selected'; ?>>
              <?php echo htmlspecialchars((string)$r['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="min-width:220px;">
        <label>District</label>
        <select name="district_id" id="districtSelect">
          <option value="0">All Districts</option>
          <?php foreach ($allDistricts as $d): ?>
            <option value="<?php echo (int)$d['id']; ?>" <?php if($f_district===(int)$d['id']) echo 'selected'; ?>>
              <?php echo htmlspecialchars((string)$d['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <button type="submit">Apply</button>
        <a class="btn2" href="?" style="display:inline-block;margin-left:8px;text-decoration:none;padding:10px 12px;border-radius:10px;">Reset</a>
      </div>
    </form>
  </div>

  <h3 style="margin-top:18px;">
    Existing Centres (<?php echo (int)$totalRows; ?>)
    <span class="muted">— showing <?php echo count($centers); ?> on this page</span>
  </h3>

  <table>
    <tr>
      <th>Center</th>
      <th>Location</th>
      <th>Region</th>
      <th>District</th>
      <th>Status</th>
      <th>Action</th>
    </tr>
    <?php foreach ($centers as $c): ?>
      <tr>
        <td><?php echo htmlspecialchars((string)$c['center_number'] . ' - ' . (string)$c['center_name']); ?></td>
        <td><?php echo htmlspecialchars((string)($c['location_name'] ?? '')); ?></td>
        <td><?php echo htmlspecialchars((string)$c['region_name']); ?></td>
        <td><?php echo htmlspecialchars((string)$c['district_name']); ?></td>
        <td>
          <?php if ((string)$c['status'] === 'active'): ?>
            <span class="pill pillA">active</span>
          <?php else: ?>
            <span class="pill pillI">inactive</span>
          <?php endif; ?>
        </td>
        <td>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this centre?');">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
            <button name="delete" value="1" type="submit" style="background:#b00020;">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php
    $qs = $_GET;
    unset($qs['page']);
    $baseQuery = http_build_query($qs);
    $baseQuery = $baseQuery ? ($baseQuery . '&') : '';
  ?>

  <div class="pager" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <?php if ($page > 1): ?>
      <a class="btn2" href="?<?php echo htmlspecialchars($baseQuery . 'page=' . ($page-1)); ?>">← Prev</a>
    <?php endif; ?>

    <span class="muted">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></span>

    <?php
      $start = max(1, $page - 3);
      $end = min($totalPages, $page + 3);
      for ($p = $start; $p <= $end; $p++):
        $active = ($p === $page);
    ?>
      <a href="?<?php echo htmlspecialchars($baseQuery . 'page=' . $p); ?>"
         style="<?php echo $active
           ? 'background:#1b5cff;color:#fff;font-weight:900;'
           : 'background:#fff;border:2px solid #1b5cff;color:#1b5cff;font-weight:800;'
         ?>">
        <?php echo (int)$p; ?>
      </a>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
      <a class="btn2" href="?<?php echo htmlspecialchars($baseQuery . 'page=' . ($page+1)); ?>">Next →</a>
    <?php endif; ?>
  </div>

  <script>
  (function(){
    // ---------------------------
    // ADD FORM: Center number -> show name below + (optional) autofill name field
    // ---------------------------
    const addNo = document.getElementById('addCenterNo');
    const addName = document.getElementById('addCenterName');
    const addResult = document.getElementById('addLookupResult');

    let t1 = null;
    function debounce1(fn, ms){
      return function(...args){
        clearTimeout(t1);
        t1 = setTimeout(() => fn.apply(this, args), ms);
      }
    }

    async function lookupCenterNumber(cn){
      if(!cn || cn.length < 2){
        addResult.textContent = '';
        addResult.className = 'live-lookup';
        return;
      }
      const res = await fetch(`?ajax=center_by_number&center_number=${encodeURIComponent(cn)}`, {
        headers: {'Accept':'application/json'}
      });
      if(!res.ok){
        addResult.textContent = '';
        addResult.className = 'live-lookup';
        return;
      }
      const data = await res.json();
      if(data && data.found){
        addResult.textContent = '✔ Existing centre name: ' + data.center_name;
        addResult.className = 'live-lookup live-ok';

        // If you want to auto-fill the center name input when found:
        // Only fill if user has not typed anything yet
        if(addName && addName.value.trim() === ''){
          addName.value = data.center_name;
        }
      } else {
        addResult.textContent = '✖ No centre found with this number (you can add it).';
        addResult.className = 'live-lookup live-warn';
      }
    }

    if(addNo){
      addNo.addEventListener('input', debounce1(() => {
        const cn = (addNo.value || '').trim().toUpperCase();
        // force uppercase
        addNo.value = cn;
        lookupCenterNumber(cn);
      }, 180));
    }

    // ---------------------------
    // SEARCH BOX: autocomplete suggestions (fills ONLY center number)
    // ---------------------------
    const input = document.getElementById('centerSearch');
    const box = document.getElementById('suggestBox');

    let t2 = null;
    function debounce2(fn, ms){
      return function(...args){
        clearTimeout(t2);
        t2 = setTimeout(() => fn.apply(this, args), ms);
      }
    }

    function hideBox(){
      box.style.display = 'none';
      box.innerHTML = '';
    }

    function renderSuggestions(items){
      if(!items || items.length === 0){ hideBox(); return; }
      box.innerHTML = '';
      items.forEach(it => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.style.cssText = 'display:block;width:100%;text-align:left;padding:10px;border:0;background:#fff;cursor:pointer;border-radius:10px;font-weight:800;';
        btn.onmouseenter = () => btn.style.background = '#f2f5ff';
        btn.onmouseleave = () => btn.style.background = '#fff';
        btn.textContent = it.center_number + ' — ' + it.center_name;

        btn.addEventListener('click', () => {
          input.value = it.center_number;
          hideBox();
        });

        box.appendChild(btn);
      });
      box.style.display = 'block';
    }

    async function loadCentres(q){
      if(!q){ hideBox(); return; }
      const res = await fetch(`?ajax=centers&q=${encodeURIComponent(q)}`, {headers:{'Accept':'application/json'}});
      if(!res.ok){ hideBox(); return; }
      const data = await res.json();
      renderSuggestions(data || []);
    }

    if (input) {
      input.addEventListener('input', debounce2(() => {
        const q = input.value.trim();
        loadCentres(q);
      }, 150));

      document.addEventListener('click', (e) => {
        if (!box.contains(e.target) && e.target !== input) hideBox();
      });
    }

    // ---------------------------
    // Region -> District dropdown
    // ---------------------------
    const regionSelect = document.getElementById('regionSelect');
    const districtSelect = document.getElementById('districtSelect');

    async function loadDistricts(regionId){
      const res = await fetch(`?ajax=districts&region_id=${encodeURIComponent(regionId)}`, {headers:{'Accept':'application/json'}});
      if(!res.ok) return;
      const data = await res.json();
      const current = districtSelect.value;

      districtSelect.innerHTML = '<option value="0">All Districts</option>';
      (data || []).forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.name;
        districtSelect.appendChild(opt);
      });

      districtSelect.value = current;
    }

    if (regionSelect && districtSelect) {
      regionSelect.addEventListener('change', () => loadDistricts(regionSelect.value));
    }
  })();
  </script>

</body>
</html>

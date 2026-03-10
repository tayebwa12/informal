<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

require_admin();

$msg = $err = null;

function norm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}

function upper_code(string $s): string {
  $s = strtoupper(norm($s));
  $s = preg_replace('/[^A-Z0-9\-_]/', '', $s);
  return $s ?? '';
}

/** Load series list */
$series = $pdo->query("SELECT id, name FROM exam_series ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/** series name -> id map */
$seriesMap = [];
foreach ($series as $s) {
  $seriesMap[mb_strtolower(norm((string)$s['name']))] = (int)$s['id'];
}

/** Filters */
$filter_series_id = (int)($_GET['series_id'] ?? 0);
$q = norm((string)($_GET['q'] ?? ''));

/** Pagination */
$perPage = 3; // ✅ show only three
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/** EXPORT (respects series filter + search) */
if (isset($_GET['export']) && in_array($_GET['export'], ['csv','xlsx'], true)) {

  $where = [];
  $params = [];

  if ($filter_series_id > 0) {
    $where[] = "o.exam_series_id = ?";
    $params[] = $filter_series_id;
  }
  if ($q !== '') {
    $where[] = "(o.code LIKE ? OR o.name LIKE ? OR s.name LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
  }

  $sql = "
    SELECT s.name AS series_name, o.code, o.name AS occupation
    FROM occupations o
    JOIN exam_series s ON s.id = o.exam_series_id
    " . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
    ORDER BY s.name, o.code, o.name
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  if ($_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="occupations_export_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Series','Occupation Code','Occupation']);
    foreach ($rows as $r) {
      fputcsv($out, [$r['series_name'], $r['code'], $r['occupation']]);
    }
    fclose($out);
    exit;
  }

  require_once __DIR__ . '/../../vendor/autoload.php';
  $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('Occupations');
  $sheet->fromArray(['Series','Occupation Code','Occupation'], null, 'A1');

  $i = 2;
  foreach ($rows as $r) {
    $sheet->fromArray([$r['series_name'], $r['code'], $r['occupation']], null, 'A'.$i);
    $i++;
  }
  foreach (range('A','C') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="occupations_export_' . date('Ymd_His') . '.xlsx"');
  header('Cache-Control: max-age=0');

  $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
  $writer->save('php://output');
  exit;
}

/** POST actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  /** MANUAL ADD */
  if (isset($_POST['add'])) {
    $series_id = (int)($_POST['exam_series_id'] ?? 0);
    $code = upper_code((string)($_POST['code'] ?? ''));
    $name = norm((string)($_POST['name'] ?? ''));

    if ($series_id <= 0) $err = "Select examination series.";
    elseif ($code === '') $err = "Occupation code required.";
    elseif ($name === '') $err = "Occupation name required.";
    else {
      try {
        $st = $pdo->prepare("INSERT INTO occupations (exam_series_id, code, name) VALUES (?, ?, ?)");
        $st->execute([$series_id, $code, $name]);
        $msg = "✅ Occupation added.";
      } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
    }
  }

  /** DELETE */
  if (isset($_POST['delete'])) {
    $id = (int)($_POST['id'] ?? 0);
    try {
      $st = $pdo->prepare("DELETE FROM occupations WHERE id=?");
      $st->execute([$id]);
      $msg = "✅ Occupation deleted.";
    } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
  }

  /** IMPORT */
  if (isset($_POST['import'])) {
    try {
      if (empty($_FILES['excel']['name']) || !is_uploaded_file($_FILES['excel']['tmp_name'])) {
        throw new RuntimeException("Please choose an Excel/CSV file to import.");
      }

      $tmp = $_FILES['excel']['tmp_name'];
      $ext = strtolower(pathinfo((string)$_FILES['excel']['name'], PATHINFO_EXTENSION));

      $existingCode = [];
      $existingName = [];
      $rows = $pdo->query("SELECT exam_series_id, code, name FROM occupations")->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as $r) {
        $sid = (int)$r['exam_series_id'];
        $existingCode[$sid.'|'.mb_strtolower(norm((string)$r['code']))] = true;
        $existingName[$sid.'|'.mb_strtolower(norm((string)$r['name']))] = true;
      }

      $items = [];

      if ($ext === 'csv') {
        $fh = fopen($tmp, 'r');
        if (!$fh) throw new RuntimeException("Failed to read CSV.");

        $header = fgetcsv($fh);
        if (!$header) throw new RuntimeException("CSV is empty.");

        $h = array_map(fn($x) => mb_strtolower(norm((string)$x)), $header);

        $iCode = array_search('code', $h, true);
        if ($iCode === false) $iCode = array_search('occupation code', $h, true);

        $iOcc = array_search('occupation', $h, true);
        if ($iOcc === false) $iOcc = array_search('name', $h, true);

        $iSer = array_search('series', $h, true);
        if ($iSer === false) $iSer = array_search('examination series', $h, true);
        if ($iSer === false) $iSer = array_search('exam series', $h, true);

        if ($iCode === false || $iOcc === false || $iSer === false) {
          throw new RuntimeException("CSV headers must include: Code, Occupation, Series");
        }

        while (($row = fgetcsv($fh)) !== false) {
          $items[] = [
            'code' => (string)($row[$iCode] ?? ''),
            'occupation' => (string)($row[$iOcc] ?? ''),
            'series' => (string)($row[$iSer] ?? ''),
          ];
        }
        fclose($fh);

      } elseif ($ext === 'xlsx' || $ext === 'xls') {
        require_once __DIR__ . '/../../vendor/autoload.php';

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        if (!$rows || count($rows) < 2) throw new RuntimeException("Excel seems empty.");

        $head = $rows[1] ?? [];
        $colCode = null; $colOcc = null; $colSer = null;

        foreach ($head as $col => $val) {
          $v = mb_strtolower(norm((string)$val));
          if (in_array($v, ['code','occupation code'], true)) $colCode = (string)$col;
          if (in_array($v, ['occupation','occupation name','name'], true)) $colOcc = (string)$col;
          if (in_array($v, ['series','examination series','exam series'], true)) $colSer = (string)$col;
        }

        $colCode = $colCode ?? 'A';
        $colOcc  = $colOcc  ?? 'B';
        $colSer  = $colSer  ?? 'C';

        $maxRow = count($rows);
        for ($i = 2; $i <= $maxRow; $i++) {
          $items[] = [
            'code' => (string)($rows[$i][$colCode] ?? ''),
            'occupation' => (string)($rows[$i][$colOcc] ?? ''),
            'series' => (string)($rows[$i][$colSer] ?? ''),
          ];
        }

      } else {
        throw new RuntimeException("Unsupported file type. Upload .xlsx or .csv");
      }

      if (!$items) {
        $msg = "✅ Import complete: nothing to import.";
      } else {
        $inserted = 0; $skipped = 0; $failed = 0;

        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO occupations (exam_series_id, code, name) VALUES (?, ?, ?)");

        foreach ($items as $it) {
          $code = upper_code((string)$it['code']);
          $occ  = norm((string)$it['occupation']);
          $serName = norm((string)$it['series']);

          if ($code === '' || $occ === '' || $serName === '') { $skipped++; continue; }

          $sid = $seriesMap[mb_strtolower($serName)] ?? 0;
          if ($sid <= 0) { $failed++; continue; }

          $kCode = $sid . '|' . mb_strtolower($code);
          $kName = $sid . '|' . mb_strtolower($occ);

          if (isset($existingCode[$kCode]) || isset($existingName[$kName])) { $skipped++; continue; }

          try {
            $ins->execute([(int)$sid, $code, $occ]);
            $existingCode[$kCode] = true;
            $existingName[$kName] = true;
            $inserted++;
          } catch (Throwable $e) {
            $failed++;
          }
        }

        $pdo->commit();
        $msg = "✅ Import done: Inserted {$inserted}, Skipped {$skipped}, Failed {$failed}.";
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = "❌ " . $e->getMessage();
    }
  }
}

/** Build WHERE for list + count */
$where = [];
$params = [];

if ($filter_series_id > 0) {
  $where[] = "o.exam_series_id = ?";
  $params[] = $filter_series_id;
}
if ($q !== '') {
  $where[] = "(o.code LIKE ? OR o.name LIKE ? OR s.name LIKE ?)";
  $params[] = "%$q%";
  $params[] = "%$q%";
  $params[] = "%$q%";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/** total rows */
$stCount = $pdo->prepare("
  SELECT COUNT(*)
  FROM occupations o
  JOIN exam_series s ON s.id = o.exam_series_id
  $whereSql
");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

/** list */
$stList = $pdo->prepare("
  SELECT o.id, o.code, o.name, s.name AS series_name
  FROM occupations o
  JOIN exam_series s ON s.id = o.exam_series_id
  $whereSql
  ORDER BY s.name, o.code, o.name
  LIMIT $perPage OFFSET $offset
");
$stList->execute($params);
$occupations = $stList->fetchAll(PDO::FETCH_ASSOC);

/** Helper for links */
function qs(array $extra = []): string {
  $base = $_GET;
  foreach ($extra as $k => $v) {
    if ($v === null || $v === '') unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Manage Occupations</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --blue:#2f57f7;
      --blue2:#2044d6;
      --bg:#f4f7ff;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#667085;
      --line:#e7eaf3;
      --shadow:0 18px 40px rgba(16,24,40,.10);
      --shadow2:0 10px 25px rgba(16,24,40,.08);
      --radius:18px;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#f6f7fb);color:var(--text)}
    .topbar{
      background:linear-gradient(90deg,var(--blue),#4b74ff);
      padding:22px 18px;
      color:#fff;
      position:sticky;top:0;z-index:10;
      box-shadow:0 10px 30px rgba(0,0,0,.12);
    }
    .topbar .wrap{max-width:1150px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:14px}
    .brand{display:flex;align-items:center;gap:12px}
    .logo{
      width:42px;height:42px;border-radius:14px;background:rgba(255,255,255,.16);
      display:grid;place-items:center;font-weight:900
    }
    .brand h1{margin:0;font-size:20px;letter-spacing:.2px}
    .brand p{margin:2px 0 0;font-size:13px;opacity:.9}
    .pill{
      background:rgba(255,255,255,.16);
      padding:10px 14px;border-radius:999px;
      font-weight:800;display:flex;align-items:center;gap:10px;
    }
    .container{max-width:1150px;margin:18px auto;padding:0 18px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media (max-width:980px){.grid{grid-template-columns:1fr}}
    .card{
      background:var(--card);
      border-radius:var(--radius);
      box-shadow:var(--shadow2);
      border:1px solid rgba(231,234,243,.9);
      padding:16px;
    }
    .card h3{margin:0 0 10px;font-size:16px}
    .row{display:flex;gap:10px;flex-wrap:wrap}
    label{font-weight:800;font-size:13px;color:#111827;display:block;margin-top:10px}
    input,select{
      width:100%;
      padding:12px 12px;
      border:1px solid var(--line);
      border-radius:14px;
      outline:none;
      background:#fff;
      margin-top:6px;
      transition:.15s;
    }
    input:focus,select:focus{border-color:rgba(47,87,247,.55);box-shadow:0 0 0 4px rgba(47,87,247,.12)}
    .btn{
      padding:12px 14px;border-radius:14px;border:0;
      background:var(--blue);
      color:#fff;font-weight:900;cursor:pointer;
      box-shadow:0 14px 25px rgba(47,87,247,.18);
      transition:.15s;
    }
    .btn:hover{transform:translateY(-1px);background:var(--blue2)}
    .btn-outline{
      background:#fff;color:var(--blue);
      border:2px solid rgba(47,87,247,.35);
      box-shadow:none;
    }
    .btn-danger{background:#b00020;box-shadow:0 14px 25px rgba(176,0,32,.14)}
    .btn-danger:hover{background:#8d0018}
    .msg{background:#ecfdf3;color:#027a48;border:1px solid #abefc6;padding:12px;border-radius:14px;font-weight:900}
    .err{background:#fff1f3;color:#b42318;border:1px solid #fda4af;padding:12px;border-radius:14px;font-weight:900}
    .toolbar{
      display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;
      margin-top:14px
    }
    .searchbar{display:flex;gap:10px;flex:1;min-width:260px}
    .searchbar input{margin-top:0}
    .chip{
      display:inline-flex;align-items:center;gap:8px;
      padding:10px 12px;border-radius:999px;
      border:1px solid rgba(255,255,255,.28);
      background:rgba(255,255,255,.16);
      color:#fff;font-weight:900;
    }

    /* Table / list */
    .list{
      margin-top:16px;
      display:grid;
      gap:12px;
    }
    .item{
      background:#fff;border:1px solid var(--line);
      border-radius:18px;
      box-shadow:var(--shadow2);
      padding:14px;
      display:flex;align-items:center;justify-content:space-between;gap:12px;
      flex-wrap:wrap;
    }
    .meta{display:flex;gap:14px;align-items:center;flex-wrap:wrap}
    .tag{
      padding:8px 10px;border-radius:999px;
      background:#f2f5ff;border:1px solid #e1e7ff;
      font-weight:900;font-size:12px;color:#1b2a6b;
    }
    .code{
      font-weight:1000;font-size:16px;letter-spacing:.6px;
      padding:10px 12px;border-radius:14px;
      background:linear-gradient(180deg,#f7faff,#eef3ff);
      border:1px solid #e4e9ff;
    }
    .name{font-weight:900;font-size:14px}
    .series{color:var(--muted);font-weight:800;font-size:13px}
    .actions{display:flex;gap:10px;align-items:center}
    .pager{
      margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:center;
    }
    .pagebtn{
      text-decoration:none;
      padding:10px 12px;border-radius:14px;
      border:1px solid var(--line);
      background:#fff;font-weight:900;color:#111827;
      min-width:44px;text-align:center;
    }
    .pagebtn.active{background:var(--blue);color:#fff;border-color:rgba(47,87,247,.25)}
    .pagebtn.disabled{opacity:.45;pointer-events:none}
    .hint{color:var(--muted);font-size:13px;margin:8px 0 0}
    .backlink{color:#fff;text-decoration:none;font-weight:900;opacity:.95}
    .backlink:hover{opacity:1;text-decoration:underline}
  </style>
</head>
<body>

<div class="topbar">
  <div class="wrap">
    <div class="brand">
      <div class="logo">✓</div>
      <div>
        <h1>Manage Occupations</h1>
        <p>Premium admin controls (Add • Import • Export • Search • Pagination)</p>
      </div>
    </div>
    <div class="pill">
      <a class="backlink" href="dashboard.php">← Back to Dashboard</a>
      <span class="chip">Admin</span>
    </div>
  </div>
</div>

<div class="container">

  <?php if ($msg) echo "<div class='msg'>".htmlspecialchars($msg)."</div>"; ?>
  <?php if ($err) echo "<div class='err' style='margin-top:10px'>".htmlspecialchars($err)."</div>"; ?>

  <div class="grid" style="margin-top:16px;">

    <!-- Manual Add -->
    <form class="card" method="post">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <h3>Add Occupation</h3>

      <label>Examination Series</label>
      <select name="exam_series_id" required>
        <option value="">-- Select Series --</option>
        <?php foreach ($series as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>">
            <?php echo htmlspecialchars((string)$s['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>Occupation Code</label>
      <input name="code" placeholder="e.g. PAT, INV, SUP01" required>

      <label>Occupation Name</label>
      <input name="name" placeholder="e.g. Practical Assessment Teacher" required>

      <div style="margin-top:12px;">
        <button class="btn" name="add" value="1" type="submit">Add</button>
      </div>
      <p class="hint">Tip: Code auto-normalizes to uppercase and removes special characters.</p>
    </form>

    <!-- Import / Export / Filter -->
    <div class="card">
      <h3>Import / Export</h3>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <label>Import (Excel/CSV)</label>
        <input type="file" name="excel" accept=".xlsx,.xls,.csv" required>
        <p class="hint">Columns required: <b>Code</b>, <b>Occupation</b>, <b>Series</b> (must match series name exactly).</p>
        <button class="btn" name="import" value="1" type="submit">Import</button>
      </form>

      <div class="toolbar">
        <div class="row">
          <a class="btn btn-outline" href="?<?php echo htmlspecialchars(qs(['export'=>'csv','page'=>1])); ?>">Export CSV</a>
          <a class="btn btn-outline" href="?<?php echo htmlspecialchars(qs(['export'=>'xlsx','page'=>1])); ?>">Export Excel</a>
        </div>

        <div style="min-width:260px;flex:1">
          <label style="margin-top:0">Filter by series</label>
          <select onchange="location.href='?'+new URLSearchParams({<?php
              // keep q, reset page
              echo 'q:'.json_encode($q).',series_id:this.value,page:1';
            ?>}).toString()">
            <option value="">-- All Series --</option>
            <?php foreach ($series as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php echo ($filter_series_id===(int)$s['id']?'selected':''); ?>>
                <?php echo htmlspecialchars((string)$s['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

  </div>

  <!-- Search + summary -->
  <div class="card" style="margin-top:16px;">
    <div class="toolbar">
      <div>
        <div style="font-weight:1000;font-size:16px">Occupations</div>
        <div class="hint">
          Showing <b><?php echo count($occupations); ?></b> of <b><?php echo $totalRows; ?></b> total • Page <b><?php echo $page; ?></b> of <b><?php echo $totalPages; ?></b> • Per page: <b><?php echo $perPage; ?></b>
        </div>
      </div>

      <form class="searchbar" method="get" style="margin:0;">
        <input type="hidden" name="series_id" value="<?php echo (int)$filter_series_id; ?>">
        <input name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search code, occupation, or series...">
        <button class="btn" type="submit">Search</button>
        <a class="btn btn-outline" href="?<?php echo htmlspecialchars(qs(['q'=>null,'page'=>1])); ?>">Clear</a>
      </form>
    </div>
  </div>

  <!-- List (premium cards) -->
  <div class="list">
    <?php if (!$occupations): ?>
      <div class="card" style="text-align:center">
        <div style="font-weight:1000;font-size:16px">No results found</div>
        <div class="hint">Try changing search text or series filter.</div>
      </div>
    <?php endif; ?>

    <?php foreach ($occupations as $o): ?>
      <div class="item">
        <div class="meta">
          <div class="code"><?php echo htmlspecialchars((string)$o['code']); ?></div>
          <div>
            <div class="name"><?php echo htmlspecialchars((string)$o['name']); ?></div>
            <div class="series"><?php echo htmlspecialchars((string)$o['series_name']); ?></div>
          </div>
          <span class="tag">Occupation</span>
        </div>

        <div class="actions">
          <form method="post" style="margin:0" onsubmit="return confirm('Delete this occupation?');">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
            <button class="btn btn-danger" name="delete" value="1" type="submit">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php
    $prev = max(1, $page - 1);
    $next = min($totalPages, $page + 1);

    // show small window around current page
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
  ?>
  <div class="pager">
    <a class="pagebtn <?php echo ($page<=1?'disabled':''); ?>" href="?<?php echo htmlspecialchars(qs(['page'=>$prev])); ?>">‹ Prev</a>

    <?php if ($start > 1): ?>
      <a class="pagebtn" href="?<?php echo htmlspecialchars(qs(['page'=>1])); ?>">1</a>
      <?php if ($start > 2): ?>
        <span class="pagebtn disabled">…</span>
      <?php endif; ?>
    <?php endif; ?>

    <?php for ($p=$start; $p<=$end; $p++): ?>
      <a class="pagebtn <?php echo ($p===$page?'active':''); ?>" href="?<?php echo htmlspecialchars(qs(['page'=>$p])); ?>"><?php echo $p; ?></a>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
      <?php if ($end < $totalPages - 1): ?>
        <span class="pagebtn disabled">…</span>
      <?php endif; ?>
      <a class="pagebtn" href="?<?php echo htmlspecialchars(qs(['page'=>$totalPages])); ?>"><?php echo $totalPages; ?></a>
    <?php endif; ?>

    <a class="pagebtn <?php echo ($page>=$totalPages?'disabled':''); ?>" href="?<?php echo htmlspecialchars(qs(['page'=>$next])); ?>">Next ›</a>
  </div>

</div>

</body>
</html>

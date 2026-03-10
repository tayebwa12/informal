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

function as_decimal($v): float {
  $v = str_replace(',', '', (string)$v);
  return (float)$v;
}

/** Load series list */
$series = $pdo->query("SELECT id, name FROM exam_series ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/** Map series name -> id */
$seriesMap = [];
foreach ($series as $s) {
  $seriesMap[mb_strtolower(norm((string)$s['name']))] = (int)$s['id'];
}

/** EXPORT */
if (isset($_GET['export']) && in_array($_GET['export'], ['csv','xlsx'], true)) {

  $rows = $pdo->query("
    SELECT
      s.name AS series_name,
      COALESCE(sr.ratio_value, '') AS ratio_value,
      COALESCE(sr.status, '') AS status,
      COALESCE(sr.notes, '') AS notes,
      COALESCE(sr.updated_at, '') AS updated_at
    FROM exam_series s
    LEFT JOIN series_ratios sr ON sr.exam_series_id = s.id
    ORDER BY s.name
  ")->fetchAll(PDO::FETCH_ASSOC);

  if ($_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="series_ratios_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Series','Ratio Value','Status','Notes','Updated At']);
    foreach ($rows as $r) {
      fputcsv($out, [$r['series_name'], $r['ratio_value'], $r['status'], $r['notes'], $r['updated_at']]);
    }
    fclose($out);
    exit;
  }

  // XLSX
  require_once __DIR__ . '/../../vendor/autoload.php';

  $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('Series Ratios');
  $sheet->fromArray(['Series','Ratio Value','Status','Notes','Updated At'], null, 'A1');

  $i = 2;
  foreach ($rows as $r) {
    $sheet->fromArray([$r['series_name'], $r['ratio_value'], $r['status'], $r['notes'], $r['updated_at']], null, 'A'.$i);
    $i++;
  }

  foreach (range('A','E') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="series_ratios_' . date('Ymd_His') . '.xlsx"');
  header('Cache-Control: max-age=0');

  $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
  $writer->save('php://output');
  exit;
}

/** POST actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  /** Save (upsert) */
  if (isset($_POST['save'])) {
    $exam_series_id = (int)($_POST['exam_series_id'] ?? 0);
    $ratio_value = as_decimal($_POST['ratio_value'] ?? '');
    $status = strtolower(norm((string)($_POST['status'] ?? 'active')));
    $notes = norm((string)($_POST['notes'] ?? ''));

    if ($exam_series_id <= 0) $err = "Select examination series.";
    elseif ($ratio_value <= 0) $err = "Enter a valid ratio value (> 0).";
    elseif (!in_array($status, ['active','inactive'], true)) $err = "Invalid status.";
    else {
      try {
        $st = $pdo->prepare("
          INSERT INTO series_ratios (exam_series_id, ratio_value, status, notes, updated_by)
          VALUES (?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE
            ratio_value=VALUES(ratio_value),
            status=VALUES(status),
            notes=VALUES(notes),
            updated_by=VALUES(updated_by)
        ");
        $st->execute([$exam_series_id, $ratio_value, $status, ($notes !== '' ? $notes : null), (int)($_SESSION['user']['id'] ?? 0)]);
        $msg = "✅ Ratio saved for the selected series.";
      } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
    }
  }

  /** Delete ratio for a series */
  if (isset($_POST['delete'])) {
    $exam_series_id = (int)($_POST['exam_series_id'] ?? 0);
    try {
      $st = $pdo->prepare("DELETE FROM series_ratios WHERE exam_series_id=?");
      $st->execute([$exam_series_id]);
      $msg = "✅ Ratio removed for that series.";
    } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
  }

  /** Import (Series + Ratio Value + Status(optional) + Notes(optional)) */
  if (isset($_POST['import'])) {
    try {
      if (empty($_FILES['excel']['name']) || !is_uploaded_file($_FILES['excel']['tmp_name'])) {
        throw new RuntimeException("Please choose an Excel/CSV file to import.");
      }

      $tmp = $_FILES['excel']['tmp_name'];
      $ext = strtolower(pathinfo((string)$_FILES['excel']['name'], PATHINFO_EXTENSION));

      $items = [];

      if ($ext === 'csv') {
        $fh = fopen($tmp, 'r');
        if (!$fh) throw new RuntimeException("Failed to read CSV.");

        $header = fgetcsv($fh);
        if (!$header) throw new RuntimeException("CSV is empty.");

        $h = array_map(fn($x) => mb_strtolower(norm((string)$x)), $header);

        $iSeries = array_search('series', $h, true);
        if ($iSeries === false) $iSeries = array_search('examination series', $h, true);

        $iValue = array_search('ratio value', $h, true);
        if ($iValue === false) $iValue = array_search('ratio', $h, true);

        $iStatus = array_search('status', $h, true); // optional
        $iNotes  = array_search('notes', $h, true);  // optional

        if ($iSeries === false || $iValue === false) {
          throw new RuntimeException("CSV headers must include: Series, Ratio Value. (Status/Notes optional)");
        }

        while (($row = fgetcsv($fh)) !== false) {
          $items[] = [
            'series' => (string)($row[$iSeries] ?? ''),
            'value'  => (string)($row[$iValue] ?? ''),
            'status' => $iStatus !== false ? (string)($row[$iStatus] ?? '') : '',
            'notes'  => $iNotes  !== false ? (string)($row[$iNotes]  ?? '') : '',
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

        // Excel default columns: A=Series, B=Ratio Value, C=Status(optional), D=Notes(optional)
        $max = count($rows);
        for ($i=2; $i<=$max; $i++) {
          $items[] = [
            'series' => (string)($rows[$i]['A'] ?? ''),
            'value'  => (string)($rows[$i]['B'] ?? ''),
            'status' => (string)($rows[$i]['C'] ?? ''),
            'notes'  => (string)($rows[$i]['D'] ?? ''),
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
        $up = $pdo->prepare("
          INSERT INTO series_ratios (exam_series_id, ratio_value, status, notes, updated_by)
          VALUES (?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE
            ratio_value=VALUES(ratio_value),
            status=VALUES(status),
            notes=VALUES(notes),
            updated_by=VALUES(updated_by)
        ");

        foreach ($items as $it) {
          $sName = norm((string)$it['series']);
          $val = as_decimal($it['value'] ?? '');
          $status = strtolower(norm((string)($it['status'] ?? '')));
          $notes = norm((string)($it['notes'] ?? ''));

          if ($sName === '' || $val <= 0) { $skipped++; continue; }

          $sid = $seriesMap[mb_strtolower($sName)] ?? 0;
          if ($sid <= 0) { $failed++; continue; }

          if ($status === '') $status = 'active';
          if (!in_array($status, ['active','inactive'], true)) $status = 'active';

          try {
            $up->execute([$sid, $val, $status, ($notes !== '' ? $notes : null), (int)($_SESSION['user']['id'] ?? 0)]);
            $inserted++;
          } catch (Throwable $e) { $failed++; }
        }

        $pdo->commit();
        $msg = "✅ Import done: Saved {$inserted}, Skipped {$skipped}, Failed {$failed}. (Failed = series name not found)";
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = "❌ " . $e->getMessage();
    }
  }
}

/** Load series + current ratio if any */
$rows = $pdo->query("
  SELECT
    s.id AS series_id,
    s.name AS series_name,
    sr.ratio_value,
    sr.status,
    sr.notes,
    sr.updated_at
  FROM exam_series s
  LEFT JOIN series_ratios sr ON sr.exam_series_id = s.id
  ORDER BY s.name
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Manage Ratios</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui;background:#f6f7fb;padding:18px;margin:0}
    .row{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start}
    .card{background:#fff;border-radius:12px;padding:14px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
    label{font-weight:800;display:block;margin-top:10px}
    input,select{width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;margin-top:6px}
    button{padding:10px 12px;border-radius:10px;border:0;background:#1b5cff;color:#fff;font-weight:800;cursor:pointer}
    .btn2{background:#ffffff;color:#1b5cff;border:2px solid #1b5cff}
    .danger{background:#b00020}
    .msg{color:green;font-weight:900}
    .err{color:#b00020;font-weight:900}
    table{width:100%;border-collapse:collapse;background:#fff;margin-top:12px}
    th,td{border:1px solid #e6e6e6;padding:10px;text-align:left;font-size:14px;vertical-align:top}
    th{background:#f2f5ff}
    a.btnlink{display:inline-block;text-decoration:none;padding:10px 12px;border-radius:10px;margin-right:8px;margin-top:8px}
    .mini{font-size:12px;color:#666}
  </style>
</head>
<body>

<h2>Manage Ratios</h2>
<p><a href="dashboard.php">← Back</a></p>

<?php if ($msg) echo "<p class='msg'>".htmlspecialchars($msg)."</p>"; ?>
<?php if ($err) echo "<p class='err'>".htmlspecialchars($err)."</p>"; ?>

<div class="row">

  <!-- Add/Update -->
  <form class="card" method="post" style="max-width:520px;min-width:320px;">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <h3 style="margin:0 0 10px;">Set Ratio (Per Series)</h3>

    <label>Examination Series</label>
    <select name="exam_series_id" required>
      <option value="">-- Select --</option>
      <?php foreach ($series as $s): ?>
        <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars((string)$s['name']); ?></option>
      <?php endforeach; ?>
    </select>

    <label>Ratio Value</label>
    <input name="ratio_value" type="number" step="0.01" min="0.01" placeholder="e.g. 25" required>

    <label>Status</label>
    <select name="status">
      <option value="active" selected>active</option>
      <option value="inactive">inactive</option>
    </select>

    <label>Notes (optional)</label>
    <input name="notes" placeholder="e.g. 1 officer per 25 candidates">

    <button name="save" value="1" type="submit" style="margin-top:12px;">Save Ratio</button>
  </form>

  <!-- Import / Export -->
  <div class="card" style="max-width:520px;min-width:320px;">
    <h3 style="margin:0 0 10px;">Import / Export</h3>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">

      <label>Import (Excel/CSV)</label>
      <input type="file" name="excel" accept=".xlsx,.xls,.csv" required>

      <p class="mini" style="margin-top:8px;">
        Required columns: <b>Series</b>, <b>Ratio Value</b> (Status/Notes optional).<br>
        Excel format: A=Series, B=Ratio Value, C=Status(optional), D=Notes(optional).
      </p>

      <button name="import" value="1" type="submit" style="margin-top:10px;">Import</button>
    </form>

    <div style="margin-top:10px;">
      <a class="btn2 btnlink" href="?export=csv">Export CSV</a>
      <a class="btn2 btnlink" href="?export=xlsx">Export Excel</a>
    </div>
  </div>

</div>

<h3 style="margin-top:18px;">Series Ratios</h3>

<table>
  <tr>
    <th>Series</th>
    <th>Ratio</th>
    <th>Status</th>
    <th>Notes</th>
    <th>Updated</th>
    <th>Action</th>
  </tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?php echo htmlspecialchars((string)$r['series_name']); ?></td>
      <td><b><?php echo htmlspecialchars((string)($r['ratio_value'] ?? '')); ?></b></td>
      <td><?php echo htmlspecialchars((string)($r['status'] ?? '')); ?></td>
      <td><?php echo htmlspecialchars((string)($r['notes'] ?? '')); ?></td>
      <td><?php echo htmlspecialchars((string)($r['updated_at'] ?? '')); ?></td>
      <td>
        <form method="post" style="display:inline" onsubmit="return confirm('Remove ratio for this series?');">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="exam_series_id" value="<?php echo (int)$r['series_id']; ?>">
          <button class="danger" name="delete" value="1" type="submit">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
</table>

</body>
</html>

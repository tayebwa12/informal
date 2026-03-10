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

/** EXPORT CSV */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="exam_series_' . date('Ymd_His') . '.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['Name','Status','Created At']);

  $rows = $pdo->query("SELECT name, status, created_at FROM exam_series ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    fputcsv($out, [$r['name'], $r['status'], $r['created_at']]);
  }
  fclose($out);
  exit;
}

/** POST actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  /** Add series */
  if (isset($_POST['add'])) {
    $name = norm((string)($_POST['name'] ?? ''));
    $status = strtolower(norm((string)($_POST['status'] ?? 'inactive')));
    if (!in_array($status, ['active','inactive'], true)) $status = 'inactive';

    if ($name === '') $err = "Series name required.";
    else {
      try {
        $st = $pdo->prepare("INSERT INTO exam_series (name, status) VALUES (?, ?)");
        $st->execute([$name, $status]);
        $msg = "✅ Series added.";
      } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
    }
  }

  /** Activate (optional: make only ONE active at a time) */
  if (isset($_POST['activate'])) {
    $id = (int)($_POST['id'] ?? 0);
    try {
      $pdo->beginTransaction();
      // Make all inactive first (so only one is active)
      $pdo->exec("UPDATE exam_series SET status='inactive'");
      $st = $pdo->prepare("UPDATE exam_series SET status='active' WHERE id=?");
      $st->execute([$id]);
      $pdo->commit();
      $msg = "✅ Series activated (all others set to inactive).";
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = "❌ " . $e->getMessage();
    }
  }

  /** Deactivate */
  if (isset($_POST['deactivate'])) {
    $id = (int)($_POST['id'] ?? 0);
    try {
      $st = $pdo->prepare("UPDATE exam_series SET status='inactive' WHERE id=?");
      $st->execute([$id]);
      $msg = "✅ Series deactivated.";
    } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
  }

  /** Delete */
  if (isset($_POST['delete'])) {
    $id = (int)($_POST['id'] ?? 0);
    try {
      $st = $pdo->prepare("DELETE FROM exam_series WHERE id=?");
      $st->execute([$id]);
      $msg = "✅ Series deleted.";
    } catch (Throwable $e) { $err = "❌ " . $e->getMessage(); }
  }

  /** Import CSV */
  if (isset($_POST['import'])) {
    try {
      if (empty($_FILES['csv']['name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        throw new RuntimeException("Choose a CSV file to import.");
      }
      $fh = fopen($_FILES['csv']['tmp_name'], 'r');
      if (!$fh) throw new RuntimeException("Failed to read CSV.");

      $header = fgetcsv($fh);
      if (!$header) throw new RuntimeException("CSV is empty.");

      $h = array_map(fn($x) => strtolower(norm((string)$x)), $header);
      $iName = array_search('name', $h, true);
      if ($iName === false) $iName = array_search('series', $h, true);

      $iStatus = array_search('status', $h, true); // optional

      if ($iName === false) {
        throw new RuntimeException("CSV must include header: Name (or Series). Status is optional.");
      }

      $pdo->beginTransaction();
      $ins = $pdo->prepare("
        INSERT INTO exam_series (name, status)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE status=VALUES(status)
      ");

      $saved = 0; $skipped = 0;

      while (($row = fgetcsv($fh)) !== false) {
        $name = norm((string)($row[$iName] ?? ''));
        if ($name === '') { $skipped++; continue; }

        $status = 'inactive';
        if ($iStatus !== false) {
          $status = strtolower(norm((string)($row[$iStatus] ?? 'inactive')));
          if (!in_array($status, ['active','inactive'], true)) $status = 'inactive';
        }

        $ins->execute([$name, $status]);
        $saved++;
      }

      fclose($fh);
      $pdo->commit();
      $msg = "✅ Import complete: Saved {$saved}, Skipped {$skipped}.";
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = "❌ " . $e->getMessage();
    }
  }
}

/** List series */
$rows = $pdo->query("SELECT id, name, status, created_at FROM exam_series ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Manage Series</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui;background:#f6f7fb;padding:18px;margin:0}
    .row{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start}
    .card{background:#fff;border-radius:12px;padding:14px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
    label{font-weight:800;display:block;margin-top:10px}
    input,select{width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;margin-top:6px}
    button{padding:10px 12px;border-radius:10px;border:0;background:#1b5cff;color:#fff;font-weight:800;cursor:pointer}
    .btn2{background:#fff;color:#1b5cff;border:2px solid #1b5cff}
    .danger{background:#b00020}
    .msg{color:green;font-weight:900}
    .err{color:#b00020;font-weight:900}
    table{width:100%;border-collapse:collapse;background:#fff;margin-top:12px}
    th,td{border:1px solid #e6e6e6;padding:10px;text-align:left;font-size:14px;vertical-align:top}
    th{background:#f2f5ff}
    a.btnlink{display:inline-block;text-decoration:none;padding:10px 12px;border-radius:10px;margin-right:8px;margin-top:8px}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:900;font-size:12px}
    .active{background:#e7fff0;color:#146b2a}
    .inactive{background:#fff3e7;color:#8a4b12}
  </style>
</head>
<body>

<h2>Manage Examination Series</h2>
<p><a href="dashboard.php">← Back</a></p>

<?php if ($msg) echo "<p class='msg'>".htmlspecialchars($msg)."</p>"; ?>
<?php if ($err) echo "<p class='err'>".htmlspecialchars($err)."</p>"; ?>

<div class="row">

  <form class="card" method="post" style="max-width:520px;min-width:320px;">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <h3 style="margin:0 0 10px;">Add Series</h3>

    <label>Series Name</label>
    <input name="name" placeholder="e.g. March 2026 Assessment Series" required>

    <label>Status</label>
    <select name="status">
      <option value="inactive" selected>inactive</option>
      <option value="active">active</option>
    </select>

    <button name="add" value="1" type="submit" style="margin-top:12px;">Add</button>
  </form>

  <div class="card" style="max-width:520px;min-width:320px;">
    <h3 style="margin:0 0 10px;">Import / Export</h3>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <label>Import CSV</label>
      <input type="file" name="csv" accept=".csv" required>
      <p style="font-size:12px;color:#666;margin:8px 0;">
        CSV headers: <b>Name</b> (or <b>Series</b>), <b>Status</b> optional
      </p>
      <button name="import" value="1" type="submit">Import</button>
    </form>

    <div style="margin-top:12px;">
      <a class="btn2 btnlink" href="?export=csv">Export CSV</a>
    </div>
  </div>

</div>

<h3 style="margin-top:18px;">Existing Series (<?php echo count($rows); ?>)</h3>

<table>
  <tr>
    <th>Name</th>
    <th>Status</th>
    <th>Created</th>
    <th>Actions</th>
  </tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?php echo htmlspecialchars((string)$r['name']); ?></td>
      <td>
        <?php if ($r['status'] === 'active'): ?>
          <span class="pill active">ACTIVE</span>
        <?php else: ?>
          <span class="pill inactive">INACTIVE</span>
        <?php endif; ?>
      </td>
      <td><?php echo htmlspecialchars((string)$r['created_at']); ?></td>
      <td>
        <?php if ($r['status'] !== 'active'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Activate this series? (others will be set inactive)');">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <button name="activate" value="1" type="submit">Activate</button>
          </form>
        <?php else: ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this series?');">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <button class="btn2" name="deactivate" value="1" type="submit">Deactivate</button>
          </form>
        <?php endif; ?>

        <form method="post" style="display:inline" onsubmit="return confirm('Delete this series? (Only if not used anywhere)');">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
          <button class="danger" name="delete" value="1" type="submit">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
</table>

</body>
</html>

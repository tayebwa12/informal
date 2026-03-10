<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

require_admin();
$msg = $err = null;

function normalize_region(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($_POST['csrf'] ?? null);

    // ✅ Add manually
    if (isset($_POST['add'])) {
        $name = normalize_region((string)($_POST['name'] ?? ''));
        if ($name === '') $err = "Region name required.";
        else {
            try {
                $st = $pdo->prepare("INSERT INTO regions (name) VALUES (?)");
                $st->execute([$name]);
                $msg = "✅ Region '$name' added successfully.";
            } catch (Throwable $e) { $err = "❌ Error: " . $e->getMessage(); }
        }
    }

    // ✅ Update / Edit
    if (isset($_POST['update'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = normalize_region((string)($_POST['name'] ?? ''));
        if ($name === '') $err = "Region name cannot be empty.";
        else {
            try {
                $st = $pdo->prepare("UPDATE regions SET name = ? WHERE id = ?");
                $st->execute([$name, $id]);
                $msg = "✅ Region updated to '$name'.";
            } catch (Throwable $e) { $err = "❌ Update failed: " . $e->getMessage(); }
        }
    }

    // ✅ Delete
    if (isset($_POST['delete'])) {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $st = $pdo->prepare("DELETE FROM regions WHERE id=?");
            $st->execute([$id]);
            $msg = "✅ Region deleted.";
        } catch (Throwable $e) { $err = "❌ Cannot delete: " . $e->getMessage(); }
    }

    // ✅ Import (Logic kept exactly as your original)
    if (isset($_POST['import'])) {
        try {
            if (empty($_FILES['excel']['name']) || !is_uploaded_file($_FILES['excel']['tmp_name'])) {
                throw new RuntimeException("Please choose an Excel/CSV file.");
            }
            $tmp = $_FILES['excel']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['excel']['name'], PATHINFO_EXTENSION));

            $existing = [];
            foreach ($pdo->query("SELECT name FROM regions")->fetchAll(PDO::FETCH_COLUMN) as $n) {
                $existing[mb_strtolower(normalize_region((string)$n))] = true;
            }

            $toInsert = [];
            if ($ext === 'csv') {
                $fh = fopen($tmp, 'r');
                while (($row = fgetcsv($fh)) !== false) {
                    $val = normalize_region((string)($row[0] ?? ''));
                    if ($val === '') continue;
                    $key = mb_strtolower($val);
                    if (!isset($existing[$key]) && !isset($toInsert[$key])) $toInsert[$key] = $val;
                }
                fclose($fh);
            } elseif (in_array($ext, ['xlsx', 'xls'])) {
                require_once __DIR__ . '/../../vendor/autoload.php';
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
                $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
                foreach ($rows as $row) {
                    $val = normalize_region((string)$row['A']);
                    if ($val === '' || preg_match('/^(region|name)$/i', $val)) continue;
                    $key = mb_strtolower($val);
                    if (!isset($existing[$key]) && !isset($toInsert[$key])) $toInsert[$key] = $val;
                }
            }

            if ($toInsert) {
                $pdo->beginTransaction();
                $ins = $pdo->prepare("INSERT INTO regions (name) VALUES (?)");
                foreach ($toInsert as $v) $ins->execute([$v]);
                $pdo->commit();
                $msg = "✅ Imported " . count($toInsert) . " new regions.";
            } else { $msg = "ℹ️ No new regions to import."; }
        } catch (Throwable $e) { $err = "❌ Import error: " . $e->getMessage(); }
    }
}

$regions = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Manage Regions | UVTAB</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #4f46e5; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; --radius: 10px; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        
        /* Header Area */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .btn-back { text-decoration: none; color: var(--text); font-size: 0.9rem; font-weight: 600; }

        /* Status Messages */
        .alert { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 20px; font-weight: 500; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Forms Layout */
        .top-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .card { background: #fff; padding: 20px; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card h3 { margin: 0 0 15px 0; font-size: 1.1rem; }
        
        input[type="text"], input[type="file"] { 
            width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; box-sizing: border-box; margin-bottom: 10px;
        }
        .btn { 
            padding: 10px 16px; border-radius: 6px; border: none; font-weight: 600; cursor: pointer; transition: 0.2s;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-danger { background: #fee2e2; color: #991b1b; }

        /* Table Area */
        .table-card { background: #fff; border-radius: var(--radius); border: 1px solid var(--border); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; padding: 12px 15px; text-align: left; font-size: 0.85rem; color: #64748b; text-transform: uppercase; }
        td { padding: 12px 15px; border-top: 1px solid var(--border); vertical-align: middle; }
        
        /* Edit Mode Styles */
        .edit-input { display: none; width: 70% !important; margin: 0 !important; }
        .view-mode.active { display: none; }
        .edit-mode.active { display: flex; gap: 5px; align-items: center; }
    </style>
</head>
<body>

<div class="container">
    <header class="page-header">
        <div>
            <h2 style="margin:0">Region Management</h2>
            <a href="dashboard.php" class="btn-back">← Back to Dashboard</a>
        </div>
    </header>

    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="top-grid">
        <div class="card">
            <h3>Add New Region</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="text" name="name" placeholder="e.g. Central Region" required>
                <button name="add" value="1" class="btn btn-primary">Add Region</button>
            </form>
        </div>

        <div class="card">
            <h3>Bulk Import</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="file" name="excel" accept=".xlsx,.xls,.csv" required>
                <button name="import" value="1" class="btn btn-outline">Upload File</button>
            </form>
        </div>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Region Name</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($regions as $r): ?>
                <tr>
                    <td>
                        <div id="view-<?= $r['id'] ?>" class="view-mode">
                            <strong><?= htmlspecialchars((string)$r['name']) ?></strong>
                        </div>
                        
                        <form id="edit-form-<?= $r['id'] ?>" method="post" class="edit-mode">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <input type="text" name="name" value="<?= htmlspecialchars((string)$r['name']) ?>" class="edit-input" id="input-<?= $r['id'] ?>">
                            <button name="update" value="1" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">Save</button>
                            <button type="button" onclick="toggleEdit(<?= $r['id'] ?>)" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px;">Cancel</button>
                        </form>
                    </td>
                    <td style="text-align:right">
                        <button class="btn btn-outline" style="padding: 5px 10px;" onclick="toggleEdit(<?= $r['id'] ?>)">Edit</button>
                        
                        <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button name="delete" value="1" class="btn btn-danger" style="padding: 5px 10px;">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleEdit(id) {
    const viewDiv = document.getElementById('view-' + id);
    const editForm = document.getElementById('edit-form-' + id);
    const input = document.getElementById('input-' + id);

    if (editForm.classList.contains('active')) {
        editForm.classList.remove('active');
        viewDiv.classList.remove('active');
        input.style.display = 'none';
    } else {
        editForm.classList.add('active');
        viewDiv.classList.add('active');
        input.style.display = 'block';
        input.focus();
    }
}
</script>

</body>
</html>
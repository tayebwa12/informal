<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_examiner.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_examiner();
$userId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tableHasColumn(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

$msg = $err = null;

// Fetch current file
$st = $pdo->prepare("SELECT qualification_file FROM users WHERE id=? LIMIT 1");
$st->execute([$userId]);
$currentFile = (string)($st->fetchColumn() ?? '');

$MAX_BYTES = 5 * 1024 * 1024; // 5MB

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  if (!isset($_FILES['qualification']) || !is_uploaded_file($_FILES['qualification']['tmp_name'])) {
    $err = "Please select a valid PDF file.";
  } elseif ((int)($_FILES['qualification']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    $err = "Upload error. Please try again.";
  } elseif ((int)($_FILES['qualification']['size'] ?? 0) > $MAX_BYTES) {
    $err = "File too large. Max allowed is 5MB.";
  } else {
    $tmp  = (string)$_FILES['qualification']['tmp_name'];
    $name = (string)$_FILES['qualification']['name'];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($ext !== 'pdf') {
      $err = "Only PDF files are allowed.";
    } else {
      try {
        $pdo->beginTransaction();

        // folder
        $dirAbs = __DIR__ . '/../uploads/qualifications/';
        if (!is_dir($dirAbs)) mkdir($dirAbs, 0775, true);

        $newName = 'qual_' . $userId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $destAbs = $dirAbs . $newName;

        if (!move_uploaded_file($tmp, $destAbs)) {
          throw new RuntimeException("Upload failed. Try again.");
        }

        $dbPath = '../uploads/qualifications/' . $newName;

        // update user record
        $st = $pdo->prepare("UPDATE users SET qualification_file=?, qualification_uploaded_at=NOW() WHERE id=? LIMIT 1");
        $st->execute([$dbPath, $userId]);

        // ✅ AUTO-RESUBMIT if application was rejected
        $app = $pdo->prepare("SELECT id, status FROM examiner_applications WHERE user_id=? ORDER BY id DESC LIMIT 1");
        $app->execute([$userId]);
        $a = $app->fetch(PDO::FETCH_ASSOC);

        if ($a && strtolower((string)$a['status']) === 'rejected') {
          $hasResubmittedAt = tableHasColumn($pdo, 'examiner_applications', 'resubmitted_at');

          $sql = "
            UPDATE examiner_applications
            SET status='pending',
                rejection_reason=NULL,
                reviewed_by=NULL,
                reviewed_at=NULL
                " . ($hasResubmittedAt ? ", resubmitted_at=NOW()" : "") . "
            WHERE id=?
            LIMIT 1
          ";
          $up = $pdo->prepare($sql);
          $up->execute([(int)$a['id']]);
        }

        $pdo->commit();

        $currentFile = $dbPath;
        $msg = "✅ Qualification uploaded successfully.";

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = "Upload failed. Please try again.";
      }
    }
  }
}

// Fix link to open current PDF in browser
$publicPrefix = "/public/";
$currentUrl = $currentFile ? $publicPrefix . ltrim($currentFile, '/') : '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Update Qualification</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui;background:#f6f7fb;margin:0;padding:18px}
    .card{background:#fff;border-radius:14px;padding:14px;box-shadow:0 8px 24px rgba(0,0,0,.06);max-width:720px}
    .msg{color:green;font-weight:800}
    .err{color:#b00020;font-weight:800}
    label{font-weight:800;display:block;margin-top:10px}
    input{width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;margin-top:6px}
    button,a.btn{display:inline-block;padding:10px 12px;border-radius:10px;background:#1b5cff;color:#fff;text-decoration:none;font-weight:800;border:0;cursor:pointer}
    a.btn2{background:#fff;color:#1b5cff;border:2px solid #1b5cff}
    .muted{color:#666;font-size:13px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  </style>
</head>
<body>

<p><a class="btn btn2" href="dashboard.php">← Back</a></p>

<div class="card">
  <h2>Update Qualification Document</h2>

  <?php if ($msg) echo "<p class='msg'>".h($msg)."</p>"; ?>
  <?php if ($err) echo "<p class='err'>".h($err)."</p>"; ?>

  <p class="muted">Upload your academic qualification as a PDF (Max 5MB). Required for verification.</p>

  <p class="row">
    <b>Current File:</b>
    <?php if ($currentUrl): ?>
      <a class="btn btn2" href="<?php echo h($currentUrl); ?>" target="_blank">View PDF</a>
    <?php else: ?>
      <span class="muted">Not uploaded yet</span>
    <?php endif; ?>
  </p>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <label>Qualification PDF</label>
    <input type="file" name="qualification" accept="application/pdf,.pdf" required>
    <button type="submit" style="margin-top:12px;">Upload / Update</button>
  </form>

  <p class="muted" style="margin-top:12px;">
    If your application was rejected, uploading here automatically re-submits it to <b>Pending</b>.
  </p>
</div>

</body>
</html>

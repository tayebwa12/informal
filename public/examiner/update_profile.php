<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_examiner.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_examiner();
$userId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function norm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}

function cleanPhone(string $s): string {
  $s = norm($s);
  $s = preg_replace('/[^0-9+]/', '', $s);
  return $s ?: '';
}

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

/**
 * ✅ Check if examiner has completed application details:
 * occupation_id, district_id, center_id (or organisation_name if WoW).
 */
function needsExtraDetails(PDO $pdo, int $userId): bool {
  try {
    $st = $pdo->prepare("
      SELECT occupation_id, district_id, center_id, organisation_name
      FROM examiner_applications
      WHERE user_id = ?
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute([$userId]);
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $occ  = (int)($a['occupation_id'] ?? 0);
    $dist = (int)($a['district_id'] ?? 0);
    $cid  = (int)($a['center_id'] ?? 0);
    $org  = trim((string)($a['organisation_name'] ?? ''));

    if ($occ <= 0 || $dist <= 0) return true;
    if ($cid <= 0 && $org === '') return true;

    return false;
  } catch (Throwable $e) {
    return false;
  }
}

$msg = $err = null;

// ✅ absolute URL (confirmed working in your browser)
$completeProfileUrl = '/public/apply_examiner.php?complete_profile=1';

// Fetch current profile (fresh from DB)
$st = $pdo->prepare("SELECT full_name, phone, email FROM users WHERE id=? LIMIT 1");
$st->execute([$userId]);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$fullName = (string)($row['full_name'] ?? ($me['full_name'] ?? ''));
$phone    = (string)($row['phone'] ?? ($me['phone'] ?? ''));
$email    = (string)($row['email'] ?? ($me['email'] ?? ''));

// ✅ OPTIONAL auto-redirect on page load (enable if you want)
// if (needsExtraDetails($pdo, $userId)) {
//   header("Location: " . $completeProfileUrl);
//   exit;
// }

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  $fullName = norm((string)($_POST['full_name'] ?? ''));
  $phone    = cleanPhone((string)($_POST['phone'] ?? ''));

  if ($fullName === '') {
    $err = "Full name is required.";
  } elseif ($phone !== '' && !preg_match('/^[0-9+]{7,20}$/', $phone)) {
    $err = "Phone number format looks invalid.";
  } else {
    try {
      $pdo->beginTransaction();

      // Update user profile
      $st = $pdo->prepare("UPDATE users SET full_name=?, phone=? WHERE id=? LIMIT 1");
      $st->execute([$fullName, ($phone !== '' ? $phone : null), $userId]);

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

      // Update session cache if used
      if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
      if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $_SESSION['user']['full_name'] = $fullName;
        $_SESSION['user']['phone'] = $phone;
      }

      // ✅ After saving: if missing occupation/district/center -> redirect to complete profile
      if (needsExtraDetails($pdo, $userId)) {
        header("Location: " . $completeProfileUrl);
        exit;
      }

      $msg = "✅ Profile updated successfully.";

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = "Update failed. Please try again.";
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Update Profile</title>
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
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:700px){.row{grid-template-columns:1fr}}
  </style>
</head>
<body>

<p style="display:flex;gap:10px;flex-wrap:wrap;">
  <a class="btn btn2" href="dashboard.php">← Back</a>
  <a class="btn btn2" href="update_qualification.php">Update Qualification</a>
</p>

<div class="card">
  <h2>Update Profile</h2>

  <?php if ($msg) echo "<p class='msg'>".h($msg)."</p>"; ?>
  <?php if ($err) echo "<p class='err'>".h($err)."</p>"; ?>

  <p class="muted">Update your name and phone number.</p>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">

    <div class="row">
      <div>
        <label>Full Name</label>
        <input type="text" name="full_name" value="<?php echo h($fullName); ?>" required>
      </div>
      <div>
        <label>Phone</label>
        <input type="text" name="phone" value="<?php echo h($phone); ?>" placeholder="+256..." />
      </div>
    </div>

    <label>Email (read-only)</label>
    <input type="text" value="<?php echo h($email); ?>" readonly>

    <button type="submit" style="margin-top:12px;">Save Changes</button>
  </form>

  <p class="muted" style="margin-top:12px;">
    If your application was rejected, updating here automatically re-submits it to <b>Pending</b>.
    If occupation/district/center is missing, you will be redirected to complete your profile.
  </p>
</div>

</body>
</html>
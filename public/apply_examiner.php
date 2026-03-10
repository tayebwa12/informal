<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/* ---------------- Helpers ---------------- */
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

/**
 * ✅ Standardize UG phone to 07XXXXXXXX (DB + login preferred format)
 * Accepts: 07XXXXXXXX, 7XXXXXXXX, 2567XXXXXXXX, +2567XXXXXXXX
 */
function normalizeUgPhone07(string $s): string {
  $p = cleanPhone($s);
  if ($p === '') return '';

  $p = ltrim($p, '+');

  if (preg_match('/^07\d{8}$/', $p)) {
    return $p;
  } elseif (preg_match('/^7\d{8}$/', $p)) {
    return '0' . $p;
  } elseif (preg_match('/^2567\d{8}$/', $p)) {
    return '0' . substr($p, 3); // 2567XXXXXXXX -> 07XXXXXXXX
  }

  // Unknown format
  return '';
}

/**
 * ✅ Legacy variants for matching old stored rows
 * returns: ['07...', '+2567...', '2567...']
 */
function phone_variants(string $raw): array {
  $p07 = normalizeUgPhone07($raw);
  if ($p07 === '') return [];

  $p256 = '+256' . substr($p07, 1); // 07... -> +2567...
  $digits256 = ltrim($p256, '+');   // 2567...

  return array_values(array_unique([$p07, $p256, $digits256]));
}

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0775, true);
}

/* ---------------- CSRF fallback ---------------- */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
  }
}
if (!function_exists('csrf_validate')) {
  function csrf_validate($token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $posted = (string)($token ?? '');
    if ($sessionToken === '' || $posted === '' || !hash_equals($sessionToken, $posted)) {
      http_response_code(419);
      die("Invalid CSRF token.");
    }
  }
}

/**
 * Save upload (optional).
 * Returns: [relativePath|null, error|null]
 */
function save_upload_optional(array $file, array $allowedExt, int $maxBytes, string $prefix): array {
  if (empty($file['name'])) return [null, null];
  if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return [null, "Upload failed."];
  if (isset($file['error']) && (int)$file['error'] !== UPLOAD_ERR_OK) return [null, "Upload error."];
  if ((int)($file['size'] ?? 0) > $maxBytes) return [null, "File too large."];

  $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) return [null, "Invalid file type."];

  $dirAbs = __DIR__ . "/uploads/applications";
  ensure_dir($dirAbs);

  $fname = $prefix . "_" . date('Ymd_His') . "_" . bin2hex(random_bytes(4)) . "." . $ext;
  if (!move_uploaded_file($file['tmp_name'], $dirAbs . "/" . $fname)) return [null, "Save failed."];

  return ["uploads/applications/" . $fname, null];
}

function table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function find_latest_application_by_nin(PDO $pdo, string $nin): ?array {
  $st = $pdo->prepare("SELECT * FROM examiner_applications WHERE nin=? ORDER BY id DESC LIMIT 1");
  $st->execute([$nin]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/**
 * ✅ Phone uniqueness check in examiner_applications (supports legacy stored formats too).
 */
function phone_exists_in_applications(PDO $pdo, string $rawPhone, ?int $excludeAppId = null): bool {
  $variants = phone_variants($rawPhone);
  if (!$variants) return false;

  $placeholders = implode(',', array_fill(0, count($variants), '?'));
  $sql = "SELECT id FROM examiner_applications WHERE phone IN ($placeholders)";
  $params = $variants;

  if ($excludeAppId !== null && $excludeAppId > 0) {
    $sql .= " AND id <> ?";
    $params[] = $excludeAppId;
  }

  $sql .= " LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (bool)$st->fetchColumn();
}

/**
 * ✅ Phone uniqueness check in users (supports legacy stored formats too).
 */
function phone_exists_in_users(PDO $pdo, string $rawPhone, ?int $excludeUserId = null): bool {
  $variants = phone_variants($rawPhone);
  if (!$variants) return false;

  $placeholders = implode(',', array_fill(0, count($variants), '?'));
  $sql = "SELECT id FROM users WHERE phone IN ($placeholders)";
  $params = $variants;

  if ($excludeUserId !== null && $excludeUserId > 0) {
    $sql .= " AND id <> ?";
    $params[] = $excludeUserId;
  }

  $sql .= " LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (bool)$st->fetchColumn();
}

/* --------- Ensure user account exists for public applicants --------- */
const DEFAULT_TEMP_PASSWORD = 'Uvt@b2026!';

function find_user_by_email_or_phone(PDO $pdo, string $email, string $phoneRaw): ?array {
  $email = strtolower(norm($email));
  $variants = phone_variants($phoneRaw);

  if ($email === '' && !$variants) return null;

  if ($variants) {
    $ph = implode(',', array_fill(0, count($variants), '?'));
    $sql = "SELECT id, password_hash FROM users WHERE email=? OR phone IN ($ph) LIMIT 1";
    $params = array_merge([$email], $variants);
  } else {
    $sql = "SELECT id, password_hash FROM users WHERE email=? LIMIT 1";
    $params = [$email];
  }

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/**
 * ✅ Creates user if not exists, returns user id.
 * Also ensures phone is stored as 07XXXXXXXX.
 * If user exists but password_hash is NULL/empty, set default password too.
 */
function ensure_user(PDO $pdo, string $full_name, string $email, string $phoneRaw, string $defaultPassword): int {
  $phone07 = normalizeUgPhone07($phoneRaw);
  $existing = find_user_by_email_or_phone($pdo, $email, $phoneRaw);

  if ($existing) {
    $uid = (int)$existing['id'];
    $hash = (string)($existing['password_hash'] ?? '');
    // update phone to 07 if needed
    if ($phone07 !== '') {
      $pdo->prepare("UPDATE users SET phone=? WHERE id=?")->execute([$phone07, $uid]);
    }
    if ($hash === '') {
      $newHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
      $st = $pdo->prepare("UPDATE users SET password_hash=?, status='active' WHERE id=?");
      $st->execute([$newHash, $uid]);
    }
    return $uid;
  }

  $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);

  $st = $pdo->prepare("
    INSERT INTO users (full_name, email, phone, region_id, password_hash, role, status, created_at, is_imported)
    VALUES (?, ?, ?, 0, ?, 'examiner', 'active', CURRENT_TIMESTAMP, 0)
  ");
  $st->execute([$full_name, $email, $phone07, $hash]);

  return (int)$pdo->lastInsertId();
}

/* ---------------- AJAX: live phone check (same page) ---------------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_phone') {
  header('Content-Type: application/json; charset=utf-8');

  $raw = (string)($_GET['phone'] ?? '');
  $phone07 = normalizeUgPhone07($raw);
  $exclude = (int)($_GET['exclude'] ?? 0);

  if ($phone07 === '') {
    echo json_encode(['ok'=>true,'exists'=>false,'normalized'=>'']);
    exit;
  }

  try {
    // Block if exists in applications OR users
    $existsApps = phone_exists_in_applications($pdo, $phone07, $exclude > 0 ? $exclude : null);
    $existsUsers = phone_exists_in_users($pdo, $phone07, null);

    echo json_encode([
      'ok'=>true,
      'exists'=>($existsApps || $existsUsers),
      'normalized'=>$phone07
    ]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'exists'=>false,'normalized'=>$phone07]);
  }
  exit;
}

/* ---------------- PERF: table existence check ---------------- */
$hasOccLinkTable = table_exists($pdo, 'examiner_occupations');

/* ---------------- Load lists ---------------- */
$occupations = $pdo->query("SELECT id, code, name FROM occupations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$districts   = $pdo->query("SELECT id, name FROM districts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$centers     = $pdo->query("SELECT id, center_number, center_name FROM centers WHERE status='active' ORDER BY center_name")->fetchAll(PDO::FETCH_ASSOC);

$occMap = [];
foreach ($occupations as $o) {
  $occMap[(int)$o['id']] = ['code' => (string)$o['code'], 'name' => (string)$o['name']];
}

/* ---------------- Login (optional) ---------------- */
$isLoggedInExaminer = false;
$userId = 0;

if (isset($_SESSION['user']) && is_array($_SESSION['user']) && ($_SESSION['user']['role'] ?? '') === 'examiner') {
  $isLoggedInExaminer = true;
  $userId = (int)($_SESSION['user']['id'] ?? 0);
}

/* ---------------- Load existing application (only for logged-in examiner) ---------------- */
$appRow = null;
if ($isLoggedInExaminer && $userId > 0) {
  $st = $pdo->prepare("SELECT * FROM examiner_applications WHERE user_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$userId]);
  $appRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$isEdit = (bool)$appRow;

$errors = [];
$notice = null;

/* ---------------- Load selected occupations (edit mode only) ---------------- */
$selectedOccIds = [];
if ($isEdit && $hasOccLinkTable && $appRow && (int)$appRow['id'] > 0) {
  $st = $pdo->prepare("SELECT occupation_id FROM examiner_occupations WHERE application_id=?");
  $st->execute([(int)$appRow['id']]);
  $selectedOccIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
} else {
  if (!empty($appRow['occupation_id'])) $selectedOccIds = [(int)$appRow['occupation_id']];
}

/* ---------------- Prefill ---------------- */
$pref_full_name = (string)($_POST['full_name'] ?? ($appRow['full_name'] ?? ''));
$pref_phone     = (string)($_POST['phone'] ?? ($appRow['phone'] ?? ''));
$pref_email     = (string)($_POST['email'] ?? ($appRow['email'] ?? ''));
$pref_nin       = (string)($_POST['nin'] ?? ($appRow['nin'] ?? ''));
$pref_dist_id   = (int)($_POST['district_id'] ?? ($appRow['district_id'] ?? 0));
$pref_org_name  = (string)($_POST['organisation_name'] ?? ($appRow['organisation_name'] ?? ''));

$pref_center_choice = (string)($_POST['center_choice'] ?? '');
if ($pref_center_choice === '') {
  if (!empty($appRow['center_id'])) $pref_center_choice = (string)$appRow['center_id'];
  elseif (!empty($appRow['organisation_name'])) $pref_center_choice = 'wow';
}

$pref_occ_ids = $_POST['occupation_ids'] ?? $selectedOccIds;
if (!is_array($pref_occ_ids)) $pref_occ_ids = $selectedOccIds;

/* Exclude id for uniqueness checks (used by server + JS) */
$excludeAppIdForChecks = 0;
if ($isEdit && $isLoggedInExaminer && !empty($appRow['id'])) {
  $excludeAppIdForChecks = (int)$appRow['id'];
} else {
  $ninTmp = strtoupper(norm((string)($_POST['nin'] ?? '')));
  if (!$isLoggedInExaminer && $ninTmp !== '') {
    $existingByNinForExclude = find_latest_application_by_nin($pdo, $ninTmp);
    if ($existingByNinForExclude) $excludeAppIdForChecks = (int)$existingByNinForExclude['id'];
  }
}

/* ---------------- POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  $full_name = norm((string)($_POST['full_name'] ?? ''));
  $phone07   = normalizeUgPhone07((string)($_POST['phone'] ?? '')); // ✅ save as 07
  $email     = strtolower(norm((string)($_POST['email'] ?? '')));
  $nin       = strtoupper(norm((string)($_POST['nin'] ?? '')));
  $district_id = (int)($_POST['district_id'] ?? 0);

  $occ_ids = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['occupation_ids'] ?? []))
  )));

  $center_choice = (string)($_POST['center_choice'] ?? '');
  $organisation_name = norm((string)($_POST['organisation_name'] ?? ''));

  if ($full_name === '') $errors[] = "Full name is required.";
  if ($phone07 === '') $errors[] = "Valid phone is required (07XXXXXXXX).";
  if ($email === '') $errors[] = "Email is required.";
  elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Enter a valid email address.";
  if ($nin === '') $errors[] = "NIN is required.";
  if ($district_id <= 0) $errors[] = "Please select a district.";
  if (!$occ_ids) $errors[] = "Select at least one occupation.";
  if ($center_choice === '') $errors[] = "Please choose an affiliated center.";

  $center_id = null;
  if ($center_choice === 'wow') {
    if ($organisation_name === '') $errors[] = "Organisation name is required when World of Work is selected.";
    $center_id = null;
  } else {
    $center_id = (int)$center_choice;
    if ($center_id <= 0) $errors[] = "Invalid center selected.";
    $organisation_name = '';
  }

  // Phone uniqueness checks
  if ($phone07 !== '' && !$errors) {
    $excludeId = null;

    if ($isEdit && $isLoggedInExaminer && !empty($appRow['id'])) {
      $excludeId = (int)$appRow['id'];
    } else {
      if (!$isLoggedInExaminer && $nin !== '') {
        $existingByNinForExclude = find_latest_application_by_nin($pdo, $nin);
        if ($existingByNinForExclude) $excludeId = (int)$existingByNinForExclude['id'];
      }
    }

    // Block if phone belongs to another applicant/user (supports legacy)
    if (phone_exists_in_applications($pdo, $phone07, $excludeId) || phone_exists_in_users($pdo, $phone07, $isLoggedInExaminer ? $userId : null)) {
      $errors[] = "This phone number belongs to another registered applicant (the real owner). If it is your number, please log in to your account or use the same NIN you applied with to update your existing application.";
    }
  }

  // Build occupation text and primary
  $parts = [];
  foreach ($occ_ids as $oid) {
    if (!isset($occMap[$oid])) continue;
    $parts[] = trim($occMap[$oid]['code']) . ' - ' . trim($occMap[$oid]['name']);
  }
  $occupationText = implode(', ', $parts);
  $primary_occ_id = $occ_ids[0] ?? null;

  // Uploads
  $oldPassport = (string)($appRow['passport_photo_path'] ?? '');
  $oldQual     = (string)($appRow['qualification_path'] ?? '');

  $passportPath = $oldPassport ?: null;
  $qualPath     = $oldQual ?: null;

  $isNewApplication = !$isEdit;

  if (!$errors) {
    if (!empty($_FILES['passport_photo']['name'] ?? '')) {
      $prefix = $isLoggedInExaminer ? "passport_{$userId}" : "passport_public";
      [$newPass, $passErr] = save_upload_optional($_FILES['passport_photo'], ['jpg','jpeg','png'], 3*1024*1024, $prefix);
      if ($passErr) $errors[] = $passErr;
      if ($newPass) $passportPath = $newPass;
    } elseif ($isNewApplication && !$passportPath) {
      $errors[] = "Passport photo is required.";
    }

    if (!empty($_FILES['qualification']['name'] ?? '')) {
      $prefix = $isLoggedInExaminer ? "qual_{$userId}" : "qual_public";
      [$newQual, $qualErr] = save_upload_optional($_FILES['qualification'], ['pdf'], 5*1024*1024, $prefix);
      if ($qualErr) $errors[] = $qualErr;
      if ($newQual) $qualPath = $newQual;
    } elseif ($isNewApplication && !$qualPath) {
      $errors[] = "Academic qualification PDF is required.";
    }
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      if ($isEdit && $isLoggedInExaminer && $userId > 0) {
        // Logged in examiner update
        $appId = (int)$appRow['id'];

        $st = $pdo->prepare("
          UPDATE examiner_applications
          SET full_name=?, phone=?, email=?, nin=?, district_id=?,
              center_id=?, organisation_name=?,
              passport_photo_path=?, qualification_path=?,
              occupation_id=?, occupation=?,
              submitted_at=CURRENT_TIMESTAMP
          WHERE id=? AND user_id=?
        ");
        $st->execute([
          $full_name, $phone07, $email, $nin, $district_id,
          $center_id, ($center_choice === 'wow' ? $organisation_name : null),
          $passportPath, $qualPath,
          $primary_occ_id, ($occupationText !== '' ? $occupationText : null),
          $appId, $userId
        ]);

        // Keep users.phone updated too
        $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")->execute([$full_name, $email, $phone07, $userId]);

        if ($hasOccLinkTable) {
          $pdo->prepare("DELETE FROM examiner_occupations WHERE application_id=?")->execute([$appId]);
          $insOcc = $pdo->prepare("INSERT INTO examiner_occupations(application_id, occupation_id) VALUES(?, ?)");
          foreach ($occ_ids as $oid) $insOcc->execute([$appId, $oid]);
        }

        $notice = ['type'=>'success','title'=>'Saved','message'=>'Profile updated successfully.'];

      } else {
        // PUBLIC handling: create/ensure user login & link
        $existingByNin = null;
        if (!$isLoggedInExaminer) {
          $existingByNin = find_latest_application_by_nin($pdo, $nin);
        }

        if ($existingByNin) {
          $appId = (int)$existingByNin['id'];

          // Ensure user exists + link it if missing
          $uid = (int)($existingByNin['user_id'] ?? 0);
          if ($uid <= 0) {
            $uid = ensure_user($pdo, $full_name, $email, $phone07, DEFAULT_TEMP_PASSWORD);
            $pdo->prepare("UPDATE examiner_applications SET user_id=? WHERE id=?")->execute([$uid, $appId]);
          } else {
            // sync user details (safe)
            $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")->execute([$full_name, $email, $phone07, $uid]);
          }

          $st = $pdo->prepare("
            UPDATE examiner_applications
            SET full_name=?, phone=?, email=?, nin=?, district_id=?,
                center_id=?, organisation_name=?,
                passport_photo_path=?, qualification_path=?,
                status='pending',
                occupation_id=?, occupation=?,
                submitted_at=CURRENT_TIMESTAMP
            WHERE id=?
          ");
          $st->execute([
            $full_name, $phone07, $email, $nin, $district_id,
            $center_id, ($center_choice === 'wow' ? $organisation_name : null),
            $passportPath, $qualPath,
            $primary_occ_id, ($occupationText !== '' ? $occupationText : null),
            $appId
          ]);

          if ($hasOccLinkTable) {
            $pdo->prepare("DELETE FROM examiner_occupations WHERE application_id=?")->execute([$appId]);
            $insOcc = $pdo->prepare("INSERT INTO examiner_occupations(application_id, occupation_id) VALUES(?, ?)");
            foreach ($occ_ids as $oid) $insOcc->execute([$appId, $oid]);
          }

          $notice = [
            'type'=>'success',
            'title'=>'Updated',
            'message'=>'Your existing application (by NIN) has been updated successfully. You can now log in using your phone (07XXXXXXXX) and the provided password.'
          ];

          // Clear form after public update
          $pref_full_name = $pref_phone = $pref_email = $pref_nin = $pref_org_name = '';
          $pref_dist_id = 0;
          $pref_center_choice = '';
          $pref_occ_ids = [];

        } else {
          // Create user login first for public new applicant
          $uid = ensure_user($pdo, $full_name, $email, $phone07, DEFAULT_TEMP_PASSWORD);

          $st = $pdo->prepare("
            INSERT INTO examiner_applications
              (user_id, full_name, phone, email, nin, district_id, center_id, organisation_name,
               passport_photo_path, qualification_path, status, occupation_id, occupation, submitted_at)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, CURRENT_TIMESTAMP)
          ");
          $st->execute([
            $uid,
            $full_name, $phone07, $email, $nin, $district_id,
            $center_id, ($center_choice === 'wow' ? $organisation_name : null),
            $passportPath, $qualPath,
            $primary_occ_id, ($occupationText !== '' ? $occupationText : null),
          ]);

          $appId = (int)$pdo->lastInsertId();

          if ($hasOccLinkTable) {
            $insOcc = $pdo->prepare("INSERT INTO examiner_occupations(application_id, occupation_id) VALUES(?, ?)");
            foreach ($occ_ids as $oid) $insOcc->execute([$appId, $oid]);
          }

          $notice = [
            'type'=>'success',
            'title'=>'Submitted',
            'message'=>"Application submitted successfully. Your login has been created. Use your phone number (07XXXXXXXX) and the temporary password to log in."
          ];

          // Clear form after submit (public)
          $pref_full_name = $pref_phone = $pref_email = $pref_nin = $pref_org_name = '';
          $pref_dist_id = 0;
          $pref_center_choice = '';
          $pref_occ_ids = [];
        }
      }

      $pdo->commit();

      // Reload (only for logged in)
      if ($isLoggedInExaminer && $userId > 0) {
        $st = $pdo->prepare("SELECT * FROM examiner_applications WHERE user_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([$userId]);
        $appRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $isEdit = (bool)$appRow;

        if ($isEdit && $hasOccLinkTable && $appRow && (int)$appRow['id'] > 0) {
          $st = $pdo->prepare("SELECT occupation_id FROM examiner_occupations WHERE application_id=?");
          $st->execute([(int)$appRow['id']]);
          $selectedOccIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } else {
          $selectedOccIds = $primary_occ_id ? [(int)$primary_occ_id] : [];
        }
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();

      $msg = $e->getMessage();
      if (stripos($msg, 'Duplicate') !== false && (stripos($msg, 'nin') !== false || stripos($msg, 'phone') !== false || stripos($msg, 'email') !== false)) {
        $msg = "This record already exists. The phone/email/NIN belongs to an existing applicant (the real owner). If it is yours, please log in or use your original NIN to update.";
      } else {
        $msg = "Something went wrong while saving. Please try again.";
      }
      $notice = ['type'=>'error','title'=>'Error','message'=>$msg];
    }
  } else {
    $notice = ['type'=>'error','title'=>'Fix these','message'=>$errors[0] ?? 'Please correct the highlighted fields.'];
  }
}

$title = ($isEdit && $isLoggedInExaminer) ? 'Update Assessor Application' : 'Apply as Assessor';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="preconnect" href="https://cdnjs.cloudflare.com">
  <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"></noscript>

  <style>
    :root{
      --primary:#2563eb;
      --bg:#f8fafc;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --border:#e2e8f0;
      --radius:14px;
      --success:#10b981;
      --danger:#ef4444;
      --shadow:0 10px 25px rgba(2,6,23,.08);
    }
    *{box-sizing:border-box}
    body{
      background:var(--bg);
      color:var(--text);
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif;
      margin:0; padding:2rem 1rem; line-height:1.45;
    }
    .wrap{max-width:920px;margin:0 auto}
    .card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      overflow:hidden;
    }
    .header{
      padding:2rem 2.25rem 1.25rem;
      border-bottom:1px solid var(--border);
      display:flex; justify-content:space-between; gap:1rem; align-items:flex-start;
    }
    .header h2{margin:0;font-size:1.6rem;font-weight:900;letter-spacing:-.02em}
    .header p{margin:.35rem 0 0;color:var(--muted);font-size:.95rem}
    .close-link{
      text-decoration:none;color:var(--muted);font-size:14px;font-weight:700;
      display:inline-flex;gap:.4rem;align-items:center;
    }
    .close-link:hover{color:#0f172a}
    .body{padding:2rem 2.25rem}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem}
    @media(max-width:720px){.header{padding:1.6rem}.body{padding:1.6rem}.grid{grid-template-columns:1fr}}
    label{display:block;font-size:.85rem;font-weight:800;color:#334155;margin:0 0 .5rem}
    input,select,.custom-select{
      width:100%;
      padding:.78rem 1rem;
      border:1px solid var(--border);
      border-radius:12px;
      font-size:1rem;
      background:#fff;
    }
    input:focus,select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 4px rgba(37,99,235,.12)}
    .form-group{margin-bottom:1.2rem}
    .muted{color:var(--muted);font-size:.88rem}

    .msgbox{
      display:flex;gap:12px;align-items:flex-start;
      padding:14px 14px;
      border-radius:14px;
      border:1px solid var(--border);
      margin-bottom:1.2rem;
      box-shadow:0 8px 20px rgba(2,6,23,.08);
    }
    .msgbox .icon{
      width:40px;height:40px;border-radius:12px;
      display:grid;place-items:center;
      color:#fff;flex:0 0 auto;
    }
    .msgbox.success{background:#ecfdf5;border-color:#bbf7d0}
    .msgbox.success .icon{background:var(--success)}
    .msgbox.success .title{color:#065f46}
    .msgbox.error{background:#fef2f2;border-color:#fecaca}
    .msgbox.error .icon{background:var(--danger)}
    .msgbox.error .title{color:#991b1b}
    .msgbox .title{font-weight:1000;margin:0 0 2px}
    .msgbox .text{margin:0;color:#334155;font-size:.92rem}

    .hint{
      margin-top:.5rem;
      font-size:.86rem;
      padding:.6rem .75rem;
      border-radius:12px;
      border:1px solid var(--border);
      background:#f8fafc;
      display:none;
    }
    .hint.bad{display:block;background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .hint.good{display:block;background:#ecfdf5;border-color:#bbf7d0;color:#065f46}

    .occ-dropdown{position:relative}
    .occ-selector{min-height:50px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;cursor:pointer}
    .tag{
      background:#dbeafe;color:#1e40af;
      padding:4px 10px;border-radius:999px;font-size:12px;font-weight:900;
      display:inline-flex;align-items:center;gap:8px;
    }
    .occ-list{
      position:absolute;top:100%;left:0;right:0;z-index:50;
      background:#fff;border:1px solid var(--border);
      border-radius:14px;margin-top:10px;
      box-shadow:0 18px 40px rgba(2,6,23,.12);
      max-height:320px;overflow:auto;display:none;
    }
    .occ-search{position:sticky;top:0;background:#f8fafc;border-bottom:1px solid var(--border);padding:10px}
    .occ-search input{padding:.55rem .7rem;border-radius:10px;font-size:.9rem}
    .occ-option{display:flex;gap:10px;align-items:center;padding:10px 14px;cursor:pointer;font-size:.92rem}
    .occ-option:hover{background:#f1f5f9}
    .occ-option input{width:auto}

    .upload{
      border:1.5px dashed var(--border);
      border-radius:14px;background:#f8fafc;
      padding:1rem;text-align:center;
    }
    .btn{
      width:100%;border:none;border-radius:14px;
      padding:1rem 1.1rem;font-weight:900;font-size:1rem;
      background:linear-gradient(135deg,var(--primary),#1e40af);
      color:#fff;cursor:pointer;
    }
    .btn:disabled{opacity:.6;cursor:not-allowed;filter:saturate(.6);}
  </style>
</head>
<body>

<div class="wrap">
  <div class="card">
    <div class="header">
      <div>
        <h2><?= h($title) ?></h2>
        <p><?= ($isEdit && $isLoggedInExaminer) ? 'Keep your information up-to-date.' : 'Register as an official UVTAB Assessor.'; ?></p>
        <?php if (!$isLoggedInExaminer): ?>
          <p class="muted" style="margin-top:.6rem;">
            Public application is allowed. If you have an account, log in to update later.
          </p>
          <p class="muted" style="margin-top:.3rem;">
            Login phone will be saved in standardized format (e.g. 07XXXXXXXX).
          </p>
        <?php endif; ?>
      </div>

      <?php if ($isEdit && $isLoggedInExaminer): ?>
        <a class="close-link" href="/public/examiner/dashboard.php">
          <i class="fa-solid fa-xmark"></i> Close
        </a>
      <?php endif; ?>
    </div>

    <div class="body">

      <?php if ($notice && isset($notice['type'],$notice['title'],$notice['message'])): ?>
        <div class="msgbox <?= h($notice['type']) ?>">
          <div class="icon">
            <?php if ($notice['type'] === 'success'): ?>
              <i class="fa-solid fa-check"></i>
            <?php else: ?>
              <i class="fa-solid fa-triangle-exclamation"></i>
            <?php endif; ?>
          </div>
          <div>
            <p class="title"><?= h($notice['title']) ?></p>
            <p class="text"><?= h($notice['message']) ?></p>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" autocomplete="off" id="assessorForm" novalidate>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">

        <div class="grid">
          <div class="form-group">
            <label><i class="fa-solid fa-user"></i> Full Name</label>
            <input name="full_name" required value="<?= h($pref_full_name) ?>" placeholder="As it appears on ID">
          </div>

          <div class="form-group">
            <label><i class="fa-solid fa-phone"></i> Phone Number</label>
            <input name="phone" id="phone" required value="<?= h($pref_phone) ?>" placeholder="e.g. 0770000000" inputmode="tel">
            <div id="phoneHint" class="hint"></div>
          </div>
        </div>

        <div class="grid">
          <div class="form-group">
            <label><i class="fa-solid fa-envelope"></i> Email Address</label>
            <input name="email" type="email" required value="<?= h($pref_email) ?>" placeholder="email@example.com">
          </div>
          <div class="form-group">
            <label><i class="fa-solid fa-id-card"></i> National ID (NIN)</label>
            <input name="nin" id="nin" required value="<?= h($pref_nin) ?>" placeholder="14-character NIN">
          </div>
        </div>

        <div class="form-group">
          <label><i class="fa-solid fa-briefcase"></i> Occupation(s)</label>
          <div class="occ-dropdown">
            <div class="custom-select occ-selector" id="occ_selector">
              <span id="occ_placeholder" class="muted">Select one or more occupations...</span>
            </div>

            <div class="occ-list" id="occ_list">
              <div class="occ-search">
                <input type="text" id="occ_filter" placeholder="Search occupation...">
              </div>

              <?php foreach ($occupations as $o): ?>
                <?php $oid = (int)$o['id']; ?>
                <label class="occ-option" data-name="<?= h(strtolower($o['name'] . ' ' . $o['code'])) ?>">
                  <input type="checkbox"
                         name="occupation_ids[]"
                         value="<?= $oid ?>"
                         data-label="<?= h($o['name']) ?>"
                         <?= in_array($oid, array_map('intval', (array)$pref_occ_ids), true) ? 'checked' : '' ?>>
                  <span><strong><?= h($o['code']) ?></strong> - <?= h($o['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <small class="muted">You must select at least one occupation.</small>
        </div>

        <div class="grid">
          <div class="form-group">
            <label><i class="fa-solid fa-location-dot"></i> Resident District</label>
            <select name="district_id" required>
              <option value="">Choose District(where you work from)</option>
              <?php foreach ($districts as $d): ?>
                <option value="<?= (int)$d['id']; ?>" <?= ($pref_dist_id === (int)$d['id']) ? 'selected' : ''; ?>>
                  <?= h($d['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label><i class="fa-solid fa-building"></i> Affiliated Center (Where you currently work)</label>
            <select name="center_choice" id="center_choice" required>
              <option value="">Choose Center</option>
              <option value="wow" <?= ($pref_center_choice === 'wow') ? 'selected' : ''; ?>>World of Work (Organisation)</option>
              <?php foreach ($centers as $c): ?>
                <option value="<?= (int)$c['id']; ?>" <?= ((string)$pref_center_choice === (string)$c['id']) ? 'selected' : ''; ?>>
                  <?= h($c['center_number'] . ' - ' . $c['center_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group" id="org_wrap" style="display:none;">
          <label><i class="fa-solid fa-briefcase"></i> Organisation Name</label>
          <input name="organisation_name" id="organisation_name" value="<?= h($pref_org_name) ?>" placeholder="Name of your workplace">
        </div>

        <div class="grid" style="margin-top:.4rem;">
          <div class="form-group">
            <label><i class="fa-solid fa-camera"></i> Passport Photo</label>
            <div class="upload">
              <input type="file" name="passport_photo" accept="image/*" <?= (!$isEdit ? 'required' : '') ?>>
              <small class="muted">JPG/PNG, Max 3MB</small>
              <?php if (!empty($appRow['passport_photo_path'])): ?>
                <small class="muted">Current: <?= h((string)$appRow['passport_photo_path']) ?></small>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-group">
            <label><i class="fa-solid fa-file-pdf"></i> Academic Qualification</label>
            <div class="upload">
              <input type="file" name="qualification" accept=".pdf" <?= (!$isEdit ? 'required' : '') ?>>
              <small class="muted">PDF Only, Max 5MB</small>
              <?php if (!empty($appRow['qualification_path'])): ?>
                <small class="muted">Current: <?= h((string)$appRow['qualification_path']) ?></small>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div style="margin-top:1.4rem;">
          <button class="btn" id="submitBtn" type="submit">
            <i class="fa-solid fa-paper-plane"></i>
            <?= ($isEdit && $isLoggedInExaminer) ? 'Update Details' : 'Submit Application' ?>
          </button>
          <small class="muted" style="display:block;margin-top:.6rem;">
            Tip: If you previously applied using the same NIN (public), submitting again will update your existing record.
          </small>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  // Center Toggle
  (function(){
    const centerChoice = document.getElementById('center_choice');
    const orgWrap = document.getElementById('org_wrap');
    function toggleOrg() {
      if (!centerChoice || !orgWrap) return;
      orgWrap.style.display = centerChoice.value === 'wow' ? 'block' : 'none';
    }
    if (centerChoice) {
      centerChoice.addEventListener('change', toggleOrg, {passive:true});
      toggleOrg();
    }
  })();

  // Custom Multi-select Logic
  (function(){
    const occSelector = document.getElementById('occ_selector');
    const occList = document.getElementById('occ_list');
    const occFilter = document.getElementById('occ_filter');
    const checkboxes = document.querySelectorAll('input[name="occupation_ids[]"]');
    const placeholder = document.getElementById('occ_placeholder');
    if (!occSelector || !occList) return;

    function openClose(force) {
      const isOpen = occList.style.display === 'block';
      const next = (force === undefined) ? !isOpen : !!force;
      occList.style.display = next ? 'block' : 'none';
      if (next && occFilter) occFilter.focus();
    }

    occSelector.addEventListener('click', () => openClose(), {passive:true});
    document.addEventListener('click', (e) => {
      if (!occSelector.contains(e.target) && !occList.contains(e.target)) openClose(false);
    });

    function updateTags() {
      const selected = [];
      checkboxes.forEach(cb => { if (cb.checked) selected.push(cb); });

      occSelector.innerHTML = '';
      if (selected.length === 0) {
        occSelector.appendChild(placeholder);
        return;
      }

      const maxVisible = 6;
      selected.slice(0, maxVisible).forEach(cb => {
        const tag = document.createElement('span');
        tag.className = 'tag';
        const label = cb.getAttribute('data-label') || 'Selected';
        tag.innerHTML = label + ' <i class="fa-solid fa-xmark"></i>';
        tag.addEventListener('click', (e) => {
          e.stopPropagation();
          cb.checked = false;
          updateTags();
        });
        occSelector.appendChild(tag);
      });

      if (selected.length > maxVisible) {
        const more = document.createElement('span');
        more.className = 'tag';
        more.textContent = `+${selected.length - maxVisible} more`;
        occSelector.appendChild(more);
      }
    }

    checkboxes.forEach(cb => cb.addEventListener('change', updateTags, {passive:true}));

    if (occFilter) {
      let raf = 0;
      occFilter.addEventListener('input', (e) => {
        const term = (e.target.value || '').toLowerCase();
        cancelAnimationFrame(raf);
        raf = requestAnimationFrame(() => {
          document.querySelectorAll('.occ-option').forEach(opt => {
            const name = (opt.getAttribute('data-name') || '');
            opt.style.display = name.includes(term) ? 'flex' : 'none';
          });
        });
      }, {passive:true});
    }

    updateTags();
  })();

  // Live phone uniqueness check + block submit
  (function(){
    const phone = document.getElementById('phone');
    const hint = document.getElementById('phoneHint');
    const submitBtn = document.getElementById('submitBtn');
    const excludeId = <?= (int)$excludeAppIdForChecks ?>;

    if (!phone || !hint || !submitBtn) return;

    let t = 0;
    let lastValue = '';

    function setHint(type, msg) {
      hint.className = 'hint ' + (type || '');
      hint.textContent = msg || '';
      hint.style.display = msg ? 'block' : 'none';
    }

    async function check(value) {
      const v = (value || '').trim();
      if (!v) {
        setHint('', '');
        submitBtn.disabled = false;
        return;
      }
      if (v === lastValue) return;
      lastValue = v;

      try {
        const url = `?ajax=check_phone&phone=${encodeURIComponent(v)}&exclude=${encodeURIComponent(excludeId)}`;
        const res = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();

        if (!data || data.ok !== true) {
          setHint('', '');
          submitBtn.disabled = false;
          return;
        }

        if (data.exists) {
          setHint('bad', 'This phone number belongs to another registered applicant (the real owner). If it is your number, please log in to your account and update.');
          submitBtn.disabled = true;
        } else {
          if (data.normalized) {
            setHint('good', 'Phone looks good. It will be saved as: ' + data.normalized);
          } else {
            setHint('good', 'Phone number is available.');
          }
          submitBtn.disabled = false;
        }
      } catch (e) {
        setHint('', '');
        submitBtn.disabled = false;
      }
    }

    phone.addEventListener('input', function(){
      clearTimeout(t);
      t = setTimeout(() => check(phone.value), 350);
    }, {passive:true});
  })();

  // Client-side block submission if required fields missing
  (function(){
    const form = document.getElementById('assessorForm');
    const submitBtn = document.getElementById('submitBtn');
    const phoneHint = document.getElementById('phoneHint');
    if (!form) return;

    function hasCheckedOcc() {
      return document.querySelectorAll('input[name="occupation_ids[]"]:checked').length > 0;
    }

    form.addEventListener('submit', function(e){
      if (submitBtn && submitBtn.disabled) {
        e.preventDefault();
        phoneHint && phoneHint.scrollIntoView({behavior:'smooth', block:'center'});
        alert('Phone number is already used. Please use a different phone number.');
        return;
      }

      const requiredFields = form.querySelectorAll('[required]');
      for (const el of requiredFields) {
        if (!el.value || el.value.trim() === '') {
          e.preventDefault();
          el.focus();
          alert('Please fill all required fields.');
          return;
        }
      }

      if (!hasCheckedOcc()) {
        e.preventDefault();
        alert('Please select at least one occupation.');
        return;
      }

      const centerChoice = document.getElementById('center_choice');
      const orgName = document.getElementById('organisation_name');
      if (centerChoice && centerChoice.value === 'wow') {
        if (!orgName || orgName.value.trim() === '') {
          e.preventDefault();
          alert('Organisation name is required when World of Work is selected.');
          orgName && orgName.focus();
          return;
        }
      }
    });
  })();
</script>

</body>
</html>
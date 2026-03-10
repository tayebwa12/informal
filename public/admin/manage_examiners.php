<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

require_admin();

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf_check(string $tokenFromPost): void {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $tokenFromPost)) {
        http_response_code(403);
        exit("Invalid CSRF token");
    }
}

$msg = null;

/* ---------------- Helpers ---------------- */
$defaultPasswordPlain = 'uvtab';
$defaultPasswordHash  = password_hash($defaultPasswordPlain, PASSWORD_DEFAULT);

function makeImportEmail(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (!$digits) $digits = (string)time() . (string)rand(100, 999);
    return 'imp_' . substr($digits, 0, 20) . '@uvtab.local';
}

/**
 * Your examiner_applications table uses home_region_id (not region_id)
 */
function examinerApplicationsHasHomeRegionId(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM examiner_applications LIKE 'home_region_id'");
        $cached = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function examinerApplicationsHasDistrictId(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM examiner_applications LIKE 'district_id'");
        $cached = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function examinerApplicationsHasCenterId(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM examiner_applications LIKE 'center_id'");
        $cached = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Make sure examiner_applications.user_id is linked using users.phone.
 */
function linkApplicationToUserByPhone(PDO $pdo, string $phone, int $userId): void {
    if ($phone === '' || $userId <= 0) return;

    $pdo->prepare("
        UPDATE examiner_applications
        SET user_id = ?
        WHERE phone = ? AND (user_id IS NULL OR user_id = 0)
        ORDER BY id DESC
        LIMIT 1
    ")->execute([$userId, $phone]);
}

/* ---------------- POST ACTIONS ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check((string)($_POST['csrf'] ?? ''));
    $action = (string)($_POST['action'] ?? '');

    /* ---------- EXPORT APPLICANTS (NOT IMPORTED) ---------- */
    if ($action === 'export_non_imported_examiners') {

        $sql = "
            SELECT
                ea.id AS application_id,
                ea.full_name,
                ea.phone,
                ea.email,
                COALESCE(o.name, 'General') AS occupation,
                ea.status AS application_status,
                ea.submitted_at,

                ea.district_id,
                d.name AS district_name,

                ea.center_id,
                ea.organisation_name,

                COALESCE(c1.center_number, c2.center_number, c3.center_number) AS center_number,
                COALESCE(c1.center_name,   c2.center_name,   c3.center_name)   AS center_name,
                COALESCE(c1.location_name, c2.location_name, c3.location_name) AS center_location,

                u.id AS user_id,
                u.email AS user_email,
                u.region_id,
                u.role,
                u.status AS user_status,
                u.created_at AS user_created_at,
                u.is_imported,
                u.imported_at,
                u.qualification_file,
                u.qualification_uploaded_at
            FROM examiner_applications ea
            INNER JOIN users u ON u.id = ea.user_id
            LEFT JOIN occupations o ON o.id = ea.occupation_id
            LEFT JOIN districts d ON d.id = ea.district_id

            LEFT JOIN centers c1 ON c1.id = ea.center_id
            LEFT JOIN centers c2 ON c2.center_number = CAST(ea.center_id AS CHAR)
            LEFT JOIN centers c3 ON c3.center_number = CONCAT('UBT', CAST(ea.center_id AS CHAR))

            WHERE u.role = 'examiner'
              AND u.is_imported = 0
            ORDER BY ea.id DESC
        ";

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $filename = "assessors_applied_not_imported_" . date('Y-m-d_His') . ".csv";

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'Application ID',
            'Full Name',
            'Phone',
            'Email',
            'Occupation',
            'Application Status',
            'Submitted At',
            'District ID',
            'District Name',
            'Center ID (stored)',
            'Center Number (from centers)',
            'Center Name (from centers)',
            'Center Location (from centers)',
            'Organisation Name (World of Work)',
            'User ID',
            'User Email',
            'Region ID',
            'Role',
            'User Status',
            'User Created At',
            'Is Imported',
            'Imported At',
            'Qualification File',
            'Qualification Uploaded At'
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['application_id'] ?? '',
                $r['full_name'] ?? '',
                $r['phone'] ?? '',
                $r['email'] ?? '',
                $r['occupation'] ?? '',
                $r['application_status'] ?? '',
                $r['submitted_at'] ?? '',
                $r['district_id'] ?? '',
                $r['district_name'] ?? '',
                $r['center_id'] ?? '',
                $r['center_number'] ?? '',
                $r['center_name'] ?? '',
                $r['center_location'] ?? '',
                $r['organisation_name'] ?? '',
                $r['user_id'] ?? '',
                $r['user_email'] ?? '',
                $r['region_id'] ?? '',
                $r['role'] ?? '',
                $r['user_status'] ?? '',
                $r['user_created_at'] ?? '',
                $r['is_imported'] ?? '',
                $r['imported_at'] ?? '',
                $r['qualification_file'] ?? '',
                $r['qualification_uploaded_at'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    /* ---------- DOWNLOAD LAST SKIP REPORT ---------- */
    if ($action === 'download_skip_report') {
        $reportPath = (string)($_SESSION['last_import_skip_report_path'] ?? '');
        if ($reportPath === '' || !is_file($reportPath)) {
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?msg=" . urlencode("Skip report not found."));
            exit;
        }

        $filename = basename($reportPath);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($reportPath);
        exit;
    }

    /* ---------- IMPORT XLSX ---------- */
    if ($action === 'import' && isset($_FILES['excel_file']) && (int)$_FILES['excel_file']['error'] === 0) {

        if (!class_exists('ZipArchive')) {
            $msg = "Import failed: ZipArchive extension is not enabled on this server.";
        } else {
            $fileTmp = $_FILES['excel_file']['tmp_name'];
            $zip = new ZipArchive();

            if ($zip->open($fileTmp) === TRUE) {
                $sharedStrings = [];
                $ssData = $zip->getFromName('xl/sharedStrings.xml');

                if ($ssData) {
                    $ssXml = @simplexml_load_string($ssData);
                    if ($ssXml && isset($ssXml->si)) {
                        foreach ($ssXml->si as $si) {
                            $sharedStrings[] = (string)($si->t ?: ($si->r->t ?? '') ?: '');
                        }
                    }
                }

                $sheetData = $zip->getFromName('xl/worksheets/sheet1.xml');

                if ($sheetData) {
                    $xml = @simplexml_load_string($sheetData);

                    // ----- Occupations map -----
                    $occMap = [];
                    $occQuery = $pdo->query("SELECT id, LOWER(TRIM(name)) as name FROM occupations")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($occQuery as $o) {
                        if (!empty($o['name'])) $occMap[(string)$o['name']] = (int)$o['id'];
                    }

                    // ----- District map (name -> [id, region_id]) -----
                    $districtMap = [];
                    $districtRows = $pdo->query("
                        SELECT id, region_id, LOWER(TRIM(name)) AS name
                        FROM districts
                    ")->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($districtRows as $d) {
                        if (!empty($d['name'])) {
                            $districtMap[(string)$d['name']] = [
                                'id' => (int)$d['id'],
                                'region_id' => (int)$d['region_id'],
                            ];
                        }
                    }

                    // ----- Center map (center_number -> center_id) -----
                    $centerMap = [];
                    $centerRows = $pdo->query("
                        SELECT id, UPPER(TRIM(center_number)) AS center_number
                        FROM centers
                    ")->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($centerRows as $c) {
                        if (!empty($c['center_number'])) {
                            $centerMap[(string)$c['center_number']] = (int)$c['id'];
                        }
                    }

                    $hasEaHomeRegionId = examinerApplicationsHasHomeRegionId($pdo);
                    $hasEaDistrictId   = examinerApplicationsHasDistrictId($pdo);
                    $hasEaCenterId     = examinerApplicationsHasCenterId($pdo);

                    $stats = ['success' => 0, 'skipped' => 0];
                    $skippedRows = [];
                    $seenPhones = [];

                    $pdo->beginTransaction();
                    try {
                        foreach ($xml->sheetData->row as $row) {
                            if ((int)$row['r'] === 1) continue; // header row

                            $cells = [];
                            foreach ($row->c as $c) {
                                $v = (string)$c->v;
                                $cells[] = ((string)$c['t'] === 's') ? ($sharedStrings[(int)$v] ?? '') : $v;
                            }

                            // Your Excel format:
                            // A Full Name, B Phone Number, C occupation, D District, E center
                            $name         = trim($cells[0] ?? '');
                            $phone        = preg_replace('/\s+/', '', trim($cells[1] ?? ''));
                            $occText      = trim((string)($cells[2] ?? ''));
                            $occKey       = strtolower(trim((string)($cells[2] ?? '')));
                            $districtName = strtolower(trim($cells[3] ?? ''));
                            $centerNumber = strtoupper(trim($cells[4] ?? ''));
                            $centerNumber = preg_replace('/\s+/', '', $centerNumber);

                            // Required fields
                            if ($name === '' || $phone === '' || $districtName === '' || $centerNumber === '') {
                                $stats['skipped']++;
                                $skippedRows[] = [$name, $phone, $occText, $districtName, $centerNumber, 'Missing required fields'];
                                continue;
                            }

                            // Duplicate phone inside the same Excel upload
                            if (isset($seenPhones[$phone])) {
                                $stats['skipped']++;
                                $skippedRows[] = [$name, $phone, $occText, $districtName, $centerNumber, 'Duplicate phone in Excel'];
                                continue;
                            }
                            $seenPhones[$phone] = true;

                            // District must exist (derive district_id + region_id)
                            $districtId = 0;
                            $regionId   = 0;
                            if (isset($districtMap[$districtName])) {
                                $districtId = (int)$districtMap[$districtName]['id'];
                                $regionId   = (int)$districtMap[$districtName]['region_id'];
                            }
                            if ($districtId <= 0 || $regionId <= 0) {
                                $stats['skipped']++;
                                $skippedRows[] = [$name, $phone, $occText, $districtName, $centerNumber, 'District not found in districts table'];
                                continue;
                            }

                            // Center must exist
                            $centerId = $centerMap[$centerNumber] ?? 0;
                            if ($centerId <= 0) {
                                $stats['skipped']++;
                                $skippedRows[] = [$name, $phone, $occText, $districtName, $centerNumber, 'Center not found in centers table'];
                                continue;
                            }

                            // Occupation id (optional)
                            $targetOccId = ($occKey !== '' && isset($occMap[$occKey])) ? (int)$occMap[$occKey] : null;

                            /* ---------------- UPSERT USERS BY PHONE (REPLACE) ---------------- */
                            $checkUser = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
                            $checkUser->execute([$phone]);
                            $existingUserId = (int)($checkUser->fetchColumn() ?: 0);

                            // safer if users.email is UNIQUE
                            $email = makeImportEmail($phone);

                            if ($existingUserId > 0) {
                                // Update existing user (keep phone for login)
                                $pdo->prepare("
                                    UPDATE users
                                    SET full_name = ?,
                                        email = ?,
                                        region_id = ?,
                                        role = 'examiner',
                                        status = 'active',
                                        is_imported = 1,
                                        imported_at = NOW()
                                    WHERE id = ?
                                    LIMIT 1
                                ")->execute([$name, $email, $regionId, $existingUserId]);

                                $newUserId = $existingUserId;
                            } else {
                                // Create user (phone will be used for login)
                                $pdo->prepare("
                                    INSERT INTO users (full_name, email, phone, region_id, password_hash, role, status, is_imported, imported_at)
                                    VALUES (?, ?, ?, ?, ?, 'examiner', 'active', 1, NOW())
                                ")->execute([$name, $email, $phone, $regionId, $defaultPasswordHash]);

                                $newUserId = (int)$pdo->lastInsertId();
                            }

                            /* ---------------- UPSERT APPLICATION BY PHONE (REPLACE) ---------------- */
                            $checkApp = $pdo->prepare("SELECT id FROM examiner_applications WHERE phone = ? ORDER BY id DESC LIMIT 1");
                            $checkApp->execute([$phone]);
                            $existingAppId = (int)($checkApp->fetchColumn() ?: 0);

                            if ($existingAppId > 0) {
                                // Replace existing application (latest)
                                // Uses home_region_id (matches your table)
                                if ($hasEaHomeRegionId && $hasEaDistrictId && $hasEaCenterId) {
                                    $pdo->prepare("
                                        UPDATE examiner_applications
                                        SET user_id = ?,
                                            full_name = ?,
                                            email = ?,
                                            occupation = ?,
                                            occupation_id = ?,
                                            home_region_id = ?,
                                            district_id = ?,
                                            center_id = ?,
                                            status = 'pending',
                                            rejection_reason = NULL,
                                            reviewed_by = NULL,
                                            reviewed_at = NULL
                                        WHERE id = ?
                                        LIMIT 1
                                    ")->execute([
                                        $newUserId,
                                        $name,
                                        $email,
                                        $occText !== '' ? $occText : null,
                                        $targetOccId,
                                        $regionId,
                                        $districtId,
                                        $centerId,
                                        $existingAppId
                                    ]);
                                } else {
                                    // Fallback if columns missing
                                    $pdo->prepare("
                                        UPDATE examiner_applications
                                        SET user_id = ?,
                                            full_name = ?,
                                            email = ?,
                                            occupation = ?,
                                            occupation_id = ?,
                                            status = 'pending',
                                            rejection_reason = NULL,
                                            reviewed_by = NULL,
                                            reviewed_at = NULL
                                        WHERE id = ?
                                        LIMIT 1
                                    ")->execute([
                                        $newUserId,
                                        $name,
                                        $email,
                                        $occText !== '' ? $occText : null,
                                        $targetOccId,
                                        $existingAppId
                                    ]);
                                }
                            } else {
                                // Insert new application
                                if ($hasEaHomeRegionId && $hasEaDistrictId && $hasEaCenterId) {
                                    $pdo->prepare("
                                        INSERT INTO examiner_applications
                                            (user_id, full_name, phone, email, occupation, occupation_id, home_region_id, district_id, center_id, status)
                                        VALUES
                                            (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                                    ")->execute([
                                        $newUserId,
                                        $name,
                                        $phone,
                                        $email,
                                        $occText !== '' ? $occText : null,
                                        $targetOccId,
                                        $regionId,
                                        $districtId,
                                        $centerId
                                    ]);
                                } else {
                                    // Fallback if columns missing
                                    $pdo->prepare("
                                        INSERT INTO examiner_applications
                                            (user_id, full_name, phone, email, occupation, occupation_id, status)
                                        VALUES
                                            (?, ?, ?, ?, ?, ?, 'pending')
                                    ")->execute([
                                        $newUserId,
                                        $name,
                                        $phone,
                                        $email,
                                        $occText !== '' ? $occText : null,
                                        $targetOccId
                                    ]);
                                }
                            }

                            // Ensure user_id linkage is correct
                            linkApplicationToUserByPhone($pdo, $phone, $newUserId);

                            $stats['success']++;
                        }

                        // Create skip report CSV (stored in session for download)
                        if (!empty($skippedRows)) {
                            $reportDir = __DIR__ . '/../../storage/import_reports';
                            if (!is_dir($reportDir)) {
                                @mkdir($reportDir, 0775, true);
                            }

                            $reportFile = 'examiner_import_skips_' . date('Y-m-d_His') . '.csv';
                            $reportPath = $reportDir . '/' . $reportFile;

                            $fp = fopen($reportPath, 'w');
                            fwrite($fp, "\xEF\xBB\xBF");
                            fputcsv($fp, ['Full Name', 'Phone Number', 'Occupation', 'District', 'Center', 'Reason']);
                            foreach ($skippedRows as $sr) fputcsv($fp, $sr);
                            fclose($fp);

                            $_SESSION['last_import_skip_report_path'] = $reportPath;
                            $_SESSION['last_import_skip_report_name'] = $reportFile;
                        } else {
                            unset($_SESSION['last_import_skip_report_path'], $_SESSION['last_import_skip_report_name']);
                        }

                        $pdo->commit();

                        $extra = !empty($_SESSION['last_import_skip_report_name'])
                            ? " Skip report: {$_SESSION['last_import_skip_report_name']}."
                            : "";

                        $msg = "Import Completed! Success: {$stats['success']}, Skipped: {$stats['skipped']}. Login Username=Phone, Default password: {$defaultPasswordPlain}." . $extra;
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        $msg = "Import failed: " . $e->getMessage();
                    }
                } else {
                    $msg = "Import failed: sheet1.xml not found in XLSX.";
                }
                $zip->close();
            } else {
                $msg = "Import failed: Could not open XLSX file.";
            }
        }
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?msg=" . urlencode((string)$msg));
        exit;
    }

    /* ---------- BULK APPROVE (robust) ---------- */
    if ($action === 'bulk_approve') {

        $st = $pdo->query("SELECT id, phone, user_id FROM examiner_applications WHERE LOWER(TRIM(status)) = 'pending'");
        $pendingRows = $st->fetchAll(PDO::FETCH_ASSOC);

        if ($pendingRows) {
            $pdo->beginTransaction();
            try {
                $pdo->query("UPDATE examiner_applications SET status = 'approved' WHERE LOWER(TRIM(status)) = 'pending'");

                $phones = array_values(array_unique(array_filter(array_map(fn($r) => (string)($r['phone'] ?? ''), $pendingRows))));
                if ($phones) {
                    $placeholders = implode(',', array_fill(0, count($phones), '?'));
                    $pdo->prepare("UPDATE users SET status='active', role='examiner' WHERE phone IN ($placeholders)")
                        ->execute($phones);
                }

                foreach ($pendingRows as $r) {
                    $phone = (string)($r['phone'] ?? '');
                    $uid   = (int)($r['user_id'] ?? 0);

                    if ($uid <= 0 && $phone !== '') {
                        $u = $pdo->prepare("SELECT id FROM users WHERE phone=? LIMIT 1");
                        $u->execute([$phone]);
                        $uid = (int)($u->fetchColumn() ?: 0);
                    }
                    if ($uid > 0) linkApplicationToUserByPhone($pdo, $phone, $uid);
                }

                $pdo->commit();
                $msg = count($pendingRows) . " examiners approved in bulk.";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $msg = "Bulk approve failed: " . $e->getMessage();
            }
        } else {
            $msg = "No pending examiners found.";
        }

        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?msg=" . urlencode((string)$msg));
        exit;
    }

    /* ---------- APPROVE / BLOCK / DELETE SINGLE ---------- */
    $targetId = (int)($_POST['id'] ?? 0);

    if ($targetId > 0 && in_array($action, ['approve', 'block', 'delete'], true)) {

        if ($action === 'approve') {
            $pdo->prepare("UPDATE examiner_applications SET status = 'approved' WHERE id = ?")->execute([$targetId]);

            $st = $pdo->prepare("SELECT phone, user_id FROM examiner_applications WHERE id=? LIMIT 1");
            $st->execute([$targetId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $phone = (string)($row['phone'] ?? '');
            $uid = (int)($row['user_id'] ?? 0);

            if ($uid <= 0 && $phone !== '') {
                $u = $pdo->prepare("SELECT id FROM users WHERE phone=? LIMIT 1");
                $u->execute([$phone]);
                $uid = (int)($u->fetchColumn() ?: 0);
                if ($uid > 0) linkApplicationToUserByPhone($pdo, $phone, $uid);
            }

            if ($phone !== '') {
                $pdo->prepare("UPDATE users SET status='active', role='examiner' WHERE phone=? LIMIT 1")->execute([$phone]);
            }
            $msg = "Examiner approved.";
        }

        if ($action === 'block') {
            $pdo->prepare("UPDATE examiner_applications SET status = 'rejected' WHERE id = ?")->execute([$targetId]);

            $st = $pdo->prepare("SELECT phone, user_id FROM examiner_applications WHERE id=? LIMIT 1");
            $st->execute([$targetId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $phone = (string)($row['phone'] ?? '');
            $uid = (int)($row['user_id'] ?? 0);

            if ($uid <= 0 && $phone !== '') {
                $u = $pdo->prepare("SELECT id FROM users WHERE phone=? LIMIT 1");
                $u->execute([$phone]);
                $uid = (int)($u->fetchColumn() ?: 0);
                if ($uid > 0) linkApplicationToUserByPhone($pdo, $phone, $uid);
            }

            if ($phone !== '') {
                $pdo->prepare("UPDATE users SET status='blocked' WHERE phone=? LIMIT 1")->execute([$phone]);
            }
            $msg = "Examiner blocked (application set to rejected).";
        }

        if ($action === 'delete') {
            $st = $pdo->prepare("SELECT phone FROM examiner_applications WHERE id=? LIMIT 1");
            $st->execute([$targetId]);
            $phone = (string)$st->fetchColumn();

            $pdo->prepare("DELETE FROM examiner_applications WHERE id = ?")->execute([$targetId]);

            if ($phone !== '') {
                $pdo->prepare("DELETE FROM users WHERE phone=? AND is_imported=1 LIMIT 1")->execute([$phone]);
            }
            $msg = "Record deleted.";
        }

        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?msg=" . urlencode((string)$msg));
        exit;
    }
}

/* ---------------- MESSAGE FROM REDIRECT ---------------- */
if (isset($_GET['msg'])) {
    $msg = (string)$_GET['msg'];
}

/* ---------------- DATA FETCHING ---------------- */
$totalPending  = (int)$pdo->query("SELECT COUNT(*) FROM examiner_applications WHERE LOWER(TRIM(status)) = 'pending'")->fetchColumn();
$totalApproved = (int)$pdo->query("SELECT COUNT(*) FROM examiner_applications WHERE LOWER(TRIM(status)) = 'approved'")->fetchColumn();

$search = (string)($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 12;
$offset = ($page - 1) * $limit;

$st = $pdo->prepare("
    SELECT
        ea.*,
        o.name AS occ_name,
        d.name AS district_name,

        COALESCE(c1.center_number, c2.center_number, c3.center_number) AS center_number,
        COALESCE(c1.center_name,   c2.center_name,   c3.center_name)   AS center_name,
        COALESCE(c1.location_name, c2.location_name, c3.location_name) AS center_location

    FROM examiner_applications ea
    LEFT JOIN occupations o ON o.id = ea.occupation_id
    LEFT JOIN districts d ON d.id = ea.district_id

    LEFT JOIN centers c1 ON c1.id = ea.center_id
    LEFT JOIN centers c2 ON c2.center_number = CAST(ea.center_id AS CHAR)
    LEFT JOIN centers c3 ON c3.center_number = CONCAT('UBT', CAST(ea.center_id AS CHAR))

    WHERE ea.full_name LIKE ? OR ea.phone LIKE ?
    ORDER BY ea.id DESC
    LIMIT $limit OFFSET $offset
");
$st->execute(["%$search%", "%$search%"]);
$list = $st->fetchAll(PDO::FETCH_ASSOC);

$countSt = $pdo->prepare("
    SELECT COUNT(*)
    FROM examiner_applications
    WHERE full_name LIKE ? OR phone LIKE ?
");
$countSt->execute(["%$search%", "%$search%"]);
$totalRows  = (int)$countSt->fetchColumn();
$totalPages = (int)ceil($totalRows / $limit);

function pageLink(int $p, string $search): string {
    $qs = http_build_query(['page' => $p, 'search' => $search]);
    return '?' . $qs;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>UVTAB | Admin</title>
    <style>
        :root { --p: #2563eb; --s: #10b981; --a: #f59e0b; --d: #ef4444; --bg: #f8fafc; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: #1e293b; margin: 0; }
        .header { background: #fff; padding: 1rem 3rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1.5rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-box { background: #fff; padding: 1.5rem; border-radius: 1rem; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 1rem; }
        .stat-box b { font-size: 1.8rem; display: block; }
        .stat-box span { font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase; }
        .content-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 1rem; background: #f8fafc; font-size: 0.75rem; color: #64748b; text-transform: uppercase; }
        td { padding: 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .btn { padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; border: 1px solid #e2e8f0; background: #fff; }
        .btn-p { background: var(--p); color: #fff; border: none; }
        .btn-s { background: var(--s); color: #fff; border: none; }
        .btn-d { color: var(--d); }
        .actions { display: inline-flex; gap: 8px; justify-content: flex-end; align-items: center; flex-wrap: wrap; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: #fff; padding: 2rem; border-radius: 1rem; width: 560px; max-width: calc(100% - 30px); }
        .searchbar { display:flex; gap:10px; align-items:center; margin-bottom: 14px; }
        .searchbar input { flex:1; padding: 10px 12px; border-radius: 10px; border: 1px solid #e2e8f0; outline:none; }
        .pagination { display:flex; gap:8px; justify-content:flex-end; padding: 14px; background:#fff; border:1px solid #e2e8f0; border-radius: 1rem; margin-top: 14px; }
        .pagination a { padding: 8px 10px; border:1px solid #e2e8f0; border-radius: 10px; text-decoration:none; color:#0f172a; font-weight:700; font-size: 12px; background:#fff; }
        .pagination a.active { background:#dbeafe; border-color:#93c5fd; }
        .pill { padding:4px 8px; border-radius:12px; font-size:10px; font-weight:800; background:#f1f5f9; display:inline-block; }
        .muted { color:#64748b; font-size: 12px; }
        button:disabled { opacity: .6; cursor: not-allowed; }
    </style>
</head>
<body>

<header class="header">
    <div style="font-weight:800; color:var(--p);">UVTAB ADMIN</div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">

        <form method="POST" style="margin:0;" onsubmit="return confirm('Approve ALL pending examiners?');">
            <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="bulk_approve">
            <button type="submit" class="btn btn-s" <?= ($totalPending <= 0 ? 'disabled' : '') ?>>
                ✅ Bulk Approve (<?= (int)$totalPending ?>)
            </button>
        </form>

        <form method="POST" style="margin:0;">
            <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="export_non_imported_examiners">
            <button type="submit" class="btn">⬇️ Export Applicants (Not Imported)</button>
        </form>

        <?php if (!empty($_SESSION['last_import_skip_report_name'])): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="download_skip_report">
                <button type="submit" class="btn">⬇️ Download Skip Report</button>
            </form>
        <?php endif; ?>

        <button onclick="document.getElementById('importModal').style.display='flex'" class="btn btn-p">+ Import Excel</button>
        <a href="dashboard.php" class="btn">← Back to Dashboard</a>
        <a href="../logout.php" class="btn">Logout</a>
    </div>
</header>

<div class="container">
    <div class="stats-grid">
        <div class="stat-box"><b><?= (int)$totalApproved ?></b><span>Approved</span></div>
        <div class="stat-box"><b><?= (int)$totalPending ?></b><span>Pending</span></div>
    </div>

    <?php if($msg): ?>
        <div style="background:#dcfce7; padding:1rem; border-radius:1rem; margin-bottom:1rem; color:#166534; border-left: 5px solid var(--s);">
            <b>Notice:</b> <?= h($msg) ?>
        </div>
    <?php endif; ?>

    <div class="searchbar">
        <form method="GET" style="display:flex; gap:10px; width:100%;">
            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search by name or phone...">
            <button class="btn btn-p" type="submit">Search</button>
        </form>
    </div>

    <div class="content-card">
        <table>
            <thead>
                <tr>
                    <th>Examiner</th>
                    <th>Occupation</th>
                    <th>District</th>
                    <th>Center / World of Work</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$list): ?>
                    <tr><td colspan="6" style="text-align:center; padding:20px; color:#64748b;">No records found.</td></tr>
                <?php endif; ?>

                <?php foreach($list as $r): ?>
                <tr>
                    <td>
                        <b><?= h((string)$r['full_name']) ?></b><br>
                        <small><?= h((string)$r['phone']) ?></small>
                        <?php if (!empty($r['email'])): ?><br><small class="muted"><?= h((string)$r['email']) ?></small><?php endif; ?>
                    </td>
                    <td><?= h((string)($r['occ_name'] ?: ($r['occupation'] ?? 'General'))) ?></td>
                    <td><?= h((string)($r['district_name'] ?? '')) ?></td>

                    <td>
                        <?php
                            $cnum = trim((string)($r['center_number'] ?? ''));
                            $cname = trim((string)($r['center_name'] ?? ''));
                            $cloc = trim((string)($r['center_location'] ?? ''));
                            $org = trim((string)($r['organisation_name'] ?? ''));

                            if ($cnum !== '') {
                                $label = $cname !== '' ? $cname : $cnum;
                                if ($cloc !== '') $label .= " - " . $cloc;
                                echo h($label);
                            } else {
                                if ($org !== '') {
                                    echo h("World of Work - " . $org);
                                } else {
                                    echo "<span class='muted'>World of Work</span>";
                                }
                            }
                        ?>
                    </td>

                    <td><span class="pill"><?= strtoupper(h((string)$r['status'])) ?></span></td>
                    <td style="text-align:right;">
                        <div class="actions">
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-s">Approve</button>
                            </form>

                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="block">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn">Block</button>
                            </form>

                            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this record?');">
                                <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-d">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($p=1; $p <= $totalPages; $p++): ?>
                <a class="<?= ($p === $page) ? 'active' : '' ?>" href="<?= h(pageLink($p, $search)) ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<div id="importModal" class="modal" onclick="if(event.target.id==='importModal') this.style.display='none'">
    <div class="modal-content">
        <h3 style="margin-top:0;">Import Excel (.xlsx)</h3>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="import">
            <input type="file" name="excel_file" accept=".xlsx" required style="margin-bottom:1rem; width:100%;">
            <button type="submit" class="btn btn-p" style="width:100%;">Upload</button>
            <button type="button" onclick="document.getElementById('importModal').style.display='none'" class="btn" style="width:100%; margin-top:10px;">Cancel</button>

            <div style="margin-top:12px; font-size:12px; color:#64748b;">
                Login Username for imported users: <b>Phone Number</b><br>
                Default password for imported users: <b><?= h($defaultPasswordPlain) ?></b>
            </div>
        </form>
    </div>
</div>

</body>
</html>
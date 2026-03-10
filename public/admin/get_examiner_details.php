<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';

require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function hasColumn(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . ':' . $column;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$column]);
        $cache[$key] = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function pickFirstExisting(PDO $pdo, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        if (hasColumn($pdo, $table, $col)) return $col;
    }
    return null;
}

function normalizeUrl(?string $path): ?string {
    $path = trim((string)$path);
    if ($path === '') return null;
    if (preg_match('~^https?://~i', $path)) return $path;

    // ../uploads/... => /uploads/...
    $path = preg_replace('~^\.\./~', '/', $path);
    if ($path !== '' && $path[0] !== '/') $path = '/' . $path;
    return $path;
}

try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid id']);
        exit;
    }

    // Optional columns in examiner_applications
    $eaDistrictCol = pickFirstExisting($pdo, 'examiner_applications', ['district','home_district','district_name']);
    $eaQualCol     = pickFirstExisting($pdo, 'examiner_applications', ['qualification_file','academic_qualification','qualification_path','qualification_pdf']);

    // Optional columns in users
    $userPhotoCol  = pickFirstExisting($pdo, 'users', ['photo','profile_photo','image','passport_photo','photo_path']);
    $usersHasRegionId = hasColumn($pdo, 'users', 'region_id');

    // Regions table
    $regionsExists = true;
    try {
        $t = $pdo->prepare("SHOW TABLES LIKE 'regions'");
        $t->execute();
        $regionsExists = (bool)$t->fetchColumn();
    } catch (Throwable $e) {
        $regionsExists = false;
    }

    $regionsHasName = $regionsExists && hasColumn($pdo, 'regions', 'name');

    $selectDistrict = $eaDistrictCol ? "ea.`$eaDistrictCol` AS district" : "NULL AS district";
    $selectQual     = $eaQualCol ? "ea.`$eaQualCol` AS qualification_file" : "NULL AS qualification_file";
    $selectPhoto    = $userPhotoCol ? "u.`$userPhotoCol` AS photo" : "NULL AS photo";

    // ✅ region_id comes ONLY from users
    $selectRegionId = $usersHasRegionId ? "u.region_id AS region_id" : "NULL AS region_id";
    $joinRegion     = $regionsHasName ? "LEFT JOIN regions r ON r.id = u.region_id" : "";
    $selectRegionName = $regionsHasName ? "r.name AS region_name" : "NULL AS region_name";

    $sql = "
        SELECT
            ea.id,
            ea.full_name,
            ea.phone,
            ea.user_id,
            ea.status AS app_status,
            o.name AS occupation,

            $selectDistrict,
            $selectQual,

            u.id AS u_id,
            u.email AS u_email,
            u.role AS u_role,
            u.status AS u_status,
            u.is_imported AS u_is_imported,
            u.imported_at AS u_imported_at,
            $selectPhoto,
            $selectRegionId,
            $selectRegionName
        FROM examiner_applications ea
        LEFT JOIN users u ON u.id = ea.user_id
        $joinRegion
        LEFT JOIN occupations o ON o.id = ea.occupation_id
        WHERE ea.id = ?
        LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Examiner application not found']);
        exit;
    }

    // Fallback if user_id is not linked: locate user by phone
    if (empty($row['u_id']) && !empty($row['phone'])) {
        $cols = "id, email, role, status, is_imported, imported_at";
        if ($userPhotoCol) $cols .= ", `$userPhotoCol` AS photo";
        if ($usersHasRegionId) $cols .= ", region_id";
        $u = $pdo->prepare("SELECT $cols FROM users WHERE phone=? LIMIT 1");
        $u->execute([(string)$row['phone']]);
        $urow = $u->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($urow) {
            $row['u_id'] = $urow['id'];
            $row['u_email'] = $urow['email'] ?? '';
            $row['u_role'] = $urow['role'] ?? '';
            $row['u_status'] = $urow['status'] ?? '';
            $row['u_is_imported'] = $urow['is_imported'] ?? 0;
            $row['u_imported_at'] = $urow['imported_at'] ?? '';
            $row['photo'] = $urow['photo'] ?? null;
            if ($usersHasRegionId && isset($urow['region_id'])) $row['region_id'] = $urow['region_id'];

            // if regions join was not possible above, fetch name manually
            if ($regionsHasName && !empty($row['region_id']) && empty($row['region_name'])) {
                $rr = $pdo->prepare("SELECT name FROM regions WHERE id=? LIMIT 1");
                $rr->execute([(int)$row['region_id']]);
                $row['region_name'] = (string)($rr->fetchColumn() ?: '');
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'app_id'      => (int)$row['id'],
            'full_name'   => (string)($row['full_name'] ?? ''),
            'phone'       => (string)($row['phone'] ?? ''),
            'occupation'  => (string)($row['occupation'] ?? 'General'),
            'app_status'  => (string)($row['app_status'] ?? ''),

            'district'    => $row['district'] !== null ? (string)$row['district'] : null,

            'region_id'   => ($row['region_id'] !== null && $row['region_id'] !== '') ? (int)$row['region_id'] : null,
            'region_name' => ($row['region_name'] !== null && $row['region_name'] !== '') ? (string)$row['region_name'] : null,

            'photo_url'         => normalizeUrl((string)($row['photo'] ?? '')),
            'qualification_url' => normalizeUrl((string)($row['qualification_file'] ?? '')),

            'user_id'     => !empty($row['u_id']) ? (int)$row['u_id'] : null,
            'email'       => (string)($row['u_email'] ?? ''),
            'role'        => (string)($row['u_role'] ?? ''),
            'user_status' => (string)($row['u_status'] ?? ''),
            'is_imported' => (int)($row['u_is_imported'] ?? 0),
            'imported_at' => (string)($row['u_imported_at'] ?? ''),
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
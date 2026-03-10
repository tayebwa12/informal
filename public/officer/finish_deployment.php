<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
$officerId = (int)($me['id'] ?? 0);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Method Not Allowed");
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    die("Invalid CSRF token");
}

$sessionId = (int)($_POST['session_id'] ?? 0);
if ($sessionId <= 0) { die("Invalid Session ID"); }

try {
    $pdo->beginTransaction();

    /* fetch session + region + requirement */
    $st = $pdo->prepare("
        SELECT ts.id, ts.candidate_count, ts.deployment_finished,
               dist.region_id,
               COALESCE(cr.candidates_per_examiner, 20) AS ratio
        FROM timetable_sessions ts
        INNER JOIN centers c ON c.id = ts.center_id
        LEFT JOIN districts dist ON dist.id = c.district_id
        LEFT JOIN candidate_ratios cr ON cr.occupation_id = ts.occupation_id
        WHERE ts.id = ?
        LIMIT 1
    ");
    $st->execute([$sessionId]);
    $sess = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sess) { $pdo->rollBack(); die("Session not found"); }

    if ((int)$sess['deployment_finished'] === 1) {
        $pdo->commit();
        header("Location: deploy_session.php?session_id=" . $sessionId);
        exit;
    }

    $regionId = (int)($sess['region_id'] ?? 0);
    $ratio    = (int)($sess['ratio'] ?? 20);
    $candCnt  = (int)($sess['candidate_count'] ?? 0);
    $required = (int)ceil($candCnt / max($ratio, 1));

    /* officer region check */
    $st = $pdo->prepare("
        SELECT region_id
        FROM officer_assignments
        WHERE user_id=? AND status='active'
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$officerId]);
    $officerRegionId = (int)($st->fetchColumn() ?: 0);

    if ($officerRegionId <= 0 || ($regionId > 0 && $officerRegionId !== $regionId)) {
        $pdo->rollBack();
        die("Access denied: Regional assignment mismatch.");
    }

    /* ✅ deployed count must meet required (count ACTIVE only) */
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM deployments
        WHERE timetable_session_id = ?
          AND status <> 'cancelled'
          AND COALESCE(response_status,'') <> 'cancelled'
          AND completed_at IS NULL
    ");
    $st->execute([$sessionId]);
    $deployed = (int)$st->fetchColumn();

    if ($deployed < $required) {
        $pdo->rollBack();
        die("You must deploy at least {$required} examiner(s) before finishing. Currently deployed: {$deployed}.");
    }

    /* mark finished */
    $st = $pdo->prepare("
        UPDATE timetable_sessions
        SET deployment_finished=1,
            deployment_finished_by=?,
            deployment_finished_at=NOW()
        WHERE id=?
        LIMIT 1
    ");
    $st->execute([$officerId, $sessionId]);

    $pdo->commit();
    header("Location: deploy_session.php?session_id=" . $sessionId . "&finished=1");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    die("Finish failed: " . $e->getMessage());
}
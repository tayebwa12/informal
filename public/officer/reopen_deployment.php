<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
$officerId = (int)($me['id'] ?? 0);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function redirectBack(string $fallback): void {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref !== '') { header("Location: " . $ref); exit; }
    header("Location: " . $fallback); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('danger', 'Invalid request method.');
    redirectBack('dashboard.php');
}

$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
    flash('danger', 'CSRF Failure. Please refresh and try again.');
    redirectBack('dashboard.php');
}

$sessionId = (int)($_POST['session_id'] ?? 0);
if ($sessionId <= 0) {
    flash('danger', 'Invalid session.');
    redirectBack('dashboard.php');
}

try {
    // Officer must belong to same region as session
    $st = $pdo->prepare("
        SELECT dist.region_id
        FROM timetable_sessions ts
        JOIN centers c ON c.id = ts.center_id
        JOIN districts dist ON dist.id = c.district_id
        WHERE ts.id = ?
        LIMIT 1
    ");
    $st->execute([$sessionId]);
    $sessionRegionId = (int)($st->fetchColumn() ?: 0);

    $st = $pdo->prepare("
        SELECT region_id
        FROM officer_assignments
        WHERE user_id=? AND status='active'
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$officerId]);
    $officerRegionId = (int)($st->fetchColumn() ?: 0);

    if ($officerRegionId <= 0 || $sessionRegionId <= 0 || $officerRegionId !== $sessionRegionId) {
        flash('danger', 'Access denied: Regional assignment mismatch.');
        redirectBack("deploy_session.php?session_id=" . $sessionId);
    }

    // Reopen (unfinish)
    $st = $pdo->prepare("
        UPDATE timetable_sessions
        SET deployment_finished = 0,
            deployment_finished_by = NULL,
            deployment_finished_at = NULL
        WHERE id = ?
        LIMIT 1
    ");
    $st->execute([$sessionId]);

    flash('success', 'Deployment reopened. You can now add/remove examiners.');
    redirectBack("deploy_session.php?session_id=" . $sessionId);

} catch (Throwable $e) {
    flash('danger', 'Reopen failed: ' . $e->getMessage());
    redirectBack("deploy_session.php?session_id=" . $sessionId);
}
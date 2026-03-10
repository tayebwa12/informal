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

function redirectBack(string $fallback = 'dashboard.php'): void {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref !== '') { header("Location: " . $ref); exit; }
    header("Location: " . $fallback); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('danger', 'Invalid request method.');
    redirectBack();
}

/* CSRF */
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
    flash('danger', 'CSRF Failure. Please refresh and try again.');
    redirectBack();
}

/* Inputs */
$deployId  = (int)($_POST['cancel_deployment_id'] ?? 0);
$sessionId = (int)($_POST['session_id'] ?? 0);

if ($deployId <= 0 || $sessionId <= 0) {
    flash('danger', 'Invalid cancel request.');
    redirectBack('dashboard.php');
}

try {
    $pdo->beginTransaction();

    // Confirm deployment belongs to session + get examiner + finished
    $st = $pdo->prepare("
        SELECT d.examiner_user_id, ts.deployment_finished
        FROM deployments d
        JOIN timetable_sessions ts ON ts.id = d.timetable_session_id
        WHERE d.id = ? AND d.timetable_session_id = ?
        LIMIT 1
    ");
    $st->execute([$deployId, $sessionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        flash('danger', 'Deployment record not found.');
        redirectBack("deploy_session.php?session_id=" . $sessionId);
    }

    $examinerUserId = (int)($row['examiner_user_id'] ?? 0);
    $finished = (int)($row['deployment_finished'] ?? 0);

    if ($finished === 1) {
        $pdo->rollBack();
        flash('danger', 'Deployment already finished for this session. You cannot remove examiners.');
        redirectBack("deploy_session.php?session_id=" . $sessionId);
    }

    // Cancel + mark completed_at so ACTIVE filters stop seeing it
    $st = $pdo->prepare("
        UPDATE deployments
        SET status='cancelled',
            response_status='cancelled',
            completed_at=NOW(),
            updated_at=NOW()
        WHERE timetable_session_id = ?
          AND examiner_user_id = ?
          AND status <> 'cancelled'
    ");
    $st->execute([$sessionId, $examinerUserId]);

    $pdo->commit();

    flash('success', 'Examiner removed successfully.');
    redirectBack("deploy_session.php?session_id=" . $sessionId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('danger', 'Cancel failed: ' . $e->getMessage());
    redirectBack("deploy_session.php?session_id=" . $sessionId);
}
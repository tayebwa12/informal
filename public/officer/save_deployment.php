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
    if ($ref) { header("Location: " . $ref); exit; }
    header("Location: " . $fallback); exit;
}

/** Always require approved examiner (latest application must be approved) */
$REQUIRE_APPROVED_EXAMINER = true;

/** If TRUE: prevent deploying multiple examiners to same session */
$ONE_EXAMINER_PER_SESSION = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('danger', 'Invalid request method.');
    redirectBack();
}

/* ---------------- CSRF ---------------- */
$csrf = (string)($_POST['csrf_token'] ?? '');
$sess = (string)($_SESSION['csrf_token'] ?? '');
if ($csrf === '' || $sess === '' || !hash_equals($sess, $csrf)) {
    flash('danger', 'CSRF verification failed.');
    redirectBack();
}

/* ---------------- Inputs ---------------- */
$sessionId   = (int)($_POST['session_id'] ?? 0);
$examinerId  = (int)($_POST['examiner_user_id'] ?? 0);
$allRegions  = (int)($_POST['all_regions'] ?? 0); // 0=region-only, 1=fallback allow other regions
$similar     = (int)($_POST['similar'] ?? 0);     // 0=strict occupation, 1=ALL occupations (same region only)

if ($sessionId <= 0 || $examinerId <= 0) {
    flash('danger', 'Missing session or examiner.');
    redirectBack();
}

/* ---------------- Officer assignment (region) ---------------- */
$st = $pdo->prepare("
    SELECT oa.region_id
    FROM officer_assignments oa
    WHERE oa.user_id = ? AND oa.status='active'
    ORDER BY oa.id DESC
    LIMIT 1
");
$st->execute([$officerId]);
$officerRegionId = (int)($st->fetchColumn() ?: 0);

if ($officerRegionId <= 0) {
    flash('danger', 'No active officer assignment found. Contact admin.');
    redirectBack();
}

try {
    $pdo->beginTransaction();

    /* ---------------- Get session context ---------------- */
    $st = $pdo->prepare("
        SELECT
            ts.id,
            ts.occupation_id,
            ts.candidate_count,
            ts.session_date,
            ts.start_time,
            ts.exam_series,
            ts.deployment_finished,
            COALESCE(ts.status,'active') AS status,
            d.region_id,
            COALESCE(cr.candidates_per_examiner, 20) AS ratio
        FROM timetable_sessions ts
        JOIN centers c ON c.id = ts.center_id
        JOIN districts d ON d.id = c.district_id
        LEFT JOIN candidate_ratios cr ON cr.occupation_id = ts.occupation_id
        WHERE ts.id = ?
        LIMIT 1
    ");
    $st->execute([$sessionId]);
    $sessRow = $st->fetch(PDO::FETCH_ASSOC);

    if (!$sessRow) {
        $pdo->rollBack();
        flash('danger', 'Timetable session not found.');
        redirectBack();
    }

    $sessionRegionId = (int)($sessRow['region_id'] ?? 0);
    $sessionOccId    = (int)($sessRow['occupation_id'] ?? 0);
    $sessionDate     = (string)($sessRow['session_date'] ?? '');
    $sessionStart    = (string)($sessRow['start_time'] ?? '');
    $sessionSeries   = (string)($sessRow['exam_series'] ?? '');
    $sessionStatus   = (string)($sessRow['status'] ?? 'active');
    $finished        = (int)($sessRow['deployment_finished'] ?? 0);

    $candCnt         = (int)($sessRow['candidate_count'] ?? 0);
    $ratio           = (int)($sessRow['ratio'] ?? 20);
    $requiredCount   = (int)ceil($candCnt / max($ratio, 1));

    if ($sessionStatus !== 'active') {
        $pdo->rollBack();
        flash('danger', 'This timetable session is inactive.');
        redirectBack();
    }

    if ($finished === 1) {
        $pdo->rollBack();
        flash('danger', 'Deployment already finished for this session. You cannot add/remove examiners.');
        redirectBack();
    }

    /* Officer can only deploy for sessions in their region */
    if ($sessionRegionId <= 0 || $sessionRegionId !== $officerRegionId) {
        $pdo->rollBack();
        flash('danger', 'This timetable session is not in your region.');
        redirectBack();
    }

    if ($sessionOccId <= 0) {
        $pdo->rollBack();
        flash('danger', 'This session has no occupation set. Please fix timetable session.');
        redirectBack();
    }

    /* ✅ ACTIVE deployed count in this session => decide if still short */
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM deployments
        WHERE timetable_session_id = ?
          AND status <> 'cancelled'
          AND COALESCE(response_status,'') <> 'cancelled'
          AND completed_at IS NULL
    ");
    $st->execute([$sessionId]);
    $deployedCount = (int)$st->fetchColumn();
    $stillShort = ($deployedCount < $requiredCount);

    /* ---------------- Fetch examiner ---------------- */
    $st = $pdo->prepare("
        SELECT id, phone, status, region_id
        FROM users
        WHERE id = ?
          AND role = 'examiner'
        LIMIT 1
    ");
    $st->execute([$examinerId]);
    $examiner = $st->fetch(PDO::FETCH_ASSOC);

    if (!$examiner) {
        $pdo->rollBack();
        flash('danger', 'Examiner not found.');
        redirectBack();
    }

    if (($examiner['status'] ?? '') !== 'active') {
        $pdo->rollBack();
        flash('danger', 'This examiner is not active.');
        redirectBack();
    }

    /* Examiner region rule */
    $examinerRegionId = (int)($examiner['region_id'] ?? 0);
    if ($examinerRegionId !== $officerRegionId) {
        if (!($allRegions === 1 && $stillShort)) {
            $pdo->rollBack();
            flash('danger', 'Examiner is not in your region. Cross-region deployment is allowed only when you are still short.');
            redirectBack();
        }
    }

    /* Similar mode must stay in same region */
    if ($similar === 1 && $examinerRegionId !== $officerRegionId) {
        $pdo->rollBack();
        flash('danger', 'Similar mode works only within your region.');
        redirectBack();
    }

    /* ---------------- Require approved (latest application) ---------------- */
    if ($REQUIRE_APPROVED_EXAMINER) {
        $st = $pdo->prepare("
            SELECT status, occupation_id
            FROM examiner_applications
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$examinerId]);
        $app = $st->fetch(PDO::FETCH_ASSOC);

        $appStatus = strtolower((string)($app['status'] ?? ''));

        if ($appStatus !== 'approved') {
            $pdo->rollBack();
            flash('danger', 'Examiner is not approved yet. Please approve first.');
            redirectBack();
        }

        if ($similar !== 1) {
            $appOccId = (int)($app['occupation_id'] ?? 0);
            if ($appOccId !== $sessionOccId) {
                $pdo->rollBack();
                flash('danger', 'This examiner is not approved for this occupation. Choose the correct occupation examiner.');
                redirectBack();
            }
        }
    }

    /* ✅ GLOBAL BLOCKER: examiner cannot be active elsewhere */
    $st = $pdo->prepare("
        SELECT id
        FROM deployments
        WHERE examiner_user_id = ?
          AND status <> 'cancelled'
          AND COALESCE(response_status,'') <> 'cancelled'
          AND completed_at IS NULL
        LIMIT 1
    ");
    $st->execute([$examinerId]);
    if ($st->fetchColumn()) {
        $pdo->rollBack();
        flash('danger', 'This examiner is already deployed elsewhere (active). Please select another examiner.');
        redirectBack();
    }

    /* ✅ Prevent same slot conflicts (active only) */
    $st = $pdo->prepare("
        SELECT d.id
        FROM deployments d
        JOIN timetable_sessions ts2 ON ts2.id = d.timetable_session_id
        WHERE d.examiner_user_id = ?
          AND d.status <> 'cancelled'
          AND COALESCE(d.response_status,'') <> 'cancelled'
          AND d.completed_at IS NULL
          AND ts2.session_date = ?
          AND ts2.start_time = ?
          AND ts2.exam_series = ?
          AND ts2.id <> ?
        LIMIT 1
    ");
    $st->execute([$examinerId, $sessionDate, $sessionStart, $sessionSeries, $sessionId]);
    if ($st->fetchColumn()) {
        $pdo->rollBack();
        flash('danger', 'This examiner is already deployed in another center at the same time slot.');
        redirectBack();
    }

    /* ✅ Because of UNIQUE (timetable_session_id, examiner_user_id), redeploy must REACTIVATE cancelled row */
    $st = $pdo->prepare("
        SELECT id
        FROM deployments
        WHERE timetable_session_id = ?
          AND examiner_user_id = ?
          AND status = 'cancelled'
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$sessionId, $examinerId]);
    $cancelledId = (int)($st->fetchColumn() ?: 0);

    if ($cancelledId > 0) {
        $pdo->prepare("
            UPDATE deployments
            SET status='active',
                response_status='pending',
                deployed_by_user_id=?,
                completed_at=NULL,
                updated_at=NOW()
            WHERE id=?
            LIMIT 1
        ")->execute([$officerId, $cancelledId]);

        $pdo->commit();
        flash('success', 'Examiner redeployed successfully (pending examiner response).');
        redirectBack();
    }

    /* Prevent duplicate active row in same session */
    $st = $pdo->prepare("
        SELECT id
        FROM deployments
        WHERE timetable_session_id = ?
          AND examiner_user_id = ?
          AND status <> 'cancelled'
          AND COALESCE(response_status,'') <> 'cancelled'
          AND completed_at IS NULL
        LIMIT 1
    ");
    $st->execute([$sessionId, $examinerId]);
    if ($st->fetchColumn()) {
        $pdo->rollBack();
        flash('warning', 'This examiner is already deployed to this session.');
        redirectBack();
    }

    /* Optional: only one examiner per session */
    if ($ONE_EXAMINER_PER_SESSION) {
        $st = $pdo->prepare("
            SELECT id
            FROM deployments
            WHERE timetable_session_id = ?
              AND status <> 'cancelled'
              AND COALESCE(response_status,'') <> 'cancelled'
              AND completed_at IS NULL
              AND response_status IN ('pending','accepted','confirmed')
            LIMIT 1
        ");
        $st->execute([$sessionId]);
        if ($st->fetchColumn()) {
            $pdo->rollBack();
            flash('warning', 'This session already has an assigned examiner.');
            redirectBack();
        }
    }

    /* Insert deployment (first time only) */
    $pdo->prepare("
        INSERT INTO deployments (
            timetable_session_id,
            examiner_user_id,
            deployed_by_user_id,
            status,
            response_status,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, 'active', 'pending', NOW(), NOW())
    ")->execute([$sessionId, $examinerId, $officerId]);

    $pdo->commit();
    flash('success', 'Deployment saved successfully (pending examiner response).');
    redirectBack();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('danger', 'Save failed: ' . $e->getMessage());
    redirectBack();
}
<?php
declare(strict_types=1);

// Enable error reporting for debugging
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

// 1. Authenticate Officer
$me = require_officer();
$userId = (int)($me['id'] ?? 0);

// Default fees (as requested)
$DEFAULT_MARKING_FEE = 110000;
$DEFAULT_TRANSPORT   = 0;      // officer can edit later in Excel if needed
$DEFAULT_DAYS        = 1;

try {
    /* ---------------- FIND ASSIGNMENT ---------------- */
    $st = $pdo->prepare("
        SELECT oa.exam_series_id, oa.region_id
        FROM officer_assignments oa
        WHERE oa.user_id = ? AND oa.status = 'active'
        ORDER BY oa.id DESC
        LIMIT 1
    ");
    $st->execute([$userId]);
    $assignment = $st->fetch(PDO::FETCH_ASSOC);

    $regionId = (int)($assignment['region_id'] ?? 0);
    $seriesId = (int)($_GET['series_id'] ?? ($assignment['exam_series_id'] ?? 0));

    if ($regionId === 0 || $seriesId === 0) {
        die("<h2>Export Error</h2><p>Could not determine your assigned region or exam series. Please refresh the dashboard and try again.</p>");
    }

    /* ---------------- FETCH DATA ---------------- */
    $st = $pdo->prepare("
        SELECT
            c.center_number,
            c.center_name,
            ts.session_date,
            ts.start_time,
            o.name AS occupation,
            u.full_name AS examiner_name,
            u.phone AS examiner_phone,
            dep.status AS deployment_status
        FROM deployments dep
        JOIN timetable_sessions ts ON ts.id = dep.timetable_session_id
        JOIN centers c ON c.id = ts.center_id
        JOIN districts d ON d.id = c.district_id
        JOIN users u ON u.id = dep.examiner_user_id
        LEFT JOIN occupations o ON o.id = ts.occupation_id
        WHERE d.region_id = ?
          AND ts.exam_series_id = ?     -- ✅ FIX (use exam_series_id)
          AND dep.status <> 'cancelled'
        ORDER BY ts.session_date ASC, c.center_number ASC
    ");
    $st->execute([$regionId, $seriesId]);
    $data = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$data) {
        die("<script>alert('No deployments found to export for this series.'); window.history.back();</script>");
    }

    /* ---------------- GENERATE CSV ---------------- */
    $filename = "Deployments_Region_" . $regionId . "_Series_" . $seriesId . "_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // ✅ Column Headers (includes the new ones)
    fputcsv($output, [
        'Center No', 'Center Name', 'Date', 'Time', 'Occupation',
        'Examiner Name', 'Phone', 'Status',
        'Days', 'Marking Fee', 'Transport - to&fro', 'Total Amount'
    ]);

    foreach ($data as $row) {
        $days = $DEFAULT_DAYS;

        $markingFee = $DEFAULT_MARKING_FEE;

        // You can later replace this with real transport from DB if you add a column for it
        $transport = $DEFAULT_TRANSPORT;

        $totalAmount = $markingFee + $transport;

        fputcsv($output, [
            $row['center_number'],
            $row['center_name'],
            $row['session_date'],
            $row['start_time'],
            $row['occupation'] ?? '',
            $row['examiner_name'],
            $row['examiner_phone'],
            $row['deployment_status'],

            $days,
            $markingFee,
            $transport,
            $totalAmount
        ]);
    }

    fclose($output);
    exit;

} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
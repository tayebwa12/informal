<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/db.php';

// Deploy sessions happening within next N days
$DAYS_AHEAD = 14;

// OPTIONAL: system user for "deployed_by_user_id" (create one admin user id, or allow NULL)
$SYSTEM_USER_ID = 1;

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Pull sessions + include session region_id
$st = $pdo->prepare("
  SELECT
    ts.id,
    ts.exam_series_id,
    ts.session_date,
    ts.start_time,
    ts.end_time,
    ts.occupation_id,
    ts.center_id,
    ts.candidate_count,
    sd.region_id AS session_region_id,
    COALESCE(cr.candidates_per_examiner, 20) AS ratio
  FROM timetable_sessions ts
  JOIN centers sc   ON sc.id = ts.center_id
  JOIN districts sd ON sd.id = sc.district_id
  LEFT JOIN candidate_ratios cr ON cr.occupation_id = ts.occupation_id
  WHERE ts.session_date >= CURDATE()
    AND ts.session_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
    AND COALESCE(ts.status,'active') = 'active'
");
$st->execute([$DAYS_AHEAD]);
$sessions = $st->fetchAll(PDO::FETCH_ASSOC);

$deployInsert = $pdo->prepare("
  INSERT INTO deployments (timetable_session_id, examiner_user_id, deployed_by_user_id, status)
  VALUES (?, ?, ?, 'active')
");

$countDeployed = $pdo->prepare("
  SELECT COUNT(*) FROM deployments
  WHERE timetable_session_id = ? AND status='active'
");

// Select available examiners for session (SMART RULES)
$pickExaminers = $pdo->prepare("
  SELECT DISTINCT u.id
  FROM users u

  -- must have approved application
  JOIN examiner_applications ea
    ON ea.user_id = u.id
   AND ea.status = 'approved'

  -- qualification via application_id
  JOIN examiner_occupations eo
    ON eo.application_id = ea.id
   AND eo.occupation_id = ?

  -- must have active assignment for the same series
  JOIN examiner_center_assignments eca
    ON eca.user_id = u.id
   AND eca.exam_series_id = ?
   AND eca.is_active = 1

  -- examiner region derived from assigned center
  JOIN centers ec   ON ec.id = eca.center_id
  JOIN districts ed ON ed.id = ec.district_id

  WHERE u.role='examiner'
    AND u.status='active'
    AND ed.region_id = ?

    -- not already deployed to the session
    AND u.id NOT IN (
      SELECT d.examiner_user_id
      FROM deployments d
      WHERE d.timetable_session_id = ? AND d.status='active'
    )

    -- no timetable overlap on same date/time
    AND u.id NOT IN (
      SELECT d2.examiner_user_id
      FROM deployments d2
      JOIN timetable_sessions ts2 ON ts2.id = d2.timetable_session_id
      WHERE d2.status='active'
        AND ts2.session_date = ?
        AND ts2.id <> ?
        AND ts2.start_time < ?
        AND ts2.end_time > ?
    )

  ORDER BY u.id ASC
  LIMIT ?
");

$done = 0;
$skipped = 0;

foreach ($sessions as $ts) {
  $sessionId = (int)$ts['id'];

  $seriesId = (int)($ts['exam_series_id'] ?? 0);
  $occId    = (int)($ts['occupation_id'] ?? 0);
  $regionId = (int)($ts['session_region_id'] ?? 0);

  // If any of these are missing, skip safely
  if ($seriesId <= 0 || $occId <= 0 || $regionId <= 0) {
    $skipped++;
    continue;
  }

  $ratio = max(1, (int)$ts['ratio']);
  $required = (int)ceil(max(0, (int)$ts['candidate_count']) / $ratio);
  if ($required < 1) $required = 1;

  $countDeployed->execute([$sessionId]);
  $deployed = (int)$countDeployed->fetchColumn();

  $need = max(0, $required - $deployed);
  if ($need === 0) { $skipped++; continue; }

  $pickExaminers->execute([
    $occId,                     // eo.occupation_id
    $seriesId,                  // eca.exam_series_id
    $regionId,                  // ed.region_id (examiner region)
    $sessionId,                 // already deployed exclusion
    (string)$ts['session_date'],
    $sessionId,                 // exclude this session in overlap check
    (string)$ts['end_time'],
    (string)$ts['start_time'],
    $need
  ]);

  $picked = $pickExaminers->fetchAll(PDO::FETCH_COLUMN);
  if (!$picked) { $skipped++; continue; }

  $pdo->beginTransaction();
  try {
    foreach ($picked as $examinerId) {
      $deployInsert->execute([$sessionId, (int)$examinerId, $SYSTEM_USER_ID]);
    }
    $pdo->commit();
    $done += count($picked);
  } catch (Throwable $e) {
    $pdo->rollBack();
    $skipped++;
  }
}

echo "AUTO_DEPLOY DONE. deployed={$done}, skipped={$skipped}\n";

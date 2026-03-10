<?php
declare(strict_types=1);

/**
 * Auto-deploy examiners to a timetable session using ratio (default 1:20).
 *
 * NEW RULE (smart):
 * - Session region = timetable_sessions.center_id -> centers.district_id -> districts.region_id
 * - Examiner region = examiner_center_assignments.center_id -> centers.district_id -> districts.region_id
 * - Examiner must have active assignment for SAME exam_series_id as the session.
 *
 * Also enforces:
 * - active examiner users only
 * - occupation qualification via examiner_occupations (through examiner_applications)
 * - not already deployed to the session
 * - avoids time overlaps with other deployments on same date
 */

function auto_deploy_for_session(PDO $pdo, int $sessionId, int $ratio = 20): array
{
  if ($sessionId < 1) return ['ok' => false, 'message' => 'Invalid session id', 'added' => 0];

  if (!$pdo->inTransaction()) $pdo->beginTransaction();

  try {
    // 1) Load session + region + series (LOCKED)
    $st = $pdo->prepare("
      SELECT
        ts.id,
        ts.exam_series_id,
        ts.center_id,
        ts.occupation_id,
        ts.candidate_count,
        ts.session_date,
        ts.start_time,
        ts.end_time,
        sd.region_id AS session_region_id
      FROM timetable_sessions ts
      JOIN centers sc   ON sc.id = ts.center_id
      JOIN districts sd ON sd.id = sc.district_id
      WHERE ts.id = ?
      LIMIT 1
      FOR UPDATE
    ");
    $st->execute([$sessionId]);
    $session = $st->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
      $pdo->rollBack();
      return ['ok' => false, 'message' => 'Session not found', 'added' => 0];
    }

    $seriesId        = (int)($session['exam_series_id'] ?? 0);
    $occupationId    = (int)($session['occupation_id'] ?? 0);
    $sessionRegionId = (int)($session['session_region_id'] ?? 0);

    if ($seriesId <= 0) {
      $pdo->rollBack();
      return ['ok' => false, 'message' => 'Session has no exam_series_id set. Fix timetable_sessions first.', 'added' => 0];
    }
    if ($occupationId <= 0) {
      $pdo->rollBack();
      return ['ok' => false, 'message' => 'Session has no occupation set.', 'added' => 0];
    }
    if ($sessionRegionId <= 0) {
      $pdo->rollBack();
      return ['ok' => false, 'message' => 'Session region could not be resolved (district.region_id is NULL).', 'added' => 0];
    }

    $candidateCount = (int)($session['candidate_count'] ?? 0);
    $needed = (int)ceil(max(0, $candidateCount) / max(1, $ratio));
    if ($needed < 1) $needed = 1;

    // 2) Already deployed (active)
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM deployments
      WHERE timetable_session_id = ?
        AND COALESCE(status,'active') = 'active'
    ");
    $st->execute([$sessionId]);
    $already = (int)$st->fetchColumn();

    $toAdd = $needed - $already;
    if ($toAdd <= 0) {
      $pdo->commit();
      return ['ok' => true, 'message' => "Already enough examiners ({$already}/{$needed}).", 'added' => 0];
    }

    // 3) Pick eligible examiners:
    // - active examiner in users
    // - has APPROVED examiner application (examiner_applications)
    // - qualified for occupation via examiner_occupations(application_id)
    // - has active center assignment for same series via examiner_center_assignments
    // - assigned center region matches session center region
    // - not already deployed to this session
    // - avoid time overlap same date
    $pickSql = "
      SELECT u.id
      FROM users u

      -- must have active center assignment for this series
      JOIN examiner_center_assignments eca
        ON eca.user_id = u.id
       AND eca.exam_series_id = :series_id
       AND eca.is_active = 1

      -- examiner region derived from their assigned center
      JOIN centers ec   ON ec.id = eca.center_id
      JOIN districts ed ON ed.id = ec.district_id

      -- must have an approved examiner application
      JOIN examiner_applications ea
        ON ea.user_id = u.id
       AND ea.status = 'approved'

      -- qualification for occupation comes from eo.application_id -> ea.id
      JOIN examiner_occupations eo
        ON eo.application_id = ea.id
       AND eo.occupation_id = :occupation_id

      WHERE u.role = 'examiner'
        AND COALESCE(u.status,'active') = 'active'
        AND ed.region_id = :session_region_id

        -- not already deployed to this session
        AND NOT EXISTS (
          SELECT 1
          FROM deployments dep
          WHERE dep.timetable_session_id = :session_id
            AND dep.examiner_user_id = u.id
            AND COALESCE(dep.status,'active') = 'active'
        )

        -- avoid overlaps on same date/time with other deployments
        AND NOT EXISTS (
          SELECT 1
          FROM deployments dep2
          JOIN timetable_sessions ts2 ON ts2.id = dep2.timetable_session_id
          WHERE dep2.examiner_user_id = u.id
            AND COALESCE(dep2.status,'active') = 'active'
            AND ts2.session_date = :session_date
            AND ts2.start_time < :end_time
            AND ts2.end_time > :start_time
        )

      GROUP BY u.id
      ORDER BY u.id ASC
      LIMIT :lim
    ";

    $pick = $pdo->prepare($pickSql);
    $pick->bindValue(':series_id', $seriesId, PDO::PARAM_INT);
    $pick->bindValue(':occupation_id', $occupationId, PDO::PARAM_INT);
    $pick->bindValue(':session_region_id', $sessionRegionId, PDO::PARAM_INT);
    $pick->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
    $pick->bindValue(':session_date', (string)$session['session_date'], PDO::PARAM_STR);
    $pick->bindValue(':start_time', (string)$session['start_time'], PDO::PARAM_STR);
    $pick->bindValue(':end_time', (string)$session['end_time'], PDO::PARAM_STR);
    $pick->bindValue(':lim', $toAdd, PDO::PARAM_INT);
    $pick->execute();

    $examinerIds = $pick->fetchAll(PDO::FETCH_COLUMN);

    if (!$examinerIds) {
      $pdo->commit();
      return [
        'ok' => false,
        'message' => "Not enough eligible examiners in this region for this occupation/series. Needed {$toAdd} more.",
        'added' => 0
      ];
    }

    // 4) Insert deployments
    $ins = $pdo->prepare("
      INSERT IGNORE INTO deployments
        (timetable_session_id, examiner_user_id, status, assigned_at)
      VALUES (?, ?, 'active', NOW())
    ");

    $added = 0;
    foreach ($examinerIds as $eid) {
      $ins->execute([$sessionId, (int)$eid]);
      if ($ins->rowCount() > 0) $added++;
    }

    $pdo->commit();
    return [
      'ok' => true,
      'message' => "Auto-deployed {$added} examiner(s) by region (needed {$needed}, already {$already}).",
      'added' => $added
    ];

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return ['ok' => false, 'message' => $e->getMessage(), 'added' => 0];
  }
}

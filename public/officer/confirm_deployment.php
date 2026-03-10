<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
csrf_validate($_POST['csrf'] ?? null);

$deploymentId = (int)($_POST['deployment_id'] ?? 0);
if ($deploymentId <= 0) exit("Invalid");

$st = $pdo->prepare("
  UPDATE deployments d
  JOIN timetable_sessions ts ON ts.id = d.timetable_session_id
  JOIN centers c ON c.id = ts.center_id
  JOIN districts dist ON dist.id = c.district_id
  JOIN officer_regions orr ON orr.region_id = dist.region_id
  SET d.response_status='confirmed',
      d.officer_confirmed_at=NOW()
  WHERE d.id=?
    AND orr.officer_user_id=?
    AND d.response_status='accepted'
");
$st->execute([$deploymentId, (int)$me['id']]);

header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'deployments.php'));
exit;

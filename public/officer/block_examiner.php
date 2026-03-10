<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/middleware/role_officer.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_officer();
csrf_validate($_POST['csrf'] ?? null);

$examinerId = (int)($_POST['examiner_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));

if ($examinerId <= 0) exit("Invalid examiner");

$st = $pdo->prepare("
  INSERT INTO examiner_blocks (examiner_user_id, blocked_by_user_id, reason)
  VALUES (?,?,?)
  ON DUPLICATE KEY UPDATE blocked_by_user_id=VALUES(blocked_by_user_id),
                          reason=VALUES(reason),
                          created_at=NOW()
");
$st->execute([$examinerId, (int)$me['id'], $reason]);

// optional: cancel their active deployments too
$pdo->prepare("
  UPDATE deployments
  SET response_status='cancelled', status='cancelled'
  WHERE examiner_user_id=? AND status='active'
")->execute([$examinerId]);

header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
exit;

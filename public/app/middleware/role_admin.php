<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function require_admin(): array
{
  if (session_status() === PHP_SESSION_NONE) session_start();

  $userId = 0;

  if (isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id'])) {
    $userId = (int)$_SESSION['user']['id'];
  } elseif (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
  } elseif (isset($_SESSION['id'])) {
    $userId = (int)$_SESSION['id'];
  }

  if ($userId <= 0) {
    http_response_code(403);
    die("Forbidden (login required)");
  }

  global $pdo;

  $st = $pdo->prepare("
    SELECT 1
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
      AND r.name = 'admin'
      AND (r.status IS NULL OR r.status='active')
    LIMIT 1
  ");
  $st->execute([$userId]);

  if (!$st->fetchColumn()) {
    http_response_code(403);
    die("Forbidden (admin only)");
  }

  $st = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
  $st->execute([$userId]);
  $me = $st->fetch(PDO::FETCH_ASSOC);

  return $me ?: ['id' => $userId];
}
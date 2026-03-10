<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/app.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params["path"] ?? '/',
    $params["domain"] ?? '',
    (bool)($params["secure"] ?? false),
    (bool)($params["httponly"] ?? true)
  );
}

session_destroy();

/**
 * ✅ Build correct base URL even if app is hosted in /public or a subfolder
 * Example:
 * - domain root: https://informalassessment.com/login.php
 * - inside /public: https://informalassessment.com/public/login.php
 */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? '';

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl   = $scheme . '://' . $host . $scriptDir;

// ✅ If your login is in same folder as logout, this works always:
header('Location: ' . $baseUrl . '/login.php');
exit;
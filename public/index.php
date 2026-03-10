<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/app.php';

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['user']['role'] ?? '';

switch ($role) {
    case 'admin':
        header('Location: admin/dashboard.php');
        break;
    case 'officer':
        header('Location: officer/dashboard.php');
        break;
    case 'examiner':
        header('Location: examiner/dashboard.php');
        break;
    default:
        session_destroy();
        header('Location: login.php');
}
exit;

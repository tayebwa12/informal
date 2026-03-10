<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function require_auth(): array {
    if (empty($_SESSION['user'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    return $_SESSION['user'];
}

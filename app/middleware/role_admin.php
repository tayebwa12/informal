<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function require_admin(): array {
    $u = require_auth();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Forbidden (admin only)');
    }
    return $u;
}

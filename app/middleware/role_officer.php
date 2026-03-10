<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function require_officer(): array {
    $u = require_auth();
    if (($u['role'] ?? '') !== 'officer') {
        http_response_code(403);
        exit('Forbidden (officer only)');
    }
    return $u;
}

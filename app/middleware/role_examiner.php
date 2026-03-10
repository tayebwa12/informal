<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function require_examiner(): array {
    $u = require_auth();
    if (($u['role'] ?? '') !== 'examiner') {
        http_response_code(403);
        exit('Forbidden (examiner only)');
    }
    return $u;
}

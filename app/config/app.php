<?php
declare(strict_types=1);

/**
 * Load environment variables from .env
 */
function env(string $key, $default = null) {
    static $data = null;

    if ($data === null) {
        $data = [];
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $pos = strpos($line, '=');
                if ($pos === false) continue;

                $k = trim(substr($line, 0, $pos));
                $v = trim(substr($line, $pos + 1));
                $data[$k] = trim($v, "\"'");
            }
        }
    }

    return $data[$key] ?? $default;
}

define('APP_NAME', env('APP_NAME', 'Practical Deployment System'));
define('APP_URL', rtrim((string)env('APP_URL', ''), '/'));
define('APP_DEBUG', (bool)((int)env('APP_DEBUG', '0')));

/**
 * Start session securely
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

/**
 * CSRF helpers
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_validate(?string $token): void {
    if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

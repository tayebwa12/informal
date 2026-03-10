<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/config/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/* 🔒 Only allow admin */
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die("Access denied.");
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('csrf_validate')) {
    function csrf_validate($token): void {
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
            http_response_code(419);
            die("Invalid CSRF token.");
        }
    }
}

$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($_POST['csrf'] ?? null);

    try {
        // 🔐 Generate hash for "uvtab"
        $hash = password_hash('uvtab', PASSWORD_DEFAULT);

        // Update only examiners
        $st = $pdo->prepare("
            UPDATE users
            SET password_hash = ?, status = 'active'
            WHERE role = 'examiner'
        ");
        $st->execute([$hash]);

        $count = $st->rowCount();

        $notice = [
            'type' => 'success',
            'msg'  => "Successfully reset password for $count examiner(s). New password is: uvtab"
        ];

    } catch (Throwable $e) {
        $notice = [
            'type' => 'error',
            'msg'  => "Something went wrong."
        ];
    }
}
?>

<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Bulk Reset Examiners</title>
<style>
body{font-family:system-ui; background:#f1f5f9; padding:40px;}
.card{max-width:500px; margin:auto; background:#fff; padding:25px; border-radius:14px; box-shadow:0 12px 30px rgba(0,0,0,.1);}
button{width:100%; padding:14px; border:none; border-radius:10px; background:#0e1b54; color:#fff; font-weight:800; font-size:16px; cursor:pointer;}
.msg{margin-bottom:15px; padding:12px; border-radius:10px;}
.success{background:#ecfdf5; color:#065f46; border:1px solid #bbf7d0;}
.error{background:#fef2f2; color:#991b1b; border:1px solid #fecaca;}
.warn{background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; padding:10px; margin-bottom:15px; border-radius:10px;}
</style>
</head>
<body>

<div class="card">
    <h2>Bulk Reset Examiner Passwords</h2>

    <div class="warn">
        ⚠ This will reset ALL examiner passwords to: <b>uvtab</b>
    </div>

    <?php if ($notice): ?>
        <div class="msg <?= h($notice['type']) ?>">
            <?= h($notice['msg']) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">
        <button type="submit">Reset All Examiner Passwords</button>
    </form>
</div>

</body>
</html>
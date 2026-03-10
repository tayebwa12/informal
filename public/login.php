<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$errors = [];

/* ---------------- Helpers ---------------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function norm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}

function cleanPhone(string $s): string {
  $s = norm($s);
  $s = preg_replace('/[^0-9+]/', '', $s);
  return $s ?: '';
}

/**
 * Standardize UG phone to 07XXXXXXXX when possible.
 * Accepts: 07XXXXXXXX, 7XXXXXXXX, 2567XXXXXXXX, +2567XXXXXXXX
 */
function normalizeUgPhone07(string $s): string {
  $p = cleanPhone($s);
  if ($p === '') return '';

  $p = ltrim($p, '+');

  if (preg_match('/^07\d{8}$/', $p)) {
    return $p;
  } elseif (preg_match('/^7\d{8}$/', $p)) {
    return '0' . $p;
  } elseif (preg_match('/^2567\d{8}$/', $p)) {
    return '0' . substr($p, 3); // 2567XXXXXXXX -> 07XXXXXXXX
  }

  return '';
}

/**
 * Also provide +256 version for legacy DB rows.
 */
function normalizeUgPhone256(string $s): string {
  $p07 = normalizeUgPhone07($s);
  if ($p07 === '') return '';
  // 07XXXXXXXX -> +2567XXXXXXXX
  return '+256' . substr($p07, 1);
}

/* ---------------- CSRF fallback (prevents fatal errors) ---------------- */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
  }
}
if (!function_exists('csrf_validate')) {
  function csrf_validate($token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $posted = (string)($token ?? '');
    if ($sessionToken === '' || $posted === '' || !hash_equals($sessionToken, $posted)) {
      http_response_code(419);
      die("Invalid CSRF token.");
    }
  }
}

/* ---------------- POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($_POST['csrf'] ?? null);

    $rawPhone = trim((string)($_POST['phone'] ?? ''));
    $pass     = (string)($_POST['password'] ?? '');

    if ($rawPhone === '') $errors[] = "Enter phone number.";
    if ($pass === '')     $errors[] = "Enter password.";

    if (!$errors) {

        // ✅ Standardize for lookup (preferred)
        $phone07  = normalizeUgPhone07($rawPhone);
        $phone256 = normalizeUgPhone256($rawPhone);

        if ($phone07 === '') {
            $errors[] = "Enter a valid UG phone number (07XXXXXXXX).";
        } else {

            // ✅ Search using multiple possible stored formats (supports legacy DB)
            $st = $pdo->prepare("
                SELECT id, full_name, email, phone, password_hash, role, status
                FROM users
                WHERE phone = ?
                   OR phone = ?
                   OR phone = ?
                LIMIT 1
            ");

            // Some old data might be stored as 2567... (without +)
            $digits256 = $phone256 !== '' ? ltrim($phone256, '+') : '';

            $st->execute([$phone07, $phone256, $digits256]);
            $u = $st->fetch(PDO::FETCH_ASSOC);

            if (!$u) {
                $errors[] = "Invalid phone number or password.";
            } else {
                $hash = (string)($u['password_hash'] ?? '');

                if ($hash === '') {
                    $errors[] = "Account password is not set yet. Contact admin.";
                } elseif (!password_verify($pass, $hash)) {
                    $errors[] = "Invalid phone number or password.";
                } elseif (strtolower((string)$u['status']) !== 'active') {
                    $errors[] = "Account not active. Contact admin.";
                } else {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$u['id'];
                    $_SESSION['role']    = (string)$u['role'];
                    $_SESSION['user'] = [
                        'id' => (int)$u['id'],
                        'full_name' => (string)$u['full_name'],
                        'email' => (string)$u['email'],
                        'phone' => (string)$u['phone'],
                        'role' => (string)$u['role'],
                        'status' => (string)$u['status'],
                    ];

                    header('Location: index.php');
                    exit;
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>UVTAB - Unified Management</title>
  <style>
    :root {
      --uv-navy: #0e1b54;
      --uv-blue: #1b5cff;
      --uv-green: #4caf50;
      --uv-light-blue: #edf3ff;
    }

    body {
      font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
      margin: 0;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background-color: #f0f2f5;
    }

    .login-container {
      display: flex;
      width: 100%;
      max-width: 1000px;
      height: 600px;
      background: #fff;
      border-radius: 24px;
      overflow: hidden;
      box-shadow: 0 20px 40px rgba(0,0,0,0.15);
      margin: 20px;
    }

    .left-panel {
      flex: 1.1;
      background-color: var(--uv-navy);
      color: #ffffff;
      padding: 60px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
    }

    .left-panel h3 { font-weight: 400; font-size: 1.2rem; margin-bottom: 5px; }
    .left-panel h1 { font-size: 3rem; margin: 0 0 20px 0; }
    .left-panel p { font-size: 1.1rem; opacity: 0.9; margin-bottom: 40px; line-height: 1.5; }

    .btn-apply {
      background-color: var(--uv-green);
      color: white;
      padding: 16px 32px;
      border-radius: 50px;
      text-decoration: none;
      font-weight: bold;
      font-size: 1rem;
      transition: opacity 0.2s;
    }

    .right-panel {
      flex: 1;
      padding: 40px 60px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      background: #fff;
    }

    .logo-box { text-align: center; margin-bottom: 30px; }
    .logo-box img { width: 100px; height: auto; }

    form { width: 100%; }

    .input-group { margin-bottom: 15px; }

    input[type="text"],
    input[type="password"] {
      width: 100%;
      padding: 14px 18px;
      border: 1px solid #dce0e5;
      border-radius: 10px;
      background-color: var(--uv-light-blue);
      font-size: 1rem;
      box-sizing: border-box;
      outline: none;
    }

    .btn-signin {
      width: 100%;
      padding: 14px;
      background-color: var(--uv-navy);
      color: white;
      border: none;
      border-radius: 10px;
      font-size: 1.1rem;
      font-weight: bold;
      cursor: pointer;
      margin-top: 10px;
    }

    .forgot-link {
      margin-top: 25px;
      color: #555;
      text-decoration: none;
      font-size: 0.95rem;
    }

    .copyright {
      margin-top: 50px;
      color: #999;
      font-size: 0.85rem;
    }

    .error-msg {
      background: #fee2e2;
      color: #b91c1c;
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 15px;
      width: 100%;
      font-size: 0.9rem;
      text-align: center;
    }

    @media (max-width: 850px) {
      .login-container { flex-direction: column; height: auto; max-width: 450px; }
      .left-panel { padding: 40px 20px; }
      .right-panel { padding: 40px 30px; }
      .left-panel h1 { font-size: 2.2rem; }
    }
  </style>
</head>
<body>

<div class="login-container">
  <div class="left-panel">
    <h3>Sign in to Your Account</h3>
    <h1>Welcome to UVTAB Informal Practical management <Portal></Portal> </h1>
    <p>Unified, secure & reliable Assessment management.</p>

    <!-- ✅ FIXED LINK (was pply_examiner.php) -->
    <a href="apply_examiner.php" class="btn-apply">
      Join Our Informal Assessment Team
    </a>
  </div>

  <div class="right-panel">
    <div class="logo-box">
      <img src="assets/images/logo.png" alt="UVTAB Logo">
    </div>

    <?php if ($errors): ?>
      <div class="error-msg"><?= implode("<br>", array_map('h', $errors)); ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">

      <div class="input-group">
        <input type="text" name="phone" placeholder="Phone (e.g. 078..., +2567...)" required value="<?= h($_POST['phone'] ?? ''); ?>">
      </div>

      <div class="input-group">
        <input type="password" name="password" placeholder="••••••" required>
      </div>

      <button type="submit" class="btn-signin">Sign In</button>
    </form>

    <a href="forgot_password.php" class="forgot-link">Forgot your password?</a>

    <div class="copyright">
      &copy; <?= date('Y'); ?> UVTAB. All rights reserved.
    </div>
  </div>
</div>

</body>
</html>
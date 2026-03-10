<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/config/db.php';
require_once __DIR__ . '/../../app/config/app.php';     // csrf_token(), csrf_validate()
require_once __DIR__ . '/../../app/middleware/role_admin.php';

$me = require_admin();
$adminId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tableExists(PDO $pdo, string $table): bool {
  $sql = "SHOW TABLES LIKE " . $pdo->quote($table);
  $st = $pdo->query($sql);
  return (bool)$st->fetchColumn();
}

/** ✅ MariaDB-safe (NO placeholders in SHOW COLUMNS) */
function columnExists(PDO $pdo, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/', '', $col);

  $sql = "SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col);
  $st = $pdo->query($sql);
  return (bool)$st->fetch(PDO::FETCH_ASSOC);
}

function hash_password(string $plain): string {
  return password_hash($plain, PASSWORD_DEFAULT);
}

function norm_phone(?string $raw): ?string {
  $p = preg_replace('/\D+/', '', (string)$raw);
  if ($p === '') return null;

  if (strlen($p) === 9 && str_starts_with($p, '7')) return '256' . $p;
  if (strlen($p) === 10 && str_starts_with($p, '0')) return '256' . substr($p, 1);
  if (str_starts_with($p, '256') && strlen($p) >= 12) return $p;

  return $p;
}

// ✅ Required tables
if (!tableExists($pdo, 'users')) die("Missing table: users");
if (!tableExists($pdo, 'roles')) die("Missing table: roles");
if (!tableExists($pdo, 'user_roles')) die("Missing table: user_roles");

// ✅ Detect columns (match your schema)
$hasFullName     = columnExists($pdo, 'users', 'full_name');
$hasEmail        = columnExists($pdo, 'users', 'email');
$hasPhone        = columnExists($pdo, 'users', 'phone');
$hasPasswordHash = columnExists($pdo, 'users', 'password_hash');
$hasRoleEnum     = columnExists($pdo, 'users', 'role');
$hasStatus       = columnExists($pdo, 'users', 'status');

// ✅ choose label
$labelExpr = "CAST(u.id AS CHAR)";
if ($hasFullName) $labelExpr = "COALESCE(u.full_name, u.phone, CAST(u.id AS CHAR))";
elseif ($hasPhone) $labelExpr = "COALESCE(u.phone, CAST(u.id AS CHAR))";

$msg = null;
$err = null;

// ------------------ POST ACTIONS ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  // ✅ Create User (login username = phone, default password = uvtab)
  if (($_POST['create_user'] ?? '') === '1') {
    try {
      $full_name = trim((string)($_POST['full_name'] ?? ''));
      $email     = trim((string)($_POST['email'] ?? ''));
      $phoneRaw  = trim((string)($_POST['phone'] ?? ''));
      $phone     = $phoneRaw !== '' ? norm_phone($phoneRaw) : null;

      $status = trim((string)($_POST['status'] ?? 'active'));
      if ($hasStatus && !in_array($status, ['active','inactive','blocked'], true)) $status = 'active';

      $role = trim((string)($_POST['user_role'] ?? 'officer'));
      if ($hasRoleEnum && !in_array($role, ['admin','officer','examiner'], true)) $role = 'officer';

      if (!$hasPhone) throw new RuntimeException("Your users table has no phone column.");
      if (!$hasPasswordHash) throw new RuntimeException("Your users table has no password_hash column.");
      if (!$hasRoleEnum) throw new RuntimeException("Your users table has no role column.");
      if (!$hasEmail) throw new RuntimeException("Your users table has no email column.");
      if (!$hasFullName) throw new RuntimeException("Your users table has no full_name column.");

      if (!$phone) throw new RuntimeException("Phone is required.");
      if ($email === '') throw new RuntimeException("Email is required.");
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Invalid email address.");
      if ($full_name === '') throw new RuntimeException("Full name is required.");

      // Uniqueness checks (phone/email are UNIQUE)
      $st = $pdo->prepare("SELECT 1 FROM users WHERE phone=? LIMIT 1");
      $st->execute([$phone]);
      if ($st->fetchColumn()) throw new RuntimeException("Phone already exists.");

      $st = $pdo->prepare("SELECT 1 FROM users WHERE email=? LIMIT 1");
      $st->execute([$email]);
      if ($st->fetchColumn()) throw new RuntimeException("Email already exists.");

      $cols = ["full_name","email","phone","password_hash","role"];
      $vals = [$full_name,$email,$phone,hash_password('uvtab'),$role];

      if ($hasStatus) { $cols[] = "status"; $vals[] = $status; }

      $placeholders = implode(',', array_fill(0, count($cols), '?'));
      $sql = "INSERT INTO users (" . implode(',', $cols) . ") VALUES ($placeholders)";
      $st = $pdo->prepare($sql);
      $st->execute($vals);

      $newId = (int)$pdo->lastInsertId();
      $msg = "✅ New user created (ID: {$newId}). Login username = {$phone} • Default password = uvtab";
    } catch (Throwable $e) {
      $err = "❌ Failed to create user: " . $e->getMessage();
    }
  }

  // ✅ Create Role (roles table)
  if (($_POST['create_role'] ?? '') === '1') {
    $name = strtolower(trim((string)($_POST['role_name'] ?? '')));
    $desc = trim((string)($_POST['role_desc'] ?? ''));

    if ($name === '') {
      $err = "Role name is required.";
    } elseif (!preg_match('/^[a-z0-9_]{2,50}$/', $name)) {
      $err = "Role name must be 2–50 chars: letters, numbers, underscore only.";
    } else {
      try {
        $st = $pdo->prepare("SELECT 1 FROM roles WHERE name=? LIMIT 1");
        $st->execute([$name]);
        if ($st->fetchColumn()) throw new RuntimeException("Role already exists.");

        $st = $pdo->prepare("INSERT INTO roles (name, description, status) VALUES (?, ?, 'active')");
        $st->execute([$name, $desc !== '' ? $desc : null]);

        $msg = "✅ Role created: {$name}";
      } catch (Throwable $e) {
        $err = "❌ Failed to create role: " . $e->getMessage();
      }
    }
  }

  // ✅ Assign Role to user (user_roles table)
  if (($_POST['assign_role'] ?? '') === '1') {
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $roleId = (int)($_POST['assign_role_id'] ?? 0);

    if ($targetUserId <= 0 || $roleId <= 0) {
      $err = "Select user and role.";
    } else {
      try {
        $st = $pdo->prepare("SELECT status FROM roles WHERE id=? LIMIT 1");
        $st->execute([$roleId]);
        $rStatus = (string)($st->fetchColumn() ?: '');
        if ($rStatus !== 'active') throw new RuntimeException("Cannot assign an INACTIVE role.");

        $st = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id=? AND role_id=? LIMIT 1");
        $st->execute([$targetUserId, $roleId]);
        if ($st->fetchColumn()) throw new RuntimeException("User already has this role.");

        $st = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)");
        $st->execute([$targetUserId, $roleId, $adminId]);

        $msg = "✅ Role assigned.";
      } catch (Throwable $e) {
        $err = "❌ Failed to assign role: " . $e->getMessage();
      }
    }
  }

  // ✅ Remove Role from user
  if (($_POST['remove_role'] ?? '') === '1') {
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $roleId = (int)($_POST['remove_role_id'] ?? 0);

    if ($targetUserId <= 0 || $roleId <= 0) {
      $err = "Invalid remove request.";
    } else {
      try {
        $st = $pdo->prepare("DELETE FROM user_roles WHERE user_id=? AND role_id=? LIMIT 1");
        $st->execute([$targetUserId, $roleId]);
        $msg = "✅ Role removed.";
      } catch (Throwable $e) {
        $err = "❌ Failed to remove role: " . $e->getMessage();
      }
    }
  }

  // ✅ EDIT USER
  if (($_POST['edit_user'] ?? '') === '1') {
    $editUserId = (int)($_POST['edit_user_id'] ?? 0);
    if ($editUserId <= 0) {
      $err = "Invalid user selected.";
    } else {
      try {
        $updates = [];
        $vals = [];

        if ($hasFullName) {
          $full = trim((string)($_POST['full_name'] ?? ''));
          if ($full === '') throw new RuntimeException("Full name cannot be blank.");
          $updates[] = "full_name=?";
          $vals[] = $full;
        }

        if ($hasEmail) {
          $email = trim((string)($_POST['email'] ?? ''));
          if ($email === '') throw new RuntimeException("Email cannot be blank.");
          if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Invalid email.");
          $updates[] = "email=?";
          $vals[] = $email;
        }

        if ($hasPhone) {
          $newPhone = norm_phone((string)($_POST['phone'] ?? ''));
          if (!$newPhone) throw new RuntimeException("Phone cannot be blank.");
          $updates[] = "phone=?";
          $vals[] = $newPhone;
        }

        if ($hasRoleEnum) {
          $role = trim((string)($_POST['role'] ?? 'officer'));
          if (!in_array($role, ['admin','officer','examiner'], true)) $role = 'officer';
          $updates[] = "role=?";
          $vals[] = $role;
        }

        if ($hasStatus) {
          $status = trim((string)($_POST['status'] ?? 'active'));
          if (!in_array($status, ['active','inactive','blocked'], true)) $status = 'active';
          $updates[] = "status=?";
          $vals[] = $status;
        }

        if (!$updates) {
          $err = "No editable fields found on users table.";
        } else {
          $vals[] = $editUserId;
          $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id=? LIMIT 1";
          $st = $pdo->prepare($sql);
          $st->execute($vals);
          $msg = "✅ User updated.";
        }
      } catch (Throwable $e) {
        $err = "❌ Failed to update user: " . $e->getMessage();
      }
    }
  }

  // ✅ RESET PASSWORD (password_hash)
  if (($_POST['reset_pass'] ?? '') === '1') {
    $resetUserId = (int)($_POST['reset_user_id'] ?? 0);
    $newPass = trim((string)($_POST['new_password'] ?? ''));

    if ($resetUserId <= 0) {
      $err = "Invalid user selected.";
    } elseif (!$hasPasswordHash) {
      $err = "Your users table has no 'password_hash' column.";
    } else {
      if ($newPass === '') $newPass = 'uvtab';
      if (strlen($newPass) < 4) {
        $err = "Password too short.";
      } else {
        try {
          $hash = hash_password($newPass);
          $st = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? LIMIT 1");
          $st->execute([$hash, $resetUserId]);
          $msg = "✅ Password reset successfully. New password = " . h($newPass);
        } catch (Throwable $e) {
          $err = "❌ Failed to reset password: " . $e->getMessage();
        }
      }
    }
  }

  // ✅ DELETE USER (hard delete)
  if (($_POST['delete_user'] ?? '') === '1') {
    $deleteUserId = (int)($_POST['delete_user_id'] ?? 0);

    if ($deleteUserId <= 0) {
      $err = "Invalid user selected for deletion.";
    } elseif ($deleteUserId === $adminId) {
      $err = "You cannot delete your own admin account.";
    } else {
      try {
        $pdo->beginTransaction();

        $st = $pdo->prepare("DELETE FROM user_roles WHERE user_id=?");
        $st->execute([$deleteUserId]);

        $st = $pdo->prepare("DELETE FROM users WHERE id=? LIMIT 1");
        $st->execute([$deleteUserId]);

        if ($st->rowCount() < 1) {
          throw new RuntimeException("User not found or already deleted.");
        }

        $pdo->commit();
        $msg = "✅ User deleted successfully.";
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = "❌ Failed to delete user: " . $e->getMessage();
      }
    }
  }
}

// ------------------ LIST ROLES ------------------
$roles = $pdo->query("SELECT id, name, description, status FROM roles ORDER BY name ASC")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ------------------ USERS: PAGINATION + SEARCH ------------------
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = "WHERE 1=1";
$params = [];

if ($q !== '') {
  $like = "%{$q}%";
  $parts = [];
  if ($hasFullName) $parts[] = "u.full_name LIKE ?";
  if ($hasEmail)    $parts[] = "u.email LIKE ?";
  if ($hasPhone)    $parts[] = "u.phone LIKE ?";
  if ($parts) {
    $where .= " AND (" . implode(" OR ", $parts) . ")";
    $params = array_fill(0, count($parts), $like);
  }
}

$st = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
$st->execute($params);
$totalUsers = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($totalUsers / $perPage));
if ($page > $totalPages) $page = $totalPages;

$usersSql = "
  SELECT
    u.id,
    {$labelExpr} AS label
    " . ($hasFullName ? ", u.full_name" : ", NULL AS full_name") . "
    " . ($hasEmail    ? ", u.email"     : ", NULL AS email") . "
    " . ($hasPhone    ? ", u.phone"     : ", NULL AS phone") . "
    " . ($hasRoleEnum ? ", u.role"      : ", NULL AS role") . "
    " . ($hasStatus   ? ", u.status"    : ", NULL AS status") . "
  FROM users u
  $where
  ORDER BY u.id DESC
  LIMIT $perPage OFFSET $offset
";
$st = $pdo->prepare($usersSql);
$st->execute($params);
$users = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ------------------ USER ROLES MAP ------------------
$userRoles = $pdo->query("
  SELECT ur.user_id, r.id AS role_id, r.name AS role_name
  FROM user_roles ur
  JOIN roles r ON r.id = ur.role_id
  ORDER BY r.name ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rolesByUser = [];
foreach ($userRoles as $ur) {
  $uid = (int)$ur['user_id'];
  $rolesByUser[$uid][] = [
    'role_id' => (int)$ur['role_id'],
    'role_name' => (string)$ur['role_name'],
  ];
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Roles & Users</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../assets/css/main.css">
  <style>
    .box{border:1px solid #e5e7eb;border-radius:14px;padding:14px;margin-bottom:14px}
    .mini{font-size:13px;color:#64748b}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media (max-width: 900px){.grid2{grid-template-columns:1fr}}
    .pill{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:800;font-size:12px;background:#eef2ff;margin:2px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    input, select, textarea{width:100%}
    .ro{padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
    .actions{display:flex;gap:8px;flex-wrap:wrap}
    .cellform input, .cellform select{margin-bottom:8px}
    .btn-danger{
      background:#dc2626;border:1px solid #dc2626;color:#fff;
      padding:10px 14px;border-radius:10px;font-weight:800;cursor:pointer;
    }
    .btn-danger:hover{opacity:.9}
  </style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="card-title">
      <div>
        <h2 style="margin:0;">Roles & User Management</h2>
        <div class="mini">Login username is <b>Phone</b> • Default password is <b>uvtab</b></div>
      </div>
      <div class="row">
        <a class="btn btn-outline" href="dashboard.php">← Back</a>
        <a class="btn btn-outline" href="../logout.php">Logout</a>
      </div>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <div class="grid2">
    <div class="box">
      <h3 style="margin-top:0;">Create New User</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="create_user" value="1">

        <label>Full Name (REQUIRED)</label>
        <input class="ro" type="text" name="full_name" placeholder="e.g. John Doe" required>

        <label style="margin-top:10px;">Email (REQUIRED)</label>
        <input class="ro" type="email" name="email" placeholder="e.g. johndoe@gmail.com" required>

        <label style="margin-top:10px;">Phone (REQUIRED) — login username</label>
        <input class="ro" type="text" name="phone" placeholder="e.g. 077... or 2567..." required>

        <label style="margin-top:10px;">Role</label>
        <select class="ro" name="user_role" required>
          <option value="officer" selected>officer</option>
          <option value="examiner">examiner</option>
          <option value="admin">admin</option>
        </select>

        <label style="margin-top:10px;">Status</label>
        <select class="ro" name="status">
          <option value="active" selected>active</option>
          <option value="inactive">inactive</option>
          <option value="blocked">blocked</option>
        </select>

        <div class="mini" style="margin-top:10px;">
          Default password will be set to <b>uvtab</b>.
        </div>

        <div class="actions" style="margin-top:12px;">
          <button class="btn btn-primary" type="submit">Create User</button>
        </div>
      </form>
    </div>

    <div class="box">
      <h3 style="margin-top:0;">Create Role (roles table)</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="create_role" value="1">

        <label>Role Name</label>
        <input class="ro" type="text" name="role_name" placeholder="e.g. admin, officer, hr_manager" required>
        <div class="mini">Allowed: letters, numbers, underscore</div>

        <label style="margin-top:10px;">Description (optional)</label>
        <textarea class="ro" name="role_desc" rows="3"></textarea>

        <div class="actions" style="margin-top:12px;">
          <button class="btn btn-outline" type="submit">Create Role</button>
        </div>
      </form>
    </div>
  </div>

  <div class="box">
    <h3 style="margin-top:0;">Assign Role to User (user_roles table)</h3>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="assign_role" value="1">

      <div style="flex:1;min-width:260px;">
        <label>Select User (current page)</label>
        <select class="ro" name="target_user_id" required>
          <option value="">-- choose user --</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>">
              #<?= (int)$u['id'] ?> — <?= h((string)$u['label']) ?>
              <?= !empty($u['phone']) ? ' • ' . h((string)$u['phone']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="flex:1;min-width:240px;">
        <label>Select Role</label>
        <select class="ro" name="assign_role_id" required>
          <option value="">-- choose role --</option>
          <?php foreach ($roles as $r): ?>
            <?php $isActive = ((string)$r['status'] === 'active'); ?>
            <option value="<?= (int)$r['id'] ?>" <?= !$isActive ? 'disabled' : '' ?>>
              <?= h((string)$r['name']) ?> <?= $isActive ? '' : '(inactive)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="padding-top:22px;">
        <button class="btn btn-primary" type="submit">Assign Role</button>
      </div>
    </form>
  </div>

  <div class="box">
    <h3 style="margin-top:0;">Users & Their Roles</h3>

    <form method="get" class="row" style="justify-content:space-between;">
      <div class="mini">Total users: <?= (int)$totalUsers ?> • Page <?= (int)$page ?> of <?= (int)$totalPages ?></div>
      <div class="row" style="min-width:280px;">
        <input class="ro" type="text" name="q" value="<?= h($q) ?>" placeholder="Search users...">
        <button class="btn btn-outline" type="submit">Search</button>
        <?php if ($q !== ''): ?>
          <a class="btn btn-outline" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Reset</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="table-wrap" style="margin-top:10px;">
      <table>
        <thead>
          <tr>
            <th>User</th>
            <th>Roles (user_roles)</th>
            <th style="width:320px;">Remove Role</th>
            <th style="width:420px;">Edit User</th>
            <th style="width:320px;">Reset Password</th>
            <th style="width:180px;">Delete</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <?php $uid = (int)$u['id']; $list = $rolesByUser[$uid] ?? []; ?>
          <tr>
            <td>
              <b>#<?= $uid ?></b><br>
              <?= h((string)$u['label']) ?>
              <?php if (!empty($u['phone'])): ?><div class="mini">Phone/Login: <?= h((string)$u['phone']) ?></div><?php endif; ?>
              <?php if (!empty($u['email'])): ?><div class="mini"><?= h((string)$u['email']) ?></div><?php endif; ?>
              <?php if (!empty($u['role'])): ?><div class="mini">Enum role: <?= h((string)$u['role']) ?></div><?php endif; ?>
              <?php if (!empty($u['status'])): ?><div class="mini">Status: <?= h((string)$u['status']) ?></div><?php endif; ?>
            </td>

            <td>
              <?php if (!$list): ?>
                <span class="mini">No roles</span>
              <?php else: ?>
                <?php foreach ($list as $rr): ?>
                  <span class="pill"><?= h($rr['role_name']) ?></span>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($list): ?>
                <form method="post" class="row" style="gap:8px;">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="remove_role" value="1">
                  <input type="hidden" name="target_user_id" value="<?= $uid ?>">
                  <select class="ro" name="remove_role_id" required style="flex:1;">
                    <option value="">Select role</option>
                    <?php foreach ($list as $rr): ?>
                      <option value="<?= (int)$rr['role_id'] ?>"><?= h($rr['role_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-outline" type="submit" onclick="return confirm('Remove selected role from this user?');">
                    Remove
                  </button>
                </form>
              <?php else: ?>
                <span class="mini">—</span>
              <?php endif; ?>
            </td>

            <td>
              <form method="post" class="cellform">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" name="edit_user_id" value="<?= $uid ?>">

                <input class="ro" name="full_name" placeholder="Full name" value="<?= h((string)($u['full_name'] ?? '')) ?>" required>
                <input class="ro" name="email" placeholder="Email" value="<?= h((string)($u['email'] ?? '')) ?>" required>
                <input class="ro" name="phone" placeholder="Phone" value="<?= h((string)($u['phone'] ?? '')) ?>" required>

                <select class="ro" name="role" required>
                  <?php foreach (['officer','examiner','admin'] as $r): ?>
                    <option value="<?= h($r) ?>" <?= ((string)($u['role'] ?? '') === $r) ? 'selected' : '' ?>><?= h($r) ?></option>
                  <?php endforeach; ?>
                </select>

                <select class="ro" name="status" required>
                  <?php foreach (['active','inactive','blocked'] as $s): ?>
                    <option value="<?= h($s) ?>" <?= ((string)($u['status'] ?? '') === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
                  <?php endforeach; ?>
                </select>

                <button class="btn btn-outline" type="submit" onclick="return confirm('Save changes for this user?');">
                  Save
                </button>
              </form>
            </td>

            <td>
              <form method="post" class="cellform">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="reset_pass" value="1">
                <input type="hidden" name="reset_user_id" value="<?= $uid ?>">

                <input class="ro" type="text" name="new_password" placeholder="Leave blank = uvtab">
                <button class="btn btn-primary" type="submit" onclick="return confirm('Reset password for this user?');">
                  Reset
                </button>
              </form>
            </td>

            <td>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="delete_user" value="1">
                <input type="hidden" name="delete_user_id" value="<?= $uid ?>">
                <button class="btn-danger" type="submit" onclick="return confirm('Delete this user permanently? This cannot be undone.');">
                  Delete
                </button>
              </form>
            </td>

          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php $base = strtok($_SERVER["REQUEST_URI"], '?'); $qs = $_GET; ?>
    <div class="row" style="justify-content:space-between;margin-top:12px;">
      <div class="mini">Page <?= (int)$page ?> of <?= (int)$totalPages ?></div>
      <div class="actions">
        <?php if ($page > 1): ?>
          <?php $qs['page'] = $page - 1; ?>
          <a class="btn btn-outline" href="<?= h($base . '?' . http_build_query($qs)) ?>">← Prev</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <?php $qs['page'] = $page + 1; ?>
          <a class="btn btn-outline" href="<?= h($base . '?' . http_build_query($qs)) ?>">Next →</a>
        <?php endif; ?>
      </div>
    </div>

  </div>

</div>
</body>
</html>
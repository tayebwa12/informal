<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_admin.php';
require_once __DIR__ . '/../../app/config/db.php';
require_once __DIR__ . '/../../app/helpers/sms.php';

$me = require_admin();
$adminId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msg = null;
$err = null;

$current = sms_get_settings($pdo) ?? [
  'api_url' => 'https://comms.egosms.co/api/v1/json/',
  'api_username' => '',
  'api_key' => '',
  'sender_id' => '',
  'priority' => '0',
];

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate($_POST['csrf'] ?? null);

  $apiUrl = trim((string)($_POST['api_url'] ?? 'https://comms.egosms.co/api/v1/json/'));
  $apiUser = trim((string)($_POST['api_username'] ?? ''));
  $apiKey  = trim((string)($_POST['api_key'] ?? ''));
  $sender  = trim((string)($_POST['sender_id'] ?? ''));
  $priority = trim((string)($_POST['priority'] ?? '0'));

  if ($apiUser === '' || $apiKey === '' || $sender === '') {
    $err = "Please fill API Username, API Key, and Sender ID.";
  } else {
    try {
      // upsert (simple approach: insert new active row, deactivate older)
      $pdo->beginTransaction();
      $pdo->exec("UPDATE sms_settings SET is_active=0");
      $ins = $pdo->prepare("
        INSERT INTO sms_settings (provider, api_url, api_username, api_key, sender_id, priority, is_active, updated_by)
        VALUES ('egosms', ?, ?, ?, ?, ?, 1, ?)
      ");
      $ins->execute([$apiUrl, $apiUser, $apiKey, $sender, $priority, $adminId]);
      $pdo->commit();

      $msg = "✅ SMS settings saved.";
      $current = sms_get_settings($pdo) ?? $current;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = "❌ Save failed: " . $e->getMessage();
    }
  }

  // Test button
  if (isset($_POST['do_test']) && $_POST['do_test'] === '1' && !$err) {
    $testPhone = trim((string)($_POST['test_phone'] ?? ''));
    $testPhoneNorm = preg_replace('/\D+/', '', $testPhone);

    if ($testPhoneNorm === '') {
      $err = "Enter a test phone number.";
    } else {
      try {
        $payload = [
          "method" => "SendSms",
          "userdata" => [
            "username" => $current['api_username'],
            "password" => $current['api_key']
          ],
          "msgdata" => [[
            "number"   => $testPhoneNorm,
            "message"  => "SMS Test: Connection OK",
            "senderid" => $current['sender_id'],
            "priority" => $current['priority'] ?? '0'
          ]]
        ];

        $res = sms_send_egosms($current, $payload);
        $ok = ((int)$res['http_code'] === 200) && sms_is_success($res['body']);

        $testResult = [
          'ok' => $ok,
          'http_code' => $res['http_code'],
          'body' => $res['body']
        ];

      } catch (Throwable $e) {
        $testResult = [
          'ok' => false,
          'http_code' => 0,
          'body' => $e->getMessage()
        ];
      }
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>SMS Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../assets/css/main.css">
  <style>
    .box{border:1px solid #e5e7eb;border-radius:14px;padding:14px}
    .ro{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
    .mini{font-size:13px;color:#64748b}
  </style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="card-title">
      <div>
        <h2 style="margin:0;">SMS Settings (Admin)</h2>
        <p class="mini" style="margin:6px 0 0;">Configure EgoSMS credentials and test connectivity.</p>
      </div>
      <div class="flex gap-10">
        <a class="btn btn-outline" href="dashboard.php">← Back</a>
      </div>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">

      <div class="box">
        <label>API URL</label>
        <input class="ro" name="api_url" value="<?= h((string)$current['api_url']) ?>">

        <label>API Username</label>
        <input class="ro" name="api_username" value="<?= h((string)$current['api_username']) ?>">

        <label>API Key / Password</label>
        <input class="ro" name="api_key" value="<?= h((string)$current['api_key']) ?>">

        <label>Sender ID (must be approved)</label>
        <input class="ro" name="sender_id" value="<?= h((string)$current['sender_id']) ?>">

        <label>Priority</label>
        <input class="ro" name="priority" value="<?= h((string)$current['priority']) ?>">

        <div class="flex gap-10" style="margin-top:12px;flex-wrap:wrap;">
          <button class="btn btn-primary" type="submit" name="save" value="1">Save Settings</button>
        </div>
      </div>

      <div class="box" style="margin-top:12px;">
        <h3 style="margin:0 0 8px;">Test Connection</h3>
        <p class="mini" style="margin:0 0 10px;">Enter your phone and send a test SMS to confirm credentials.</p>

        <label>Test Phone (e.g. 2567...)</label>
        <input class="ro" name="test_phone" placeholder="2567XXXXXXXX">

        <div class="flex gap-10" style="margin-top:12px;flex-wrap:wrap;">
          <button class="btn btn-outline" type="submit" name="do_test" value="1">Send Test SMS</button>
        </div>

        <?php if (is_array($testResult)): ?>
          <div style="margin-top:12px;">
            <?php if ($testResult['ok']): ?>
              <div class="alert alert-success">✅ Connection OK (HTTP <?= (int)$testResult['http_code'] ?>)</div>
            <?php else: ?>
              <div class="alert alert-danger">❌ Connection failed (HTTP <?= (int)$testResult['http_code'] ?>)</div>
            <?php endif; ?>
            <div class="mini"><b>Raw response:</b></div>
            <pre style="white-space:pre-wrap;"><?= h((string)$testResult['body']) ?></pre>
          </div>
        <?php endif; ?>
      </div>

    </form>
  </div>

</div>
</body>
</html>
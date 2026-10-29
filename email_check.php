<?php
/**
 * EstateScope — Email Delivery Diagnostics
 * Run this ONCE from browser to check SMTP configuration.
 * DELETE this file after use — never leave it on a production server.
 *
 * URL: http://localhost/AWS-based-Realestate-project/email_check.php
 */
require_once __DIR__ . '/secrets.php';

$checks = [];

// 1. PHPMailer present?
$mailerPath = __DIR__ . '/include/mailer/PHPMailer.php';
$checks['PHPMailer installed'] = is_file($mailerPath) ? '✅ Found at ' . $mailerPath : '❌ NOT FOUND at ' . $mailerPath;

// 2. Credentials
$username = defined('MAIL_USERNAME')     ? MAIL_USERNAME     : (getenv('MAIL_USERNAME') ?: '');
$password = defined('MAIL_APP_PASSWORD') ? MAIL_APP_PASSWORD : (getenv('MAIL_APP_PASSWORD') ?: '');

$isPlaceholderUser = ($username === 'your_email@gmail.com' || $username === '');
$isPlaceholderPass = ($password === 'your_16_char_app_password' || $password === '');

$checks['MAIL_USERNAME set']     = $isPlaceholderUser  ? '❌ Still placeholder / empty — set your Gmail address in secrets.php' : '✅ ' . $username;
$checks['MAIL_APP_PASSWORD set'] = $isPlaceholderPass  ? '❌ Still placeholder / empty — set your Gmail App Password in secrets.php' : '✅ [hidden — ' . strlen($password) . ' chars]';
$checks['Email format valid']    = filter_var($username, FILTER_VALIDATE_EMAIL) ? '✅ Valid email format' : '❌ Invalid email format';

// 3. Try SMTP connection (only if credentials look real)
$smtpResult = '⚠️ Skipped — fix credentials first';
if (!$isPlaceholderUser && !$isPlaceholderPass && filter_var($username, FILTER_VALIDATE_EMAIL)) {
    if (is_file($mailerPath)) {
        require_once $mailerPath;
        require_once __DIR__ . '/include/mailer/SMTP.php';
        require_once __DIR__ . '/include/mailer/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $username;
            $mail->Password   = $password;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->SMTPDebug  = 0;
            // Just open the connection
            $mail->smtpConnect();
            $smtpResult = '✅ SMTP connection + authentication succeeded!';
            $mail->smtpClose();
        } catch (Throwable $e) {
            $smtpResult = '❌ SMTP FAILED: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}
$checks['SMTP connection test'] = $smtpResult;

// 4. DB connection
require_once __DIR__ . '/config.php';
$checks['Database connection'] = $con ? '✅ Connected to MySQL' : '❌ MySQL connection failed';

// 5. Check password_reset_tokens table
if ($con) {
    $res = $con->query("SHOW TABLES LIKE 'password_reset_tokens'");
    $checks['password_reset_tokens table'] = ($res && $res->num_rows > 0) ? '✅ Table exists' : '⚠️ Table not yet created (will auto-create on first use)';
}

// ─── Output ───────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<title>EstateScope Email Diagnostics</title>
<style>
body { font-family: monospace; background: #f5f5f5; padding: 40px; color: #222; }
h1   { color: #0d1432; }
table { border-collapse: collapse; width: 100%; max-width: 900px; background: #fff;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08); border-radius: 8px; overflow: hidden; }
th, td { padding: 14px 20px; text-align: left; border-bottom: 1px solid #ececec; }
th { background: #0d1432; color: #fff; }
.warn { background: #fffbea; }
h2 { margin-top: 40px; color: #0d1432; }
pre { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ddd; }
</style>
</head>
<body>
<h1>🔍 EstateScope Email Delivery Diagnostics</h1>
<table>
  <tr><th>Check</th><th>Result</th></tr>
  <?php foreach ($checks as $label => $result): ?>
    <tr class="<?= str_contains($result, '❌') || str_contains($result, '⚠️') ? 'warn' : '' ?>">
      <td><strong><?= htmlspecialchars($label) ?></strong></td>
      <td><?= $result ?></td>
    </tr>
  <?php endforeach; ?>
</table>

<h2>📋 What to fix if credentials are placeholder</h2>
<pre>
STEP 1 — Get a Gmail App Password
  a) Go to: https://myaccount.google.com/security
  b) Enable 2-Step Verification
  c) Go to: https://myaccount.google.com/apppasswords
  d) App name: "EstateScope"  → Generate → copy the 16-char password

STEP 2 — Edit secrets.php
  File: <?= htmlspecialchars(__DIR__ . '/secrets.php') ?>

  define('MAIL_USERNAME',    'you@gmail.com');          // your Gmail address
  define('MAIL_APP_PASSWORD','abcd efgh ijkl mnop');    // 16-char App Password (spaces OK)

STEP 3 — Reload this page to re-run diagnostics

STEP 4 — Delete this file when done:
  <?= htmlspecialchars(__FILE__) ?>
</pre>

<p style="color:#999;font-size:12px;">⚠️ Delete <strong>email_check.php</strong> after use. Never leave diagnostic files on a production server.</p>
</body>
</html>


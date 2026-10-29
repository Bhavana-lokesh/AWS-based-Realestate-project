<?php
// ─── Email-link password reset (loaded by forgot_password.php & reset_password.php) ───

function ensurePasswordResetTokenTable(mysqli $connection): void
{
    $connection->query("CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id`     INT NOT NULL,
        `token_hash`  VARCHAR(64) NOT NULL UNIQUE,
        `expires_at`  DATETIME NOT NULL,
        `used_at`     DATETIME DEFAULT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_prt_user (user_id),
        INDEX idx_prt_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function createPasswordResetToken(mysqli $connection, int $userId): ?string
{
    ensurePasswordResetTokenTable($connection);
    // Rate-limit: max 3 requests per user per hour
    $check = $connection->prepare("SELECT COUNT(*) AS cnt FROM password_reset_tokens
        WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    if (!$check) { return null; }
    $check->bind_param('i', $userId);
    $check->execute();
    $cnt = (int)($check->get_result()->fetch_assoc()['cnt'] ?? 0);
    $check->close();
    if ($cnt >= 3) { return null; }

    // Invalidate previous unused tokens for this user
    $inv = $connection->prepare("UPDATE password_reset_tokens SET used_at = NOW()
        WHERE user_id = ? AND used_at IS NULL");
    if ($inv) { $inv->bind_param('i', $userId); $inv->execute(); $inv->close(); }

    // Generate secure token
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $ins = $connection->prepare("INSERT INTO password_reset_tokens
        (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
    if (!$ins) { return null; }
    $ins->bind_param('is', $userId, $tokenHash);
    if (!$ins->execute()) { $ins->close(); return null; }
    $ins->close();
    return $rawToken;
}

function verifyPasswordResetToken(mysqli $connection, string $rawToken): ?int
{
    ensurePasswordResetTokenTable($connection);
    $tokenHash = hash('sha256', $rawToken);
    $stmt = $connection->prepare("SELECT user_id FROM password_reset_tokens
        WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['user_id'] : null;
}

function consumePasswordResetToken(mysqli $connection, string $rawToken): bool
{
    $tokenHash = hash('sha256', $rawToken);
    $stmt = $connection->prepare("UPDATE password_reset_tokens SET used_at = NOW()
        WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()");
    if (!$stmt) { return false; }
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected === 1;
}

/**
 * Reads SMTP credentials from .env (SMTP_USERNAME / SMTP_PASSWORD) with a
 * secrets.php fallback (MAIL_USERNAME / MAIL_APP_PASSWORD).
 * Does NOT require APP_SEND_EMAIL to be set — that flag only gates the old OTP system.
 * Returns ['username' => ..., 'password' => ...] or null if not configured.
 */
function getSmtpCredentials(): ?array
{
    // 1. Try environment variables first (.env takes priority)
    $username = (string)(getenv('SMTP_USERNAME') ?: getenv('MAIL_USERNAME') ?: '');
    $password = (string)(getenv('SMTP_PASSWORD') ?: getenv('MAIL_APP_PASSWORD') ?: '');

    // 2. Fall back to secrets.php constants
    if (($username === '' || $password === '') && is_file(__DIR__ . '/../secrets.php')) {
        // Load without re-defining already-defined constants
        @include_once __DIR__ . '/../secrets.php';
        $username = $username !== '' ? $username : (defined('MAIL_USERNAME')     ? MAIL_USERNAME     : '');
        $password = $password !== '' ? $password : (defined('MAIL_APP_PASSWORD') ? MAIL_APP_PASSWORD : '');
    }

    // 3. Validate — reject placeholders
    if ($username === ''
        || $password === ''
        || $username === 'your_email@gmail.com'
        || $password === 'your_16_char_app_password'
        || filter_var($username, FILTER_VALIDATE_EMAIL) === false
    ) {
        return null;
    }

    return ['username' => $username, 'password' => $password];
}

function sendPasswordResetEmail(string $toEmail, string $toName, string $resetUrl): bool
{
    $credentials = getSmtpCredentials();
    if ($credentials === null) {
        error_log('[EstateScope] Password reset email NOT sent: SMTP credentials not configured in .env (SMTP_USERNAME/SMTP_PASSWORD) or secrets.php (MAIL_USERNAME/MAIL_APP_PASSWORD).');
        return false;
    }

    $mailerDir = __DIR__ . '/mailer/';
    if (!is_file($mailerDir . 'PHPMailer.php')) {
        error_log('[EstateScope] PHPMailer not found at ' . $mailerDir . 'PHPMailer.php');
        return false;
    }

    require_once $mailerDir . 'PHPMailer.php';
    require_once $mailerDir . 'SMTP.php';
    require_once $mailerDir . 'Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = (string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = $credentials['username'];
        $mail->Password   = $credentials['password'];
        $mail->SMTPSecure = (function () {
            $encryption = strtolower((string)(getenv('SMTP_ENCRYPTION') ?: 'tls'));
            return $encryption === 'ssl'
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        })();
        $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom((string)(getenv('SMTP_FROM_EMAIL') ?: $credentials['username']), (string)(getenv('SMTP_FROM_NAME') ?: 'EstateScope Support'));
        $mail->addAddress($toEmail, $toName ?: 'User');
        $mail->Subject = 'Reset Your EstateScope Password';
        $mail->isHTML(true);

        $firstName = htmlspecialchars(explode(' ', trim($toName))[0] ?: 'there', ENT_QUOTES, 'UTF-8');
        $safeUrl   = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:0;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0"
      style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:100%;">
      <tr>
        <td style="background:#0d1432;padding:28px 40px;text-align:center;">
          <span style="font-size:24px;color:#fff;font-weight:700;letter-spacing:1px;">EstateScope</span>
        </td>
      </tr>
      <tr>
        <td style="padding:40px 40px 28px;">
          <p style="color:#0d1432;font-size:17px;font-weight:700;margin:0 0 12px;">Hello, {$firstName}</p>
          <p style="color:#74777b;font-size:15px;line-height:1.6;margin:0 0 28px;">
            We received a request to reset your EstateScope account password.<br>
            Click the button below to create a new password.
          </p>
          <p style="text-align:center;margin:0 0 28px;">
            <a href="{$safeUrl}"
               style="display:inline-block;background:#17c788;color:#fff;text-decoration:none;
                      font-size:16px;font-weight:700;padding:14px 36px;border-radius:8px;">
              Reset Password
            </a>
          </p>
          <p style="color:#a3a7af;font-size:13px;margin:0 0 8px;">
            This link will expire in <strong>30 minutes</strong>.
          </p>
          <p style="color:#a3a7af;font-size:13px;margin:0;">
            If you did not request this password reset, you can safely ignore this email.
          </p>
        </td>
      </tr>
      <tr>
        <td style="background:#f8f8f8;padding:20px 40px;border-top:1px solid #ececec;">
          <p style="color:#a3a7af;font-size:12px;margin:0;text-align:center;">
            Regards, EstateScope Support Team
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

        $mail->AltBody =
            "Hello {$firstName},\n\n" .
            "We received a request to reset your EstateScope account password.\n\n" .
            "Click the link below to create a new password:\n{$resetUrl}\n\n" .
            "This link will expire in 30 minutes.\n\n" .
            "If you did not request this, you can safely ignore this email.\n\n" .
            "Regards,\nEstateScope Support Team";

        $mail->send();
        error_log('[EstateScope] Password reset email submitted via SMTP to: ' . $toEmail);
        return true;

    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('[EstateScope] PHPMailer SMTP error sending to ' . $toEmail . ': ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        error_log('[EstateScope] Unexpected error sending password reset email: ' . $e->getMessage());
        return false;
    }
}

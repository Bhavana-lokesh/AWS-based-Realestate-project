<?php
function normalizeAuthPhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (preg_match('/^[6-9]\d{9}$/', $digits) === 1) {
        return '91' . $digits;
    }

    return $digits;
}

function authRequestIpHash(): string
{
    return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function smsOtpConfigured(): bool
{
    $accountSid = (string)(getenv('TWILIO_ACCOUNT_SID') ?: '');
    $authToken = (string)(getenv('TWILIO_AUTH_TOKEN') ?: '');
    $fromNumber = (string)(getenv('TWILIO_FROM_NUMBER') ?: '');
    return preg_match('/^AC[a-fA-F0-9]{32}$/', $accountSid) === 1
        && strlen($authToken) >= 16
        && preg_match('/^\+[1-9]\d{7,14}$/', $fromNumber) === 1
        && function_exists('curl_init');
}

function smsOtpDestination(string $phone): ?string
{
    $phone = trim($phone);
    if (preg_match('/^\+[1-9]\d{7,14}$/', $phone) === 1) {
        return $phone;
    }

    $countryCode = normalizeAuthPhone((string)(getenv('TWILIO_DEFAULT_COUNTRY_CODE') ?: ''));
    $digits = normalizeAuthPhone($phone);
    if ($countryCode === '' || $digits === '') {
        return null;
    }
    $internationalNumber = '+' . $countryCode . ltrim($digits, '0');

    return preg_match('/^\+[1-9]\d{7,14}$/', $internationalNumber) === 1
        ? $internationalNumber
        : null;
}

function mailOtpCredentials(): ?array
{
    $username = (string)(getenv('MAIL_USERNAME') ?: '');
    $password = (string)(getenv('MAIL_APP_PASSWORD') ?: '');
    if (($username === '' || $password === '') && is_file(__DIR__ . '/../secrets.php')) {
        require_once __DIR__ . '/../secrets.php';
        $username = $username !== '' ? $username : (defined('MAIL_USERNAME') ? MAIL_USERNAME : '');
        $password = $password !== '' ? $password : (defined('MAIL_APP_PASSWORD') ? MAIL_APP_PASSWORD : '');
    }

    if (filter_var($username, FILTER_VALIDATE_EMAIL) === false
        || $password === ''
        || $username === 'your_email@gmail.com'
        || $password === 'your_16_char_app_password') {
        return null;
    }

    return ['username' => $username, 'password' => $password];
}

function emailOtpConfigured(): bool
{
    return getenv('APP_SEND_EMAIL') === 'true' && mailOtpCredentials() !== null;
}

function findBuyerByPhone(mysqli $connection, string $phone): ?array
{
    $normalizedPhone = normalizeAuthPhone($phone);
    if (strlen($normalizedPhone) < 7 || strlen($normalizedPhone) > 30) {
        return null;
    }

    $statement = $connection->prepare("SELECT user_id, phone_num FROM user
        WHERE phone_normalized = ? AND LOWER(role) = 'buyer' AND is_active = 1
        ORDER BY user_id LIMIT 2");
    if (!$statement) {
        throw new RuntimeException('Buyer account lookup could not be prepared.');
    }
    $statement->bind_param('s', $normalizedPhone);
    $statement->execute();
    $result = $statement->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return count($users) === 1 ? $users[0] : null;
}

function findPasswordAccountByEmail(mysqli $connection, string $email): ?array
{
    $email = strtolower(trim($email));
    $statement = $connection->prepare("SELECT user_id, name, email, role FROM user
        WHERE LOWER(email) = ? AND LOWER(role) IN ('buyer', 'agent', 'super_admin', 'admin') AND is_active = 1
        LIMIT 1");
    if (!$statement) {
        throw new RuntimeException('Password account lookup could not be prepared.');
    }
    $statement->bind_param('s', $email);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc() ?: null;
    $statement->close();

    return $user;
}

function passwordLoginIsLocked(mysqli $connection, string $email): bool
{
    $identifierHash = hash('sha256', strtolower(trim($email)));
    $ipHash = authRequestIpHash();
    $statement = $connection->prepare("SELECT failed_attempts,
        (window_started_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS window_active,
        (locked_until > NOW()) AS is_locked
        FROM auth_password_login_limits
        WHERE identifier_hash = ? AND request_ip_hash = ? LIMIT 1");
    if (!$statement) {
        throw new RuntimeException('Sign-in rate limit could not be checked.');
    }
    $statement->bind_param('ss', $identifierHash, $ipHash);
    $statement->execute();
    $limit = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$limit) {
        return false;
    }

    return (int)$limit['failed_attempts'] >= 5
        && (int)$limit['window_active'] === 1
        || (int)$limit['is_locked'] === 1;
}

function recordPasswordLoginFailure(mysqli $connection, string $email): void
{
    $identifierHash = hash('sha256', strtolower(trim($email)));
    $ipHash = authRequestIpHash();
    if (!$connection->query("DELETE FROM auth_password_login_limits
        WHERE window_started_at < DATE_SUB(NOW(), INTERVAL 1 DAY)")) {
        throw new RuntimeException('Expired sign-in attempts could not be cleared.');
    }
    $statement = $connection->prepare("INSERT INTO auth_password_login_limits
        (identifier_hash, request_ip_hash, failed_attempts, window_started_at, locked_until)
        VALUES (?, ?, 1, NOW(), NULL)
        ON DUPLICATE KEY UPDATE
            locked_until = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                NULL, IF(failed_attempts >= 4, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until)),
            failed_attempts = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                1, LEAST(failed_attempts + 1, 255)),
            window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                NOW(), window_started_at)");
    if (!$statement) {
        throw new RuntimeException('Sign-in attempt could not be recorded.');
    }
    $statement->bind_param('ss', $identifierHash, $ipHash);
    $statement->execute();
    $statement->close();
}

function clearPasswordLoginFailures(mysqli $connection, string $email): void
{
    $identifierHash = hash('sha256', strtolower(trim($email)));
    $ipHash = authRequestIpHash();
    $statement = $connection->prepare("DELETE FROM auth_password_login_limits
        WHERE identifier_hash = ? AND request_ip_hash = ?");
    if (!$statement) {
        throw new RuntimeException('Sign-in attempt counter could not be cleared.');
    }
    $statement->bind_param('ss', $identifierHash, $ipHash);
    $statement->execute();
    $statement->close();
}

function createAuthOtpChallenge(mysqli $connection, int $userId, string $purpose): ?array
{
    if (!in_array($purpose, ['buyer_login', 'password_reset'], true)) {
        throw new InvalidArgumentException('Unsupported OTP purpose.');
    }

    $ipHash = authRequestIpHash();
    $rateLimit = $connection->prepare("SELECT
        SUM(user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS user_requests,
        SUM(request_ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS ip_requests
        FROM auth_otp_challenges WHERE purpose = ?");
    if (!$rateLimit) {
        throw new RuntimeException('OTP rate limit could not be checked.');
    }
    $rateLimit->bind_param('iss', $userId, $ipHash, $purpose);
    $rateLimit->execute();
    $limits = $rateLimit->get_result()->fetch_assoc();
    $rateLimit->close();
    if ((int)($limits['user_requests'] ?? 0) >= 5 || (int)($limits['ip_requests'] ?? 0) >= 10) {
        return null;
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $connection->begin_transaction();
    try {
        $consumePrevious = $connection->prepare("UPDATE auth_otp_challenges
            SET consumed_at = NOW() WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL");
        if (!$consumePrevious) {
            throw new RuntimeException('Previous OTP challenges could not be invalidated.');
        }
        $consumePrevious->bind_param('is', $userId, $purpose);
        $consumePrevious->execute();
        $consumePrevious->close();

        $insert = $connection->prepare("INSERT INTO auth_otp_challenges
            (user_id, purpose, code_hash, request_ip_hash, expires_at, last_sent_at)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())");
        if (!$insert) {
            throw new RuntimeException('OTP challenge could not be created.');
        }
        $insert->bind_param('isss', $userId, $purpose, $codeHash, $ipHash);
        $insert->execute();
        $challengeId = (int)$insert->insert_id;
        $insert->close();
        $connection->commit();

        return ['challenge_id' => $challengeId, 'code' => $code];
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

function resendAuthOtpChallenge(mysqli $connection, int $challengeId, string $purpose): ?array
{
    if (!in_array($purpose, ['buyer_login', 'password_reset'], true)) {
        throw new InvalidArgumentException('Unsupported OTP purpose.');
    }

    $connection->begin_transaction();
    try {
        $statement = $connection->prepare("SELECT challenge_id, user_id, attempt_count, resend_count
            FROM auth_otp_challenges WHERE challenge_id = ? AND purpose = ?
            AND consumed_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            AND last_sent_at <= DATE_SUB(NOW(), INTERVAL 1 MINUTE) FOR UPDATE");
        if (!$statement) {
            throw new RuntimeException('OTP challenge could not be loaded.');
        }
        $statement->bind_param('is', $challengeId, $purpose);
        $statement->execute();
        $challenge = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$challenge
            || (int)$challenge['resend_count'] >= 3
            || (int)$challenge['attempt_count'] >= 5) {
            $connection->commit();
            return null;
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $update = $connection->prepare("UPDATE auth_otp_challenges SET code_hash = ?,
            expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), last_sent_at = NOW(),
            resend_count = resend_count + 1
            WHERE challenge_id = ?");
        if (!$update) {
            throw new RuntimeException('OTP challenge could not be updated.');
        }
        $update->bind_param('si', $codeHash, $challengeId);
        $update->execute();
        $update->close();
        $connection->commit();

        return [
            'challenge_id' => $challengeId,
            'user_id' => (int)$challenge['user_id'],
            'code' => $code,
        ];
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

function verifyAuthOtpChallenge(mysqli $connection, int $challengeId, string $purpose, string $code): ?int
{
    if (!in_array($purpose, ['buyer_login', 'password_reset'], true)) {
        throw new InvalidArgumentException('Unsupported OTP purpose.');
    }

    $connection->begin_transaction();
    try {
        $statement = $connection->prepare("SELECT user_id, code_hash, attempt_count,
            (expires_at >= NOW()) AS is_unexpired
            FROM auth_otp_challenges WHERE challenge_id = ? AND purpose = ?
            AND consumed_at IS NULL FOR UPDATE");
        if (!$statement) {
            throw new RuntimeException('OTP challenge could not be verified.');
        }
        $statement->bind_param('is', $challengeId, $purpose);
        $statement->execute();
        $challenge = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$challenge || (int)$challenge['attempt_count'] >= 5) {
            $connection->commit();
            return null;
        }

        $isValid = (int)$challenge['is_unexpired'] === 1
            && preg_match('/^\d{6}$/', $code) === 1
            && password_verify($code, $challenge['code_hash']);
        $update = $connection->prepare("UPDATE auth_otp_challenges
            SET attempt_count = attempt_count + 1,
                consumed_at = IF(?, NOW(), consumed_at)
            WHERE challenge_id = ?");
        if (!$update) {
            throw new RuntimeException('OTP attempt could not be recorded.');
        }
        $validFlag = $isValid ? 1 : 0;
        $update->bind_param('ii', $validFlag, $challengeId);
        $update->execute();
        $update->close();
        $connection->commit();

        return $isValid ? (int)$challenge['user_id'] : null;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

function sendSmsOtp(string $phone, string $code): bool
{
    if (!smsOtpConfigured() || !function_exists('curl_init')) {
        return false;
    }
    $destination = smsOtpDestination($phone);
    if ($destination === null) {
        error_log('Buyer OTP SMS delivery failed.');
        return false;
    }

    $accountSid = (string)getenv('TWILIO_ACCOUNT_SID');
    $authToken = (string)getenv('TWILIO_AUTH_TOKEN');
    $fromNumber = (string)getenv('TWILIO_FROM_NUMBER');
    $curl = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'To' => $destination,
            'From' => $fromNumber,
            'Body' => 'Your EstateScope sign-in code is ' . $code . '. It expires in 10 minutes.',
        ]),
        CURLOPT_USERPWD => $accountSid . ':' . $authToken,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $failed = curl_errno($curl) !== 0;
    curl_close($curl);

    if ($failed || $status < 200 || $status >= 300) {
        error_log('Buyer OTP SMS delivery failed.');
        return false;
    }
    return true;
}

function sendEmailOtp(string $email, string $name, string $code): bool
{
    if (getenv('APP_SEND_EMAIL') !== 'true') {
        return false;
    }
    $credentials = mailOtpCredentials();
    if ($credentials === null) {
        return false;
    }
    require_once __DIR__ . '/mailer/PHPMailer.php';
    require_once __DIR__ . '/mailer/SMTP.php';
    require_once __DIR__ . '/mailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $credentials['username'];
        $mail->Password = $credentials['password'];
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom($credentials['username'], 'EstateScope');
        $mail->addAddress($email, $name);
        $mail->Subject = 'Your EstateScope verification code';
        $mail->Body = 'Your verification code is ' . $code . '. It expires in 10 minutes.';
        $mail->send();
        return true;
    } catch (Throwable $exception) {
        error_log('Email OTP delivery failed.');
        return false;
    }
}

function invalidateAuthOtpChallenge(mysqli $connection, int $challengeId, string $purpose): void
{
    $statement = $connection->prepare("UPDATE auth_otp_challenges SET consumed_at = NOW()
        WHERE challenge_id = ? AND purpose = ? AND consumed_at IS NULL");
    if (!$statement) {
        throw new RuntimeException('OTP challenge could not be invalidated.');
    }
    $statement->bind_param('is', $challengeId, $purpose);
    $statement->execute();
    $statement->close();
}
?>

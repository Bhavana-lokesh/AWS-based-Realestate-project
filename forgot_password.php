<?php
session_start();
require_once 'config.php';
require_once 'include/auth_challenges.php';
require_once 'include/auth_challenges_extra.php';

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

$error   = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $email   = strtolower(trim((string)($_POST['email'] ?? '')));
    $account = null;

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $account = findPasswordAccountByEmail($con, $email);
    }

    if ($account) {
        $rawToken = createPasswordResetToken($con, (int)$account['user_id']);
        if ($rawToken) {
            // Build reset URL
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $resetUrl = $scheme . '://' . $host . $base . '/reset_password.php?token=' . urlencode($rawToken);

            sendPasswordResetEmail((string)$account['email'], (string)$account['name'], $resetUrl);
        }
    }

    // Always show the same generic message (prevents enumeration)
    $message = 'If an account exists with this email address, a password reset link has been sent. Please check your inbox (and spam folder).';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password | EstateScope</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/role-themes.css">
    <link rel="stylesheet" href="css/marketplace.css">
</head>
<body class="market-auth-page">
<?php include 'include/header.php'; ?>
<main class="market-auth-layout">
    <section class="market-auth-form">
        <p class="market-eyebrow">Account recovery</p>
        <h1>Reset your password</h1>
        <p class="market-auth-intro">Enter the email address associated with your account and we'll send you a password reset link.</p>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($message !== ''): ?><div class="alert alert-info" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post" action="forgot_password.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" class="form-control" autocomplete="email" required>
            </div>
            <button class="market-button" type="submit">Send Reset Link</button>
        </form>
        <p class="market-auth-footer"><a href="login.php">Return to sign in</a></p>
    </section>
</main>
<?php include 'include/footer.php'; ?>
</body>
</html>

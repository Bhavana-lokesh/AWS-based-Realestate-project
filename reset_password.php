<?php
session_start();
require_once 'config.php';
require_once 'include/auth_challenges.php';
require_once 'include/auth_challenges_extra.php';

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

$error   = '';
$message = '';
$success = false;

// Read token from URL (GET) or POST
$rawToken = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $rawToken = trim((string)($_POST['token'] ?? ''));
    $password = (string)($_POST['pass'] ?? '');
    $confirmPassword = (string)($_POST['cpass'] ?? '');

    if ($rawToken === '') {
        $error = 'Reset link is missing or invalid. Please request a new one.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $userId = verifyPasswordResetToken($con, $rawToken);
        if ($userId === null) {
            $error = 'This reset link is invalid or has expired. Please request a new one.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $con->prepare("UPDATE user SET password = ?, reset_token = NULL
                WHERE user_id = ? AND is_active = 1 AND LOWER(role) IN ('buyer','agent','seller','super_admin','admin')");
            if (!$stmt) {
                $error = 'Password reset could not be completed. Please try again.';
            } else {
                $stmt->bind_param('si', $passwordHash, $userId);
                if ($stmt->execute() && $stmt->affected_rows >= 1) {
                    consumePasswordResetToken($con, $rawToken);
                    header('Location: login.php');
                    exit();
                } else {
                    $error = 'Password reset failed. Please request a new link.';
                }
                $stmt->close();
            }
        }
    }
} elseif ($rawToken !== '') {
    // Validate token on page load so we can show an early error
    $userId = verifyPasswordResetToken($con, $rawToken);
    if ($userId === null) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
        $rawToken = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password | EstateScope</title>
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
        <h1>Choose a new password</h1>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <div class="alert alert-success" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <p class="market-auth-footer"><a class="market-button" href="login.php">Sign in now</a></p>
        <?php elseif ($rawToken !== ''): ?>
            <form method="post" action="reset_password.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="pass">New password <small>(min 8 characters)</small></label>
                    <input id="pass" type="password" name="pass" class="form-control" autocomplete="new-password" minlength="8" required>
                </div>
                <div class="form-group">
                    <label for="cpass">Confirm new password</label>
                    <input id="cpass" type="password" name="cpass" class="form-control" autocomplete="new-password" minlength="8" required>
                </div>
                <button class="market-button" type="submit">Reset password</button>
            </form>
        <?php else: ?>
            <p>No valid reset token found.</p>
        <?php endif; ?>

        <p class="market-auth-footer"><a href="forgot_password.php">Request a new reset link</a></p>
        <p class="market-auth-footer"><a href="login.php">Return to sign in</a></p>
    </section>
</main>
<?php include 'include/footer.php'; ?>
</body>
</html>

<?php
session_start();
require_once 'config.php';
require_once 'auth.php';
require_once 'include/auth_challenges.php';

$error = '';
$message = '';
$isAdminPortal = (string)($_GET['portal'] ?? $_POST['portal'] ?? '') === 'admin';
$loginRoles = ['buyer', 'agent', 'super_admin'];
$selectedLoginRole = strtolower(trim((string)($_POST['account_role'] ?? ($isAdminPortal ? 'super_admin' : 'buyer'))));
if (!in_array($selectedLoginRole, $loginRoles, true)) {
    $selectedLoginRole = $isAdminPortal ? 'super_admin' : 'buyer';
}
if ($isAdminPortal) {
    $selectedLoginRole = 'super_admin';
}
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'password_login') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if ($email !== '' && $password !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (!passwordLoginIsLocked($con, $email)) {
                $statement = $con->prepare('SELECT user_id, name, email, password, role, is_active
                    FROM user WHERE LOWER(email) = ? LIMIT 1');
                if (!$statement) {
                    throw new RuntimeException('Sign-in could not be prepared.');
                }
                $statement->bind_param('s', $email);
                $statement->execute();
                $user = $statement->get_result()->fetch_assoc();
                $statement->close();

                $role = strtolower(trim((string)($user['role'] ?? '')));
                $role = match ($role) {
                    'seller' => 'agent',
                    'admin', 'super admin' => 'super_admin',
                    default => $role,
                };
                if ($user
                    && (int)$user['is_active'] === 1
                    && in_array($role, $loginRoles, true)
                    && $role === $selectedLoginRole
                    && password_verify($password, $user['password'])) {
                    clearPasswordLoginFailures($con, $email);
                    $role = $role === 'admin' ? 'super_admin' : $role;
                    session_regenerate_id(true);
                    $_SESSION['uid'] = (int)$user['user_id'];
                    $_SESSION['user_id'] = (int)$user['user_id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $role === 'super_admin' ? 'admin' : $role;
                    if ($role === 'super_admin') {
                        $_SESSION['admin_email'] = $user['email'];
                    }
                    header('Location: ' . dashboardPathForRole($role));
                    exit;
                }
                recordPasswordLoginFailure($con, $email);
            }
        }
        $error = 'The email or password is incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isAdminPortal ? 'Administrator sign in' : 'Sign in' ?> | EstateScope</title>
    <link href="https://fonts.googleapis.com/css?family=Muli:400,600,700&amp;display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Comfortaa:400,700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/role-themes.css?v=admin-login-theme">
    <link rel="stylesheet" href="css/marketplace.css?v=admin-login-theme">
</head>
<body class="market-auth-page<?= $isAdminPortal ? ' market-admin-auth-page' : '' ?>" data-role="<?= htmlspecialchars($selectedLoginRole, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($isAdminPortal): ?>
    <header class="market-admin-auth-header">
        <a class="estate-brand" href="index.php" aria-label="EstateScope home">
            <span class="estate-brand-mark" aria-hidden="true">E</span>
            <span class="estate-brand-name">Estate<span>Scope</span></span>
        </a>
        <span>Administrator portal</span>
        <?php if (isset($_SESSION['uid'])): ?><a href="logout.php">Sign out</a><?php endif; ?>
    </header>
<?php else: ?>
    <?php include 'include/header.php'; ?>
<?php endif; ?>
<main class="market-auth-layout">
    <section class="market-auth-visual" aria-label="Property image">
        <img src="admin/property/zillhms1.jpg" alt="House surrounded by mature trees">
        <div class="market-auth-visual-copy">
            <p class="market-eyebrow">EstateScope</p>
            <h2><?= $isAdminPortal ? 'EstateScope administration.' : 'Make your next move with clarity.' ?></h2>
            <p><?= $isAdminPortal ? 'A focused workspace for platform oversight and property review.' : 'Sign in to revisit saved properties, offers, and appointments.' ?></p>
        </div>
    </section>
    <section class="market-auth-form">
        <p class="market-eyebrow"><?= $isAdminPortal ? 'Secure administrator access' : 'Choose your account type' ?></p>
        <h1><?= $isAdminPortal ? 'Administrator sign in' : 'Sign in' ?></h1>
        <p class="market-auth-intro"><?= $isAdminPortal ? 'Sign in with your Super Admin email and password.' : 'Select whether you are signing in as a Buyer, Agent, or Super Admin.' ?></p>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($message !== ''): ?><div class="alert alert-info" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form id="buyer-login-form" method="post" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="password_login">
            <?php if ($isAdminPortal): ?><input type="hidden" name="portal" value="admin"><?php endif; ?>
            <?php if (!$isAdminPortal): ?>
                <div class="form-group">
                    <label for="account-role">Sign in as</label>
                    <select id="account-role" name="account_role" class="form-control" required>
                        <option value="buyer" <?= $selectedLoginRole === 'buyer' ? 'selected' : '' ?>>Buyer</option>
                        <option value="agent" <?= $selectedLoginRole === 'agent' ? 'selected' : '' ?>>Agent</option>
                        <option value="super_admin" <?= $selectedLoginRole === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                    </select>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="login-email">Email address</label>
                <input id="login-email" type="email" name="email" class="form-control" autocomplete="username" placeholder="you@example.com" required>
            </div>
            <div class="form-group">
                <label for="login-password">Password</label>
                <input id="login-password" type="password" name="password" class="form-control" autocomplete="current-password" placeholder="Your password" required>
            </div>
            <button class="market-button" type="submit">Sign in</button>
        </form>
        <?php if (!$isAdminPortal): ?>
            <p class="market-auth-footer"><a href="forgot_password.php">Forgot your password?</a></p>
            <p class="market-auth-footer">New to EstateScope? <a href="register.php">Create an account</a></p>
        <?php endif; ?>
    </section>
</main>
<?php if ($isAdminPortal): ?>
    <footer class="market-admin-auth-footer">© <?= date('Y') ?> EstateScope · Secure administrator access</footer>
<?php else: ?>
    <?php include 'include/footer.php'; ?>
    <script>
        document.getElementById('account-role').addEventListener('change', (event) => {
            document.body.dataset.role = event.currentTarget.value;
        });
    </script>
<?php endif; ?>
</body>
</html>

<?php 
session_start();
include("config.php");
include("functions.php");
require_once 'auth.php';

$error = "";
$msg = "";
$allowedRegistrationRoles = [
    'buyer' => 'Buyer',
    'agent' => 'Agent',
];
$roleDescriptions = [
    'buyer' => 'Find properties, save favorites, submit inquiries, and manage your visits.',
    'agent' => 'Manage listings owned by your account and follow their buyer activity.',
];
$selectedRole = strtolower(trim((string)($_POST['account_type'] ?? 'buyer')));
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

if (isset($_POST['reg'])) {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $pass = (string)($_POST['pass'] ?? '');
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    $normalizedPhone = $phoneDigits;

    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    if (!isset($allowedRegistrationRoles[$selectedRole])) {
        $error = "<p class='alert alert-warning'>Choose a valid account type.</p>";
    } elseif ($name === '' || $email === '' || $phone === '' || $pass === '') {
        $error = "<p class='alert alert-warning'>Please fill all the required fields.</p>";
    } elseif (strlen($name) > 255 || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "<p class='alert alert-warning'>Enter a valid name and email address.</p>";
    } elseif (strlen($pass) < 8) {
        $error = "<p class='alert alert-warning'>Password must be at least 8 characters long.</p>";
    } elseif (preg_match('/^[6-9]\d{9}$/', $phoneDigits) !== 1) {
        $error = "<p class='alert alert-warning'>Enter a valid 10-digit Indian mobile number.</p>";
    } else {
        $normalizedPhone = '91' . $phoneDigits;
        $check = $con->prepare("SELECT user_id FROM user WHERE email = ? LIMIT 1");
        if (!$check) {
            $error = "<p class='alert alert-danger'>Registration is temporarily unavailable. Please try again later.</p>";
        } else {
            $check->bind_param("s", $email);
            $check->execute();
            $existingUser = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existingUser) {
                $error = "<p class='alert alert-warning'>Email ID already exists.</p>";
            } else {
                $phoneCheck = $con->prepare("SELECT user_id FROM user WHERE phone_normalized = ? LIMIT 1");
                if (!$phoneCheck) {
                    $error = "<p class='alert alert-danger'>Registration is temporarily unavailable. Please try again later.</p>";
                } else {
                    $phoneCheck->bind_param('s', $normalizedPhone);
                    $phoneCheck->execute();
                    $existingBuyerPhone = $phoneCheck->get_result()->fetch_assoc();
                    $phoneCheck->close();
                }

                if ($error === '' && $existingBuyerPhone) {
                    $error = "<p class='alert alert-warning'>That phone number is already linked to an account.</p>";
                }

                if ($error === '') {
                    $hashedPass = password_hash($pass, PASSWORD_DEFAULT);
                    $role = $allowedRegistrationRoles[$selectedRole];
                    $insert = $con->prepare("INSERT INTO user (name, email, phone_num, phone_normalized, password, role, date_of_creation)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    if (!$insert) {
                        $error = "<p class='alert alert-danger'>Registration is temporarily unavailable. Please try again later.</p>";
                    } else {
                        $phoneForDb = $normalizedPhone;
                        $insert->bind_param("ssssss", $name, $email, $phoneDigits, $phoneForDb, $hashedPass, $role);
                        $registrationCreated = false;
                        try {
                            $insert->execute();
                            $registrationCreated = true;
                        } catch (mysqli_sql_exception $exception) {
                            if ($exception->getCode() === 1062) {
                                $error = "<p class='alert alert-warning'>Email address or phone number is already in use.</p>";
                            } else {
                                error_log('Registration insert failed: ' . $exception->getMessage());
                                $error = "<p class='alert alert-danger'>Registration could not be completed. Please try again.</p>";
                            }
                        }
                        if ($registrationCreated) {
                            addAuditLog($insert->insert_id, 'REGISTRATION', $role . ' registered with name: ' . $name);
                            $msg = "<p class='alert alert-success'>Registration successful. <a href='login.php'>Sign in</a>.</p>";
                        }
                        $insert->close();
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account | EstateScope</title>
    <link href="https://fonts.googleapis.com/css?family=Muli:400,600,700&amp;display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Comfortaa:400,700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/role-themes.css">
    <link rel="stylesheet" href="css/marketplace.css">
</head>
<body class="market-auth-page" data-role="<?= in_array($selectedRole, ['buyer', 'agent'], true) ? htmlspecialchars($selectedRole, ENT_QUOTES, 'UTF-8') : 'buyer' ?>">
<?php include 'include/header.php'; ?>
<main class="market-auth-layout">
    <section class="market-auth-visual" aria-label="Property image">
        <img src="admin/property/download.jpeg" alt="Stone house at dusk">
        <div class="market-auth-visual-copy">
            <p class="market-eyebrow">EstateScope</p>
            <h2>Start with the right place.</h2>
            <p><?= htmlspecialchars($roleDescriptions[$selectedRole] ?? $roleDescriptions['buyer'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </section>
    <section class="market-auth-form">
        <p class="market-eyebrow">New account</p>
        <h1>Create your account</h1>
        <p class="market-auth-intro">Choose the account type that best describes how you will use EstateScope.</p>
        <?php echo $error; ?><?php echo $msg; ?>
        <form id="registration-form" method="post" action="register.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <fieldset class="market-role-picker">
                    <legend>Account type</legend>
                    <div class="market-role-options">
                        <label class="market-role-option" for="role-buyer">
                            <input id="role-buyer" type="radio" name="account_type" value="buyer" required <?= $selectedRole === 'buyer' ? 'checked' : '' ?>>
                            <span><strong>Buyer</strong><small>Find and explore properties.</small></span>
                        </label>
                        <label class="market-role-option" for="role-agent">
                            <input id="role-agent" type="radio" name="account_type" value="agent" required <?= $selectedRole === 'agent' ? 'checked' : '' ?>>
                            <span><strong>Agent</strong><small>Manage listings you are authorized for.</small></span>
                        </label>
                    </div>
                </fieldset>
                <div class="form-group">
                    <label for="name">Full name</label>
                    <input id="name" type="text" name="name" class="form-control" autocomplete="name" placeholder="Your name" required>
                </div>
                <div class="form-group">
                    <label for="register-email">Email address</label>
                    <input id="register-email" type="email" name="email" class="form-control" autocomplete="email" placeholder="you@example.com" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone number</label>
                    <div class="input-group">
                        <span class="input-group-text" aria-hidden="true">+91</span>
                        <input id="phone" type="tel" name="phone" class="form-control" autocomplete="tel-national" inputmode="numeric" maxlength="10" placeholder="9876543210" pattern="[6-9][0-9]{9}" aria-describedby="phone-help" required>
                    </div>
                    <small id="phone-help" class="form-text text-muted">Enter your 10-digit Indian mobile number.</small>
                </div>
                <div class="form-group" id="registration-password-group">
                    <label for="register-password">Password</label>
                    <input id="register-password" type="password" name="pass" class="form-control" autocomplete="new-password" minlength="8" placeholder="At least 8 characters" required>
                </div>
                <button id="register-submit" class="market-button" name="reg" type="submit">Create account</button>
        </form>
        <p class="market-auth-footer">Already have an account? <a href="login.php">Sign in</a></p>
    </section>
</main>
<noscript><p class="alert alert-warning">JavaScript is required to create an account.</p></noscript>
<?php include 'include/footer.php'; ?>
<script>
    document.querySelectorAll('input[name="account_type"]').forEach((option) => {
        option.addEventListener('change', () => {
            if (option.checked) {
                document.body.dataset.role = option.value;
            }
        });
    });
</script>
</body>
</html>

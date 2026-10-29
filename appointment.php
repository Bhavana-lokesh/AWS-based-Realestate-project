<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

$userId = (int)($_SESSION['uid'] ?? 0);
$role = strtolower($_SESSION['role'] ?? '');
requireRole(['buyer', 'agent']);
$emailEnabled = getenv('APP_SEND_EMAIL') === 'true';
if ($emailEnabled && is_file(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}
if (!$userId || !in_array($role, ['buyer', 'agent'], true)) {
    http_response_code(403);
    exit('Sign in with a Buyer or Agent account to request an appointment.');
}

$propertyId = filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT) ?: 0;
$propertyStatement = $con->prepare("SELECT property_id, seller_id, title FROM property_listings
    WHERE property_id = ? AND status = 'available' AND review_status = 'approved' LIMIT 1");
$propertyStatement->bind_param('i', $propertyId);
$propertyStatement->execute();
$property = $propertyStatement->get_result()->fetch_assoc();
$propertyStatement->close();

if (!$property || (int)$property['seller_id'] === $userId) {
    http_response_code(404);
    exit('This property is unavailable for appointment requests.');
}

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$error = '';
$success = $_SESSION['appointment_message'] ?? '';
unset($_SESSION['appointment_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $submittedTime = $_POST['time'] ?? '';
    $appointmentTime = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $submittedTime);
    $timeErrors = DateTimeImmutable::getLastErrors();
    if (!$appointmentTime || ($timeErrors && ($timeErrors['warning_count'] || $timeErrors['error_count'])) || $appointmentTime <= new DateTimeImmutable()) {
        $error = 'Choose a valid future date and time.';
    } else {
        $storedTime = $appointmentTime->format('Y-m-d H:i:s');
        $insert = $con->prepare("INSERT INTO appointment (user_id, property_id, time, status) VALUES (?, ?, ?, 'Pending')");
        $insert->bind_param('iis', $userId, $propertyId, $storedTime);

        if ($insert->execute()) {
            $insert->close();
            $_SESSION['appointment_message'] = 'Your appointment request has been sent.';

            if ($emailEnabled && defined('MAIL_USERNAME') && defined('MAIL_APP_PASSWORD')) {
                require_once 'include/mailer/PHPMailer.php';
                require_once 'include/mailer/SMTP.php';
                require_once 'include/mailer/Exception.php';

                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = MAIL_USERNAME;
                    $mail->Password = MAIL_APP_PASSWORD;
                    $mail->SMTPSecure = 'tls';
                    $mail->Port = 587;
                    $mail->setFrom(MAIL_USERNAME, 'EstateScope');
                    $mail->addAddress($_SESSION['email'], $_SESSION['name']);
                    $mail->isHTML(true);
                    $mail->Subject = 'Appointment request received';
                    $mail->Body = '<p>Hello ' . htmlspecialchars($_SESSION['name'], ENT_QUOTES, 'UTF-8') . ',</p>' .
                        '<p>Your appointment request for <strong>' . htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') . '</strong> is pending confirmation.</p>' .
                        '<p>Requested time: ' . htmlspecialchars($appointmentTime->format('d M Y, H:i'), ENT_QUOTES, 'UTF-8') . '</p>';
                    $mail->send();
                } catch (Throwable $exception) {
                    error_log('Appointment confirmation email could not be sent.');
                }
            }

            header('Location: appointment.php?property_id=' . $propertyId);
            exit;
        }

        $insert->close();
        $error = 'The appointment request could not be saved. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request an appointment | EstateScope</title>
    <link href="https://fonts.googleapis.com/css?family=Muli:400,600,700&amp;display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Comfortaa:400,700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/role-themes.css">
    <link rel="stylesheet" href="css/marketplace.css">
</head>
<body class="market-page">
<?php include 'include/header.php'; ?>
<main class="market-section market-appointment-page">
    <a class="market-text-link" href="propertydetail.php?pid=<?= $propertyId ?>">Back to property</a>
    <section class="market-appointment-form">
        <p class="market-eyebrow">Property visit</p>
        <h1>Request an appointment</h1>
        <p><?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($success): ?><div class="market-feedback" role="status"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error): ?><div class="market-form-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post" action="appointment.php?property_id=<?= $propertyId ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <label for="appointment-time">Preferred date and time</label>
                <input id="appointment-time" type="datetime-local" name="time" class="form-control" min="<?= (new DateTimeImmutable())->format('Y-m-d\\TH:i') ?>" required>
            </div>
            <button class="market-button" type="submit">Send appointment request</button>
        </form>
    </section>
</main>
<?php include 'include/footer.php'; ?>
</body>
</html>

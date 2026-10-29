<?php
session_start();
require_once 'config.php';

use PHPMailer\PHPMailer\PHPMailer;

require_once 'auth.php';

$userId = currentUserId();
$role = currentUserRole();
$propertyId = filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT);
requireRole(['agent']);
if (!$propertyId || $propertyId < 1) {
    http_response_code(400);
    exit('A valid property is required.');
}

$ownerCheck = $con->prepare("SELECT p.seller_id,
    EXISTS(SELECT 1 FROM appointment a WHERE a.property_id = p.property_id AND a.assigned_agent_id = ?) AS assigned_to_agent
    FROM property_listings p WHERE p.property_id = ? LIMIT 1");
$ownerCheck->bind_param('ii', $userId, $propertyId);
$ownerCheck->execute();
$property = $ownerCheck->get_result()->fetch_assoc();
$ownerCheck->close();
if (!$property) {
    http_response_code(404);
    exit('Property not found.');
}
if ((int)$property['seller_id'] !== $userId && (int)$property['assigned_to_agent'] !== 1) {
    http_response_code(403);
    exit('You can only view appointments for properties you manage or appointments assigned to you.');
}

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$emailEnabled = getenv('APP_SEND_EMAIL') === 'true';
if ($emailEnabled && is_file(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';
    if (!$appointmentId || !in_array($status, ['Accepted', 'Rejected'], true)) {
        http_response_code(400);
        exit('Invalid appointment action.');
    }

    $infoStatement = $con->prepare("SELECT a.time, u.name, u.email
        FROM appointment a JOIN user u ON a.user_id = u.user_id
        JOIN property_listings p ON p.property_id = a.property_id
        WHERE a.appointment_id = ? AND a.property_id = ? AND a.status = 'Pending'
        AND (p.seller_id = ? OR a.assigned_agent_id = ?) LIMIT 1");
    $infoStatement->bind_param('iiii', $appointmentId, $propertyId, $userId, $userId);
    $infoStatement->execute();
    $appointment = $infoStatement->get_result()->fetch_assoc();
    $infoStatement->close();

    if (!$appointment) {
        http_response_code(404);
        exit('Pending appointment not found for this property.');
    }

    $update = $con->prepare("UPDATE appointment a
        JOIN property_listings p ON p.property_id = a.property_id
        SET a.status = ? WHERE a.appointment_id = ? AND a.property_id = ? AND a.status = 'Pending'
        AND (p.seller_id = ? OR a.assigned_agent_id = ?)");
    $update->bind_param('siiii', $status, $appointmentId, $propertyId, $userId, $userId);
    $update->execute();
    $updated = $update->affected_rows === 1;
    $update->close();
    if (!$updated) {
        http_response_code(409);
        exit('The appointment has already been updated.');
    }

    $_SESSION['appointment_action_message'] = 'Appointment ' . strtolower($status) . '.';
    if ($status === 'Accepted' && $emailEnabled && defined('MAIL_USERNAME') && defined('MAIL_APP_PASSWORD')) {
        require_once __DIR__ . '/include/mailer/PHPMailer.php';
        require_once __DIR__ . '/include/mailer/SMTP.php';
        require_once __DIR__ . '/include/mailer/Exception.php';

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = MAIL_USERNAME;
            $mail->Password = MAIL_APP_PASSWORD;
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;
            $mail->setFrom(MAIL_USERNAME, 'EstateScope');
            $mail->addAddress($appointment['email'], $appointment['name']);
            $mail->isHTML(true);
            $mail->Subject = 'Your Appointment Has Been Accepted';
            $mail->Body = '<p>Hello ' . htmlspecialchars($appointment['name'], ENT_QUOTES, 'UTF-8') . ',</p>' .
                '<p>Your appointment for property #' . $propertyId . ' has been accepted.</p>' .
                '<p>Confirmed date and time: ' . htmlspecialchars($appointment['time'], ENT_QUOTES, 'UTF-8') . '</p>';
            $mail->send();
        } catch (Throwable $exception) {
            error_log('Appointment acceptance email could not be sent: ' . $mail->ErrorInfo);
            $_SESSION['appointment_action_message'] .= ' The email notification could not be sent.';
        }
    } elseif ($status === 'Accepted' && $emailEnabled) {
        error_log('Appointment email is enabled but its credentials are not configured.');
        $_SESSION['appointment_action_message'] .= ' The email notification is not configured.';
    }

    header('Location: view_appointments.php?property_id=' . $propertyId);
    exit;
}

$message = $_SESSION['appointment_action_message'] ?? '';
unset($_SESSION['appointment_action_message']);
$listStatement = $con->prepare("SELECT a.appointment_id, a.time, a.status, u.name, u.email
    FROM appointment a JOIN user u ON a.user_id = u.user_id
    JOIN property_listings p ON p.property_id = a.property_id
    WHERE a.property_id = ? AND (p.seller_id = ? OR a.assigned_agent_id = ?) ORDER BY a.time");
$listStatement->bind_param('iii', $propertyId, $userId, $userId);
$listStatement->execute();
$result = $listStatement->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Appointments | EstateScope</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/role-themes.css">
    <link rel="stylesheet" href="css/marketplace.css">
</head>
<body class="market-page" data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
<?php include 'include/header.php'; ?>
<main class="market-section">
    <h1>Appointments for Property #<?= (int)$propertyId ?></h1>
    <?php if ($message): ?><div class="market-feedback" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($result->num_rows === 0): ?>
        <div class="market-empty-state">No appointment requests found for this property.</div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="table table-bordered">
        <thead class="thead-dark">
            <tr>
                <th>#</th>
                <th>User</th>
                <th>Email</th>
                <th>Time</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($row = mysqli_fetch_assoc($result)): ?>
            <tr>
                <td><?= (int)$row['appointment_id'] ?></td>
                <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(date('d-m-Y H:i', strtotime($row['time'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?php if ($row['status'] === 'Pending'): ?>
                        <form method="POST" class="form-inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="appointment_id" value="<?= (int)$row['appointment_id'] ?>">
                            <button name="status" value="Accepted" class="btn btn-success btn-sm mr-1">Accept</button>
                            <button name="status" value="Rejected" class="btn btn-danger btn-sm">Reject</button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge-info"><?= $row['status'] ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</main>
<?php include 'include/footer.php'; ?>
</body>
</html>

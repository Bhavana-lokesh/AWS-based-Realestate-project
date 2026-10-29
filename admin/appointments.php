<?php
session_start();
require_once 'config.php';
require_once '../auth.php';
require_once '../functions.php';
requireRole(['super_admin']);

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }
    $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
    $agentId = filter_input(INPUT_POST, 'agent_id', FILTER_VALIDATE_INT);
    if (!$appointmentId || $appointmentId < 1 || $agentId === false || $agentId === null || $agentId < 0) {
        $error = 'Choose a valid appointment and Agent.';
    } else {
        $agentValue = null;
        if ($agentId > 0) {
            $agentCheck = $con->prepare("SELECT user_id FROM user
                WHERE user_id = ? AND LOWER(role) = 'agent' AND is_active = 1 LIMIT 1");
            if (!$agentCheck) {
                throw new RuntimeException('Agent account could not be checked.');
            }
            $agentCheck->bind_param('i', $agentId);
            $agentCheck->execute();
            $agentExists = (bool)$agentCheck->get_result()->fetch_assoc();
            $agentCheck->close();
            if (!$agentExists) {
                $error = 'Choose an active Agent account.';
            } else {
                $agentValue = $agentId;
            }
        }

        if ($error === '') {
            $appointmentCheck = $con->prepare('SELECT appointment_id FROM appointment WHERE appointment_id = ? LIMIT 1');
            if (!$appointmentCheck) {
                throw new RuntimeException('Appointment could not be checked.');
            }
            $appointmentCheck->bind_param('i', $appointmentId);
            $appointmentCheck->execute();
            $appointmentExists = (bool)$appointmentCheck->get_result()->fetch_assoc();
            $appointmentCheck->close();
            if (!$appointmentExists) {
                $error = 'Appointment not found.';
            }
        }
        if ($error === '') {
            $statement = $agentValue === null
                ? $con->prepare('UPDATE appointment SET assigned_agent_id = NULL WHERE appointment_id = ?')
                : $con->prepare('UPDATE appointment SET assigned_agent_id = ? WHERE appointment_id = ?');
            if (!$statement) {
                throw new RuntimeException('Appointment assignment could not be prepared.');
            }
            if ($agentValue === null) {
                $statement->bind_param('i', $appointmentId);
            } else {
                $statement->bind_param('ii', $agentValue, $appointmentId);
            }
            if ($statement->execute()) {
                if ($statement->affected_rows === 1) {
                    addAuditLog(currentUserId(), 'APPOINTMENT_ASSIGN', 'Updated assignment for appointment ' . $appointmentId);
                }
                $message = 'Appointment assignment updated.';
            } else {
                $error = 'Appointment assignment could not be updated.';
            }
            $statement->close();
        }
    }
}

$agentsResult = $con->query("SELECT user_id, name FROM user
    WHERE LOWER(role) = 'agent' AND is_active = 1 ORDER BY name");
$appointmentsResult = $con->query("SELECT a.appointment_id, a.time, a.status, a.assigned_agent_id,
    p.property_id, p.title, u.name AS buyer_name, u.email AS buyer_email, g.name AS agent_name
    FROM appointment a
    JOIN property_listings p ON p.property_id = a.property_id
    JOIN user u ON u.user_id = a.user_id
    LEFT JOIN user g ON g.user_id = a.assigned_agent_id
    ORDER BY FIELD(a.status, 'Pending', 'Accepted', 'Rejected'), a.time DESC");
if (!$agentsResult || !$appointmentsResult) {
    throw new RuntimeException('Appointment data could not be loaded.');
}
$agents = $agentsResult->fetch_all(MYSQLI_ASSOC);
$appointments = $appointmentsResult->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Appointments | EstateScope Admin</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=20261024">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="page-wrapper">
    <div class="content container-fluid">
        <div class="page-header"><h1 class="page-title">Appointments</h1></div>
        <?php if ($message !== ''): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($appointments === []): ?>
            <div class="card"><div class="card-body">There are no appointment requests.</div></div>
        <?php else: ?>
            <div class="card"><div class="card-body table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Property</th><th>Buyer</th><th>Requested time</th><th>Status</th><th>Assigned Agent</th></tr></thead>
                    <tbody>
                    <?php foreach ($appointments as $appointment): ?>
                        <tr>
                            <td><a href="../propertydetail.php?pid=<?= (int)$appointment['property_id'] ?>"><?= htmlspecialchars($appointment['title'], ENT_QUOTES, 'UTF-8') ?></a></td>
                            <td><?= htmlspecialchars($appointment['buyer_name'], ENT_QUOTES, 'UTF-8') ?><br><a href="mailto:<?= htmlspecialchars($appointment['buyer_email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($appointment['buyer_email'], ENT_QUOTES, 'UTF-8') ?></a></td>
                            <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($appointment['time'])), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($appointment['status'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <form method="post" class="form-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="appointment_id" value="<?= (int)$appointment['appointment_id'] ?>">
                                    <select name="agent_id" class="form-control mr-2" aria-label="Assign Agent">
                                        <option value="0">Unassigned</option>
                                        <?php foreach ($agents as $agent): ?>
                                            <option value="<?= (int)$agent['user_id'] ?>" <?= (int)$appointment['assigned_agent_id'] === (int)$agent['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($agent['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Assign</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        <?php endif; ?>
    </div>
</div>
<script src="assets/js/jquery-3.2.1.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
</body>
</html>

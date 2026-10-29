<?php
session_start();
include("config.php");
require_once '../auth.php';
requireRole(['super_admin']);
require_once '../functions.php';

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
    http_response_code(403);
    exit('Invalid request.');
}

$userId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$userId) {
    header("Location: userlist.php?msg=" . urlencode('Invalid user selected.'));
    exit();
}

// ✅ Deactivate user (soft delete) so they cannot log in,
// but the email remains available for re-registration.
$statement = $con->prepare('UPDATE user SET is_active = 0 WHERE user_id = ? AND LOWER(role) IN ("buyer", "agent")');
if (!$statement) {
    header("Location: userlist.php?msg=" . urlencode('User could not be deactivated.'));
    exit();
}
$statement->bind_param('i', $userId);
if ($statement->execute()) {
    addAuditLog((int)($_SESSION['uid'] ?? 0), 'USER_DEACTIVATED', 'Deactivated account ' . $userId);
    $msg = 'User account deactivated. They can no longer log in.';
} else {
    $msg = 'User account could not be deactivated.';
}
$statement->close();

// ✅ Redirect with message
header("Location: userlist.php?msg=" . urlencode($msg));
mysqli_close($con);
exit();
?>

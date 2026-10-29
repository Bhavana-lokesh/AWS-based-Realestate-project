<?php
session_start();
require_once 'config.php';
require_once '../auth.php';
require_once '../functions.php';
requireRole(['super_admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_SESSION['csrf_token'])
    || empty($_POST['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    http_response_code(403);
    exit('Invalid request.');
}

$propertyId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$propertyId || $propertyId < 1) {
    header('Location: propertyview.php?msg=' . urlencode('Choose a valid property.'));
    exit;
}

$statement = $con->prepare("UPDATE property_listings SET status = 'hold'
    WHERE property_id = ? AND status = 'available'");
if (!$statement) {
    throw new RuntimeException('Property deactivation could not be prepared.');
}
$statement->bind_param('i', $propertyId);
$statement->execute();
$deactivated = $statement->affected_rows === 1;
$statement->close();
if ($deactivated) {
    addAuditLog(currentUserId(), 'ADMIN_DEACTIVATE_PROPERTY', 'Deactivated property ' . $propertyId);
}

$message = $deactivated
    ? 'Property deactivated. Purchase and booking history was preserved.'
    : 'Property was not active or could not be found.';
header('Location: propertyview.php?msg=' . urlencode($message));
exit;

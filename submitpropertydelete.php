<?php
session_start();
require 'config.php';
require_once 'auth.php';
require_once 'functions.php';

requireRole(['agent']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid request.');
}

$propertyId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$propertyId || $propertyId < 1) {
    header('Location: my_properties.php?msg=' . urlencode('Choose a valid listing.'));
    exit;
}

$statement = $con->prepare("UPDATE property_listings SET status = 'hold'
    WHERE property_id = ? AND seller_id = ? AND (status IS NULL OR status <> 'hold')");
if (!$statement) {
    throw new RuntimeException('Listing could not be removed.');
}
$userId = currentUserId();
$statement->bind_param('ii', $propertyId, $userId);
if (!$statement->execute()) {
    throw new RuntimeException('Listing could not be removed.');
}
$wasRemoved = $statement->affected_rows === 1;
$statement->close();

if ($wasRemoved) {
    addAuditLog($userId, 'ARCHIVE_PROPERTY', 'Agent removed listing ' . $propertyId . ' from the marketplace');
    $message = 'Listing removed from the marketplace. Related property and transaction history was preserved.';
} else {
    $message = 'Listing not found, not owned by your account, or already removed.';
}

header('Location: my_properties.php?msg=' . urlencode($message));
exit;
?>

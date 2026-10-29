<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

requireRole(['buyer', 'agent']);
$userId = currentUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid request.');
}

$propertyId = filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT);
if (!$propertyId) {
    http_response_code(400);
    exit('A valid property is required.');
}

if (($_POST['action'] ?? 'add') === 'remove') {
    $stmt = $con->prepare('DELETE FROM favorite WHERE user_id = ? AND property_id = ?');
    $stmt->bind_param('ii', $userId, $propertyId);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $con->prepare("INSERT IGNORE INTO favorite (user_id, property_id, date)
        SELECT ?, property_id, CURDATE() FROM property_listings
        WHERE property_id = ? AND review_status = 'approved' AND status = 'available'");
    $stmt->bind_param('ii', $userId, $propertyId);
    $stmt->execute();
    $stmt->close();
}

header('Location: favorites.php');
exit;

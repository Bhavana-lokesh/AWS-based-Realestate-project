<?php
session_start();
require_once 'config.php';
require_once 'auth.php';
requireRole(['buyer', 'agent']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_SESSION['csrf_token'])
    || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid request.');
}

$propertyId = filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT);
$details = trim((string)($_POST['details'] ?? ''));
if (!$propertyId || mb_strlen($details) < 10 || mb_strlen($details) > 2000) {
    http_response_code(400);
    exit('Enter a valid property report between 10 and 2,000 characters.');
}

$propertyStatement = $con->prepare("SELECT property_id FROM property_listings
    WHERE property_id = ? AND status = 'available' AND review_status = 'approved' LIMIT 1");
if (!$propertyStatement) {
    throw new RuntimeException('Property report could not be prepared.');
}
$propertyStatement->bind_param('i', $propertyId);
$propertyStatement->execute();
$property = $propertyStatement->get_result()->fetch_assoc();
$propertyStatement->close();
if (!$property) {
    http_response_code(404);
    exit('Property not found.');
}

$userId = currentUserId();
$insert = $con->prepare("INSERT INTO property_reports (property_id, reporter_id, details)
    VALUES (?, ?, ?)");
if (!$insert) {
    throw new RuntimeException('Property report could not be saved.');
}
$insert->bind_param('iis', $propertyId, $userId, $details);
try {
    $insert->execute();
    $_SESSION['flash_message'] = 'Your property report has been submitted for review.';
} catch (mysqli_sql_exception $exception) {
    if ($exception->getCode() === 1062) {
        $_SESSION['flash_message'] = 'You have already reported this property.';
    } else {
        $insert->close();
        throw $exception;
    }
}
$insert->close();

header('Location: propertydetail.php?pid=' . $propertyId);
exit;
?>

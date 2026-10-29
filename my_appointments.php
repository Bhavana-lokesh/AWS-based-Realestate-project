<?php
session_start();
require_once 'auth.php';

requireRole(['seller', 'agent']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Use the appointment page to update an appointment.');
}

$propertyId = filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT);
if (!$propertyId || $propertyId < 1) {
    http_response_code(400);
    exit('A valid property is required.');
}

header('Location: view_appointments.php?property_id=' . $propertyId, true, 302);
exit;
?>

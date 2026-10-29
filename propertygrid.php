<?php
session_start();

$type = $_POST['type'] ?? $_GET['type'] ?? '';
$type = in_array($type, ['Sale', 'Rental'], true) ? $type : '';
$city = trim($_POST['city'] ?? $_GET['city'] ?? '');
$city = mb_substr($city, 0, 100);

$params = [];
if ($type !== '') {
    $params['type'] = $type;
}
if ($city !== '') {
    $params['city'] = $city;
}

header('Location: property.php' . ($params ? '?' . http_build_query($params) : ''));
exit;

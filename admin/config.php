<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../auth.php';
requireRole(['super_admin']);

// Existing admin pages still compare the legacy session role directly.
$_SESSION['role'] = 'admin';

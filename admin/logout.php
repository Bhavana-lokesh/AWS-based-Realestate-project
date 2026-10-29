<?php
session_start();
require_once __DIR__ . '/../auth.php';

if (currentUserRole() === 'super_admin') {
    unset($_SESSION['admin_email']);
    unset($_SESSION['role']);
    session_destroy();
    header("Location: ../login.php");
    exit();
}

header("Location: ../login.php");
exit();

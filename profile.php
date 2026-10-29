<?php
ini_set('session.cache_limiter','public');
session_cache_limiter(false);
session_start();
include("config.php");
if(!isset($_SESSION['email'])) {
    header("location:login.php");
    exit();
}
$uid = $_SESSION['uid'];
$userRole = $_SESSION['role'] ?? '';

// Ensure address column exists
$colCheck = $con->query("SHOW COLUMNS FROM `user` LIKE 'address'");
if ($colCheck && $colCheck->num_rows === 0) {
    $con->query("ALTER TABLE `user` ADD COLUMN `address` VARCHAR(500) DEFAULT NULL AFTER `phone_num`");
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$msg = "";

// Handle profile picture upload (separate form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_picture') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        http_response_code(403); exit('Invalid CSRF token');
    }
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['profile_image']['tmp_name']);
        if (isset($allowed[$mime])) {
            $ext = $allowed[$mime];
            $targetDir = __DIR__.'/uploads/profile/';
            if (!is_dir($targetDir)) { mkdir($targetDir, 0755, true); }
            array_map('unlink', glob($targetDir.$uid.'.*'));
            $targetPath = $targetDir.$uid.'.'.$ext;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetPath)) {
                $msg = '<div class="alert alert-success alert-dismissible fade show"><i class="fa fa-check-circle mr-2"></i>Profile picture updated successfully.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
            } else {
                $msg = '<div class="alert alert-danger alert-dismissible fade show"><i class="fa fa-exclamation-circle mr-2"></i>Failed to upload picture.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
            }
        } else {
            $msg = '<div class="alert alert-danger alert-dismissible fade show"><i class="fa fa-exclamation-circle mr-2"></i>Invalid image type. Use JPG, PNG, or WebP.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
        }
    }
}

// Handle remove picture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_picture') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        http_response_code(403); exit('Invalid CSRF token');
    }
    $targetDir = __DIR__.'/uploads/profile/';
    $removed = array_map('unlink', glob($targetDir.$uid.'.*'));
    $msg = '<div class="alert alert-success alert-dismissible fade show"><i class="fa fa-check-circle mr-2"></i>Profile picture removed.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        http_response_code(403); exit('Invalid CSRF token');
    }
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone_num'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '' || $email === '' || $phone === '') {
        $msg = '<div class="alert alert-danger alert-dismissible fade show"><i class="fa fa-exclamation-circle mr-2"></i>Name, Email and Phone are required.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
    } else {
        $dupStmt = $con->prepare('SELECT user_id FROM `user` WHERE email = ? AND user_id <> ?');
        $dupStmt->bind_param('si', $email, $uid);
        $dupStmt->execute();
        $dupStmt->store_result();
        if ($dupStmt->num_rows > 0) {
            $msg = '<div class="alert alert-danger alert-dismissible fade show"><i class="fa fa-exclamation-circle mr-2"></i>Email already in use by another account.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
        } else {
            $updStmt = $con->prepare('UPDATE `user` SET name = ?, email = ?, phone_num = ?, address = ? WHERE user_id = ?');
            $updStmt->bind_param('ssssi', $name, $email, $phone, $address, $uid);
            if ($updStmt->execute()) {
                $msg = '<div class="alert alert-success alert-dismissible fade show"><i class="fa fa-check-circle mr-2"></i>Profile updated successfully.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
                $_SESSION['email'] = $email;
            } else {
                $msg = '<div class="alert alert-danger alert-dismissible fade show"><i class="fa fa-exclamation-circle mr-2"></i>Failed to update profile.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
            }
        }
        $dupStmt->close();
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        http_response_code(403); exit('Invalid CSRF token');
    }
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($new === '' || strlen($new) < 6) {
        $msg = '<div class="alert alert-danger alert-dismissible fade show"><i class="fa fa-exclamation-circle mr-2"></i>New password must be at least 6 characters.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
    } elseif ($new !== $confirm) {
        $msg = '<div class="alert alert-danger alert-dismissible fade show"><i class="fa fa-exclamation-circle mr-2"></i>New passwords do not match.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
    } else {
        $pwdStmt = $con->prepare('SELECT password FROM `user` WHERE user_id = ?');
        $pwdStmt->bind_param('i', $uid);
        $pwdStmt->execute();
        $pwdStmt->bind_result($hashed);
        $pwdStmt->fetch();
        $pwdStmt->close();
        if (!password_verify($current, $hashed)) {
            $msg = '<div class="alert alert-danger alert-dismissible fade show"><i class="fa fa-exclamation-circle mr-2"></i>Current password is incorrect.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
        } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $updPwd = $con->prepare('UPDATE `user` SET password = ? WHERE user_id = ?');
            $updPwd->bind_param('si', $newHash, $uid);
            if ($updPwd->execute()) {
                $msg = '<div class="alert alert-success alert-dismissible fade show"><i class="fa fa-check-circle mr-2"></i>Password changed successfully.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
            } else {
                $msg = '<div class="alert alert-danger alert-dismissible fade show"><i class="fa fa-exclamation-circle mr-2"></i>Failed to change password.<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
            }
        }
    }
}

// Fetch current user data
$query = mysqli_query($con, "SELECT * FROM `user` WHERE user_id='$uid'");
$row = mysqli_fetch_assoc($query);

// Profile picture
$profileImg = '';
$imgDir = __DIR__.'/uploads/profile/';
if (is_dir($imgDir)) {
    $files = glob($imgDir.$uid.'.*');
    if ($files) { $profileImg = 'uploads/profile/' . basename($files[0]); }
}

// Role display
$roleDisplay = ucfirst($row['role'] ?? 'User');
$roleBadgeClass = 'profile-badge-buyer';
if (strtolower($row['role'] ?? '') === 'seller' || strtolower($row['role'] ?? '') === 'agent') {
    $roleDisplay = 'Agent';
    $roleBadgeClass = 'profile-badge-agent';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="shortcut icon" href="images/favicon.ico">
    <link href="https://fonts.googleapis.com/css?family=Muli:400,400i,500,600,700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Comfortaa:400,700" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="css/bootstrap-slider.css">
    <link rel="stylesheet" type="text/css" href="css/jquery-ui.css">
    <link rel="stylesheet" type="text/css" href="css/layerslider.css">
    <link rel="stylesheet" type="text/css" href="css/color.css">
    <link rel="stylesheet" type="text/css" href="css/owl.carousel.min.css">
    <link rel="stylesheet" type="text/css" href="css/font-awesome.min.css">
    <link rel="stylesheet" type="text/css" href="fonts/flaticon/flaticon.css">
    <link rel="stylesheet" type="text/css" href="css/style.css">
    <link rel="stylesheet" type="text/css" href="css/role-themes.css">
    <title>EstateScope – My Profile</title>
    <style>
        /* === Full-width layout fix === */
        #page-wrapper { width: 100%; overflow-x: hidden; }
        #page-wrapper > .row { width: 100%; max-width: 100%; margin: 0; }
        #page-wrapper > .row > * { flex: 0 0 100%; max-width: 100%; width: 100%; }

        .profile-page-wrapper {
            background: var(--role-soft, #f5f7fa);
            min-height: 60vh;
            padding: 40px 0 60px;
            width: 100%;
        }
        /* Profile Card */
        .profile-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
            overflow: hidden;
            border: 1px solid #e8ecf1;
        }
        .profile-card-header {
            background: linear-gradient(135deg, var(--role-primary, #2563eb), var(--role-primary-hover, #1d4ed8));
            padding: 32px 30px 70px;
            position: relative;
        }
        .profile-card-header h4 {
            color: #fff;
            font-family: 'Comfortaa', cursive;
            font-weight: 700;
            margin: 0;
            font-size: 22px;
        }
        .profile-card-header p {
            color: rgba(255,255,255,0.8);
            margin: 4px 0 0;
            font-size: 14px;
        }
        .profile-card-body {
            padding: 30px;
            position: relative;
        }
        /* Avatar area */
        .profile-avatar-section {
            display: flex;
            align-items: flex-end;
            margin-top: -65px;
            margin-bottom: 25px;
            padding: 0 10px;
        }
        .profile-avatar-wrapper {
            position: relative;
            flex-shrink: 0;
        }
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid #fff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.12);
            object-fit: cover;
            background: #e9ecef;
        }
        .profile-avatar-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid #fff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.12);
            background: linear-gradient(135deg, var(--role-primary, #2563eb), var(--role-primary-hover, #1d4ed8));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 42px;
            font-family: 'Comfortaa', cursive;
            font-weight: 700;
        }
        .profile-avatar-actions {
            margin-left: 20px;
            padding-bottom: 8px;
        }
        .profile-avatar-actions .btn {
            font-size: 13px;
            padding: 5px 14px;
            border-radius: 8px;
            margin-right: 6px;
        }
        .btn-role-primary {
            background: var(--role-primary, #2563eb);
            border-color: var(--role-primary, #2563eb);
            color: #fff;
        }
        .btn-role-primary:hover {
            background: var(--role-primary-hover, #1d4ed8);
            border-color: var(--role-primary-hover, #1d4ed8);
            color: #fff;
        }
        .btn-role-outline {
            background: transparent;
            border: 1px solid var(--role-primary, #2563eb);
            color: var(--role-primary, #2563eb);
        }
        .btn-role-outline:hover {
            background: var(--role-soft, #eff6ff);
            color: var(--role-primary-hover, #1d4ed8);
        }
        /* Role badge */
        .profile-role-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .profile-badge-agent {
            background: rgba(91,60,196,0.12);
            color: #5b3cc4;
        }
        .profile-badge-buyer {
            background: rgba(37,99,235,0.12);
            color: #2563eb;
        }
        /* Info grid */
        .profile-info-row {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f2f5;
        }
        .profile-info-row:last-child { border-bottom: none; }
        .profile-info-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--role-soft, #eff6ff);
            color: var(--role-primary, #2563eb);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            margin-right: 15px;
        }
        .profile-info-label {
            font-size: 12px;
            color: #8b95a2;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin: 0;
        }
        .profile-info-value {
            font-size: 15px;
            color: #1a2332;
            font-weight: 500;
            margin: 0;
        }
        /* Section card */
        .profile-section-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
            border: 1px solid #e8ecf1;
            margin-bottom: 24px;
        }
        .profile-section-header {
            padding: 22px 30px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .profile-section-header i {
            color: var(--role-primary, #2563eb);
            font-size: 18px;
        }
        .profile-section-header h5 {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            color: #1a2332;
            font-family: 'Comfortaa', cursive;
        }
        .profile-section-body {
            padding: 25px 30px 30px;
        }
        /* Form styling */
        .profile-form .form-group {
            margin-bottom: 20px;
        }
        .profile-form label {
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
        }
        .profile-form .form-control {
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
            padding: 10px 16px;
            font-size: 14px;
            height: auto;
            transition: all 0.2s ease;
            color: #1a2332;
        }
        .profile-form .form-control:focus {
            border-color: var(--role-primary, #2563eb);
            box-shadow: 0 0 0 3px var(--role-focus, rgba(37,99,235,0.15));
        }
        .profile-form .form-control[readonly] {
            background: #f8fafc;
            color: #64748b;
            cursor: default;
        }
        .profile-form-actions {
            display: flex;
            gap: 10px;
            margin-top: 8px;
        }
        .profile-form-actions .btn {
            padding: 10px 28px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
        }
        /* Stats mini cards */
        .profile-stat-card {
            background: var(--role-soft, #eff6ff);
            border-radius: 12px;
            padding: 18px 20px;
            text-align: center;
        }
        .profile-stat-card i {
            font-size: 22px;
            color: var(--role-primary, #2563eb);
            margin-bottom: 8px;
        }
        .profile-stat-card .stat-value {
            font-size: 22px;
            font-weight: 700;
            color: #1a2332;
        }
        .profile-stat-card .stat-label {
            font-size: 12px;
            color: #8b95a2;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        /* Password strength */
        .password-requirements {
            font-size: 12px;
            color: #8b95a2;
            margin-top: 4px;
        }
        /* Full-width footer fix */
        .footer-dark-row, footer, .footer-row { width: 100% !important; }
        /* Remove gray line between content and footer */
        .profile-page-wrapper { border-bottom: none !important; }
        .profile-page-wrapper + .footer-dark-row,
        .profile-page-wrapper + footer,
        .profile-page-wrapper + div { border-top: none !important; }
        .full-row { border: none !important; }
        #page-wrapper > .row > .banner-full-row { border-bottom: none !important; }
        #page-wrapper > .row { border: none !important; }

        /* Responsive */
        @media (min-width: 1200px) {
            .profile-page-wrapper .container { max-width: 1200px; }
        }
        @media (max-width: 991px) {
            .profile-card { margin-bottom: 24px; }
        }
        @media (max-width: 767px) {
            .profile-avatar-section { flex-direction: column; align-items: center; text-align: center; }
            .profile-avatar-actions { margin-left: 0; margin-top: 12px; }
            .profile-card-header { padding: 24px 20px 65px; }
            .profile-section-body { padding: 20px; }
            .profile-form-actions { flex-direction: column; }
            .profile-form-actions .btn { width: 100%; }
            .profile-page-wrapper { padding: 20px 0 40px; }
        }
        @media (max-width: 575px) {
            .profile-section-body { padding: 15px; }
            .profile-card-body { padding: 20px; }
        }
    </style>
</head>
<body>
<div id="page-wrapper">
    <div class="row">
        <?php include("include/header.php");?>
        <!-- Banner -->
        <div class="banner-full-row page-banner" style="background-image:url('images/breadcromb.jpg');">
            <div class="container">
                <div class="row">
                    <div class="col-md-6"><h2 class="page-name float-left text-white text-uppercase mt-1 mb-0"><b>My Profile</b></h2></div>
                    <div class="col-md-6">
                        <nav aria-label="breadcrumb" class="float-left float-md-right">
                            <ol class="breadcrumb bg-transparent m-0 p-0">
                                <li class="breadcrumb-item text-white"><a href="index.php">Home</a></li>
                                <li class="breadcrumb-item active">Profile</li>
                            </ol>
                        </nav>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Content -->
        <div class="profile-page-wrapper">
            <div class="container">
                <?php if (!empty($msg)) echo $msg; ?>

                <div class="row">
                    <!-- Left Column: Profile Card -->
                    <div class="col-lg-4 mb-4">
                        <div class="profile-card">
                            <div class="profile-card-header">
                                <h4><?= htmlspecialchars($row['name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></h4>
                                <p><i class="fa fa-envelope mr-1"></i> <?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="profile-card-body">
                                <div class="profile-avatar-section">
                                    <div class="profile-avatar-wrapper">
                                        <?php if ($profileImg): ?>
                                            <img src="<?= htmlspecialchars($profileImg, ENT_QUOTES, 'UTF-8') ?>" alt="Profile" class="profile-avatar">
                                        <?php else: ?>
                                            <div class="profile-avatar-placeholder">
                                                <?= strtoupper(substr($row['name'] ?? 'U', 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Picture upload/remove -->
                                <div class="d-flex flex-wrap gap-2 mb-4" style="gap:8px;">
                                    <form method="POST" enctype="multipart/form-data" class="d-inline" id="picUploadForm">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="upload_picture">
                                        <input type="file" name="profile_image" id="profile_image" accept="image/*" class="d-none" onchange="document.getElementById('picUploadForm').submit();">
                                        <button type="button" class="btn btn-sm btn-role-primary" onclick="document.getElementById('profile_image').click();">
                                            <i class="fa fa-camera mr-1"></i> <?= $profileImg ? 'Change' : 'Upload' ?> Photo
                                        </button>
                                    </form>
                                    <?php if ($profileImg): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="remove_picture">
                                        <button type="submit" class="btn btn-sm btn-role-outline" onclick="return confirm('Remove profile picture?');">
                                            <i class="fa fa-trash mr-1"></i> Remove
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>

                                <!-- User info summary -->
                                <div class="mb-3">
                                    <span class="profile-role-badge <?= $roleBadgeClass ?>">
                                        <i class="fa fa-user"></i> <?= htmlspecialchars($roleDisplay, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>

                                <div class="profile-info-row">
                                    <div class="profile-info-icon"><i class="fa fa-phone"></i></div>
                                    <div>
                                        <p class="profile-info-label">Phone</p>
                                        <p class="profile-info-value"><?= htmlspecialchars($row['phone_num'] ?? 'Not set', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </div>
                                <div class="profile-info-row">
                                    <div class="profile-info-icon"><i class="fa fa-map-marker"></i></div>
                                    <div>
                                        <p class="profile-info-label">Address</p>
                                        <p class="profile-info-value"><?= htmlspecialchars($row['address'] ?? 'Not set', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </div>
                                <div class="profile-info-row">
                                    <div class="profile-info-icon"><i class="fa fa-calendar"></i></div>
                                    <div>
                                        <p class="profile-info-label">Member Since</p>
                                        <p class="profile-info-value"><?= htmlspecialchars(date('M d, Y', strtotime($row['date_of_creation'] ?? 'now')), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Edit Form + Password -->
                    <div class="col-lg-8">
                        <!-- Edit Profile -->
                        <div class="profile-section-card mb-4">
                            <div class="profile-section-header">
                                <i class="fa fa-pencil"></i>
                                <h5>Edit Profile</h5>
                            </div>
                            <div class="profile-section-body">
                                <form method="POST" class="profile-form" id="editProfileForm">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_profile">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="name"><i class="fa fa-user mr-1"></i> Full Name</label>
                                                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($row['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="email"><i class="fa fa-envelope mr-1"></i> Email Address</label>
                                                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="phone_num"><i class="fa fa-phone mr-1"></i> Phone Number</label>
                                                <input type="text" class="form-control" id="phone_num" name="phone_num" value="<?= htmlspecialchars($row['phone_num'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="address"><i class="fa fa-map-marker mr-1"></i> Address / Location</label>
                                                <input type="text" class="form-control" id="address" name="address" value="<?= htmlspecialchars($row['address'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><i class="fa fa-id-badge mr-1"></i> Role</label>
                                                <input type="text" class="form-control" readonly value="<?= htmlspecialchars($roleDisplay, ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><i class="fa fa-calendar mr-1"></i> Account Created</label>
                                                <input type="text" class="form-control" readonly value="<?= htmlspecialchars(date('M d, Y \a\t h:i A', strtotime($row['date_of_creation'] ?? 'now')), ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="profile-form-actions">
                                        <button type="submit" class="btn btn-role-primary">
                                            <i class="fa fa-check mr-1"></i> Save Changes
                                        </button>
                                        <a href="profile.php" class="btn btn-role-outline">
                                            <i class="fa fa-times mr-1"></i> Cancel
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Change Password -->
                        <div class="profile-section-card">
                            <div class="profile-section-header">
                                <i class="fa fa-lock"></i>
                                <h5>Change Password</h5>
                            </div>
                            <div class="profile-section-body">
                                <form method="POST" class="profile-form" id="changePasswordForm">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label for="current_password"><i class="fa fa-key mr-1"></i> Current Password</label>
                                                <input type="password" class="form-control" id="current_password" name="current_password" required placeholder="Enter your current password">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="new_password"><i class="fa fa-lock mr-1"></i> New Password</label>
                                                <input type="password" class="form-control" id="new_password" name="new_password" required placeholder="Min 6 characters">
                                                <span class="password-requirements">Minimum 6 characters</span>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="confirm_password"><i class="fa fa-lock mr-1"></i> Confirm New Password</label>
                                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required placeholder="Re-enter new password">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="profile-form-actions">
                                        <button type="submit" class="btn btn-role-primary">
                                            <i class="fa fa-shield mr-1"></i> Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php include("include/footer.php");?>
    </div>
</div>
<script src="js/jquery.min.js"></script>
<script src="js/greensock.js"></script>
<script src="js/layerslider.transitions.js"></script>
<script src="js/layerslider.kreaturamedia.jquery.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/owl.carousel.min.js"></script>
<script src="js/tmpl.js"></script>
<script src="js/jquery.dependClass-0.1.js"></script>
<script src="js/draggable-0.1.js"></script>
<script src="js/jquery.slider.js"></script>
<script src="js/wow.js"></script>
<script src="js/custom.js"></script>
<script>
// Password match validation
document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
    var np = document.getElementById('new_password').value;
    var cp = document.getElementById('confirm_password').value;
    if (np.length < 6) {
        e.preventDefault();
        alert('Password must be at least 6 characters long.');
        return;
    }
    if (np !== cp) {
        e.preventDefault();
        alert('New passwords do not match.');
    }
});
</script>
</body>
</html>

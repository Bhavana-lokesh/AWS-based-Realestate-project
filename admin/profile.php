<?php
session_start();
require("config.php");

// ✅ Secure access: allow only admins
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// ✅ Get admin email from session
$admin_email = $_SESSION['admin_email'];

// ✅ Fetch admin details using email
$sql = "SELECT * FROM user WHERE email = '$admin_email'";
$result = mysqli_query($con, $sql);
$row = mysqli_fetch_array($result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <title>LM HOMES | Profile</title>

    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=20261024">
    <link rel="stylesheet" href="assets/css/feathericon.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<!-- Header -->
<?php include("header.php"); ?>
<!-- /Header -->

<!-- Page Wrapper -->
<div class="page-wrapper">
    <div class="content container-fluid">

        <!-- Page Header -->
        <div class="page-header">
            <div class="row">
                <div class="col">
                    <h3 class="page-title">Profile</h3>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Profile</li>
                    </ul>
                </div>
            </div>
        </div>
        <!-- /Page Header -->

        <div class="row">
            <div class="col-md-12">
                <div class="profile-header">
                    <div class="row align-items-center">
                        <div class="col-auto profile-image">
                            <a href="#"><img class="rounded-circle" alt="User Image" src="assets/img/profiles/avatar-01.png"></a>
                        </div>
                        <div class="col ml-md-n2 profile-user-info">
                            <h4 class="user-name mb-2 text-uppercase"><?php echo $row['name']; ?></h4>
                            <h6 class="text-muted"><?php echo $row['email']; ?></h6>
                            <div class="about-text"></div>
                        </div>
                    </div>
                </div>

                <div class="profile-menu">
                    <ul class="nav nav-tabs nav-tabs-solid">
                        <li class="nav-item">
                            <a class="nav-link active" data-toggle="tab" href="#per_details_tab">About</a>
                        </li>
                    </ul>
                </div>

                <div class="tab-content profile-tab-cont">
                    <div class="tab-pane fade show active" id="per_details_tab">
                        <div class="row">
                            <div class="col-lg-9">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="row">
                                            <p class="col-sm-3 text-muted text-sm-right mb-0 mb-sm-3">Name</p>
                                            <p class="col-sm-9"><?php echo $row['name']; ?></p>
                                        </div>
                                        <div class="row">
                                            <p class="col-sm-3 text-muted text-sm-right mb-0 mb-sm-3">Email ID</p>
                                            <p class="col-sm-9"><a href="#"><?php echo $row['email']; ?></a></p>
                                        </div>
                                        <div class="row">
                                            <p class="col-sm-3 text-muted text-sm-right mb-0 mb-sm-3">Mobile</p>
                                            <p class="col-sm-9"><?php echo $row['phone_num']; ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-3">
                                <div class="card">
                                    <div class="card-body">
                                        <h5 class="card-title d-flex justify-content-between">
                                            <span>Account Status</span>
                                        </h5>
                                        <button class="btn btn-success" type="button"><i class="fe fe-check-verified"></i> Active</button>
                                    </div>
                                </div>
                            </div>
                        </div> <!-- /Personal Details -->
                    </div>
                </div> <!-- /tab content -->
            </div>
        </div>
    </div>
</div>
<!-- /Page Wrapper -->

<!-- Scripts -->
<script src="assets/js/jquery-3.2.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/plugins/slimscroll/jquery.slimscroll.min.js"></script>
<script src="assets/js/script.js?v=20261024-2"></script>

</body>
</html>

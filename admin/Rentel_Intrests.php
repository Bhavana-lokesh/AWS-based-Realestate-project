<?php
require("config.php");
session_start();

// Access Control for Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Fetch Rental Interests with Buyer, Seller, Property Details
$query = "
    SELECT 
        ri.interest_id, 
        ri.status AS rental_status, 
        ri.payment_status, 
        ri.payment_method, 
        ri.payment_date, 
        ri.interest_date,
        b.user_id AS buyer_id, 
        b.name AS buyer_name, 
        s.user_id AS seller_id, 
        s.name AS seller_name, 
        p.property_id, 
        p.title AS property_title, 
        p.price AS rental_price
    FROM rental_interest ri
    JOIN user b ON ri.buyer_id = b.user_id
    JOIN property_listings p ON ri.property_id = p.property_id
    JOIN user s ON p.seller_id = s.user_id
    ORDER BY ri.interest_id DESC
";
$result = mysqli_query($con, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <title>LM Homes - View Rental Interests</title>

    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=20261024">
    <link rel="stylesheet" href="assets/css/feathericon.min.css">
    <link rel="stylesheet" href="assets/plugins/morris/morris.css">
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
                <div class="col-sm-12">
                    <h3 class="page-title">🏠 All Rental Interests</h3>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">View Rental Interests</li>
                    </ul>
                </div>
            </div>
        </div>
        <!-- /Page Header -->

        <!-- Rental Interests Table -->
        <div class="row">
            <div class="col-md-12">
                <div class="card card-table">
                    <div class="card-header">
                        <h4 class="card-title">📋 Rental Interests List</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-center mb-0">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Interest ID</th>
                                        <th>Buyer Name (ID)</th>
                                        <th>Property Title (ID)</th>
                                        <th>Rental Price</th>
                                        <th>Agent / Listing Owner (ID)</th>
                                        <th>Interest Date</th>
                                        <th>Rental Status</th>
                                        <th>Payment Status</th>
                                        <th>Payment Method</th>
                                        <th>Payment Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                    <tr>
                                        <td><?= $row['interest_id'] ?></td>
                                        <td><?= htmlspecialchars($row['buyer_name']) . " (ID: " . $row['buyer_id'] . ")" ?></td>
                                        <td><?= htmlspecialchars($row['property_title']) . " (ID: " . $row['property_id'] . ")" ?></td>
                                        <td>₹<?= number_format($row['rental_price']) ?></td>
                                        <td><?= htmlspecialchars($row['seller_name']) . " (ID: " . $row['seller_id'] . ")" ?></td>
                                        <td><?= date('d-m-Y', strtotime($row['interest_date'])) ?></td>
                                        <td><strong><?= $row['rental_status'] ?></strong></td>
                                        <td><?= $row['payment_status'] ?></td>
                                        <td><?= $row['payment_method'] ?? 'N/A' ?></td>
                                        <td><?= $row['payment_date'] ?? 'N/A' ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /Rental Interests Table -->

    </div>
</div>
<!-- /Page Wrapper -->

<!-- Scripts -->
<script src="assets/js/jquery-3.2.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/plugins/slimscroll/jquery.slimscroll.min.js"></script>
<script src="assets/plugins/raphael/raphael.min.js"></script>
<script src="assets/plugins/morris/morris.min.js"></script>
<script src="assets/js/chart.morris.js?v=20261024"></script>
<script src="assets/js/script.js?v=20261024-2"></script>

</body>
</html>

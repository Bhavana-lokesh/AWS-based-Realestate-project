<?php
require("config.php");
session_start();

// Access Control
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Fetch purchase details (Completed Payments)
$query = "
    SELECT 
        pay.payment_id, 
        pay.amount_paid, 
        pay.payment_method, 
        pay.payment_date, 
        pay.status AS payment_status, 
        o.offer_id, 
        o.offer_price, 
        b.user_id AS buyer_id, 
        b.name AS buyer_name, 
        s.user_id AS seller_id, 
        s.name AS seller_name, 
        p.property_id, 
        p.title AS property_title, 
        p.price AS actual_price
    FROM payment pay
    JOIN offer o ON pay.offer_id = o.offer_id
    JOIN property_listings p ON o.property_id = p.property_id
    JOIN user b ON o.buyer_id = b.user_id
    JOIN user s ON p.seller_id = s.user_id
    WHERE pay.status = 'Completed'
    ORDER BY pay.payment_date DESC
";
$result = mysqli_query($con, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <title>LM Homes - Purchased Properties</title>

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
                    <h3 class="page-title">🏠 Purchased Property Records</h3>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Purchased Properties</li>
                    </ul>
                </div>
            </div>
        </div>
        <!-- /Page Header -->

        <!-- Purchase Table -->
        <div class="row">
            <div class="col-md-12">
                <div class="card card-table">
                    <div class="card-header">
                        <h4 class="card-title">🧾 Property Purchase History</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-center mb-0">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Buyer Name (ID)</th>
                                        <th>Property Title (ID)</th>
                                        <th>Actual Price</th>
                                        <th>Purchased Price</th>
                                        <th>Agent / Listing Owner (ID)</th>
                                        <th>Payment Method</th>
                                        <th>Payment Date</th>
                                        <th>Payment Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['buyer_name']) . " (ID: " . $row['buyer_id'] . ")" ?></td>
                                        <td><?= htmlspecialchars($row['property_title']) . " (ID: " . $row['property_id'] . ")" ?></td>
                                        <td>₹<?= number_format($row['actual_price']) ?></td>
                                        <td>₹<?= number_format($row['amount_paid']) ?></td>
                                        <td><?= htmlspecialchars($row['seller_name']) . " (ID: " . $row['seller_id'] . ")" ?></td>
                                        <td><?= htmlspecialchars($row['payment_method']) ?></td>
                                        <td><?= $row['payment_date'] ?></td>
                                        <td><strong><?= $row['payment_status'] ?></strong></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /Purchase Table -->

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

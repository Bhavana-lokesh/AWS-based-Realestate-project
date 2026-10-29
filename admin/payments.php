<?php
session_start();
require_once 'config.php';
require_once '../auth.php';
requireRole(['super_admin']);

$payments = $con->query("SELECT pay.payment_id, pay.amount_paid, pay.payment_method, pay.payment_date,
    pay.status, pay.payment_type, p.property_id, p.title,
    COALESCE(sale_buyer.name, rental_buyer.name, 'Buyer not recorded') AS buyer_name,
    COALESCE(sale_buyer.email, rental_buyer.email, '') AS buyer_email
    FROM payment pay
    LEFT JOIN offer sale_offer ON sale_offer.offer_id = pay.offer_id
    LEFT JOIN user sale_buyer ON sale_buyer.user_id = sale_offer.buyer_id
    LEFT JOIN rental_interest rental ON rental.interest_id = pay.rental_interest_id
    LEFT JOIN user rental_buyer ON rental_buyer.user_id = rental.buyer_id
    LEFT JOIN property_listings p ON p.property_id = COALESCE(sale_offer.property_id, rental.property_id)
    ORDER BY pay.payment_date DESC, pay.payment_id DESC");
if (!$payments) {
    throw new RuntimeException('Payment records could not be loaded.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payments | EstateScope Super Admin</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=20261024">
    <link rel="stylesheet" href="assets/css/feathericon.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="page-wrapper">
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-title">Payment records</h1>
            <ol class="breadcrumb"><li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li><li class="breadcrumb-item active">Payments</li></ol>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Recorded payments</h2><p class="text-muted mb-0">Read-only payment monitoring; this page does not initiate or modify payments.</p></div>
            <div class="card-body table-responsive">
                <?php if ($payments->num_rows === 0): ?>
                    <p>No payment records are available.</p>
                <?php else: ?>
                    <table class="table table-hover">
                        <thead><tr><th>Payment</th><th>Property</th><th>Buyer</th><th>Type</th><th>Amount</th><th>Method</th><th>Date</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php while ($payment = $payments->fetch_assoc()): ?>
                            <tr>
                                <td>#<?= (int)$payment['payment_id'] ?></td>
                                <td><?php if ($payment['property_id']): ?><a href="../propertydetail.php?pid=<?= (int)$payment['property_id'] ?>"><?= htmlspecialchars($payment['title'] ?? 'View property', ENT_QUOTES, 'UTF-8') ?></a><?php else: ?>Property not recorded<?php endif; ?></td>
                                <td><?= htmlspecialchars($payment['buyer_name'], ENT_QUOTES, 'UTF-8') ?><?php if ($payment['buyer_email'] !== ''): ?><br><a href="mailto:<?= htmlspecialchars($payment['buyer_email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($payment['buyer_email'], ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?></td>
                                <td><?= htmlspecialchars($payment['payment_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $payment['amount_paid'] !== null ? '₹' . number_format((float)$payment['amount_paid'], 2) : '—' ?></td>
                                <td><?= htmlspecialchars($payment['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($payment['payment_date'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($payment['status'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/jquery-3.2.1.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/js/script.js?v=20261024-2"></script>
</body>
</html>

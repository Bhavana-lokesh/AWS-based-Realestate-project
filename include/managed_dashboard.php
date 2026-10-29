<?php
requireRole(['agent']);
$userId = currentUserId();
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$title = 'Agent workspace';
$description = 'Review activity for listings owned by your account. Agent assignments and commissions are not configured.';

$countStatement = $con->prepare("SELECT COUNT(*) AS total,
    COALESCE(SUM(status = 'available'), 0) AS available,
    COALESCE(SUM(status = 'hold'), 0) AS on_hold,
    COALESCE(SUM(status = 'sold'), 0) AS sold
    FROM property_listings WHERE seller_id = ?");
$countStatement->bind_param('i', $userId);
$countStatement->execute();
$listingStats = $countStatement->get_result()->fetch_assoc();
$countStatement->close();

$activityCounts = [];
foreach ([
    'Appointments' => "SELECT COUNT(*) FROM appointment a JOIN property_listings p ON p.property_id = a.property_id WHERE p.seller_id = ?",
    'Sale offers' => "SELECT COUNT(*) FROM offer o JOIN property_listings p ON p.property_id = o.property_id WHERE p.seller_id = ?",
    'Rental inquiries' => "SELECT COUNT(*) FROM rental_interest ri JOIN property_listings p ON p.property_id = ri.property_id WHERE p.seller_id = ?",
] as $label => $sql) {
    $statement = $con->prepare($sql);
    $statement->bind_param('i', $userId);
    $statement->execute();
    $activityCounts[$label] = (int)$statement->get_result()->fetch_row()[0];
    $statement->close();
}
$assignedCountStatement = $con->prepare('SELECT COUNT(*) FROM appointment WHERE assigned_agent_id = ?');
if (!$assignedCountStatement) {
    throw new RuntimeException('Assigned appointment count could not be prepared.');
}
$assignedCountStatement->bind_param('i', $userId);
$assignedCountStatement->execute();
$activityCounts['Assigned appointments'] = (int)$assignedCountStatement->get_result()->fetch_row()[0];
$assignedCountStatement->close();
$appointmentStatement = $con->prepare("SELECT a.appointment_id, a.time, a.status,
    a.property_id, p.title, u.name AS buyer_name
    FROM appointment a
    JOIN property_listings p ON p.property_id = a.property_id
    JOIN user u ON u.user_id = a.user_id
    WHERE p.seller_id = ? OR a.assigned_agent_id = ?
    ORDER BY a.time DESC LIMIT 5");
if (!$appointmentStatement) {
    throw new RuntimeException('Appointments could not be loaded.');
}
$appointmentStatement->bind_param('ii', $userId, $userId);
$appointmentStatement->execute();
$appointments = $appointmentStatement->get_result();
$appointmentStatement->close();
$pendingOffersStatement = $con->prepare("SELECT o.offer_id, o.offer_price, o.offer_date,
        p.property_id, p.title, p.price AS asking_price, u.name AS buyer_name
    FROM offer o
    JOIN property_listings p ON p.property_id = o.property_id
    JOIN user u ON u.user_id = o.buyer_id
    WHERE p.seller_id = ? AND p.property_type = 'Sale'
        AND p.status = 'available' AND o.status = 'Pending'
    ORDER BY o.offer_date ASC, o.offer_id ASC LIMIT 20");
if (!$pendingOffersStatement) {
    throw new RuntimeException('Pending offers could not be prepared.');
}
$pendingOffersStatement->bind_param('i', $userId);
$pendingOffersStatement->execute();
$pendingOffers = $pendingOffersStatement->get_result();
$pendingOffersStatement->close();
$paymentStatement = $con->prepare("SELECT pay.payment_id, pay.amount_paid, pay.payment_date,
    pay.payment_type, p.property_id, p.title, b.name AS buyer_name
    FROM payment pay
    LEFT JOIN offer o ON o.offer_id = pay.offer_id
    LEFT JOIN rental_interest ri ON ri.interest_id = pay.rental_interest_id
    JOIN property_listings p ON p.property_id = COALESCE(o.property_id, ri.property_id)
    JOIN user b ON b.user_id = COALESCE(o.buyer_id, ri.buyer_id)
    WHERE pay.status = 'Completed' AND p.seller_id = ?
    ORDER BY pay.payment_date DESC, pay.payment_id DESC LIMIT 5");
if (!$paymentStatement) {
    throw new RuntimeException('Payment history could not be prepared.');
}
$paymentStatement->bind_param('i', $userId);
$paymentStatement->execute();
$payments = $paymentStatement->get_result();
$paymentStatement->close();
$paymentStatsStatement = $con->prepare("SELECT COUNT(*), COALESCE(SUM(pay.amount_paid), 0)
    FROM payment pay
    LEFT JOIN offer o ON o.offer_id = pay.offer_id
    LEFT JOIN rental_interest ri ON ri.interest_id = pay.rental_interest_id
    JOIN property_listings p ON p.property_id = COALESCE(o.property_id, ri.property_id)
    WHERE pay.status = 'Completed' AND p.seller_id = ?");
if (!$paymentStatsStatement) {
    throw new RuntimeException('Payment statistics could not be prepared.');
}
$paymentStatsStatement->bind_param('i', $userId);
$paymentStatsStatement->execute();
[$completedPurchaseCount, $completedSalesTotal] = $paymentStatsStatement->get_result()->fetch_row();
$paymentStatsStatement->close();
$activityCounts['Completed purchases'] = (int)$completedPurchaseCount;
$activityCounts['Recorded sales'] = '₹' . number_format((float)$completedSalesTotal, 0);

$listingStatement = $con->prepare("SELECT p.property_id, p.title, p.price, p.location, p.property_type,
    p.status, p.review_status, p.rejection_reason,
    (SELECT COUNT(*) FROM appointment a WHERE a.property_id = p.property_id) AS appointment_count,
    (SELECT COUNT(*) FROM offer o WHERE o.property_id = p.property_id) AS offer_count,
    (SELECT COUNT(*) FROM rental_interest ri WHERE ri.property_id = p.property_id) AS inquiry_count
    FROM property_listings p WHERE p.seller_id = ? ORDER BY p.created_at DESC LIMIT 5");
$listingStatement->bind_param('i', $userId);
$listingStatement->execute();
$listings = $listingStatement->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | EstateScope</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/role-themes.css">
    <link rel="stylesheet" href="css/marketplace.css">
</head>
<body class="market-page" data-role="agent">
<?php include __DIR__ . '/header.php'; ?>
<div class="market-workspace" data-role="agent">
    <div class="market-workspace-main">
<main class="market-section market-dashboard" id="overview">
    <header class="market-dashboard-heading">
        <div>
            <p class="market-eyebrow">Relationships & listings</p>
            <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a class="market-button market-dashboard-primary" href="submitproperty.php">Create a listing</a>
    </header>

    <div class="market-dashboard-stats" aria-label="Listing overview">
        <div><strong><?= (int)$listingStats['total'] ?></strong><span>My listings</span></div>
        <div><strong><?= (int)$listingStats['available'] ?></strong><span>Available</span></div>
        <div><strong><?= (int)$listingStats['on_hold'] ?></strong><span>On hold</span></div>
        <div><strong><?= (int)$listingStats['sold'] ?></strong><span>Sold</span></div>
    </div>

    <div class="market-dashboard-stats market-dashboard-activity" aria-label="Listing activity">
        <?php foreach ($activityCounts as $label => $count): ?>
            <div><strong><?= $count ?></strong><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></div>
        <?php endforeach; ?>
    </div>

    <section class="market-dashboard-panel mb-4" id="offers">
        <h2>Pending sale offers</h2>
        <?php if ($pendingOffers->num_rows === 0): ?>
            <p class="market-empty-inline">There are no pending offers on your available sale listings.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead><tr><th>Property</th><th>Buyer</th><th>Offer</th><th>Asking price</th><th>Submitted</th><th>Decision</th></tr></thead>
                    <tbody>
                    <?php while ($offer = $pendingOffers->fetch_assoc()): ?>
                        <tr>
                            <td><a href="propertydetail.php?pid=<?= (int)$offer['property_id'] ?>"><?= htmlspecialchars($offer['title'], ENT_QUOTES, 'UTF-8') ?></a></td>
                            <td><?= htmlspecialchars($offer['buyer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>₹<?= number_format((float)$offer['offer_price']) ?></td>
                            <td>₹<?= number_format((float)$offer['asking_price']) ?></td>
                            <td><?= htmlspecialchars(date('d M Y', strtotime($offer['offer_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <form method="post" action="view_offers.php?property_id=<?= (int)$offer['property_id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="offer_id" value="<?= (int)$offer['offer_id'] ?>">
                                    <input type="hidden" name="return" value="dashboard">
                                    <button type="submit" name="accept" class="btn btn-success btn-sm">Accept</button>
                                    <button type="submit" name="reject" class="btn btn-danger btn-sm">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <p class="market-empty-inline">Accepting an offer puts the property on hold and rejects its other pending offers. It does not record payment or complete a sale.</p>
        <?php endif; ?>
    </section>

    <section class="market-dashboard-panel mb-4">
        <h2>Appointments for your listings and assigned to you</h2>
        <?php if ($appointments->num_rows === 0): ?>
            <p class="market-empty-inline">No appointments have been requested for or assigned to you.</p>
        <?php else: ?>
            <ul><?php while ($appointment = $appointments->fetch_assoc()): ?><li>
                <a href="view_appointments.php?property_id=<?= (int)$appointment['property_id'] ?>"><?= htmlspecialchars($appointment['title'], ENT_QUOTES, 'UTF-8') ?></a>
                <span><?= htmlspecialchars($appointment['buyer_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(date('d M Y, H:i', strtotime($appointment['time'])), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($appointment['status'], ENT_QUOTES, 'UTF-8') ?></span>
            </li><?php endwhile; ?></ul>
        <?php endif; ?>
    </section>

    <section class="market-dashboard-panel mb-4">
        <h2>Completed purchases</h2>
        <?php if ($payments->num_rows === 0): ?>
            <p class="market-empty-inline">No completed payment records are linked to your listings.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead><tr><th>Property</th><th>Buyer</th><th>Type</th><th>Amount</th><th>Payment date</th></tr></thead>
                    <tbody><?php while ($payment = $payments->fetch_assoc()): ?><tr>
                        <td><a href="propertydetail.php?pid=<?= (int)$payment['property_id'] ?>"><?= htmlspecialchars($payment['title'], ENT_QUOTES, 'UTF-8') ?></a></td>
                        <td><?= htmlspecialchars($payment['buyer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($payment['payment_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>₹<?= number_format((float)$payment['amount_paid'], 2) ?></td>
                        <td><?= htmlspecialchars($payment['payment_date'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr><?php endwhile; ?></tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="market-dashboard-list" id="activity">
        <div class="market-section-heading">
            <div>
                <h2>Listings linked to your account</h2>
                <p>Only records where your account is the listing owner are shown.</p>
            </div>
            <a class="market-text-link" href="my_properties.php">Manage all listings</a>
        </div>
        <?php if ($listings->num_rows === 0): ?>
            <div class="market-empty-state">
                <h3>No listings yet</h3>
                <p>Listings created by your account will appear here.</p>
                <a class="market-button" href="submitproperty.php">Create a listing</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr><th>Property</th><th>Type</th><th>Price</th><th>Status</th><th>Review</th><th>Appointments</th><th>Offers</th><th>Rental inquiries</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php while ($listing = $listings->fetch_assoc()): ?>
                        <tr>
                            <td><a href="propertydetail.php?pid=<?= (int)$listing['property_id'] ?>"><?= htmlspecialchars($listing['title'], ENT_QUOTES, 'UTF-8') ?></a><br><small><?= htmlspecialchars($listing['location'] ?? '', ENT_QUOTES, 'UTF-8') ?></small></td>
                            <td><?= htmlspecialchars($listing['property_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>₹<?= number_format((float)$listing['price']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($listing['status']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(ucfirst($listing['review_status']), ENT_QUOTES, 'UTF-8') ?><?= $listing['review_status'] === 'rejected' ? ': ' . htmlspecialchars($listing['rejection_reason'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?></td>
                            <td><a href="view_appointments.php?property_id=<?= (int)$listing['property_id'] ?>"><?= (int)$listing['appointment_count'] ?></a></td>
                            <td><?php if (strtolower($listing['property_type']) === 'sale'): ?><a href="view_offers.php?property_id=<?= (int)$listing['property_id'] ?>"><?= (int)$listing['offer_count'] ?></a><?php else: ?>—<?php endif; ?></td>
                            <td><?php if (strtolower($listing['property_type']) === 'rental'): ?><a href="view_rental_interest.php?property_id=<?= (int)$listing['property_id'] ?>"><?= (int)$listing['inquiry_count'] ?></a><?php else: ?>—<?php endif; ?></td>
                            <td><a href="edit_property.php?property_id=<?= (int)$listing['property_id'] ?>">Edit</a></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <p class="market-dashboard-note">No agent assignment, commission, or independent performance-tracking model is configured. Counts above reflect only listings owned by this account and their stored activity.</p>
</main>
    </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>

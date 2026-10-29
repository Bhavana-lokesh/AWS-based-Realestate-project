<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

requireRole(['seller', 'agent']);
$role = currentUserRole();
$user_id = currentUserId();

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

$listingStatement = $con->prepare("SELECT p.property_id, p.title, p.price, p.location, p.property_type,
    p.status, p.review_status, p.rejection_reason, p.bedrooms, p.bathrooms, p.size_sqft, p.created_at,
    (SELECT status FROM property_change_requests WHERE property_id = p.property_id AND agent_id = p.seller_id ORDER BY request_id DESC LIMIT 1) AS change_request_status,
    (SELECT rejection_reason FROM property_change_requests WHERE property_id = p.property_id AND agent_id = p.seller_id ORDER BY request_id DESC LIMIT 1) AS change_request_rejection_reason,
    (SELECT image_url FROM property_image WHERE property_id = p.property_id LIMIT 1) AS image_url
    FROM property_listings p WHERE p.seller_id = ? ORDER BY p.created_at DESC");
$listingStatement->bind_param('i', $user_id);
$listingStatement->execute();
$result = $listingStatement->get_result();
$listings = $result->fetch_all(MYSQLI_ASSOC);
$listingCount = count($listings);
$availableCount = count(array_filter($listings, static fn($listing) => $listing['status'] === 'available'));
$inactiveCount = $listingCount - $availableCount;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css?family=Muli:400,400i,500,600,700&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css?family=Comfortaa:400,700" rel="stylesheet">

<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>My properties | EstateScope</title>
<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
<link rel="stylesheet" type="text/css" href="css/bootstrap-slider.css">
<link rel="stylesheet" type="text/css" href="css/jquery-ui.css">
<link rel="stylesheet" type="text/css" href="css/layerslider.css">
<link rel="stylesheet" type="text/css" href="css/color.css" id="color-change">
<link rel="stylesheet" type="text/css" href="css/owl.carousel.min.css">
<link rel="stylesheet" type="text/css" href="css/font-awesome.min.css">
<link rel="stylesheet" type="text/css" href="fonts/flaticon/flaticon.css">
<link rel="stylesheet" type="text/css" href="css/style.css">
<link rel="stylesheet" href="css/marketplace.css">
</head>
<body class="market-page" data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">

<?php include("include/header.php"); ?>

<main class="market-section market-dashboard">
    <header class="market-dashboard-heading">
        <div>
            <p class="market-eyebrow">Listing workspace</p>
            <h1>My properties</h1>
            <p>Manage your listings and review activity by property.</p>
        </div>
        <a class="market-button market-dashboard-primary" href="submitproperty.php">List a property</a>
    </header>

    <div class="market-dashboard-stats" aria-label="Listing totals">
        <div><strong><?= $listingCount ?></strong><span>Total listings</span></div>
        <div><strong><?= $availableCount ?></strong><span>Available</span></div>
        <div><strong><?= $inactiveCount ?></strong><span>Other status</span></div>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
        <div class="market-feedback" role="status"><?= htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($listingCount === 0): ?>
        <div class="market-empty-state market-dashboard-empty">
            <h2>No listings yet</h2>
            <p>Your properties will appear here after you publish a listing.</p>
            <a class="market-button" href="submitproperty.php">Create your first listing</a>
        </div>
    <?php else: ?>
        <div class="market-listing-grid">
            <?php foreach ($listings as $row): ?>
                <article class="market-listing-card">
                    <?php if (!empty($row['image_url'])): ?>
                        <img class="market-card-image" src="admin/property/<?= htmlspecialchars(basename($row['image_url']), ENT_QUOTES, 'UTF-8') ?>" alt="Photo for <?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <div class="market-no-image">No photos uploaded</div>
                    <?php endif; ?>
                    <div class="market-card-body">
                        <div class="market-dashboard-card-status">
                            <span class="market-card-type"><?= htmlspecialchars($row['property_type'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="market-status-label"><?= htmlspecialchars(ucfirst($row['status']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <p class="market-status-note">Listing review: <?= htmlspecialchars(ucfirst($row['review_status']), ENT_QUOTES, 'UTF-8') ?><?= $row['review_status'] === 'rejected' ? ' · ' . htmlspecialchars($row['rejection_reason'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?></p>
                        <?php if ($row['change_request_status'] !== null): ?>
                            <?php
                            $changeRequestStatus = match ($row['change_request_status']) {
                                'pending' => 'Pending Approval',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                                default => ucfirst($row['change_request_status']),
                            };
                            ?>
                            <p class="market-status-note">Latest change request: <?= htmlspecialchars($changeRequestStatus, ENT_QUOTES, 'UTF-8') ?><?= $row['change_request_status'] === 'rejected' && !empty($row['change_request_rejection_reason']) ? ' · ' . htmlspecialchars($row['change_request_rejection_reason'], ENT_QUOTES, 'UTF-8') : '' ?></p>
                        <?php endif; ?>
                        <h2 class="market-card-title"><a href="propertydetail.php?pid=<?= (int)$row['property_id'] ?>"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></a></h2>
                        <p class="market-card-location"><?= htmlspecialchars($row['location'] ?? 'Location not listed', ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="market-card-price">₹<?= number_format((float)$row['price']) ?></div>
                        <div class="market-card-specs">
                            <span><?= (int)$row['bedrooms'] ?> beds</span>
                            <span><?= (int)$row['bathrooms'] ?> baths</span>
                            <span><?= number_format((int)$row['size_sqft']) ?> sqft</span>
                        </div>
                        <div class="market-dashboard-card-actions">
                            <a href="edit_property.php?property_id=<?= (int)$row['property_id'] ?>">Edit listing</a>
                            <a href="view_appointments.php?property_id=<?= (int)$row['property_id'] ?>">Appointments</a>
                            <?php if (strtolower($row['property_type']) === 'rental'): ?>
                                <a href="view_rental_interest.php?property_id=<?= (int)$row['property_id'] ?>">Rental inquiries</a>
                            <?php else: ?>
                                <a href="view_offers.php?property_id=<?= (int)$row['property_id'] ?>">Offers</a>
                            <?php endif; ?>
                            <form method="post" action="submitpropertydelete.php" onsubmit="return confirm('Remove this listing from the marketplace? Its property and transaction history will be preserved.');">
                                <input type="hidden" name="id" value="<?= (int)$row['property_id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit">Remove listing</button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php include("include/footer.php"); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireRole(['buyer']);

$userId = currentUserId();
$statement = $con->prepare("SELECT o.offer_id, o.offer_price, o.offer_date, o.status AS offer_status,
        p.property_id, p.title, p.location, p.price AS asking_price
    FROM offer o
    JOIN property_listings p ON p.property_id = o.property_id
    WHERE o.buyer_id = ?
    ORDER BY o.offer_date DESC, o.offer_id DESC");
$statement->bind_param('i', $userId);
$statement->execute();
$offers = $statement->get_result();

$statusClasses = [
    'Pending' => 'offer-status-pending',
    'Accepted' => 'offer-status-accepted',
    'Rejected' => 'offer-status-rejected',
    'Sold' => 'offer-status-sold',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Offers | EstateScope</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/role-themes.css">
    <link rel="stylesheet" href="css/marketplace.css">
    <style>
        .buyer-offers { max-width: 1050px; margin: 0 auto; padding: 48px 20px; }
        .buyer-offers-intro { color: var(--market-muted); margin-bottom: 24px; }
        .buyer-offer-card { border: 1px solid var(--market-line); border-radius: 8px; padding: 20px; margin-bottom: 14px; background: #fff; box-shadow: var(--market-shadow); }
        .buyer-offer-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
        .buyer-offer-heading h2 { margin: 0 0 5px; font-size: 19px; }
        .buyer-offer-meta { color: var(--market-muted); margin: 0 0 12px; }
        .buyer-offer-status { display: inline-block; padding: 5px 10px; border-radius: 16px; font-size: 13px; font-weight: 700; white-space: nowrap; }
        .offer-status-pending { color: #735300; background: #fff3cd; }
        .offer-status-accepted { color: #155724; background: #d4edda; }
        .offer-status-rejected { color: #721c24; background: #f8d7da; }
        .offer-status-sold { color: #fff; background: #343a40; }
        .buyer-offer-note { margin: 14px 0 0; padding: 10px 12px; background: #f3f6f4; border-radius: 5px; font-size: 14px; }
        @media (max-width: 520px) { .buyer-offer-heading { display: block; } .buyer-offer-status { margin-top: 8px; } }
    </style>
</head>
<body class="market-page" data-role="buyer">
<?php include __DIR__ . '/include/header.php'; ?>
<main class="buyer-offers">
    <p class="market-eyebrow">Your activity</p>
    <h1>My Offers</h1>
    <p class="buyer-offers-intro">Track the offers you have sent to property owners.</p>

    <?php if ($offers->num_rows === 0): ?>
        <div class="market-feedback" role="status">
            You have not submitted any property offers yet.
            <a href="property.php">Browse sale properties</a>
        </div>
    <?php else: ?>
        <?php while ($offer = $offers->fetch_assoc()): ?>
            <?php
            $offerStatus = (string)$offer['offer_status'];
            $statusClass = $statusClasses[$offerStatus] ?? 'offer-status-pending';
            ?>
            <article class="buyer-offer-card">
                <div class="buyer-offer-heading">
                    <div>
                        <h2><a href="propertydetail.php?pid=<?= (int)$offer['property_id'] ?>"><?= htmlspecialchars($offer['title'], ENT_QUOTES, 'UTF-8') ?></a></h2>
                        <p class="buyer-offer-meta"><?= htmlspecialchars($offer['location'] ?: 'Location not listed', ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <span class="buyer-offer-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($offerStatus, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <p class="mb-1"><strong>Your offer:</strong> ₹<?= number_format((float)$offer['offer_price']) ?></p>
                <p class="mb-1"><strong>Asking price:</strong> ₹<?= number_format((float)$offer['asking_price']) ?></p>
                <p class="mb-0"><strong>Submitted:</strong> <?= htmlspecialchars(date('d M Y', strtotime($offer['offer_date'])), ENT_QUOTES, 'UTF-8') ?></p>
                <?php if ($offerStatus === 'Accepted'): ?>
                    <p class="buyer-offer-note">The owner accepted your offer. This is not a completed purchase or payment. Contact the owner to arrange the next steps.</p>
                <?php elseif ($offerStatus === 'Pending'): ?>
                    <p class="buyer-offer-note">Your offer is waiting for the owner to review it.</p>
                <?php elseif ($offerStatus === 'Rejected'): ?>
                    <p class="buyer-offer-note">The owner did not accept this offer. Contact them if you want to discuss another offer.</p>
                <?php elseif ($offerStatus === 'Sold'): ?>
                    <p class="buyer-offer-note">The listing has been marked as sold.</p>
                <?php endif; ?>
            </article>
        <?php endwhile; ?>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>

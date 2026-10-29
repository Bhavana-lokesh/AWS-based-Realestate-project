<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

requireRole(['buyer', 'agent']);
$userId = currentUserId();

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$statement = $con->prepare("SELECT f.date, p.property_id, p.title, p.price, p.location, p.property_type, p.bedrooms, p.bathrooms, p.size_sqft,
    (SELECT image_url FROM property_image WHERE property_id = p.property_id LIMIT 1) AS image_url
    FROM favorite f JOIN property_listings p ON f.property_id = p.property_id
    WHERE f.user_id = ? ORDER BY f.date DESC");
$statement->bind_param('i', $userId);
$statement->execute();
$favorites = $statement->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Saved properties | EstateScope</title>
    <link href="https://fonts.googleapis.com/css?family=Muli:400,400i,500,600,700&amp;display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Comfortaa:400,700" rel="stylesheet">

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
<body class="market-page" data-role="<?= htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8') ?>">

<?php include("include/header.php"); ?>

<main class="market-section">
    <div class="market-section-heading">
        <div>
            <h1>Saved properties</h1>
            <p>Listings you have kept for later.</p>
        </div>
        <a class="market-text-link" href="property.php">Explore properties</a>
    </div>
    <?php if ($favorites->num_rows === 0): ?>
        <div class="market-empty-state">No saved properties yet. Browse listings to add one here.</div>
    <?php else: ?>
        <div class="market-listing-grid">
            <?php while ($row = $favorites->fetch_assoc()): ?>
                <article class="market-listing-card">
                    <?php if (!empty($row['image_url'])): ?>
                        <img class="market-card-image" src="admin/property/<?= htmlspecialchars(basename($row['image_url']), ENT_QUOTES, 'UTF-8') ?>" alt="Property photo for <?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <div class="market-no-image">No photos uploaded</div>
                    <?php endif; ?>
                    <div class="market-card-body">
                        <span class="market-card-type"><?= htmlspecialchars($row['property_type'], ENT_QUOTES, 'UTF-8') ?></span>
                        <h2 class="market-card-title"><a href="propertydetail.php?pid=<?= (int)$row['property_id'] ?>"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></a></h2>
                        <p class="market-card-location"><?= htmlspecialchars($row['location'] ?? 'Location not listed', ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="market-card-price">₹<?= number_format((float)$row['price']) ?></div>
                        <div class="market-card-specs">
                            <span><?= (int)$row['bedrooms'] ?> beds</span>
                            <span><?= (int)$row['bathrooms'] ?> baths</span>
                            <span><?= number_format((int)$row['size_sqft']) ?> sqft</span>
                        </div>
                        <form method="post" action="favorite_add.php" class="mt-3">
                            <input type="hidden" name="property_id" value="<?= (int)$row['property_id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="remove">
                            <button class="market-text-link btn btn-link p-0" type="submit">Remove from saved</button>
                        </form>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</main>

<?php include("include/footer.php"); ?>

</body>
</html>

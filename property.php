<?php
session_start();
require_once 'config.php';

$user_id = (int)($_SESSION['uid'] ?? 0);
$role = strtolower($_SESSION['role'] ?? '');
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$type = $_GET['type'] ?? '';
$type = in_array($type, ['Sale', 'Rental'], true) ? $type : '';
$city = trim($_GET['city'] ?? '');
$city = mb_substr($city, 0, 100);
$minPrice = filter_input(INPUT_GET, 'min_price', FILTER_VALIDATE_FLOAT);
$maxPrice = filter_input(INPUT_GET, 'max_price', FILTER_VALIDATE_FLOAT);
$filterError = '';
foreach (['minPrice', 'maxPrice'] as $priceField) {
    if ($$priceField !== null && ($$priceField === false || !is_finite((float)$$priceField) || $$priceField < 0 || $$priceField > 1000000000000)) {
        $$priceField = null;
        $filterError = 'Enter valid budget limits.';
    }
}
if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
    $minPrice = null;
    $maxPrice = null;
    $filterError = 'Minimum budget must not exceed maximum budget.';
}
$sort = $_GET['sort'] ?? 'newest';
$sortOptions = [
    'newest' => 'p.created_at DESC',
    'price_low' => 'p.price ASC',
    'price_high' => 'p.price DESC',
];
$orderBy = $sortOptions[$sort] ?? $sortOptions['newest'];
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$perPage = 9;

$conditions = [
    "p.status = 'available'",
    "p.review_status = 'approved'",
    "EXISTS (SELECT 1 FROM user owner WHERE owner.user_id = p.seller_id AND LOWER(owner.role) IN ('agent', 'seller') AND owner.is_active = 1)",
];
$filterParams = [];
if ($type !== '') {
    $conditions[] = 'p.property_type = ?';
    $filterParams[] = $type;
}
if ($city !== '') {
    $conditions[] = 'p.location LIKE ?';
    $filterParams[] = '%' . $city . '%';
}
if ($minPrice !== null) {
    $conditions[] = 'p.price >= ?';
    $filterParams[] = (float)$minPrice;
}
if ($maxPrice !== null) {
    $conditions[] = 'p.price <= ?';
    $filterParams[] = (float)$maxPrice;
}
if ($user_id && in_array($role, ['buyer', 'agent'], true)) {
    $conditions[] = 'p.seller_id <> ?';
    $filterParams[] = $user_id;
}
$whereSql = ' WHERE ' . implode(' AND ', $conditions);

$countStatement = $con->prepare('SELECT COUNT(*) FROM property_listings p' . $whereSql);
$countStatement->execute($filterParams);
$totalProperties = (int)$countStatement->get_result()->fetch_row()[0];
$totalPages = max(1, (int)ceil($totalProperties / $perPage));
$page = min(max(1, (int)$page), $totalPages);
$offset = ($page - 1) * $perPage;

$listingStatement = $con->prepare(
    "SELECT p.property_id, p.title, p.price, p.location, p.property_type, p.bedrooms, p.bathrooms, p.size_sqft, p.created_at, u.name AS seller_name,
        (SELECT image_url FROM property_image WHERE property_id = p.property_id LIMIT 1) AS image_url
     FROM property_listings p
     JOIN user u ON p.seller_id = u.user_id" . $whereSql .
     " ORDER BY " . $orderBy . " LIMIT ? OFFSET ?"
);
$listingParams = array_merge($filterParams, [$perPage, $offset]);
$listingStatement->execute($listingParams);
$properties = $listingStatement->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta http-equiv="X-UA-Compatible" content="IE=edge"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Properties | EstateScope</title>
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
<link rel="stylesheet" type="text/css" href="css/marketplace.css">
</head>
<body class="market-page">

<?php include("include/header.php"); ?>

<main class="market-section">
    <div class="market-section-heading">
        <div>
            <h1>Available properties</h1>
            <p>Search current sale and rental listings.</p>
        </div>
        <p class="market-results-count"><?= $totalProperties ?> matching listing<?= $totalProperties === 1 ? '' : 's' ?></p>
    </div>

    <form class="market-filter-form" method="get" action="property.php">
        <div class="market-filter-field">
            <label for="filter-type">Listing type</label>
            <select class="form-control" name="type" id="filter-type">
                <option value="">All types</option>
                <option value="Sale" <?= $type === 'Sale' ? 'selected' : '' ?>>For sale</option>
                <option value="Rental" <?= $type === 'Rental' ? 'selected' : '' ?>>For rent</option>
            </select>
        </div>
        <div class="market-filter-field market-filter-location">
            <label for="filter-city">City or area</label>
            <input class="form-control" name="city" id="filter-city" value="<?= htmlspecialchars($city, ENT_QUOTES, 'UTF-8') ?>" placeholder="Any location">
        </div>
        <div class="market-filter-field">
            <label for="filter-min-price">Minimum price (₹)</label>
            <input class="form-control" type="number" min="0" step="1000" name="min_price" id="filter-min-price" value="<?= $minPrice !== null ? htmlspecialchars((string)$minPrice, ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="No minimum">
        </div>
        <div class="market-filter-field">
            <label for="filter-max-price">Maximum price (₹)</label>
            <input class="form-control" type="number" min="0" step="1000" name="max_price" id="filter-max-price" value="<?= $maxPrice !== null ? htmlspecialchars((string)$maxPrice, ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="No maximum">
        </div>
        <div class="market-filter-field">
            <label for="filter-sort">Sort by</label>
            <select class="form-control" name="sort" id="filter-sort">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: low to high</option>
                <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: high to low</option>
            </select>
        </div>
        <button class="market-button" type="submit">Apply filters</button>
    </form>
    <?php if ($filterError !== ''): ?><p class="market-form-error" role="alert"><?= htmlspecialchars($filterError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

    <div class="market-listing-grid">
        <?php if ($properties && $properties->num_rows > 0): ?>
            <?php while ($row = $properties->fetch_assoc()): ?>
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
                            <?php if ((int)$row['bedrooms'] > 0): ?><span><?= (int)$row['bedrooms'] ?> beds</span><?php endif; ?>
                            <?php if ((int)$row['bathrooms'] > 0): ?><span><?= (int)$row['bathrooms'] ?> baths</span><?php endif; ?>
                            <span><?= number_format((int)$row['size_sqft']) ?> sqft</span>
                        </div>
                        <p class="market-card-location mt-3">Listed by <?= htmlspecialchars($row['seller_name'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="mt-3">
                            <a class="market-text-link" href="propertydetail.php?pid=<?= (int)$row['property_id'] ?>">View Details</a>
                            <?php if ($user_id): ?>
                                <form method="post" action="favorite_add.php" class="mt-2">
                                    <input type="hidden" name="property_id" value="<?= (int)$row['property_id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="market-text-link btn btn-link p-0">Save to favorites</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="market-empty-state">No listings match these filters. Try a different location or listing type.</div>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="market-pagination" aria-label="Property listing pages">
            <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                <?php $pageUrl = 'property.php?' . http_build_query(['type' => $type, 'city' => $city, 'min_price' => $minPrice, 'max_price' => $maxPrice, 'sort' => $sort, 'page' => $pageNumber]); ?>
                <?php if ($pageNumber === $page): ?>
                    <span class="market-page-current" aria-current="page"><?= $pageNumber ?></span>
                <?php else: ?>
                    <a class="market-page-link" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>"><?= $pageNumber ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</main>

<?php include("include/footer.php"); ?>

</body>
</html>

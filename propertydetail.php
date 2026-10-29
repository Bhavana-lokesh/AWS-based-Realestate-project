<?php 
ini_set('session.cache_limiter','public');
session_cache_limiter(false);
session_start();
ob_start();
include("config.php");
require_once 'auth.php';

$pid = filter_input(INPUT_GET, 'pid', FILTER_VALIDATE_INT) ?: 0;
$userId = currentUserId();
$role = currentUserRole();
$statement = $con->prepare("SELECT p.*, u.name AS uname, u.email AS uemail, u.phone_num AS uphone, u.user_id AS seller_id
    FROM property_listings p JOIN user u ON p.seller_id = u.user_id WHERE p.property_id = ? LIMIT 1");
$statement->bind_param('i', $pid);
$statement->execute();
$row = $statement->get_result()->fetch_assoc();
$statement->close();

if (!$row) {
    http_response_code(404);
    exit('Property not found.');
}

$canViewUnapproved = (int)$row['seller_id'] === $userId && $role === 'agent'
    || $role === 'super_admin';
if ($row['review_status'] !== 'approved' && !$canViewUnapproved) {
    http_response_code(404);
    exit('Property not found.');
}
if ($row['status'] === 'hold' && !$canViewUnapproved) {
    http_response_code(404);
    exit('Property not found.');
}

$imageStatement = $con->prepare("SELECT image_url FROM property_image WHERE property_id = ? ORDER BY image_id");
$imageStatement->bind_param('i', $pid);
$imageStatement->execute();
$images = $imageStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$imageStatement->close();

$property_type = strtolower($row['property_type']);
$property_id = $pid;
$buyer_id = $userId;
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?> | EstateScope</title>
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
<body class="market-page">

<?php include("include/header.php"); ?>

<main class="market-detail-wrap">
    <a class="market-text-link" href="property.php">All properties</a>
    <?php if (!empty($_SESSION['flash_message'])): ?>
        <div class="market-feedback" role="status"><?= htmlspecialchars($_SESSION['flash_message'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>
    <div class="market-detail-gallery market-detail-gallery-count-<?= min(5, count($images)) ?>" aria-label="Property photos">
        <?php if ($images): ?>
            <?php foreach (array_slice($images, 0, 5) as $image): ?>
                <img src="admin/property/<?= htmlspecialchars(basename($image['image_url']), ENT_QUOTES, 'UTF-8') ?>" alt="Photo of <?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>">
            <?php endforeach; ?>
        <?php else: ?>
            <div class="market-detail-no-image">No property photos uploaded</div>
        <?php endif; ?>
    </div>

    <header class="market-detail-heading">
        <div>
            <p class="market-card-type"><?= htmlspecialchars($row['property_type'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst($row['status']), ENT_QUOTES, 'UTF-8') ?></p>
            <h1><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="market-detail-location"><?= htmlspecialchars(implode(', ', array_filter([$row['street'], $row['location'], $row['state'], $row['zip']])), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="market-detail-price">₹<?= number_format((float)$row['price']) ?></div>
    </header>

    <div class="market-detail-layout">
        <div>
            <div class="market-spec-list">
                <div class="market-spec">Bedrooms<strong><?= (int)$row['bedrooms'] ?></strong></div>
                <div class="market-spec">Bathrooms<strong><?= (int)$row['bathrooms'] ?></strong></div>
                <div class="market-spec">Area<strong><?= number_format((int)$row['size_sqft']) ?> sqft</strong></div>
                <div class="market-spec">Listed<strong><?= date('d M Y', strtotime($row['created_at'])) ?></strong></div>
            </div>

            <section class="market-detail-section">
                <h2>About this property</h2>
                <p><?= nl2br(htmlspecialchars($row['description'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p>
            </section>

            <section class="market-detail-section">
                <h2>Property features</h2>
                <div class="market-feature-list">
                    <?php if (!empty($row['nearest_school'])): ?><span>Nearby school: <?= htmlspecialchars($row['nearest_school'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    <span>Bus access: <?= htmlspecialchars($row['bus_availability'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span>Tram access: <?= htmlspecialchars($row['tram_availability'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span>Pool: <?= htmlspecialchars($row['pool_available'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span>Pet friendly: <?= htmlspecialchars($row['is_dog_friendly'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </section>
        </div>

        <aside class="market-contact-panel">
            <?php if ($userId && in_array($role, ['buyer', 'agent'], true) && $userId !== (int)$row['seller_id']): ?>
                <h2>Contact the listing owner</h2>
                <p><?= htmlspecialchars($row['uname'], ENT_QUOTES, 'UTF-8') ?></p>
                <p><a href="mailto:<?= htmlspecialchars($row['uemail'], ENT_QUOTES, 'UTF-8') ?>">Email the owner</a><br><?= htmlspecialchars($row['uphone'], ENT_QUOTES, 'UTF-8') ?></p>
                <a class="market-button market-contact-button" href="appointment.php?property_id=<?= (int)$property_id ?>">Request an appointment</a>
                <form method="post" action="report_property.php" class="market-action-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="property_id" value="<?= (int)$property_id ?>">
                    <label for="property-report-details">Report a concern</label>
                    <textarea class="form-control" id="property-report-details" name="details" minlength="10" maxlength="2000" required></textarea>
                    <button class="btn btn-outline-secondary mt-2" type="submit">Submit report</button>
                </form>

                <?php if ($property_type === 'rental'): ?>
                    <?php
                    $interestStatement = $con->prepare("SELECT status FROM rental_interest WHERE property_id = ? AND buyer_id = ? ORDER BY interest_id DESC LIMIT 1");
                    $interestStatement->bind_param('ii', $property_id, $userId);
                    $interestStatement->execute();
                    $rentalInterest = $interestStatement->get_result()->fetch_assoc();
                    $interestStatement->close();
                    ?>
                    <?php if (!$rentalInterest): ?>
                        <form method="post" class="market-action-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button class="market-button market-button-secondary" type="submit" name="interested_rental">Express rental interest</button>
                        </form>
                    <?php else: ?>
                        <p class="market-status-note">Rental inquiry status: <?= htmlspecialchars($rentalInterest['status'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <p class="market-payment-note">Online rental payments are unavailable.</p>
                <?php else: ?>
                    <?php
                    $offerStatement = $con->prepare("SELECT offer_price, status FROM offer WHERE property_id = ? AND buyer_id = ? ORDER BY offer_id DESC LIMIT 1");
                    $offerStatement->bind_param('ii', $property_id, $userId);
                    $offerStatement->execute();
                    $existingOffer = $offerStatement->get_result()->fetch_assoc();
                    $offerStatement->close();
                    ?>
                    <?php if (!$existingOffer): ?>
                        <form method="post" class="market-action-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <label for="offer-price">Your offer (₹)</label>
                            <input class="form-control" id="offer-price" type="number" name="offer_price" min="1" step="1000" required>
                            <button class="market-button market-button-secondary" type="submit" name="submit_offer">Submit offer</button>
                        </form>
                    <?php else: ?>
                        <p class="market-status-note">Your offer: ₹<?= number_format((float)$existingOffer['offer_price']) ?> · <?= htmlspecialchars($existingOffer['status'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <p class="market-payment-note">Online sale payments are unavailable.</p>
                <?php endif; ?>
            <?php elseif (!$userId): ?>
                <h2>Interested in this property?</h2>
                <p>Sign in to contact the owner, request an appointment, or save a listing.</p>
                <a class="market-button market-contact-button" href="login.php">Sign in to continue</a>
            <?php elseif ($userId === (int)$row['seller_id']): ?>
                <h2>Your listing</h2>
                <p>You are the owner of this property.</p>
                <a class="market-button market-contact-button" href="edit_property.php?property_id=<?= (int)$property_id ?>">Edit listing</a>
            <?php else: ?>
                <h2>Contact information</h2>
                <p>Contact details are available to signed-in buyers and agents.</p>
                <a class="market-button market-contact-button" href="login.php">Sign in</a>
            <?php endif; ?>
        </aside>
    </div>
</main>

<?php include("include/footer.php"); ?>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['buyer', 'agent']);
    $validAction = isset($_POST['csrf_token']) && hash_equals($csrfToken, $_POST['csrf_token']);
    $allowedRole = $userId && in_array($role, ['buyer', 'agent'], true) && $userId !== (int)$row['seller_id'];
    if (!$validAction || !$allowedRole || $row['status'] !== 'available') {
        http_response_code(403);
        exit('This action is not permitted.');
    }

    if (isset($_POST['submit_offer']) && $property_type === 'sale') {
        $offerPrice = filter_var($_POST['offer_price'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($offerPrice === false || $offerPrice <= 0 || $offerPrice > 1000000000000) {
            $_SESSION['flash_message'] = 'Enter a valid offer amount.';
        } else {
            $duplicate = $con->prepare("SELECT offer_id FROM offer WHERE property_id = ? AND buyer_id = ? AND status = 'Pending' LIMIT 1");
            $duplicate->bind_param('ii', $property_id, $userId);
            $duplicate->execute();
            $hasPendingOffer = (bool)$duplicate->get_result()->fetch_assoc();
            $duplicate->close();

            if (!$hasPendingOffer) {
                $offerDate = date('Y-m-d');
                $insertOffer = $con->prepare("INSERT INTO offer (property_id, buyer_id, offer_price, offer_date, status) VALUES (?, ?, ?, ?, 'Pending')");
                $insertOffer->bind_param('iids', $property_id, $userId, $offerPrice, $offerDate);
                $insertOffer->execute();
                $insertOffer->close();
                $_SESSION['flash_message'] = 'Your offer has been submitted.';
            }
        }
    } elseif (isset($_POST['interested_rental']) && $property_type === 'rental') {
        $duplicate = $con->prepare("SELECT interest_id FROM rental_interest WHERE property_id = ? AND buyer_id = ? AND status = 'Pending' LIMIT 1");
        $duplicate->bind_param('ii', $property_id, $userId);
        $duplicate->execute();
        $hasPendingInterest = (bool)$duplicate->get_result()->fetch_assoc();
        $duplicate->close();

        if (!$hasPendingInterest) {
            $insertInterest = $con->prepare("INSERT INTO rental_interest (property_id, buyer_id, interest_date, status) VALUES (?, ?, NOW(), 'Pending')");
            $insertInterest->bind_param('ii', $property_id, $userId);
            $insertInterest->execute();
            $insertInterest->close();
            $_SESSION['flash_message'] = 'Your rental inquiry has been sent.';
        }
    } else {
        http_response_code(400);
        exit('Unsupported property action.');
    }

    header('Location: propertydetail.php?pid=' . $property_id);
    exit;
}

ob_end_flush();
?>
</body>
</html>

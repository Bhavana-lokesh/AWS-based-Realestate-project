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

$addressParts = [];
foreach (['street', 'location', 'state', 'zip'] as $addressField) {
    $addressValue = trim((string)($row[$addressField] ?? ''));
    if ($addressValue !== '') {
        $addressParts[$addressField] = $addressValue;
    }
}
$propertyAddress = implode(', ', array_values($addressParts));
$hasMapLocation = count($addressParts) >= 2
    && (isset($addressParts['location']) || (isset($addressParts['street']) && isset($addressParts['zip'])));
$mapUrl = null;
$directionsUrl = null;
$leafletTileUrl = trim((string)(getenv('LEAFLET_TILE_URL') ?: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'));
$leafletTileAttribution = trim((string)(getenv('LEAFLET_TILE_ATTRIBUTION')
    ?: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'));
if ($hasMapLocation) {
    $encodedAddress = rawurlencode($propertyAddress);
    $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . $encodedAddress;
    $directionsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . $encodedAddress;
}
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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<link rel="stylesheet" href="css/marketplace.css?v=9">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>
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
            <?php if (!empty($addressParts['location'])): ?>
                <p class="market-detail-location"><?= htmlspecialchars($addressParts['location'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>
        <div class="market-detail-price">₹<?= number_format((float)$row['price']) ?></div>
    </header>

    <div class="market-detail-layout">
        <div class="market-detail-main">
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

            <section class="market-detail-section market-features-section">
                <h2>Property features</h2>
                <div class="market-feature-list">
                    <?php if (!empty($row['nearest_school'])): ?>
                        <div class="market-feature-item">
                            <i class="fa fa-graduation-cap" aria-hidden="true"></i>
                            <div class="market-feature-text">
                                <span class="market-feature-label">Nearby school</span>
                                <span class="market-feature-value"><?= htmlspecialchars($row['nearest_school'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="market-feature-item">
                        <i class="fa fa-bus" aria-hidden="true"></i>
                        <div class="market-feature-text">
                            <span class="market-feature-label">Bus access</span>
                            <span class="market-feature-value"><?= htmlspecialchars($row['bus_availability'] ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <div class="market-feature-item">
                        <i class="fa fa-subway" aria-hidden="true"></i>
                        <div class="market-feature-text">
                            <span class="market-feature-label">Tram access</span>
                            <span class="market-feature-value"><?= htmlspecialchars($row['tram_availability'] ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <div class="market-feature-item">
                        <i class="fa fa-tint" aria-hidden="true"></i>
                        <div class="market-feature-text">
                            <span class="market-feature-label">Pool</span>
                            <span class="market-feature-value"><?= htmlspecialchars($row['pool_available'] ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <div class="market-feature-item">
                        <i class="fa fa-paw" aria-hidden="true"></i>
                        <div class="market-feature-text">
                            <span class="market-feature-label">Pet friendly</span>
                            <span class="market-feature-value"><?= htmlspecialchars($row['is_dog_friendly'] ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($role === 'buyer' && $userId > 0 && $property_type !== 'rental'): ?>
            <section class="market-detail-section" id="emi-calculator" style="padding:24px 0;">
                <h2>EMI Calculator</h2>
                <p style="color:#666;font-size:13px;margin-bottom:16px;">Estimate your monthly loan repayment for this property.</p>
                <div style="background:#f9f9f9;border:1px solid #e0e0e0;border-radius:8px;padding:24px;">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="emi-property-price">Property Price (₹)</label>
                                <input type="number" id="emi-property-price" class="form-control"
                                       value="<?= (int)$row['price'] ?>" min="1" step="1000" readonly
                                       style="background:#f0f0f0;">
                            </div>
                            <div class="form-group">
                                <label for="emi-down-payment">Down Payment (₹)</label>
                                <input type="number" id="emi-down-payment" class="form-control"
                                       value="<?= (int)($row['price'] * 0.2) ?>" min="0" step="1000">
                            </div>
                            <div class="form-group">
                                <label for="emi-loan-amount">Loan Amount (₹)</label>
                                <input type="number" id="emi-loan-amount" class="form-control"
                                       value="<?= (int)($row['price'] * 0.8) ?>" min="1" step="1000" readonly
                                       style="background:#f0f0f0;">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="emi-rate">Annual Interest Rate (%)</label>
                                <input type="number" id="emi-rate" class="form-control"
                                       value="8.5" min="0.1" max="30" step="0.1">
                            </div>
                            <div class="form-group">
                                <label for="emi-tenure">Loan Tenure (years)</label>
                                <input type="number" id="emi-tenure" class="form-control"
                                       value="20" min="1" max="30" step="1">
                            </div>
                            <div class="form-group" style="margin-top:24px;">
                                <button type="button" id="emi-calc-btn" class="market-button" style="width:100%;padding:10px;">
                                    Calculate EMI
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="emi-error" style="color:#c0392b;display:none;margin-top:8px;"></div>

                    <div id="emi-result" style="display:none;margin-top:20px;border-top:1px solid #ddd;padding-top:20px;">
                        <div style="display:flex;gap:20px;flex-wrap:wrap;">
                            <div style="flex:1;min-width:140px;background:#fff;border-radius:6px;padding:16px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.08);">
                                <div style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.5px;">Monthly EMI</div>
                                <div id="emi-monthly" style="font-size:24px;font-weight:700;color:#1a3c5e;margin-top:6px;"></div>
                            </div>
                            <div style="flex:1;min-width:140px;background:#fff;border-radius:6px;padding:16px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.08);">
                                <div style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Interest</div>
                                <div id="emi-interest" style="font-size:24px;font-weight:700;color:#c0392b;margin-top:6px;"></div>
                            </div>
                            <div style="flex:1;min-width:140px;background:#fff;border-radius:6px;padding:16px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.08);">
                                <div style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Repayment</div>
                                <div id="emi-total" style="font-size:24px;font-weight:700;color:#27ae60;margin-top:6px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <script>
            (function () {
                var price  = document.getElementById('emi-property-price');
                var down   = document.getElementById('emi-down-payment');
                var loan   = document.getElementById('emi-loan-amount');
                var rate   = document.getElementById('emi-rate');
                var tenure = document.getElementById('emi-tenure');
                var btn    = document.getElementById('emi-calc-btn');
                var result = document.getElementById('emi-result');
                var errBox = document.getElementById('emi-error');

                function formatINR(n) {
                    return '₹' + Math.round(n).toLocaleString('en-IN');
                }

                function syncLoan() {
                    var p = parseFloat(price.value) || 0;
                    var d = parseFloat(down.value)  || 0;
                    d = Math.min(d, p);
                    loan.value = Math.max(0, p - d);
                }

                down.addEventListener('input', syncLoan);
                price.addEventListener('change', syncLoan);

                btn.addEventListener('click', function () {
                    errBox.style.display = 'none';
                    errBox.textContent   = '';
                    result.style.display = 'none';

                    var P = parseFloat(loan.value);
                    var r = parseFloat(rate.value) / 12 / 100;
                    var n = parseInt(tenure.value, 10) * 12;

                    if (!P || P <= 0)       { showErr('Loan amount must be greater than 0.'); return; }
                    if (!rate.value || parseFloat(rate.value) <= 0) { showErr('Enter a valid interest rate.'); return; }
                    if (!n || n <= 0)       { showErr('Tenure must be at least 1 year.'); return; }

                    var emi, totalPayment, totalInterest;
                    if (r === 0) {
                        emi = P / n;
                    } else {
                        emi = P * r * Math.pow(1 + r, n) / (Math.pow(1 + r, n) - 1);
                    }
                    totalPayment  = emi * n;
                    totalInterest = totalPayment - P;

                    document.getElementById('emi-monthly').textContent  = formatINR(emi);
                    document.getElementById('emi-interest').textContent = formatINR(totalInterest);
                    document.getElementById('emi-total').textContent    = formatINR(totalPayment);
                    result.style.display = 'block';
                });

                function showErr(msg) {
                    errBox.textContent   = msg;
                    errBox.style.display = 'block';
                }

                btn.click();
            })();
            </script>
            <?php endif; ?>

            <?php if ($role === 'buyer' && $userId > 0): ?>
            <div class="market-brochure-wrap">
                <a class="market-button market-button-secondary market-brochure-btn"
                   href="download_brochure.php?pid=<?= (int)$property_id ?>">
                    <i class="fa fa-file-pdf-o mr-2" aria-hidden="true"></i> Download Property Brochure (PDF)
                </a>
            </div>
            <?php endif; ?>
        </div>

        <aside class="market-contact-panel">
            <section class="market-contact-location" aria-labelledby="contact-location-heading">
                <h2 id="contact-location-heading">Property Location</h2>
                <p class="market-contact-address">
                    <i class="fa fa-map-marker" aria-hidden="true"></i>
                    <span><?= $propertyAddress !== '' ? htmlspecialchars($propertyAddress, ENT_QUOTES, 'UTF-8') : 'Address details unavailable.' ?></span>
                </p>
                <?php if ($hasMapLocation): ?>
                    <div class="market-leaflet-map-shell">
                        <div
                            id="property-leaflet-map"
                            class="market-contact-map-preview"
                            data-property-id="<?= (int)$property_id ?>"
                            data-tile-url="<?= htmlspecialchars($leafletTileUrl, ENT_QUOTES, 'UTF-8') ?>"
                            data-tile-attribution="<?= htmlspecialchars($leafletTileAttribution, ENT_QUOTES, 'UTF-8') ?>"
                            role="application"
                            aria-label="Interactive map showing the approximate property location"></div>
                        <p id="property-leaflet-map-status" class="market-leaflet-map-status" role="status" aria-live="polite">
                            Loading the address-based map…
                        </p>
                    </div>
                    <p id="property-leaflet-map-note" class="market-map-note">Map pin is approximate and based on the listing address. Verify the location before visiting.</p>
                    <script>
                    (function () {
                        document.addEventListener('DOMContentLoaded', function () {
                            const mapElement = document.getElementById('property-leaflet-map');
                            const statusElement = document.getElementById('property-leaflet-map-status');
                            const noteElement = document.getElementById('property-leaflet-map-note');

                            function showMapStatus(message) {
                                statusElement.textContent = message;
                                statusElement.hidden = false;
                            }

                            if (!window.L) {
                                showMapStatus('The map library could not be loaded. Use the location links below instead.');
                                return;
                            }

                            const propertyId = mapElement.dataset.propertyId;
                            fetch('property_geocode.php?pid=' + encodeURIComponent(propertyId), {
                                credentials: 'same-origin',
                                headers: { Accept: 'application/json' }
                            })
                                .then(function (response) {
                                    return response.json().then(function (data) {
                                        if (!response.ok || !data.success) {
                                            throw new Error(data.message || 'The map location is unavailable.');
                                        }
                                        return data;
                                    });
                                })
                                .then(function (location) {
                                    const latitude = Number(location.latitude);
                                    const longitude = Number(location.longitude);
                                    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
                                        || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
                                        throw new Error('The address did not produce valid map coordinates.');
                                    }
                                    if (location.location_level === 'locality') {
                                        noteElement.textContent = 'The street address was not found. This map shows only the approximate listing locality.';
                                    }

                                    const map = L.map(mapElement, { scrollWheelZoom: false }).setView([latitude, longitude], location.zoom || 15);
                                    const tiles = L.tileLayer(mapElement.dataset.tileUrl, {
                                        maxZoom: 19,
                                        attribution: mapElement.dataset.tileAttribution
                                    });
                                    tiles.on('load', function () {
                                        statusElement.hidden = true;
                                    });
                                    tiles.on('tileerror', function () {
                                        showMapStatus('Map tiles could not be loaded. Check your internet connection or map tile configuration.');
                                    });
                                    tiles.addTo(map);

                                    const popupContent = document.createElement('span');
                                    popupContent.textContent = location.display_name || 'Approximate property location';
                                    L.marker([latitude, longitude]).addTo(map).bindPopup(popupContent);
                                })
                                .catch(function (error) {
                                    showMapStatus(error.message || 'The map location could not be loaded. Use the location links below instead.');
                                });
                        });
                    })();
                    </script>
                    <div class="market-contact-map-links">
                        <a class="market-button market-contact-button" href="<?= htmlspecialchars($mapUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                            <i class="fa fa-map-marker mr-1" aria-hidden="true"></i> View on Google Maps
                        </a>
                        <a class="market-button market-button-secondary market-contact-button" href="<?= htmlspecialchars($directionsUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                            <i class="fa fa-location-arrow mr-1" aria-hidden="true"></i> Get Directions
                        </a>
                    </div>
                <?php else: ?>
                    <p class="market-leaflet-map-unavailable" role="status">
                        A map cannot be shown because the listing does not include enough address details.
                    </p>
                <?php endif; ?>
            </section>
            <hr class="market-contact-divider">

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

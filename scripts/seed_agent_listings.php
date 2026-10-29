<?php
session_start();
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/auth.php';
requireRole(['agent']);

$agentId = currentUserId();
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$message = '';
$error = '';
$inserted = 0;

$imageDirectory = dirname(__DIR__) . '/admin/property/';
$photoDisclosure = 'Stock photos are representative only and are not photographs of these example properties.';
$imageFiles = [];
$fallbackImage = null;
foreach (range(1, 200) as $number) {
    $imageFile = sprintf('example-property-%03d.jpg', $number);
    $imagePath = $imageDirectory . $imageFile;
    if (is_file($imagePath) && filesize($imagePath) >= 4096) {
        $imageFiles[$number] = $imageFile;
        $fallbackImage ??= $imageFile;
    }
}
if ($fallbackImage === null) {
    http_response_code(500);
    exit('No usable property photos are available.');
}
foreach (range(1, 200) as $number) {
    $imageFiles[$number] ??= $fallbackImage;
}

$localities = [
    ['Koramangala', '560034'],
    ['Whitefield', '560066'],
    ['Indiranagar', '560038'],
    ['HSR Layout', '560102'],
    ['Jayanagar', '560041'],
    ['Hebbal', '560024'],
    ['Electronic City', '560100'],
    ['Sarjapur Road', '560035'],
    ['Yelahanka', '560064'],
    ['Devanahalli', '562110'],
];

$categories = ['apartment', 'independent house', 'villa', 'residential plot', 'commercial office'];
$sampleMarker = ' - Sample #';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $lockName = 'seed_agent_examples_' . $agentId;
    $lockStatement = $con->prepare('SELECT GET_LOCK(?, 10)');
    $lockStatement->bind_param('s', $lockName);
    $lockStatement->execute();
    $lockAcquired = (int)$lockStatement->get_result()->fetch_row()[0];
    $lockStatement->close();

    if ($lockAcquired !== 1) {
        http_response_code(503);
        exit('The listing seed is already running. Try again shortly.');
    }

    try {
        $con->begin_transaction();
        $existingStatement = $con->prepare(
            'SELECT property_id, title, description
             FROM property_listings WHERE seller_id = ? AND title LIKE ? FOR UPDATE'
        );
        $likePrefix = '%' . $sampleMarker . '%';
        $existingStatement->bind_param('is', $agentId, $likePrefix);
        $existingStatement->execute();
        $existingRows = $existingStatement->get_result()->fetch_all(MYSQLI_ASSOC);
        $existingStatement->close();

        $existingNumbers = [];
        $existingPropertyIds = [];
        $existingTitles = [];
        $existingDescriptions = [];
        foreach ($existingRows as $row) {
            if (!preg_match('/ - Sample #(\d{3})$/', $row['title'], $matches)) {
                throw new RuntimeException('An example listing marker is malformed.');
            }
            $number = (int)$matches[1];
            if ($number < 1 || $number > 200 || isset($existingNumbers[$number])) {
                throw new RuntimeException('Example listing markers are duplicated or out of range.');
            }
            $existingNumbers[$number] = true;
            $existingPropertyIds[$number] = (int)$row['property_id'];
            $existingTitles[$number] = $row['title'];
            $existingDescriptions[$number] = (string)$row['description'];
        }

        $insertListing = $con->prepare(
            'INSERT INTO property_listings
                (title, description, price, location, property_type, bedrooms, bathrooms, size_sqft,
                 nearest_school, bus_availability, tram_availability, seller_id, status, review_status,
                 zip, street, state, pool_available, is_dog_friendly)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertImage = $con->prepare(
            'INSERT INTO property_image (property_id, image_url) VALUES (?, ?)'
        );
        $selectImage = $con->prepare(
            'SELECT image_id FROM property_image WHERE property_id = ? ORDER BY image_id LIMIT 1'
        );
        $updateImage = $con->prepare(
            'UPDATE property_image SET image_url = ? WHERE image_id = ?'
        );
        $updateDescription = $con->prepare(
            'UPDATE property_listings SET description = ? WHERE property_id = ? AND seller_id = ? AND title = ?'
        );

        foreach (range(1, 200) as $number) {
            $imageFile = $imageFiles[$number];
            if (isset($existingNumbers[$number])) {
                $propertyId = $existingPropertyIds[$number];
                $selectImage->bind_param('i', $propertyId);
                $selectImage->execute();
                $imageRow = $selectImage->get_result()->fetch_assoc();

                if ($imageRow) {
                    $imageId = (int)$imageRow['image_id'];
                    $updateImage->bind_param('si', $imageFile, $imageId);
                    $updateImage->execute();
                } else {
                    $insertImage->bind_param('is', $propertyId, $imageFile);
                    $insertImage->execute();
                }

                if (strpos($existingDescriptions[$number], $photoDisclosure) === false) {
                    $description = rtrim($existingDescriptions[$number]) . ' ' . $photoDisclosure;
                    $title = $existingTitles[$number];
                    $updateDescription->bind_param('siis', $description, $propertyId, $agentId, $title);
                    $updateDescription->execute();
                    $existingDescriptions[$number] = $description;
                }
                continue;
            }

            $localityIndex = ($number - 1) % count($localities);
            [$locality, $zip] = $localities[$localityIndex];
            $category = $categories[intdiv($number - 1, 40)];
            $variation = intdiv($number - 1, count($localities));
            $isRental = $category !== 'residential plot' && $variation % 4 === 0;
            $propertyType = $isRental ? 'Rental' : 'Sale';
            $factor = 0.9 + ($localityIndex % 5) * 0.08;
            $bedrooms = 0;
            $bathrooms = 0;
            $pool = 'No';
            $dogFriendly = $variation % 3 === 0 ? 'Yes' : 'No';

            if ($category === 'apartment') {
                $bedrooms = [1, 2, 2, 3][$variation % 4];
                $bathrooms = $bedrooms === 1 ? 1 : 2;
                $size = [680, 1080, 1240, 1540][$variation % 4];
                $titleType = $bedrooms . ' BHK Apartment';
                $price = $isRental
                    ? [22000, 33000, 37000, 49000][$variation % 4] * $factor
                    : [6900000, 10400000, 11900000, 15100000][$variation % 4] * $factor;
            } elseif ($category === 'independent house') {
                $bedrooms = $variation % 2 === 0 ? 2 : 3;
                $bathrooms = $bedrooms;
                $size = $bedrooms === 2 ? 1280 : 1860;
                $titleType = $bedrooms . ' BHK Independent House';
                $price = $isRental
                    ? ($bedrooms === 2 ? 36000 : 54000) * $factor
                    : ($bedrooms === 2 ? 14800000 : 23900000) * $factor;
            } elseif ($category === 'villa') {
                $bedrooms = $variation % 2 === 0 ? 3 : 4;
                $bathrooms = $bedrooms;
                $size = $bedrooms === 3 ? 2050 : 2860;
                $titleType = $bedrooms . ' BHK Villa';
                $pool = $variation % 2 === 0 ? 'Yes' : 'No';
                $price = $isRental
                    ? ($bedrooms === 3 ? 72000 : 98000) * $factor
                    : ($bedrooms === 3 ? 28500000 : 41900000) * $factor;
            } elseif ($category === 'residential plot') {
                $size = 1200 + ($variation % 5) * 300;
                $titleType = 'Residential Plot';
                $price = $size * (5600 + ($localityIndex % 4) * 900) * $factor;
            } else {
                $bathrooms = $variation % 2 === 0 ? 1 : 2;
                $size = [720, 1180, 2050, 1380][$variation % 4];
                $titleType = 'Commercial Office';
                $price = $isRental
                    ? [32000, 56000, 94000, 65000][$variation % 4] * $factor
                    : [8200000, 14300000, 24800000, 17700000][$variation % 4] * $factor;
            }

            $price = $isRental ? round($price / 1000) * 1000 : round($price / 100000) * 100000;
            $title = sprintf('%s in %s, Bengaluru%s%03d', $titleType, $locality, $sampleMarker, $number);
            $priceDescription = $isRental
                ? 'Illustrative monthly rent'
                : 'Illustrative asking price';
            $description = sprintf(
                'Illustrative sample #%03d: %s in %s, Bengaluru. Approximate built-up/plot area: %s sq ft; bedrooms: %s; bathrooms: %s. %s. Bus availability is listed as yes; tram availability is listed as no. Nearby school, address, price, amenities, ownership, and availability are fictional placeholders and are not verified real-world details. This is not a real property offer. %s',
                $number,
                $titleType,
                $locality,
                $locality,
                number_format($size),
                $bedrooms === 0 ? 'not applicable' : (string)$bedrooms,
                $bathrooms === 0 ? 'not applicable' : (string)$bathrooms,
                $priceDescription,
                $photoDisclosure
            );
            $location = $locality . ', Bengaluru';
            $nearestSchool = 'Nearby school details to be verified';
            $busAvailability = 'Yes';
            $tramAvailability = 'No';
            $status = 'available';
            $reviewStatus = 'approved';
            $street = sprintf('%d %s Main Road', 10 + (($number * 7) % 890), $locality);
            $state = 'Karnataka';

            $insertListing->bind_param(
                'ssdssiiisssisssssss',
                $title,
                $description,
                $price,
                $location,
                $propertyType,
                $bedrooms,
                $bathrooms,
                $size,
                $nearestSchool,
                $busAvailability,
                $tramAvailability,
                $agentId,
                $status,
                $reviewStatus,
                $zip,
                $street,
                $state,
                $pool,
                $dogFriendly
            );
            $insertListing->execute();
            $propertyId = $con->insert_id;
            $insertImage->bind_param('is', $propertyId, $imageFile);
            $insertImage->execute();
            $inserted++;
        }

        $insertListing->close();
        $insertImage->close();
        $selectImage->close();
        $updateImage->close();
        $updateDescription->close();
        $con->commit();
        $message = sprintf(
            'Verified: %d example listings belong to this agent account, each with its assigned representative photo. %d were added in this run.',
            count($existingNumbers) + $inserted,
            $inserted
        );
    } catch (Throwable $exception) {
        $con->rollback();
        error_log('Agent example listing seed failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'The listings could not be seeded. No partial listing changes were saved.';
    } finally {
        $releaseStatement = $con->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStatement->bind_param('s', $lockName);
        $releaseStatement->execute();
        $releaseStatement->close();
    }
}

$countStatement = $con->prepare(
    'SELECT COUNT(*) FROM property_listings WHERE seller_id = ? AND title LIKE ?'
);
$likePrefixForCount = '%' . $sampleMarker . '%';
$countStatement->bind_param('is', $agentId, $likePrefixForCount);
$countStatement->execute();
$exampleCount = (int)$countStatement->get_result()->fetch_row()[0];
$countStatement->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seed example listings | EstateScope</title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/marketplace.css">
</head>
<body class="market-page">
<main class="market-section">
    <h1>Example property listings</h1>
    <p>Signed-in agent account: <?= $agentId ?>. Existing examples for this account: <?= $exampleCount ?>/200.</p>
    <p>These are clearly labeled illustrative examples, not verified real properties or offers. Replace them with genuine listings before public use.</p>
    <?php if ($message !== ''): ?><p class="market-feedback" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="market-form-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <button class="market-button" type="submit">Create missing listings and refresh photos</button>
    </form>
    <p><a href="../agent_dashboard.php">Return to agent dashboard</a> · <a href="../my_properties.php">Manage my listings</a></p>
</main>
</body>
</html>

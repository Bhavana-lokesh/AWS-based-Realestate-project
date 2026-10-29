<?php
session_start();
require_once 'config.php';
require_once 'functions.php';
require_once 'auth.php';

requireRole(['seller', 'agent']);
$userId = currentUserId();
$role = currentUserRole();

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$error = '';

if (isset($_POST['add'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $street = trim($_POST['street'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $zip = trim($_POST['zip'] ?? '');
    $propertyType = $_POST['property_type'] ?? '';
    $bedrooms = filter_var($_POST['bedrooms'] ?? null, FILTER_VALIDATE_INT);
    $bathrooms = filter_var($_POST['bathrooms'] ?? null, FILTER_VALIDATE_INT);
    $sizeSqft = filter_var($_POST['size_sqft'] ?? null, FILTER_VALIDATE_INT);
    $poolAvailable = $_POST['pool_available'] ?? '';
    $dogFriendly = $_POST['is_dog_friendly'] ?? '';
    $nearestSchool = trim($_POST['nearest_school'] ?? '');
    $busAvailability = $_POST['bus_availability'] ?? '';
    $tramAvailability = $_POST['tram_availability'] ?? '';

    $requiredText = [$street, $location, $state, $zip];
    $validText = $title !== '' && mb_strlen($title) <= 255 && $description !== '' && mb_strlen($description) <= 10000;
    if ($nearestSchool !== '' && mb_strlen($nearestSchool) > 255) {
        $validText = false;
    }
    foreach ($requiredText as $value) {
        if ($value === '' || mb_strlen($value) > 255) {
            $validText = false;
        }
    }

    $validNumbers = $price !== false && $price > 0 && $price <= 1000000000000 &&
        $bedrooms !== false && $bedrooms >= 0 && $bedrooms <= 50 &&
        $bathrooms !== false && $bathrooms >= 0 && $bathrooms <= 50 &&
        $sizeSqft !== false && $sizeSqft > 0 && $sizeSqft <= 10000000;
    $validOptions = in_array($propertyType, ['Sale', 'Rental'], true) &&
        in_array($poolAvailable, ['Yes', 'No'], true) &&
        in_array($dogFriendly, ['Yes', 'No'], true) &&
        in_array($busAvailability, ['Yes', 'No'], true) &&
        in_array($tramAvailability, ['Yes', 'No'], true);

    $availableDate = null;
    $securityDeposit = null;
    if ($propertyType === 'Rental') {
        $dateInput = $_POST['available_date'] ?? '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $securityDeposit = filter_var($_POST['security_deposit'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!$date || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) ||
            $dateInput < date('Y-m-d') || $securityDeposit === false || $securityDeposit < 0) {
            $validOptions = false;
        } else {
            $availableDate = $date->format('Y-m-d');
        }
    }

    $uploads = [];
    $fileInputs = $_FILES['images'] ?? null;
    if ($fileInputs && is_array($fileInputs['name'] ?? null)) {
        $fileCount = count($fileInputs['name']);
        if ($fileCount > 6) {
            $validOptions = false;
            $error = 'Upload no more than six property images.';
        } else {
            $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            for ($index = 0; $index < $fileCount; $index++) {
                if ($fileInputs['error'][$index] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $mimeType = $fileInputs['error'][$index] === UPLOAD_ERR_OK ? $fileInfo->file($fileInputs['tmp_name'][$index]) : '';
                if ($fileInputs['error'][$index] !== UPLOAD_ERR_OK || $fileInputs['size'][$index] > 5 * 1024 * 1024 || !isset($allowedTypes[$mimeType])) {
                    $validOptions = false;
                    $error = 'Choose JPG, PNG, or WebP images smaller than 5 MB each.';
                    break;
                }
                $uploads[] = ['tmp' => $fileInputs['tmp_name'][$index], 'name' => bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType]];
            }
        }
    }

    if (!$validText || !$validNumbers || !$validOptions) {
        $error = $error ?: 'Check the property information and try again.';
    } else {
        $movedFiles = [];
        $con->begin_transaction();
        try {
            $status = 'available';
            $reviewStatus = 'pending';
            $insert = $con->prepare("INSERT INTO property_listings
                (title, description, price, street, location, state, zip, property_type, bedrooms, bathrooms, size_sqft, pool_available, is_dog_friendly, nearest_school, bus_availability, tram_availability, seller_id, status, review_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $insert->bind_param('ssdsssssiiisssssiss', $title, $description, $price, $street, $location, $state, $zip, $propertyType, $bedrooms, $bathrooms, $sizeSqft, $poolAvailable, $dogFriendly, $nearestSchool, $busAvailability, $tramAvailability, $userId, $status, $reviewStatus);
            $insert->execute();
            $propertyId = $insert->insert_id;
            $insert->close();

            if ($propertyType === 'Rental') {
                $rental = $con->prepare('INSERT INTO rental_contracts (property_id, available_date, security_deposit) VALUES (?, ?, ?)');
                $rental->bind_param('isd', $propertyId, $availableDate, $securityDeposit);
                $rental->execute();
                $rental->close();
            }

            foreach ($uploads as $upload) {
                $target = __DIR__ . '/admin/property/' . $upload['name'];
                if (!move_uploaded_file($upload['tmp'], $target)) {
                    throw new RuntimeException('Image upload failed.');
                }
                $movedFiles[] = $target;
                $imageInsert = $con->prepare('INSERT INTO property_image (property_id, image_url) VALUES (?, ?)');
                $imageInsert->bind_param('is', $propertyId, $upload['name']);
                $imageInsert->execute();
                $imageInsert->close();
            }

            addAuditLog($userId, 'ADD_PROPERTY', 'Added property with ID: ' . $propertyId);
            $con->commit();
            header('Location: my_properties.php?msg=' . urlencode('Listing submitted for Super Admin approval.'));
            exit;
        } catch (Throwable $exception) {
            $con->rollback();
            foreach ($movedFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            error_log('Property creation failed.');
            $error = 'The property could not be saved. Please check your details and try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>List a property | EstateScope</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
<link href="https://fonts.googleapis.com/css?family=Muli:400,400i,500,600,700&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css?family=Comfortaa:400,700" rel="stylesheet">
    <link rel="stylesheet" href="css/role-themes.css">
    <link rel="stylesheet" href="css/marketplace.css">
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const propertyType = document.getElementById('property_type');
            const rentalFields = document.getElementById('rental_fields');
            const availableDate = document.getElementById('available_date');
            const securityDeposit = document.getElementById('security_deposit');

            function toggleRentalFields() {
                const rental = propertyType.value === 'Rental';
                rentalFields.hidden = !rental;
                availableDate.required = rental;
                securityDeposit.required = rental;
            }

            propertyType.addEventListener('change', toggleRentalFields);
            toggleRentalFields();
        });
    </script>
</head>

<body class="market-page" data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
<?php include 'include/header.php'; ?>
<main class="market-section market-dashboard">
    <header class="market-dashboard-heading">
        <div>
            <p class="market-eyebrow">Listing workspace</p>
            <h1>List a property</h1>
            <p>Add the details buyers need to evaluate your listing.</p>
        </div>
    </header>
    <?php if ($error !== ''): ?><div class="market-form-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form class="market-submit-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="market-form-section">
            <h2>Property details</h2>
            <div class="market-form-grid">
                <div class="form-group market-form-wide"><label for="title">Listing title</label><input id="title" type="text" name="title" class="form-control" maxlength="255" required></div>
                <div class="form-group market-form-wide"><label for="description">Description</label><textarea id="description" name="description" class="form-control" rows="5" maxlength="10000" required></textarea></div>
                <div class="form-group"><label for="price">Price (₹)</label><input id="price" type="number" name="price" class="form-control" min="1" step="any" required></div>
                <div class="form-group"><label for="property_type">Listing type</label><select id="property_type" name="property_type" class="form-control" required><option value="">Choose a type</option><option value="Sale">For sale</option><option value="Rental">For rent</option></select></div>
                <div class="form-group"><label for="street">Street address</label><input id="street" type="text" name="street" class="form-control" maxlength="255" required></div>
                <div class="form-group"><label for="location">City or area</label><input id="location" type="text" name="location" class="form-control" maxlength="255" required></div>
                <div class="form-group"><label for="state">State</label><input id="state" type="text" name="state" class="form-control" maxlength="100" required></div>
                <div class="form-group"><label for="zip">ZIP code</label><input id="zip" type="text" name="zip" class="form-control" maxlength="100" required></div>
                <div class="form-group"><label for="bedrooms">Bedrooms</label><input id="bedrooms" type="number" name="bedrooms" class="form-control" min="0" max="50" required></div>
                <div class="form-group"><label for="bathrooms">Bathrooms</label><input id="bathrooms" type="number" name="bathrooms" class="form-control" min="0" max="50" required></div>
                <div class="form-group"><label for="size_sqft">Area (sqft)</label><input id="size_sqft" type="number" name="size_sqft" class="form-control" min="1" required></div>
                <div class="form-group"><label for="nearest_school">Nearby school</label><input id="nearest_school" type="text" name="nearest_school" class="form-control" maxlength="255"></div>
                <div class="form-group"><label for="pool_available">Pool</label><select id="pool_available" name="pool_available" class="form-control"><option value="No">No</option><option value="Yes">Yes</option></select></div>
                <div class="form-group"><label for="is_dog_friendly">Pet friendly</label><select id="is_dog_friendly" name="is_dog_friendly" class="form-control"><option value="No">No</option><option value="Yes">Yes</option></select></div>
                <div class="form-group"><label for="bus_availability">Bus access</label><select id="bus_availability" name="bus_availability" class="form-control"><option value="No">No</option><option value="Yes">Yes</option></select></div>
                <div class="form-group"><label for="tram_availability">Tram access</label><select id="tram_availability" name="tram_availability" class="form-control"><option value="No">No</option><option value="Yes">Yes</option></select></div>
            </div>
        </div>

        <div class="market-form-section" id="rental_fields" hidden>
            <h2>Rental availability</h2>
            <div class="market-form-grid">
                <div class="form-group"><label for="available_date">Available from</label><input id="available_date" type="date" name="available_date" class="form-control" min="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label for="security_deposit">Security deposit (₹)</label><input id="security_deposit" type="number" name="security_deposit" class="form-control" min="0" step="1000"></div>
            </div>
        </div>

        <div class="market-form-section">
            <h2>Property photos</h2>
            <p class="market-form-hint">Upload up to six JPG, PNG, or WebP images, maximum 5 MB each.</p>
            <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" class="form-control" multiple>
        </div>

        <button class="market-button market-submit-button" type="submit" name="add">Create listing</button>
    </form>
</main>
<?php include 'include/footer.php'; ?>
</html>

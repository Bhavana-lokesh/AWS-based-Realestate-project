<?php
session_start();
include("config.php");
include("functions.php"); // ✅ Include your audit log function
require_once 'auth.php';

$user_id = currentUserId();
$role = currentUserRole();
$property_id = filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT);

requireRole(['seller', 'agent']);
if (!$property_id) {
    http_response_code(400);
    exit("A valid property is required.");
}

$check = $con->prepare("SELECT * FROM property_listings WHERE property_id = ? AND seller_id = ? LIMIT 1");
$check->bind_param("ii", $property_id, $user_id);
$check->execute();
$property = $check->get_result()->fetch_assoc();
$check->close();

if (!$property) {
    http_response_code(404);
    exit("Property not found or not owned by you.");
}

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (empty($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token']))) {
    http_response_code(403);
    exit('Invalid request.');
}

$rental_data = [
    'available_date' => '',
    'security_deposit' => ''
];

$rentalCheck = $con->prepare("SELECT available_date, security_deposit FROM rental_contracts WHERE property_id = ? LIMIT 1");
$rentalCheck->bind_param('i', $property_id);
$rentalCheck->execute();
if ($rentalRow = $rentalCheck->get_result()->fetch_assoc()) {
    $rental_data = $rentalRow;
}
$rentalCheck->close();

$imageStatement = $con->prepare("SELECT image_id, image_url FROM property_image WHERE property_id = ? ORDER BY image_id");
$imageStatement->bind_param('i', $property_id);
$imageStatement->execute();
$propertyImages = $imageStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$imageStatement->close();

$buildSnapshot = static function (array $listing, ?array $rental): array {
    $snapshot = [];
    foreach ([
        'title', 'description', 'price', 'street', 'location', 'state', 'zip', 'property_type',
        'bedrooms', 'bathrooms', 'size_sqft', 'pool_available', 'is_dog_friendly', 'nearest_school',
        'bus_availability', 'tram_availability', 'status',
    ] as $field) {
        $snapshot[$field] = (string)($listing[$field] ?? '');
    }
    $snapshot['available_date'] = (string)($rental['available_date'] ?? '');
    $snapshot['security_deposit'] = (string)($rental['security_deposit'] ?? '');
    return $snapshot;
};
$baseVersion = hash('sha256', json_encode(
    $buildSnapshot($property, $rental_data),
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
));
$requestStatement = $con->prepare(
    'SELECT status, rejection_reason FROM property_change_requests
     WHERE property_id = ? AND agent_id = ? ORDER BY request_id DESC LIMIT 1'
);
$requestStatement->bind_param('ii', $property_id, $user_id);
$requestStatement->execute();
$latestChangeRequest = $requestStatement->get_result()->fetch_assoc();
$requestStatement->close();

$msg = "";

// Ensure POST handling works even if the submit button name is omitted (e.g., when disabled via JS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update']) || (isset($_POST['action']) && $_POST['action'] === 'update_property'))) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $street = trim($_POST['street'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $zip = trim($_POST['zip'] ?? '');
    $property_type = $_POST['property_type'] ?? '';
    $bedrooms = filter_var($_POST['bedrooms'] ?? null, FILTER_VALIDATE_INT);
    $bathrooms = filter_var($_POST['bathrooms'] ?? null, FILTER_VALIDATE_INT);
    $size_sqft = filter_var($_POST['size_sqft'] ?? null, FILTER_VALIDATE_INT);
    $pool_available = $_POST['pool_available'] ?? '';
    $is_dog_friendly = $_POST['is_dog_friendly'] ?? '';
    $nearest_school = trim($_POST['nearest_school'] ?? '');
    $bus_availability = $_POST['bus_availability'] ?? '';
    $tram_availability = $_POST['tram_availability'] ?? '';
    $status = $_POST['status'] ?? '';

    $validValues = $title !== '' && mb_strlen($title) <= 255 &&
        $description !== '' && mb_strlen($description) <= 10000 &&
        $price !== false && $price > 0 && $price <= 1000000000000 &&
        in_array($property_type, ['Sale', 'Rental'], true) &&
        $bedrooms !== false && $bedrooms >= 0 && $bedrooms <= 50 &&
        $bathrooms !== false && $bathrooms >= 0 && $bathrooms <= 50 &&
        $size_sqft !== false && $size_sqft > 0 && $size_sqft <= 10000000 &&
        in_array($pool_available, ['Yes', 'No'], true) &&
        in_array($is_dog_friendly, ['Yes', 'No'], true) &&
        in_array($bus_availability, ['Yes', 'No'], true) &&
        in_array($tram_availability, ['Yes', 'No'], true) &&
        in_array($status, ['available', 'hold', 'sold'], true);

    foreach ([$street, $location, $state, $zip] as $requiredText) {
        $validValues = $validValues && $requiredText !== '' && mb_strlen($requiredText) <= 255;
    }
    $validValues = $validValues && mb_strlen($nearest_school) <= 255;

    $available_date = $_POST['available_date'] ?? null;
    $security_deposit = $_POST['security_deposit'] ?? null;
    if ($property_type === 'Rental') {
        $availableDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$available_date);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $securityDeposit = filter_var($security_deposit, FILTER_VALIDATE_FLOAT);
        if (!$availableDate || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) ||
            $availableDate->format('Y-m-d') < date('Y-m-d') || $securityDeposit === false ||
            $securityDeposit < 0 || $securityDeposit > 99999999.99) {
            $validValues = false;
        } else {
            $available_date = $availableDate->format('Y-m-d');
            $security_deposit = $securityDeposit;
        }
    }

    if (!$validValues) {
        $msg = "Check the property information and try again.";
    } else {

        $proposedValues = [
            'title' => $title,
            'description' => $description,
            'price' => (string)$price,
            'street' => $street,
            'location' => $location,
            'state' => $state,
            'zip' => $zip,
            'property_type' => $property_type,
            'bedrooms' => (string)$bedrooms,
            'bathrooms' => (string)$bathrooms,
            'size_sqft' => (string)$size_sqft,
            'pool_available' => $pool_available,
            'is_dog_friendly' => $is_dog_friendly,
            'nearest_school' => $nearest_school,
            'bus_availability' => $bus_availability,
            'tram_availability' => $tram_availability,
            'status' => $status,
            'available_date' => $property_type === 'Rental' ? (string)$available_date : '',
            'security_deposit' => $property_type === 'Rental' ? (string)$security_deposit : '',
        ];

        try {
            $con->begin_transaction();
            $currentStatement = $con->prepare(
                'SELECT * FROM property_listings WHERE property_id = ? AND seller_id = ? FOR UPDATE'
            );
            $currentStatement->bind_param('ii', $property_id, $user_id);
            $currentStatement->execute();
            $currentProperty = $currentStatement->get_result()->fetch_assoc();
            $currentStatement->close();
            if (!$currentProperty) {
                throw new RuntimeException('Property not found or not owned by this agent.');
            }

            $currentRentalStatement = $con->prepare(
                'SELECT available_date, security_deposit FROM rental_contracts WHERE property_id = ? LIMIT 1'
            );
            $currentRentalStatement->bind_param('i', $property_id);
            $currentRentalStatement->execute();
            $currentRental = $currentRentalStatement->get_result()->fetch_assoc();
            $currentRentalStatement->close();
            $currentSnapshot = $buildSnapshot($currentProperty, $currentRental);
            $currentVersion = hash('sha256', json_encode(
                $currentSnapshot,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));

            if (!hash_equals((string)($_POST['base_version'] ?? ''), $currentVersion)) {
                $con->rollback();
                $msg = 'This listing changed while you were editing it. Reload the page and review the current details before submitting again.';
            } else {
                $pendingStatement = $con->prepare(
                    "SELECT request_id FROM property_change_requests
                     WHERE property_id = ? AND status = 'pending' LIMIT 1"
                );
                $pendingStatement->bind_param('i', $property_id);
                $pendingStatement->execute();
                $pendingRequest = $pendingStatement->get_result()->fetch_assoc();
                $pendingStatement->close();

                if ($pendingRequest) {
                    $con->rollback();
                    $msg = 'A property change request is already Pending Approval.';
                } else {
                    $originalJson = json_encode($currentSnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $proposedJson = json_encode($proposedValues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $insertRequest = $con->prepare(
                        "INSERT INTO property_change_requests
                            (property_id, agent_id, original_values, proposed_values, status)
                         VALUES (?, ?, ?, ?, 'pending')"
                    );
                    $insertRequest->bind_param('iiss', $property_id, $user_id, $originalJson, $proposedJson);
                    $insertRequest->execute();
                    $insertRequest->close();
                    $con->commit();
                    addAuditLog($user_id, 'SUBMIT_PROPERTY_CHANGE', 'Submitted property change request for ID: ' . $property_id);
                    $msg = 'Your property changes have been submitted for Super Admin approval.';
                    $latestChangeRequest = ['status' => 'pending', 'rejection_reason' => null];
                }
            }
        } catch (Throwable $exception) {
            $con->rollback();
            error_log('Property change request failed for property ' . $property_id . ': ' . $exception->getMessage());
            $msg = 'The property change request could not be submitted. Please try again.';
        }
    }
}

// ✅ Handle Add More Images (Image Upload)
if (isset($_POST['add_image']) && isset($_FILES['new_image'])) {
    $image = $_FILES['new_image'];
    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $imageType = $image['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($image['tmp_name']) : '';

    if ($image['error'] !== UPLOAD_ERR_OK || $image['size'] > 5 * 1024 * 1024 || !isset($allowedTypes[$imageType])) {
        $msg = "❌ Choose a JPG, PNG, or WebP image smaller than 5 MB.";
    } else {
        $image_name = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$imageType];
        $target_file = __DIR__ . '/admin/property/' . $image_name;
    }

    if (isset($target_file) && move_uploaded_file($image['tmp_name'], $target_file)) {
        $stmt = $con->prepare("INSERT INTO property_image (property_id, image_url) VALUES (?, ?)");
        $stmt->bind_param("is", $property_id, $image_name);
        if ($stmt->execute()) {
            addAuditLog($user_id, 'ADD_PROPERTY_IMAGE', 'Added new image for Property ID: ' . $property_id);
            $msg = "✅ Image uploaded successfully.";
        }
    } elseif (isset($target_file)) {
        $msg = "❌ Failed to upload image.";
    }
}

if (isset($_POST['delete_image'])) {
    $imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);
    if (!$imageId) {
        http_response_code(400);
        exit('A valid image is required.');
    }

    $imageLookup = $con->prepare('SELECT image_url FROM property_image WHERE image_id = ? AND property_id = ? LIMIT 1');
    $imageLookup->bind_param('ii', $imageId, $property_id);
    $imageLookup->execute();
    $image = $imageLookup->get_result()->fetch_assoc();
    $imageLookup->close();
    if (!$image) {
        http_response_code(404);
        exit('Property image not found.');
    }

    $deleteImage = $con->prepare('DELETE FROM property_image WHERE image_id = ? AND property_id = ?');
    $deleteImage->bind_param('ii', $imageId, $property_id);
    $deleteImage->execute();
    if ($deleteImage->affected_rows !== 1) {
        http_response_code(409);
        exit('The property image could not be removed.');
    }
    $deleteImage->close();

    $remainingImage = $con->prepare('SELECT image_id FROM property_image WHERE image_url = ? LIMIT 1');
    $remainingImage->bind_param('s', $image['image_url']);
    $remainingImage->execute();
    $isShared = (bool)$remainingImage->get_result()->fetch_assoc();
    $remainingImage->close();
    $imagePath = __DIR__ . '/admin/property/' . basename($image['image_url']);
    if (!$isShared && is_file($imagePath) && !unlink($imagePath)) {
        error_log('Unable to remove unreferenced property image: ' . basename($imagePath));
    }
    addAuditLog($user_id, 'DELETE_PROPERTY_IMAGE', 'Removed image from Property ID: ' . $property_id);
    $msg = 'Property photo removed.';
    $imageStatement = $con->prepare('SELECT image_id, image_url FROM property_image WHERE property_id = ? ORDER BY image_id');
    $imageStatement->bind_param('i', $property_id);
    $imageStatement->execute();
    $propertyImages = $imageStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $imageStatement->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit property | EstateScope</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css?family=Muli:400,400i,500,600,700&amp;display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Comfortaa:400,700" rel="stylesheet">
    <link rel="stylesheet" href="css/role-themes.css">
    <link rel="stylesheet" href="css/marketplace.css">
</head>
<body class="market-page" data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
<?php include 'include/header.php'; ?>
    <main class="market-section market-dashboard market-edit-page">
        <a class="market-text-link" id="back-to-properties" href="my_properties.php">My properties</a>
        <header class="market-dashboard-heading">
            <div>
                <p class="market-eyebrow">Listing workspace</p>
                <h1>Edit property</h1>
                <p><?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </header>
        <?php if (!empty($msg)): ?><div class="market-feedback" role="status"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($latestChangeRequest): ?>
            <?php
            $changeRequestStatus = match ($latestChangeRequest['status']) {
                'pending' => 'Pending Approval',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                default => ucfirst($latestChangeRequest['status']),
            };
            ?>
            <p class="market-status-note">Latest change request: <?= htmlspecialchars($changeRequestStatus, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($latestChangeRequest['status'] === 'rejected' && !empty($latestChangeRequest['rejection_reason'])): ?>
                    · <?= htmlspecialchars($latestChangeRequest['rejection_reason'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <section class="market-edit-form">
        <form method="POST" class="market-edit-grid">
            <input type="hidden" name="action" value="update_property">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="base_version" value="<?= htmlspecialchars($baseVersion, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group"><label for="edit-title">Title:</label><input id="edit-title" type="text" name="title" class="form-control" maxlength="255" value="<?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="form-group"><label for="edit-price">Price:</label><input id="edit-price" type="number" name="price" class="form-control" min="0" step="any" value="<?= htmlspecialchars((string)$property['price'], ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="form-group"><label for="edit-street">Street:</label><input id="edit-street" type="text" name="street" class="form-control" value="<?= htmlspecialchars($property['street'], ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="form-group market-form-wide"><label for="edit-description">Description:</label><textarea id="edit-description" name="description" class="form-control" rows="5" required><?= htmlspecialchars($property['description'], ENT_QUOTES, 'UTF-8') ?></textarea></div>
            <div class="form-group"><label>City:</label><input type="text" name="location" class="form-control" value="<?= htmlspecialchars($property['location']) ?>" required></div>
            <div class="form-group"><label>State:</label><input type="text" name="state" class="form-control" value="<?= htmlspecialchars($property['state']) ?>" required></div>
            <div class="form-group"><label>Zip:</label><input type="text" name="zip" class="form-control" value="<?= htmlspecialchars($property['zip']) ?>" required></div>
            <div class="form-group"><label>Property Type:</label>
                <select name="property_type" class="form-control" id="property_type" required>

                    <option value="Sale" <?= $property['property_type'] === 'Sale' ? 'selected' : '' ?>>Sale</option>
                    <option value="Rental" <?= $property['property_type'] === 'Rental' ? 'selected' : '' ?>>Rental</option>
                </select>
            </div>
                

            <div id="rental_fields" style="display: none;">
            <div class="form-group">
             <label>Available Date:</label>
                 <input type="date" name="available_date" class="form-control" 
                 value="<?= htmlspecialchars($rental_data['available_date']) ?>">
                 </div>
                <div class="form-group">
                <label>Security Deposit:</label>
                    <input type="number" name="security_deposit" class="form-control" 
                    value="<?= htmlspecialchars($rental_data['security_deposit']) ?>">
                    </div>
            </div>

            <div class="form-group"><label>Bedrooms:</label><input type="number" name="bedrooms" class="form-control" value="<?= $property['bedrooms'] ?>" required></div>
            <div class="form-group"><label>Bathrooms:</label><input type="number" name="bathrooms" class="form-control" value="<?= $property['bathrooms'] ?>" required></div>
            <div class="form-group"><label>Size (sqft):</label><input type="number" name="size_sqft" class="form-control" value="<?= $property['size_sqft'] ?>" required></div>
            <div class="form-group"><label>Pool Available:</label>
                <select name="pool_available" class="form-control">
                    <option value="Yes" <?= $property['pool_available'] === 'Yes' ? 'selected' : '' ?>>Yes</option>
                    <option value="No" <?= $property['pool_available'] === 'No' ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="form-group"><label>Dog Friendly:</label>
                <select name="is_dog_friendly" class="form-control">
                    <option value="Yes" <?= $property['is_dog_friendly'] === 'Yes' ? 'selected' : '' ?>>Yes</option>
                    <option value="No" <?= $property['is_dog_friendly'] === 'No' ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="form-group"><label>Nearest School:</label><input type="text" name="nearest_school" class="form-control" value="<?= htmlspecialchars($property['nearest_school']) ?>"></div>
            <div class="form-group"><label>Bus Availability:</label>
                <select name="bus_availability" class="form-control">
                    <option value="Yes" <?= $property['bus_availability'] === 'Yes' ? 'selected' : '' ?>>Yes</option>
                    <option value="No" <?= $property['bus_availability'] === 'No' ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="form-group"><label>Tram Availability:</label>
                <select name="tram_availability" class="form-control">
                    <option value="Yes" <?= $property['tram_availability'] === 'Yes' ? 'selected' : '' ?>>Yes</option>
                    <option value="No" <?= $property['tram_availability'] === 'No' ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="form-group"><label>Status:</label>
                <select name="status" class="form-control" required>
                    <option value="available" <?= $property['status'] === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="hold" <?= $property['status'] === 'hold' ? 'selected' : '' ?>>On hold</option>
                    <option value="sold" <?= $property['status'] === 'sold' ? 'selected' : '' ?>>Sold</option>
                </select>
            </div>
            <button type="submit" name="update" class="market-button">Save changes</button>
        </form>

        <h2>Add property photos</h2>
        <p class="market-form-hint">JPG, PNG, or WebP images under 5 MB.</p>
        <form method="POST" enctype="multipart/form-data" class="market-edit-upload">
            <input type="hidden" name="action" value="update_property">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <label for="new-image">Choose an image</label>
                <input id="new-image" type="file" name="new_image" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <button type="submit" name="add_image" class="market-button market-button-secondary">Upload image</button>
        </form>
        </section>

        <section class="market-edit-gallery">
            <h2>Current photos</h2>
            <?php if ($propertyImages): ?>
                <div class="market-edit-image-grid">
                    <?php foreach ($propertyImages as $image): ?>
                        <div class="market-edit-image-item">
                            <img src="admin/property/<?= htmlspecialchars(basename($image['image_url']), ENT_QUOTES, 'UTF-8') ?>" alt="Photo for <?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?>">
                            <form method="post">
                                <input type="hidden" name="action" value="update_property">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="image_id" value="<?= (int)$image['image_id'] ?>">
                                <button class="market-image-remove" type="submit" name="delete_image">Remove photo</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="market-empty-state">No photos uploaded for this listing.</div>
            <?php endif; ?>
        </section>
    </main>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const propertyType = document.getElementById('property_type');
        const rentalFields = document.getElementById('rental_fields');
        const editForm = document.querySelector('.market-edit-grid');
        const backLink = document.getElementById('back-to-properties');
        const saveButton = editForm.querySelector('button[name="update"]');
        const originalValues = new FormData(editForm);
        const originalEntries = Array.from(originalValues.entries());

        function toggleRentalFields() {
            if (propertyType.value.toLowerCase() === 'rental') {
                rentalFields.style.display = 'block';
            } else {
                rentalFields.style.display = 'none';
            }
        }

        propertyType.addEventListener('change', toggleRentalFields);
        editForm.addEventListener('submit', function () {
            saveButton.disabled = true;
        });
        backLink.addEventListener('click', function (event) {
            const currentEntries = Array.from(new FormData(editForm).entries());
            const hasUnsavedChanges = currentEntries.some(function (entry, index) {
                return !originalEntries[index] || originalEntries[index][0] !== entry[0] || originalEntries[index][1] !== entry[1];
            }) || currentEntries.length !== originalEntries.length;
            if (hasUnsavedChanges && !window.confirm('Discard your unsaved changes and return to My Properties?')) {
                event.preventDefault();
            }
        });
        toggleRentalFields(); // ✅ Call once to show/hide on page load based on existing value
    });
</script>

</body>
<?php include("include/footer.php"); ?>
</html>

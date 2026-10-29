<?php
session_start();
require("config.php");
require_once '../auth.php';
requireRole(['super_admin']);
require("../functions.php");

$error = "";
$msg = "";
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

if (isset($_POST['add'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $street = trim((string)($_POST['street'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $state = trim((string)($_POST['state'] ?? ''));
    $zip = trim((string)($_POST['zip'] ?? ''));
    $propertyType = (string)($_POST['property_type'] ?? '');
    $bedrooms = filter_var($_POST['bedrooms'] ?? null, FILTER_VALIDATE_INT);
    $bathrooms = filter_var($_POST['bathrooms'] ?? null, FILTER_VALIDATE_INT);
    $sizeSqft = filter_var($_POST['size_sqft'] ?? null, FILTER_VALIDATE_INT);
    $nearestSchool = trim((string)($_POST['nearest_school'] ?? ''));
    $busAvailability = (string)($_POST['bus_availability'] ?? '');
    $tramAvailability = (string)($_POST['tram_availability'] ?? '');
    $poolAvailable = (string)($_POST['pool_available'] ?? '');
    $isDogFriendly = (string)($_POST['is_dog_friendly'] ?? '');
    $status = (string)($_POST['status'] ?? '');
    $sellerId = filter_var($_POST['seller_id'] ?? null, FILTER_VALIDATE_INT);
    $rentalDate = (string)($_POST['available_date'] ?? '');
    $securityDeposit = filter_var($_POST['security_deposit'] ?? null, FILTER_VALIDATE_FLOAT);
    $image = $_FILES['image'] ?? null;
    $validImageTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $valid = $title !== ''
        && strlen($title) <= 255
        && $description !== ''
        && strlen($description) <= 60000
        && $price !== false
        && $price >= 0
        && $price <= 9999999999999.99
        && strlen($street) <= 255
        && strlen($location) <= 255
        && strlen($state) <= 100
        && strlen($zip) <= 100
        && in_array($propertyType, ['Sale', 'Rental'], true)
        && $bedrooms !== false && $bedrooms >= 0
        && $bathrooms !== false && $bathrooms >= 0
        && $sizeSqft !== false && $sizeSqft >= 0
        && strlen($nearestSchool) <= 255
        && in_array($busAvailability, ['Yes', 'No'], true)
        && in_array($tramAvailability, ['Yes', 'No'], true)
        && in_array($poolAvailable, ['Yes', 'No'], true)
        && in_array($isDogFriendly, ['Yes', 'No'], true)
        && in_array($status, ['available', 'sold'], true)
        && $sellerId !== false && $sellerId > 0
        && is_array($image)
        && ($image['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && is_string($image['tmp_name'] ?? null)
        && ($image['size'] ?? 0) > 0
        && ($image['size'] ?? 0) <= 5 * 1024 * 1024;

    if ($propertyType === 'Rental') {
        $date = DateTime::createFromFormat('!Y-m-d', $rentalDate);
        $valid = $valid
            && $date !== false
            && $date->format('Y-m-d') === $rentalDate
            && $securityDeposit !== false
            && $securityDeposit >= 0
            && $securityDeposit <= 99999999.99;
    }

    $imageMimeType = null;
    if ($valid && class_exists('finfo') && is_uploaded_file($image['tmp_name'] ?? '')) {
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $imageMimeType = $fileInfo->file($image['tmp_name']);
        $valid = isset($validImageTypes[$imageMimeType]);
    } else {
        $valid = false;
    }

    if (!$valid) {
        $error = "<p class='alert alert-warning'>Enter valid property details and upload a JPEG, PNG, or WebP image (maximum 5 MB).</p>";
    } else {
        $agent = $con->prepare("SELECT user_id FROM user
            WHERE user_id = ? AND LOWER(role) = 'agent' AND is_active = 1 LIMIT 1");
        if (!$agent) {
            throw new RuntimeException('Agent account could not be checked.');
        }
        $agent->bind_param('i', $sellerId);
        $agent->execute();
        $agentExists = (bool)$agent->get_result()->fetch_assoc();
        $agent->close();

        if (!$agentExists) {
            $error = "<p class='alert alert-warning'>Select an active Agent account.</p>";
        } else {
            $uploadDirectory = __DIR__ . '/property';
            $imageName = bin2hex(random_bytes(16)) . '.' . $validImageTypes[$imageMimeType];
            $imagePath = $uploadDirectory . '/' . $imageName;
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                $error = "<p class='alert alert-warning'>Property image storage is unavailable.</p>";
            } elseif (!move_uploaded_file($image['tmp_name'], $imagePath)) {
                $error = "<p class='alert alert-warning'>Property image could not be saved.</p>";
            } else {
                try {
                    $con->begin_transaction();
                    $insert = $con->prepare("INSERT INTO property_listings
                        (title, description, price, street, location, state, zip, property_type, bedrooms,
                         bathrooms, size_sqft, nearest_school, bus_availability, tram_availability,
                         pool_available, is_dog_friendly, seller_id, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    if (!$insert) {
                        throw new RuntimeException('Property could not be prepared.');
                    }
                    $insert->bind_param(
                        'ssdsssssiiisssssis',
                        $title,
                        $description,
                        $price,
                        $street,
                        $location,
                        $state,
                        $zip,
                        $propertyType,
                        $bedrooms,
                        $bathrooms,
                        $sizeSqft,
                        $nearestSchool,
                        $busAvailability,
                        $tramAvailability,
                        $poolAvailable,
                        $isDogFriendly,
                        $sellerId,
                        $status
                    );
                    if (!$insert->execute()) {
                        throw new RuntimeException('Property could not be saved.');
                    }
                    $propertyId = (int)$insert->insert_id;
                    $insert->close();

                    $imageInsert = $con->prepare('INSERT INTO property_image (property_id, image_url) VALUES (?, ?)');
                    if (!$imageInsert) {
                        throw new RuntimeException('Property image could not be prepared.');
                    }
                    $imageInsert->bind_param('is', $propertyId, $imageName);
                    if (!$imageInsert->execute()) {
                        throw new RuntimeException('Property image could not be saved.');
                    }
                    $imageInsert->close();

                    if ($propertyType === 'Rental') {
                        $rentalInsert = $con->prepare('INSERT INTO rental_contracts
                            (property_id, available_date, security_deposit) VALUES (?, ?, ?)');
                        if (!$rentalInsert) {
                            throw new RuntimeException('Rental details could not be prepared.');
                        }
                        $rentalInsert->bind_param('isd', $propertyId, $rentalDate, $securityDeposit);
                        if (!$rentalInsert->execute()) {
                            throw new RuntimeException('Rental details could not be saved.');
                        }
                        $rentalInsert->close();
                    }
                    addAuditLog((int)$_SESSION['uid'], 'ADMIN_ADD_PROPERTY', 'Super Admin added property ' . $propertyId);
                    $con->commit();
                    $msg = "<p class='alert alert-success'>Property created and awaiting approval.</p>";
                } catch (Throwable $exception) {
                    $con->rollback();
                    if (is_file($imagePath)) {
                        unlink($imagePath);
                    }
                    error_log('Super Admin property creation failed.');
                    $error = "<p class='alert alert-warning'>Property could not be added. Please try again.</p>";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Add Property - Admin | LM Homes</title>
	<link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=20261024">
    <link rel="stylesheet" href="assets/css/feathericon.min.css">
    <link rel="stylesheet" href="assets/plugins/morris/morris.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include("header.php"); ?>

<div class="page-wrapper">
    <div class="content container-fluid">
        <div class="page-header">
            <h3 class="page-title">Add Property</h3>
        </div>

        <?php echo $msg; ?><?php echo $error; ?>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="card p-4">
                <h5>Basic Information</h5>
                <div class="form-group"><label>Title</label><input type="text" name="title" class="form-control" required></div>
                <div class="form-group"><label>Description</label><textarea name="description" class="form-control" required></textarea></div>
                <div class="form-group"><label>Price</label><input type="number" name="price" class="form-control" required></div>

                <h5 class="mt-4">Address Details</h5>
                <div class="form-group"><label>Street</label><input type="text" name="street" class="form-control" required></div>
                <div class="form-group"><label>City</label><input type="text" name="location" class="form-control" required></div>
                <div class="form-group"><label>State</label><input type="text" name="state" class="form-control" required></div>
                <div class="form-group"><label>Zip</label><input type="text" name="zip" class="form-control" required></div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Property Type</label>
                        <select name="property_type" class="form-control" id="property_type" required>
                            <option value="">Select</option>
                            <option value="Sale" <?= (isset($row['property_type']) && $row['property_type'] === 'Sale') ? 'selected' : '' ?>>Sale</option>
                            <option value="Rental" <?= (isset($row['property_type']) && $row['property_type'] === 'Rental') ? 'selected' : '' ?>>Rental</option>
                        </select>

                    </div>
                    <div class="form-group col-md-6">
                        <label>Status</label>
                        <select name="status" class="form-control" required>
                            <option value="available">Available</option>
                            <option value="sold">Sold</option>
                        </select>
                    </div>
                </div>

                <div id="rental_fields" style="display:none;">
                    <h6 class="mt-3">Rental Contract Details</h6>
                    <div class="form-group"><label>Available Date</label><input type="date" name="available_date" class="form-control"></div>
                    <div class="form-group"><label>Security Deposit</label><input type="number" name="security_deposit" class="form-control"></div>
                </div>

                <h5 class="mt-4">Additional Details</h5>
                <div class="form-row">
                    <div class="form-group col-md-4"><label>Bedrooms</label><input type="number" name="bedrooms" class="form-control" required></div>
                    <div class="form-group col-md-4"><label>Bathrooms</label><input type="number" name="bathrooms" class="form-control" required></div>
                    <div class="form-group col-md-4"><label>Size (sqft)</label><input type="number" name="size_sqft" class="form-control" required></div>
                </div>
                <div class="form-row">
                   
                    <div class="form-group col-md-3">
                        <label>Tram Availability</label>
                        <select name="tram_availability" class="form-control">
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label>Bus Availability</label>
                        <select name="bus_availability" class="form-control">
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label>Nearest School</label><input type="text" name="nearest_school" class="form-control"></div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Pool Available</label>
                        <select name="pool_available" class="form-control">
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Dog Friendly</label>
                        <select name="is_dog_friendly" class="form-control">
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Property Image</label>
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                </div>

                <div class="form-group">
                    <label>Select Agent (User)</label>
                    <select name="seller_id" class="form-control" required>
                        <option value="">Select User</option>
                        <?php
                        $q = mysqli_query($con, "SELECT user_id, name, role FROM user WHERE LOWER(role) = 'agent' AND is_active = 1");
                        while ($u = mysqli_fetch_assoc($q)) {
                            echo '<option value="' . (int)$u['user_id'] . '">'
                                . htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8')
                                . ' (' . htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8') . ')</option>';
                        }
                        ?>
                    </select>
                </div>

                <input type="submit" name="add" value="Submit Property" class="btn btn-primary">
            </div>
        </form>
    </div>
</div>

<script src="assets/js/jquery-3.2.1.min.js"></script>
    <script src="assets/js/popper.min.js"></script>
    <script src="assets/js/bootstrap.min.js"></script>
    <script src="assets/plugins/slimscroll/jquery.slimscroll.min.js"></script>
    <script src="assets/plugins/raphael/raphael.min.js"></script>
    <script src="assets/plugins/morris/morris.min.js"></script>
    <script src="assets/js/chart.morris.js?v=20261024"></script>
    <script src="assets/js/script.js?v=20261024-2"></script>
<script>
    document.getElementById("property_type").addEventListener("change", function () {
        const val = this.value.toLowerCase();
        document.getElementById("rental_fields").style.display = val === "rental" ? "block" : "none";
    });
</script>
</body>
</html>

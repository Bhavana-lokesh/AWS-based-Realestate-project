<?php
session_start();
require("config.php");
require_once '../auth.php';
requireRole(['super_admin']);
require("../functions.php");

$msg = "";

if (isset($_POST['update'])) {
    $property_id = $_POST['property_id'];
    $title = $_POST['title'];
    $property_type = $_POST['property_type'];
    $bedrooms = $_POST['bedrooms'];
    $bathrooms = $_POST['bathrooms'];
    $size_sqft = $_POST['size_sqft'];
    $price = $_POST['price'];
    $street = $_POST['street'];
    $location = $_POST['location'];
    $state = $_POST['state'];
    $zip = $_POST['zip'];
    $pool_available = $_POST['pool_available'];
    $is_dog_friendly = $_POST['is_dog_friendly'];
    $nearest_school = $_POST['nearest_school'];
    $bus_availability = $_POST['bus_availability'];
    $tram_availability = $_POST['tram_availability'];
    $status = $_POST['status'];
    $available_date = $_POST['available_date'] ?? null;
    $security_deposit = $_POST['security_deposit'] ?? null;


    // ✅ Image upload handling
    $image = $_FILES['image']['name'];
    $temp_image = $_FILES['image']['tmp_name'];
    $image_query = "";
    if (!empty($image)) {
        move_uploaded_file($temp_image, "property/$image");
        $image_query = ", image_url = '$image'";
    }

    $sql = "UPDATE property_listings 
            SET title = '$title', property_type = '$property_type', bedrooms = '$bedrooms', bathrooms = '$bathrooms',
                size_sqft = '$size_sqft', price = '$price', street = '$street', location = '$location', state = '$state', 
                zip = '$zip', pool_available = '$pool_available', is_dog_friendly = '$is_dog_friendly', 
                nearest_school = '$nearest_school', bus_availability = '$bus_availability', 
                tram_availability = '$tram_availability', status = '$status' $image_query
            WHERE property_id = '$property_id'";

    $result = mysqli_query($con, $sql);
    if ($result) {
        // ✅ Handle Rental Contracts (Insert / Update / Delete)
        if (strtolower($property_type) === 'rental') {
            // Check if rental contract already exists
            $rental_check = mysqli_query($con, "SELECT * FROM rental_contracts WHERE property_id = '$property_id'");
    
            if (mysqli_num_rows($rental_check) > 0) {
                // Update existing rental contract
                $update_rental = mysqli_prepare($con, "UPDATE rental_contracts SET available_date = ?, security_deposit = ? WHERE property_id = ?");
                mysqli_stmt_bind_param($update_rental, "sdi", $available_date, $security_deposit, $property_id);
                mysqli_stmt_execute($update_rental);
            } else {
                // Insert new rental contract
                $insert_rental = mysqli_prepare($con, "INSERT INTO rental_contracts (property_id, available_date, security_deposit) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($insert_rental, "isd", $property_id, $available_date, $security_deposit);
                mysqli_stmt_execute($insert_rental);
            }
        } else {
            // ✅ Delete rental contract if property type is changed to Sale
            mysqli_query($con, "DELETE FROM rental_contracts WHERE property_id = '$property_id'");
        }
    
        // ✅ Continue with audit log and redirect
        header("Location: propertyview.php?msg=Property+Updated+Successfully");
        addAuditLog($_SESSION['uid'], 'ADMIN_EDIT_PROPERTY', 'Admin edited property with ID: ' . $property_id);
        exit();
    } else {
        $msg = "<p class='alert alert-warning'>Failed to Update Property</p>";
    }
    
}

if (isset($_GET['id'])) {
    $property_id = $_GET['id'];
    $query = mysqli_query($con, "SELECT * FROM property_listings WHERE property_id = '$property_id'");
    $row = mysqli_fetch_assoc($query);
        // ✅ Load rental contract data if exists
    $rental_data = mysqli_query($con, "SELECT * FROM rental_contracts WHERE property_id = '$property_id'");
    $rental_row = mysqli_fetch_assoc($rental_data);
    
} else {
    header("Location: propertyview.php?msg=No+Property+Selected");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <title>LM HOMES | Edit Property</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=20261024">
    <link rel="stylesheet" href="assets/css/feathericon.min.css">
    
    <link rel="stylesheet" href="assets/css/style.css">
    
</head>

<body>
<?php include("header.php"); ?>

<div class="container mt-5">
    <h2>Edit Property Details</h2>
    <?php echo $msg; ?>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="property_id" value="<?php echo $row['property_id']; ?>">

        <div class="form-group"><label>Title</label><input type="text" name="title" class="form-control" required value="<?php echo $row['title']; ?>"></div>
       
        <div class="form-group"><label>Property Type:</label>
        <select name="property_type" class="form-control" id="property_type" required>
        <option value="Sale" <?= $row['property_type'] === 'Sale' ? 'selected' : '' ?>>Sale</option>
        <option value="Rental" <?= $row['property_type'] === 'Rental' ? 'selected' : '' ?>>Rental</option>
        </select>
        </div>


        <div id="rental_fields" style="display: none;">
        <div class="form-group">
        <label>Available Date:</label>
        <input type="date" name="available_date" class="form-control"
               value="<?= isset($rental_row['available_date']) ? $rental_row['available_date'] : '' ?>">

               
        </div>
        <div class="form-group">
        <label>Security Deposit:</label>
        <input type="number" name="security_deposit" class="form-control"
               value="<?= isset($rental_row['security_deposit']) ? $rental_row['security_deposit'] : '' ?>">

        </div>
        </div>


        <div class="form-group"><label>Bedrooms</label><input type="number" name="bedrooms" class="form-control" required value="<?php echo $row['bedrooms']; ?>"></div>
        <div class="form-group"><label>Bathrooms</label><input type="number" name="bathrooms" class="form-control" required value="<?php echo $row['bathrooms']; ?>"></div>
        <div class="form-group"><label>Size (sqft)</label><input type="number" name="size_sqft" class="form-control" required value="<?php echo $row['size_sqft']; ?>"></div>
        <div class="form-group"><label>Price</label><input type="number" name="price" class="form-control" required value="<?php echo $row['price']; ?>"></div>

        <div class="form-group"><label>Street</label><input type="text" name="street" class="form-control" required value="<?php echo $row['street']; ?>"></div>
        <div class="form-group"><label>City</label><input type="text" name="location" class="form-control" required value="<?php echo $row['location']; ?>"></div>
        <div class="form-group"><label>State</label><input type="text" name="state" class="form-control" required value="<?php echo $row['state']; ?>"></div>
        <div class="form-group"><label>Zip</label><input type="text" name="zip" class="form-control" required value="<?php echo $row['zip']; ?>"></div>

        <div class="form-group"><label>Pool Available</label>
            <select name="pool_available" class="form-control">
                <option value="Yes" <?php if($row['pool_available'] == 'Yes') echo 'selected'; ?>>Yes</option>
                <option value="No" <?php if($row['pool_available'] == 'No') echo 'selected'; ?>>No</option>
            </select>
        </div>

        <div class="form-group"><label>Dog Friendly</label>
            <select name="is_dog_friendly" class="form-control">
                <option value="Yes" <?php if($row['is_dog_friendly'] == 'Yes') echo 'selected'; ?>>Yes</option>
                <option value="No" <?php if($row['is_dog_friendly'] == 'No') echo 'selected'; ?>>No</option>
            </select>
        </div>

        <div class="form-group"><label>Nearest School</label><input type="text" name="nearest_school" class="form-control" value="<?php echo $row['nearest_school']; ?>"></div>
        <div class="form-group"><label>Bus Availability</label>
            <select name="bus_availability" class="form-control">
                <option value="Yes" <?php if($row['bus_availability'] == 'Yes') echo 'selected'; ?>>Yes</option>
                <option value="No" <?php if($row['bus_availability'] == 'No') echo 'selected'; ?>>No</option>
            </select>
        </div>
        <div class="form-group"><label>Tram Availability</label>
            <select name="tram_availability" class="form-control">
                <option value="Yes" <?php if($row['tram_availability'] == 'Yes') echo 'selected'; ?>>Yes</option>
                <option value="No" <?php if($row['tram_availability'] == 'No') echo 'selected'; ?>>No</option>
            </select>
        </div>

        <div class="form-group"><label>Status</label>
            <select name="status" class="form-control" required>
                <option value="available" <?php if($row['status'] == 'available') echo 'selected'; ?>>Available</option>
                <option value="sold out" <?php if($row['status'] == 'sold out') echo 'selected'; ?>>Sold Out</option>
            </select>
        </div>

        <div class="form-group"><label>Change Image (Optional)</label><br>
            <input type="file" name="image" class="form-control">
            <?php if (!empty($row['image_url'])): ?>
                <br><img src="property/<?php echo $row['image_url']; ?>" width="150" height="150">
            <?php endif; ?>
        </div>

        <button type="submit" name="update" class="btn btn-primary">Update Property</button>
    </form>
</div>

<!-- Scripts -->
<script src="assets/js/jquery-3.2.1.min.js"></script>
    <script src="assets/js/popper.min.js"></script>
    <script src="assets/js/bootstrap.min.js"></script>
    <script src="assets/plugins/slimscroll/jquery.slimscroll.min.js"></script>
    <script src="assets/plugins/datatables/jquery.dataTables.min.js"></script>
    <script src="assets/plugins/datatables/dataTables.bootstrap4.min.js"></script>
    <script src="assets/plugins/datatables/dataTables.responsive.min.js"></script>
    <script src="assets/plugins/datatables/responsive.bootstrap4.min.js"></script>
    <script src="assets/plugins/datatables/dataTables.select.min.js"></script>
    <script src="assets/plugins/datatables/dataTables.buttons.min.js"></script>
    <script src="assets/plugins/datatables/buttons.bootstrap4.min.js"></script>
    <script src="assets/plugins/datatables/buttons.html5.min.js"></script>
    <script src="assets/plugins/datatables/buttons.flash.min.js"></script>
    <script src="assets/plugins/datatables/buttons.print.min.js"></script>
    <script src="assets/js/script.js?v=20261024-2"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const propertyType = document.getElementById('property_type');
        const rentalFields = document.getElementById('rental_fields');

        function toggleRentalFields() {
            if (propertyType.value.toLowerCase() === 'rental') {
                rentalFields.style.display = 'block';
            } else {
                rentalFields.style.display = 'none';
            }
        }

        // Trigger toggle on page load
        toggleRentalFields();

        // Add event listener for changes
        propertyType.addEventListener('change', toggleRentalFields);
    });
</script>

</body>
</html>

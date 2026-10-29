<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

$userId = currentUserId();
$role = currentUserRole();
$propertyId = filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT);
requireRole(['agent']);
if (!$propertyId || $propertyId < 1) {
    http_response_code(400);
    exit('A valid property is required.');
}

$ownerCheck = $con->prepare("SELECT seller_id FROM property_listings
    WHERE property_id = ? AND property_type = 'Sale' LIMIT 1");
$ownerCheck->bind_param('i', $propertyId);
$ownerCheck->execute();
$property = $ownerCheck->get_result()->fetch_assoc();
$ownerCheck->close();
if (!$property) {
    http_response_code(404);
    exit('Sale property not found.');
}
if ((int)$property['seller_id'] !== $userId) {
    http_response_code(403);
    exit('You do not own this property.');
}

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $offerId = filter_input(INPUT_POST, 'offer_id', FILTER_VALIDATE_INT);
    $action = isset($_POST['accept']) ? 'Accept' : (isset($_POST['reject']) ? 'Reject' : '');
    if (!$offerId || !in_array($action, ['Accept', 'Reject'], true) ||
        (isset($_POST['accept']) && isset($_POST['reject']))) {
        http_response_code(400);
        exit('Invalid offer action.');
    }

    try {
        $con->begin_transaction();
        $status = $action === 'Accept' ? 'Accepted' : 'Rejected';
        $updateOffer = $con->prepare("UPDATE offer SET status = ?
            WHERE offer_id = ? AND property_id = ? AND status = 'Pending'");
        $updateOffer->bind_param('sii', $status, $offerId, $propertyId);
        $updateOffer->execute();
        if ($updateOffer->affected_rows !== 1) {
            $updateOffer->close();
            $con->rollback();
            http_response_code(404);
            exit('Pending offer not found for this property.');
        }
        $updateOffer->close();

        if ($action === 'Accept') {
            $rejectOthers = $con->prepare("UPDATE offer SET status = 'Rejected'
                WHERE property_id = ? AND offer_id <> ? AND status = 'Pending'");
            $rejectOthers->bind_param('ii', $propertyId, $offerId);
            $rejectOthers->execute();
            $rejectOthers->close();

            $holdProperty = $con->prepare("UPDATE property_listings SET status = 'Hold'
                WHERE property_id = ? AND seller_id = ? AND status = 'available'");
            $holdProperty->bind_param('ii', $propertyId, $userId);
            $holdProperty->execute();
            if ($holdProperty->affected_rows !== 1) {
                throw new RuntimeException('The property is no longer available.');
            }
            $holdProperty->close();
        }
        $con->commit();
        $_SESSION['message'] = $action === 'Accept'
            ? 'Offer accepted. Property status set to Hold.'
            : 'Offer rejected.';
    } catch (Throwable $exception) {
        $con->rollback();
        error_log('Offer update failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('The offer could not be updated. Please try again.');
    }

    if (($_POST['return'] ?? '') === 'dashboard') {
        header('Location: agent_dashboard.php#offers');
    } else {
        header('Location: view_offers.php?property_id=' . $propertyId);
    }
    exit;
}

$offersStatement = $con->prepare("SELECT o.offer_id, o.offer_price, o.offer_date, o.status,
        u.name AS buyer_name, u.email AS buyer_email, u.phone_num AS buyer_phone
    FROM offer o JOIN user u ON o.buyer_id = u.user_id
    WHERE o.property_id = ? ORDER BY o.offer_date DESC");
$offersStatement->bind_param('i', $propertyId);
$offersStatement->execute();
$offers = $offersStatement->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offers for Property #<?= (int)$propertyId ?> | EstateScope</title>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
<link href="https://fonts.googleapis.com/css?family=Muli:400,400i,500,600,700&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css?family=Comfortaa:400,700" rel="stylesheet">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
<link rel="stylesheet" type="text/css" href="css/bootstrap-slider.css">
<link rel="stylesheet" type="text/css" href="css/jquery-ui.css">
<link rel="stylesheet" type="text/css" href="css/layerslider.css">
<link rel="stylesheet" type="text/css" href="css/color.css" id="color-change">
<link rel="stylesheet" type="text/css" href="css/owl.carousel.min.css">
<link rel="stylesheet" type="text/css" href="css/font-awesome.min.css">
<link rel="stylesheet" type="text/css" href="fonts/flaticon/flaticon.css">
<link rel="stylesheet" type="text/css" href="css/style.css">

<link rel="stylesheet" href="css/role-themes.css">
<link rel="stylesheet" href="css/marketplace.css">

</head>
<body class="market-page" data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">

<?php include("include/header.php"); ?>

<main class="market-section">
    <h1>Offers for Property #<?= (int)$propertyId ?></h1>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-info"><?= $_SESSION['message']; unset($_SESSION['message']); ?></div>
    <?php endif; ?>

    <?php if ($offers->num_rows === 0): ?>
        <div class="alert alert-info mt-3">No offers have been made for this property yet.</div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-bordered table-striped mt-4">
            <thead class="thead-dark">
                <tr>
                    <th>Buyer Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Offer Price (₹)</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($offer = mysqli_fetch_assoc($offers)): ?>
                <tr>
                    <td><?= htmlspecialchars($offer['buyer_name']) ?></td>
                    <td><?= htmlspecialchars($offer['buyer_email']) ?></td>
                    <td><?= htmlspecialchars($offer['buyer_phone']) ?></td>
                    <td><?= number_format($offer['offer_price']) ?></td>
                    <td><?= date("d M Y", strtotime($offer['offer_date'])) ?></td>
                    <td>
                        <?php if ($offer['status'] === 'Accepted'): ?>
                            <span class="badge badge-success">Accepted</span>
                        <?php elseif ($offer['status'] === 'Rejected'): ?>
                            <span class="badge badge-danger">Rejected</span>
                        <?php elseif ($offer['status'] === 'Sold'): ?>
                            <span class="badge badge-dark">Sold</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($offer['status'] === 'Pending'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="offer_id" value="<?= (int)$offer['offer_id'] ?>">
                                <button type="submit" name="accept" class="btn btn-success btn-sm">Accept</button>
                                <button type="submit" name="reject" class="btn btn-danger btn-sm">Reject</button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <a href="my_properties.php" class="btn btn-secondary mt-3">Back to My Properties</a>
</main>

<?php include("include/footer.php"); ?>
<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/custom.js"></script>
</body>
</html>

<?php
session_start();
require_once 'config.php';
require_once '../auth.php';
requireRole(['super_admin']);
require_once '../functions.php';

$reviewFilter = isset($_GET['review']) ? (string)$_GET['review'] : '';
$reviewWhere = '';
$reviewFilterLabel = 'All Properties';
if ($reviewFilter === 'pending') {
    $reviewWhere = " WHERE review_status = 'pending'";
    $reviewFilterLabel = 'Pending Approval';
} elseif ($reviewFilter === 'approved') {
    $reviewWhere = " WHERE review_status = 'approved'";
    $reviewFilterLabel = 'Approved';
} elseif ($reviewFilter === 'rejected') {
    $reviewWhere = " WHERE review_status = 'rejected'";
    $reviewFilterLabel = 'Rejected';
}

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$reviewMessage = '';
$reviewError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_property') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $propertyId = filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT);
    $decision = (string)($_POST['review_status'] ?? '');
    $reason = trim((string)($_POST['rejection_reason'] ?? ''));
    if (!$propertyId || !in_array($decision, ['approved', 'rejected'], true)) {
        $reviewError = 'Choose a valid property review decision.';
    } elseif ($decision === 'rejected' && ($reason === '' || mb_strlen($reason) > 2000)) {
        $reviewError = 'Enter a rejection reason of up to 2,000 characters.';
    } else {
        $exists = $con->prepare('SELECT property_id FROM property_listings WHERE property_id = ? LIMIT 1');
        if (!$exists) {
            throw new RuntimeException('Property could not be checked.');
        }
        $exists->bind_param('i', $propertyId);
        $exists->execute();
        $propertyExists = (bool)$exists->get_result()->fetch_assoc();
        $exists->close();
        if (!$propertyExists) {
            $reviewError = 'Property not found.';
        } else {
            $storedReason = $decision === 'rejected' ? $reason : null;
            $statement = $con->prepare('UPDATE property_listings SET review_status = ?, rejection_reason = ?
                WHERE property_id = ?');
            if (!$statement) {
                throw new RuntimeException('Property review could not be prepared.');
            }
            $statement->bind_param('ssi', $decision, $storedReason, $propertyId);
            if ($statement->execute()) {
                addAuditLog((int)$_SESSION['uid'], 'PROPERTY_REVIEW', 'Property ' . $propertyId . ' marked ' . $decision);
                $reviewMessage = 'Property review updated.';
            } else {
                $reviewError = 'Property review could not be updated.';
            }
            $statement->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_property_change') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $decision = (string)($_POST['decision'] ?? '');
    $reason = trim((string)($_POST['rejection_reason'] ?? ''));
    if (!$requestId || !in_array($decision, ['approved', 'rejected'], true)) {
        $reviewError = 'Choose a valid property change request decision.';
    } elseif ($decision === 'rejected' && ($reason === '' || mb_strlen($reason) > 2000)) {
        $reviewError = 'Enter a rejection reason of up to 2,000 characters.';
    } else {
        try {
            $con->begin_transaction();
            $requestStatement = $con->prepare(
                'SELECT request_id, property_id, agent_id, original_values, proposed_values, status
                 FROM property_change_requests WHERE request_id = ? FOR UPDATE'
            );
            $requestStatement->bind_param('i', $requestId);
            $requestStatement->execute();
            $changeRequest = $requestStatement->get_result()->fetch_assoc();
            $requestStatement->close();

            if (!$changeRequest || $changeRequest['status'] !== 'pending') {
                $con->rollback();
                $reviewError = 'This pending property change request was not found or has already been reviewed.';
            } elseif ($decision === 'rejected') {
                $updateRequest = $con->prepare(
                    "UPDATE property_change_requests
                     SET status = 'rejected', rejection_reason = ?, reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?
                     WHERE request_id = ? AND status = 'pending'"
                );
                $reviewerId = currentUserId();
                $updateRequest->bind_param('sii', $reason, $reviewerId, $requestId);
                $updateRequest->execute();
                if ($updateRequest->affected_rows !== 1) {
                    throw new RuntimeException('The property change request was already reviewed.');
                }
                $updateRequest->close();
                $con->commit();
                addAuditLog($reviewerId, 'REJECT_PROPERTY_CHANGE', 'Rejected property change request ' . $requestId);
                $reviewMessage = 'Property changes rejected. The agent can review the reason and resubmit.';
            } else {
                $propertyId = (int)$changeRequest['property_id'];
                $agentId = (int)$changeRequest['agent_id'];
                $propertyStatement = $con->prepare(
                    'SELECT * FROM property_listings WHERE property_id = ? AND seller_id = ? FOR UPDATE'
                );
                $propertyStatement->bind_param('ii', $propertyId, $agentId);
                $propertyStatement->execute();
                $currentProperty = $propertyStatement->get_result()->fetch_assoc();
                $propertyStatement->close();
                if (!$currentProperty) {
                    throw new RuntimeException('The property no longer belongs to the requesting agent.');
                }

                $rentalStatement = $con->prepare(
                    'SELECT available_date, security_deposit FROM rental_contracts WHERE property_id = ? LIMIT 1 FOR UPDATE'
                );
                $rentalStatement->bind_param('i', $propertyId);
                $rentalStatement->execute();
                $currentRental = $rentalStatement->get_result()->fetch_assoc();
                $rentalStatement->close();

                $originalValues = json_decode($changeRequest['original_values'], true, 512, JSON_THROW_ON_ERROR);
                $proposedValues = json_decode($changeRequest['proposed_values'], true, 512, JSON_THROW_ON_ERROR);
                $editableFields = [
                    'title', 'description', 'price', 'street', 'location', 'state', 'zip', 'property_type',
                    'bedrooms', 'bathrooms', 'size_sqft', 'pool_available', 'is_dog_friendly',
                    'nearest_school', 'bus_availability', 'tram_availability', 'status',
                    'available_date', 'security_deposit',
                ];
                if (!is_array($originalValues) || !is_array($proposedValues)
                    || array_diff($editableFields, array_keys($originalValues))
                    || array_diff($editableFields, array_keys($proposedValues))) {
                    throw new RuntimeException('The stored property change request is invalid.');
                }

                $currentValues = [];
                foreach (array_slice($editableFields, 0, 17) as $field) {
                    $currentValues[$field] = (string)($currentProperty[$field] ?? '');
                }
                $currentValues['available_date'] = (string)($currentRental['available_date'] ?? '');
                $currentValues['security_deposit'] = (string)($currentRental['security_deposit'] ?? '');
                $baselineMatches = true;
                foreach ($editableFields as $field) {
                    if ((string)$originalValues[$field] !== $currentValues[$field]) {
                        $baselineMatches = false;
                        break;
                    }
                }

                if (!$baselineMatches) {
                    $staleReason = 'The listing changed after this request was submitted. Review the current listing and resubmit your changes.';
                    $updateRequest = $con->prepare(
                        "UPDATE property_change_requests
                         SET status = 'rejected', rejection_reason = ?, reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?
                         WHERE request_id = ? AND status = 'pending'"
                    );
                    $reviewerId = currentUserId();
                    $updateRequest->bind_param('sii', $staleReason, $reviewerId, $requestId);
                    $updateRequest->execute();
                    if ($updateRequest->affected_rows !== 1) {
                        throw new RuntimeException('The stale property change request could not be closed.');
                    }
                    $updateRequest->close();
                    $con->commit();
                    addAuditLog($reviewerId, 'REJECT_STALE_PROPERTY_CHANGE', 'Closed stale property change request ' . $requestId);
                    $reviewMessage = 'The listing changed after submission. The request was closed and the agent can review the listing and resubmit.';
                } else {
                    $title = (string)$proposedValues['title'];
                    $description = (string)$proposedValues['description'];
                    $price = (float)$proposedValues['price'];
                    $street = (string)$proposedValues['street'];
                    $location = (string)$proposedValues['location'];
                    $state = (string)$proposedValues['state'];
                    $zip = (string)$proposedValues['zip'];
                    $propertyType = (string)$proposedValues['property_type'];
                    $bedrooms = (int)$proposedValues['bedrooms'];
                    $bathrooms = (int)$proposedValues['bathrooms'];
                    $sizeSqft = (int)$proposedValues['size_sqft'];
                    $poolAvailable = (string)$proposedValues['pool_available'];
                    $dogFriendly = (string)$proposedValues['is_dog_friendly'];
                    $nearestSchool = (string)$proposedValues['nearest_school'];
                    $busAvailability = (string)$proposedValues['bus_availability'];
                    $tramAvailability = (string)$proposedValues['tram_availability'];
                    $listingStatus = (string)$proposedValues['status'];
                    if ($title === '' || mb_strlen($title) > 255 || $description === '' || mb_strlen($description) > 10000
                        || $price <= 0 || $price > 1000000000000
                        || !in_array($propertyType, ['Sale', 'Rental'], true)
                        || $bedrooms < 0 || $bedrooms > 50 || $bathrooms < 0 || $bathrooms > 50
                        || $sizeSqft < 1 || $sizeSqft > 10000000
                        || !in_array($poolAvailable, ['Yes', 'No'], true)
                        || !in_array($dogFriendly, ['Yes', 'No'], true)
                        || !in_array($busAvailability, ['Yes', 'No'], true)
                        || !in_array($tramAvailability, ['Yes', 'No'], true)
                        || !in_array($listingStatus, ['available', 'hold', 'sold'], true)) {
                        throw new RuntimeException('The stored property change values failed validation.');
                    }
                    foreach ([$street, $location, $state, $zip] as $requiredText) {
                        if ($requiredText === '' || mb_strlen($requiredText) > 255) {
                            throw new RuntimeException('The stored property address failed validation.');
                        }
                    }
                    if (mb_strlen((string)$proposedValues['nearest_school']) > 255) {
                        throw new RuntimeException('The stored nearby-school value failed validation.');
                    }

                    $updateProperty = $con->prepare(
                        'UPDATE property_listings
                         SET title = ?, description = ?, price = ?, street = ?, location = ?, state = ?, zip = ?,
                             property_type = ?, bedrooms = ?, bathrooms = ?, size_sqft = ?, pool_available = ?,
                             is_dog_friendly = ?, nearest_school = ?, bus_availability = ?, tram_availability = ?, status = ?
                         WHERE property_id = ? AND seller_id = ?'
                    );
                    $updateProperty->bind_param(
                        'ssdsssssiiissssssii',
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
                        $poolAvailable,
                        $dogFriendly,
                        $nearestSchool,
                        $busAvailability,
                        $tramAvailability,
                        $listingStatus,
                        $propertyId,
                        $agentId
                    );
                    $updateProperty->execute();
                    if ($updateProperty->affected_rows < 0) {
                        throw new RuntimeException('The approved property changes could not be applied.');
                    }
                    $updateProperty->close();

                    if ($propertyType === 'Rental') {
                        $availableDate = (string)$proposedValues['available_date'];
                        $availableDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $availableDate);
                        $dateErrors = DateTimeImmutable::getLastErrors();
                        $securityDeposit = filter_var($proposedValues['security_deposit'], FILTER_VALIDATE_FLOAT);
                        if (!$availableDateValue || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
                            || $availableDateValue->format('Y-m-d') !== $availableDate
                            || $securityDeposit === false || $securityDeposit < 0 || $securityDeposit > 99999999.99) {
                            throw new RuntimeException('The requested rental terms failed validation.');
                        }
                        if ($currentRental) {
                            $rentalUpdate = $con->prepare(
                                'UPDATE rental_contracts SET available_date = ?, security_deposit = ? WHERE property_id = ?'
                            );
                            $rentalUpdate->bind_param('sdi', $availableDate, $securityDeposit, $propertyId);
                            $rentalUpdate->execute();
                            $rentalUpdate->close();
                        } else {
                            $rentalInsert = $con->prepare(
                                'INSERT INTO rental_contracts (property_id, available_date, security_deposit) VALUES (?, ?, ?)'
                            );
                            $rentalInsert->bind_param('isd', $propertyId, $availableDate, $securityDeposit);
                            $rentalInsert->execute();
                            $rentalInsert->close();
                        }
                    } else {
                        $rentalDelete = $con->prepare('DELETE FROM rental_contracts WHERE property_id = ?');
                        $rentalDelete->bind_param('i', $propertyId);
                        $rentalDelete->execute();
                        $rentalDelete->close();
                    }

                    $reviewerId = currentUserId();
                    $updateRequest = $con->prepare(
                        "UPDATE property_change_requests
                         SET status = 'approved', rejection_reason = NULL, reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?
                         WHERE request_id = ? AND status = 'pending'"
                    );
                    $updateRequest->bind_param('ii', $reviewerId, $requestId);
                    $updateRequest->execute();
                    if ($updateRequest->affected_rows !== 1) {
                        throw new RuntimeException('The property change request was already reviewed.');
                    }
                    $updateRequest->close();
                    $con->commit();
                    addAuditLog($reviewerId, 'APPROVE_PROPERTY_CHANGE', 'Approved property change request ' . $requestId);
                    $reviewMessage = 'Property changes approved and published. The agent can see the approval in My Properties.';
                }
            }
        } catch (Throwable $exception) {
            $con->rollback();
            error_log('Property change review failed for request ' . $requestId . ': ' . $exception->getMessage());
            $reviewError = 'The property change request could not be processed. No partial listing changes were saved.';
        }
    }
}

$changeRequestStatement = $con->prepare(
    "SELECT r.request_id, r.property_id, r.agent_id, r.original_values, r.proposed_values, r.submitted_at,
            p.title AS property_title, u.name AS agent_name
     FROM property_change_requests r
     JOIN property_listings p ON p.property_id = r.property_id
     JOIN user u ON u.user_id = r.agent_id
     WHERE r.status = 'pending'
     ORDER BY r.submitted_at ASC"
);
$changeRequestStatement->execute();
$pendingChangeRequests = $changeRequestStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$changeRequestStatement->close();
$changeFieldLabels = [
    'title' => 'Title',
    'description' => 'Description',
    'price' => 'Price',
    'street' => 'Street',
    'location' => 'City',
    'state' => 'State',
    'zip' => 'Zip',
    'property_type' => 'Property type',
    'bedrooms' => 'Bedrooms',
    'bathrooms' => 'Bathrooms',
    'size_sqft' => 'Area (sqft)',
    'pool_available' => 'Pool available',
    'is_dog_friendly' => 'Dog friendly',
    'nearest_school' => 'Nearest school',
    'bus_availability' => 'Bus availability',
    'tram_availability' => 'Tram availability',
    'status' => 'Listing status',
    'available_date' => 'Rental available date',
    'security_deposit' => 'Security deposit',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <title>LM Homes - Property Listings</title>

    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=20261024">
    <link rel="stylesheet" href="assets/css/feathericon.min.css">
    <link rel="stylesheet" href="assets/plugins/datatables/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/plugins/datatables/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/plugins/datatables/select.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/plugins/datatables/buttons.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
<?php include("header.php"); ?>

<div class="page-wrapper">
    <div class="content container-fluid">
        <div class="page-header">
            <div class="row">
                <div class="col">
                    <h3 class="page-title">Property Listings</h3>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Property</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mt-0 mb-4"><?= htmlspecialchars($reviewFilterLabel, ENT_QUOTES, 'UTF-8') ?></h4>
<form method="get" class="review-filter" role="search" aria-label="Filter properties by review status">
    <label class="me-2" style="font-weight: 600; font-size: 0.9rem;">Review status:</label>
    <a href="propertyview.php" class="btn <?= $reviewFilter === '' ? 'btn-primary' : 'btn-outline-secondary' ?> btn-sm me-2">All Properties</a>
    <a href="propertyview.php?review=pending" class="btn <?= $reviewFilter === 'pending' ? 'btn-primary' : 'btn-outline-secondary' ?> btn-sm me-2">Pending Approval</a>
    <a href="propertyview.php?review=approved" class="btn <?= $reviewFilter === 'approved' ? 'btn-primary' : 'btn-outline-secondary' ?> btn-sm me-2">Approved</a>
    <a href="propertyview.php?review=rejected" class="btn <?= $reviewFilter === 'rejected' ? 'btn-primary' : 'btn-outline-secondary' ?> btn-sm">Rejected</a>
</form>
                        <?php if (isset($_GET['msg'])) echo '<div class="alert alert-success">'.htmlspecialchars($_GET['msg']).'</div>'; ?>
                        <?php if ($reviewMessage !== ''): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($reviewMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                        <?php if ($reviewError !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($reviewError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

                        <section id="change-requests" class="mb-4">
                            <h4>Pending Property Change Requests (<?= count($pendingChangeRequests) ?>)</h4>
                            <?php if (!$pendingChangeRequests): ?>
                                <p>No pending property change requests.</p>
                            <?php else: ?>
                                <?php foreach ($pendingChangeRequests as $changeRequest): ?>
                                    <?php
                                    $originalValues = json_decode($changeRequest['original_values'], true, 512, JSON_THROW_ON_ERROR);
                                    $proposedValues = json_decode($changeRequest['proposed_values'], true, 512, JSON_THROW_ON_ERROR);
                                    ?>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <h5><?= htmlspecialchars($changeRequest['property_title'], ENT_QUOTES, 'UTF-8') ?></h5>
                                            <p>
                                                Request #<?= (int)$changeRequest['request_id'] ?> ·
                                                Agent: <?= htmlspecialchars($changeRequest['agent_name'], ENT_QUOTES, 'UTF-8') ?> ·
                                                Submitted: <?= htmlspecialchars($changeRequest['submitted_at'], ENT_QUOTES, 'UTF-8') ?>
                                            </p>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered">
                                                    <thead><tr><th>Property field</th><th>Current value</th><th>Requested value</th></tr></thead>
                                                    <tbody>
                                                        <?php $hasDifferences = false; ?>
                                                        <?php foreach ($changeFieldLabels as $field => $label): ?>
                                                            <?php if ((string)($originalValues[$field] ?? '') === (string)($proposedValues[$field] ?? '')): continue; endif; ?>
                                                            <?php $hasDifferences = true; ?>
                                                            <tr>
                                                                <th><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></th>
                                                                <td><?= nl2br(htmlspecialchars((string)($originalValues[$field] ?? ''), ENT_QUOTES, 'UTF-8')) ?></td>
                                                                <td><?= nl2br(htmlspecialchars((string)($proposedValues[$field] ?? ''), ENT_QUOTES, 'UTF-8')) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (!$hasDifferences): ?><tr><td colspan="3">No field values differ from the current listing.</td></tr><?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <form method="post" action="propertyview.php#change-requests" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="review_property_change">
                                                <input type="hidden" name="request_id" value="<?= (int)$changeRequest['request_id'] ?>">
                                                <input type="hidden" name="decision" value="approved">
                                                <button class="btn btn-success btn-sm" type="submit">Approve changes</button>
                                            </form>
                                            <form method="post" action="propertyview.php#change-requests" class="mt-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="review_property_change">
                                                <input type="hidden" name="request_id" value="<?= (int)$changeRequest['request_id'] ?>">
                                                <input type="hidden" name="decision" value="rejected">
                                                <textarea class="form-control mb-2" name="rejection_reason" maxlength="2000" required placeholder="Reason required when rejecting"></textarea>
                                                <button class="btn btn-danger btn-sm" type="submit">Reject changes</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </section>

                        <div class="table-responsive">
                            <table id="datatable-buttons" class="table table-striped dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Bedrooms</th>
                                    <th>Bathrooms</th>
                                    <th>Size (sqft)</th>
                                    <th>Price</th>
                                    <th>Street</th>
                                    <th>City</th>
                                    <th>State</th>
                                    <th>Zip</th>
                                    <th>Pool Available</th>
                                    <th>Dog Friendly</th>
                                    <th>Nearest School</th>
                                    <th>Bus Availability</th>
                                    <th>Tram Availability</th>
                                    <th>Available Date (If Rental)</th>
                                    <th>Security Deposit (If Rental)</th>
                                    <th>Status</th>
                                    <th>Review</th>
                                    <th>Added Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $query = mysqli_query($con, "SELECT * FROM property_listings" . $reviewWhere . " ORDER BY created_at DESC");
                                while ($row = mysqli_fetch_assoc($query)) {
                                    // Fetch rental details if Rental
                                    $available_date = 'N/A';
                                    $security_deposit = 'N/A';
                                    if ($row['property_type'] === 'Rental') {
                                        $rentalQuery = mysqli_query($con, "SELECT available_date, security_deposit FROM rental_contracts WHERE property_id = '{$row['property_id']}' LIMIT 1");
                                        $rentalData = mysqli_fetch_assoc($rentalQuery);
                                        $available_date = $rentalData['available_date'] ?? 'N/A';
                                        $security_deposit = $rentalData['security_deposit'] ?? 'N/A';
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                                        <td><?php echo htmlspecialchars($row['property_type']); ?></td>
                                        <td><?php echo $row['bedrooms']; ?></td>
                                        <td><?php echo $row['bathrooms']; ?></td>
                                        <td><?php echo $row['size_sqft']; ?></td>
                                        <td><?php echo $row['price']; ?></td>
                                        <td><?php echo htmlspecialchars($row['street']); ?></td>
                                        <td><?php echo htmlspecialchars($row['location']); ?></td>
                                        <td><?php echo htmlspecialchars($row['state']); ?></td>
                                        <td><?php echo htmlspecialchars($row['zip']); ?></td>
                                        <td><?php echo $row['pool_available']; ?></td>
                                        <td><?php echo $row['is_dog_friendly']; ?></td>
                                        <td><?php echo htmlspecialchars($row['nearest_school']); ?></td>
                                        <td><?php echo $row['bus_availability']; ?></td>
                                        <td><?php echo $row['tram_availability']; ?></td>
                                        <td><?php echo $available_date; ?></td>
                                        <td><?php echo $security_deposit; ?></td>
                                        <td><?php echo $row['status']; ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars(ucfirst($row['review_status']), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if ($row['review_status'] === 'rejected'): ?><p><?= htmlspecialchars($row['rejection_reason'] ?? '', ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                                            <form method="post" action="propertyview.php" class="mt-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="review_property">
                                                <input type="hidden" name="property_id" value="<?= (int)$row['property_id'] ?>">
                                                <select class="form-control form-control-sm mb-2" name="review_status" aria-label="Review status for <?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <option value="approved" <?= $row['review_status'] === 'approved' ? 'selected' : '' ?>>Approve</option>
                                                    <option value="rejected" <?= $row['review_status'] === 'rejected' ? 'selected' : '' ?>>Reject</option>
                                                </select>
                                                <textarea class="form-control form-control-sm mb-2" name="rejection_reason" maxlength="2000" placeholder="Reason required when rejecting"></textarea>
                                                <button class="btn btn-primary btn-sm" type="submit">Save review</button>
                                            </form>
                                        </td>
                                        <td><?php echo $row['created_at']; ?></td>
                                        <td>
                                            <a href="propertyedit.php?id=<?php echo $row['property_id']; ?>" class="btn btn-info btn-sm">Edit</a>
                                            <?php if ($row['status'] === 'available'): ?>
                                                <form method="post" action="propertydelete.php" class="d-inline" onsubmit="return confirm('Deactivate this listing? Purchase and booking history will be preserved.');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="id" value="<?= (int)$row['property_id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Deactivate</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>
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
</body>
</html>

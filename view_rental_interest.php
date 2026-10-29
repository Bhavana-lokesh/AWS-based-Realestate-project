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
    WHERE property_id = ? AND property_type = 'Rental' LIMIT 1");
$ownerCheck->bind_param('i', $propertyId);
$ownerCheck->execute();
$property = $ownerCheck->get_result()->fetch_assoc();
$ownerCheck->close();
if (!$property) {
    http_response_code(404);
    exit('Rental property not found.');
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

    $interestId = filter_input(INPUT_POST, 'interest_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    if (!$interestId || !in_array($action, ['Accept', 'Reject'], true)) {
        http_response_code(400);
        exit('Invalid rental inquiry action.');
    }

    try {
        $con->begin_transaction();
        $status = $action === 'Accept' ? 'Accepted' : 'Rejected';
        $updateInterest = $con->prepare("UPDATE rental_interest SET status = ?
            WHERE interest_id = ? AND property_id = ? AND status = 'Pending'");
        $updateInterest->bind_param('sii', $status, $interestId, $propertyId);
        $updateInterest->execute();
        if ($updateInterest->affected_rows !== 1) {
            $con->rollback();
            http_response_code(404);
            exit('Pending rental inquiry not found for this property.');
        }
        $updateInterest->close();

        if ($action === 'Accept') {
            $rejectOthers = $con->prepare("UPDATE rental_interest SET status = 'Rejected'
                WHERE property_id = ? AND interest_id <> ? AND status = 'Pending'");
            $rejectOthers->bind_param('ii', $propertyId, $interestId);
            $rejectOthers->execute();
            $rejectOthers->close();

            $holdProperty = $con->prepare("UPDATE property_listings SET status = 'Hold'
                WHERE property_id = ? AND seller_id = ? AND status = 'available'");
            $holdProperty->bind_param('ii', $propertyId, $userId);
            $holdProperty->execute();
            if ($holdProperty->affected_rows !== 1) {
                throw new RuntimeException('The rental property is no longer available.');
            }
            $holdProperty->close();
        }
        $con->commit();
        $_SESSION['rental_interest_message'] = 'Rental inquiry ' . strtolower($status) . '.';
    } catch (Throwable $exception) {
        $con->rollback();
        error_log('Rental inquiry update failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('The rental inquiry could not be updated. Please try again.');
    }

    header('Location: view_rental_interest.php?property_id=' . $propertyId);
    exit;
}

$message = $_SESSION['rental_interest_message'] ?? '';
unset($_SESSION['rental_interest_message']);
$listStatement = $con->prepare("SELECT ri.interest_id, ri.status, ri.interest_date, u.name, u.email
    FROM rental_interest ri JOIN user u ON ri.buyer_id = u.user_id
    WHERE ri.property_id = ? ORDER BY ri.interest_date DESC");
$listStatement->bind_param('i', $propertyId);
$listStatement->execute();
$result = $listStatement->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rental inquiries | EstateScope</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/role-themes.css">
    <link rel="stylesheet" href="css/marketplace.css">
</head>
<body class="market-page" data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
<?php include 'include/header.php'; ?>
<main class="market-section">
<h1>Rental inquiries for Property #<?= (int)$propertyId ?></h1>
<?php if ($message): ?><div class="market-feedback" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<?php if ($result->num_rows === 0): ?>
    <div class="market-empty-state">No one has shown interest yet.</div>
<?php else: ?>
    <div class="table-responsive">
    <table class="table table-bordered mt-3">
        <thead class="thead-dark">
            <tr>
                <th>Buyer</th>
                <th>Email</th>
                <th>Interest Date</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($row = mysqli_fetch_assoc($result)): ?>
            <tr>
                <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(date('d-m-Y', strtotime($row['interest_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><strong><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td>
                    <?php if ($row['status'] === 'Pending'): ?>
                        <form method="POST" class="form-inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="interest_id" value="<?= (int)$row['interest_id'] ?>">
                            <button name="action" value="Accept" class="btn btn-success btn-sm mr-2">Accept</button>
                            <button name="action" value="Reject" class="btn btn-danger btn-sm">Reject</button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge-secondary">Final</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</main>
<?php include 'include/footer.php'; ?>
</body>
</html>

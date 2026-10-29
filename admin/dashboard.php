<?php
session_start();
require("config.php");
require_once '../auth.php';
requireRole(['super_admin']);
require_once '../functions.php';

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$userMessage = '';
$userError = '';

$dashboardCount = static function (string $sql) use ($con): int {
    $result = $con->query($sql);
    if (!$result) {
        throw new RuntimeException('A dashboard statistic could not be loaded.');
    }
    return (int)$result->fetch_row()[0];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid CSRF request.');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'update_user') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = trim((string)($_POST['phone'] ?? ''));
        $role = strtolower(trim((string)($_POST['role'] ?? '')));
        $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

        if (!$userId) {
            $userError = 'Choose a valid user to update.';
        } elseif ($name === '') {
            $userError = 'User name cannot be empty.';
        } elseif (!$email) {
            $userError = 'Please provide a valid email address.';
        } elseif (!in_array($role, ['buyer', 'agent'], true)) {
            $userError = 'Role must be either Agent or Buyer.';
        } else {
            $checkEmail = $con->prepare("SELECT user_id FROM user WHERE email = ? AND user_id != ? LIMIT 1");
            $checkEmail->bind_param('si', $email, $userId);
            $checkEmail->execute();
            if ($checkEmail->get_result()->fetch_assoc()) {
                $userError = 'This email address is already in use by another account.';
            } else {
                $updateStmt = $con->prepare("UPDATE user SET name = ?, email = ?, phone_num = ?, role = ?, is_active = ? WHERE user_id = ? AND LOWER(role) IN ('buyer', 'agent')");
                if ($updateStmt) {
                    $updateStmt->bind_param('ssssii', $name, $email, $phone, $role, $isActive, $userId);
                    if ($updateStmt->execute()) {
                        addAuditLog((int)($_SESSION['uid'] ?? 0), 'USER_ACCOUNT_UPDATED', 'Updated account ' . $userId . ' (' . $name . ', ' . $role . ')');
                        $userMessage = 'User account for "' . $name . '" updated successfully.';
                    } else {
                        $userError = 'Failed to update user account in database.';
                    }
                    $updateStmt->close();
                }
            }
            $checkEmail->close();
        }
    } elseif ($action === 'delete_user') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        if (!$userId) {
            $userError = 'Choose a valid user to delete.';
        } else {
            try {
                $delStmt = $con->prepare("DELETE FROM user WHERE user_id = ? AND LOWER(role) IN ('buyer', 'agent')");
                if ($delStmt) {
                    $delStmt->bind_param('i', $userId);
                    $delStmt->execute();
                    if ($delStmt->affected_rows > 0) {
                        addAuditLog((int)($_SESSION['uid'] ?? 0), 'USER_DELETED', 'Permanently deleted user ' . $userId);
                        $userMessage = 'User account was permanently deleted.';
                    } else {
                        $userError = 'User account not found or already deleted.';
                    }
                    $delStmt->close();
                }
            } catch (mysqli_sql_exception $e) {
                // Foreign key constraint prevented permanent deletion - soft delete safely
                $deactStmt = $con->prepare("UPDATE user SET is_active = 0 WHERE user_id = ? AND LOWER(role) IN ('buyer', 'agent')");
                if ($deactStmt) {
                    $deactStmt->bind_param('i', $userId);
                    $deactStmt->execute();
                    $deactStmt->close();
                    addAuditLog((int)($_SESSION['uid'] ?? 0), 'USER_DEACTIVATED', 'Deactivated user ' . $userId . ' because linked history exists');
                    $userMessage = 'User has linked appointments or transactions; account has been safely deactivated to preserve historical data.';
                } else {
                    $userError = 'Unable to delete or deactivate this user account.';
                }
            }
        }
    }
}

$buyerCount = $dashboardCount("SELECT COUNT(*) FROM user WHERE LOWER(role) = 'buyer'");
$agentCount = $dashboardCount("SELECT COUNT(*) FROM user WHERE LOWER(role) = 'agent'");
$userCount = $buyerCount + $agentCount;
$availablePropertyCount = $dashboardCount("SELECT COUNT(*) FROM property_listings WHERE status = 'available' AND review_status = 'approved'");
$pendingApprovalCount = $dashboardCount("SELECT COUNT(*) FROM property_listings WHERE review_status = 'pending'");
$pendingChangeRequestCount = $dashboardCount("SELECT COUNT(*) FROM property_change_requests WHERE status = 'pending'");
$bookingActivityCount = $dashboardCount('SELECT COUNT(*) FROM appointment')
    + $dashboardCount('SELECT COUNT(*) FROM rental_interest');
$paymentRecordCount = $dashboardCount('SELECT COUNT(*) FROM payment');
$completedPaymentCount = $dashboardCount("SELECT COUNT(*) FROM payment WHERE status = 'Completed'");
$paymentTotalResult = $con->query("SELECT COALESCE(SUM(amount_paid), 0) FROM payment WHERE status = 'Completed'");
if (!$paymentTotalResult) {
    throw new RuntimeException('Payment totals could not be loaded.');
}
$completedPaymentTotal = (float)$paymentTotalResult->fetch_row()[0];
$salePropertyCount = $dashboardCount("SELECT COUNT(*) FROM property_listings WHERE property_type = 'sale'");
$rentalPropertyCount = $dashboardCount("SELECT COUNT(*) FROM property_listings WHERE property_type = 'rental'");

// Fetch all buyer and agent users for the table
$userQuery = $con->query("SELECT user_id, name, email, phone_num, role, is_active, date_of_creation FROM user WHERE LOWER(role) IN ('buyer', 'agent') ORDER BY date_of_creation DESC");
$dashboardUsers = [];
if ($userQuery) {
    while ($u = $userQuery->fetch_assoc()) {
        $dashboardUsers[] = $u;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <title>Super Admin Dashboard | EstateScope</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=20261024">
    <link rel="stylesheet" href="assets/css/feathericon.min.css">
    <link rel="stylesheet" href="assets/plugins/morris/morris.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<!-- Header -->
<?php include("header.php"); ?>
<!-- /Header -->

<!-- Page Wrapper -->
<div class="page-wrapper">
    <div class="content container-fluid">
        <!-- Page Header -->
        <div class="page-header">
            <div class="row">
                <div class="col-sm-12">
                    <h3 class="page-title">Welcome, Super Admin</h3>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item active">Dashboard</li>
                    </ul>
                </div>
            </div>
        </div>
        <!-- /Page Header -->

        <!-- Stat Cards Row 1 -->
        <div class="row stat-cards-row">
            <!-- Total Registered Users Card -->
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="dash-widget-header">
                            <span class="dash-widget-icon bg-primary"><i class="fe fe-users"></i></span>
                        </div>
                        <div class="dash-widget-info">
                            <h3><?= $userCount ?></h3>
                            <h6 class="text-muted">Registered Users (<?= $agentCount ?> Agents • <?= $buyerCount ?> Buyers)</h6>
                            <a href="#users-table" class="btn btn-sm btn-outline-primary">Manage Users</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- All Properties -->
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="dash-widget-header">
                            <span class="dash-widget-icon bg-info"><i class="fe fe-home"></i></span>
                        </div>
                        <div class="dash-widget-info">
                            <h3><?= $availablePropertyCount ?></h3>
                            <h6 class="text-muted">Approved Available Properties</h6>
                            <a href="propertyview.php?review=approved" class="btn btn-sm btn-outline-primary">View Listings</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Pending Approvals -->
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="dash-widget-header">
                            <span class="dash-widget-icon bg-warning"><i class="fe fe-clock"></i></span>
                        </div>
                        <div class="dash-widget-info">
                            <h3><?= $pendingApprovalCount + $pendingChangeRequestCount ?></h3>
                            <h6 class="text-muted">Pending Property & Change Approvals</h6>
                            <a href="propertyview.php#change-requests" class="btn btn-sm btn-outline-warning">Review Listings & Changes</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Payment Total Value -->
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="dash-widget-header">
                            <span class="dash-widget-icon bg-gold"><i class="fe fe-money"></i></span>
                        </div>
                        <div class="dash-widget-info">
                            <h3>₹<?= number_format($completedPaymentTotal, 2) ?></h3>
                            <h6 class="text-muted">Completed Transaction Value</h6>
                            <a href="payments.php" class="btn btn-sm btn-outline-warning">Transaction History</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stat Cards Row 2 -->
        <div class="row stat-cards-row">
            <!-- Properties For Sale -->
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="dash-widget-header">
                            <span class="dash-widget-icon bg-primary"><i class="fe fe-tag"></i></span>
                        </div>
                        <div class="dash-widget-info">
                            <h3><?= $salePropertyCount ?></h3>
                            <h6 class="text-muted">Properties For Sale</h6>
                            <a href="propertyview.php" class="btn btn-sm btn-outline-primary">Browse Sale Listings</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Rental Properties -->
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="dash-widget-header">
                            <span class="dash-widget-icon bg-success"><i class="fe fe-building"></i></span>
                        </div>
                        <div class="dash-widget-info">
                            <h3><?= $rentalPropertyCount ?></h3>
                            <h6 class="text-muted">Rental Properties</h6>
                            <a href="propertyview.php" class="btn btn-sm btn-outline-success">Browse Rentals</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Appointments -->
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="dash-widget-header">
                            <span class="dash-widget-icon bg-purple"><i class="fe fe-calendar"></i></span>
                        </div>
                        <div class="dash-widget-info">
                            <h3><?= $bookingActivityCount ?></h3>
                            <h6 class="text-muted">Appointments & Inquiries</h6>
                            <a href="appointments.php" class="btn btn-sm btn-outline-primary">Manage Appointments</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Payments Count -->
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="dash-widget-header">
                            <span class="dash-widget-icon bg-success"><i class="fe fe-credit-card"></i></span>
                        </div>
                        <div class="dash-widget-info">
                            <h3><?= $paymentRecordCount ?></h3>
                            <h6 class="text-muted">Payment Records (<?= $completedPaymentCount ?> completed)</h6>
                            <a href="payments.php" class="btn btn-sm btn-outline-success">View Payment Ledger</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Registered Users Directory & Management Section -->
        <div class="row" id="users-table">
            <div class="col-12">
                <div class="card user-mgmt-card shadow-sm">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
                        <div>
                            <h4 class="card-title mb-1 font-weight-bold">
                                <i class="fe fe-users mr-2 text-primary"></i> Registered Users Directory (Agents & Buyers)
                            </h4>
                            <p class="text-muted mb-0 small">
                                Filter by role, edit details directly in the table, save updates, or delete user accounts.
                            </p>
                        </div>
                        <!-- Role Filter Section -->
                        <div class="user-filter-bar mt-2 mt-md-0">
                            <span class="mr-2 font-weight-bold text-muted small">Filter Role:</span>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Role Filters">
                                <button type="button" class="btn btn-primary active role-filter-btn" data-role="all">
                                    All (<span class="badge badge-light"><?= $userCount ?></span>)
                                </button>
                                <button type="button" class="btn btn-outline-info role-filter-btn" data-role="agent">
                                    Agents (<span class="badge badge-info"><?= $agentCount ?></span>)
                                </button>
                                <button type="button" class="btn btn-outline-primary role-filter-btn" data-role="buyer">
                                    Buyers (<span class="badge badge-primary"><?= $buyerCount ?></span>)
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($userMessage !== ''): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fa fa-check-circle mr-1"></i> <?= htmlspecialchars($userMessage, ENT_QUOTES, 'UTF-8') ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            </div>
                        <?php endif; ?>
                        <?php if ($userError !== ''): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fa fa-exclamation-circle mr-1"></i> <?= htmlspecialchars($userError, ENT_QUOTES, 'UTF-8') ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-hover table-striped table-bordered mb-0 align-middle user-table">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th style="min-width: 150px;">Name</th>
                                        <th style="min-width: 180px;">Email</th>
                                        <th style="min-width: 140px;">Phone</th>
                                        <th style="min-width: 110px;">Role</th>
                                        <th style="min-width: 100px;">Status</th>
                                        <th style="min-width: 120px;">Registered</th>
                                        <th style="min-width: 190px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dashboardUsers)): ?>
                                        <tr><td colspan="8" class="text-center py-4 text-muted">No buyer or agent accounts found.</td></tr>
                                    <?php else: ?>
                                        <?php $idx = 1; foreach ($dashboardUsers as $user): 
                                            $uId = (int)$user['user_id'];
                                            $uRole = strtolower($user['role']);
                                            $uActive = (int)$user['is_active'];
                                        ?>
                                        <tr id="user-row-<?= $uId ?>" class="user-row" data-role="<?= $uRole ?>">
                                            <td class="text-muted font-weight-bold"><?= $idx++ ?></td>
                                            <td class="user-name-cell">
                                                <span class="view-val"><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <input type="text" class="form-control form-control-sm edit-input d-none" id="input-name-<?= $uId ?>" value="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Full Name">
                                            </td>
                                            <td class="user-email-cell">
                                                <span class="view-val"><a href="mailto:<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></a></span>
                                                <input type="email" class="form-control form-control-sm edit-input d-none" id="input-email-<?= $uId ?>" value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Email Address">
                                            </td>
                                            <td class="user-phone-cell">
                                                <span class="view-val"><?= htmlspecialchars($user['phone_num'] ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
                                                <input type="text" class="form-control form-control-sm edit-input d-none" id="input-phone-<?= $uId ?>" value="<?= htmlspecialchars($user['phone_num'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Phone Number">
                                            </td>
                                            <td class="user-role-cell">
                                                <span class="view-val">
                                                    <span class="badge badge-<?= $uRole === 'agent' ? 'info' : 'primary' ?> px-2 py-1">
                                                        <?= ucfirst($uRole) ?>
                                                    </span>
                                                </span>
                                                <select class="form-control form-control-sm edit-input d-none" id="input-role-<?= $uId ?>">
                                                    <option value="agent" <?= $uRole === 'agent' ? 'selected' : '' ?>>Agent</option>
                                                    <option value="buyer" <?= $uRole === 'buyer' ? 'selected' : '' ?>>Buyer</option>
                                                </select>
                                            </td>
                                            <td class="user-status-cell">
                                                <span class="view-val">
                                                    <span class="badge badge-<?= $uActive === 1 ? 'success' : 'secondary' ?> px-2 py-1">
                                                        <?= $uActive === 1 ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </span>
                                                <select class="form-control form-control-sm edit-input d-none" id="input-status-<?= $uId ?>">
                                                    <option value="1" <?= $uActive === 1 ? 'selected' : '' ?>>Active</option>
                                                    <option value="0" <?= $uActive === 0 ? 'selected' : '' ?>>Inactive</option>
                                                </select>
                                            </td>
                                            <td class="small text-muted">
                                                <?= htmlspecialchars(date('d M Y', strtotime($user['date_of_creation'] ?? 'now')), ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td class="text-center user-actions-cell">
                                                <!-- Action form for Save -->
                                                <form method="post" action="#users-table" class="d-inline" id="user-form-<?= $uId ?>" onsubmit="return syncUserData(<?= $uId ?>);">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="update_user">
                                                    <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                    <input type="hidden" name="name" id="post-name-<?= $uId ?>">
                                                    <input type="hidden" name="email" id="post-email-<?= $uId ?>">
                                                    <input type="hidden" name="phone" id="post-phone-<?= $uId ?>">
                                                    <input type="hidden" name="role" id="post-role-<?= $uId ?>">
                                                    <input type="hidden" name="is_active" id="post-status-<?= $uId ?>">

                                                    <!-- Edit Button -->
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit mr-1" id="btn-edit-<?= $uId ?>" onclick="enableUserEdit(<?= $uId ?>)" title="Edit user">
                                                        <i class="fa fa-pencil"></i> Edit
                                                    </button>
                                                    <!-- Save Button -->
                                                    <button type="submit" class="btn btn-sm btn-success btn-save mr-1 d-none" id="btn-save-<?= $uId ?>" title="Save changes">
                                                        <i class="fa fa-check"></i> Save
                                                    </button>
                                                    <!-- Cancel Button -->
                                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-cancel mr-1 d-none" id="btn-cancel-<?= $uId ?>" onclick="cancelUserEdit(<?= $uId ?>)" title="Cancel editing">
                                                        <i class="fa fa-times"></i> Cancel
                                                    </button>
                                                </form>

                                                <!-- Delete Form & Button -->
                                                <form method="post" action="#users-table" class="d-inline" id="del-form-<?= $uId ?>" onsubmit="return confirm('Are you sure you want to delete this user? If historical bookings/payments exist, their account will be deactivated instead.');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-delete" id="btn-del-<?= $uId ?>" title="Delete user">
                                                        <i class="fa fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
<!-- /Page Wrapper -->

<!-- Scripts -->
<script src="assets/js/jquery-3.2.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/plugins/slimscroll/jquery.slimscroll.min.js"></script>
<script src="assets/plugins/raphael/raphael.min.js"></script>
<script src="assets/plugins/morris/morris.min.js"></script>
<script src="assets/js/chart.morris.js?v=20261024"></script>
<script src="assets/js/script.js?v=20261024-2"></script>

<script>
function enableUserEdit(id) {
    const row = document.getElementById('user-row-' + id);
    if (!row) return;
    row.querySelectorAll('.view-val').forEach(el => el.classList.add('d-none'));
    row.querySelectorAll('.edit-input').forEach(el => el.classList.remove('d-none'));
    document.getElementById('btn-edit-' + id).classList.add('d-none');
    document.getElementById('btn-del-' + id).classList.add('d-none');
    document.getElementById('btn-save-' + id).classList.remove('d-none');
    document.getElementById('btn-cancel-' + id).classList.remove('d-none');
}

function cancelUserEdit(id) {
    const row = document.getElementById('user-row-' + id);
    if (!row) return;
    row.querySelectorAll('.view-val').forEach(el => el.classList.remove('d-none'));
    row.querySelectorAll('.edit-input').forEach(el => el.classList.add('d-none'));
    document.getElementById('btn-edit-' + id).classList.remove('d-none');
    document.getElementById('btn-del-' + id).classList.remove('d-none');
    document.getElementById('btn-save-' + id).classList.add('d-none');
    document.getElementById('btn-cancel-' + id).classList.add('d-none');
}

function syncUserData(id) {
    document.getElementById('post-name-' + id).value = document.getElementById('input-name-' + id).value.trim();
    document.getElementById('post-email-' + id).value = document.getElementById('input-email-' + id).value.trim();
    document.getElementById('post-phone-' + id).value = document.getElementById('input-phone-' + id).value.trim();
    document.getElementById('post-role-' + id).value = document.getElementById('input-role-' + id).value;
    document.getElementById('post-status-' + id).value = document.getElementById('input-status-' + id).value;
    return true;
}

// Client-side role filtering
document.querySelectorAll('.role-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.role-filter-btn').forEach(b => {
            b.classList.remove('btn-primary', 'btn-info', 'active');
            b.classList.add('btn-outline-primary');
        });
        this.classList.remove('btn-outline-primary', 'btn-outline-info');
        this.classList.add('active');

        const role = this.getAttribute('data-role');
        if (role === 'agent') {
            this.classList.add('btn-info');
        } else {
            this.classList.add('btn-primary');
        }

        document.querySelectorAll('.user-row').forEach(row => {
            if (role === 'all' || row.getAttribute('data-role') === role) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
});
</script>

</body>
</html>

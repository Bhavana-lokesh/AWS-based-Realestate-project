<?php
session_start();
require("config.php");
require_once '../auth.php';
requireRole(['super_admin']);
require_once '../functions.php';

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$message = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$error = '';

$userFilter = isset($_GET['filter']) ? strtolower(trim((string)$_GET['filter'])) : '';
$userFilterSql = '';
$userFilterLabel = 'All Users';
if ($userFilter === 'agent') {
    $userFilterSql = " AND LOWER(role) = 'agent'";
    $userFilterLabel = 'Agents';
} elseif ($userFilter === 'buyer') {
    $userFilterSql = " AND LOWER(role) = 'buyer'";
    $userFilterLabel = 'Buyers';
}

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
            $error = 'Choose a valid user to update.';
        } elseif ($name === '') {
            $error = 'User name cannot be empty.';
        } elseif (!$email) {
            $error = 'Please provide a valid email address.';
        } elseif (!in_array($role, ['buyer', 'agent'], true)) {
            $error = 'Role must be either Agent or Buyer.';
        } else {
            $checkEmail = $con->prepare("SELECT user_id FROM user WHERE email = ? AND user_id != ? LIMIT 1");
            $checkEmail->bind_param('si', $email, $userId);
            $checkEmail->execute();
            if ($checkEmail->get_result()->fetch_assoc()) {
                $error = 'This email address is already in use by another user.';
            } else {
                $stmt = $con->prepare("UPDATE user SET name = ?, email = ?, phone_num = ?, role = ?, is_active = ? WHERE user_id = ? AND LOWER(role) IN ('buyer', 'agent')");
                if ($stmt) {
                    $stmt->bind_param('ssssii', $name, $email, $phone, $role, $isActive, $userId);
                    if ($stmt->execute()) {
                        addAuditLog((int)($_SESSION['uid'] ?? 0), 'USER_ACCOUNT_UPDATED', 'Updated account ' . $userId . ' (' . $name . ', ' . $role . ')');
                        $message = 'User account for "' . $name . '" updated successfully.';
                    } else {
                        $error = 'Failed to update user account in database.';
                    }
                    $stmt->close();
                }
            }
            $checkEmail->close();
        }
    } elseif ($action === 'delete_user') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        if (!$userId) {
            $error = 'Choose a valid user to delete.';
        } else {
            try {
                $delStmt = $con->prepare("DELETE FROM user WHERE user_id = ? AND LOWER(role) IN ('buyer', 'agent')");
                if ($delStmt) {
                    $delStmt->bind_param('i', $userId);
                    $delStmt->execute();
                    if ($delStmt->affected_rows > 0) {
                        addAuditLog((int)($_SESSION['uid'] ?? 0), 'USER_DELETED', 'Permanently deleted user ' . $userId);
                        $message = 'User account was permanently deleted.';
                    } else {
                        $error = 'User account not found or already deleted.';
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
                    $message = 'User has linked appointments or transactions; account has been safely deactivated to preserve historical data.';
                } else {
                    $error = 'Unable to delete or deactivate this user account.';
                }
            }
        }
    }
}

// User count metrics
$buyerTotal = 0;
$agentTotal = 0;
$countQuery = $con->query("SELECT LOWER(role) as r, COUNT(*) as c FROM user WHERE LOWER(role) IN ('buyer', 'agent') GROUP BY LOWER(role)");
if ($countQuery) {
    while ($cr = $countQuery->fetch_assoc()) {
        if ($cr['r'] === 'buyer') $buyerTotal = (int)$cr['c'];
        if ($cr['r'] === 'agent') $agentTotal = (int)$cr['c'];
    }
}
$allTotal = $buyerTotal + $agentTotal;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <title>EstateScope | Buyer & Agent Directory</title>

    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=20261024">
    <link rel="stylesheet" href="assets/css/feathericon.min.css">

    <!-- Datatables CSS -->
    <link rel="stylesheet" href="assets/plugins/datatables/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/plugins/datatables/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/plugins/datatables/select.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/plugins/datatables/buttons.bootstrap4.min.css">

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
            <div class="row align-items-center">
                <div class="col">
                    <h3 class="page-title"><i class="fe fe-users mr-2 text-primary"></i> <?= htmlspecialchars($userFilterLabel, ENT_QUOTES, 'UTF-8') ?> Directory</h3>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">User List</li>
                    </ul>
                </div>
                <!-- Role Filter Buttons -->
                <div class="col-auto">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Filter users by role">
                        <a href="userlist.php" class="btn <?= $userFilter === '' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                            All (<span class="badge badge-light"><?= $allTotal ?></span>)
                        </a>
                        <a href="userlist.php?filter=agent" class="btn <?= $userFilter === 'agent' ? 'btn-info' : 'btn-outline-info' ?>">
                            Agents (<span class="badge badge-light"><?= $agentTotal ?></span>)
                        </a>
                        <a href="userlist.php?filter=buyer" class="btn <?= $userFilter === 'buyer' ? 'btn-primary' : 'btn-outline-primary' ?>">
                            Buyers (<span class="badge badge-light"><?= $buyerTotal ?></span>)
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- /Page Header -->

        <div class="row">
            <div class="col-sm-12">
                <div class="card user-mgmt-card shadow-sm">
                    <div class="card-header">
                        <h4 class="card-title mb-1 font-weight-bold">Registered Buyer and Agent Accounts</h4>
                        <p class="text-muted small mb-0">Use inline edit to update user credentials/roles and save, or delete accounts directly.</p>
                        <?php if ($message !== ''): ?>
                            <div class="alert alert-success alert-dismissible fade show mt-3" role="status">
                                <i class="fa fa-check-circle mr-1"></i> <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            </div>
                        <?php endif; ?>
                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
                                <i class="fa fa-exclamation-circle mr-1"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover user-table mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th style="min-width: 150px;">Name</th>
                                        <th style="min-width: 180px;">Email</th>
                                        <th style="min-width: 140px;">Phone</th>
                                        <th style="min-width: 110px;">Role</th>
                                        <th style="min-width: 100px;">Status</th>
                                        <th style="min-width: 120px;">Registered On</th>
                                        <th style="min-width: 190px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $query = mysqli_query($con, "SELECT user_id, name, email, phone_num, role, is_active, date_of_creation
                                        FROM user WHERE LOWER(role) IN ('buyer', 'agent')
                                        $userFilterSql
                                        ORDER BY date_of_creation DESC");
                                    $cnt = 1;
                                    while ($row = mysqli_fetch_assoc($query)) {
                                        $uId = (int)$row['user_id'];
                                        $uRole = strtolower($row['role']);
                                        $uActive = (int)$row['is_active'];
                                    ?>
                                    <tr id="user-row-<?= $uId ?>" class="user-row" data-role="<?= $uRole ?>">
                                        <td class="text-muted font-weight-bold"><?= $cnt++ ?></td>
                                        <td class="user-name-cell">
                                            <span class="view-val"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <input type="text" class="form-control form-control-sm edit-input d-none" id="input-name-<?= $uId ?>" value="<?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Full Name">
                                        </td>
                                        <td class="user-email-cell">
                                            <span class="view-val"><a href="mailto:<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?></a></span>
                                            <input type="email" class="form-control form-control-sm edit-input d-none" id="input-email-<?= $uId ?>" value="<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Email Address">
                                        </td>
                                        <td class="user-phone-cell">
                                            <span class="view-val"><?= htmlspecialchars($row['phone_num'] ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
                                            <input type="text" class="form-control form-control-sm edit-input d-none" id="input-phone-<?= $uId ?>" value="<?= htmlspecialchars($row['phone_num'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Phone Number">
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
                                            <?= htmlspecialchars(date('d M Y', strtotime($row['date_of_creation'] ?? 'now')), ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td class="text-center user-actions-cell">
                                            <!-- Action form for Save -->
                                            <form method="post" action="userlist.php<?= $userFilter ? '?filter=' . urlencode($userFilter) : '' ?>" class="d-inline" id="user-form-<?= $uId ?>" onsubmit="return syncUserData(<?= $uId ?>);">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="update_user">
                                                <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                <input type="hidden" name="name" id="post-name-<?= $uId ?>">
                                                <input type="hidden" name="email" id="post-email-<?= $uId ?>">
                                                <input type="hidden" name="phone" id="post-phone-<?= $uId ?>">
                                                <input type="hidden" name="role" id="post-role-<?= $uId ?>">
                                                <input type="hidden" name="is_active" id="post-status-<?= $uId ?>">

                                                <!-- Edit Button -->
                                                <button type="button" class="btn btn-sm btn-outline-primary mr-1" id="btn-edit-<?= $uId ?>" onclick="enableUserEdit(<?= $uId ?>)" title="Edit user">
                                                    <i class="fa fa-pencil"></i> Edit
                                                </button>
                                                <!-- Save Button -->
                                                <button type="submit" class="btn btn-sm btn-success mr-1 d-none" id="btn-save-<?= $uId ?>" title="Save changes">
                                                    <i class="fa fa-check"></i> Save
                                                </button>
                                                <!-- Cancel Button -->
                                                <button type="button" class="btn btn-sm btn-outline-secondary mr-1 d-none" id="btn-cancel-<?= $uId ?>" onclick="cancelUserEdit(<?= $uId ?>)" title="Cancel editing">
                                                    <i class="fa fa-times"></i> Cancel
                                                </button>
                                            </form>

                                            <!-- Delete Form & Button -->
                                            <form method="post" action="userlist.php<?= $userFilter ? '?filter=' . urlencode($userFilter) : '' ?>" class="d-inline" id="del-form-<?= $uId ?>" onsubmit="return confirm('Are you sure you want to delete this user? If historical bookings/payments exist, their account will be deactivated instead.');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" id="btn-del-<?= $uId ?>" title="Delete user">
                                                    <i class="fa fa-trash"></i> Delete
                                                </button>
                                            </form>
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
<!-- /Page Wrapper -->

<!-- Scripts -->
<script src="assets/js/jquery-3.2.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/plugins/slimscroll/jquery.slimscroll.min.js"></script>
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
</script>

</body>
</html>

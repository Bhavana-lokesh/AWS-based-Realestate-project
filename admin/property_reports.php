<?php
session_start();
require_once 'config.php';
require_once '../auth.php';
requireRole(['super_admin']);

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
    $status = (string)($_POST['status'] ?? '');
    if (!$reportId || !in_array($status, ['reviewed', 'dismissed'], true)) {
        $error = 'Choose a valid report status.';
    } else {
        $statement = $con->prepare("UPDATE property_reports SET status = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE report_id = ?");
        if (!$statement) {
            throw new RuntimeException('Property report update could not be prepared.');
        }
        $adminId = (int)($_SESSION['uid'] ?? 0);
        $statement->bind_param('sii', $status, $adminId, $reportId);
        if ($statement->execute()) {
            $message = 'Property report updated.';
        } else {
            $error = 'Property report could not be updated.';
        }
        $statement->close();
    }
}

$reports = $con->query("SELECT r.report_id, r.property_id, r.reporter_id, r.details, r.status,
    r.created_at, r.reviewed_at, p.title AS property_title, u.name AS reporter_name
    FROM property_reports r
    JOIN property_listings p ON p.property_id = r.property_id
    JOIN user u ON u.user_id = r.reporter_id
    ORDER BY FIELD(r.status, 'open', 'reviewed', 'dismissed'), r.created_at DESC");
if (!$reports) {
    throw new RuntimeException('Property reports could not be loaded.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Property reports | Super Admin</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="page-wrapper">
    <div class="content container-fluid">
        <div class="page-header"><h3 class="page-title">Reported properties</h3></div>
        <?php if ($message !== ''): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <div class="card"><div class="card-body table-responsive">
            <?php if ($reports->num_rows === 0): ?>
                <p>No property reports have been submitted.</p>
            <?php else: ?>
            <table class="table table-bordered table-hover">
                <thead><tr><th>Property</th><th>Reported by</th><th>Details</th><th>Reported</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php while ($report = $reports->fetch_assoc()): ?>
                    <tr>
                        <td><a href="../propertydetail.php?pid=<?= (int)$report['property_id'] ?>"><?= htmlspecialchars($report['property_title'], ENT_QUOTES, 'UTF-8') ?></a></td>
                        <td><?= htmlspecialchars($report['reporter_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= nl2br(htmlspecialchars($report['details'], ENT_QUOTES, 'UTF-8')) ?></td>
                        <td><?= htmlspecialchars($report['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(ucfirst($report['status']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($report['status'] === 'open'): ?>
                            <form method="post" action="property_reports.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="report_id" value="<?= (int)$report['report_id'] ?>">
                                <select class="form-control mb-2" name="status" aria-label="Report status">
                                    <option value="reviewed">Mark reviewed</option>
                                    <option value="dismissed">Dismiss</option>
                                </select>
                                <button class="btn btn-primary btn-sm" type="submit">Save</button>
                            </form>
                            <?php else: ?>
                                <?= htmlspecialchars($report['reviewed_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<script src="assets/js/jquery-3.2.1.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/js/script.js?v=20261024-2"></script>
</body>
</html>

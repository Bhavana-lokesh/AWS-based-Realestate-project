<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require("config.php");
require_once '../auth.php';
requireRole(['super_admin']);

$currentAdminPage = basename($_SERVER['PHP_SELF'] ?? '');
$usersActive = in_array($currentAdminPage, ['adminlist.php', 'userlist.php', 'useragent.php', 'userbuilder.php'], true);
$propertyActive = in_array($currentAdminPage, ['propertyadd.php', 'propertyview.php', 'propertyedit.php', 'property_reports.php'], true);
$transActive = in_array($currentAdminPage, ['Offerstoproperty.php', 'PropertyBoughtBy.php', 'Rentel_Intrests.php', 'Rent_Finalize.php'], true);
$analyticsActive = in_array($currentAdminPage, ['reports.php', 'view_audit_log.php'], true);
?>

<link rel="stylesheet" href="../css/premium.css?v=3">
<link rel="stylesheet" href="../css/admin-console.css?v=7">
<script>document.body.classList.add('admin-console');</script>
<div class="header">
    <!-- Logo -->
    <div class="header-left">
        <a href="dashboard.php" class="logo">
            <span class="admin-console-mark" aria-hidden="true">
                <svg viewBox="0 0 40 40" focusable="false">
                    <path d="M7.5 18.5 20 8l12.5 10.5V32h-25z"></path>
                    <path d="M16 32V22h8v10"></path>
                </svg>
            </span>
            <span class="admin-console-name">EstateScope<small>SUPER ADMIN</small></span>
        </a>
        <a href="dashboard.php" class="logo logo-small">
            <span class="admin-console-mark" aria-hidden="true">
                <svg viewBox="0 0 40 40" focusable="false">
                    <path d="M7.5 18.5 20 8l12.5 10.5V32h-25z"></path>
                    <path d="M16 32V22h8v10"></path>
                </svg>
            </span>
        </a>
    </div>
    <!-- /Logo -->

    <a href="javascript:void(0);" id="toggle_btn">
        <i class="fe fe-text-align-left"></i>
    </a>

    <!-- Mobile Menu Toggle -->
    <a class="mobile_btn" id="mobile_btn">
        <i class="fa fa-bars"></i>
    </a>
    <!-- /Mobile Menu Toggle -->

    <!-- Header Right Menu -->
    <ul class="nav user-menu">

        <!-- User Menu -->
        <li class="nav-item dropdown app-dropdown">
            <a href="#" class="dropdown-toggle nav-link" data-toggle="dropdown">
                <span class="user-img">
                    <img class="rounded-circle" src="assets/img/profiles/avatar-01.png" width="31" alt="Admin">
                </span>
            </a>
            <div class="dropdown-menu">
                <div class="user-header">
                    <div class="avatar avatar-sm">
                        <img src="assets/img/profiles/avatar-01.png" alt="User Image" class="avatar-img rounded-circle">
                    </div>
                    <div class="user-text">
                        <h6><?= htmlspecialchars((string)($_SESSION['name'] ?? $_SESSION['admin_email'] ?? 'Super Admin'), ENT_QUOTES, 'UTF-8') ?></h6>
                        <p class="text-muted mb-0"><?= htmlspecialchars((string)($_SESSION['admin_email'] ?? 'Super Admin'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <a class="dropdown-item" href="profile.php">Profile</a>
                <a class="dropdown-item" href="logout.php">Logout</a>
            </div>
        </li>
        <!-- /User Menu -->

    </ul>
    <!-- /Header Right Menu -->
</div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-inner slimscroll">
        <div id="sidebar-menu" class="sidebar-menu">
            <ul>
                <li class="menu-title"><span>Main</span></li>
                <li class="<?= $currentAdminPage === 'dashboard.php' ? 'active' : '' ?>"><a href="dashboard.php"><i class="fe fe-home"></i> <span>Dashboard</span></a></li>
                <li class="<?= $currentAdminPage === 'appointments.php' ? 'active' : '' ?>"><a href="appointments.php"><i class="fe fe-calendar"></i> <span>Appointments</span></a></li>
                <li class="<?= $currentAdminPage === 'payments.php' ? 'active' : '' ?>"><a href="payments.php"><i class="fe fe-credit-card"></i> <span>Payments</span></a></li>

                <li class="menu-title"><span>All Users</span></li>
                <li class="submenu <?= $usersActive ? 'active' : '' ?>">
                    <a href="#" class="<?= $usersActive ? 'active subdrop' : '' ?>"><i class="fe fe-user"></i> <span> All Users </span> <span class="menu-arrow"></span></a>
                    <ul style="<?= $usersActive ? '' : 'display: none;' ?>">
                        <li class="<?= $currentAdminPage === 'adminlist.php' ? 'active' : '' ?>"><a href="adminlist.php"> Super Admins </a></li>
                        <li class="<?= $currentAdminPage === 'userlist.php' ? 'active' : '' ?>"><a href="userlist.php"> Buyers & Agents </a></li>
                    </ul>
                </li>

                <li class="menu-title"><span>Property Management</span></li>
                <li class="submenu <?= $propertyActive ? 'active' : '' ?>">
                    <a href="#" class="<?= $propertyActive ? 'active subdrop' : '' ?>"><i class="fe fe-building"></i> <span> Property</span> <span class="menu-arrow"></span></a>
                    <ul style="<?= $propertyActive ? '' : 'display: none;' ?>">
                        <li class="<?= $currentAdminPage === 'propertyadd.php' ? 'active' : '' ?>"><a href="propertyadd.php"> Add Property</a></li>
                        <li class="<?= $currentAdminPage === 'propertyview.php' || $currentAdminPage === 'propertyedit.php' ? 'active' : '' ?>"><a href="propertyview.php"> View Property </a></li>
                        <li class="<?= $currentAdminPage === 'property_reports.php' ? 'active' : '' ?>"><a href="property_reports.php"> Reported properties </a></li>
                    </ul>
                </li>

                <li class="menu-title"><span>Transactions</span></li>
                <li class="submenu <?= $transActive ? 'active' : '' ?>">
                    <a href="#" class="<?= $transActive ? 'active subdrop' : '' ?>"><i class="fe fe-browser"></i> <span> Transactions</span> <span class="menu-arrow"></span></a>
                    <ul style="<?= $transActive ? '' : 'display: none;' ?>">
                        <li class="<?= $currentAdminPage === 'Offerstoproperty.php' ? 'active' : '' ?>"><a href="Offerstoproperty.php"> Offers to a property </a></li>
                        <li class="<?= $currentAdminPage === 'PropertyBoughtBy.php' ? 'active' : '' ?>"><a href="PropertyBoughtBy.php"> Property Bought By </a></li>
                        <li class="<?= $currentAdminPage === 'Rentel_Intrests.php' ? 'active' : '' ?>"><a href="Rentel_Intrests.php"> Rental Interests </a></li>
                        <li class="<?= $currentAdminPage === 'Rent_Finalize.php' ? 'active' : '' ?>"><a href="Rent_Finalize.php"> Rental Finalization </a></li>
                    </ul>
                </li>

                <li class="menu-title"><span>Analytics & Logs</span></li>
                <li class="<?= $currentAdminPage === 'reports.php' ? 'active' : '' ?>"><a href="reports.php"><i class="fe fe-bar-chart"></i> <span>Reports & Analytics</span></a></li>
                <li class="<?= $currentAdminPage === 'view_audit_log.php' ? 'active' : '' ?>"><a href="view_audit_log.php"><i class="fe fe-activity"></i> <span>Audit Log</span></a></li>
            </ul>
        </div>
    </div>
</div>
<!-- /Sidebar -->

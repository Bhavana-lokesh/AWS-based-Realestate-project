<?php
session_start();
require_once 'config.php';
require_once '../auth.php';
requireRole(['super_admin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LM Homes - Reports & Analytics</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=20261024">
    <link rel="stylesheet" href="assets/css/feathericon.min.css">
    <link rel="stylesheet" href="assets/plugins/morris/morris.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .chart-container {
            max-width: 960px;
            margin: 28px auto;
            padding: 22px;
            background: #fff;
            border: 1px solid var(--admin-border, #e2e7ef);
            border-radius: 12px;
        }
        body.admin-console .chart-container {
            border-color: var(--admin-border);
        }
        .chart-container canvas {
            max-height: 340px;
            width: 100% !important;
            height: auto !important;
        }
        .chart-hint {
            font-size: 0.85rem;
            color: var(--admin-muted, #6d788a);
            margin-top: 8px;
            text-align: center;
        }
    </style>
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
                    <h3 class="page-title">📊 Reports & Analytics Dashboard</h3>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Reports & Analytics</li>
                    </ul>
                </div>
            </div>
        </div>
        <!-- /Page Header -->

        <!-- Property Sales Report -->
        <h4 class="mt-4">🏘️ Property Sales Report (Day-wise)</h4>
        <div class="chart-container">
            <canvas id="salesChart"></canvas>
        </div>
        <?php
        $salesData = mysqli_query($con, 
            "SELECT DATE(created_at) AS Day, COUNT(*) AS Properties_Sold 
             FROM property_listings 
             WHERE status = 'sold' 
             GROUP BY DATE(created_at)
             ORDER BY DATE(created_at)");
        $days = [];
        $sales = [];
        while ($row = mysqli_fetch_assoc($salesData)) {
            $days[] = $row['Day'];
            $sales[] = $row['Properties_Sold'];
        }
        ?>

        <!-- User Engagement Report -->
        <h4 class="mt-5">👥 User Engagement (Registrations Per Day)</h4>
        <div class="chart-container">
            <canvas id="userChart"></canvas>
        </div>
        <?php
        $userData = mysqli_query($con, 
            "SELECT DATE(date_of_creation) AS Day, COUNT(*) AS Registrations 
             FROM user 
             GROUP BY DATE(date_of_creation)
             ORDER BY DATE(date_of_creation)");
        $userDays = [];
        $userCounts = [];
        while ($row = mysqli_fetch_assoc($userData)) {
            $userDays[] = $row['Day'];
            $userCounts[] = $row['Registrations'];
        }
        ?>

        <!-- Market Trends Report -->
        <h4 class="mt-5">📌 Market Trends (Average Property Price by Location)</h4>
        <div class="chart-container">
            <canvas id="priceChart"></canvas>
        </div>
        <?php
        $priceData = mysqli_query($con, 
            "SELECT location, AVG(price) AS AvgPrice 
             FROM property_listings 
             GROUP BY location");
        $locations = [];
        $avgPrices = [];
        while ($row = mysqli_fetch_assoc($priceData)) {
            $locations[] = $row['location'];
            $avgPrices[] = $row['AvgPrice'];
        }
        ?>

        <!-- Property Types Distribution -->
        <h4 class="mt-5">🏠 Property Types Distribution</h4>
        <div class="chart-container">
            <canvas id="typeChart"></canvas>
        </div>
        <?php
        $typeData = mysqli_query($con, 
            "SELECT property_type, COUNT(*) AS Count 
             FROM property_listings 
             GROUP BY property_type");
        $types = [];
        $typeCounts = [];
        while ($row = mysqli_fetch_assoc($typeData)) {
            $types[] = $row['property_type'];
            $typeCounts[] = $row['Count'];
        }
        ?>
    </div>
</div>
<!-- /Page Wrapper -->

<!-- Chart.js Scripts -->
<script>
    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', labels: { font: { size: 13 }, padding: 20 } },
            tooltip: { mode: 'index', intersect: false, backgroundColor: 'rgba(0,0,0,0.75)' },
            title: { display: true, font: { size: 15, weight: '600' }, padding: { top: 10, bottom: 20 } }
        },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 12 } }, grid: { color: '#eee' } },
            x: { ticks: { font: { size: 12 }, maxRotation: 45, minRotation: 45 }, grid: { display: false } }
        }
    };
    const clone = (o) => JSON.parse(JSON.stringify(o));
    const palette = ['#2563eb', '#10b981', '#f59e0b', '#ec4899', '#6366f1', '#8b5cf6', '#14b8a6'];

    // Bar — Properties sold per day
    new Chart(document.getElementById('salesChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($days); ?>,
            datasets: [{
                label: 'Properties Sold',
                data: <?= json_encode($sales); ?>,
                backgroundColor: palette[0],
                borderColor: palette[0],
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: Object.assign(clone(commonOptions), {
            plugins: Object.assign({}, commonOptions.plugins, { title: { display: true, text: 'Properties Sold per Day' } }),
            scales: Object.assign({}, commonOptions.scales, { y: Object.assign({}, commonOptions.scales.y, { title: { display: true, text: 'Count' } }) })
        })
    });

    // Line — User registrations per day
    new Chart(document.getElementById('userChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($userDays); ?>,
            datasets: [{
                label: 'User Registrations',
                data: <?= json_encode($userCounts); ?>,
                borderColor: palette[1],
                backgroundColor: palette[1] + '1a',
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                fill: false,
                tension: 0.3
            }]
        },
        options: Object.assign(clone(commonOptions), {
            plugins: Object.assign({}, commonOptions.plugins, { title: { display: true, text: 'User Registrations per Day' } }),
            scales: Object.assign({}, commonOptions.scales, { y: Object.assign({}, commonOptions.scales.y, { title: { display: true, text: 'Registrations' } }) })
        })
    });

    // Area — Average property price by location
    new Chart(document.getElementById('priceChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($locations); ?>,
            datasets: [{
                label: 'Average Price (₹)',
                data: <?= json_encode($avgPrices); ?>,
                borderColor: palette[2],
                backgroundColor: palette[2] + '26',
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                fill: true,
                tension: 0.4
            }]
        },
        options: Object.assign(clone(commonOptions), {
            plugins: Object.assign({}, commonOptions.plugins, { title: { display: true, text: 'Average Property Price by Location' } }),
            scales: Object.assign({}, commonOptions.scales, { y: Object.assign({}, commonOptions.scales.y, { title: { display: true, text: 'Avg Price (₹)' }, ticks: { callback: function (v) { return '₹' + v; } } }) })
        })
    });

    // Bar — Property types distribution
    new Chart(document.getElementById('typeChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($types); ?>,
            datasets: [{
                label: 'Number of Listings',
                data: <?= json_encode($typeCounts); ?>,
                backgroundColor: palette[3],
                borderColor: palette[3],
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: Object.assign(clone(commonOptions), {
            plugins: Object.assign({}, commonOptions.plugins, { title: { display: true, text: 'Property Types Distribution' } }),
            scales: Object.assign({}, commonOptions.scales, { y: Object.assign({}, commonOptions.scales.y, { title: { display: true, text: 'Count' } }) })
        })
    });
</script>

<!-- Scripts -->
<script src="assets/js/jquery-3.2.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/plugins/slimscroll/jquery.slimscroll.min.js"></script>
<script src="assets/plugins/raphael/raphael.min.js"></script>
<script src="assets/plugins/morris/morris.min.js"></script>
<script src="assets/js/chart.morris.js?v=20261024"></script>
<script src="assets/js/script.js?v=20261024-2"></script>

</body>
</html>

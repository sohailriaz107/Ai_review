<?php
session_start();
require_once __DIR__.'/../include/connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = $_SESSION['user_id'];

// Fetch user info
$u_stmt = $mysqli->prepare("SELECT name, email, created_at FROM users WHERE id=?");
$u_stmt->bind_param('i', $uid);
$u_stmt->execute();
$u_stmt->bind_result($uname, $uemail, $ucreated);
$u_stmt->fetch();
$u_stmt->close();

// Count total businesses
$b_stmt = $mysqli->prepare("SELECT COUNT(*) FROM businesses WHERE user_id=?");
$b_stmt->bind_param('i', $uid);
$b_stmt->execute();
$b_stmt->bind_result($total_businesses);
$b_stmt->fetch();
$b_stmt->close();

// Count total tokens/links generated
$t_stmt = $mysqli->prepare("SELECT COUNT(*) FROM review_tokens WHERE user_id=?");
$t_stmt->bind_param('i', $uid);
$t_stmt->execute();
$t_stmt->bind_result($total_tokens);
$t_stmt->fetch();
$t_stmt->close();

// Fetch all businesses with tokens for chart
$biz_stmt = $mysqli->prepare("SELECT b.name, b.category, b.city, b.created_at, t.token 
                               FROM businesses b 
                               LEFT JOIN review_tokens t ON b.id = t.business_id 
                               WHERE b.user_id = ? ORDER BY b.id DESC");
$biz_stmt->bind_param('i', $uid);
$biz_stmt->execute();
$biz_result = $biz_stmt->get_result();
$all_businesses = $biz_result->fetch_all(MYSQLI_ASSOC);
$biz_stmt->close();

// Build category count for pie chart
$cat_counts = [];
foreach ($all_businesses as $biz) {
    $c = $biz['category'] ?: 'Uncategorized';
    $cat_counts[$c] = ($cat_counts[$c] ?? 0) + 1;
}

// Build monthly business creation for bar chart
$monthly = [];
foreach ($all_businesses as $biz) {
    $month = date('M Y', strtotime($biz['created_at']));
    $monthly[$month] = ($monthly[$month] ?? 0) + 1;
}
// Reverse to show oldest first
$monthly = array_reverse($monthly, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard – AI Review</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.min.js"></script>
    
</head>
<body class="admin">
    <?php include '../include/sidebar.php'; ?>
    <main class="content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div>
                <h2>Welcome back, <?= htmlspecialchars($uname) ?>! 👋</h2>
                <p style="color: floralwhite;">Here's an overview of your AI Review system.</p>
            </div>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?= date('l, d M Y') ?>
            </div>
        </div>

        <!-- Stat Cards (Bootstrap BG Colors) -->
        <div class="dash-stats">
            <div class="stat-card bg-primary">
                <div>
                    <h3><?= $total_businesses ?></h3>
                    <p>Total Businesses</p>
                </div>
                <i class="fas fa-store stat-bg-icon"></i>
            </div>
            <div class="stat-card bg-success">
                <div>
                    <h3><?= $total_tokens ?></h3>
                    <p>Review Links</p>
                </div>
                <i class="fas fa-link stat-bg-icon"></i>
            </div>
            <div class="stat-card bg-warning">
                <div>
                    <h3><?= $total_tokens ?></h3>
                    <p>QR Codes Generated</p>
                </div>
                <i class="fas fa-qrcode stat-bg-icon"></i>
            </div>
            <div class="stat-card bg-info">
                <div>
                    <h3>Active</h3>
                    <p>AI Status</p>
                </div>
                <i class="fas fa-robot stat-bg-icon"></i>
            </div>
        </div>

        <!-- Charts -->
        <div class="chart-grid">
            <div class="chart-card">
                <h3><i class="fas fa-chart-area" style="color:#0d6efd;"></i> Businesses Added (Monthly)</h3>
                <div id="areaChart"></div>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-chart-pie" style="color:#6f42c1;"></i> Businesses by Category</h3>
                <div id="donutChart"></div>
            </div>
        </div>

        <!-- Recent 3 Businesses -->
        <div class="recent-table">
            <h3><i class="fas fa-clock" style="color:#fd7e14;"></i> Recent Businesses</h3>
            <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Business Name</th>
                        <th>Category</th>
                        <th>City</th>
                        <th>Token</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($all_businesses) > 0): ?>
                        <?php foreach(array_slice($all_businesses, 0, 3) as $biz): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($biz['name']) ?></strong></td>
                            <td><span class="badge badge-blue"><?= htmlspecialchars($biz['category']) ?></span></td>
                            <td><?= htmlspecialchars($biz['city'] ?: '-') ?></td>
                            <td><span class="badge badge-green"><?= htmlspecialchars($biz['token'] ?? 'N/A') ?></span></td>
                            <td><?= date('d M Y', strtotime($biz['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center;color:#aaa;padding:30px;">No businesses added yet. <a href="business.php" style="color:#0d6efd;">Add one now!</a></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </main>

    <script>
    // Area Chart - Monthly
    var areaOptions = {
        chart: { type: 'area', height: 310, toolbar: { show: false }, fontFamily: 'Inter', zoom: { enabled: false } },
        series: [{ name: 'Businesses Added', data: <?= json_encode(array_values($monthly)) ?> }],
        xaxis: { categories: <?= json_encode(array_keys($monthly)) ?>, labels: { style: { colors: '#999' } } },
        yaxis: { labels: { style: { colors: '#999' } } },
        colors: ['#0d6efd'],
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [0, 100] } },
        stroke: { curve: 'smooth', width: 3 },
        dataLabels: { enabled: false },
        grid: { borderColor: '#f0f0f0', strokeDashArray: 4 },
        markers: { size: 5, colors: ['#0d6efd'], strokeColors: '#fff', strokeWidth: 2 },
        tooltip: { theme: 'light' }
    };
    new ApexCharts(document.querySelector("#areaChart"), areaOptions).render();

    // Donut Chart - Categories
    var donutOptions = {
        chart: { type: 'donut', height: 310, fontFamily: 'Inter' },
        series: <?= json_encode(array_values($cat_counts)) ?>,
        labels: <?= json_encode(array_keys($cat_counts)) ?>,
        colors: ['#0d6efd', '#198754', '#dc3545', '#ffc107', '#6f42c1', '#0dcaf0', '#fd7e14', '#20c997'],
        legend: { position: 'bottom', fontSize: '12px' },
        dataLabels: { enabled: true, style: { fontSize: '12px' } },
        plotOptions: { pie: { donut: { size: '60%', labels: { show: true, total: { show: true, label: 'Total', fontSize: '14px', fontWeight: 600 } } } } },
        stroke: { width: 2, colors: ['#fff'] }
    };
    new ApexCharts(document.querySelector("#donutChart"), donutOptions).render();
    </script>
</body>
</html>

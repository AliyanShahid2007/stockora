<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();
$period = (int)($_GET['period'] ?? 30);
$dateFrom = date('Y-m-d', strtotime("-{$period} days"));

// Platform GMV trend (last 30 days)
$gmvTrend = [];
for ($i = $period - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $st = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as t FROM sales WHERE DATE(sale_date)=?");
    $st->execute([$d]);
    $gmvTrend[] = ['date' => date('d M', strtotime($d)), 'total' => (float)$st->fetch()['t']];
}

// Revenue trend
$revTrend = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-{$i} months"));
    $st = $db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='completed' AND DATE_FORMAT(payment_date, '%Y-%m')=?");
    $st->execute([$m]);
    $revTrend[] = ['month' => date('M Y', strtotime("-{$i} months")), 'total' => (float)$st->fetch()['t']];
}

// Shop growth (new shops per month last 6 months)
$shopGrowth = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-{$i} months"));
    $st = $db->prepare("SELECT COUNT(*) as c FROM shops WHERE DATE_FORMAT(created_at, '%Y-%m')=?");
    $st->execute([$m]);
    $shopGrowth[] = ['month' => date('M', strtotime("-{$i} months")), 'count' => (int)$st->fetch()['c']];
}

// Key metrics
$totalGMV     = (float)$db->query("SELECT COALESCE(SUM(grand_total),0) FROM sales")->fetchColumn();
$periodGMV    = (float)$db->prepare("SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE DATE(sale_date)>=?")->execute([$dateFrom]) ? 0 : 0;
$stP = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as t FROM sales WHERE DATE(sale_date)>=?"); $stP->execute([$dateFrom]); $periodGMV = (float)$stP->fetch()['t'];
$totalRevenue  = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed'")->fetchColumn();
$totalShops    = (int)$db->query("SELECT COUNT(*) FROM shops")->fetchColumn();
$activeShops   = (int)$db->query("SELECT COUNT(*) FROM shops WHERE status='active'")->fetchColumn();
$totalSales    = (int)$db->query("SELECT COUNT(*) FROM sales")->fetchColumn();
$totalProducts = (int)$db->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$totalCustomers= (int)$db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$avgSaleValue  = $totalSales > 0 ? $totalGMV / $totalSales : 0;
$avgRevenuePerShop = $activeShops > 0 ? $totalRevenue / $activeShops : 0;

// Subscription renewal rate
$totalSubsEver = (int)$db->query("SELECT COUNT(DISTINCT shop_id) FROM subscriptions")->fetchColumn();
$shopsWithMultiple = (int)$db->query("SELECT COUNT(*) FROM (SELECT shop_id FROM subscriptions GROUP BY shop_id HAVING COUNT(*)>1) AS t")->fetchColumn();
$renewalRate = $totalSubsEver > 0 ? round($shopsWithMultiple / $totalSubsEver * 100) : 0;

adminHeader('Platform Analytics', 'analytics');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Platform Analytics</h1>
        <p class="page-subtitle">Complete SaaS platform metrics and insights</p>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <?php foreach ([7=>'7d',30=>'30d',90=>'90d',365=>'1yr'] as $d => $lbl): ?>
        <a href="<?= BASE_URL ?>?period=<?= $d ?>" class="btn btn-sm btn-<?= $period==$d?'primary':'outline-secondary' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-graph-up"></i></div>
            <div class="stat-card-value">Rs.<?= $totalGMV>=1000?number_format($totalGMV/1000,0).'K':number_format($totalGMV,0) ?></div>
            <div class="stat-card-label">Total Platform GMV</div>
            <div class="stat-card-change">Rs.<?= number_format($periodGMV,0) ?> last <?= $period ?>d</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-cash-coin"></i></div>
            <div class="stat-card-value">Rs.<?= $totalRevenue>=1000?number_format($totalRevenue/1000,0).'K':number_format($totalRevenue,0) ?></div>
            <div class="stat-card-label">SaaS Revenue</div>
            <div class="stat-card-change">Rs.<?= number_format($avgRevenuePerShop,0) ?>/shop avg</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-shop"></i></div>
            <div class="stat-card-value"><?= $activeShops ?> / <?= $totalShops ?></div>
            <div class="stat-card-label">Active / Total Shops</div>
            <div class="stat-card-change"><?= $totalShops>0?round($activeShops/$totalShops*100):0 ?>% activation rate</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-info">
            <div class="stat-card-icon"><i class="bi bi-arrow-repeat"></i></div>
            <div class="stat-card-value"><?= $renewalRate ?>%</div>
            <div class="stat-card-label">Renewal Rate</div>
            <div class="stat-card-change"><?= $shopsWithMultiple ?> of <?= $totalSubsEver ?> renewed</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="fw-bold fs-4 text-primary"><?= $totalSales ?></div>
            <div class="text-muted small">Total Transactions</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="fw-bold fs-4 text-success">Rs.<?= number_format($avgSaleValue,0) ?></div>
            <div class="text-muted small">Avg Sale Value</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="fw-bold fs-4 text-warning"><?= $totalProducts ?></div>
            <div class="text-muted small">Total Products (All Shops)</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="fw-bold fs-4 text-info"><?= $totalCustomers ?></div>
            <div class="text-muted small">Total Customers</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- GMV Trend -->
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-activity me-2 text-primary"></i>GMV Trend (Last <?= $period ?> Days)</div>
            <div class="card-body p-3"><canvas id="gmvChart" height="120"></canvas></div>
        </div>
    </div>
    <!-- Shop Growth -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-shop me-2 text-success"></i>New Shops (6 Months)</div>
            <div class="card-body p-3"><canvas id="growthChart" height="200"></canvas></div>
        </div>
    </div>
</div>

<!-- Revenue 12 Month -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-bar-chart me-2 text-warning"></i>Monthly SaaS Revenue (12 Months)</div>
    <div class="card-body p-3"><canvas id="revChart" height="80"></canvas></div>
</div>

<script>
// GMV Trend
new Chart(document.getElementById('gmvChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_values(array_map(fn($d) => $d['date'], array_filter($gmvTrend, fn($d,$i) => $i % max(1, intdiv($period,15)) === 0 || $i === count($gmvTrend)-1, ARRAY_FILTER_USE_BOTH)))) ?>,
        datasets: [{
            label: 'GMV (Rs)',
            data: <?= json_encode(array_values(array_map(fn($d) => $d['total'], array_filter($gmvTrend, fn($d,$i) => $i % max(1, intdiv($period,15)) === 0 || $i === count($gmvTrend)-1, ARRAY_FILTER_USE_BOTH)))) ?>,
            borderColor: '#6C63FF', backgroundColor: 'rgba(108,99,255,0.1)',
            fill: true, tension: 0.4, pointRadius: 3
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: v => 'Rs.'+(v>=1000?(v/1000).toFixed(0)+'K':v) }, grid: { color: '#f0f0f0' } }, x: { grid: { display: false } } } }
});
// Shop Growth
new Chart(document.getElementById('growthChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($shopGrowth,'month')) ?>,
        datasets: [{ data: <?= json_encode(array_column($shopGrowth,'count')) ?>, backgroundColor: 'rgba(40,199,111,0.7)', borderRadius: 8 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
});
// Revenue 12 Months
new Chart(document.getElementById('revChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($revTrend,'month')) ?>,
        datasets: [{ label: 'Revenue', data: <?= json_encode(array_column($revTrend,'total')) ?>, backgroundColor: Array(12).fill(0).map((_,i) => `hsl(${240+i*10},70%,${55+i*2}%)`), borderRadius: 6 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: v => 'Rs.'+(v>=1000?(v/1000).toFixed(0)+'K':v) }, grid: { color: '#f0f0f0' } }, x: { grid: { display: false } } } }
});
</script>

<?php adminFooter(); ?>

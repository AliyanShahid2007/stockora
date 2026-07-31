<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();

// Filters
$filterMonth = $_GET['month'] ?? date('Y-m');
$filterShop = safeInt($_GET['shop'] ?? 0);

$where = "WHERE p.status='completed'";
$params = [];
if ($filterMonth) { $where .= " AND DATE_FORMAT(p.payment_date, '%Y-%m') = ?"; $params[] = $filterMonth; }
if ($filterShop) { $where .= " AND p.shop_id = ?"; $params[] = $filterShop; }

$stmt = $db->prepare("SELECT p.*, s.name as shop_name, s.phone as shop_phone FROM payments p JOIN shops s ON s.id=p.shop_id $where ORDER BY p.payment_date DESC");
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Totals
$totalRevenue = array_sum(array_column($payments, 'amount'));

// Monthly chart data (last 12 months)
$monthlyData = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-{$i} months"));
    $label = date('M Y', strtotime("-{$i} months"));
    $stmt2 = $db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='completed' AND DATE_FORMAT(payment_date, '%Y-%m')=?");
    $stmt2->execute([$m]);
    $monthlyData[] = ['label' => $label, 'total' => (float)$stmt2->fetch()['t']];
}

$shops = $db->query("SELECT id, name FROM shops ORDER BY name")->fetchAll();

adminHeader('Payments', 'payments');
?>

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-cash-coin me-2 text-primary"></i>Payment History</h1>
    <p class="page-subtitle">Track all subscription payments</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-currency-rupee"></i></div>
            <div class="stat-card-value"><?= formatCurrency(array_sum(array_column($db->query("SELECT amount FROM payments WHERE status='completed'")->fetchAll(),'amount'))) ?></div>
            <div class="stat-card-label">Total Revenue</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-calendar-month"></i></div>
            <div class="stat-card-value"><?= formatCurrency(end($monthlyData)['total']) ?></div>
            <div class="stat-card-label">This Month</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-receipt"></i></div>
            <div class="stat-card-value"><?= $db->query("SELECT COUNT(*) FROM payments WHERE status='completed'")->fetchColumn() ?></div>
            <div class="stat-card-label">Total Transactions</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-info">
            <div class="stat-card-icon"><i class="bi bi-filter-circle"></i></div>
            <div class="stat-card-value"><?= formatCurrency($totalRevenue) ?></div>
            <div class="stat-card-label">Filtered Total</div>
        </div>
    </div>
</div>

<!-- Chart -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-bar-chart me-2 text-primary"></i>Monthly Revenue Trend (12 Months)</div>
    <div class="card-body p-3"><canvas id="payChart" height="80"></canvas></div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body p-3">
        <form method="GET" class="row g-2">
            <div class="col-6 col-md-3">
                <input type="month" class="form-control" name="month" value="<?= htmlspecialchars($filterMonth) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select class="form-select" name="shop">
                    <option value="">All Shops</option>
                    <?php foreach ($shops as $sh): ?>
                    <option value="<?= $sh['id'] ?>" <?= $filterShop==$sh['id']?'selected':'' ?>><?= htmlspecialchars($sh['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="payments.php" class="btn btn-outline-secondary ms-1">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Payments Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list me-2"></i>Payments (<?= count($payments) ?>)</span>
        <a href="<?= BASE_URL ?>/admin/revenue.php?export=csv&month=<?= urlencode($filterMonth) ?>&shop=<?= $filterShop ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-download me-1"></i>Export CSV
        </a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>#</th><th>Shop</th><th>Amount</th><th>Method</th><th>Reference</th><th>Date</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $i => $pay): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($pay['shop_name']) ?></td>
                    <td class="fw-bold text-success"><?= formatCurrency($pay['amount']) ?></td>
                    <td><span class="badge" style="background:rgba(167,139,250,.15);color:#C4B5FD;"><?= ucfirst($pay['payment_method']) ?></span></td>
                    <td><small><?= htmlspecialchars($pay['reference_no'] ?? '-') ?></small></td>
                    <td><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                    <td><span class="badge status-active">Completed</span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($payments)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No payments found for selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const ctx = document.getElementById('payChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($monthlyData,'label')) ?>,
        datasets: [{
            label: 'Revenue',
            data: <?= json_encode(array_column($monthlyData,'total')) ?>,
            borderColor: '#6C63FF',
            backgroundColor: 'rgba(108,99,255,0.1)',
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#6C63FF',
            pointRadius: 4
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => 'Rs.' + (v/1000).toFixed(0) + 'K' }, grid: { color: '#f0f0f0' } },
            x: { grid: { display: false } }
        }
    }
});
</script>
<?php adminFooter(); ?>

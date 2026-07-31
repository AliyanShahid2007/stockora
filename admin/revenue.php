<?php
require_once '../includes/functions.php';
requireAdmin();

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $db = getDB();
    $filterMonth = $_GET['month'] ?? '';
    $filterShop = safeInt($_GET['shop'] ?? 0);
    $where = "WHERE p.status='completed'";
    $params = [];
    if ($filterMonth) { $where .= " AND DATE_FORMAT(p.payment_date, '%Y-%m')=?"; $params[] = $filterMonth; }
    if ($filterShop) { $where .= " AND p.shop_id=?"; $params[] = $filterShop; }
    $stmt = $db->prepare("SELECT p.*, s.name as shop_name FROM payments p JOIN shops s ON s.id=p.shop_id $where ORDER BY p.payment_date DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stockora_revenue_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#','Shop','Amount','Method','Reference','Date','Status']);
    foreach ($rows as $i => $r) {
        fputcsv($out, [$i+1, $r['shop_name'], $r['amount'], $r['payment_method'], $r['reference_no'] ?? '', $r['payment_date'], $r['status']]);
    }
    fclose($out);
    exit;
}

require_once '../includes/admin_layout.php';
$db = getDB();

// Revenue by shop
$shopRevenue = $db->query("SELECT s.name, COALESCE(SUM(p.amount),0) as total FROM shops s LEFT JOIN payments p ON p.shop_id=s.id AND p.status='completed' GROUP BY s.id ORDER BY total DESC LIMIT 10")->fetchAll();

// Yearly stats
$yearlyStats = [];
for ($m = 1; $m <= 12; $m++) {
    $month = date('Y') . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='completed' AND DATE_FORMAT(payment_date, '%Y-%m')=?");
    $stmt->execute([$month]);
    $yearlyStats[] = ['m' => date('M', mktime(0,0,0,$m,1)), 'v' => (float)$stmt->fetch()['t']];
}

adminHeader('Revenue Reports', 'revenue');
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Revenue Reports</h1>
        <p class="page-subtitle">Annual revenue analytics for <?= date('Y') ?></p>
    </div>
    <a href="<?= BASE_URL ?>?export=csv" class="btn btn-success"><i class="bi bi-download me-1"></i>Export CSV</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-bar-chart me-2 text-primary"></i>Monthly Revenue <?= date('Y') ?></div>
            <div class="card-body p-3"><canvas id="yearlyChart" height="120"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-md-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-trophy me-2 text-primary"></i>Top Revenue Shops</div>
            <div class="card-body p-3">
                <?php foreach ($shopRevenue as $i => $sr): ?>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div style="width:28px;height:28px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);border-radius:8px;display:flex;align-items:center;justify-content:center;color:white;font-size:0.75rem;font-weight:700;">
                        <?= $i+1 ?>
                    </div>
                    <div style="flex:1;">
                        <div class="fw-semibold small"><?= htmlspecialchars($sr['name']) ?></div>
                        <div class="progress" style="height:6px;border-radius:10px;">
                            <?php $max = max(array_column($shopRevenue,'total')) ?: 1; ?>
                            <div class="progress-bar" style="width:<?= ($sr['total']/$max*100) ?>%;background:linear-gradient(90deg,#6C63FF,#3ECFCF);"></div>
                        </div>
                    </div>
                    <div class="fw-bold text-success small"><?= formatCurrency($sr['total']) ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($shopRevenue)): ?>
                <div class="text-center text-muted py-3">No revenue data yet</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('yearlyChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($yearlyStats,'m')) ?>,
        datasets: [{
            label: 'Revenue (PKR)',
            data: <?= json_encode(array_column($yearlyStats,'v')) ?>,
            backgroundColor: Array(12).fill(0).map((_,i) => `hsl(${240+i*10},70%,${55+i*2}%)`),
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => 'Rs.'+(v/1000).toFixed(0)+'K' }, grid: { color: '#f0f0f0' } },
            x: { grid: { display: false } }
        }
    }
});
</script>
<?php adminFooter(); ?>

<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();
$stats = getAdminDashboardStats();

// ── Revenue data ──────────────────────────────────────────
$revenueData = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-{$i} months"));
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status='completed' AND DATE_FORMAT(payment_date, '%Y-%m')=?");
    $stmt->execute([$month]);
    $revenueData[] = ['month' => date('M', strtotime("-{$i} months")), 'total' => (float)$stmt->fetch()['total']];
}

// ── Recent shops ──────────────────────────────────────────
$recentShops = $db->query("
    SELECT s.*, 
           sub.status as sub_status, sub.end_date,
           u.email as owner_email,
           (SELECT COUNT(*) FROM sales WHERE shop_id=s.id) as total_sales
    FROM shops s
    LEFT JOIN subscriptions sub ON sub.shop_id=s.id AND sub.id=(SELECT id FROM subscriptions WHERE shop_id=s.id ORDER BY end_date DESC LIMIT 1)
    LEFT JOIN users u ON u.shop_id=s.id AND u.role='owner'
    ORDER BY s.created_at DESC LIMIT 6")->fetchAll();

// ── Subscription breakdown ─────────────────────────────────
$activeSubs  = (int)$db->query("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND end_date>=CURDATE()")->fetchColumn();
$expiredSubs = (int)$db->query("SELECT COUNT(*) FROM subscriptions WHERE status='expired' OR (status='active' AND end_date<CURDATE())")->fetchColumn();
$expiringSoon = (int)$db->query("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

// ── Platform GMV (total sales across all shops) ───────────
$totalGMV = (float)$db->query("SELECT COALESCE(SUM(grand_total),0) FROM sales")->fetchColumn();
$monthGMV  = (float)$db->prepare("SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE DATE(sale_date)>=?")->execute([date('Y-m-01')]) ? 0 : 0;
$stmtGMV = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as t FROM sales WHERE DATE(sale_date)>=?");
$stmtGMV->execute([date('Y-m-01')]); $monthGMV = (float)$stmtGMV->fetch()['t'];

// ── Today stats ────────────────────────────────────────────
$todayRevenue = (float)$db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='completed' AND DATE(payment_date)=?")->execute([date('Y-m-d')]) ? 0 : 0;
$stmtTR = $db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='completed' AND DATE(payment_date)=?");
$stmtTR->execute([date('Y-m-d')]); $todayRevenue = (float)$stmtTR->fetch()['t'];

// ── Active announcements ───────────────────────────────────
$announcements = $db->query("SELECT * FROM announcements WHERE status='active' ORDER BY created_at DESC LIMIT 3")->fetchAll();

adminHeader('Dashboard', 'dashboard');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-speedometer2 me-2 text-primary"></i>Admin Dashboard</h1>
        <p class="page-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?>! Platform overview for <?= date('d M Y') ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/admin/shops.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New Shop</a>
        <a href="<?= BASE_URL ?>/admin/announcements.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-megaphone me-1"></i>Announce</a>
        <a href="<?= BASE_URL ?>/admin/shops_export.php" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Export</a>
    </div>
</div>

<!-- ── EXPIRING SOON ALERT ── -->
<?php if ($expiringSoon > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 rounded-3 mb-4 py-2">
    <i class="bi bi-bell-fill fs-5"></i>
    <div class="flex-grow-1">
        <strong><?= $expiringSoon ?> shop<?= $expiringSoon > 1 ? 's' : '' ?> expiring within 7 days!</strong>
        <span class="d-none d-md-inline"> Renew subscriptions to avoid service interruption.</span>
    </div>
    <a href="<?= BASE_URL ?>/admin/subscriptions.php?filter=expiring" class="btn btn-sm btn-warning">View Now</a>
</div>
<?php endif; ?>

<!-- ── MAIN STATS ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-shop"></i></div>
            <div class="stat-card-value"><?= $stats['active_shops'] ?></div>
            <div class="stat-card-label">Active Shops</div>
            <div class="stat-card-change"><i class="bi bi-buildings me-1"></i><?= $stats['total_shops'] ?> total</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-cash-coin"></i></div>
            <div class="stat-card-value">Rs.<?= $stats['total_revenue'] >= 1000 ? number_format($stats['total_revenue']/1000,0).'K' : number_format($stats['total_revenue'],0) ?></div>
            <div class="stat-card-label">Total Revenue</div>
            <div class="stat-card-change"><i class="bi bi-calendar-month me-1"></i>Rs.<?= number_format($stats['monthly_revenue'],0) ?> this month</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-calendar-check"></i></div>
            <div class="stat-card-value"><?= $activeSubs ?></div>
            <div class="stat-card-label">Active Subs</div>
            <div class="stat-card-change">
                <?php if ($expiringSoon > 0): ?>
                <span class="text-warning"><i class="bi bi-clock me-1"></i><?= $expiringSoon ?> expiring</span>
                <?php else: ?>
                <i class="bi bi-check-circle me-1"></i>All healthy
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-info">
            <div class="stat-card-icon"><i class="bi bi-graph-up"></i></div>
            <div class="stat-card-value">Rs.<?= $totalGMV >= 1000 ? number_format($totalGMV/1000,0).'K' : number_format($totalGMV,0) ?></div>
            <div class="stat-card-label">Platform GMV</div>
            <div class="stat-card-change"><i class="bi bi-receipt me-1"></i>Rs.<?= number_format($monthGMV,0) ?> this month</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Revenue Chart -->
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-bar-chart-line me-2 text-primary"></i>Monthly Revenue (Last 6 Months)</span>
                <a href="<?= BASE_URL ?>/admin/payments.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-3"><canvas id="revenueChart" height="110"></canvas></div>
        </div>
    </div>
    <!-- Subscription Status -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-2 text-primary"></i>Subscription Status</div>
            <div class="card-body p-3">
                <canvas id="subChart" height="160"></canvas>
                <div class="mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span><span style="display:inline-block;width:12px;height:12px;background:#28c76f;border-radius:3px;margin-right:6px;"></span>Active</span>
                        <strong class="text-success"><?= $activeSubs ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span><span style="display:inline-block;width:12px;height:12px;background:#ea5455;border-radius:3px;margin-right:6px;"></span>Expired</span>
                        <strong class="text-danger"><?= $expiredSubs ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span><span style="display:inline-block;width:12px;height:12px;background:#ff9f43;border-radius:3px;margin-right:6px;"></span>Expiring (7d)</span>
                        <strong class="text-warning"><?= $expiringSoon ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── ANNOUNCEMENTS + QUICK ACTIONS ── -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-megaphone me-2 text-primary"></i>Active Announcements</span>
                <a href="<?= BASE_URL ?>/admin/announcements.php" class="btn btn-sm btn-outline-primary">Manage</a>
            </div>
            <div class="card-body p-3">
                <?php if (empty($announcements)): ?>
                <div class="text-center text-muted py-3">
                    <i class="bi bi-megaphone fs-3 d-block mb-2 opacity-25"></i>
                    No active announcements
                </div>
                <?php else: ?>
                <?php foreach ($announcements as $ann): ?>
                <?php $colors = ['info'=>'primary','warning'=>'warning','success'=>'success','danger'=>'danger']; $c = $colors[$ann['type']] ?? 'primary'; ?>
                <div class="d-flex gap-2 mb-3 p-2 rounded-2" style="background:rgba(255,255,255,.05);border:1px solid rgba(167,139,250,.12);">
                    <div style="width:4px;background:var(--<?= $c ?>);border-radius:4px;flex-shrink:0;"></div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small"><?= htmlspecialchars($ann['title']) ?></div>
                        <div class="text-muted" style="font-size:0.78rem;"><?= htmlspecialchars(substr($ann['message'],0,80)) ?>...</div>
                        <div class="text-muted" style="font-size:0.72rem;"><?= date('d M Y', strtotime($ann['created_at'])) ?></div>
                    </div>
                    <span class="badge bg-<?= $c ?> align-self-start"><?= ucfirst($ann['type']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-lightning-charge me-2 text-primary"></i>Quick Actions</div>
            <div class="card-body p-3">
                <div class="row g-2">
                    <div class="col-6">
                        <a href="<?= BASE_URL ?>/admin/shops.php" class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1">
                            <i class="bi bi-shop fs-4"></i><span style="font-size:0.78rem;">New Shop</span>
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?= BASE_URL ?>/admin/subscriptions.php" class="btn btn-outline-success w-100 py-3 d-flex flex-column align-items-center gap-1">
                            <i class="bi bi-calendar-plus fs-4"></i><span style="font-size:0.78rem;">Add Subscription</span>
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?= BASE_URL ?>/admin/announcements.php" class="btn btn-outline-warning w-100 py-3 d-flex flex-column align-items-center gap-1">
                            <i class="bi bi-megaphone fs-4"></i><span style="font-size:0.78rem;">Broadcast</span>
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?= BASE_URL ?>/admin/top_shops.php" class="btn btn-outline-info w-100 py-3 d-flex flex-column align-items-center gap-1">
                            <i class="bi bi-trophy fs-4"></i><span style="font-size:0.78rem;">Top Shops</span>
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?= BASE_URL ?>/admin/shops_export.php" class="btn btn-outline-secondary w-100 py-3 d-flex flex-column align-items-center gap-1">
                            <i class="bi bi-file-earmark-spreadsheet fs-4"></i><span style="font-size:0.78rem;">Export Data</span>
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?= BASE_URL ?>/admin/subscription_calendar.php" class="btn btn-outline-danger w-100 py-3 d-flex flex-column align-items-center gap-1">
                            <i class="bi bi-calendar3 fs-4"></i><span style="font-size:0.78rem;">Sub Calendar</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── RECENT SHOPS ── -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-shop me-2 text-primary"></i>Recent Shops</span>
        <a href="<?= BASE_URL ?>/admin/shops.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>#</th><th>Shop</th><th>Owner Email</th><th>Subscription</th><th>Expires</th><th>Sales</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recentShops as $i => $shop):
                    $subSt = $shop['sub_status'];
                    if ($shop['end_date'] && $shop['end_date'] < date('Y-m-d')) $subSt = 'expired';
                    $cls = match($subSt) {'active'=>'status-active','expired'=>'status-expired',default=>'status-inactive'};
                ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div style="width:32px;height:32px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);border-radius:8px;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:0.75rem;flex-shrink:0;">
                                <?= strtoupper(substr($shop['name'],0,2)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold small"><?= htmlspecialchars($shop['name']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($shop['city'] ?? '') ?></div>
                            </div>
                        </div>
                    </td>
                    <td><small><?= htmlspecialchars($shop['owner_email'] ?? '-') ?></small></td>
                    <td><span class="badge <?= $cls ?>"><?= ucfirst($subSt ?: 'None') ?></span></td>
                    <td><small><?= $shop['end_date'] ? date('d M Y', strtotime($shop['end_date'])) : '-' ?></small></td>
                    <td><span class="badge" style="background:rgba(167,139,250,.15);color:#C4B5FD;"><?= $shop['total_sales'] ?></span></td>
                    <td><span class="badge <?= $shop['status']==='active'?'status-active':'status-inactive' ?>"><?= ucfirst($shop['status']) ?></span></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="<?= BASE_URL ?>/admin/shops.php?action=edit&id=<?= $shop['id'] ?>" class="btn btn-xs btn-outline-primary" style="padding:.2rem .5rem;font-size:.72rem;"><i class="bi bi-pencil"></i></a>
                            <a href="<?= BASE_URL ?>/admin/subscriptions.php?shop_id=<?= $shop['id'] ?>" class="btn btn-xs btn-outline-success" style="padding:.2rem .5rem;font-size:.72rem;"><i class="bi bi-calendar-plus"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentShops)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No shops yet. <a href="<?= BASE_URL ?>/admin/shops.php">Create first shop</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Revenue Chart
new Chart(document.getElementById('revenueChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($revenueData,'month')) ?>,
        datasets: [{
            label: 'Revenue (PKR)',
            data: <?= json_encode(array_column($revenueData,'total')) ?>,
            backgroundColor: 'rgba(108,99,255,0.2)',
            borderColor: '#6C63FF',
            borderWidth: 2, borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f0f0f0' }, ticks: { callback: v => 'Rs.'+(v>=1000?(v/1000).toFixed(0)+'K':v) } },
            x: { grid: { display: false } }
        }
    }
});
// Sub Doughnut
new Chart(document.getElementById('subChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Active','Expired','Expiring'],
        datasets: [{ data: [<?= $activeSubs ?>, <?= $expiredSubs ?>, <?= $expiringSoon ?>], backgroundColor: ['#28c76f','#ea5455','#ff9f43'], borderWidth: 0, borderRadius: 4 }]
    },
    options: { responsive: true, cutout: '65%', plugins: { legend: { display: false } } }
});
</script>
<?php adminFooter(); ?>

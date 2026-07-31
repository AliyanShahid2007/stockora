<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/admin_layout.php';
requireAdmin();
$db = getDB();

$shopId = safeInt($_GET['shop_id'] ?? 0);

// Get shop details if specific
$shopData = null;
$shops = $db->query("SELECT id, name FROM shops ORDER BY name")->fetchAll();
if ($shopId) {
    $stmt = $db->prepare("SELECT s.*, u.email as owner_email, u.last_login FROM shops s LEFT JOIN users u ON u.shop_id=s.id AND u.role='owner' WHERE s.id=?");
    $stmt->execute([$shopId]);
    $shopData = $stmt->fetch();
}

// Build activity log from multiple tables
$activityLog = [];

if ($shopId) {
    // Recent sales
    $sales = $db->prepare("SELECT 'sale' as type, invoice_no as ref, grand_total as amount, sale_date as date, payment_method as detail FROM sales WHERE shop_id=? ORDER BY sale_date DESC LIMIT 20");
    $sales->execute([$shopId]);
    foreach ($sales->fetchAll() as $row) {
        $activityLog[] = ['type' => 'sale', 'icon' => 'bi-receipt text-success', 'title' => 'Sale: ' . $row['ref'], 'detail' => formatCurrency($row['amount']) . ' via ' . ucfirst($row['detail']), 'date' => $row['date']];
    }
    
    // Recent purchases
    $purchases = $db->prepare("SELECT 'purchase' as type, supplier_name, total_amount, purchase_date FROM purchases WHERE shop_id=? ORDER BY purchase_date DESC LIMIT 10");
    $purchases->execute([$shopId]);
    foreach ($purchases->fetchAll() as $row) {
        $activityLog[] = ['type' => 'purchase', 'icon' => 'bi-truck text-info', 'title' => 'Purchase: ' . ($row['supplier_name'] ?: 'Unknown'), 'detail' => formatCurrency($row['total_amount']), 'date' => $row['purchase_date']];
    }
    
    // Subscription payments
    $payments = $db->prepare("SELECT p.amount, p.payment_method as method, p.created_at, sub.plan_name FROM payments p LEFT JOIN subscriptions sub ON p.subscription_id=sub.id WHERE p.shop_id=? ORDER BY p.created_at DESC LIMIT 10");
    $payments->execute([$shopId]);
    foreach ($payments->fetchAll() as $row) {
        $activityLog[] = ['type' => 'payment', 'icon' => 'bi-cash-coin text-primary', 'title' => 'Subscription Payment: ' . ($row['plan_name'] ?: 'Plan'), 'detail' => formatCurrency($row['amount']) . ' via ' . ucfirst($row['method']), 'date' => $row['created_at']];
    }
    
    // Sort by date
    usort($activityLog, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
    $activityLog = array_slice($activityLog, 0, 30);
    
    // Stats for this shop
    $shopStats = $db->prepare("SELECT 
        COUNT(DISTINCT s.id) as total_sales,
        COALESCE(SUM(s.grand_total),0) as total_revenue,
        COUNT(DISTINCT p.id) as total_products,
        COALESCE(SUM(s.grand_total - s.discount),0) as net_revenue,
        COUNT(DISTINCT c.id) as total_customers
        FROM shops sh 
        LEFT JOIN sales s ON s.shop_id=sh.id
        LEFT JOIN products p ON p.shop_id=sh.id
        LEFT JOIN customers c ON c.shop_id=sh.id
        WHERE sh.id=?");
    $shopStats->execute([$shopId]);
    $stats = $shopStats->fetch();
} else {
    // Global activity: all recent activity
    $globalSales = $db->query("SELECT s.name as shop_name, sa.invoice_no, sa.grand_total, sa.sale_date FROM sales sa JOIN shops s ON sa.shop_id=s.id ORDER BY sa.sale_date DESC LIMIT 30")->fetchAll();
    foreach ($globalSales as $row) {
        $activityLog[] = ['type' => 'sale', 'icon' => 'bi-receipt text-success', 'title' => $row['shop_name'] . ': Sale ' . $row['invoice_no'], 'detail' => formatCurrency($row['grand_total']), 'date' => $row['sale_date']];
    }
    $globalPay = $db->query("SELECT s.name as shop_name, p.amount, p.payment_method as method, p.created_at FROM payments p JOIN shops s ON p.shop_id=s.id WHERE p.status='completed' ORDER BY p.created_at DESC LIMIT 20")->fetchAll();
    foreach ($globalPay as $row) {
        $activityLog[] = ['type' => 'payment', 'icon' => 'bi-cash-coin text-primary', 'title' => $row['shop_name'] . ': Payment', 'detail' => formatCurrency($row['amount']), 'date' => $row['created_at']];
    }
    usort($activityLog, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
    $activityLog = array_slice($activityLog, 0, 40);
    $stats = null;
}

adminHeader('Shop Activity Log', 'activity_log');
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-activity me-2 text-primary"></i>
            <?= $shopData ? htmlspecialchars($shopData['name']) . ' - Activity Log' : 'All Shops Activity Log' ?>
        </h1>
        <p class="page-subtitle">Real-time activity tracking across all shops</p>
    </div>
    <?php if ($shopData): ?>
    <a href="<?= BASE_URL ?>?shop_id=0" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>All Shops</a>
    <?php endif; ?>
</div>

<!-- Shop Filter -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" class="d-flex gap-2 align-items-end">
            <div class="flex-grow-1">
                <label class="form-label fw-medium small">Filter by Shop</label>
                <select class="form-select" name="shop_id" onchange="this.form.submit()">
                    <option value="0">All Shops</option>
                    <?php foreach ($shops as $sh): ?>
                    <option value="<?= $sh['id'] ?>" <?= $shopId == $sh['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sh['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($shopData && $stats): ?>
<!-- Shop Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-receipt"></i></div>
            <div class="stat-card-value"><?= number_format($stats['total_sales']) ?></div>
            <div class="stat-card-label">Total Sales</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-cash"></i></div>
            <div class="stat-card-value"><?= formatCurrency($stats['total_revenue']) ?></div>
            <div class="stat-card-label">Total Revenue</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-box-seam"></i></div>
            <div class="stat-card-value"><?= number_format($stats['total_products']) ?></div>
            <div class="stat-card-label">Products</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-info">
            <div class="stat-card-icon"><i class="bi bi-people"></i></div>
            <div class="stat-card-value"><?= number_format($stats['total_customers']) ?></div>
            <div class="stat-card-label">Customers</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Activity Timeline -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Recent Activity</span>
        <span class="badge bg-primary"><?= count($activityLog) ?> events</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($activityLog)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <div class="empty-state-icon"><i class="bi bi-clock"></i></div>
                <h5>No Activity Yet</h5>
                <p class="text-muted">No activity recorded for this period.</p>
            </div>
        </div>
        <?php else: ?>
        <div style="max-height:600px;overflow-y:auto;">
            <?php foreach ($activityLog as $event): ?>
            <div class="d-flex align-items-start gap-3 px-4 py-3 border-bottom">
                <div style="width:36px;height:36px;background:rgba(167,139,250,.1);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem;">
                    <i class="bi <?= $event['icon'] ?>"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold small"><?= htmlspecialchars($event['title']) ?></div>
                    <div class="text-muted small"><?= htmlspecialchars($event['detail']) ?></div>
                </div>
                <div class="text-muted" style="font-size:0.75rem;white-space:nowrap;">
                    <?= date('d M, h:i A', strtotime($event['date'])) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php adminFooter(); ?>

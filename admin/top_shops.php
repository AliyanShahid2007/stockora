<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();
$period = $_GET['period'] ?? '30';
$dateFrom = date('Y-m-d', strtotime("-{$period} days"));

// Top shops by revenue (payments received)
$topByRevenue = $db->prepare("
    SELECT s.id, s.name, s.city, s.status,
           COALESCE(SUM(p.amount),0) as total_revenue,
           COUNT(DISTINCT p.id) as payment_count,
           sub.end_date
    FROM shops s
    LEFT JOIN payments p ON p.shop_id=s.id AND p.status='completed'
    LEFT JOIN subscriptions sub ON sub.shop_id=s.id AND sub.id=(SELECT id FROM subscriptions WHERE shop_id=s.id ORDER BY end_date DESC LIMIT 1)
    GROUP BY s.id ORDER BY total_revenue DESC LIMIT 10
");
$topByRevenue->execute();
$topByRevenue = $topByRevenue->fetchAll();

// Top shops by sales (GMV)
$topBySales = $db->prepare("
    SELECT s.id, s.name, s.city,
           COALESCE(SUM(sa.grand_total),0) as gmv,
           COUNT(sa.id) as sale_count,
           COUNT(DISTINCT sa.customer_name) as unique_customers
    FROM shops s
    LEFT JOIN sales sa ON sa.shop_id=s.id AND DATE(sa.sale_date) >= ?
    GROUP BY s.id ORDER BY gmv DESC LIMIT 10
");
$topBySales->execute([$dateFrom]);
$topBySales = $topBySales->fetchAll();

// Top shops by products
$topByProducts = $db->prepare("
    SELECT s.id, s.name,
           COUNT(p.id) as product_count,
           SUM(p.stock_quantity) as total_stock,
           COALESCE(SUM(p.retail_price * p.stock_quantity),0) as stock_value
    FROM shops s LEFT JOIN products p ON p.shop_id=s.id AND p.status='active'
    GROUP BY s.id ORDER BY product_count DESC LIMIT 10
");
$topByProducts->execute();
$topByProducts = $topByProducts->fetchAll();

$maxRevenue = max(array_column($topByRevenue, 'total_revenue') ?: [1]);
$maxGMV = max(array_column($topBySales, 'gmv') ?: [1]);

adminHeader('Top Shops', 'top_shops');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-trophy me-2 text-warning"></i>Top Performing Shops</h1>
        <p class="page-subtitle">Rankings and performance metrics</p>
    </div>
    <div class="d-flex gap-2">
        <?php foreach (['7'=>'7 Days','30'=>'30 Days','90'=>'90 Days','365'=>'1 Year'] as $d => $label): ?>
        <a href="<?= BASE_URL ?>?period=<?= $d ?>" class="btn btn-sm btn-<?= $period==$d?'primary':'outline-secondary' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Top by Revenue (Subscription Payments) -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-cash-coin me-2 text-success"></i>Top by Subscription Revenue</div>
            <div class="card-body p-3">
                <?php foreach ($topByRevenue as $i => $shop): ?>
                <?php $pct = $maxRevenue > 0 ? ($shop['total_revenue']/$maxRevenue*100) : 0; ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="fw-bold text-center flex-shrink-0" style="width:28px;height:28px;background:<?= $i===0?'#ffd700':($i===1?'#c0c0c0':($i===2?'#cd7f32':'#e9ecef')) ?>;border-radius:8px;line-height:28px;font-size:.8rem;">
                        <?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':$i+1)) ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold small truncate"><?= htmlspecialchars($shop['name']) ?></span>
                            <span class="fw-bold text-success small">Rs. <?= number_format($shop['total_revenue'],0) ?></span>
                        </div>
                        <div class="progress" style="height:6px;border-radius:10px;">
                            <div class="progress-bar bg-success" style="width:<?= $pct ?>%;border-radius:10px;"></div>
                        </div>
                        <div style="font-size:.7rem;color:var(--text2,#8eb8c4);"><?= $shop['payment_count'] ?> payments · <?= htmlspecialchars($shop['city'] ?? '') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($topByRevenue)): ?><div class="text-center text-muted py-3">No data</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top by Sales GMV -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-graph-up me-2 text-primary"></i>Top by Sales GMV (Last <?= $period ?> days)</div>
            <div class="card-body p-3">
                <?php foreach ($topBySales as $i => $shop): ?>
                <?php $pct = $maxGMV > 0 ? ($shop['gmv']/$maxGMV*100) : 0; ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="fw-bold text-center flex-shrink-0" style="width:28px;height:28px;background:<?= $i===0?'#ffd700':($i===1?'#c0c0c0':($i===2?'#cd7f32':'#e9ecef')) ?>;border-radius:8px;line-height:28px;font-size:.8rem;">
                        <?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':$i+1)) ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold small truncate"><?= htmlspecialchars($shop['name']) ?></span>
                            <span class="fw-bold text-primary small">Rs. <?= number_format($shop['gmv'],0) ?></span>
                        </div>
                        <div class="progress" style="height:6px;border-radius:10px;">
                            <div class="progress-bar" style="width:<?= $pct ?>%;border-radius:10px;background:linear-gradient(90deg,#6C63FF,#3ECFCF);"></div>
                        </div>
                        <div style="font-size:.7rem;color:var(--text2,#8eb8c4);"><?= $shop['sale_count'] ?> sales · <?= $shop['unique_customers'] ?> customers</div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($topBySales)): ?><div class="text-center text-muted py-3">No sales data</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Top by Inventory -->
<div class="card">
    <div class="card-header"><i class="bi bi-boxes me-2 text-info"></i>Top by Inventory Size</div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>#</th><th>Shop</th><th>Products</th><th>Total Stock</th><th>Stock Value (Rs.)</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($topByProducts as $i => $shop): ?>
                <tr>
                    <td>
                        <?php if ($i === 0) echo '🥇'; elseif ($i === 1) echo '🥈'; elseif ($i === 2) echo '🥉'; else echo $i+1; ?>
                    </td>
                    <td class="fw-semibold"><?= htmlspecialchars($shop['name']) ?></td>
                    <td><span class="badge bg-primary"><?= $shop['product_count'] ?></span></td>
                    <td><?= number_format($shop['total_stock'],0) ?> units</td>
                    <td class="fw-bold text-success">Rs. <?= number_format($shop['stock_value'],0) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/subscriptions.php?shop_id=<?= $shop['id'] ?>" class="btn btn-xs btn-outline-primary" style="padding:.2rem .5rem;font-size:.72rem;">View Sub</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($topByProducts)): ?>
                <tr><td colspan="6" class="text-center py-3 text-muted">No inventory data</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php adminFooter(); ?>

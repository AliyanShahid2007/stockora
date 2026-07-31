<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';
$db = getDB();
$shopId = (int)$_SESSION['shop_id'];
$shopName = $_SESSION['shop_name'] ?? 'My Shop';

$reportDate = $_GET['date'] ?? date('Y-m-d');

// ─── Z-Report Data ───────────────────────────────────────────
// Sales summary
$salesSummary = $db->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        COUNT(DISTINCT CASE WHEN sale_type='retail' THEN id END) as retail_count,
        COUNT(DISTINCT CASE WHEN sale_type='wholesale' THEN id END) as wholesale_count,
        COALESCE(SUM(grand_total),0) as gross_sales,
        COALESCE(SUM(discount),0) as total_discounts,
        COALESCE(SUM(tax),0) as total_tax,
        COALESCE(SUM(grand_total - discount),0) as net_sales,
        COALESCE(SUM(amount_paid),0) as total_collected,
        COALESCE(AVG(grand_total),0) as avg_sale_value
    FROM sales 
    WHERE shop_id = ? AND DATE(sale_date) = ?
");
$salesSummary->execute([$shopId, $reportDate]);
$summary = $salesSummary->fetch();

// Payment methods breakdown
$payMethods = $db->prepare("
    SELECT payment_method, COUNT(*) as count, SUM(grand_total) as amount 
    FROM sales 
    WHERE shop_id = ? AND DATE(sale_date) = ? AND payment_status = 'paid'
    GROUP BY payment_method
");
$payMethods->execute([$shopId, $reportDate]);
$payBreakdown = $payMethods->fetchAll();

// Top items sold today
$topItems = $db->prepare("
    SELECT p.name, SUM(si.quantity) as qty_sold, SUM(si.total_price) as revenue,
           AVG(si.unit_price) as avg_price
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    JOIN products p ON si.product_id = p.id
    WHERE s.shop_id = ? AND DATE(s.sale_date) = ?
    GROUP BY si.product_id
    ORDER BY qty_sold DESC
    LIMIT 10
");
$topItems->execute([$shopId, $reportDate]);
$topItems = $topItems->fetchAll();

// Profit calculation
$profitCalc = $db->prepare("
    SELECT COALESCE(SUM((si.unit_price - COALESCE(p.company_price,0)) * si.quantity),0) as profit
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    JOIN products p ON si.product_id = p.id
    WHERE s.shop_id = ? AND DATE(s.sale_date) = ?
");
$profitCalc->execute([$shopId, $reportDate]);
$profit = $profitCalc->fetchColumn();

// Expenses today
$expensesToday = $db->prepare("
    SELECT COALESCE(SUM(amount),0) as total FROM expenses 
    WHERE shop_id = ? AND expense_date = ?
");
$expensesToday->execute([$shopId, $reportDate]);
$todayExpenses = $expensesToday->fetchColumn();

// Hourly sales
$hourlySales = $db->prepare("
    SELECT HOUR(sale_date) as hour, COUNT(*) as cnt, SUM(grand_total) as total
    FROM sales WHERE shop_id = ? AND DATE(sale_date) = ?
    GROUP BY hour ORDER BY hour
");
$hourlySales->execute([$shopId, $reportDate]);
$hourlyData = $hourlySales->fetchAll();

$netProfit = $profit - $todayExpenses;
$margin = $summary['gross_sales'] > 0 ? round(($profit / $summary['gross_sales']) * 100, 1) : 0;

shopHeader('Z-Report (End of Day)', 'zreport');
?>
<!-- Date Selector -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="page-title mb-1"><i class="bi bi-journal-check me-2 text-primary"></i>Z-Report — End of Day</h1>
        <p class="page-subtitle mb-0">Daily sales summary for <?= date('d M Y', strtotime($reportDate)) ?></p>
    </div>
    <div class="d-flex gap-2 align-items-center no-print">
        <form method="GET" class="d-flex gap-2">
            <input type="date" class="form-control form-control-sm" name="date" value="<?= $reportDate ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
        </form>
        <button onclick="window.print()" class="btn btn-primary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <a href="?date=<?= date('Y-m-d', strtotime($reportDate . ' -1 day')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
        <a href="?date=<?= date('Y-m-d', strtotime($reportDate . ' +1 day')) ?>" class="btn btn-outline-secondary btn-sm" <?= $reportDate >= date('Y-m-d') ? 'disabled' : '' ?>><i class="bi bi-chevron-right"></i></a>
    </div>
</div>

<!-- Print Header -->
<div class="d-none d-print-block text-center mb-4">
    <h3 class="fw-bold"><?= htmlspecialchars($shopName) ?></h3>
    <h4>Z-REPORT — END OF DAY</h4>
    <p><?= date('l, d F Y', strtotime($reportDate)) ?> | Generated: <?= date('d M Y, h:i A') ?></p>
    <hr>
</div>

<!-- Key Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-receipt"></i></div>
            <div class="stat-card-value"><?= $summary['total_transactions'] ?></div>
            <div class="stat-card-label">Transactions</div>
            <div class="stat-card-change"><?= $summary['retail_count'] ?> retail, <?= $summary['wholesale_count'] ?> wholesale</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-cash"></i></div>
            <div class="stat-card-value"><?= formatCurrency($summary['gross_sales']) ?></div>
            <div class="stat-card-label">Gross Sales</div>
            <div class="stat-card-change">Discount: <?= formatCurrency($summary['total_discounts']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-graph-up"></i></div>
            <div class="stat-card-value"><?= formatCurrency($profit) ?></div>
            <div class="stat-card-label">Gross Profit</div>
            <div class="stat-card-change">Margin: <?= $margin ?>%</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card <?= $netProfit >= 0 ? 'stat-success' : 'stat-danger' ?>">
            <div class="stat-card-icon"><i class="bi bi-wallet2"></i></div>
            <div class="stat-card-value"><?= formatCurrency($netProfit) ?></div>
            <div class="stat-card-label">Net Profit</div>
            <div class="stat-card-change">Expenses: <?= formatCurrency($todayExpenses) ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Summary Table -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-bold"><i class="bi bi-list-columns me-2"></i>Sales Summary</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td class="text-muted">Gross Sales</td><td class="fw-bold text-end"><?= formatCurrency($summary['gross_sales']) ?></td></tr>
                        <tr><td class="text-muted">Total Discounts</td><td class="fw-bold text-end text-danger">- <?= formatCurrency($summary['total_discounts']) ?></td></tr>
                        <tr><td class="text-muted">Tax Collected</td><td class="fw-bold text-end"><?= formatCurrency($summary['total_tax']) ?></td></tr>
                        <tr class="table-info"><td class="fw-bold">Net Sales</td><td class="fw-bold text-end text-primary"><?= formatCurrency($summary['net_sales']) ?></td></tr>
                        <tr><td class="text-muted">Total Collected</td><td class="fw-bold text-end text-success"><?= formatCurrency($summary['total_collected']) ?></td></tr>
                        <tr><td class="text-muted">Avg Sale Value</td><td class="fw-bold text-end"><?= formatCurrency($summary['avg_sale_value']) ?></td></tr>
                        <tr><td class="text-muted">Cost of Goods</td><td class="fw-bold text-end text-danger">- <?= formatCurrency($summary['gross_sales'] - $profit) ?></td></tr>
                        <tr class="table-success"><td class="fw-bold">Gross Profit</td><td class="fw-bold text-end text-success"><?= formatCurrency($profit) ?> (<?= $margin ?>%)</td></tr>
                        <tr><td class="text-muted">Expenses</td><td class="fw-bold text-end text-danger">- <?= formatCurrency($todayExpenses) ?></td></tr>
                        <tr class="<?= $netProfit >= 0 ? 'table-success' : 'table-danger' ?>">
                            <td class="fw-bold fs-5">NET PROFIT</td>
                            <td class="fw-bold text-end fs-5"><?= formatCurrency($netProfit) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Payment Methods -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-bold"><i class="bi bi-credit-card me-2"></i>Payment Methods</div>
            <div class="card-body">
                <?php if (empty($payBreakdown)): ?>
                <p class="text-center text-muted py-3">No sales today</p>
                <?php else: ?>
                <?php foreach ($payBreakdown as $pm): ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:36px;height:36px;background:rgba(14,206,206,.12);border-radius:8px;display:flex;align-items:center;justify-content:center;">
                            <i class="bi bi-<?= $pm['payment_method'] === 'cash' ? 'cash' : ($pm['payment_method'] === 'card' ? 'credit-card' : 'phone') ?> text-primary"></i>
                        </div>
                        <div>
                            <div class="fw-semibold text-capitalize"><?= htmlspecialchars($pm['payment_method']) ?></div>
                            <small class="text-muted"><?= $pm['count'] ?> transactions</small>
                        </div>
                    </div>
                    <div class="fw-bold text-success"><?= formatCurrency($pm['amount']) ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Top Items -->
<?php if (!empty($topItems)): ?>
<div class="card mb-4">
    <div class="card-header fw-bold"><i class="bi bi-trophy me-2 text-warning"></i>Top Items Sold</div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>#</th><th>Product</th><th>Qty Sold</th><th>Avg Price</th><th>Revenue</th></tr></thead>
            <tbody>
                <?php foreach ($topItems as $i => $item): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($item['name']) ?></td>
                    <td><span class="badge bg-primary"><?= $item['qty_sold'] ?></span></td>
                    <td><?= formatCurrency($item['avg_price']) ?></td>
                    <td class="fw-bold text-success"><?= formatCurrency($item['revenue']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($summary['total_transactions'] == 0): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <div class="empty-state">
            <div class="empty-state-icon"><i class="bi bi-journal-x"></i></div>
            <h5>No Sales on <?= date('d M Y', strtotime($reportDate)) ?></h5>
            <p class="text-muted">No transactions found for this date.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Print Footer -->
<div class="d-none d-print-block mt-4 text-center">
    <hr>
    <small class="text-muted">*** END OF Z-REPORT *** | <?= APP_NAME ?> | Generated: <?= date('d M Y h:i A') ?></small>
</div>

<style>
@media print {
    /* The global receipt print rule hides the entire document except #printArea.
       Z-Report prints its page content directly, so restore its visibility. */
    body *, .app-wrapper, .main-content, .page-content { visibility: visible !important; }
    .no-print, nav.sidebar, .topbar, .sidebar-overlay { display: none !important; }
    .mobile-bottom-nav { display: none !important; }
    .main-content { margin: 0 !important; }
    .page-content { padding: 0.5rem !important; }
}
</style>

<?php shopFooter(); ?>

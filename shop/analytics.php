<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db     = getDB();
$today  = date('Y-m-d');
$msg    = '';

// Shop creation date for calendar restriction
$_shopDataAn = $db->prepare("SELECT created_at FROM shops WHERE id=?");
$_shopDataAn->execute([$shopId]);
$_shopDataAn = $_shopDataAn->fetch();
$shopCreatedDate = $_shopDataAn ? date('Y-m-d', strtotime($_shopDataAn['created_at'])) : '2020-01-01';

// ── POST Handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // 1. Set daily/monthly target
    if ($act === 'set_target') {
        $type   = in_array($_POST['target_type'] ?? '', ['daily', 'monthly']) ? $_POST['target_type'] : 'monthly';
        $amount = safeFloat($_POST['target_amount'] ?? 0);
        setShopSetting($shopId, $type.'_target', (string)$amount);
        redirect('analytics.php', 'Target saved: Rs. '.number_format($amount));
    }

    // 2. Bulk price update for category
    if ($act === 'bulk_price') {
        $catId  = safeInt($_POST['category_id'] ?? 0);
        $pctAdj = safeFloat($_POST['pct_adjust'] ?? 0);
        $type   = in_array($_POST['price_type'] ?? '', ['retail','wholesale','both']) ? $_POST['price_type'] : 'retail';
        if ($pctAdj != 0 && $catId) {
            $factor = 1 + ($pctAdj / 100);
            if ($type === 'retail' || $type === 'both') {
                $db->prepare("UPDATE products SET retail_price = ROUND(retail_price * ?, 0) WHERE shop_id=? AND category_id=?")->execute([$factor, $shopId, $catId]);
            }
            if ($type === 'wholesale' || $type === 'both') {
                $db->prepare("UPDATE products SET wholesale_price = ROUND(wholesale_price * ?, 0) WHERE shop_id=? AND category_id=?")->execute([$factor, $shopId, $catId]);
            }
            redirect('analytics.php', "Prices updated. Adjustment: ".($pctAdj>0?'+':'').$pctAdj.'%');
        }
        redirect('analytics.php', 'No adjustment made.', 'error');
    }

    // 3. Quick restock
    if ($act === 'quick_restock') {
        $productId = safeInt($_POST['product_id'] ?? 0);
        $qty       = safeInt($_POST['qty'] ?? 0);
        $price     = safeFloat($_POST['unit_price'] ?? 0);
        if ($productId > 0 && $qty > 0) {
            $db->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id=? AND shop_id=?")->execute([$qty, $productId, $shopId]);
            $db->prepare("INSERT INTO stock_movements (shop_id, product_id, movement_type, quantity, notes, created_at) VALUES (?, ?, 'in', ?, 'Quick restock', CURRENT_TIMESTAMP)")->execute([$shopId, $productId, $qty]);
            if ($price > 0) {
                $db->prepare("INSERT INTO purchases (shop_id, product_id, quantity, unit_price, total_amount, purchase_date, notes) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$shopId, $productId, $qty, $price, $qty*$price, $today, 'Quick restock']);
            }
            redirect('analytics.php', "Stock +{$qty} added successfully.");
        }
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
// Targets
$dailyTarget   = (float)(getShopSetting($shopId, 'daily_target', '0') ?: 0);
$monthlyTarget = (float)(getShopSetting($shopId, 'monthly_target', '0') ?: 0);

// Today's & month sales
$stmtT = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as t, COUNT(*) as c FROM sales WHERE shop_id=? AND DATE(sale_date)=?");
$stmtT->execute([$shopId, $today]);
$todayRow = $stmtT->fetch();
$todaySales = (float)$todayRow['t'];
$todayCount = (int)$todayRow['c'];

$stmtM = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as t, COUNT(*) as c FROM sales WHERE shop_id=? AND DATE(sale_date)>=DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$stmtM->execute([$shopId]);
$monthRow = $stmtM->fetch();
$monthlySales  = (float)$monthRow['t'];
$monthlyCount  = (int)$monthRow['c'];

// Today's profit
$stmtTP = $db->prepare("SELECT COALESCE(SUM(si.profit),0) as p FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.shop_id=? AND DATE(s.sale_date)=?");
$stmtTP->execute([$shopId, $today]);
$todayProfit = (float)$stmtTP->fetch()['p'];

// Monthly profit
$stmtMP = $db->prepare("SELECT COALESCE(SUM(si.profit),0) as p FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.shop_id=? AND DATE(s.sale_date)>=DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$stmtMP->execute([$shopId]);
$monthlyProfit = (float)$stmtMP->fetch()['p'];

// Profit margin %
$profitMargin = $monthlySales > 0 ? round(($monthlyProfit / $monthlySales) * 100, 1) : 0;

// Last 7 days chart
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $stmt = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as s, COALESCE(SUM(si.profit),0) as p FROM sales sl LEFT JOIN sale_items si ON si.sale_id=sl.id WHERE sl.shop_id=? AND DATE(sl.sale_date)=?");
    $stmt->execute([$shopId, $d]);
    $r = $stmt->fetch();
    $chartData[] = ['day'=>date('D',strtotime($d)), 'sales'=>(float)$r['s'], 'profit'=>(float)$r['p']];
}

// Top selling products (last 30 days)
$topProducts = $db->prepare("SELECT p.name, p.retail_price, p.company_price, p.stock_quantity, p.min_stock_alert,
    SUM(si.quantity) as qty_sold, SUM(si.total_price) as revenue, SUM(si.profit) as profit
    FROM sale_items si
    JOIN sales s ON s.id=si.sale_id
    JOIN products p ON p.id=si.product_id
    WHERE s.shop_id=? AND DATE(s.sale_date)>=DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY si.product_id ORDER BY qty_sold DESC LIMIT 10");
$topProducts->execute([$shopId]);
$topProducts = $topProducts->fetchAll();

// Low stock products
$lowStock = getLowStockProducts($shopId);

// Categories for bulk price update
$categories = $db->prepare("SELECT * FROM categories WHERE shop_id=? ORDER BY name");
$categories->execute([$shopId]);
$categories = $categories->fetchAll();

// All active products for quick restock
$allProducts = $db->prepare("SELECT id, name, stock_quantity, min_stock_alert FROM products WHERE shop_id=? AND status='active' ORDER BY name");
$allProducts->execute([$shopId]);
$allProducts = $allProducts->fetchAll();

// Z-report (today's summary)
$zReport = $db->prepare("SELECT
    COUNT(DISTINCT s.id) as total_invoices,
    COALESCE(SUM(s.grand_total),0) as gross_sales,
    COALESCE(SUM(s.discount),0) as total_discount,
    COALESCE(SUM(si.profit),0) as gross_profit,
    COALESCE(SUM(CASE WHEN s.payment_method='cash' THEN s.grand_total ELSE 0 END),0) as cash_sales,
    COALESCE(SUM(CASE WHEN s.payment_method='card' THEN s.grand_total ELSE 0 END),0) as card_sales,
    COALESCE(SUM(CASE WHEN s.payment_method='online' THEN s.grand_total ELSE 0 END),0) as online_sales,
    COALESCE(SUM(CASE WHEN s.sale_type='retail' THEN s.grand_total ELSE 0 END),0) as retail_sales,
    COALESCE(SUM(CASE WHEN s.sale_type='wholesale' THEN s.grand_total ELSE 0 END),0) as wholesale_sales,
    COUNT(DISTINCT CASE WHEN s.sale_type='retail' THEN s.id END) as retail_count,
    COUNT(DISTINCT CASE WHEN s.sale_type='wholesale' THEN s.id END) as wholesale_count
    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id=s.id
    WHERE s.shop_id=? AND DATE(s.sale_date)=?");
$zReport->execute([$shopId, $today]);
$zReport = $zReport->fetch();

// Get shop info for Z-report print
$shopInfo = getCurrentShop();

// Check for admin announcement
$announcement = getShopSetting($shopId, 'announcement', '');
$announcement = $announcement ? json_decode($announcement, true) : null;

shopHeader('Analytics & Tools', 'analytics');
?>

<!-- Admin Announcement Banner -->
<?php if ($announcement && !empty($announcement['title'])): ?>
<div class="alert alert-info alert-dismissible fade show mx-0 mb-3 rounded-3">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-megaphone-fill flex-shrink-0 mt-1"></i>
        <div>
            <strong><?= htmlspecialchars($announcement['title']) ?></strong><br>
            <small><?= htmlspecialchars($announcement['body']) ?></small>
            <br><span class="badge bg-light text-muted" style="font-size:0.7rem;"><?= $announcement['sent_at'] ?? '' ?></span>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php flashMessage(); ?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Analytics & Shop Tools</h1>
        <p class="page-subtitle">Sales performance, targets, reports & management tools</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-primary btn-sm" onclick="showZReport()">
            <i class="bi bi-printer me-1"></i>Z-Report
        </button>
        <button class="btn btn-outline-success btn-sm" onclick="exportSalesCSV()">
            <i class="bi bi-download me-1"></i>Export CSV
        </button>
    </div>
</div>

<!-- ═══════════════ FEATURE 1: KPI CARDS with Profit Margin ═══════════════ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-cart-check"></i></div>
            <div class="stat-card-value">Rs.<?= number_format($todaySales,0) ?></div>
            <div class="stat-card-label">Today's Sales</div>
            <div class="stat-card-change"><?= $todayCount ?> transactions</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-graph-up"></i></div>
            <div class="stat-card-value">Rs.<?= number_format($monthlySales,0) ?></div>
            <div class="stat-card-label">Monthly Sales</div>
            <div class="stat-card-change"><?= $monthlyCount ?> transactions</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-cash"></i></div>
            <div class="stat-card-value">Rs.<?= number_format($monthlyProfit,0) ?></div>
            <div class="stat-card-label">Monthly Profit</div>
            <div class="stat-card-change">Today: Rs.<?= number_format($todayProfit,0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-info">
            <div class="stat-card-icon"><i class="bi bi-percent"></i></div>
            <div class="stat-card-value"><?= $profitMargin ?>%</div>
            <div class="stat-card-label">Profit Margin</div>
            <div class="stat-card-change">Last 30 days avg</div>
        </div>
    </div>
</div>

<!-- ═══════════════ FEATURE 2: DAILY / MONTHLY TARGET TRACKER ═══════════════ -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-bullseye me-2 text-danger"></i>Daily Target</span>
                <button class="btn btn-xs btn-outline-primary" onclick="showSetTarget('daily', <?= $dailyTarget ?>)" style="padding:0.18rem 0.5rem;font-size:0.75rem;">
                    <i class="bi bi-pencil"></i> Set
                </button>
            </div>
            <div class="card-body p-3">
                <?php if ($dailyTarget > 0):
                    $dPct = min(100, round(($todaySales / $dailyTarget) * 100));
                    $dColor = $dPct >= 100 ? 'success' : ($dPct >= 60 ? 'warning' : 'danger');
                ?>
                <div class="d-flex justify-content-between mb-2">
                    <span class="fw-bold fs-5 text-<?= $dColor ?>">Rs.<?= number_format($todaySales,0) ?></span>
                    <span class="text-muted">Target: Rs.<?= number_format($dailyTarget,0) ?></span>
                </div>
                <div class="progress mb-2" style="height:12px;border-radius:8px;">
                    <div class="progress-bar bg-<?= $dColor ?>" style="width:<?= $dPct ?>%;transition:width 1s;" aria-valuenow="<?= $dPct ?>"></div>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span><?= $dPct ?>% achieved</span>
                    <span><?= $dPct >= 100 ? '🎉 Target hit!' : 'Need Rs.'.number_format(max(0,$dailyTarget-$todaySales),0).' more' ?></span>
                </div>
                <?php else: ?>
                <div class="text-center py-3">
                    <i class="bi bi-bullseye text-muted" style="font-size:2rem;"></i>
                    <p class="text-muted mt-2 mb-2 small">No daily target set</p>
                    <button class="btn btn-sm btn-outline-primary" onclick="showSetTarget('daily',0)">Set Daily Target</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-month me-2 text-primary"></i>Monthly Target</span>
                <button class="btn btn-xs btn-outline-primary" onclick="showSetTarget('monthly', <?= $monthlyTarget ?>)" style="padding:0.18rem 0.5rem;font-size:0.75rem;">
                    <i class="bi bi-pencil"></i> Set
                </button>
            </div>
            <div class="card-body p-3">
                <?php if ($monthlyTarget > 0):
                    $mPct = min(100, round(($monthlySales / $monthlyTarget) * 100));
                    $mColor = $mPct >= 100 ? 'success' : ($mPct >= 60 ? 'warning' : 'danger');
                ?>
                <div class="d-flex justify-content-between mb-2">
                    <span class="fw-bold fs-5 text-<?= $mColor ?>">Rs.<?= number_format($monthlySales,0) ?></span>
                    <span class="text-muted">Target: Rs.<?= number_format($monthlyTarget,0) ?></span>
                </div>
                <div class="progress mb-2" style="height:12px;border-radius:8px;">
                    <div class="progress-bar bg-<?= $mColor ?>" style="width:<?= $mPct ?>%;transition:width 1s;"></div>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span><?= $mPct ?>% achieved</span>
                    <span><?= $mPct >= 100 ? '🎉 Monthly target hit!' : 'Need Rs.'.number_format(max(0,$monthlyTarget-$monthlySales),0).' more' ?></span>
                </div>
                <?php else: ?>
                <div class="text-center py-3">
                    <i class="bi bi-calendar-month text-muted" style="font-size:2rem;"></i>
                    <p class="text-muted mt-2 mb-2 small">No monthly target set</p>
                    <button class="btn btn-sm btn-outline-primary" onclick="showSetTarget('monthly',0)">Set Monthly Target</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ FEATURE 3: SALES CHART (7 days) ═══════════════ -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Sales &amp; Profit — Last 7 Days</div>
    <div class="card-body p-3"><canvas id="weekChart" height="90"></canvas></div>
</div>

<!-- ═══════════════ FEATURE 4: TOP SELLING PRODUCTS ═══════════════ -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-trophy me-2 text-warning"></i>Top 10 Selling Products — Last 30 Days</span>
        <small class="text-muted">Includes profit calculator</small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>Sold Qty</th>
                    <th>Revenue</th>
                    <th>Profit</th>
                    <th>Margin%</th>
                    <th>Stock Left</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topProducts as $i => $tp):
                    $margin = $tp['revenue'] > 0 ? round(($tp['profit'] / $tp['revenue']) * 100, 1) : 0;
                    $stockLow = $tp['stock_quantity'] <= ($tp['min_stock_alert'] ?? 5);
                ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($tp['name']) ?></td>
                    <td><span class="badge bg-primary"><?= $tp['qty_sold'] ?></span></td>
                    <td class="fw-bold">Rs.<?= number_format($tp['revenue'],0) ?></td>
                    <td class="text-success fw-bold">Rs.<?= number_format($tp['profit'],0) ?></td>
                    <td>
                        <span class="badge <?= $margin >= 20 ? 'bg-success' : ($margin >= 10 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                            <?= $margin ?>%
                        </span>
                    </td>
                    <td>
                        <span class="<?= $stockLow ? 'text-danger fw-bold' : '' ?>">
                            <?= $tp['stock_quantity'] ?>
                            <?php if ($stockLow): ?>
                            <i class="bi bi-exclamation-triangle-fill text-danger ms-1"></i>
                            <?php endif; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($topProducts)): ?>
                <tr><td colspan="7" class="text-center py-3 text-muted">No sales in the last 30 days</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════ FEATURES 5+6: LOW STOCK ALERTS + QUICK RESTOCK ═══════════════ -->
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100" style="border-left:4px solid #ea5455;">
            <div class="card-header" style="background:rgba(239,68,68,.12);">
                <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>
                <strong>Low Stock Alert</strong>
                <span class="badge bg-danger ms-2"><?= count($lowStock) ?></span>
            </div>
            <?php if (empty($lowStock)): ?>
            <div class="card-body text-center py-4">
                <i class="bi bi-check-circle text-success fs-2"></i>
                <p class="text-muted mt-2 mb-0">All products well stocked!</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Product</th><th>Stock</th><th>Min</th><th>Restock</th></tr></thead>
                    <tbody>
                    <?php foreach ($lowStock as $p): ?>
                    <tr class="table-warning">
                        <td class="fw-semibold small"><?= htmlspecialchars($p['name']) ?></td>
                        <td><span class="badge bg-danger"><?= $p['stock_quantity'] ?></span></td>
                        <td><small class="text-muted"><?= $p['min_stock_alert'] ?></small></td>
                        <td>
                            <button onclick="showRestock(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')"
                                    class="btn btn-xs btn-warning" style="padding:0.15rem 0.4rem;font-size:0.72rem;">
                                <i class="bi bi-plus-circle"></i> Restock
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- FEATURE 7: Bulk Price Update -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-tags me-2 text-primary"></i>Bulk Price Update by Category</div>
            <div class="card-body p-3">
                <form method="POST">
                    <input type="hidden" name="action" value="bulk_price">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category *</label>
                        <select class="form-select" name="category_id" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Price Type</label>
                            <select class="form-select" name="price_type">
                                <option value="retail">Retail Only</option>
                                <option value="wholesale">Wholesale Only</option>
                                <option value="both">Both Prices</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Adjustment %</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="pct_adjust" step="0.1" required placeholder="+5 or -10">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 p-2 rounded-2 bg-light small text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Enter <strong>+5</strong> to increase prices by 5%, or <strong>-10</strong> to decrease by 10%.
                        All products in selected category will be updated.
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mt-3" onclick="return confirm('Update ALL prices in this category?')">
                        <i class="bi bi-tags me-1"></i>Update Prices
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ FEATURE 8: DATE RANGE SALES REPORT ═══════════════ -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-calendar-range me-2 text-primary"></i>Sales Report by Date Range</div>
    <div class="card-body p-3">
        <form id="dateRangeForm" class="row g-2 mb-3" onsubmit="loadDateRange(event)">
            <div class="col-5 col-md-3">
                <label class="form-label form-label-sm">From Date</label>
                <input type="date" class="form-control form-control-sm" id="drFrom" value="<?= date('Y-m-01') ?>" min="<?= $shopCreatedDate ?>" max="<?= $today ?>">
            </div>
            <div class="col-5 col-md-3">
                <label class="form-label form-label-sm">To Date</label>
                <input type="date" class="form-control form-control-sm" id="drTo" value="<?= $today ?>" min="<?= $shopCreatedDate ?>" max="<?= $today ?>">
            </div>
            <div class="col-2 col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search me-1 d-none d-md-inline"></i>Search
                </button>
            </div>
            <div class="col-auto d-flex align-items-end gap-1">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setRange('today')">Today</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setRange('week')">7d</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setRange('month')">Month</button>
            </div>
        </form>
        <div id="dateRangeResult">
            <div class="text-center text-muted py-3"><i class="bi bi-search me-1"></i>Click Search to load report</div>
        </div>
    </div>
</div>

<!-- ═══════════════ FEATURE 9: PRODUCT PROFIT CALCULATOR ═══════════════ -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-calculator me-2 text-success"></i>Product Profit Calculator</div>
    <div class="card-body p-3">
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold">Buy Price (Rs.)</label>
                <input type="number" class="form-control" id="calcBuy" min="0" step="1" placeholder="e.g. 100" oninput="calcProfit()">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold">Sell Price (Rs.)</label>
                <input type="number" class="form-control" id="calcSell" min="0" step="1" placeholder="e.g. 140" oninput="calcProfit()">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold">Quantity</label>
                <input type="number" class="form-control" id="calcQty" min="1" step="1" value="1" oninput="calcProfit()">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">&nbsp;</label>
                <div class="p-2 rounded-3" style="background:rgba(108,99,255,.12);border:1px solid rgba(167,139,250,.2);" id="profitResult">
                    <div class="small text-muted">Profit per unit: <strong id="profitPerUnit">—</strong></div>
                    <div class="small text-muted">Total profit: <strong id="totalProfitCalc" class="text-success">—</strong></div>
                    <div class="small text-muted">Margin: <strong id="marginCalc" class="text-primary">—</strong></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ FEATURE 10: PAYMENT METHOD BREAKDOWN ═══════════════ -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <?php
        $payMethods = $db->prepare("SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(grand_total),0) as amt FROM sales WHERE shop_id=? AND DATE(sale_date)>=DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY payment_method");
        $payMethods->execute([$shopId]);
        $payMethods = $payMethods->fetchAll();
        $totalPayAmt = array_sum(array_column($payMethods,'amt')) ?: 1;
        ?>
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-2 text-primary"></i>Payment Methods — Last 30 Days</div>
            <div class="card-body p-3">
                <?php if (empty($payMethods)): ?>
                <div class="text-center text-muted py-3">No sales data</div>
                <?php else: ?>
                <?php foreach ($payMethods as $pm):
                    $pct = round(($pm['amt'] / $totalPayAmt) * 100);
                    $icons = ['cash'=>'💵','card'=>'💳','online'=>'📱','' => '💰'];
                    $icon = $icons[$pm['payment_method']] ?? '💰';
                ?>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span style="font-size:1.2rem;"><?= $icon ?></span>
                    <div style="flex:1;">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-semibold"><?= ucfirst($pm['payment_method'] ?: 'Cash') ?></span>
                            <span class="fw-bold">Rs.<?= number_format($pm['amt'],0) ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="progress" style="height:6px;border-radius:4px;">
                            <div class="progress-bar" style="width:<?= $pct ?>%;background:linear-gradient(90deg,#6C63FF,#3ECFCF);"></div>
                        </div>
                    </div>
                    <span class="text-muted small"><?= $pm['cnt'] ?> sales</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <?php
        $custTypes = $db->prepare("SELECT sale_type as customer_type, COUNT(*) as cnt, COALESCE(SUM(grand_total),0) as amt FROM sales WHERE shop_id=? AND DATE(sale_date)>=DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY sale_type");
        $custTypes->execute([$shopId]);
        $custTypes = $custTypes->fetchAll();
        $totalCustAmt = array_sum(array_column($custTypes,'amt')) ?: 1;
        ?>
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-people me-2 text-success"></i>Retail vs Wholesale — Last 30 Days</div>
            <div class="card-body p-3">
                <?php if (empty($custTypes)): ?>
                <div class="text-center text-muted py-3">No sales data</div>
                <?php else: ?>
                <?php foreach ($custTypes as $ct):
                    $pct = round(($ct['amt'] / $totalCustAmt) * 100);
                    $color = $ct['customer_type'] === 'wholesale' ? '#3ECFCF' : '#6C63FF';
                ?>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div style="width:12px;height:12px;border-radius:3px;background:<?= $color ?>;flex-shrink:0;"></div>
                    <div style="flex:1;">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-semibold"><?= ucfirst($ct['customer_type'] ?: 'Retail') ?></span>
                            <span class="fw-bold">Rs.<?= number_format($ct['amt'],0) ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="progress" style="height:6px;border-radius:4px;">
                            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
                        </div>
                    </div>
                    <span class="text-muted small"><?= $ct['cnt'] ?> sales</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     MODALS
══════════════════════════════════════════ -->

<!-- Set Target Modal -->
<div class="modal fade" id="setTargetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bullseye me-2 text-primary"></i>Set Sales Target</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="set_target">
                <input type="hidden" name="target_type" id="targetTypeInput">
                <div class="modal-body">
                    <p class="text-muted small" id="targetTypeLabel"></p>
                    <div class="input-group">
                        <span class="input-group-text fw-bold">Rs.</span>
                        <input type="number" class="form-control" name="target_amount" id="targetAmountInput" required min="0" step="100" placeholder="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check me-1"></i>Save Target</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Restock Modal -->
<div class="modal fade" id="restockModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2 text-success"></i>Quick Restock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="quick_restock">
                <input type="hidden" name="product_id" id="restockProductId">
                <div class="modal-body">
                    <p class="fw-semibold mb-3" id="restockProductName"></p>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Quantity to Add *</label>
                            <input type="number" class="form-control" name="qty" required min="1" placeholder="e.g. 50">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Unit Cost (Rs.)</label>
                            <input type="number" class="form-control" name="unit_price" min="0" step="1" placeholder="Optional">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-plus me-1"></i>Add Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Z-Report Modal -->
<div class="modal fade" id="zReportModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-printer me-2"></i>Z-Report — <?= date('d M Y') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="zReportContent" style="font-family:monospace;padding:1.5rem;background:rgba(255,255,255,.03);color:#e0d8ff;border-radius:8px;border:1px solid rgba(14,206,206,.1);">
                    <div style="text-align:center;margin-bottom:1rem;">
                        <strong style="font-size:1.1rem;"><?= htmlspecialchars($shopInfo['name'] ?? '') ?></strong><br>
                        <small><?= htmlspecialchars($shopInfo['address'] ?? '') ?></small><br>
                        <small><?= htmlspecialchars($shopInfo['phone'] ?? '') ?></small>
                        <hr style="border-style:dashed;">
                        <strong>END OF DAY Z-REPORT</strong><br>
                        <small><?= date('d/m/Y H:i:s') ?></small>
                        <hr style="border-style:dashed;">
                    </div>
                    <table style="width:100%;font-size:0.9rem;">
                        <tr><td>Total Invoices</td><td style="text-align:right;font-weight:bold;"><?= $zReport['total_invoices'] ?></td></tr>
                        <tr><td>Gross Sales</td><td style="text-align:right;font-weight:bold;">Rs.<?= number_format($zReport['gross_sales'],0) ?></td></tr>
                        <tr><td>Total Discount</td><td style="text-align:right;color:#dc3545;">-Rs.<?= number_format($zReport['total_discount'],0) ?></td></tr>
                        <tr><td>Net Sales</td><td style="text-align:right;font-weight:bold;color:#28c76f;">Rs.<?= number_format($zReport['gross_sales']-$zReport['total_discount'],0) ?></td></tr>
                        <tr><td>Gross Profit</td><td style="text-align:right;font-weight:bold;color:#6C63FF;">Rs.<?= number_format($zReport['gross_profit'],0) ?></td></tr>
                        <tr><td colspan="2"><hr style="border-style:dashed;margin:6px 0;"></td></tr>
                        <tr><td>💵 Cash Sales</td><td style="text-align:right;">Rs.<?= number_format($zReport['cash_sales'],0) ?></td></tr>
                        <tr><td>💳 Card Sales</td><td style="text-align:right;">Rs.<?= number_format($zReport['card_sales'],0) ?></td></tr>
                        <tr><td>📱 Online Sales</td><td style="text-align:right;">Rs.<?= number_format($zReport['online_sales'],0) ?></td></tr>
                        <tr><td colspan="2"><hr style="border-style:dashed;margin:6px 0;"></td></tr>
                        <tr><td>Retail (<?= $zReport['retail_count'] ?> invoices)</td><td style="text-align:right;">Rs.<?= number_format($zReport['retail_sales'],0) ?></td></tr>
                        <tr><td>Wholesale (<?= $zReport['wholesale_count'] ?> invoices)</td><td style="text-align:right;">Rs.<?= number_format($zReport['wholesale_sales'],0) ?></td></tr>
                    </table>
                    <hr style="border-style:dashed;margin-top:1rem;">
                    <div style="text-align:center;font-size:0.8rem;color:var(--text2,#8eb8c4);">
                        *** End of Report ***<br>Generated: <?= date('d M Y H:i') ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printZReport()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Chart
new Chart(document.getElementById('weekChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($chartData,'day')) ?>,
        datasets: [
            {
                label: 'Sales',
                data: <?= json_encode(array_column($chartData,'sales')) ?>,
                backgroundColor: 'rgba(108,99,255,0.7)',
                borderRadius: 6
            },
            {
                label: 'Profit',
                data: <?= json_encode(array_column($chartData,'profit')) ?>,
                backgroundColor: 'rgba(40,199,111,0.7)',
                borderRadius: 6
            }
        ]
    },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top', labels: { color: '#b8aee8' } } },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => 'Rs.'+v.toLocaleString('en-PK'), color: '#b8aee8' }, grid: { color: 'rgba(167,139,250,0.1)' } },
                x: { ticks: { color: '#b8aee8' }, grid: { display: false } }
            }
        }
});

// ── Target modals
function showSetTarget(type, current) {
    document.getElementById('targetTypeInput').value = type;
    document.getElementById('targetTypeLabel').textContent = 'Setting ' + type + ' target:';
    document.getElementById('targetAmountInput').value = current || '';
    new bootstrap.Modal(document.getElementById('setTargetModal')).show();
}

// ── Quick Restock
function showRestock(productId, productName) {
    document.getElementById('restockProductId').value = productId;
    document.getElementById('restockProductName').textContent = productName;
    new bootstrap.Modal(document.getElementById('restockModal')).show();
}

// ── Z-Report
function showZReport() {
    new bootstrap.Modal(document.getElementById('zReportModal')).show();
}
function printZReport() {
    const w = window.open('', '_blank', 'width=400,height=600');
    w.document.write(`<html><head><title>Z-Report</title>
    <style>body{font-family:monospace;padding:20px;}</style></head>
    <body>${document.getElementById('zReportContent').innerHTML}
    <script>window.onload=()=>window.print();<\/script></body></html>`);
    w.document.close();
}

// ── Profit Calculator
function calcProfit() {
    const buy  = parseFloat(document.getElementById('calcBuy').value)  || 0;
    const sell = parseFloat(document.getElementById('calcSell').value) || 0;
    const qty  = parseInt(document.getElementById('calcQty').value)    || 1;
    const perUnit = sell - buy;
    const total = perUnit * qty;
    const margin = sell > 0 ? ((perUnit / sell) * 100).toFixed(1) : 0;
    document.getElementById('profitPerUnit').textContent   = perUnit > 0 ? 'Rs.' + perUnit.toLocaleString('en-PK') : '—';
    document.getElementById('totalProfitCalc').textContent = total > 0 ? 'Rs.' + total.toLocaleString('en-PK') : '—';
    document.getElementById('marginCalc').textContent      = sell > 0 ? margin + '%' : '—';
}

// ── Date Range Report
async function loadDateRange(e) {
    e.preventDefault();
    const from = document.getElementById('drFrom').value;
    const to   = document.getElementById('drTo').value;
    if (!from || !to) return;

    document.getElementById('dateRangeResult').innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Loading...</div>';

    try {
        const resp = await fetch(`<?= BASE_URL ?>/api/shop_report.php?from=${from}&to=${to}&shop_id=<?= $shopId ?>`);
        const data = await resp.json();
        if (data.error) throw new Error(data.error);

        let html = `
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="p-2 rounded-2 text-center" style="background:rgba(108,99,255,.15);border:1px solid rgba(108,99,255,.25);">
                    <div class="fw-bold" style="color:#C4B5FD;">Rs.${formatCurrency(data.total_sales).replace('Rs. ','')}</div>
                    <div class="small text-muted">Total Sales</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2 rounded-2 text-center" style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.2);">
                    <div class="fw-bold text-success">Rs.${formatCurrency(data.total_profit).replace('Rs. ','')}</div>
                    <div class="small text-muted">Total Profit</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2 rounded-2 text-center" style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.2);">
                    <div class="fw-bold text-warning">${data.total_invoices}</div>
                    <div class="small text-muted">Invoices</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2 rounded-2 text-center" style="background:rgba(6,182,212,.12);border:1px solid rgba(6,182,212,.2);">
                    <div class="fw-bold text-info">${data.margin}%</div>
                    <div class="small text-muted">Avg Margin</div>
                </div>
            </div>
        </div>`;

        if (data.daily && data.daily.length > 0) {
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date</th><th>Sales</th><th>Profit</th><th>Invoices</th></tr></thead><tbody>';
            data.daily.forEach(d => {
                html += `<tr>
                    <td>${d.date}</td>
                    <td class="fw-bold">Rs.${parseInt(d.sales).toLocaleString('en-PK')}</td>
                    <td class="text-success">Rs.${parseInt(d.profit).toLocaleString('en-PK')}</td>
                    <td>${d.cnt}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        }

        document.getElementById('dateRangeResult').innerHTML = html;
    } catch (err) {
        document.getElementById('dateRangeResult').innerHTML = '<div class="alert alert-danger py-2 small">Error loading report. Please try again.</div>';
    }
}

function setRange(type) {
    const today = new Date().toISOString().slice(0,10);
    let from = today;
    if (type === 'week') {
        const d = new Date(); d.setDate(d.getDate() - 6);
        from = d.toISOString().slice(0,10);
    } else if (type === 'month') {
        from = today.slice(0,7) + '-01';
    }
    document.getElementById('drFrom').value = from;
    document.getElementById('drTo').value = today;
    document.getElementById('dateRangeForm').dispatchEvent(new Event('submit'));
}

// ── Export CSV
function exportSalesCSV() {
    window.location = '<?= BASE_URL ?>/shop/export.php?type=sales&from=<?= date('Y-m-01') ?>&to=<?= $today ?>';
}
</script>
<?php shopFooter(); ?>

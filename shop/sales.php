<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();

// Get shop creation date for calendar min restriction
$shopRow = $db->prepare("SELECT created_at FROM shops WHERE id=? LIMIT 1");
$shopRow->execute([$shopId]);
$shopData = $shopRow->fetch();
$shopCreatedDate = $shopData ? date('Y-m-d', strtotime($shopData['created_at'])) : '2020-01-01';
$todayDate = date('Y-m-d');

// Filters
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? $todayDate;

// Clamp dates: no future dates, no dates before shop creation
if ($dateFrom > $todayDate) $dateFrom = $todayDate;
if ($dateTo   > $todayDate) $dateTo   = $todayDate;
if ($dateFrom < $shopCreatedDate) $dateFrom = $shopCreatedDate;
if ($dateTo   < $shopCreatedDate) $dateTo   = $shopCreatedDate;
$typeFilter = $_GET['type'] ?? 'all';
$page = max(1, safeInt($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = "WHERE s.shop_id=? AND DATE(s.sale_date) BETWEEN ? AND ?";
$params = [$shopId, $dateFrom, $dateTo];
if ($typeFilter !== 'all') { $where .= " AND s.sale_type=?"; $params[] = $typeFilter; }

$countStmt = $db->prepare("SELECT COUNT(*) FROM sales s $where");
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

$stmt = $db->prepare("SELECT s.*, COUNT(si.id) as item_count, COALESCE(SUM(si.profit),0) as total_profit FROM sales s LEFT JOIN sale_items si ON si.sale_id=s.id $where GROUP BY s.id ORDER BY s.sale_date DESC LIMIT ? OFFSET ?");
$stmt->execute([...$params, $perPage, $offset]);
$sales = $stmt->fetchAll();

// Summary stats for filters
$statsStmt = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as total, COUNT(*) as count FROM sales s $where");
$statsStmt->execute($params);
$summary = $statsStmt->fetch();

$profitStmt = $db->prepare("SELECT COALESCE(SUM(si.profit),0) as profit FROM sale_items si JOIN sales s ON s.id=si.sale_id $where");
$profitStmt->execute($params);
$totalProfit = $profitStmt->fetch()['profit'];

shopHeader('Sales History', 'sales');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-receipt me-2 text-primary"></i>Sales History</h1>
        <p class="page-subtitle"><?= number_format($totalRecords) ?> sales records</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/shop/export.php?type=sales&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-download me-1"></i>Export
        </a>
        <a href="<?= BASE_URL ?>/shop/pos.php" class="btn btn-primary btn-sm">
            <i class="bi bi-cart3 me-1"></i>New Sale
        </a>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-cart-check"></i></div>
            <div class="stat-card-value"><?= $summary['count'] ?></div>
            <div class="stat-card-label">Transactions</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-cash"></i></div>
            <div class="stat-card-value"><?= number_format($summary['total']/1000, 0) ?>K</div>
            <div class="stat-card-label">Total Sales</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-graph-up"></i></div>
            <div class="stat-card-value"><?= number_format($totalProfit/1000, 0) ?>K</div>
            <div class="stat-card-label">Total Profit</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-info">
            <div class="stat-card-icon"><i class="bi bi-calculator"></i></div>
            <div class="stat-card-value"><?= $summary['count'] > 0 ? formatCurrency($summary['total']/$summary['count']) : 'Rs. 0' ?></div>
            <div class="stat-card-label">Avg Sale Value</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body p-3">
        <form method="GET" class="row g-2">
            <div class="col-6 col-md-3">
                <label class="form-label fw-medium small">From Date</label>
                <input type="date" class="form-control" name="from"
                    value="<?= $dateFrom ?>"
                    min="<?= $shopCreatedDate ?>"
                    max="<?= $todayDate ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-medium small">To Date</label>
                <input type="date" class="form-control" name="to"
                    value="<?= $dateTo ?>"
                    min="<?= $shopCreatedDate ?>"
                    max="<?= $todayDate ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-medium small">Type</label>
                <select class="form-select" name="type">
                    <option value="all" <?= $typeFilter==='all'?'selected':'' ?>>All</option>
                    <option value="retail" <?= $typeFilter==='retail'?'selected':'' ?>>Retail</option>
                    <option value="wholesale" <?= $typeFilter==='wholesale'?'selected':'' ?>>Wholesale</option>
                </select>
            </div>
            <div class="col-auto d-flex align-items-end gap-1">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= BASE_URL ?>/shop/sales.php" class="btn btn-outline-secondary">Clear</a>
            </div>
            <!-- Quick Date Buttons -->
            <div class="col-12">
                <div class="d-flex gap-1 flex-wrap">
                    <?php
                    $q_today = $todayDate;
                    $q_yest  = date('Y-m-d', strtotime('yesterday'));
                    if ($q_yest < $shopCreatedDate) $q_yest = $shopCreatedDate;
                    $q_mStart = max(date('Y-m-01'), $shopCreatedDate);
                    $q_lmS   = max(date('Y-m-01', strtotime('first day of last month')), $shopCreatedDate);
                    $q_lmE   = min(date('Y-m-t', strtotime('first day of last month')), $todayDate);
                    $q_yrS   = max(date('Y-01-01'), $shopCreatedDate);
                    ?>
                    <a href="<?= BASE_URL ?>?from=<?= $q_today ?>&to=<?= $q_today ?>" class="btn btn-xs btn-outline-secondary" style="font-size:0.75rem;padding:0.2rem 0.6rem;">Today</a>
                    <?php if ($q_yest >= $shopCreatedDate): ?>
                    <a href="<?= BASE_URL ?>?from=<?= $q_yest ?>&to=<?= $q_yest ?>" class="btn btn-xs btn-outline-secondary" style="font-size:0.75rem;padding:0.2rem 0.6rem;">Yesterday</a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>?from=<?= $q_mStart ?>&to=<?= $q_today ?>" class="btn btn-xs btn-outline-secondary" style="font-size:0.75rem;padding:0.2rem 0.6rem;">This Month</a>
                    <?php if ($q_lmS <= $q_lmE): ?>
                    <a href="<?= BASE_URL ?>?from=<?= $q_lmS ?>&to=<?= $q_lmE ?>" class="btn btn-xs btn-outline-secondary" style="font-size:0.75rem;padding:0.2rem 0.6rem;">Last Month</a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>?from=<?= $q_yrS ?>&to=<?= $q_today ?>" class="btn btn-xs btn-outline-secondary" style="font-size:0.75rem;padding:0.2rem 0.6rem;">This Year</a>
                    <a href="<?= BASE_URL ?>?from=<?= $shopCreatedDate ?>&to=<?= $q_today ?>" class="btn btn-xs btn-outline-secondary" style="font-size:0.75rem;padding:0.2rem 0.6rem;"><i class="bi bi-shop me-1"></i>All Time</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Sales Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>#</th><th>Invoice</th><th>Type</th><th>Customer</th><th>Items</th><th>Total</th><th>Profit</th><th>Payment</th><th>Date/Time</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($sales as $i => $sale): ?>
                <tr>
                    <td><?= $offset + $i + 1 ?></td>
                    <td class="fw-semibold text-primary"><?= htmlspecialchars($sale['invoice_no']) ?></td>
                    <td><span class="badge <?= $sale['sale_type']==='retail'?'bg-info':'bg-warning text-dark' ?>"><?= ucfirst($sale['sale_type']) ?></span></td>
                    <td><small><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in') ?></small></td>
                    <td><span class="badge" style="background:rgba(255,255,255,.1);color:#e0d8ff;"><?= $sale['item_count'] ?></span></td>
                    <td class="fw-bold"><?= formatCurrency($sale['grand_total']) ?></td>
                    <td class="fw-bold text-success"><?= formatCurrency($sale['total_profit']) ?></td>
                    <td>
                        <span class="badge <?= $sale['payment_status']==='paid'?'status-active':($sale['payment_status']==='partial'?'status-pending':'status-inactive') ?>">
                            <?= ucfirst($sale['payment_status']) ?>
                        </span>
                        <small class="text-muted d-block"><?= ucfirst($sale['payment_method']) ?></small>
                    </td>
                    <td><small><?php $dt=new DateTime($sale['sale_date'],new DateTimeZone('UTC')); $dt->setTimezone(new DateTimeZone('Asia/Karachi')); echo $dt->format('d M Y, h:i A'); ?></small></td>
                    <td>
                        <a href="<?= BASE_URL ?>/shop/invoice.php?id=<?= $sale['id'] ?>" class="btn btn-xs btn-outline-primary" style="padding:0.2rem 0.5rem;font-size:0.75rem;" target="_blank">
                            <i class="bi bi-printer"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sales)): ?>
                <tr><td colspan="10" class="text-center py-5">
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="bi bi-receipt"></i></div>
                        <h5>No Sales Found</h5>
                        <p class="text-muted">No sales match your filter criteria</p>
                    </div>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="card-body pt-0">
        <nav>
            <ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap gap-1">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item <?= $p==$page?'active':'' ?>">
                    <a class="page-link" href="<?= BASE_URL ?>?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&type=<?= $typeFilter ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php shopFooter(); ?>

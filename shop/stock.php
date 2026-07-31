<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
$filter = $_GET['filter'] ?? 'all';
$search = sanitize($_GET['search'] ?? '');
$catFilter = safeInt($_GET['cat'] ?? 0);

$q = "SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.shop_id=? AND p.status='active'";
$params = [$shopId];

if ($filter === 'low') { $q .= " AND p.stock_quantity > 0 AND p.stock_quantity <= p.min_stock_alert"; }
elseif ($filter === 'out') { $q .= " AND p.stock_quantity <= 0"; }
elseif ($filter === 'ok') { $q .= " AND p.stock_quantity > p.min_stock_alert"; }

if ($catFilter) { $q .= " AND p.category_id=?"; $params[] = $catFilter; }
if ($search) { $q .= " AND p.name LIKE ?"; $params[] = "%{$search}%"; }
$q .= " ORDER BY p.stock_quantity ASC, p.name";

$stmt = $db->prepare($q);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Stats
$allProds = $db->prepare("SELECT COUNT(*) as all_c, SUM(CASE WHEN stock_quantity<=0 THEN 1 ELSE 0 END) as out_c, SUM(CASE WHEN stock_quantity>0 AND stock_quantity<=min_stock_alert THEN 1 ELSE 0 END) as low_c, SUM(CASE WHEN stock_quantity>min_stock_alert THEN 1 ELSE 0 END) as ok_c, SUM(retail_price*stock_quantity) as total_val FROM products WHERE shop_id=? AND status='active'");
$allProds->execute([$shopId]);
$stockStats = $allProds->fetch();

$categories = $db->prepare("SELECT * FROM categories WHERE shop_id=? ORDER BY name");
$categories->execute([$shopId]);
$categories = $categories->fetchAll();

// Recent movements
$movements = $db->prepare("SELECT sm.*, p.name as product_name FROM stock_movements sm JOIN products p ON p.id=sm.product_id WHERE sm.shop_id=? ORDER BY sm.created_at DESC LIMIT 15");
$movements->execute([$shopId]);
$movements = $movements->fetchAll();

shopHeader('Stock Report', 'stock');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <h1 class="page-title"><i class="bi bi-clipboard-data me-2 text-primary"></i>Stock Report</h1>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/shop/export.php?type=stock&download=1" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Export</a>
        <a href="<?= BASE_URL ?>/shop/purchases.php" class="btn btn-primary btn-sm"><i class="bi bi-plus me-1"></i>Add Stock</a>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <a href="?filter=all" class="text-decoration-none">
            <div class="stat-card stat-primary">
                <div class="stat-card-icon"><i class="bi bi-box-seam"></i></div>
                <div class="stat-card-value"><?= $stockStats['all_c'] ?></div>
                <div class="stat-card-label">Total Products</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?filter=ok" class="text-decoration-none">
            <div class="stat-card stat-success">
                <div class="stat-card-icon"><i class="bi bi-check-circle"></i></div>
                <div class="stat-card-value"><?= $stockStats['ok_c'] ?></div>
                <div class="stat-card-label">Well Stocked</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?filter=low" class="text-decoration-none">
            <div class="stat-card stat-warning">
                <div class="stat-card-icon"><i class="bi bi-exclamation-triangle"></i></div>
                <div class="stat-card-value"><?= $stockStats['low_c'] ?></div>
                <div class="stat-card-label">Low Stock</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?filter=out" class="text-decoration-none">
            <div class="stat-card stat-danger">
                <div class="stat-card-icon"><i class="bi bi-x-circle"></i></div>
                <div class="stat-card-value"><?= $stockStats['out_c'] ?></div>
                <div class="stat-card-label">Out of Stock</div>
            </div>
        </a>
    </div>
</div>

<div class="card mb-2 p-3 d-flex flex-row align-items-center gap-2" style="border-left:4px solid var(--primary);">
    <i class="bi bi-currency-rupee text-primary fs-5"></i>
    <span>Total Inventory Value: <strong class="text-primary"><?= formatCurrency($stockStats['total_val'] ?? 0) ?></strong></span>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body p-3">
        <form method="GET" class="row g-2">
            <div class="col-12 col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search products...">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select" name="filter">
                    <option value="all" <?= $filter==='all'?'selected':'' ?>>All Stock</option>
                    <option value="low" <?= $filter==='low'?'selected':'' ?>>Low Stock</option>
                    <option value="out" <?= $filter==='out'?'selected':'' ?>>Out of Stock</option>
                    <option value="ok" <?= $filter==='ok'?'selected':'' ?>>Well Stocked</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select" name="cat">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $catFilter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="stock.php" class="btn btn-outline-secondary ms-1">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list me-2"></i>Products (<?= count($products) ?>)</span>
                <span class="badge <?= $filter==='low'?'bg-warning text-dark':($filter==='out'?'bg-danger':'bg-primary') ?>"><?= ucfirst($filter) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Product</th><th>Category</th><th>Stock</th><th>Min Alert</th><th>Unit</th><th>Value</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                        <tr class="<?= $p['stock_quantity'] <= 0 ? 'table-danger' : ($p['stock_quantity'] <= $p['min_stock_alert'] ? 'table-warning' : '') ?>">
                            <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?></td>
                            <td><span class="badge" style="background:rgba(255,255,255,.1);color:#e0d8ff;"><?= htmlspecialchars($p['cat_name'] ?? '-') ?></span></td>
                            <td>
                                <span class="fw-bold <?= $p['stock_quantity']<=0?'text-danger':($p['stock_quantity']<=$p['min_stock_alert']?'text-warning':'text-success') ?>">
                                    <?= $p['stock_quantity'] ?>
                                </span>
                            </td>
                            <td><?= $p['min_stock_alert'] ?></td>
                            <td><?= htmlspecialchars($p['unit']) ?></td>
                            <td><?= formatCurrency($p['retail_price'] * $p['stock_quantity']) ?></td>
                            <td><a href="<?= BASE_URL ?>/shop/purchases.php?product_id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-success" style="padding:0.2rem 0.5rem;font-size:0.75rem;" title="Add Stock"><i class="bi bi-plus-circle"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($products)): ?><tr><td colspan="7" class="text-center py-4 text-muted">No products match filter</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Movements -->
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-activity me-2 text-primary"></i>Recent Movements</div>
            <div class="list-group list-group-flush">
                <?php foreach ($movements as $m): ?>
                <div class="list-group-item py-2 px-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div style="flex:1;min-width:0;">
                            <div class="fw-semibold small truncate"><?= htmlspecialchars($m['product_name']) ?></div>
                            <small class="text-muted"><?= date('d M, h:i A', strtotime($m['created_at'])) ?></small>
                        </div>
                        <span class="badge ms-2 <?= $m['movement_type']==='sale'?'bg-danger':($m['movement_type']==='purchase'?'bg-success':'bg-warning text-dark') ?>">
                            <?= $m['movement_type']==='sale'?'-':'+' ?><?= abs($m['quantity']) ?>
                        </span>
                    </div>
                    <?php if ($m['after_quantity'] !== null): ?>
                    <div class="small text-muted">After: <?= $m['after_quantity'] ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($movements)): ?><div class="list-group-item text-center py-4 text-muted">No stock movements yet</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php shopFooter(); ?>

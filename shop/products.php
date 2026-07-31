<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $id = safeInt($_POST['product_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $catId = safeInt($_POST['category_id'] ?? 0) ?: null;
        $barcode = sanitize($_POST['barcode'] ?? '');
        $sku = sanitize($_POST['sku'] ?? '');
        $companyPrice = safeFloat($_POST['company_price'] ?? 0);
        $retailPrice = safeFloat($_POST['retail_price'] ?? 0);
        $wholesalePrice = safeFloat($_POST['wholesale_price'] ?? 0);
        $stockQty = safeInt($_POST['stock_quantity'] ?? 0);
        $minAlert = safeInt($_POST['min_stock_alert'] ?? 5);
        $unit = sanitize($_POST['unit'] ?? 'pcs');
        $desc = sanitize($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (!$name) {
            redirect('products.php', 'Product name is required', 'error');
        }
        $image = null;
        if (!empty($_FILES['image']['name'])) {
            $image = uploadLogo($_FILES['image'], 'product');
        }
        
        if ($action === 'create') {
            $stmt = $db->prepare("INSERT INTO products (shop_id, category_id, name, barcode, sku, company_price, retail_price, wholesale_price, stock_quantity, min_stock_alert, unit, description, image, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$shopId, $catId, $name, $barcode, $sku, $companyPrice, $retailPrice, $wholesalePrice, $stockQty, $minAlert, $unit, $desc, $image, $status]);
            
            $newId = $db->lastInsertId();
            if ($stockQty > 0) {
                $db->prepare("INSERT INTO stock_movements (shop_id, product_id, movement_type, quantity, after_quantity, notes) VALUES (?,?,'purchase',?,?,'Initial stock')")->execute([$shopId, $newId, $stockQty, $stockQty]);
            }
            redirect('products.php', "Product '{$name}' created!");
        } else {
            $params = [$catId, $name, $barcode, $sku, $companyPrice, $retailPrice, $wholesalePrice, $stockQty, $minAlert, $unit, $desc, $status];
            if ($image) {
                $db->prepare("UPDATE products SET category_id=?,name=?,barcode=?,sku=?,company_price=?,retail_price=?,wholesale_price=?,stock_quantity=?,min_stock_alert=?,unit=?,description=?,status=?,image=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND shop_id=?")->execute([...$params, $image, $id, $shopId]);
            } else {
                $db->prepare("UPDATE products SET category_id=?,name=?,barcode=?,sku=?,company_price=?,retail_price=?,wholesale_price=?,stock_quantity=?,min_stock_alert=?,unit=?,description=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND shop_id=?")->execute([...$params, $id, $shopId]);
            }
            redirect('products.php', 'Product updated!');
        }
    }
    
    if ($action === 'delete') {
        $id = safeInt($_POST['product_id'] ?? 0);
        $db->prepare("UPDATE products SET status='inactive' WHERE id=? AND shop_id=?")->execute([$id, $shopId]);
        redirect('products.php', 'Product archived.');
    }
}

$search = sanitize($_GET['search'] ?? '');
$catFilter = safeInt($_GET['cat'] ?? 0);
$statusFilter = $_GET['status'] ?? 'active';
$perPage = 20;
$page = max(1, safeInt($_GET['page'] ?? 1));

$where = " WHERE p.shop_id=?";
$params = [$shopId];
if ($statusFilter !== 'all') { $where .= " AND p.status=?"; $params[] = $statusFilter; }
if ($catFilter) { $where .= " AND p.category_id=?"; $params[] = $catFilter; }
if ($search) { $where .= " AND p.name LIKE ?"; $params[] = "%{$search}%"; }

$countStmt = $db->prepare("SELECT COUNT(*) FROM products p" . $where);
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$summaryStmt = $db->prepare("SELECT
    COALESCE(SUM(p.retail_price * p.stock_quantity), 0) AS total_value,
    COALESCE(SUM(p.stock_quantity <= p.min_stock_alert AND p.stock_quantity > 0), 0) AS low_stock,
    COALESCE(SUM(p.stock_quantity <= 0), 0) AS out_of_stock FROM products p" . $where);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalValue = (float)($summary['total_value'] ?? 0);
$lowStockCount = (int)($summary['low_stock'] ?? 0);
$outOfStockCount = (int)($summary['out_of_stock'] ?? 0);

$q = "SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id"
    . $where . " ORDER BY p.name LIMIT {$perPage} OFFSET {$offset}";

$stmt = $db->prepare($q);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $db->prepare("SELECT * FROM categories WHERE shop_id=? AND status='active' ORDER BY name")->execute([$shopId]) ? [] : [];
$stmt = $db->prepare("SELECT * FROM categories WHERE shop_id=? AND status='active' ORDER BY name");
$stmt->execute([$shopId]);
$categories = $stmt->fetchAll();

$paginationParams = array_filter([
    'search' => $search ?: null,
    'cat' => $catFilter ?: null,
    'status' => $statusFilter !== 'active' ? $statusFilter : null,
]);
$productPageUrl = static function (int $targetPage) use ($paginationParams): string {
    return BASE_URL . '/shop/products.php?' . http_build_query($paginationParams + ['page' => $targetPage]);
};

shopHeader('Products', 'products');
?>

<?php flashMessage(); ?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-box-seam me-2 text-primary"></i>Products</h1>
        <p class="page-subtitle"><?= $totalProducts ?> products • Stock value: <?= formatCurrency($totalValue) ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/shop/import.php" class="btn btn-outline-success btn-sm"><i class="bi bi-upload me-1"></i>Import</a>
        <a href="<?= BASE_URL ?>/shop/export.php?type=products" class="btn btn-outline-info btn-sm"><i class="bi bi-download me-1"></i>Export</a>
        <button class="btn btn-primary btn-sm" onclick="showCreateModal()"><i class="bi bi-plus-circle me-1"></i>Add Product</button>
    </div>
</div>

<!-- Quick Stats -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="h3 text-primary fw-bold mb-0"><?= $totalProducts ?></div>
            <small class="text-muted">Total Products</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="h3 text-warning fw-bold mb-0"><?= $lowStockCount ?></div>
            <small class="text-muted">Low Stock</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="h3 text-danger fw-bold mb-0"><?= $outOfStockCount ?></div>
            <small class="text-muted">Out of Stock</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="h4 text-success fw-bold mb-0 small"><?= formatCurrency($totalValue) ?></div>
            <small class="text-muted">Stock Value</small>
        </div>
    </div>
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
            <div class="col-6 col-md-3">
                <select class="form-select" name="cat">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $catFilter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select" name="status">
                    <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
                    <option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= BASE_URL ?>/shop/products.php" class="btn btn-outline-secondary ms-1">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>#</th><th>Product</th><th>Category</th><th>Retail Price</th><th>Wholesale</th><th>Stock</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($products as $i => $p): ?>
                <tr class="<?= $p['stock_quantity'] <= 0 ? 'table-danger' : ($p['stock_quantity'] <= $p['min_stock_alert'] ? 'table-warning' : '') ?>">
                    <td><?= $offset + $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($p['image']): ?>
                            <img src="<?= BASE_URL ?>/assets/uploads/<?= htmlspecialchars($p['image']) ?>" width="36" height="36" style="border-radius:8px;object-fit:cover;" alt="">
                            <?php else: ?>
                            <div style="width:36px;height:36px;background:linear-gradient(135deg,#f0f2ff,#e8f4fd);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;">📦</div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?></div>
                                <?php if ($p['barcode']): ?><small class="text-muted font-monospace"><?= htmlspecialchars($p['barcode']) ?></small><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge" style="background:rgba(255,255,255,.1);color:#e0d8ff;"><?= htmlspecialchars($p['cat_name'] ?? 'Uncategorized') ?></span></td>
                    <td class="fw-bold text-primary"><?= formatCurrency($p['retail_price']) ?></td>
                    <td class="text-success"><?= formatCurrency($p['wholesale_price']) ?></td>
                    <td>
                        <span class="fw-bold <?= $p['stock_quantity'] <= 0 ? 'text-danger' : ($p['stock_quantity'] <= $p['min_stock_alert'] ? 'text-warning' : 'text-success') ?>">
                            <?= $p['stock_quantity'] ?> <?= htmlspecialchars($p['unit']) ?>
                        </span>
                        <?php if ($p['stock_quantity'] <= $p['min_stock_alert'] && $p['stock_quantity'] > 0): ?>
                        <span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;">Low</span>
                        <?php elseif ($p['stock_quantity'] <= 0): ?>
                        <span class="badge bg-danger ms-1" style="font-size:0.65rem;">Out</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $p['status']==='active'?'status-active':'status-inactive' ?>"><?= ucfirst($p['status']) ?></span></td>
                    <td>
                        <div class="d-flex gap-1">
                            <button onclick="editProduct(<?= htmlspecialchars(json_encode($p)) ?>)" class="btn btn-xs btn-outline-primary" style="padding:0.2rem 0.5rem;font-size:0.75rem;">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="<?= BASE_URL ?>/shop/purchases.php?product_id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-success" style="padding:0.2rem 0.5rem;font-size:0.75rem;" title="Add Stock">
                                <i class="bi bi-plus-circle"></i>
                            </a>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger" style="padding:0.2rem 0.5rem;font-size:0.75rem;" onclick="return confirm('Archive this product?')">
                                    <i class="bi bi-archive"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                <tr><td colspan="8" class="text-center py-5">
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="bi bi-box-seam"></i></div>
                        <h5>No Products Found</h5>
                        <p class="text-muted">Add products to start billing</p>
                        <button class="btn btn-primary" onclick="showCreateModal()"><i class="bi bi-plus me-1"></i>Add Product</button>
                    </div>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.products-pagination { gap: .45rem; }
.products-pagination .page-link {
    min-width: 2.25rem;
    border-radius: .45rem !important;
    text-align: center;
}
</style>

<?php if ($totalProducts > 0): ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
    <small class="text-muted">Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalProducts) ?> of <?= $totalProducts ?> products</small>
    <?php if ($totalPages > 1): ?>
    <nav aria-label="Products pagination">
        <ul class="pagination pagination-sm mb-0 products-pagination">
            <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page === 1 ? '#' : htmlspecialchars($productPageUrl($page - 1)) ?>" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>
            </li>
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($productPageUrl(1)) ?>">1</a></li>
                <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($productPageUrl($p)) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($productPageUrl($totalPages)) ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $page === $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page === $totalPages ? '#' : htmlspecialchars($productPageUrl($page + 1)) ?>" aria-label="Next"><i class="bi bi-chevron-right"></i></a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Create/Edit Product Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productModalTitle"><i class="bi bi-box-seam me-2"></i>Add Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="productForm">
                <input type="hidden" name="action" id="productAction" value="create">
                <input type="hidden" name="product_id" id="productId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Product Name *</label>
                            <input type="text" class="form-control" name="name" id="pName" required placeholder="Enter product name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category_id" id="pCategory">
                                <option value="">Select category</option>
                                <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Barcode / SKU</label>
                            <input type="text" class="form-control" name="barcode" id="pBarcode" placeholder="Scan or enter barcode">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Company Purchase Price <small class="text-danger">(Hidden from customer)</small></label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" class="form-control" name="company_price" id="pCompanyPrice" min="0" step="0.01" placeholder="e.g. 2200">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Retail Price *</label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" class="form-control" name="retail_price" id="pRetailPrice" min="0" step="0.01" required placeholder="e.g. 2200">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Wholesale Price</label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" class="form-control" name="wholesale_price" id="pWholesalePrice" min="0" step="0.01" placeholder="e.g. 2200">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Stock Quantity</label>
                            <input type="number" class="form-control" name="stock_quantity" id="pStock" min="0" placeholder="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Low Stock Alert</label>
                            <input type="number" class="form-control" name="min_stock_alert" id="pMinAlert" min="0" value="5">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit</label>
                            <select class="form-select" name="unit" id="pUnit">
                                <option value="pcs">pcs</option>
                                <option value="kg">kg</option>
                                <option value="g">g</option>
                                <option value="ltr">ltr</option>
                                <option value="ml">ml</option>
                                <option value="box">box</option>
                                <option value="pack">pack</option>
                                <option value="dozen">dozen</option>
                                <option value="meter">meter</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Description</label>
                            <input type="text" class="form-control" name="description" id="pDesc" placeholder="Optional description">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="pStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Image</label>
                            <input type="file" class="form-control" name="image" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showCreateModal() {
    document.getElementById('productModalTitle').innerHTML = '<i class="bi bi-box-seam me-2"></i>Add Product';
    document.getElementById('productAction').value = 'create';
    document.getElementById('productId').value = '';
    document.getElementById('productForm').reset();
    document.getElementById('pMinAlert').value = 5;
    new bootstrap.Modal(document.getElementById('productModal')).show();
}

function editProduct(p) {
    document.getElementById('productModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Product';
    document.getElementById('productAction').value = 'update';
    document.getElementById('productId').value = p.id;
    document.getElementById('pName').value = p.name;
    document.getElementById('pCategory').value = p.category_id || '';
    document.getElementById('pBarcode').value = p.barcode || '';
    document.getElementById('pCompanyPrice').value = p.company_price;
    document.getElementById('pRetailPrice').value = p.retail_price;
    document.getElementById('pWholesalePrice').value = p.wholesale_price;
    document.getElementById('pStock').value = p.stock_quantity;
    document.getElementById('pMinAlert').value = p.min_stock_alert;
    document.getElementById('pUnit').value = p.unit || 'pcs';
    document.getElementById('pDesc').value = p.description || '';
    document.getElementById('pStatus').value = p.status;
    new bootstrap.Modal(document.getElementById('productModal')).show();
}
</script>
<?php shopFooter(); ?>

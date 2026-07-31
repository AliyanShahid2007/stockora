<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';
$db = getDB();
$shopId = (int)$_SESSION['shop_id'];

// Handle bulk price update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    
    if ($postAction === 'bulk_update_category') {
        $catId = safeInt($_POST['category_id'] ?? 0);
        $updateType = $_POST['update_type'] ?? 'percent';
        $updateValue = safeFloat($_POST['update_value'] ?? 0);
        $priceField = in_array($_POST['price_field'] ?? '', ['retail_price','wholesale_price','company_price']) ? $_POST['price_field'] : 'retail_price';
        
        if ($updateValue <= 0) {
            redirect('profit_calculator.php', 'Please enter a valid value.', 'error');
        }
        $whereClause = "shop_id = $shopId";
        if ($catId) $whereClause .= " AND category_id = $catId";
        
        if ($updateType === 'percent_increase') {
            $sql = "UPDATE products SET {$priceField} = ROUND({$priceField} * (1 + {$updateValue}/100), 2) WHERE {$whereClause}";
        } elseif ($updateType === 'percent_decrease') {
            $sql = "UPDATE products SET {$priceField} = ROUND({$priceField} * (1 - {$updateValue}/100), 2) WHERE {$whereClause}";
        } elseif ($updateType === 'fixed_increase') {
            $sql = "UPDATE products SET {$priceField} = ROUND({$priceField} + {$updateValue}, 2) WHERE {$whereClause}";
        } elseif ($updateType === 'fixed_decrease') {
            $sql = "UPDATE products SET {$priceField} = ROUND(GREATEST({$priceField} - {$updateValue}, 1), 2) WHERE {$whereClause}";
        } else {
            $sql = "UPDATE products SET {$priceField} = {$updateValue} WHERE {$whereClause}";
        }
        $affected = $db->exec($sql);
        redirect('profit_calculator.php', "Updated {$affected} products successfully!");
    }
    
    if ($postAction === 'update_individual') {
        $productId = safeInt($_POST['product_id'] ?? 0);
        $retailPrice = safeFloat($_POST['retail_price'] ?? 0);
        $wholesalePrice = safeFloat($_POST['wholesale_price'] ?? 0);
        $companyPrice = safeFloat($_POST['company_price'] ?? 0);
        $db->prepare("UPDATE products SET retail_price=?, wholesale_price=?, company_price=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND shop_id=?")
           ->execute([$retailPrice, $wholesalePrice, $companyPrice, $productId, $shopId]);
        jsonResponse(['success' => true, 'message' => 'Price updated!']);
    }
}

// Get categories
$categories = $db->prepare("SELECT * FROM categories WHERE shop_id=? ORDER BY name");
$categories->execute([$shopId]);
$categories = $categories->fetchAll();

// Get products with category
$filterCat = safeInt($_GET['cat'] ?? 0);
$search = sanitize($_GET['q'] ?? '');
$sql = "SELECT p.*, c.name as cat_name, 
               ROUND((p.retail_price - p.company_price) / NULLIF(p.retail_price,0) * 100, 1) as margin_pct,
               ROUND(p.retail_price - p.company_price, 2) as profit_per_unit
        FROM products p LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.shop_id = ?";
$params = [$shopId];
if ($filterCat) { $sql .= " AND p.category_id = ?"; $params[] = $filterCat; }
if ($search) { $sql .= " AND p.name LIKE ?"; $params[] = "%$search%"; }
$sql .= " ORDER BY p.name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Stats
$totalProducts = count($products);
$avgMargin = $totalProducts > 0 ? array_sum(array_column($products, 'margin_pct')) / $totalProducts : 0;
$bestMargin = $totalProducts > 0 ? max(array_column($products, 'margin_pct')) : 0;
$totalValue = array_sum(array_map(fn($p) => $p['retail_price'] * $p['stock_quantity'], $products));

shopHeader('Profit Calculator & Price Manager', 'profit_calc');
?>
<?php flashMessage(); ?>

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-calculator me-2 text-primary"></i>Profit Calculator & Bulk Price Update</h1>
    <p class="page-subtitle">Analyze profit margins and update prices in bulk</p>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-box-seam"></i></div>
            <div class="stat-card-value"><?= $totalProducts ?></div>
            <div class="stat-card-label" style="color:#fff!important;">Products</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-percent"></i></div>
            <div class="stat-card-value"><?= round($avgMargin, 1) ?>%</div>
            <div class="stat-card-label" style="color:#fff!important;">Avg Margin</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-trophy"></i></div>
            <div class="stat-card-value"><?= round($bestMargin, 1) ?>%</div>
            <div class="stat-card-label" style="color:#fff!important;">Best Margin</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-info">
            <div class="stat-card-icon"><i class="bi bi-currency-rupee"></i></div>
            <div class="stat-card-value"><?= $totalValue >= 1000 ? number_format($totalValue/1000,1).'K' : number_format($totalValue) ?></div>
            <div class="stat-card-label" style="color:#fff!important;">Stock Value</div>
        </div>
    </div>
</div>

<!-- Bulk Update Panel -->
<div class="card mb-4">
    <div class="card-header">
        <span><i class="bi bi-pencil-square me-2 text-warning"></i>Bulk Price Update</span>
    </div>
    <div class="card-body">
        <form method="POST" onsubmit="return confirmBulkUpdate();">
            <input type="hidden" name="action" value="bulk_update_category">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Category</label>
                    <select class="form-select" name="category_id">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Price Field</label>
                    <select class="form-select" name="price_field">
                        <option value="retail_price">Retail Price</option>
                        <option value="wholesale_price">Wholesale Price</option>
                        <option value="company_price">Cost Price</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">Update Type</label>
                    <select class="form-select" name="update_type" id="updateTypeSelect" onchange="updateLabel()">
                        <option value="percent_increase">Increase by %</option>
                        <option value="percent_decrease">Decrease by %</option>
                        <option value="fixed_increase">Increase by Rs.</option>
                        <option value="fixed_decrease">Decrease by Rs.</option>
                        <option value="set_fixed">Set Fixed Price</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small" id="updateLabel">Value (%)</label>
                    <input type="number" class="form-control" name="update_value" required min="0.1" step="0.1" placeholder="e.g. 10">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="bi bi-pencil-square me-1"></i>Apply Bulk Update
                    </button>
                </div>
            </div>
            <div class="alert alert-info mt-3 py-2 mb-0 small">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Preview:</strong> Select a category and type, then click Apply. Changes will affect all matching products.
            </div>
        </form>
    </div>
</div>

<!-- Search & Filter -->
<div class="card mb-3">
    <div class="card-body p-3">
        <form method="GET" class="row g-2">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search products...">
                </div>
            </div>
            <div class="col-md-4">
                <select class="form-select" name="cat" onchange="this.form.submit()">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $filterCat == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i></button>
                <a href="<?= BASE_URL ?>/shop/profit_calculator.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Products Profit Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2"></i>Product Profit Analysis (<?= $totalProducts ?>)</span>
        <small class="text-muted">Green = >20% margin | Yellow = 10-20% | Red = <10%</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Cost Price</th>
                    <th>Retail Price</th>
                    <th>Wholesale</th>
                    <th>Profit/Unit</th>
                    <th>Margin %</th>
                    <th>Stock</th>
                    <th>Stock Profit</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <?php
                $marginClass = $p['margin_pct'] >= 20 ? 'text-success' : ($p['margin_pct'] >= 10 ? 'text-warning' : 'text-danger');
                $rowClass = $p['margin_pct'] >= 20 ? '' : ($p['margin_pct'] >= 10 ? 'table-warning' : 'table-danger');
                $stockProfit = $p['profit_per_unit'] * $p['stock_quantity'];
                ?>
                <tr class="<?= $rowClass ?>">
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($p['name']) ?></div>
                        <?php if ($p['sku']): ?><small class="text-muted"><?= htmlspecialchars($p['sku']) ?></small><?php endif; ?>
                    </td>
                    <td><small><?= htmlspecialchars($p['cat_name'] ?? 'Uncategorized') ?></small></td>
                    <td class="text-muted small"><?= formatCurrency($p['company_price']) ?></td>
                    <td class="fw-bold"><?= formatCurrency($p['retail_price']) ?></td>
                    <td class="text-info small"><?= formatCurrency($p['wholesale_price']) ?></td>
                    <td class="fw-bold <?= $marginClass ?>"><?= formatCurrency($p['profit_per_unit']) ?></td>
                    <td>
                        <span class="badge <?= $p['margin_pct'] >= 20 ? 'bg-success' : ($p['margin_pct'] >= 10 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                            <?= $p['margin_pct'] ?>%
                        </span>
                    </td>
                    <td><?= $p['stock_quantity'] ?></td>
                    <td class="fw-bold text-success small"><?= formatCurrency($stockProfit) ?></td>
                    <td>
                        <button onclick="editPrice(<?= htmlspecialchars(json_encode($p)) ?>)" class="btn btn-xs btn-outline-primary" style="font-size:0.72rem;padding:0.15rem 0.5rem;" title="Edit Price">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Price Modal -->
<div class="modal fade" id="editPriceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Product Price</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="fw-bold mb-3" id="editProdName"></p>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-bold">Cost Price (Rs.)</label>
                        <input type="number" class="form-control" id="epCost" step="1" min="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">Retail Price (Rs.)</label>
                        <input type="number" class="form-control" id="epRetail" step="1" min="0" oninput="calcMargin()">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">Wholesale Price (Rs.)</label>
                        <input type="number" class="form-control" id="epWholesale" step="1" min="0">
                    </div>
                    <div class="col-12">
                        <div class="p-2 rounded text-center" style="background:rgba(108,99,255,.12);border:1px solid rgba(108,99,255,.2);">
                            Profit: <strong id="epProfit" class="text-success">Rs. 0</strong>  |  
                            Margin: <strong id="epMargin" class="text-primary">0%</strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="savePrice()">
                    <i class="bi bi-save me-1"></i>Save Price
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentProduct = null;

function updateLabel() {
    const t = document.getElementById('updateTypeSelect').value;
    const labels = { percent_increase: 'Increase (%)', percent_decrease: 'Decrease (%)', fixed_increase: 'Amount (Rs.)', fixed_decrease: 'Amount (Rs.)', set_fixed: 'Fixed Price (Rs.)' };
    document.getElementById('updateLabel').textContent = labels[t] || 'Value';
}

function confirmBulkUpdate() {
    const updateType = document.getElementById('updateTypeSelect').options[document.getElementById('updateTypeSelect').selectedIndex].text;
    return confirm(`⚠️ Bulk Update\n\nThis will update prices for ALL matching products.\nType: ${updateType}\n\nContinue?`);
}

function editPrice(p) {
    currentProduct = p;
    document.getElementById('editProdName').textContent = p.name;
    document.getElementById('epCost').value = p.company_price || 0;
    document.getElementById('epRetail').value = p.retail_price || 0;
    document.getElementById('epWholesale').value = p.wholesale_price || 0;
    calcMargin();
    new bootstrap.Modal(document.getElementById('editPriceModal')).show();
}

function calcMargin() {
    const cost = parseFloat(document.getElementById('epCost').value) || 0;
    const retail = parseFloat(document.getElementById('epRetail').value) || 0;
    const profit = retail - cost;
    const margin = retail > 0 ? ((profit/retail)*100).toFixed(1) : 0;
    document.getElementById('epProfit').textContent = 'Rs. ' + fmtNum(profit);
    document.getElementById('epMargin').textContent = margin + '%';
}

async function savePrice() {
    if (!currentProduct) return;
    const data = {
        action: 'update_individual',
        product_id: currentProduct.id,
        company_price: document.getElementById('epCost').value,
        retail_price: document.getElementById('epRetail').value,
        wholesale_price: document.getElementById('epWholesale').value
    };
    try {
        const resp = await apiCall('<?= BASE_URL ?>/shop/profit_calculator.php', 'POST', data);
        if (resp.success) {
            showToast('Price updated!', 'success');
            setTimeout(() => location.reload(), 800);
            bootstrap.Modal.getInstance(document.getElementById('editPriceModal')).hide();
        }
    } catch(e) {
        showToast('Error saving price', 'danger');
    }
}
</script>

<?php shopFooter(); ?>

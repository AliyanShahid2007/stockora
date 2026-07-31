<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';
$db = getDB();
$shopId = (int)$_SESSION['shop_id'];

// Quick restock from this page
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    if ($postAction === 'quick_restock') {
        $productId = safeInt($_POST['product_id'] ?? 0);
        $qty = safeInt($_POST['qty'] ?? 0);
        $unitPrice = safeFloat($_POST['unit_price'] ?? 0);
        $supplier = sanitize($_POST['supplier'] ?? '');
        
        if ($qty > 0) {
            $db->beginTransaction();
            try {
                // Add stock
                $db->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND shop_id=?")
                   ->execute([$qty, $productId, $shopId]);
                
                // Log stock movement — get BEFORE quantity first (before the UPDATE)
                $curStmt = $db->prepare("SELECT stock_quantity FROM products WHERE id=? AND shop_id=?");
                $curStmt->execute([$productId, $shopId]);
                $beforeQty = (int)$curStmt->fetchColumn();
                $afterQty  = $beforeQty + $qty;
                $db->prepare("INSERT INTO stock_movements (shop_id, product_id, movement_type, quantity, before_quantity, after_quantity, notes, created_at) VALUES (?,?,'in',?,?,?,?,CURRENT_TIMESTAMP)")
                   ->execute([$shopId, $productId, $qty, $beforeQty, $afterQty, "Quick restock. Supplier: " . ($supplier ?: 'N/A')]);
                
                // Add purchase record if price provided
                if ($unitPrice > 0) {
                    $db->prepare("INSERT INTO purchases (shop_id, product_id, quantity, unit_price, total_amount, supplier_name, purchase_date) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$shopId, $productId, $qty, $unitPrice, $qty * $unitPrice, $supplier ?: 'Unknown', date('Y-m-d')]);
                }
                
                $db->commit();
                redirect('reorder_alerts.php', "Restocked {$qty} units!");
            } catch (Exception $e) {
                $db->rollback();
                redirect('reorder_alerts.php', 'Error: ' . $e->getMessage(), 'error');
            }
        } else {
            redirect('reorder_alerts.php', 'Quantity must be > 0', 'error');
        }
    }
    
    if ($postAction === 'update_alert_level') {
        $productId = safeInt($_POST['product_id'] ?? 0);
        $level = safeInt($_POST['alert_level'] ?? 5);
        $db->prepare("UPDATE products SET min_stock_alert=? WHERE id=? AND shop_id=?")->execute([$level, $productId, $shopId]);
        redirect('reorder_alerts.php', 'Alert level updated!');
    }
}

// Get products below stock alert level
$lowStock = $db->prepare("
    SELECT p.*, c.name as cat_name,
           COALESCE((SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON si.sale_id=s.id WHERE si.product_id=p.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)),0) as sold_30d,
           COALESCE((SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON si.sale_id=s.id WHERE si.product_id=p.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)),0) as sold_7d
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.shop_id = ? AND p.stock_quantity <= p.min_stock_alert AND p.status = 'active'
    ORDER BY p.stock_quantity ASC, p.name
");
$lowStock->execute([$shopId]);
$lowStockProducts = $lowStock->fetchAll();

// Out of stock
$outOfStock = array_filter($lowStockProducts, fn($p) => $p['stock_quantity'] == 0);
$criticalStock = array_filter($lowStockProducts, fn($p) => $p['stock_quantity'] > 0 && $p['stock_quantity'] <= 2);
$lowStockList = array_filter($lowStockProducts, fn($p) => $p['stock_quantity'] > 2);

// Recent restock history
$restockHistory = $db->prepare("
    SELECT sm.*, p.name as product_name
    FROM stock_movements sm
    JOIN products p ON sm.product_id=p.id
    WHERE sm.shop_id=? AND sm.movement_type='in'
    ORDER BY sm.created_at DESC
    LIMIT 10
");
$restockHistory->execute([$shopId]);
$restockHistory = $restockHistory->fetchAll();

shopHeader('Low Stock & Reorder Alerts', 'reorder_alerts');
?>
<?php flashMessage(); ?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Low Stock & Reorder Alerts</h1>
        <p class="page-subtitle">Products that need restocking</p>
    </div>
    <?php if (!empty($lowStockProducts)): ?>
    <a href="https://wa.me/?text=<?= urlencode("📦 Low Stock Alert - " . ($_SESSION['shop_name'] ?? '') . "\n\n" . implode("\n", array_map(fn($p) => "• {$p['name']}: {$p['stock_quantity']} left", array_slice($lowStockProducts, 0, 10)))) ?>" 
       target="_blank" class="btn btn-success btn-sm">
        <i class="bi bi-whatsapp me-1"></i>Send WhatsApp Alert
    </a>
    <?php endif; ?>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="stat-card stat-danger">
            <div class="stat-card-icon"><i class="bi bi-x-circle"></i></div>
            <div class="stat-card-value"><?= count($outOfStock) ?></div>
            <div class="stat-card-label">Out of Stock</div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-card-value"><?= count($criticalStock) ?></div>
            <div class="stat-card-label">Critical (≤2)</div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card stat-info">
            <div class="stat-card-icon"><i class="bi bi-arrow-down-circle"></i></div>
            <div class="stat-card-value"><?= count($lowStockList) ?></div>
            <div class="stat-card-label">Low Stock</div>
        </div>
    </div>
</div>

<?php if (empty($lowStockProducts)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <div class="empty-state">
            <div class="empty-state-icon text-success"><i class="bi bi-check-circle"></i></div>
            <h5>All Stock Levels OK!</h5>
            <p class="text-muted">No products are below their alert levels.</p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list me-2"></i>Products Needing Restock (<?= count($lowStockProducts) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Product</th><th>Category</th><th>Current Stock</th><th>Alert Level</th><th>Sold (30d)</th><th>Sold (7d)</th><th>Est. Days Left</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($lowStockProducts as $p): ?>
                <?php
                $daily = $p['sold_30d'] / 30;
                $daysLeft = $daily > 0 ? round($p['stock_quantity'] / $daily) : 999;
                $urgency = $p['stock_quantity'] == 0 ? 'table-danger' : ($p['stock_quantity'] <= 2 ? 'table-warning' : '');
                ?>
                <tr class="<?= $urgency ?>">
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($p['name']) ?></div>
                        <?php if ($p['sku']): ?><small class="text-muted"><?= htmlspecialchars($p['sku']) ?></small><?php endif; ?>
                    </td>
                    <td><small><?= htmlspecialchars($p['cat_name'] ?? '—') ?></small></td>
                    <td>
                        <span class="badge <?= $p['stock_quantity'] == 0 ? 'bg-danger' : ($p['stock_quantity'] <= 2 ? 'bg-warning text-dark' : 'bg-secondary') ?> fs-6">
                            <?= $p['stock_quantity'] ?> <?= $p['unit'] ?>
                        </span>
                    </td>
                    <td><small class="text-muted"><?= $p['min_stock_alert'] ?></small></td>
                    <td><?= $p['sold_30d'] ?></td>
                    <td><?= $p['sold_7d'] ?></td>
                    <td>
                        <?php if ($daysLeft >= 999): ?>
                        <span class="text-muted">—</span>
                        <?php else: ?>
                        <span class="badge <?= $daysLeft <= 3 ? 'bg-danger' : ($daysLeft <= 7 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                            ~<?= $daysLeft ?>d
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <button onclick="quickRestock(<?= htmlspecialchars(json_encode($p)) ?>)" class="btn btn-xs btn-success" style="font-size:0.72rem;padding:0.15rem 0.5rem;">
                                <i class="bi bi-plus-circle me-1"></i>Restock
                            </button>
                            <button onclick="setAlert(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', <?= $p['min_stock_alert'] ?>)" class="btn btn-xs btn-outline-secondary" style="font-size:0.72rem;padding:0.15rem 0.5rem;" title="Set Alert Level">
                                <i class="bi bi-bell"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Recent Restock History -->
<?php if (!empty($restockHistory)): ?>
<div class="card">
    <div class="card-header fw-bold"><i class="bi bi-clock-history me-2"></i>Recent Restock History</div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Product</th><th>Qty Added</th><th>Before</th><th>After</th><th>Notes</th><th>Date</th></tr></thead>
            <tbody>
                <?php foreach ($restockHistory as $r): ?>
                <tr>
                    <td class="fw-semibold small"><?= htmlspecialchars($r['product_name']) ?></td>
                    <td><span class="badge bg-success">+<?= $r['quantity'] ?></span></td>
                    <td><small class="text-muted"><?= $r['before_quantity'] ?></small></td>
                    <td><small class="text-success fw-bold"><?= $r['after_quantity'] ?></small></td>
                    <td><small class="text-muted"><?= htmlspecialchars(substr($r['notes'] ?? '', 0, 40)) ?></small></td>
                    <td><small><?= date('d M, h:i A', strtotime($r['created_at'])) ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Quick Restock Modal -->
<div class="modal fade" id="restockModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h6 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Quick Restock</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="quick_restock">
                <input type="hidden" name="product_id" id="rsProductId">
                <div class="modal-body">
                    <p class="fw-bold mb-1" id="rsProductName"></p>
                    <p class="text-muted small mb-3">Current stock: <strong id="rsCurrentStock"></strong></p>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Quantity to Add *</label>
                            <input type="number" class="form-control" name="qty" id="rsQty" min="1" required placeholder="e.g. 50">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Unit Cost Price (Rs.)</label>
                            <input type="number" class="form-control" name="unit_price" id="rsPrice" min="0" step="1" placeholder="Optional">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Supplier Name</label>
                            <input type="text" class="form-control" name="supplier" placeholder="Optional supplier name">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check me-1"></i>Add Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Set Alert Modal -->
<div class="modal fade" id="alertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-bell me-2"></i>Set Alert Level</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_alert_level">
                <input type="hidden" name="product_id" id="alProductId">
                <div class="modal-body">
                    <p class="small mb-3" id="alProductName"></p>
                    <label class="form-label fw-bold small">Alert when stock falls below:</label>
                    <input type="number" class="form-control" name="alert_level" id="alLevel" min="0" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Set</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function quickRestock(p) {
    document.getElementById('rsProductId').value = p.id;
    document.getElementById('rsProductName').textContent = p.name;
    document.getElementById('rsCurrentStock').textContent = p.stock_quantity + ' ' + (p.unit || '');
    document.getElementById('rsPrice').value = p.company_price || '';
    new bootstrap.Modal(document.getElementById('restockModal')).show();
}
function setAlert(id, name, level) {
    document.getElementById('alProductId').value = id;
    document.getElementById('alProductName').textContent = name;
    document.getElementById('alLevel').value = level;
    new bootstrap.Modal(document.getElementById('alertModal')).show();
}
</script>

<?php shopFooter(); ?>

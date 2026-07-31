<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $id       = safeInt($_POST['buyer_id'] ?? 0);
    $name     = sanitize($_POST['name'] ?? '');
    $business = sanitize($_POST['business_name'] ?? '');
    $phone    = sanitize($_POST['phone'] ?? '');
    $email    = sanitize($_POST['email'] ?? '');
    $city     = sanitize($_POST['city'] ?? '');
    $address  = sanitize($_POST['address'] ?? '');
    $creditLimit       = safeFloat($_POST['credit_limit'] ?? 0);
    $minQtyWholesale   = safeInt($_POST['min_qty_wholesale'] ?? 0);
    $wholesaleDiscount = safeFloat($_POST['wholesale_discount'] ?? 0);

    if (($action === 'create' || $action === 'update') && !$name) {
        redirect('bulk_buyers.php', 'Name is required', 'error');
    } elseif ($action === 'create') {
        $db->prepare("INSERT INTO bulk_buyers (shop_id, name, business_name, phone, email, city, address, credit_limit, min_qty_wholesale, wholesale_discount)
                      VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$shopId, $name, $business, $phone, $email, $city, $address, $creditLimit, $minQtyWholesale, $wholesaleDiscount]);
        redirect('bulk_buyers.php', "Buyer '{$name}' added!");
    } elseif ($action === 'update') {
        $db->prepare("UPDATE bulk_buyers SET name=?, business_name=?, phone=?, email=?, city=?, address=?, credit_limit=?, min_qty_wholesale=?, wholesale_discount=?, updated_at=CURRENT_TIMESTAMP
                      WHERE id=? AND shop_id=?")
           ->execute([$name, $business, $phone, $email, $city, $address, $creditLimit, $minQtyWholesale, $wholesaleDiscount, $id, $shopId]);
        redirect('bulk_buyers.php', 'Buyer updated!');
    } elseif ($action === 'delete') {
        $db->prepare("DELETE FROM bulk_buyers WHERE id=? AND shop_id=?")->execute([$id, $shopId]);
        redirect('bulk_buyers.php', 'Buyer deleted.');
    }
}

$stmt = $db->prepare("SELECT * FROM bulk_buyers WHERE shop_id=? ORDER BY total_purchases DESC");
$stmt->execute([$shopId]);
$buyers = $stmt->fetchAll();

shopHeader('Bulk Buyers', 'buyers');
?>
<?php flashMessage(); ?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="page-title"><i class="bi bi-building me-2 text-primary"></i>Bulk Buyers</h1>
        <p class="page-subtitle">Manage wholesale customers with automatic pricing rules</p>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#buyerModal" onclick="resetBuyerModal()">
        <i class="bi bi-plus me-1"></i>Add Buyer
    </button>
</div>

<!-- Info Banner -->
<div class="alert alert-info mb-3 py-2" style="border-left:4px solid #06B6D4;">
    <i class="bi bi-info-circle me-2"></i>
    <strong>Wholesale Auto-Pricing:</strong> Set a <em>Min Quantity</em> per buyer. When POS order reaches that quantity, wholesale prices apply automatically.
    You can also set an extra <em>Buyer Discount %</em> on top of wholesale prices.
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name / Business</th>
                    <th>Phone</th>
                    <th>City</th>
                    <th>Min Qty (Wholesale)</th>
                    <th>Extra Discount</th>
                    <th>Total Purchases</th>
                    <th>Credit Limit</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($buyers as $i => $b): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($b['name']) ?></div>
                        <?php if ($b['business_name']): ?>
                        <small class="text-muted"><?= htmlspecialchars($b['business_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($b['phone'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($b['city'] ?? '-') ?></td>
                    <td>
                        <?php if ($b['min_qty_wholesale'] > 0): ?>
                        <span class="badge bg-primary"><?= $b['min_qty_wholesale'] ?> units</span>
                        <?php else: ?>
                        <span class="text-muted small">Any qty</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($b['wholesale_discount'] > 0): ?>
                        <span class="badge bg-success"><?= $b['wholesale_discount'] ?>% off</span>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-bold text-success"><?= formatCurrency($b['total_purchases']) ?></td>
                    <td><?= $b['credit_limit'] > 0 ? formatCurrency($b['credit_limit']) : '<span class="text-muted">-</span>' ?></td>
                    <td>
                        <button onclick="editBuyer(<?= htmlspecialchars(json_encode($b)) ?>)"
                                class="btn btn-xs btn-outline-primary" style="padding:0.2rem 0.5rem;font-size:0.75rem;">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="buyer_id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger"
                                    style="padding:0.2rem 0.5rem;font-size:0.75rem;"
                                    onclick="return confirm('Delete this buyer?')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($buyers)): ?>
                <tr><td colspan="9" class="text-center py-4 text-muted">No bulk buyers added yet</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Buyer Modal -->
<div class="modal fade" id="buyerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="buyerModalTitle">Add Bulk Buyer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" id="buyerAction" value="create">
                <input type="hidden" name="buyer_id" id="buyerId">
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- Basic Info -->
                        <div class="col-md-6">
                            <label class="form-label">Contact Name *</label>
                            <input type="text" class="form-control" name="name" id="bName" required placeholder="e.g. Ahmed Khan">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Business Name</label>
                            <input type="text" class="form-control" name="business_name" id="bBusiness" placeholder="e.g. Khan Traders">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" id="bPhone" placeholder="03xx-xxxxxxx">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="bEmail" placeholder="Optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city" id="bCity" placeholder="e.g. Lahore">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Credit Limit (PKR)</label>
                            <input type="number" class="form-control" name="credit_limit" id="bCredit" min="0" step="100" placeholder="0 = No credit limit">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" id="bAddress" rows="2" placeholder="Optional"></textarea>
                        </div>

                        <!-- Wholesale Pricing Rules -->
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold mb-3"><i class="bi bi-tags me-2 text-primary"></i>Wholesale Pricing Rules</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                Min Quantity for Wholesale Price
                                <small class="text-muted d-block">Order must reach this qty for wholesale rates</small>
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="min_qty_wholesale" id="bMinQty" min="0" step="1" placeholder="0 = Always wholesale">
                                <span class="input-group-text">units</span>
                            </div>
                            <div class="form-text">Set 0 to always apply wholesale price for this buyer</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                Extra Buyer Discount %
                                <small class="text-muted d-block">Additional discount on top of wholesale price</small>
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="wholesale_discount" id="bWsDiscount" min="0" max="50" step="0.5" placeholder="0">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text">e.g. 5 = 5% extra off wholesale price</div>
                        </div>

                        <!-- Preview Box -->
                        <div class="col-12">
                            <div class="rounded-3 p-3" style="background:rgba(108,99,255,.1);border:1px solid rgba(167,139,250,.2);">
                                <div class="small fw-bold text-primary mb-1"><i class="bi bi-eye me-1"></i>How It Works in POS:</div>
                                <div class="small text-muted" id="rulePreview">
                                    Fill Min Qty to see rule preview...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check me-1"></i>Save Buyer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetBuyerModal() {
    document.getElementById('buyerAction').value = 'create';
    document.getElementById('buyerModalTitle').textContent = 'Add Bulk Buyer';
    ['buyerId','bName','bBusiness','bPhone','bEmail','bCity','bCredit','bAddress','bMinQty','bWsDiscount']
        .forEach(id => document.getElementById(id).value = '');
    updateRulePreview();
}

function editBuyer(b) {
    document.getElementById('buyerAction').value = 'update';
    document.getElementById('buyerModalTitle').textContent = 'Edit Buyer — ' + b.name;
    document.getElementById('buyerId').value = b.id;
    document.getElementById('bName').value = b.name || '';
    document.getElementById('bBusiness').value = b.business_name || '';
    document.getElementById('bPhone').value = b.phone || '';
    document.getElementById('bEmail').value = b.email || '';
    document.getElementById('bCity').value = b.city || '';
    document.getElementById('bCredit').value = b.credit_limit || '';
    document.getElementById('bAddress').value = b.address || '';
    document.getElementById('bMinQty').value = b.min_qty_wholesale || '0';
    document.getElementById('bWsDiscount').value = b.wholesale_discount || '0';
    updateRulePreview();
    new bootstrap.Modal(document.getElementById('buyerModal')).show();
}

function updateRulePreview() {
    const minQty = parseInt(document.getElementById('bMinQty').value) || 0;
    const discount = parseFloat(document.getElementById('bWsDiscount').value) || 0;
    const name = document.getElementById('bName').value || 'This buyer';
    let text = '';

    if (minQty === 0 && discount === 0) {
        text = `${name} will always get <strong>wholesale prices</strong> with no extra discount.`;
    } else if (minQty === 0 && discount > 0) {
        text = `${name} will get <strong>wholesale prices + ${discount}% extra discount</strong> on every order.`;
    } else if (minQty > 0 && discount === 0) {
        text = `When total cart quantity reaches <strong>${minQty} or more units</strong>, wholesale prices will apply automatically. Below ${minQty} units, retail price applies.`;
    } else {
        text = `When total cart quantity reaches <strong>${minQty} or more units</strong>, wholesale prices + <strong>${discount}% extra discount</strong> apply. Below ${minQty}, retail price applies.`;
    }
    document.getElementById('rulePreview').innerHTML = text;
}

document.getElementById('bMinQty').addEventListener('input', updateRulePreview);
document.getElementById('bWsDiscount').addEventListener('input', updateRulePreview);
document.getElementById('bName').addEventListener('input', updateRulePreview);
</script>
<?php shopFooter(); ?>

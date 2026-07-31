<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';
$db = getDB();
$shopId = (int)$_SESSION['shop_id'];

// Shop creation date for calendar restriction
$_shopDataCr = $db->prepare("SELECT created_at FROM shops WHERE id=?");
$_shopDataCr->execute([$shopId]);
$_shopDataCr = $_shopDataCr->fetch();
$shopCreatedDate = $_shopDataCr ? date('Y-m-d', strtotime($_shopDataCr['created_at'])) : '2020-01-01';
$todayDate = date('Y-m-d');

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    
    if ($postAction === 'add_payment') {
        $customerId = safeInt($_POST['customer_id'] ?? 0);
        $amount = safeFloat($_POST['amount'] ?? 0);
        $type = in_array($_POST['payment_type']??'', ['payment','credit']) ? $_POST['payment_type'] : 'payment';
        $notes = sanitize($_POST['notes'] ?? '');
        $date = $_POST['payment_date'] ?? date('Y-m-d');
        if ($amount <= 0) {
            redirect('credit_dues.php', 'Invalid amount.', 'error');
        }
        $db->prepare("INSERT INTO customer_payments (shop_id, customer_id, amount, payment_type, notes, payment_date) VALUES (?,?,?,?,?,?)")
           ->execute([$shopId, $customerId, $amount, $type, $notes, $date]);
        if ($type === 'payment') {
            $db->prepare("UPDATE customers SET outstanding_dues = GREATEST(0, outstanding_dues - ?), last_payment = ?, last_payment_date = ? WHERE id=? AND shop_id=?")
               ->execute([$amount, $amount, $date, $customerId, $shopId]);
        } elseif ($type === 'credit') {
            $db->prepare("UPDATE customers SET outstanding_dues = outstanding_dues + ? WHERE id=? AND shop_id=?")
               ->execute([$amount, $customerId, $shopId]);
        }
        redirect('credit_dues.php', 'Payment recorded!');
    }
    
    if ($postAction === 'set_credit_limit') {
        $customerId = safeInt($_POST['customer_id'] ?? 0);
        $limit = safeFloat($_POST['credit_limit'] ?? 0);
        $db->prepare("UPDATE customers SET credit_limit=? WHERE id=? AND shop_id=?")->execute([$limit, $customerId, $shopId]);
        redirect('credit_dues.php', 'Credit limit updated!');
    }
    
    if ($postAction === 'add_customer_with_dues') {
        $name = sanitize($_POST['name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $dues = safeFloat($_POST['initial_dues'] ?? 0);
        $limit = safeFloat($_POST['credit_limit'] ?? 0);
        if (!$name) { redirect('credit_dues.php', 'Name required.', 'error'); }
        $db->prepare("INSERT INTO customers (shop_id, name, phone, outstanding_dues, credit_limit) VALUES (?,?,?,?,?)")
           ->execute([$shopId, $name, $phone, $dues, $limit]);
        redirect('credit_dues.php', 'Customer added!');
    }
}

// Get customers with dues
$search = sanitize($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all, dues, clear

$sql = "SELECT c.*, 
               COALESCE(SUM(CASE WHEN cp.payment_type='credit' THEN cp.amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN cp.payment_type='payment' THEN cp.amount ELSE 0 END),0) as calc_dues,
               COUNT(DISTINCT s.id) as total_sales_count,
               COALESCE(SUM(s.grand_total),0) as total_sales_amount
        FROM customers c
        LEFT JOIN customer_payments cp ON cp.customer_id=c.id AND cp.shop_id=c.shop_id
        LEFT JOIN sales s ON s.customer_id=c.id AND s.shop_id=c.shop_id
        WHERE c.shop_id=?";
$params = [$shopId];
if ($search) { $sql .= " AND c.name LIKE ?"; $params[] = "%$search%"; }
$sql .= " GROUP BY c.id ORDER BY c.outstanding_dues DESC, c.name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

if ($filter === 'dues') $customers = array_filter($customers, fn($c) => $c['outstanding_dues'] > 0);
if ($filter === 'clear') $customers = array_filter($customers, fn($c) => $c['outstanding_dues'] <= 0);

$totalDues = array_sum(array_column($customers, 'outstanding_dues'));
$customersWithDues = count(array_filter($customers, fn($c) => $c['outstanding_dues'] > 0));

// Get payment history for a specific customer
$viewCustomer = safeInt($_GET['customer_id'] ?? 0);
$custPayments = [];
if ($viewCustomer) {
    $stmt2 = $db->prepare("SELECT * FROM customer_payments WHERE customer_id=? AND shop_id=? ORDER BY payment_date DESC LIMIT 20");
    $stmt2->execute([$viewCustomer, $shopId]);
    $custPayments = $stmt2->fetchAll();
}

shopHeader('Customer Credit & Dues', 'credit_dues');
?>
<?php flashMessage(); ?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-person-badge me-2 text-primary"></i>Customer Credit & Dues</h1>
        <p class="page-subtitle">Track outstanding dues and credit limits</p>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCustModal">
        <i class="bi bi-person-plus me-1"></i>Add Customer
    </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-people"></i></div>
            <div class="stat-card-value"><?= count($customers) ?></div>
            <div class="stat-card-label">Total Customers</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-danger">
            <div class="stat-card-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-card-value"><?= $customersWithDues ?></div>
            <div class="stat-card-label">With Dues</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-card-value"><?= formatCurrency($totalDues) ?></div>
            <div class="stat-card-label">Total Outstanding</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-check-circle"></i></div>
            <div class="stat-card-value"><?= count($customers) - $customersWithDues ?></div>
            <div class="stat-card-label">Clear Accounts</div>
        </div>
    </div>
</div>

<!-- Filters & Search -->
<div class="card mb-3">
    <div class="card-body p-3">
        <div class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="custSearch" placeholder="Search customers..." oninput="filterTable('custSearch','custTable')">
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex gap-1">
                    <a href="<?= BASE_URL ?>?" class="btn btn-sm <?= $filter==='all' ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
                    <a href="<?= BASE_URL ?>?filter=dues" class="btn btn-sm <?= $filter==='dues' ? 'btn-danger' : 'btn-outline-danger' ?>">Has Dues</a>
                    <a href="<?= BASE_URL ?>?filter=clear" class="btn btn-sm <?= $filter==='clear' ? 'btn-success' : 'btn-outline-success' ?>">Clear</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Customers Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table" id="custTable">
            <thead>
                <tr><th>Customer</th><th>Phone</th><th>Total Sales</th><th>Outstanding Dues</th><th>Credit Limit</th><th>Last Payment</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $cust): ?>
                <tr class="<?= $cust['outstanding_dues'] > 0 ? 'table-warning' : '' ?>">
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($cust['name']) ?></div>
                        <?php if ($cust['email']): ?><small class="text-muted"><?= htmlspecialchars($cust['email']) ?></small><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($cust['phone'] ?? '-') ?></td>
                    <td class="fw-bold"><?= formatCurrency($cust['total_sales_amount']) ?></td>
                    <td>
                        <?php if ($cust['outstanding_dues'] > 0): ?>
                        <span class="fw-bold text-danger"><?= formatCurrency($cust['outstanding_dues']) ?></span>
                        <?php if ($cust['credit_limit'] > 0): ?>
                        <div class="progress mt-1" style="height:5px;">
                            <div class="progress-bar bg-danger" style="width:<?= min(100, round(($cust['outstanding_dues']/$cust['credit_limit'])*100)) ?>%"></div>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="badge bg-success">Clear</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $cust['credit_limit'] > 0 ? formatCurrency($cust['credit_limit']) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?php if ($cust['last_payment_date']): ?>
                        <small><?= date('d M Y', strtotime($cust['last_payment_date'])) ?><br><?= formatCurrency($cust['last_payment']) ?></small>
                        <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <button onclick="recordPayment(<?= $cust['id'] ?>, '<?= htmlspecialchars(addslashes($cust['name'])) ?>', <?= $cust['outstanding_dues'] ?>)" 
                                    class="btn btn-xs btn-success" style="font-size:0.72rem;padding:0.15rem 0.5rem;" title="Record Payment">
                                <i class="bi bi-cash"></i>
                            </button>
                            <button onclick="addCredit(<?= $cust['id'] ?>, '<?= htmlspecialchars(addslashes($cust['name'])) ?>')" 
                                    class="btn btn-xs btn-warning" style="font-size:0.72rem;padding:0.15rem 0.5rem;" title="Add Credit/Dues">
                                <i class="bi bi-plus"></i>
                            </button>
                            <button onclick="setLimit(<?= $cust['id'] ?>, '<?= htmlspecialchars(addslashes($cust['name'])) ?>', <?= $cust['credit_limit'] ?>)" 
                                    class="btn btn-xs btn-outline-primary" style="font-size:0.72rem;padding:0.15rem 0.5rem;" title="Set Credit Limit">
                                <i class="bi bi-sliders"></i>
                            </button>
                            <?php if ($cust['phone']): ?>
                            <a href="https://wa.me/92<?= preg_replace('/^0/', '', preg_replace('/[^0-9]/', '', $cust['phone'])) ?>?text=<?= urlencode("Assalam-o-Alaikum {$cust['name']},\nYour outstanding dues at our shop are: Rs. " . number_format($cust['outstanding_dues']) . "\nPlease clear your dues at your earliest convenience.\nJazakAllah Khair.") ?>" 
                               target="_blank" class="btn btn-xs btn-outline-success" style="font-size:0.72rem;padding:0.15rem 0.5rem;" title="WhatsApp Reminder">
                                <i class="bi bi-whatsapp"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($customers)): ?>
                <tr><td colspan="7" class="text-center py-5">
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="bi bi-people"></i></div>
                        <h5>No Customers Found</h5>
                    </div>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h6 class="modal-title"><i class="bi bi-cash me-2"></i>Record Payment</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_payment">
                <input type="hidden" name="payment_type" value="payment">
                <input type="hidden" name="customer_id" id="pmCustId">
                <div class="modal-body">
                    <p class="mb-2">Customer: <strong id="pmCustName"></strong></p>
                    <p class="text-danger mb-3">Outstanding: <strong id="pmDues"></strong></p>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Amount Received (Rs.)</label>
                        <input type="number" class="form-control" name="amount" id="pmAmount" min="1" step="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Date</label>
                        <input type="date" class="form-control" name="payment_date" value="<?= $todayDate ?>" min="<?= $shopCreatedDate ?>" max="<?= $todayDate ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Notes</label>
                        <input type="text" class="form-control" name="notes" placeholder="Optional">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check me-1"></i>Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Credit Modal -->
<div class="modal fade" id="creditModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h6 class="modal-title"><i class="bi bi-plus me-2"></i>Add Dues/Credit</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_payment">
                <input type="hidden" name="payment_type" value="credit">
                <input type="hidden" name="customer_id" id="crCustId">
                <div class="modal-body">
                    <p class="mb-3">Customer: <strong id="crCustName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Amount Due (Rs.)</label>
                        <input type="number" class="form-control" name="amount" min="1" step="1" required placeholder="e.g. 500">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Date</label>
                        <input type="date" class="form-control" name="payment_date" value="<?= $todayDate ?>" min="<?= $shopCreatedDate ?>" max="<?= $todayDate ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Notes</label>
                        <input type="text" class="form-control" name="notes" placeholder="e.g. Udhar items 3 April">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm">Add Dues</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Set Limit Modal -->
<div class="modal fade" id="limitModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-sliders me-2"></i>Set Credit Limit</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="set_credit_limit">
                <input type="hidden" name="customer_id" id="lmCustId">
                <div class="modal-body">
                    <p class="mb-3">Customer: <strong id="lmCustName"></strong></p>
                    <label class="form-label fw-bold small">Credit Limit (Rs.)</label>
                    <input type="number" class="form-control" name="credit_limit" id="lmAmount" min="0" step="1" placeholder="0 = no limit">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Set Limit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Customer with Dues</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_customer_with_dues">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Name *</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Phone</label>
                            <input type="text" class="form-control" name="phone" placeholder="03XX-XXXXXXX">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Initial Dues (Rs.)</label>
                            <input type="number" class="form-control" name="initial_dues" min="0" step="1" value="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Credit Limit (Rs.)</label>
                            <input type="number" class="form-control" name="credit_limit" min="0" step="1" value="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check me-1"></i>Add Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function recordPayment(id, name, dues) {
    document.getElementById('pmCustId').value = id;
    document.getElementById('pmCustName').textContent = name;
    document.getElementById('pmDues').textContent = 'Rs. ' + fmtNum(dues);
    document.getElementById('pmAmount').value = Math.ceil(dues);
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}
function addCredit(id, name) {
    document.getElementById('crCustId').value = id;
    document.getElementById('crCustName').textContent = name;
    new bootstrap.Modal(document.getElementById('creditModal')).show();
}
function setLimit(id, name, limit) {
    document.getElementById('lmCustId').value = id;
    document.getElementById('lmCustName').textContent = name;
    document.getElementById('lmAmount').value = limit || 0;
    new bootstrap.Modal(document.getElementById('limitModal')).show();
}
</script>

<?php shopFooter(); ?>

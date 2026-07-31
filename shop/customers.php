<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
$msg = '';

// Ensure customer_credit table exists (MySQL compatible)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS customer_credit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id INT NOT NULL,
        customer_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'credit',
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* table exists */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = safeInt($_POST['cust_id'] ?? 0);
    $name   = sanitize($_POST['name']    ?? '');
    $phone  = sanitize($_POST['phone']   ?? '');
    $email  = sanitize($_POST['email']   ?? '');
    $address= sanitize($_POST['address'] ?? '');
    $notes  = sanitize($_POST['notes']   ?? '');

    if ($action === 'create') {
        if (!$name) { redirect('customers.php', 'Name is required.', 'error'); }
        $db->prepare("INSERT INTO customers (shop_id, name, phone, email, address, notes) VALUES (?,?,?,?,?,?)")
           ->execute([$shopId, $name, $phone, $email, $address, $notes]);
        redirect('customers.php', "Customer '{$name}' added!");
    } elseif ($action === 'update') {
        $db->prepare("UPDATE customers SET name=?, phone=?, email=?, address=?, notes=? WHERE id=? AND shop_id=?")
           ->execute([$name, $phone, $email, $address, $notes, $id, $shopId]);
        redirect('customers.php', 'Customer updated!');
    } elseif ($action === 'delete') {
        $db->prepare("DELETE FROM customers WHERE id=? AND shop_id=?")->execute([$id, $shopId]);
        redirect('customers.php', 'Customer deleted.');
    } elseif ($action === 'add_credit') {
        $custId  = safeInt($_POST['cust_id'] ?? 0);
        $amount  = safeFloat($_POST['amount'] ?? 0);
        $type    = in_array($_POST['credit_type'] ?? '', ['credit','payment']) ? $_POST['credit_type'] : 'credit';
        $desc    = sanitize($_POST['description'] ?? '');
        if ($custId > 0 && $amount > 0) {
            $db->prepare("INSERT INTO customer_credit (shop_id, customer_id, amount, type, description) VALUES (?,?,?,?,?)")
               ->execute([$shopId, $custId, $amount, $type, $desc]);
            if ($type === 'credit') {
                $db->prepare("UPDATE customers SET total_purchases = COALESCE(total_purchases,0) + ? WHERE id=? AND shop_id=?")
                   ->execute([$amount, $custId, $shopId]);
            }
            redirect('customers.php', ucfirst($type).' of Rs. '.number_format($amount).' recorded!');
        }
        redirect('customers.php', 'Invalid amount.', 'error');
    }
}

// Customers list
$search = sanitize($_GET['search'] ?? '');
$q = "SELECT c.*,
    (SELECT COUNT(*) FROM sales WHERE customer_id=c.id) as sale_count,
    (SELECT COALESCE(SUM(CASE WHEN cc.type='credit' THEN cc.amount ELSE -cc.amount END),0)
     FROM customer_credit cc WHERE cc.customer_id=c.id AND cc.shop_id=?) as credit_balance
    FROM customers c WHERE c.shop_id=?";
$params = [$shopId, $shopId];
if ($search) { $q .= " AND c.name LIKE ?"; $params[] = "%{$search}%"; }
$q .= " ORDER BY c.total_purchases DESC";
$stmt = $db->prepare($q);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$totalDues = 0;
foreach ($customers as $c) { if ($c['credit_balance'] > 0) $totalDues += $c['credit_balance']; }

shopHeader('Customers', 'customers');
?>

<?php flashMessage(); ?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-people me-2 text-primary"></i>Retail Customers</h1>
        <p class="page-subtitle"><?= count($customers) ?> customers registered</p>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#custModal" onclick="resetModal()">
        <i class="bi bi-plus me-1"></i>Add Customer
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
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-cash"></i></div>
            <div class="stat-card-value"><?= formatCurrency(array_sum(array_column($customers,'total_purchases'))) ?></div>
            <div class="stat-card-label">Total Purchases</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-danger">
            <div class="stat-card-icon"><i class="bi bi-exclamation-circle"></i></div>
            <div class="stat-card-value"><?= formatCurrency($totalDues) ?></div>
            <div class="stat-card-label">Pending Dues</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-person-check"></i></div>
            <div class="stat-card-value"><?= count(array_filter($customers, fn($c) => $c['credit_balance'] > 0)) ?></div>
            <div class="stat-card-label">Customers with Dues</div>
        </div>
    </div>
</div>

<!-- Search -->
<div class="card mb-3">
    <div class="card-body p-3">
        <form method="GET" class="d-flex gap-2">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search customers...">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="<?= BASE_URL ?>customers.php" class="btn btn-outline-secondary">Clear</a>
        </form>
    </div>
</div>

<!-- Customers Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table" id="custTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Total Purchases</th>
                    <th>Credit Dues</th>
                    <th>Visits</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $i => $c):
                    $hasDues = $c['credit_balance'] > 0;
                ?>
                <tr class="<?= $hasDues ? 'table-warning' : '' ?>">
                    <td><?= $i+1 ?></td>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($c['name']) ?></div>
                        <?php if ($c['email']): ?><small class="text-muted"><?= htmlspecialchars($c['email']) ?></small><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($c['phone'] ?? '-') ?></td>
                    <td class="fw-bold text-success"><?= formatCurrency($c['total_purchases']) ?></td>
                    <td>
                        <?php if ($hasDues): ?>
                        <span class="badge bg-danger">Owes <?= formatCurrency($c['credit_balance']) ?></span>
                        <?php elseif ($c['credit_balance'] < 0): ?>
                        <span class="badge bg-success">Advance: <?= formatCurrency(abs($c['credit_balance'])) ?></span>
                        <?php else: ?>
                        <span class="badge bg-light text-muted">Clear</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge" style="background:rgba(255,255,255,.1);color:#e0d8ff;"><?= $c['visit_count'] ?></span></td>
                    <td>
                        <button onclick="editCust(<?= htmlspecialchars(json_encode($c)) ?>)" class="btn btn-xs btn-outline-primary" style="padding:.2rem .5rem;font-size:.75rem;" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button onclick="showCredit(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>', <?= $c['credit_balance'] ?>)" class="btn btn-xs btn-outline-warning" style="padding:.2rem .5rem;font-size:.75rem;" title="Credit/Dues">
                            <i class="bi bi-cash"></i>
                        </button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="cust_id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger" style="padding:.2rem .5rem;font-size:.75rem;" onclick="return confirm('Delete this customer?')" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($customers)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No customers found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Customer Modal -->
<div class="modal fade" id="custModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="custModalTitle">Add Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" id="custAction" value="create">
                <input type="hidden" name="cust_id" id="custId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Full Name *</label>
                            <input type="text" class="form-control" name="name" id="custName" required placeholder="Customer name">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" class="form-control" name="phone" id="custPhone" placeholder="03xx-xxxxxxx">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" class="form-control" name="email" id="custEmail" placeholder="Optional">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Address</label>
                            <textarea class="form-control" name="address" id="custAddress" rows="2" placeholder="Optional"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" class="form-control" name="notes" id="custNotes" placeholder="Optional">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Credit / Dues Modal -->
<div class="modal fade" id="creditModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash me-2 text-warning"></i>Credit / Dues</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_credit">
                <input type="hidden" name="cust_id" id="creditCustId">
                <div class="modal-body">
                    <p class="fw-semibold mb-1" id="creditCustName"></p>
                    <div class="mb-2 p-2 rounded-2" style="background:#fff8e1;border:1px solid #ffe082;">
                        <small class="text-muted">Current Balance: </small>
                        <span class="fw-bold" id="creditBalance"></span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Transaction Type</label>
                        <div class="d-flex gap-2">
                            <div class="form-check flex-fill">
                                <input class="form-check-input" type="radio" name="credit_type" id="typeCred" value="credit" checked>
                                <label class="form-check-label" for="typeCred">
                                    <span class="badge bg-danger">Give Credit (Owed to You)</span>
                                </label>
                            </div>
                            <div class="form-check flex-fill">
                                <input class="form-check-input" type="radio" name="credit_type" id="typePay" value="payment">
                                <label class="form-check-label" for="typePay">
                                    <span class="badge bg-success">Receive Payment</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount (Rs.) *</label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold">Rs.</span>
                            <input type="number" class="form-control" name="amount" required min="1" step="1" placeholder="e.g. 5000">
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Description (Optional)</label>
                        <input type="text" class="form-control" name="description" placeholder="e.g. Product on credit, Partial payment...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check me-1"></i>Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetModal() {
    document.getElementById('custAction').value = 'create';
    document.getElementById('custModalTitle').textContent = 'Add Customer';
    document.getElementById('custId').value = '';
    ['custName','custPhone','custEmail','custAddress','custNotes'].forEach(id => document.getElementById(id).value = '');
}

function editCust(c) {
    document.getElementById('custAction').value = 'update';
    document.getElementById('custModalTitle').textContent = 'Edit Customer';
    document.getElementById('custId').value = c.id;
    document.getElementById('custName').value = c.name || '';
    document.getElementById('custPhone').value = c.phone || '';
    document.getElementById('custEmail').value = c.email || '';
    document.getElementById('custAddress').value = c.address || '';
    document.getElementById('custNotes').value = c.notes || '';
    new bootstrap.Modal(document.getElementById('custModal')).show();
}

function showCredit(custId, custName, balance) {
    document.getElementById('creditCustId').value = custId;
    document.getElementById('creditCustName').textContent = custName;
    const bal = parseFloat(balance) || 0;
    const balEl = document.getElementById('creditBalance');
    if (bal > 0) {
        balEl.textContent = 'Rs. ' + bal.toLocaleString('en-PK') + ' (OWES YOU)';
        balEl.className = 'fw-bold text-danger';
    } else if (bal < 0) {
        balEl.textContent = 'Rs. ' + Math.abs(bal).toLocaleString('en-PK') + ' (Advance Paid)';
        balEl.className = 'fw-bold text-success';
    } else {
        balEl.textContent = 'Clear (No dues)';
        balEl.className = 'fw-bold text-muted';
    }
    new bootstrap.Modal(document.getElementById('creditModal')).show();
}
</script>
<?php shopFooter(); ?>

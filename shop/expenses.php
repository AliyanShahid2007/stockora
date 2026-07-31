<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';
$db = getDB();
$shopId = (int)$_SESSION['shop_id'];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    
    if ($postAction === 'add_expense') {
        $category = sanitize($_POST['category'] ?? 'Other');
        $description = sanitize($_POST['description'] ?? '');
        $amount = safeFloat($_POST['amount'] ?? 0);
        $date = $_POST['expense_date'] ?? date('Y-m-d');
        $method = sanitize($_POST['payment_method'] ?? 'cash');
        $notes = sanitize($_POST['notes'] ?? '');
        
        if (!$description || $amount <= 0) {
            redirect('expenses.php', 'Description and amount are required.', 'error');
        }
        $db->prepare("INSERT INTO expenses (shop_id, category, description, amount, expense_date, payment_method, notes) VALUES (?,?,?,?,?,?,?)")
           ->execute([$shopId, $category, $description, $amount, $date, $method, $notes]);
        redirect('expenses.php', 'Expense recorded!');
    }
    
    if ($postAction === 'delete_expense') {
        $id = safeInt($_POST['expense_id'] ?? 0);
        $db->prepare("DELETE FROM expenses WHERE id=? AND shop_id=?")->execute([$id, $shopId]);
        redirect('expenses.php', 'Expense deleted.');
    }
}

// Shop creation date for calendar min restriction
$shopDataEx = $db->prepare("SELECT created_at FROM shops WHERE id=?");
$shopDataEx->execute([$shopId]);
$shopDataEx = $shopDataEx->fetch();
$shopCreatedDate = $shopDataEx ? date('Y-m-d', strtotime($shopDataEx['created_at'])) : '2020-01-01';
$todayDate = date('Y-m-d');

// Filters
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? $todayDate;
if ($dateFrom > $todayDate) $dateFrom = $todayDate;
if ($dateTo   > $todayDate) $dateTo   = $todayDate;
if ($dateFrom < $shopCreatedDate) $dateFrom = $shopCreatedDate;
if ($dateTo   < $shopCreatedDate) $dateTo   = $shopCreatedDate;
$filterCat = sanitize($_GET['cat'] ?? '');

$sql = "SELECT * FROM expenses WHERE shop_id=? AND expense_date BETWEEN ? AND ?";
$params = [$shopId, $dateFrom, $dateTo];
if ($filterCat) { $sql .= " AND category=?"; $params[] = $filterCat; }
$sql .= " ORDER BY expense_date DESC, created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$totalExpenses = array_sum(array_column($expenses, 'amount'));

// Category breakdown
$catBreakdown = $db->prepare("SELECT category, SUM(amount) as total, COUNT(*) as count FROM expenses WHERE shop_id=? AND expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$catBreakdown->execute([$shopId, $dateFrom, $dateTo]);
$catBreakdown = $catBreakdown->fetchAll();

// Sales vs expenses comparison
$stmt2 = $db->prepare("SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE shop_id=? AND DATE(sale_date) BETWEEN ? AND ?");
$stmt2->execute([$shopId, $dateFrom, $dateTo]);
$salesTotal = (float)$stmt2->fetchColumn();

$expenseCategories = ['Rent', 'Electricity', 'Salary', 'Transport', 'Supplies', 'Maintenance', 'Marketing', 'Internet', 'Other'];

shopHeader('Expense Tracker', 'expenses');
?>
<?php flashMessage(); ?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-wallet2 me-2 text-danger"></i>Expense Tracker</h1>
        <p class="page-subtitle">Track and manage all shop expenses</p>
    </div>
    <button class="btn btn-danger" onclick="document.getElementById('addExpenseModal').classList.add('show'); document.getElementById('addExpenseModal').style.display='block'; document.body.classList.add('modal-open');" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
        <i class="bi bi-plus-circle me-1"></i>Add Expense
    </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-danger">
            <div class="stat-card-icon"><i class="bi bi-wallet2"></i></div>
            <div class="stat-card-value"><?= formatCurrency($totalExpenses) ?></div>
            <div class="stat-card-label">Total Expenses</div>
            <div class="stat-card-change"><?= count($expenses) ?> entries</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-cash"></i></div>
            <div class="stat-card-value"><?= formatCurrency($salesTotal) ?></div>
            <div class="stat-card-label">Sales Revenue</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card <?= ($salesTotal - $totalExpenses) >= 0 ? 'stat-success' : 'stat-danger' ?>">
            <div class="stat-card-icon"><i class="bi bi-graph-up"></i></div>
            <div class="stat-card-value"><?= formatCurrency($salesTotal - $totalExpenses) ?></div>
            <div class="stat-card-label">Net After Expenses</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-percent"></i></div>
            <div class="stat-card-value"><?= $salesTotal > 0 ? round(($totalExpenses/$salesTotal)*100, 1) : 0 ?>%</div>
            <div class="stat-card-label">Expense Ratio</div>
            <div class="stat-card-change">of total sales</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Category Breakdown -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-bold"><i class="bi bi-pie-chart me-2"></i>By Category</div>
            <div class="card-body p-3">
                <?php if (empty($catBreakdown)): ?>
                <p class="text-muted text-center py-3">No expenses in this period</p>
                <?php else: ?>
                <?php foreach ($catBreakdown as $cat): ?>
                <?php $pct = $totalExpenses > 0 ? round(($cat['total']/$totalExpenses)*100) : 0; ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small fw-semibold"><?= htmlspecialchars($cat['category']) ?></span>
                        <span class="small fw-bold"><?= formatCurrency($cat['total']) ?></span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar bg-danger" style="width:<?= $pct ?>%"></div>
                    </div>
                    <small class="text-muted"><?= $cat['count'] ?> entries • <?= $pct ?>%</small>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Expenses List -->
    <div class="col-md-8">
        <div class="card">
            <!-- Filters -->
            <div class="card-header p-3">
                <form method="GET" class="row g-2">
                    <div class="col-5">
                        <input type="date" class="form-control form-control-sm" name="from" value="<?= $dateFrom ?>" min="<?= $shopCreatedDate ?>" max="<?= $todayDate ?>">
                    </div>
                    <div class="col-5">
                        <input type="date" class="form-control form-control-sm" name="to" value="<?= $dateTo ?>" min="<?= $shopCreatedDate ?>" max="<?= $todayDate ?>">
                    </div>
                    <div class="col-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i></button>
                    </div>
                    <div class="col-12">
                        <div class="d-flex gap-1 flex-wrap">
                            <a href="<?= BASE_URL ?>?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-xs btn-outline-secondary" style="font-size:0.72rem;padding:0.15rem 0.5rem;">Today</a>
                            <a href="<?= BASE_URL ?>?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-xs btn-outline-secondary" style="font-size:0.72rem;padding:0.15rem 0.5rem;">This Month</a>
                            <a href="<?= BASE_URL ?>?from=<?= date('Y-m-01', strtotime('first day of last month')) ?>&to=<?= date('Y-m-t', strtotime('first day of last month')) ?>" class="btn btn-xs btn-outline-secondary" style="font-size:0.72rem;padding:0.15rem 0.5rem;">Last Month</a>
                        </div>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Method</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                        <tr>
                            <td><small><?= date('d M', strtotime($exp['expense_date'])) ?></small></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($exp['category']) ?></span></td>
                            <td>
                                <div class="small fw-semibold"><?= htmlspecialchars($exp['description']) ?></div>
                                <?php if ($exp['notes']): ?><small class="text-muted"><?= htmlspecialchars($exp['notes']) ?></small><?php endif; ?>
                            </td>
                            <td class="fw-bold text-danger"><?= formatCurrency($exp['amount']) ?></td>
                            <td><small class="text-muted"><?= ucfirst($exp['payment_method']) ?></small></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this expense?')">
                                    <input type="hidden" name="action" value="delete_expense">
                                    <input type="hidden" name="expense_id" value="<?= $exp['id'] ?>">
                                    <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:0.7rem;padding:0.15rem 0.4rem;"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($expenses)): ?>
                        <tr><td colspan="6" class="text-center py-4">
                            <div class="empty-state">
                                <div class="empty-state-icon small"><i class="bi bi-wallet2"></i></div>
                                <p class="text-muted mb-0">No expenses recorded in this period.</p>
                            </div>
                        </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Expense</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_expense">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Category *</label>
                            <select class="form-select" name="category" required>
                                <?php foreach ($expenseCategories as $cat): ?>
                                <option value="<?= $cat ?>"><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Date *</label>
                            <input type="date" class="form-control" name="expense_date" value="<?= $todayDate ?>" min="<?= $shopCreatedDate ?>" max="<?= $todayDate ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Description *</label>
                            <input type="text" class="form-control" name="description" required placeholder="e.g. Shop rent for April">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Amount (Rs.) *</label>
                            <input type="number" class="form-control" name="amount" min="1" step="1" required placeholder="e.g. 15000">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank</option>
                                <option value="easypaisa">EasyPaisa</option>
                                <option value="jazzcash">JazzCash</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Notes (optional)</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Additional details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-check-circle me-1"></i>Save Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php shopFooter(); ?>

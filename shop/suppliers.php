<?php
require_once '../includes/functions.php';
requireShop();

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
ensureSupplierSchema($db);
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_supplier') {
            $id = safeInt($_POST['id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $phone = sanitize($_POST['phone'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $address = sanitize($_POST['address'] ?? '');
            $opening = safeFloat($_POST['opening_balance'] ?? 0);
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($name === '') throw new Exception('Supplier name is required.');
            if ($id) {
                $stmt = $db->prepare('UPDATE suppliers SET name=?,phone=?,email=?,address=?,opening_balance=?,status=? WHERE id=? AND shop_id=?');
                $stmt->execute([$name, $phone ?: null, $email ?: null, $address ?: null, $opening, $status, $id, $shopId]);
                if (!$stmt->rowCount()) throw new Exception('Supplier was not found.');
                redirect('suppliers.php', 'Supplier updated successfully.');
            }
            $db->prepare('INSERT INTO suppliers (shop_id,name,phone,email,address,opening_balance,status) VALUES (?,?,?,?,?,?,?)')
                ->execute([$shopId, $name, $phone ?: null, $email ?: null, $address ?: null, $opening, $status]);
            redirect('suppliers.php', 'Supplier added successfully.');
        }
        if ($action === 'add_payment') {
            $supplierId = safeInt($_POST['supplier_id'] ?? 0);
            $amount = safeFloat($_POST['amount'] ?? 0);
            $date = $_POST['payment_date'] ?? $today;
            $method = in_array($_POST['payment_method'] ?? '', ['cash', 'bank', 'card', 'other'], true) ? $_POST['payment_method'] : 'cash';
            $reference = sanitize($_POST['reference_no'] ?? '');
            $notes = sanitize($_POST['notes'] ?? '');
            if (!$supplierId || $amount <= 0) throw new Exception('Choose a supplier and enter a valid payment amount.');
            $check = $db->prepare('SELECT id FROM suppliers WHERE id=? AND shop_id=? AND status="active"');
            $check->execute([$supplierId, $shopId]);
            if (!$check->fetch()) throw new Exception('Selected supplier is not active.');
            $db->prepare('INSERT INTO supplier_payments (shop_id,supplier_id,amount,payment_date,payment_method,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$shopId, $supplierId, $amount, $date, $method, $reference ?: null, $notes ?: null, $_SESSION['user_id']]);
            redirect('suppliers.php', 'Supplier payment recorded successfully.');
        }
    } catch (Exception $e) {
        redirect('suppliers.php', $e->getMessage(), 'error');
    }
}

$supplierSql = "SELECT s.*, COALESCE(p.purchased,0) purchased, COALESCE(p.paid_on_purchase,0) paid_on_purchase,
    COALESCE(sp.paid,0) paid, (s.opening_balance + COALESCE(p.purchased,0) - COALESCE(p.paid_on_purchase,0) - COALESCE(sp.paid,0)) due
    FROM suppliers s
    LEFT JOIN (SELECT supplier_id,SUM(total_amount) purchased,SUM(amount_paid) paid_on_purchase FROM (
        SELECT supplier_id,total_amount,amount_paid FROM purchase_invoices WHERE shop_id=?
        UNION ALL SELECT supplier_id,total_amount,amount_paid FROM purchases WHERE shop_id=? AND purchase_invoice_id IS NULL
    ) purchase_totals GROUP BY supplier_id) p ON p.supplier_id=s.id
    LEFT JOIN (SELECT supplier_id,SUM(amount) paid FROM supplier_payments WHERE shop_id=? GROUP BY supplier_id) sp ON sp.supplier_id=s.id
    WHERE s.shop_id=? ORDER BY s.status='active' DESC,s.name";
$stmt = $db->prepare($supplierSql);
$stmt->execute([$shopId, $shopId, $shopId, $shopId]);
$suppliers = $stmt->fetchAll();
$totalDue = array_sum(array_map(fn($s) => max(0, (float)$s['due']), $suppliers));
$totalCredit = abs(array_sum(array_map(fn($s) => min(0, (float)$s['due']), $suppliers)));
$viewId = safeInt($_GET['supplier_id'] ?? 0);
$history = [];
if ($viewId) {
    $hist = $db->prepare("SELECT 'Purchase' type, pi.purchase_date entry_date, pi.invoice_no reference_no, pi.total_amount amount, pi.amount_paid paid, pi.notes, (SELECT GROUP_CONCAT(CONCAT(pr.name,' ×',p.quantity) ORDER BY pr.name SEPARATOR ' | ') FROM purchases p JOIN products pr ON pr.id=p.product_id WHERE p.purchase_invoice_id=pi.id) items FROM purchase_invoices pi WHERE pi.shop_id=? AND pi.supplier_id=?
        UNION ALL SELECT 'Purchase', pu.purchase_date, pu.invoice_no, pu.total_amount, pu.amount_paid, pu.notes, CONCAT(pr.name,' ×',pu.quantity) FROM purchases pu JOIN products pr ON pr.id=pu.product_id WHERE pu.shop_id=? AND pu.supplier_id=? AND pu.purchase_invoice_id IS NULL
        UNION ALL SELECT 'Payment', payment_date, reference_no, 0, amount, notes, NULL FROM supplier_payments WHERE shop_id=? AND supplier_id=? ORDER BY entry_date DESC");
    $hist->execute([$shopId, $viewId, $shopId, $viewId, $shopId, $viewId]);
    $history = $hist->fetchAll();
}

require_once '../includes/shop_layout.php';
shopHeader('Suppliers & Dues', 'suppliers');
?>
<?php flashMessage(); ?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div><h1 class="page-title"><i class="bi bi-person-vcard me-2 text-primary"></i>Suppliers &amp; Dues</h1><p class="page-subtitle">Manage vendors, purchase liabilities and payments</p></div>
  <button class="btn btn-primary" onclick="openSupplier()"><i class="bi bi-plus-lg me-1"></i>Add Supplier</button>
</div>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="stat-card stat-primary"><div class="stat-card-value"><?= count($suppliers) ?></div><div class="stat-card-label">Total Suppliers</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card stat-danger"><div class="stat-card-value"><?= formatCurrency($totalDue) ?></div><div class="stat-card-label">Outstanding Dues</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card stat-success"><div class="stat-card-value"><?= formatCurrency($totalCredit) ?></div><div class="stat-card-label">Supplier Credit</div></div></div>
  <div class="col-6 col-md-3"><a href="purchases.php" class="text-decoration-none"><div class="stat-card stat-warning"><div class="stat-card-value"><i class="bi bi-box-arrow-in-down"></i></div><div class="stat-card-label">New Purchase</div></div></a></div>
</div>
<div class="card"><div class="card-header"><i class="bi bi-building me-2"></i>Supplier Directory</div><div class="table-responsive"><table class="table align-middle mb-0">
<thead><tr><th>Supplier</th><th>Contact</th><th>Purchases</th><th>Paid</th><th>Due</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php foreach ($suppliers as $s): ?><tr>
 <td><strong><?= htmlspecialchars($s['name']) ?></strong><br><small class="text-muted"><?= $s['status']==='active' ? 'Active' : 'Inactive' ?></small></td>
 <td><small><?= htmlspecialchars($s['phone'] ?: '-') ?><br><?= htmlspecialchars($s['email'] ?: '') ?></small></td>
 <td><?= formatCurrency($s['purchased']) ?></td><td><?= formatCurrency($s['paid_on_purchase'] + $s['paid']) ?></td>
 <td class="fw-bold <?= $s['due']>0?'text-danger':'text-success' ?>"><?= formatCurrency(abs($s['due'])) ?> <?= $s['due']<0?'Cr':'' ?></td>
 <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="?supplier_id=<?= $s['id'] ?>" title="History"><i class="bi bi-clock-history"></i></a>
  <?php if ($s['status']==='active'): ?><button class="btn btn-sm btn-success" onclick="openPayment(<?= $s['id'] ?>,'<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>',<?= max(0,(float)$s['due']) ?>)"><i class="bi bi-cash-stack"></i></button><?php endif; ?>
  <button class="btn btn-sm btn-outline-primary" onclick='openSupplier(<?= json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button></td>
</tr><?php endforeach; ?>
<?php if (!$suppliers): ?><tr><td colspan="6" class="text-center text-muted py-4">No suppliers yet. Add a supplier before recording purchases.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if ($viewId): ?><div class="card mt-3"><div class="card-header d-flex justify-content-between"><span><i class="bi bi-clock-history me-2"></i>Supplier Ledger</span><a href="suppliers.php" class="btn btn-sm btn-outline-secondary">Close</a></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Items / Qty</th><th>Purchase</th><th>Payment</th><th>Notes</th></tr></thead><tbody><?php foreach($history as $h): ?><tr><td><?= date('d M Y',strtotime($h['entry_date'])) ?></td><td><?= $h['type'] ?></td><td><?= htmlspecialchars($h['reference_no'] ?: '-') ?></td><td><small style="min-width:220px;display:block;white-space:normal;"><?= htmlspecialchars($h['items'] ?: '-') ?></small></td><td><?= $h['amount']?formatCurrency($h['amount']):'-' ?></td><td><?= $h['paid']?formatCurrency($h['paid']):'-' ?></td><td><?= htmlspecialchars($h['notes'] ?: '-') ?></td></tr><?php endforeach; ?><?php if(!$history): ?><tr><td colspan="7" class="text-center text-muted py-3">No entries found.</td></tr><?php endif; ?></tbody></table></div></div><?php endif; ?>

<div class="modal fade" id="supplierModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><input type="hidden" name="action" value="save_supplier"><input type="hidden" name="id" id="supplierId"><div class="modal-header"><h5 class="modal-title" id="supplierTitle">Add Supplier</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div><div class="modal-body row g-3"><div class="col-12"><label class="form-label">Supplier Name *</label><input class="form-control" name="name" id="supplierName" required></div><div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" id="supplierPhone"></div><div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" id="supplierEmail"></div><div class="col-md-6"><label class="form-label">Opening Due (Rs.)</label><input type="number" min="0" step="0.01" class="form-control" name="opening_balance" id="supplierOpening" value="0"></div><div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status" id="supplierStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="address" id="supplierAddress" rows="2"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Supplier</button></div></form></div></div>
<div class="modal fade" id="paymentModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><input type="hidden" name="action" value="add_payment"><input type="hidden" name="supplier_id" id="paymentSupplierId"><div class="modal-header"><h5 class="modal-title">Record Supplier Payment</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div><div class="modal-body row g-3"><div class="col-12"><div class="mb-0 rounded px-3 py-2" id="paymentSupplier" style="background:rgba(14,206,206,.10);border:1px solid rgba(14,206,206,.28);color:#bff9f9;"></div></div><div class="col-md-6"><label class="form-label">Amount *</label><input type="number" min="0.01" step="0.01" class="form-control" name="amount" id="paymentAmount" required></div><div class="col-md-6"><label class="form-label">Date *</label><input type="date" class="form-control" name="payment_date" value="<?= $today ?>" required></div><div class="col-md-6"><label class="form-label">Method</label><select class="form-select" name="payment_method"><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="card">Card</option><option value="other">Other</option></select></div><div class="col-md-6"><label class="form-label">Reference No.</label><input class="form-control" name="reference_no"></div><div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success">Save Payment</button></div></form></div></div>
<script>
function openSupplier(s){s=s||{};['Id','Name','Phone','Email','Address','Opening'].forEach(k=>{const e=document.getElementById('supplier'+k);if(e)e.value=s[{Id:'id',Name:'name',Phone:'phone',Email:'email',Address:'address',Opening:'opening_balance'}[k]]||''});document.getElementById('supplierOpening').value=s.opening_balance||0;document.getElementById('supplierStatus').value=s.status||'active';document.getElementById('supplierTitle').textContent=s.id?'Edit Supplier':'Add Supplier';bootstrap.Modal.getOrCreateInstance(document.getElementById('supplierModal')).show()}
function openPayment(id,name,due){document.getElementById('paymentSupplierId').value=id;document.getElementById('paymentSupplier').textContent=name+' — outstanding due: Rs. '+fmtNum(due);document.getElementById('paymentAmount').value=due||'';bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal')).show()}
</script>
<?php shopFooter(); ?>

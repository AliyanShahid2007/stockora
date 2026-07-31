<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
ensureSupplierSchema($db);
$todayDate = date('Y-m-d');
$preProductId = safeInt($_GET['product_id'] ?? 0);
$created = $db->prepare('SELECT created_at FROM shops WHERE id=?'); $created->execute([$shopId]);
$shopCreatedDate = date('Y-m-d', strtotime($created->fetchColumn() ?: '2020-01-01'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierId = safeInt($_POST['supplier_id'] ?? 0);
    $invoiceNo = sanitize($_POST['invoice_no'] ?? '');
    $purchaseDate = $_POST['purchase_date'] ?? $todayDate;
    $amountPaid = safeFloat($_POST['amount_paid'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');
    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unitPrices = $_POST['unit_price'] ?? [];
    try {
        if (!$supplierId) throw new Exception('Please select a supplier.');
        if ($invoiceNo === '') throw new Exception('Supplier invoice number is required before stock can be added.');
        if (!is_array($productIds) || !count($productIds)) throw new Exception('Add at least one product to the invoice.');
        if ($purchaseDate < $shopCreatedDate || $purchaseDate > $todayDate) throw new Exception('Enter a valid purchase date.');
        $supplier = $db->prepare("SELECT name FROM suppliers WHERE id=? AND shop_id=? AND status='active'");
        $supplier->execute([$supplierId, $shopId]); $supplierName = $supplier->fetchColumn();
        if (!$supplierName) throw new Exception('Selected supplier is not active or does not exist.');
        $lines = []; $seenProducts = [];
        foreach ($productIds as $i => $rawProductId) {
            $productId = safeInt($rawProductId); $qty = safeInt($quantities[$i] ?? 0); $price = safeFloat($unitPrices[$i] ?? 0);
            if (!$productId || $qty <= 0 || $price <= 0) throw new Exception('Every invoice item needs a product, quantity and unit price.');
            if (isset($seenProducts[$productId])) throw new Exception('Add each product only once; combine its quantity in one row.');
            $seenProducts[$productId] = true; $lines[] = ['product_id'=>$productId,'quantity'=>$qty,'unit_price'=>$price,'total'=>$qty*$price];
        }
        $total = array_sum(array_column($lines, 'total'));
        if ($amountPaid < 0 || $amountPaid > $total) throw new Exception('Paid amount must be between zero and the invoice total.');
        $db->beginTransaction();
        $old = $db->prepare('SELECT id FROM purchases WHERE shop_id=? AND invoice_no=? LIMIT 1'); $old->execute([$shopId, $invoiceNo]);
        if ($old->fetch()) throw new Exception('This supplier invoice number has already been entered.');
        $invoice = $db->prepare('INSERT INTO purchase_invoices (shop_id,supplier_id,supplier_name,invoice_no,total_amount,amount_paid,purchase_date,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $invoice->execute([$shopId,$supplierId,$supplierName,$invoiceNo,$total,$amountPaid,$purchaseDate,$notes ?: null,$_SESSION['user_id']]);
        $invoiceId = (int)$db->lastInsertId();
        $getProduct = $db->prepare('SELECT id,name,stock_quantity FROM products WHERE id=? AND shop_id=? FOR UPDATE');
        $addPurchase = $db->prepare('INSERT INTO purchases (purchase_invoice_id,shop_id,product_id,supplier_id,supplier_name,quantity,unit_price,total_amount,amount_paid,purchase_date,invoice_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $updateStock = $db->prepare('UPDATE products SET stock_quantity=?,company_price=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND shop_id=?');
        $movement = $db->prepare("INSERT INTO stock_movements (shop_id,product_id,movement_type,quantity,before_quantity,after_quantity,notes,created_by) VALUES (?,?,'purchase',?,?,?,?,?)");
        foreach ($lines as $line) {
            $getProduct->execute([$line['product_id'], $shopId]); $product = $getProduct->fetch();
            if (!$product) throw new Exception('One of the selected products no longer exists.');
            $before = (int)$product['stock_quantity']; $after = $before + $line['quantity'];
            $addPurchase->execute([$invoiceId,$shopId,$line['product_id'],$supplierId,$supplierName,$line['quantity'],$line['unit_price'],$line['total'],0,$purchaseDate,$invoiceNo,$notes ?: null,$_SESSION['user_id']]);
            $updateStock->execute([$after,$line['unit_price'],$line['product_id'],$shopId]);
            $movement->execute([$shopId,$line['product_id'],$line['quantity'],$before,$after,'Purchase invoice: '.$invoiceNo,$_SESSION['user_id']]);
        }
        $db->commit();
        redirect('purchases.php', 'Purchase invoice saved. '.count($lines).' product(s) added to stock.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        redirect('purchases.php', $e->getMessage(), 'error');
    }
}

$products = $db->prepare("SELECT p.id,p.name,p.company_price,p.stock_quantity,p.unit,c.name cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.shop_id=? AND p.status='active' ORDER BY p.name");
$products->execute([$shopId]); $products = $products->fetchAll();
$suppliers = $db->prepare("SELECT id,name FROM suppliers WHERE shop_id=? AND status='active' ORDER BY name"); $suppliers->execute([$shopId]); $suppliers = $suppliers->fetchAll();
$historyPage = max(1, safeInt($_GET['page'] ?? 1));
$historyPerPage = 10;
$historyCount = $db->prepare('SELECT (SELECT COUNT(*) FROM purchase_invoices WHERE shop_id=?) + (SELECT COUNT(*) FROM purchases WHERE shop_id=? AND purchase_invoice_id IS NULL)');
$historyCount->execute([$shopId, $shopId]);
$historyTotal = (int)$historyCount->fetchColumn();
$historyPages = max(1, (int)ceil($historyTotal / $historyPerPage));
if ($historyPage > $historyPages) $historyPage = $historyPages;
$historyOffset = ($historyPage - 1) * $historyPerPage;
$history = $db->prepare("SELECT pi.*, (SELECT COUNT(*) FROM purchases p WHERE p.purchase_invoice_id=pi.id) item_count, (SELECT GROUP_CONCAT(CONCAT(pr.name,' ×',p.quantity) ORDER BY pr.name SEPARATOR ' | ') FROM purchases p JOIN products pr ON pr.id=p.product_id WHERE p.purchase_invoice_id=pi.id) item_summary FROM purchase_invoices pi WHERE pi.shop_id=? UNION ALL SELECT 0,pu.shop_id,pu.supplier_id,pu.supplier_name,pu.invoice_no,pu.total_amount,pu.amount_paid,pu.purchase_date,pu.notes,pu.created_by,pu.created_at,1,CONCAT(pr.name,' ×',pu.quantity) FROM purchases pu JOIN products pr ON pr.id=pu.product_id WHERE pu.shop_id=? AND pu.purchase_invoice_id IS NULL ORDER BY created_at DESC LIMIT {$historyPerPage} OFFSET {$historyOffset}");
$history->execute([$shopId,$shopId]); $history = $history->fetchAll();
shopHeader('Purchase Entry', 'purchases');
?>
<?php flashMessage(); ?>
<div class="page-header"><h1 class="page-title"><i class="bi bi-truck me-2 text-primary"></i>Purchase Invoice</h1><p class="page-subtitle">One supplier invoice can add stock for multiple products</p></div>
<div class="row g-3"><div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-file-earmark-plus me-2 text-success"></i>New Purchase Invoice</div><div class="card-body"><form method="post" id="purchaseForm">
<div class="row g-2 mb-3"><div class="col-md-5"><label class="form-label">Supplier *</label><div class="input-group"><select class="form-select" name="supplier_id" required><option value="">Select supplier...</option><?php foreach($suppliers as $supplier): ?><option value="<?= $supplier['id'] ?>"><?= htmlspecialchars($supplier['name']) ?></option><?php endforeach; ?></select><a href="suppliers.php" class="btn btn-outline-primary" title="Add supplier"><i class="bi bi-plus-lg"></i></a></div></div><div class="col-md-4"><label class="form-label">Invoice No. *</label><input class="form-control" name="invoice_no" required maxlength="100" placeholder="Supplier invoice #"></div><div class="col-md-3"><label class="form-label">Purchase Date *</label><input type="date" class="form-control" name="purchase_date" value="<?= $todayDate ?>" min="<?= $shopCreatedDate ?>" max="<?= $todayDate ?>" required></div></div>
<div class="table-responsive"><table class="table align-middle" id="lineTable"><thead><tr><th style="min-width:230px">Product *</th><th style="min-width:100px">Qty *</th><th style="min-width:130px">Unit Cost *</th><th>Total</th><th></th></tr></thead><tbody id="lineItems"></tbody></table></div><button type="button" class="btn btn-sm btn-outline-primary" onclick="addLine()"><i class="bi bi-plus-lg me-1"></i>Add Product</button>
<div class="row g-2 mt-3"><div class="col-md-4"><label class="form-label">Amount Paid Now (Rs.)</label><input type="number" min="0" step="0.01" class="form-control" name="amount_paid" id="amountPaid" value="0"></div><div class="col-md-4"><div class="bg-light rounded p-2 text-center h-100"><small class="text-muted">Invoice Total</small><div class="fw-bold text-primary" id="invoiceTotal">Rs. 0</div></div></div><div class="col-md-4"><div class="bg-light rounded p-2 text-center h-100"><small class="text-muted">Supplier Due</small><div class="fw-bold text-danger" id="invoiceDue">Rs. 0</div></div></div><div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2" placeholder="Optional invoice notes"></textarea></div></div>
<button class="btn btn-success w-100 mt-3" <?= !$suppliers?'disabled':'' ?>><i class="bi bi-check2-circle me-1"></i>Save Invoice &amp; Add Stock</button><?php if(!$suppliers): ?><small class="text-danger d-block mt-2">Add a supplier first to record a purchase.</small><?php endif; ?></form></div></div></div>
<div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-clock-history me-2 text-primary"></i>Recent Purchase Invoices</span><small class="text-muted"><?= $historyTotal ?> total</small></div><div class="table-responsive"><table class="table"><thead><tr><th>Invoice</th><th>Supplier</th><th>Items</th><th>Total</th><th>Due</th></tr></thead><tbody><?php foreach($history as $row): ?><tr><td><strong><?= htmlspecialchars($row['invoice_no']) ?></strong><br><small class="text-muted"><?= date('d M',strtotime($row['purchase_date'])) ?></small></td><td><small><?= htmlspecialchars($row['supplier_name']) ?></small></td><td><span class="badge bg-primary"><?= $row['item_count'] ?> item<?= $row['item_count'] != 1 ? 's' : '' ?></span><div class="small text-muted mt-1" style="min-width:220px;white-space:normal;"><?= htmlspecialchars($row['item_summary'] ?: '-') ?></div></td><td><?= formatCurrency($row['total_amount']) ?></td><td class="<?= $row['total_amount']-$row['amount_paid']>0?'text-danger':'text-success' ?> fw-semibold"><?= formatCurrency($row['total_amount']-$row['amount_paid']) ?></td></tr><?php endforeach; ?><?php if(!$history): ?><tr><td colspan="5" class="text-center text-muted py-4">No purchase invoices yet</td></tr><?php endif; ?></tbody></table></div><?php if($historyTotal > $historyPerPage): ?><div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2"><small class="text-muted">Showing <?= $historyOffset + 1 ?>–<?= min($historyOffset + $historyPerPage, $historyTotal) ?> of <?= $historyTotal ?></small><nav><ul class="pagination pagination-sm mb-0" style="gap:6px;"><li class="page-item <?= $historyPage<=1?'disabled':'' ?>"><a class="page-link rounded" href="?page=<?= $historyPage-1 ?>" aria-label="Previous page" title="Previous page"><i class="bi bi-chevron-left"></i></a></li><?php for($p=max(1,$historyPage-2);$p<=min($historyPages,$historyPage+2);$p++): ?><li class="page-item <?= $p===$historyPage?'active':'' ?>"><a class="page-link rounded" href="?page=<?= $p ?>"><?= $p ?></a></li><?php endfor; ?><li class="page-item <?= $historyPage>=$historyPages?'disabled':'' ?>"><a class="page-link rounded" href="?page=<?= $historyPage+1 ?>" aria-label="Next page" title="Next page"><i class="bi bi-chevron-right"></i></a></li></ul></nav></div><?php endif; ?></div></div></div>
<script>
const products=<?= json_encode($products, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const preProductId=<?= $preProductId ?>;
function options(){return '<option value="">Select product...</option>'+products.map(p=>'<option value="'+p.id+'" data-price="'+p.company_price+'">'+esc(p.name)+' ('+esc(p.cat_name||'')+')</option>').join('')}
function esc(v){const d=document.createElement('div');d.textContent=v;return d.innerHTML}
function addLine(productId){const tr=document.createElement('tr');tr.innerHTML='<td><select class="form-select product-select" name="product_id[]" required onchange="productChanged(this)">'+options()+'</select></td><td><input class="form-control qty" type="number" name="quantity[]" min="1" value="1" required oninput="calculate()"></td><td><input class="form-control price" type="number" name="unit_price[]" min="0.01" step="0.01" required oninput="calculate()"></td><td class="line-total fw-semibold">Rs. 0</td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLine(this)"><i class="bi bi-trash"></i></button></td>';document.getElementById('lineItems').appendChild(tr);if(productId){tr.querySelector('.product-select').value=productId;productChanged(tr.querySelector('.product-select'))}}
function productChanged(select){const opt=select.options[select.selectedIndex];if(opt.dataset.price) select.closest('tr').querySelector('.price').value=opt.dataset.price;calculate()}
function removeLine(button){const rows=document.querySelectorAll('#lineItems tr');if(rows.length>1)button.closest('tr').remove();calculate()}
function calculate(){let total=0;document.querySelectorAll('#lineItems tr').forEach(tr=>{const amount=(parseFloat(tr.querySelector('.qty').value)||0)*(parseFloat(tr.querySelector('.price').value)||0);tr.querySelector('.line-total').textContent='Rs. '+fmtNum(amount);total+=amount});const paid=parseFloat(document.getElementById('amountPaid').value)||0;document.getElementById('invoiceTotal').textContent='Rs. '+fmtNum(total);document.getElementById('invoiceDue').textContent='Rs. '+fmtNum(Math.max(0,total-paid))}
document.getElementById('amountPaid').addEventListener('input',calculate);addLine(preProductId||null);
</script>
<button type="button" id="backToTop" class="back-to-top" aria-label="Back to top" title="Back to top"><i class="bi bi-arrow-up"></i></button>
<style>.back-to-top{position:fixed;right:24px;bottom:24px;width:44px;height:44px;border:0;border-radius:50%;z-index:1050;background:linear-gradient(135deg,#6C63FF,#3ECFCF);color:#fff;box-shadow:0 8px 24px rgba(60,207,207,.3);opacity:0;visibility:hidden;transform:translateY(14px);transition:opacity .25s ease,transform .25s ease,visibility .25s}.back-to-top.show{opacity:1;visibility:visible;transform:translateY(0);animation:topPulse 1.8s ease-in-out infinite}.back-to-top:hover{filter:brightness(1.1)}@keyframes topPulse{50%{box-shadow:0 8px 30px rgba(108,99,255,.65);transform:translateY(-3px)}}</style>
<script>const backToTop=document.getElementById('backToTop');window.addEventListener('scroll',()=>backToTop.classList.toggle('show',window.scrollY>350));backToTop.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));</script>
<?php shopFooter(); ?>

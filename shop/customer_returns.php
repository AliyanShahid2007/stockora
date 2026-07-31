<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$userId = (int)($_SESSION['user_id'] ?? 0);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_return') {
    $saleId = safeInt($_POST['sale_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $refundMethod = in_array($_POST['refund_method'] ?? '', ['cash','card','credit','exchange'], true) ? $_POST['refund_method'] : 'cash';
    $requested = $_POST['qty'] ?? [];
    try {
        $db->beginTransaction();
        $saleStmt = $db->prepare("SELECT id, customer_name, subtotal, grand_total FROM sales WHERE id=? AND shop_id=? FOR UPDATE");
        $saleStmt->execute([$saleId, $shopId]);
        $sale = $saleStmt->fetch();
        if (!$sale) throw new RuntimeException('Sale not found.');
        $itemsStmt = $db->prepare("SELECT si.*, COALESCE((SELECT SUM(cri.quantity) FROM customer_return_items cri JOIN customer_returns cr ON cr.id=cri.return_id WHERE cri.sale_item_id=si.id),0) returned_qty
            FROM sale_items si WHERE si.sale_id=? FOR UPDATE");
        $itemsStmt->execute([$saleId]);
        $returnItems = [];
        $refund = 0;
        // Apply invoice-level discounts and tax proportionally, so all item
        // returns together always total the amount charged on the invoice.
        $refundFactor = (float)$sale['subtotal'] > 0
            ? max(0, (float)$sale['grand_total'] / (float)$sale['subtotal'])
            : 1;
        foreach ($itemsStmt->fetchAll() as $item) {
            $qty = max(0, safeInt($requested[$item['id']] ?? 0));
            $available = (int)$item['quantity'] - (int)$item['returned_qty'];
            if ($qty > $available) throw new RuntimeException($item['product_name'] . ' can only be returned up to ' . $available . ' unit(s).');
            if ($qty > 0) {
                $refundUnitPrice = ((float)$item['total_price'] / max(1, (int)$item['quantity'])) * $refundFactor;
                $amount = round($qty * $refundUnitPrice, 2);
                $returnItems[] = [$item, $qty, $refundUnitPrice, $amount];
                $refund += $amount;
            }
        }
        if (!$returnItems) throw new RuntimeException('Enter a quantity for at least one product.');
        $returnStmt = $db->prepare("INSERT INTO customer_returns (shop_id,sale_id,customer_name,reason,refund_method,refund_amount,created_by) VALUES (?,?,?,?,?,?,?)");
        $returnStmt->execute([$shopId,$saleId,$sale['customer_name'],$reason,$refundMethod,$refund,$userId ?: null]);
        $returnId = (int)$db->lastInsertId();
        $itemInsert = $db->prepare("INSERT INTO customer_return_items (return_id,sale_item_id,product_id,product_name,quantity,unit_price,refund_amount) VALUES (?,?,?,?,?,?,?)");
        $stockStmt = $db->prepare("SELECT stock_quantity FROM products WHERE id=? AND shop_id=? FOR UPDATE");
        $stockUpdate = $db->prepare("UPDATE products SET stock_quantity=stock_quantity+? WHERE id=? AND shop_id=?");
        $movement = $db->prepare("INSERT INTO stock_movements (shop_id,product_id,movement_type,quantity,before_quantity,after_quantity,reference_id,notes,created_by) VALUES (? ,?,'return',?,?,?,?,?,?)");
        foreach ($returnItems as [$item, $qty, $refundUnitPrice, $amount]) {
            $itemInsert->execute([$returnId,$item['id'],$item['product_id'],$item['product_name'],$qty,$refundUnitPrice,$amount]);
            $stockStmt->execute([$item['product_id'],$shopId]); $before = (int)$stockStmt->fetchColumn();
            $after = $before + $qty;
            $stockUpdate->execute([$qty,$item['product_id'],$shopId]);
            $movement->execute([$shopId,$item['product_id'],$qty,$before,$after,$returnId,'Customer return for invoice '.$saleId,$userId ?: null]);
        }
        $db->commit();
        redirect('customer_returns.php', 'Return processed. Stock has been restored.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        redirect('customer_returns.php?sale_id='.$saleId, $e->getMessage(), 'error');
    }
}

$sales = $db->prepare("SELECT id,invoice_no,customer_name,grand_total,sale_date FROM sales WHERE shop_id=? ORDER BY sale_date DESC LIMIT 100");
$sales->execute([$shopId]); $sales = $sales->fetchAll();
$saleId = safeInt($_GET['sale_id'] ?? 0);
$saleItems = [];
if ($saleId) {
    $items = $db->prepare("SELECT si.*, COALESCE((SELECT SUM(cri.quantity) FROM customer_return_items cri JOIN customer_returns cr ON cr.id=cri.return_id WHERE cri.sale_item_id=si.id),0) returned_qty,
        COALESCE((si.total_price / NULLIF(si.quantity, 0)) * (s.grand_total / NULLIF(s.subtotal, 0)), si.unit_price) AS refund_unit_price
        FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.sale_id=? AND s.shop_id=?");
    $items->execute([$saleId,$shopId]); $saleItems = $items->fetchAll();
}
$returns = $db->prepare("SELECT cr.*, s.invoice_no, COUNT(cri.id) item_count FROM customer_returns cr JOIN sales s ON s.id=cr.sale_id LEFT JOIN customer_return_items cri ON cri.return_id=cr.id WHERE cr.shop_id=? GROUP BY cr.id ORDER BY cr.created_at DESC LIMIT 50");
$returns->execute([$shopId]); $returns = $returns->fetchAll();
shopHeader('Customer Returns', 'customer_returns');
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2"><div><h1 class="page-title"><i class="bi bi-arrow-return-left me-2 text-warning"></i>Customer Returns</h1><p class="page-subtitle">Process returned items, issue a refund and restore stock.</p></div></div>
<?php flashMessage(); ?>
<div class="card mb-4"><div class="card-body"><form method="GET" class="row g-2 align-items-end"><div class="col-md-8"><label class="form-label">Select sale / invoice</label><select name="sale_id" class="form-select" onchange="this.form.submit()"><option value="">Choose an invoice to start a return</option><?php foreach($sales as $sale): ?><option value="<?= $sale['id'] ?>" <?= $saleId===$sale['id']?'selected':'' ?>><?= htmlspecialchars($sale['invoice_no']) ?> — <?= htmlspecialchars($sale['customer_name'] ?: 'Walk-in customer') ?> (<?= formatCurrency($sale['grand_total']) ?>)</option><?php endforeach; ?></select></div><div class="col-auto"><button class="btn btn-outline-primary"><i class="bi bi-search"></i> Load items</button></div></form></div></div>
<?php if ($saleItems): ?><div class="card mb-4"><div class="card-header"><strong>Return items</strong></div><form method="POST"><input type="hidden" name="action" value="create_return"><input type="hidden" name="sale_id" value="<?= $saleId ?>"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Product</th><th>Sold</th><th>Already returned</th><th>Available</th><th>Return quantity</th><th>Refund</th></tr></thead><tbody><?php foreach($saleItems as $item): $available=(int)$item['quantity']-(int)$item['returned_qty']; ?><tr><td><?= htmlspecialchars($item['product_name']) ?></td><td><?= (int)$item['quantity'] ?></td><td><?= (int)$item['returned_qty'] ?></td><td><?= $available ?></td><td><input class="form-control" style="max-width:100px" type="number" min="0" max="<?= $available ?>" name="qty[<?= $item['id'] ?>]" value="0" <?= $available?'':'disabled' ?>></td><td><?= formatCurrency((float)$item['refund_unit_price']) ?> each</td></tr><?php endforeach; ?></tbody></table></div><div class="card-body row g-3"><div class="col-md-4"><label class="form-label">Refund method</label><select name="refund_method" class="form-select"><option value="cash">Cash</option><option value="card">Card</option><option value="credit">Store credit</option><option value="exchange">Exchange</option></select></div><div class="col-md-8"><label class="form-label">Return reason</label><input name="reason" class="form-control" placeholder="e.g. damaged item or wrong size"></div><div class="col-12"><button class="btn btn-warning" onclick="return confirm('Process this return and restore stock?')"><i class="bi bi-check2-circle me-1"></i>Process Return</button></div></div></form></div><?php endif; ?>
<div class="card"><div class="card-header"><strong>Recent returns</strong></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Invoice</th><th>Customer</th><th>Items</th><th>Refund</th><th>Method</th><th>Date</th></tr></thead><tbody><?php foreach($returns as $return): ?><tr><td><?= htmlspecialchars($return['invoice_no']) ?></td><td><?= htmlspecialchars($return['customer_name'] ?: 'Walk-in customer') ?></td><td><?= (int)$return['item_count'] ?></td><td class="text-warning fw-bold"><?= formatCurrency($return['refund_amount']) ?></td><td><?= htmlspecialchars(ucfirst($return['refund_method'])) ?></td><td><?= date('d M Y H:i',strtotime($return['created_at'])) ?></td></tr><?php endforeach; ?><?php if(!$returns): ?><tr><td colspan="6" class="text-center text-muted py-4">No returns processed yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php shopFooter(); ?>

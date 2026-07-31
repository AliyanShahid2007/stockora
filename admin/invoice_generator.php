<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/admin_layout.php';
requireAdmin();
$db = getDB();

// Generate invoice PDF/print for a subscription payment
$paymentId = safeInt($_GET['payment_id'] ?? 0);
$shopId = safeInt($_GET['shop_id'] ?? 0);
$payment = null;

// If specific payment
if ($paymentId) {
    $stmt = $db->prepare("
        SELECT p.*, p.payment_method as method, s.name as shop_name, s.phone as shop_phone, s.email as shop_email,
               s.address as shop_address, s.city as shop_city,
               sub.plan_name, sub.start_date, sub.end_date, sub.months
        FROM payments p
        JOIN shops s ON p.shop_id = s.id
        LEFT JOIN subscriptions sub ON p.subscription_id = sub.id
        WHERE p.id = ?
    ");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
} elseif ($shopId) {
    // Last payment for this shop
    $stmt = $db->prepare("
        SELECT p.*, p.payment_method as method, s.name as shop_name, s.phone as shop_phone, s.email as shop_email,
               s.address as shop_address, s.city as shop_city,
               sub.plan_name, sub.start_date, sub.end_date, sub.months
        FROM payments p
        JOIN shops s ON p.shop_id = s.id
        LEFT JOIN subscriptions sub ON p.subscription_id = sub.id
        WHERE p.shop_id = ? AND p.status = 'completed'
        ORDER BY p.created_at DESC LIMIT 1
    ");
    $stmt->execute([$shopId]);
    $payment = $stmt->fetch();
}

// List all invoices
$allPayments = $db->query("
    SELECT p.id, p.amount, p.payment_method as method, p.reference_no, p.created_at, p.status,
           s.name as shop_name, s.city as shop_city,
           sub.plan_name, sub.months, sub.end_date
    FROM payments p
    JOIN shops s ON p.shop_id = s.id
    LEFT JOIN subscriptions sub ON p.subscription_id = sub.id
    WHERE p.status = 'completed'
    ORDER BY p.created_at DESC
    LIMIT 50
")->fetchAll();

$invNo = 'INV-SUB-' . date('Ymd') . '-' . str_pad($paymentId ?? rand(1000,9999), 4, '0', STR_PAD_LEFT);

adminHeader('Subscription Invoices', 'invoice_gen');
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-receipt-cutoff me-2 text-primary"></i>Subscription Invoices</h1>
        <p class="page-subtitle">Generate & print subscription payment receipts</p>
    </div>
</div>

<?php if ($payment): ?>
<!-- INVOICE PRINT VIEW -->
<div class="card mb-4" id="invoicePrintArea">
    <div class="card-body p-4">
        <div class="row mb-4">
            <div class="col-6">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                    <div style="width:50px;height:50px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.4rem;">
                        <i class="bi bi-shop-window"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold">Stockora POS Pro</h4>
                        <small class="text-muted">Subscription Management System</small>
                    </div>
                </div>
                <p class="text-muted small mb-0">Pakistan</p>
            </div>
            <div class="col-6 text-end">
                <h5 class="fw-bold text-primary">SUBSCRIPTION RECEIPT</h5>
                <div class="text-muted small">Invoice No: <strong><?= $invNo ?></strong></div>
                <div class="text-muted small">Date: <strong><?= date('d M Y', strtotime($payment['created_at'])) ?></strong></div>
                <span class="badge bg-success fs-6">PAID</span>
            </div>
        </div>
        
        <hr>
        
        <div class="row mb-4">
            <div class="col-6">
                <h6 class="fw-bold text-muted mb-2">BILLED TO:</h6>
                <h5 class="fw-bold mb-1"><?= htmlspecialchars($payment['shop_name']) ?></h5>
                <?php if ($payment['shop_city']): ?>
                <p class="text-muted small mb-1"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($payment['shop_city']) ?></p>
                <?php endif; ?>
                <?php if ($payment['shop_phone']): ?>
                <p class="text-muted small mb-1"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($payment['shop_phone']) ?></p>
                <?php endif; ?>
                <?php if ($payment['shop_email']): ?>
                <p class="text-muted small mb-0"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($payment['shop_email']) ?></p>
                <?php endif; ?>
            </div>
            <div class="col-6">
                <h6 class="fw-bold text-muted mb-2">PAYMENT DETAILS:</h6>
                <table class="table table-sm table-borderless small">
                    <tr><td class="text-muted">Method:</td><td class="fw-bold"><?= ucfirst($payment['method'] ?? 'Cash') ?></td></tr>
                    <?php if ($payment['reference_no']): ?>
                    <tr><td class="text-muted">Reference:</td><td class="fw-bold"><?= htmlspecialchars($payment['reference_no']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td class="text-muted">Status:</td><td><span class="badge bg-success">Completed</span></td></tr>
                </table>
            </div>
        </div>
        
        <table class="table table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th>Description</th>
                    <th>Period</th>
                    <th>Duration</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>
                        <strong><?= htmlspecialchars($payment['plan_name'] ?? 'Subscription Plan') ?></strong><br>
                        <small class="text-muted">Stockora POS Pro - Full Access</small>
                    </td>
                    <td>
                        <?php if ($payment['start_date']): ?>
                        <small><?= date('d M Y', strtotime($payment['start_date'])) ?> -<br><?= date('d M Y', strtotime($payment['end_date'])) ?></small>
                        <?php else: ?>
                        <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                    <td><?= $payment['months'] ?? 1 ?> Month(s)</td>
                    <td class="text-end fw-bold"><?= formatCurrency($payment['amount']) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="invoice-subtotal-row">
                    <td colspan="4" class="text-end fw-bold">Subtotal:</td>
                    <td class="text-end fw-bold"><?= formatCurrency($payment['amount']) ?></td>
                </tr>
                <tr class="table-success">
                    <td colspan="4" class="text-end fw-bold fs-5">TOTAL PAID:</td>
                    <td class="text-end fw-bold fs-5 text-success"><?= formatCurrency($payment['amount']) ?></td>
                </tr>
            </tfoot>
        </table>
        
        <div class="row mt-4">
            <div class="col-6">
                <p class="text-muted small">Thank you for your subscription! Your account is now active and you have full access to all Stockora POS Pro features.</p>
            </div>
            <div class="col-6 text-end">
                <div style="border-top:2px solid #333;margin-top:40px;padding-top:8px;display:inline-block;min-width:200px;">
                    <small class="text-muted">Authorized Signature</small>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="d-flex gap-2 mb-4 no-print">
    <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer me-1"></i>Print Invoice</button>
    <a href="<?= BASE_URL ?>/admin/invoice_generator.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to All</a>
    <a href="<?= BASE_URL ?>/admin/payments.php" class="btn btn-outline-secondary"><i class="bi bi-cash-coin me-1"></i>All Payments</a>
</div>
<?php endif; ?>

<!-- ALL INVOICES TABLE -->
<div class="card <?= $payment ? 'no-print' : '' ?>">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2"></i>All Subscription Payments</span>
        <span class="badge bg-primary"><?= count($allPayments) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Shop</th>
                    <th>Plan</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Valid Until</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allPayments as $i => $p): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($p['shop_name']) ?></td>
                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($p['plan_name'] ?? 'N/A') ?></span></td>
                    <td class="fw-bold text-success"><?= formatCurrency($p['amount']) ?></td>
                    <td><?= ucfirst($p['method']) ?></td>
                    <td><?= $p['end_date'] ? date('d M Y', strtotime($p['end_date'])) : '-' ?></td>
                    <td><small><?= date('d M Y', strtotime($p['created_at'])) ?></small></td>
                    <td>
                        <a href="?payment_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" style="font-size:0.75rem;padding:0.2rem 0.6rem;">
                            <i class="bi bi-printer me-1"></i>Invoice
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
#invoicePrintArea .invoice-subtotal-row > td {
    background: rgba(255,255,255,.06) !important;
    color: #f0ecff !important;
    border-color: rgba(167,139,250,.16) !important;
}
@media print {
    @page { margin: 12mm; }
    body * { visibility: hidden; }
    #invoicePrintArea, #invoicePrintArea * { visibility: visible; }
    #invoicePrintArea {
        position: absolute; left: 0; top: 0; width: 100%; margin: 0;
        background: #fff !important; color: #000 !important;
        box-shadow: none !important; border: none !important;
    }
    #invoicePrintArea .card-body { background: #fff !important; }
    #invoicePrintArea h4, #invoicePrintArea h5, #invoicePrintArea h6,
    #invoicePrintArea p, #invoicePrintArea small, #invoicePrintArea td,
    #invoicePrintArea th, #invoicePrintArea div, #invoicePrintArea span {
        color: #000 !important; -webkit-text-fill-color: #000 !important;
    }
    #invoicePrintArea .invoice-subtotal-row > td,
    #invoicePrintArea .table-success > td { background: #fff !important; }
    #invoicePrintArea .badge { border: 1px solid #555 !important; }
}
</style>

<?php adminFooter(); ?>

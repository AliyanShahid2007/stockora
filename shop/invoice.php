<?php
require_once '../includes/functions.php';
requireShop();

$shopId = (int)$_SESSION['shop_id'];
$saleId = safeInt($_GET['id'] ?? 0);

if (!$saleId) { header('Location: ' . BASE_URL . '/shop/sales.php'); exit; }

$db = getDB();
$shop = getCurrentShop();

$stmt = $db->prepare("SELECT * FROM sales WHERE id=? AND shop_id=?");
$stmt->execute([$saleId, $shopId]);
$sale = $stmt->fetch();

if (!$sale) { header('Location: ' . BASE_URL . '/shop/sales.php'); exit; }

$stmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id=?");
$stmt->execute([$saleId]);
$items = $stmt->fetchAll();

$logoUrl = !empty($shop['logo']) ? BASE_URL . '/assets/uploads/' . $shop['logo'] : null;
$thankYouMsg = getShopSetting($shopId, 'thank_you_msg', 'Thank you for your purchase!');
$footerNote = getShopSetting($shopId, 'invoice_footer', 'Please visit again. Goods once sold will not be returned.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($sale['invoice_no']) ?> - <?= htmlspecialchars($shop['name']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root { --primary: #6C63FF; }
body { background: #f0f2f7; font-family: 'Segoe UI', sans-serif; }
.invoice-page { max-width: 600px; margin: 1.5rem auto; background: white; border-radius: 16px; box-shadow: 0 4px 30px rgba(0,0,0,0.1); overflow: hidden; }
.invoice-top-bar { background: linear-gradient(135deg, var(--primary), #3ECFCF); height: 6px; }
.invoice-body { padding: 2rem; }
.invoice-shop-header { text-align: center; padding-bottom: 1.5rem; border-bottom: 2px dashed #e9ecef; margin-bottom: 1.5rem; }
.shop-logo { max-width: 80px; max-height: 80px; border-radius: 12px; margin-bottom: 0.75rem; }
.shop-name { font-size: 1.4rem; font-weight: 800; color: #1a1a2e; }
.shop-contact { color: #6c757d; font-size: 0.9rem; line-height: 1.8; }
.invoice-meta { background: #f8f9fa; border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem; }
.meta-row { display: flex; justify-content: space-between; font-size: 0.875rem; padding: 0.2rem 0; }
.meta-label { color: #6c757d; }
.meta-value { font-weight: 600; }
.items-table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
.items-table th { background: #f8f9fa; padding: 0.75rem; font-size: 0.78rem; text-transform: uppercase; color: #6c757d; font-weight: 700; letter-spacing: 0.5px; }
.items-table td { padding: 0.75rem; border-bottom: 1px solid #f0f0f0; font-size: 0.9rem; }
.items-table tr:last-child td { border-bottom: none; }
.totals-section { border-top: 2px solid #f0f0f0; padding-top: 1rem; }
.total-row { display: flex; justify-content: space-between; padding: 0.3rem 0; font-size: 0.9rem; }
.total-row.grand { font-size: 1.1rem; font-weight: 800; color: #1a1a2e; border-top: 2px solid #e9ecef; padding-top: 0.75rem; margin-top: 0.5rem; }
.invoice-footer-section { text-align: center; padding: 1.5rem; background: #f8f9ff; margin: 1.5rem -2rem -2rem; }
.thank-you { font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 0.5rem; }
.footer-note { font-size: 0.8rem; color: #6c757d; }
.actions-bar { position: sticky; top: 0; z-index: 100; background: white; padding: 0.75rem 1rem; border-bottom: 1px solid #e9ecef; display: flex; gap: 0.5rem; flex-wrap: wrap; }
@media print {
    .actions-bar, .no-print { display: none !important; }
    body { background: white; }
    .invoice-page { box-shadow: none; border-radius: 0; margin: 0; max-width: 100%; }
    .invoice-body { padding: 1rem; }
}
@media (max-width: 576px) {
    .invoice-body { padding: 1.25rem; }
    .invoice-footer-section { margin: 1.5rem -1.25rem -1.25rem; }
}
</style>
</head>
<body>

<div class="actions-bar no-print">
    <a href="<?= BASE_URL ?>/shop/sales.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
    <button class="btn btn-success btn-sm" onclick="shareWhatsApp()"><i class="bi bi-whatsapp me-1"></i>WhatsApp</button>
    <a href="<?= BASE_URL ?>/shop/pos.php" class="btn btn-outline-primary btn-sm ms-auto"><i class="bi bi-cart3 me-1"></i>New Sale</a>
</div>

<div class="invoice-page">
    <div class="invoice-top-bar"></div>
    <div class="invoice-body">
        <!-- Shop Header -->
        <div class="invoice-shop-header">
            <?php if ($logoUrl): ?>
            <img src="<?= htmlspecialchars($logoUrl) ?>" class="shop-logo" alt="<?= htmlspecialchars($shop['name']) ?>">
            <?php else: ?>
            <div style="width:70px;height:70px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);border-radius:16px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.8rem;margin:0 auto 0.75rem;">🏪</div>
            <?php endif; ?>
            <div class="shop-name"><?= htmlspecialchars($shop['name']) ?></div>
            <div class="shop-contact">
                <?php if ($shop['phone']): ?><div><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($shop['phone']) ?></div><?php endif; ?>
                <?php if ($shop['address']): ?><div><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($shop['address']) ?></div><?php endif; ?>
            </div>
        </div>

        <!-- Invoice Meta -->
        <div class="invoice-meta">
            <div class="meta-row"><span class="meta-label">Invoice No.</span><span class="meta-value text-primary"><?= htmlspecialchars($sale['invoice_no']) ?></span></div>
            <div class="meta-row"><span class="meta-label">Date & Time</span><span class="meta-value"><?php $idt=new DateTime($sale['sale_date'],new DateTimeZone('UTC')); $idt->setTimezone(new DateTimeZone('Asia/Karachi')); echo $idt->format('d M Y, h:i A'); ?></span></div>
            <div class="meta-row"><span class="meta-label">Sale Type</span><span class="meta-value"><?= ucfirst($sale['sale_type']) ?></span></div>
            <?php if ($sale['customer_name']): ?>
            <div class="meta-row"><span class="meta-label">Customer</span><span class="meta-value"><?= htmlspecialchars($sale['customer_name']) ?></span></div>
            <?php endif; ?>
            <div class="meta-row"><span class="meta-label">Payment</span><span class="meta-value"><?= ucfirst($sale['payment_method']) ?> - <span class="badge <?= $sale['payment_status']==='paid'?'bg-success':'bg-warning' ?>"><?= ucfirst($sale['payment_status']) ?></span></span></div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="text-align:left;">Item</th>
                    <th style="text-align:center;">Qty</th>
                    <th style="text-align:right;">Price</th>
                    <th style="text-align:right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td style="text-align:center;"><?= $item['quantity'] ?></td>
                    <td style="text-align:right;"><?= formatCurrency($item['unit_price']) ?></td>
                    <td style="text-align:right;" class="fw-bold"><?= formatCurrency($item['total_price']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-section">
            <div class="total-row"><span>Subtotal</span><span><?= formatCurrency($sale['subtotal']) ?></span></div>
            <?php if ($sale['discount'] > 0): ?>
            <div class="total-row"><span>Discount</span><span class="text-danger">-<?= formatCurrency($sale['discount']) ?></span></div>
            <?php endif; ?>
            <?php if ($sale['tax'] > 0): ?>
            <div class="total-row"><span>Tax</span><span><?= formatCurrency($sale['tax']) ?></span></div>
            <?php endif; ?>
            <div class="total-row grand"><span>GRAND TOTAL</span><span class="text-primary"><?= formatCurrency($sale['grand_total']) ?></span></div>
            <div class="total-row"><span>Amount Paid</span><span><?= formatCurrency($sale['amount_paid']) ?></span></div>
            <?php if ($sale['change_amount'] > 0): ?>
            <div class="total-row"><span>Change</span><span class="text-success"><?= formatCurrency($sale['change_amount']) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <div class="invoice-footer-section">
        <div class="thank-you">★ <?= htmlspecialchars($thankYouMsg) ?> ★</div>
        <?php if ($footerNote): ?>
        <div class="footer-note"><?= htmlspecialchars($footerNote) ?></div>
        <?php endif; ?>
        <div class="footer-note mt-2" style="font-size:0.7rem;opacity:0.6;">Powered by Stockora POS Pro</div>
    </div>
</div>

<script>
function shareWhatsApp() {
    const msg = `*<?= addslashes(htmlspecialchars($shop['name'])) ?>*\nInvoice: <?= $sale['invoice_no'] ?>\nDate: <?php $wdt=new DateTime($sale['sale_date'],new DateTimeZone('UTC')); $wdt->setTimezone(new DateTimeZone('Asia/Karachi')); echo $wdt->format('d M Y'); ?>\n\n<?php foreach ($items as $it): ?>• <?= addslashes($it['product_name']) ?> x<?= $it['quantity'] ?> = <?= formatCurrency($it['total_price']) ?>\n<?php endforeach; ?>\nTotal: <?= formatCurrency($sale['grand_total']) ?>\nPaid: <?= formatCurrency($sale['amount_paid']) ?>\n\n★ Thank you! ★`;
    window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank');
}
</script>
</body>
</html>

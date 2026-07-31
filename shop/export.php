<?php
require_once '../includes/functions.php';
requireShop();

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
$type = $_GET['type'] ?? '';

// Shop creation date for calendar restriction
$_shopDataExp = $db->prepare("SELECT created_at FROM shops WHERE id=?");
$_shopDataExp->execute([$shopId]);
$_shopDataExp = $_shopDataExp->fetch();
$shopCreatedDate = $_shopDataExp ? date('Y-m-d', strtotime($_shopDataExp['created_at'])) : '2020-01-01';
$todayDate = date('Y-m-d');

// ===== EXPORT HANDLER =====
if ($type && isset($_GET['download'])) {
    $dateFrom = $_GET['from'] ?? date('Y-01-01');
    $dateTo = $_GET['to'] ?? date('Y-m-d');
    $catFilter = safeInt($_GET['cat'] ?? 0);
    
    header('Content-Type: text/csv; charset=utf-8');
    $filename = "stockora_{$type}_" . date('Y-m-d') . ".csv";
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Pragma: no-cache');
    
    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fwrite($out, "\xEF\xBB\xBF");
    
    if ($type === 'products') {
        fputcsv($out, ['ID','Name','Category','Company Price','Retail Price','Wholesale Price','Stock','Unit','Barcode','Min Stock Alert','Status']);
        $q = "SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.shop_id=?";
        $params = [$shopId];
        if ($catFilter) { $q .= " AND p.category_id=?"; $params[] = $catFilter; }
        $q .= " ORDER BY p.name";
        $stmt = $db->prepare($q);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $p) {
            fputcsv($out, [$p['id'], $p['name'], $p['cat_name'] ?? '', $p['company_price'], $p['retail_price'], $p['wholesale_price'], $p['stock_quantity'], $p['unit'], $p['barcode'] ?? '', $p['min_stock_alert'], $p['status']]);
        }
        // Log export
        $db->prepare("INSERT INTO export_logs (shop_id, export_type, file_name, filters_used, exported_by) VALUES (?,?,?,?,?)")->execute([$shopId, 'products', $filename, "cat={$catFilter}", $_SESSION['user_id']]);
    }
    
    elseif ($type === 'sales') {
        fputcsv($out, ['Invoice No','Type','Customer','Items','Subtotal','Discount','Grand Total','Paid','Change','Payment Method','Status','Date']);
        $stmt = $db->prepare("SELECT * FROM sales WHERE shop_id=? AND DATE(sale_date) BETWEEN ? AND ? ORDER BY sale_date DESC");
        $stmt->execute([$shopId, $dateFrom, $dateTo]);
        foreach ($stmt->fetchAll() as $s) {
            // count items
            $cnt = $db->prepare("SELECT COUNT(*) FROM sale_items WHERE sale_id=?");
            $cnt->execute([$s['id']]);
            fputcsv($out, [$s['invoice_no'], $s['sale_type'], $s['customer_name'] ?? 'Walk-in', $cnt->fetchColumn(), $s['subtotal'], $s['discount'], $s['grand_total'], $s['amount_paid'], $s['change_amount'], $s['payment_method'], $s['payment_status'], $s['sale_date']]);
        }
        $db->prepare("INSERT INTO export_logs (shop_id, export_type, file_name, filters_used, exported_by) VALUES (?,?,?,?,?)")->execute([$shopId, 'sales', $filename, "from={$dateFrom}&to={$dateTo}", $_SESSION['user_id']]);
    }
    
    elseif ($type === 'sales_detail') {
        fputcsv($out, ['Invoice No','Sale Type','Product','Qty','Unit Price','Total Price','Profit','Customer','Date']);
        $stmt = $db->prepare("SELECT si.*, s.invoice_no, s.sale_type, s.customer_name, s.sale_date FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.shop_id=? AND DATE(s.sale_date) BETWEEN ? AND ? ORDER BY s.sale_date DESC");
        $stmt->execute([$shopId, $dateFrom, $dateTo]);
        foreach ($stmt->fetchAll() as $r) {
            fputcsv($out, [$r['invoice_no'], $r['sale_type'], $r['product_name'], $r['quantity'], $r['unit_price'], $r['total_price'], $r['profit'], $r['customer_name'] ?? 'Walk-in', date('Y-m-d', strtotime($r['sale_date']))]);
        }
    }
    
    elseif ($type === 'profit') {
        fputcsv($out, ['Date','Invoice','Product','Qty','Sale Price','Company Price','Profit','Sale Type']);
        $stmt = $db->prepare("SELECT si.*, s.invoice_no, s.sale_type, s.sale_date FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.shop_id=? AND DATE(s.sale_date) BETWEEN ? AND ? ORDER BY s.sale_date DESC");
        $stmt->execute([$shopId, $dateFrom, $dateTo]);
        foreach ($stmt->fetchAll() as $r) {
            fputcsv($out, [date('Y-m-d', strtotime($r['sale_date'])), $r['invoice_no'], $r['product_name'], $r['quantity'], $r['unit_price'], $r['company_price'], $r['profit'], $r['sale_type']]);
        }
    }
    
    elseif ($type === 'customers') {
        fputcsv($out, ['Name','Phone','Email','Address','Total Purchases','Visit Count','Created']);
        $stmt = $db->prepare("SELECT * FROM customers WHERE shop_id=? ORDER BY name");
        $stmt->execute([$shopId]);
        foreach ($stmt->fetchAll() as $c) {
            fputcsv($out, [$c['name'], $c['phone'] ?? '', $c['email'] ?? '', $c['address'] ?? '', $c['total_purchases'], $c['visit_count'], $c['created_at']]);
        }
    }
    
    elseif ($type === 'buyers') {
        fputcsv($out, ['Name','Business','Phone','Email','City','Total Purchases','Balance','Status']);
        $stmt = $db->prepare("SELECT * FROM bulk_buyers WHERE shop_id=? ORDER BY name");
        $stmt->execute([$shopId]);
        foreach ($stmt->fetchAll() as $b) {
            fputcsv($out, [$b['name'], $b['business_name'] ?? '', $b['phone'] ?? '', $b['email'] ?? '', $b['city'] ?? '', $b['total_purchases'], $b['outstanding_balance'], $b['status']]);
        }
    }
    
    elseif ($type === 'stock') {
        fputcsv($out, ['Product','Category','Current Stock','Min Alert','Unit','Retail Price','Stock Value','Status']);
        $stmt = $db->prepare("SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.shop_id=? ORDER BY p.name");
        $stmt->execute([$shopId]);
        foreach ($stmt->fetchAll() as $p) {
            fputcsv($out, [$p['name'], $p['cat_name'] ?? '', $p['stock_quantity'], $p['min_stock_alert'], $p['unit'], $p['retail_price'], $p['retail_price']*$p['stock_quantity'], $p['status']]);
        }
    }
    
    fclose($out);
    exit;
}

// ===== EXPORT PAGE =====
require_once '../includes/shop_layout.php';

$categories = $db->prepare("SELECT * FROM categories WHERE shop_id=? ORDER BY name");
$categories->execute([$shopId]);
$categories = $categories->fetchAll();

$exportHistory = $db->prepare("SELECT * FROM export_logs WHERE shop_id=? ORDER BY created_at DESC LIMIT 10");
$exportHistory->execute([$shopId]);
$exportHistory = $exportHistory->fetchAll();

shopHeader('Export Data', 'export');
?>

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-download me-2 text-primary"></i>Export Data</h1>
    <p class="page-subtitle">Download your data as CSV/Excel files</p>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        
        <!-- Products Export -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-box-seam me-2 text-primary"></i>Export Products</div>
            <div class="card-body">
                <p class="text-muted small mb-3">Export all products with prices and stock information</p>
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="type" value="products">
                    <input type="hidden" name="download" value="1">
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-medium">Filter by Category</label>
                        <select class="form-select form-select-sm" name="cat">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-download me-1"></i>Download Products CSV</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sales Export -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-receipt me-2 text-primary"></i>Export Sales</div>
            <div class="card-body">
                <div class="row g-2 mb-2">
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-medium">From Date</label>
                        <input type="date" class="form-control form-control-sm" id="salesFrom" value="<?= date('Y-m-01') ?>" min="<?= $shopCreatedDate ?>" max="<?= $todayDate ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-medium">To Date</label>
                        <input type="date" class="form-control form-control-sm" id="salesTo" value="<?= $todayDate ?>" min="<?= $shopCreatedDate ?>" max="<?= $todayDate ?>">
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button onclick="exportSales('sales')" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Summary</button>
                    <button onclick="exportSales('sales_detail')" class="btn btn-outline-info btn-sm"><i class="bi bi-download me-1"></i>Detailed</button>
                    <button onclick="exportSales('profit')" class="btn btn-outline-warning btn-sm"><i class="bi bi-download me-1"></i>Profit Report</button>
                </div>
            </div>
        </div>

        <!-- Stock Export -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-clipboard-data me-2 text-primary"></i>Export Stock Report</div>
            <div class="card-body">
                <p class="text-muted small mb-2">Export current stock levels and values</p>
                <a href="<?= BASE_URL ?>?type=stock&download=1" class="btn btn-outline-primary btn-sm"><i class="bi bi-download me-1"></i>Download Stock CSV</a>
            </div>
        </div>

        <!-- Customer/Buyer Export -->
        <div class="card">
            <div class="card-header"><i class="bi bi-people me-2 text-primary"></i>Export Customers & Buyers</div>
            <div class="card-body">
                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?= BASE_URL ?>?type=customers&download=1" class="btn btn-outline-info btn-sm"><i class="bi bi-download me-1"></i>Retail Customers</a>
                    <a href="<?= BASE_URL ?>?type=buyers&download=1" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Bulk Buyers</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Export History -->
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-2"></i>Export History</div>
            <div class="card-body p-0">
                <?php if ($exportHistory): ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($exportHistory as $h): ?>
                    <div class="list-group-item py-2 px-3">
                        <div class="d-flex justify-content-between">
                            <span class="badge" style="background:rgba(255,255,255,.1);color:#e0d8ff;"><?= ucfirst($h['export_type']) ?></span>
                            <small class="text-muted"><?= date('d M Y', strtotime($h['created_at'])) ?></small>
                        </div>
                        <small class="text-muted"><?= htmlspecialchars($h['file_name'] ?? '') ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted">No exports yet</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Export Guide -->
        <div class="card mt-3 border-0" style="background:linear-gradient(135deg,#fff8f0,#fff);">
            <div class="card-body">
                <h6 class="fw-bold text-warning mb-2"><i class="bi bi-info-circle me-1"></i>Export Guide</h6>
                <ul class="list-unstyled mb-0 small text-muted">
                    <li class="mb-1">📊 Open CSV in Microsoft Excel</li>
                    <li class="mb-1">🔒 Company prices included (confidential)</li>
                    <li class="mb-1">📅 Use date filters for period reports</li>
                    <li class="mb-1">💰 Profit report shows margin details</li>
                    <li class="mb-1">🔄 Use as backup for re-import</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function exportSales(type) {
    const from = document.getElementById('salesFrom').value;
    const to = document.getElementById('salesTo').value;
    window.location.href = `?type=${type}&download=1&from=${from}&to=${to}`;
}
</script>
<?php shopFooter(); ?>

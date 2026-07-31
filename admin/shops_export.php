<?php
require_once '../includes/functions.php';
requireAdmin();

$db = getDB();

// Handle CSV export
if (isset($_GET['export'])) {
    $type = $_GET['export'];

    if ($type === 'shops') {
        $rows = $db->query("
            SELECT s.id, s.name, s.owner_name, s.email, s.phone, s.city, s.address, s.status, s.created_at,
                   u.email as login_email,
                   sub.plan_name, sub.end_date, sub.status as sub_status,
                   (SELECT COUNT(*) FROM products WHERE shop_id=s.id) as products,
                   (SELECT COUNT(*) FROM sales WHERE shop_id=s.id) as sales,
                   (SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE shop_id=s.id) as gmv,
                   (SELECT COALESCE(SUM(amount),0) FROM payments WHERE shop_id=s.id AND status='completed') as revenue_paid
            FROM shops s
            LEFT JOIN users u ON u.shop_id=s.id AND u.role='owner'
            LEFT JOIN subscriptions sub ON sub.shop_id=s.id AND sub.id=(SELECT id FROM subscriptions WHERE shop_id=s.id ORDER BY end_date DESC LIMIT 1)
            ORDER BY s.created_at DESC
        ")->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stockora_shops_'.date('Y-m-d').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Shop Name','Owner Name','Email','Login Email','Phone','City','Address','Status','Subscription Plan','Sub Expires','Sub Status','Products','Sales','GMV (Rs)','Revenue Paid (Rs)','Created At']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'],$r['name'],$r['owner_name'],$r['email'],$r['login_email'],$r['phone'],$r['city'],$r['address'],$r['status'],$r['plan_name'],$r['end_date'],$r['sub_status'],$r['products'],$r['sales'],$r['gmv'],$r['revenue_paid'],$r['created_at']]);
        }
        fclose($out); exit;
    }

    if ($type === 'payments') {
        $rows = $db->query("
            SELECT p.id, sh.name as shop_name, p.amount, p.payment_method, p.reference_no, p.payment_date, p.status, sub.plan_name
            FROM payments p JOIN shops sh ON sh.id=p.shop_id
            LEFT JOIN subscriptions sub ON sub.id=p.subscription_id
            ORDER BY p.payment_date DESC
        ")->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stockora_payments_'.date('Y-m-d').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Shop','Amount (Rs)','Method','Reference','Date','Status','Plan']);
        foreach ($rows as $r) fputcsv($out, [$r['id'],$r['shop_name'],$r['amount'],$r['payment_method'],$r['reference_no'],$r['payment_date'],$r['status'],$r['plan_name']]);
        fclose($out); exit;
    }

    if ($type === 'subscriptions') {
        $rows = $db->query("
            SELECT sub.id, sh.name as shop_name, sub.plan_name, sub.amount, sub.start_date, sub.end_date, sub.status, sub.payment_method, sub.created_at
            FROM subscriptions sub JOIN shops sh ON sh.id=sub.shop_id
            ORDER BY sub.created_at DESC
        ")->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stockora_subscriptions_'.date('Y-m-d').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Shop','Plan','Amount (Rs)','Start Date','End Date','Status','Payment Method','Created At']);
        foreach ($rows as $r) fputcsv($out, [$r['id'],$r['shop_name'],$r['plan_name'],$r['amount'],$r['start_date'],$r['end_date'],$r['status'],$r['payment_method'],$r['created_at']]);
        fclose($out); exit;
    }
}

require_once '../includes/admin_layout.php';

// Stats
$totalShops    = (int)$db->query("SELECT COUNT(*) FROM shops")->fetchColumn();
$totalPayments = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed'")->fetchColumn();
$totalSubs     = (int)$db->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn();
$totalSales    = (float)$db->query("SELECT COALESCE(SUM(grand_total),0) FROM sales")->fetchColumn();

adminHeader('Export Data', 'shops_export');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-file-earmark-spreadsheet me-2 text-success"></i>Export All Data</h1>
        <p class="page-subtitle">Download complete platform data as CSV files</p>
    </div>
</div>

<!-- Platform Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-shop"></i></div>
            <div class="stat-card-value"><?= $totalShops ?></div>
            <div class="stat-card-label">Total Shops</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-cash-coin"></i></div>
            <div class="stat-card-value">Rs.<?= number_format($totalPayments,0) ?></div>
            <div class="stat-card-label">Total Revenue</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-calendar-check"></i></div>
            <div class="stat-card-value"><?= $totalSubs ?></div>
            <div class="stat-card-label">Total Subscriptions</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-info">
            <div class="stat-card-icon"><i class="bi bi-graph-up"></i></div>
            <div class="stat-card-value">Rs.<?= number_format($totalSales,0) ?></div>
            <div class="stat-card-label">Platform GMV</div>
        </div>
    </div>
</div>

<!-- Export Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card text-center h-100">
            <div class="card-body p-4">
                <div class="mb-3" style="width:70px;height:70px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);border-radius:18px;display:flex;align-items:center;justify-content:center;margin:0 auto;font-size:1.8rem;color:white;">
                    <i class="bi bi-shop"></i>
                </div>
                <h5 class="fw-bold">Shops Directory</h5>
                <p class="text-muted small mb-3">All shop details including owner info, subscription status, GMV, and revenue paid</p>
                <div class="text-muted small mb-3"><strong><?= $totalShops ?></strong> records</div>
                <a href="<?= BASE_URL ?>?export=shops" class="btn btn-primary w-100">
                    <i class="bi bi-download me-2"></i>Download Shops CSV
                </a>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card text-center h-100">
            <div class="card-body p-4">
                <div class="mb-3" style="width:70px;height:70px;background:linear-gradient(135deg,#28c76f,#48da89);border-radius:18px;display:flex;align-items:center;justify-content:center;margin:0 auto;font-size:1.8rem;color:white;">
                    <i class="bi bi-cash-coin"></i>
                </div>
                <h5 class="fw-bold">Payment History</h5>
                <p class="text-muted small mb-3">Complete payment transactions with shop names, methods, references, and dates</p>
                <div class="text-muted small mb-3"><strong><?= (int)$db->query("SELECT COUNT(*) FROM payments")->fetchColumn() ?></strong> records</div>
                <a href="<?= BASE_URL ?>?export=payments" class="btn btn-success w-100">
                    <i class="bi bi-download me-2"></i>Download Payments CSV
                </a>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card text-center h-100">
            <div class="card-body p-4">
                <div class="mb-3" style="width:70px;height:70px;background:linear-gradient(135deg,#ff9f43,#ffb347);border-radius:18px;display:flex;align-items:center;justify-content:center;margin:0 auto;font-size:1.8rem;color:white;">
                    <i class="bi bi-calendar-check"></i>
                </div>
                <h5 class="fw-bold">Subscriptions</h5>
                <p class="text-muted small mb-3">All subscription records with plans, dates, amounts, and current status</p>
                <div class="text-muted small mb-3"><strong><?= $totalSubs ?></strong> records</div>
                <a href="<?= BASE_URL ?>?export=subscriptions" class="btn btn-warning w-100">
                    <i class="bi bi-download me-2"></i>Download Subscriptions CSV
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Preview Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2 text-primary"></i>Shops Preview</span>
        <a href="<?= BASE_URL ?>?export=shops" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Export Full CSV</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>#</th><th>Shop Name</th><th>Owner</th><th>Phone</th><th>City</th><th>Sub Status</th><th>Products</th><th>GMV (Rs)</th><th>Revenue Paid (Rs)</th></tr></thead>
            <tbody>
                <?php
                $preview = $db->query("
                    SELECT s.id, s.name, s.owner_name, s.phone, s.city,
                           sub.end_date, sub.status as sub_status,
                           (SELECT COUNT(*) FROM products WHERE shop_id=s.id) as products,
                           (SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE shop_id=s.id) as gmv,
                           (SELECT COALESCE(SUM(amount),0) FROM payments WHERE shop_id=s.id AND status='completed') as revenue
                    FROM shops s
                    LEFT JOIN subscriptions sub ON sub.shop_id=s.id AND sub.id=(SELECT id FROM subscriptions WHERE shop_id=s.id ORDER BY end_date DESC LIMIT 1)
                    ORDER BY s.created_at DESC
                ")->fetchAll();
                foreach ($preview as $i => $row):
                    $subSt = $row['sub_status'];
                    if ($row['end_date'] && $row['end_date'] < date('Y-m-d')) $subSt = 'expired';
                ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= htmlspecialchars($row['owner_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['phone'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['city'] ?? '-') ?></td>
                    <td><span class="badge <?= $subSt==='active'?'status-active':($subSt==='expired'?'status-expired':'status-inactive') ?>"><?= ucfirst($subSt ?: 'None') ?></span></td>
                    <td><?= $row['products'] ?></td>
                    <td>Rs. <?= number_format($row['gmv'],0) ?></td>
                    <td>Rs. <?= number_format($row['revenue'],0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php adminFooter(); ?>

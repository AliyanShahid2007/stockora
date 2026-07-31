<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/admin_layout.php';
requireAdmin();
$db = getDB();

// Expiring subscriptions (within 7, 14, 30 days)
$days7 = $db->query("
    SELECT s.id, s.name, s.phone, s.email, u.email as owner_email,
           sub.end_date, sub.plan_name, sub.amount, sub.months,
           DATEDIFF(sub.end_date, CURDATE()) as days_left
    FROM subscriptions sub
    JOIN shops s ON sub.shop_id = s.id
    LEFT JOIN users u ON u.shop_id = s.id AND u.role = 'owner'
    WHERE sub.status = 'active' AND sub.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY sub.end_date ASC
")->fetchAll();

$days14 = $db->query("
    SELECT s.id, s.name, s.phone, s.email, u.email as owner_email,
           sub.end_date, sub.plan_name, sub.amount, sub.months,
           DATEDIFF(sub.end_date, CURDATE()) as days_left
    FROM subscriptions sub
    JOIN shops s ON sub.shop_id = s.id
    LEFT JOIN users u ON u.shop_id = s.id AND u.role = 'owner'
    WHERE sub.status = 'active' AND sub.end_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
    ORDER BY sub.end_date ASC
")->fetchAll();

$expired = $db->query("
    SELECT s.id, s.name, s.phone, u.email as owner_email,
           sub.end_date, sub.plan_name, sub.amount,
           DATEDIFF(CURDATE(), sub.end_date) as days_expired
    FROM subscriptions sub
    JOIN shops s ON sub.shop_id = s.id
    LEFT JOIN users u ON u.shop_id = s.id AND u.role = 'owner'
    WHERE sub.status = 'expired' OR (sub.status = 'active' AND sub.end_date < CURDATE())
    GROUP BY s.id
    ORDER BY sub.end_date DESC
    LIMIT 20
")->fetchAll();

$totalRevenuePending = count($days7) * SUBSCRIPTION_PRICE + count($days14) * SUBSCRIPTION_PRICE;

adminHeader('Expiry Alerts', 'expiry_alerts');
?>
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bell-fill me-2 text-warning"></i>Subscription Expiry Alerts</h1>
    <p class="page-subtitle">Monitor subscriptions expiring soon and take action</p>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-danger">
            <div class="stat-card-icon"><i class="bi bi-alarm"></i></div>
            <div class="stat-card-value"><?= count($days7) ?></div>
            <div class="stat-card-label">Expiring in 7 Days</div>
            <div class="stat-card-change"><i class="bi bi-exclamation-triangle me-1"></i>Critical</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-clock-history"></i></div>
            <div class="stat-card-value"><?= count($days14) ?></div>
            <div class="stat-card-label">Expiring 7-14 Days</div>
            <div class="stat-card-change"><i class="bi bi-exclamation me-1"></i>Warning</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-secondary">
            <div class="stat-card-icon"><i class="bi bi-x-circle"></i></div>
            <div class="stat-card-value"><?= count($expired) ?></div>
            <div class="stat-card-label">Already Expired</div>
            <div class="stat-card-change"><i class="bi bi-arrow-right me-1"></i>Needs Renewal</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-cash"></i></div>
            <div class="stat-card-value"><?= formatCurrency($totalRevenuePending) ?></div>
            <div class="stat-card-label">Potential Revenue</div>
            <div class="stat-card-change"><i class="bi bi-graph-up me-1"></i>From renewals</div>
        </div>
    </div>
</div>

<?php if (!empty($days7)): ?>
<!-- Critical: Expiring in 7 Days -->
<div class="card mb-4 border-danger">
    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-alarm me-2"></i>Critical — Expiring in 7 Days (<?= count($days7) ?>)</span>
        <button class="btn btn-sm btn-light" onclick="sendAllWhatsApp('7days')">
            <i class="bi bi-whatsapp me-1"></i>WhatsApp All
        </button>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Shop</th><th>Contact</th><th>Plan</th><th>Expires</th><th>Days Left</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($days7 as $s): ?>
                <tr class="<?= $s['days_left'] <= 2 ? 'table-danger' : 'table-warning' ?>">
                    <td>
                        <div class="fw-bold"><?= htmlspecialchars($s['name']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($s['owner_email'] ?? '') ?></small>
                    </td>
                    <td><?= htmlspecialchars($s['phone'] ?? '-') ?></td>
                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($s['plan_name']) ?></span></td>
                    <td><strong><?= date('d M Y', strtotime($s['end_date'])) ?></strong></td>
                    <td>
                        <span class="badge bg-danger"><?= $s['days_left'] ?> day<?= $s['days_left'] != 1 ? 's' : '' ?></span>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="<?= BASE_URL ?>/admin/subscriptions.php?shop_id=<?= $s['id'] ?>" class="btn btn-sm btn-success" style="font-size:0.75rem;padding:0.2rem 0.6rem;">
                                <i class="bi bi-calendar-plus me-1"></i>Renew
                            </a>
                            <?php if ($s['phone']): ?>
                            <a href="https://wa.me/92<?= preg_replace('/^0/', '', preg_replace('/[^0-9]/', '', $s['phone'])) ?>?text=<?= urlencode("Dear {$s['name']}, your Stockora POS Pro subscription expires on " . date('d M Y', strtotime($s['end_date'])) . ". Please renew to continue using the system.") ?>" 
                               target="_blank" class="btn btn-sm btn-outline-success" style="font-size:0.75rem;padding:0.2rem 0.6rem;" title="WhatsApp">
                                <i class="bi bi-whatsapp"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($days14)): ?>
<!-- Warning: Expiring 7-14 Days -->
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Warning — Expiring in 7-14 Days (<?= count($days14) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Shop</th><th>Contact</th><th>Plan</th><th>Expires</th><th>Days Left</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($days14 as $s): ?>
                <tr>
                    <td>
                        <div class="fw-bold"><?= htmlspecialchars($s['name']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($s['owner_email'] ?? '') ?></small>
                    </td>
                    <td><?= htmlspecialchars($s['phone'] ?? '-') ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($s['plan_name']) ?></span></td>
                    <td><?= date('d M Y', strtotime($s['end_date'])) ?></td>
                    <td>
                        <span class="badge bg-warning text-dark"><?= $s['days_left'] ?> days</span>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/subscriptions.php?shop_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-success" style="font-size:0.75rem;padding:0.2rem 0.6rem;">
                            <i class="bi bi-calendar-plus me-1"></i>Renew
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($expired)): ?>
<!-- Expired -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-x-circle me-2 text-danger"></i>Expired Subscriptions (<?= count($expired) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Shop</th><th>Contact</th><th>Expired On</th><th>Days Ago</th><th>Last Plan</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($expired as $s): ?>
                <tr>
                    <td>
                        <div class="fw-bold"><?= htmlspecialchars($s['name']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($s['owner_email'] ?? '') ?></small>
                    </td>
                    <td><?= htmlspecialchars($s['phone'] ?? '-') ?></td>
                    <td><span class="text-danger fw-bold"><?= date('d M Y', strtotime($s['end_date'])) ?></span></td>
                    <td><span class="badge bg-danger"><?= $s['days_expired'] ?> days ago</span></td>
                    <td><?= htmlspecialchars($s['plan_name']) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/subscriptions.php?shop_id=<?= $s['id'] ?>" class="btn btn-sm btn-danger" style="font-size:0.75rem;padding:0.2rem 0.6rem;">
                            <i class="bi bi-arrow-repeat me-1"></i>Renew Now
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($days7) && empty($days14) && empty($expired)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <div class="empty-state">
            <div class="empty-state-icon text-success"><i class="bi bi-check-circle"></i></div>
            <h5>All Good!</h5>
            <p class="text-muted">No subscriptions expiring in the next 14 days.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php adminFooter(); ?>

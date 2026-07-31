<?php
require_once '../includes/functions.php';
startSession();
if (!isShopLoggedIn()) { header('Location: ' . BASE_URL . '/login.php'); exit; }
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();

// Get subscription status
$subStatus = getSubscriptionStatus($shopId);
$isTrial = isFreeTrial($shopId);

// Get ALL subscriptions for this shop ordered by end_date
$allSubs = $db->prepare("SELECT * FROM subscriptions WHERE shop_id=? ORDER BY end_date DESC");
$allSubs->execute([$shopId]);
$allSubs = $allSubs->fetchAll();

// Get payments (read-only)
$payments = $db->prepare("SELECT * FROM payments WHERE shop_id=? ORDER BY payment_date DESC LIMIT 30");
$payments->execute([$shopId]);
$payments = $payments->fetchAll();

// --- Active subscription logic ---
// If multiple active subs exist (stacked renewals), pick:
//   - The EARLIEST currently active one as "current period" start
//   - The LATEST active end_date as the final expiry
$today = date('Y-m-d');
$activeSubs = array_filter($allSubs, fn($s) => $s['status'] === 'active' && $s['end_date'] >= $today);

$activeSub    = null;   // The currently running period (earliest start)
$latestActive = null;   // The furthest active period (latest end)
$latestSub    = $allSubs[0] ?? null; // Most recent subscription regardless of status

if (!empty($activeSubs)) {
    // Sort by start_date ASC to find the earliest currently-active
    usort($activeSubs, fn($a, $b) => strcmp($a['start_date'], $b['start_date']));
    $activeSub = array_values($activeSubs)[0];

    // Sort by end_date DESC to find the latest end
    usort($activeSubs, fn($a, $b) => strcmp($b['end_date'], $a['end_date']));
    $latestActive = array_values($activeSubs)[0];
}

shopHeader('Subscription', 'subscription');
?>
<style>
/* Subscription plans intentionally use the shop panel's dark surface.
   Do not rely on generic light-card Bootstrap utilities here. */
.subscription-plan-card {
    height: 100%;
    background: linear-gradient(145deg, #17283d, #101e30) !important;
    border: 1px solid rgba(14,206,206,.22) !important;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.04), 0 8px 20px rgba(0,0,0,.16);
    transition: transform .2s ease, border-color .2s ease;
}
.subscription-plan-card:hover {
    transform: translateY(-3px);
    border-color: rgba(14,206,206,.55) !important;
}
.subscription-plan-price { color: #f4fbff; }
.subscription-plan-note {
    background: rgba(14,206,206,.08) !important;
    border: 1px solid rgba(14,206,206,.16);
    color: #8eb8c4 !important;
}
</style>

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-calendar-check me-2 text-primary"></i>Subscription Status</h1>
    <p class="page-subtitle">View your subscription &amp; payment history</p>
</div>

<!-- Current Status Card -->
<?php if ($activeSub && $latestActive): ?>
<?php
// Total remaining = from today to the LATEST active end_date
$daysLeft  = max(0, (int)ceil((strtotime($latestActive['end_date'] . ' 23:59:59') - time()) / 86400));
$isUrgent  = $daysLeft <= 7;

// Total duration = from EARLIEST active start to LATEST active end
$totalDays = max(1, (int)(new DateTime($activeSub['start_date']))->diff(new DateTime($latestActive['end_date']))->days);
$usedDays  = max(0, $totalDays - $daysLeft);
$pct       = min(100, round(($usedDays / $totalDays) * 100));

// Count stacked periods
$stackCount = count($activeSubs);
?>
<div class="subscription-card mb-4 <?= $isUrgent ? 'subscription-expired' : '' ?>">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="badge bg-white text-success fw-bold px-3 py-2" style="font-size:0.85rem;">✓ ACTIVE</span>
                <?php if ($stackCount > 1): ?>
                <span class="badge bg-white bg-opacity-25 text-white"><?= $stackCount ?> Stacked Plans</span>
                <?php else: ?>
                <span class="badge bg-white bg-opacity-25 text-white"><?= htmlspecialchars($activeSub['plan_name']) ?></span>
                <?php endif; ?>
            </div>
            <h4 class="text-white fw-bold mb-1"><?= htmlspecialchars($_SESSION['shop_name'] ?? '') ?></h4>
            <p class="text-white mb-1 opacity-85">
                <i class="bi bi-calendar-range me-1"></i>
                Current period: <strong><?= date('d M Y', strtotime($activeSub['start_date'])) ?> → <?= date('d M Y', strtotime($latestActive['end_date'])) ?></strong>
            </p>
            <?php if ($stackCount > 1): ?>
            <p class="text-white mb-0 opacity-75">
                <i class="bi bi-layers me-1"></i>
                <?= $stackCount ?> consecutive plans stacked — total validity shown above
            </p>
            <?php else: ?>
            <p class="text-white mb-0 opacity-75">
                <i class="bi bi-cash-coin me-1"></i>
                Amount Paid: <strong>Rs. <?= number_format($activeSub['amount']) ?></strong>
            </p>
            <?php endif; ?>
        </div>
        <div class="text-center text-white">
            <div style="font-size:3rem;font-weight:900;line-height:1;"><?= $daysLeft ?></div>
            <div style="font-size:0.85rem;opacity:0.85;">Days Left</div>
        </div>
    </div>
    <?php if ($isUrgent): ?>
    <div class="alert alert-warning mt-3 mb-0 rounded-2 py-2 d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong>Renewal Required Soon!</strong> Contact admin to renew before <?= date('d M Y', strtotime($latestActive['end_date'])) ?>.
    </div>
    <?php endif; ?>
    <!-- Progress bar -->
    <div class="mt-3">
        <div class="d-flex justify-content-between text-white mb-1" style="font-size:0.78rem;opacity:0.8;">
            <span><?= date('d M Y', strtotime($activeSub['start_date'])) ?></span>
            <span><?= $pct ?>% used</span>
            <span><?= date('d M Y', strtotime($latestActive['end_date'])) ?></span>
        </div>
        <div class="progress" style="height:10px;background:rgba(255,255,255,0.2);border-radius:10px;">
            <div class="progress-bar bg-white" style="width:<?= $pct ?>%;border-radius:10px;"></div>
        </div>
    </div>
</div>

<?php if ($isTrial): ?>
<div class="alert alert-info mb-4 d-flex align-items-start gap-3" style="border-left:4px solid #0ECECE;">
    <i class="bi bi-gem fs-4"></i>
    <div class="flex-grow-1"><strong>Free Trial Mode</strong><br><span class="small">Core POS features are available during your 7-day trial. Commerce Cloud and AI Decision Engine unlock with a paid subscription.</span></div>
    <a href="https://wa.me/?text=Hello+Admin,+I+want+to+upgrade+my+Stockora+free+trial.+Shop+ID:+<?= $shopId ?>" target="_blank" class="btn btn-sm btn-primary flex-shrink-0">Upgrade</a>
</div>
<?php endif; ?>

<?php if ($stackCount > 1): ?>
<!-- Stacked periods breakdown -->
<div class="card mb-4" style="border-left:4px solid #6C63FF;">
    <div class="card-header fw-bold"><i class="bi bi-layers me-2 text-primary"></i>Active Plan Breakdown (<?= $stackCount ?> Stacked)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Plan</th><th>From</th><th>Until</th><th>Days</th><th>Amount</th></tr></thead>
            <tbody>
                <?php
                // Sort for display: earliest first
                usort($activeSubs, fn($a, $b) => strcmp($a['start_date'], $b['start_date']));
                foreach ($activeSubs as $ap):
                    $apDays = (int)(new DateTime($ap['start_date']))->diff(new DateTime($ap['end_date']))->days;
                ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($ap['plan_name']) ?></td>
                    <td><?= date('d M Y', strtotime($ap['start_date'])) ?></td>
                    <td><?= date('d M Y', strtotime($ap['end_date'])) ?></td>
                    <td><span class="badge bg-success"><?= $apDays ?> days</span></td>
                    <td>Rs. <?= number_format($ap['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="table-primary fw-bold">
                    <td colspan="3">Total Remaining</td>
                    <td colspan="2"><span class="badge bg-primary fs-6"><?= $daysLeft ?> days left</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php elseif ($latestSub): ?>
<!-- Expired subscription -->
<?php
$expiredDaysAgo = (int)((time() - strtotime($latestSub['end_date'])) / 86400);
?>
<div class="subscription-card subscription-expired mb-4">
    <div class="d-flex align-items-start gap-3 flex-wrap">
        <i class="bi bi-x-circle-fill text-white" style="font-size:2.5rem;flex-shrink:0;"></i>
        <div class="flex-grow-1">
            <h4 class="text-white fw-bold mb-1">Subscription Expired!</h4>
            <p class="text-white mb-1 opacity-85">
                Expired on <strong><?= date('d M Y', strtotime($latestSub['end_date'])) ?></strong>
                (<?= $expiredDaysAgo ?> day<?= $expiredDaysAgo != 1 ? 's' : '' ?> ago)
            </p>
            <p class="text-white mb-0 opacity-75">Your access is restricted. Contact admin to renew.</p>
        </div>
    </div>
    <div class="mt-3 p-3 rounded-2" style="background:rgba(0,0,0,0.2);">
        <div class="row text-white g-2">
            <div class="col-6"><small class="opacity-75">Monthly Price</small><br><strong>Rs. <?= number_format(SUBSCRIPTION_PRICE) ?></strong></div>
            <div class="col-6"><small class="opacity-75">Last Plan</small><br><strong><?= htmlspecialchars($latestSub['plan_name']) ?></strong></div>
        </div>
    </div>
</div>
<?php else: ?>
<!-- No subscription -->
<div class="subscription-card subscription-expired mb-4">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-slash-circle-fill text-white" style="font-size:2.5rem;"></i>
        <div>
            <h4 class="text-white fw-bold mb-1">No Subscription Found</h4>
            <p class="text-white mb-0 opacity-85">Contact your admin to activate subscription.</p>
        </div>
    </div>
    <div class="mt-3 p-3 rounded-2" style="background:rgba(0,0,0,0.2);">
        <strong class="text-white">Price:</strong>
        <span class="text-white"> Rs. <?= number_format(SUBSCRIPTION_PRICE) ?> per month</span>
    </div>
</div>
<?php endif; ?>

<!-- Contact Admin Card -->
<div class="card mb-4" style="border-left:4px solid var(--primary);background:linear-gradient(135deg,#f8f7ff,#fff);">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3">
            <div style="width:50px;height:50px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);border-radius:14px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.4rem;flex-shrink:0;">
                <i class="bi bi-headset"></i>
            </div>
            <div class="flex-grow-1">
                <h6 class="fw-bold mb-1">Need to Renew Your Subscription?</h6>
                <p class="text-muted mb-0 small">Contact your admin to renew. Monthly subscription fee: <strong>Rs. <?= number_format(SUBSCRIPTION_PRICE) ?></strong></p>
            </div>
            <?php
            $shopInfo = $db->prepare("SELECT * FROM shops WHERE id=?");
            $shopInfo->execute([$shopId]);
            $shopData = $shopInfo->fetch();
            ?>
        </div>
        <?php if (!$activeSub): ?>
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <a href="https://wa.me/?text=Hello+Admin,+I+need+to+renew+my+Stockora+subscription+for+<?= urlencode($shopData['name'] ?? 'my shop') ?>+%28Shop+ID:+<?= $shopId ?>%29" 
               target="_blank" class="btn btn-success btn-sm">
                <i class="bi bi-whatsapp me-1"></i>WhatsApp Admin
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pricing Plans -->
<div class="card mb-4">
    <div class="card-header fw-bold"><i class="bi bi-tags me-2 text-primary"></i>Subscription Plans (PKR)</div>
    <div class="card-body p-3">
        <div class="row g-3">
            <?php
            $plans = [
                ['label' => '1 Month',   'months' => 1,  'days' => 30,  'price' => 10000,  'saving' => null,            'color' => 'primary'],
                ['label' => '3 Months',  'months' => 3,  'days' => 90,  'price' => 28500,  'saving' => 'Save Rs. 1,500', 'color' => 'success'],
                ['label' => '6 Months',  'months' => 6,  'days' => 180, 'price' => 54000,  'saving' => 'Save Rs. 6,000', 'color' => 'warning'],
                ['label' => '12 Months', 'months' => 12, 'days' => 365, 'price' => 102000, 'saving' => 'Save Rs. 18,000','color' => 'danger'],
            ];
            foreach ($plans as $plan):
            ?>
            <div class="col-6 col-md-3">
                <div class="subscription-plan-card text-center p-3 rounded-3">
                    <div class="fw-bold text-<?= $plan['color'] ?> mb-1"><?= $plan['label'] ?></div>
                    <div class="subscription-plan-price fs-5 fw-bold">Rs. <?= number_format($plan['price']) ?></div>
                    <div class="text-muted small"><?= $plan['days'] ?> days</div>
                    <div class="text-muted small"><?= number_format($plan['price']/$plan['months']) ?>/mo</div>
                    <?php if ($plan['saving']): ?>
                    <div class="badge bg-<?= $plan['color'] ?> mt-1"><?= $plan['saving'] ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="subscription-plan-note mt-3 p-2 rounded-2 small text-center">
            <i class="bi bi-info-circle me-1"></i>
            Plans: 1 Month = 30 days &nbsp;|&nbsp; 3 Months = 90 days &nbsp;|&nbsp; 6 Months = 180 days &nbsp;|&nbsp; 12 Months = 365 days
        </div>
    </div>
</div>

<!-- Subscription History -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-list me-2"></i>Subscription History</div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr><th>Plan</th><th>Start</th><th>End</th><th>Days</th><th>Amount (PKR)</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($allSubs as $s): ?>
                <?php
                $isExp = $s['end_date'] < $today || $s['status'] === 'expired';
                $planDays = (int)(new DateTime($s['start_date']))->diff(new DateTime($s['end_date']))->days;
                ?>
                <tr class="<?= $isExp ? 'table-danger' : ($s['status']==='active' ? 'table-success' : '') ?>">
                    <td><?= htmlspecialchars($s['plan_name']) ?></td>
                    <td><?= date('d M Y', strtotime($s['start_date'])) ?></td>
                    <td><?= date('d M Y', strtotime($s['end_date'])) ?></td>
                    <td><span class="badge <?= $isExp ? 'bg-danger' : 'bg-success' ?>"><?= $planDays ?>d</span></td>
                    <td class="fw-bold">Rs. <?= number_format($s['amount'], 0) ?></td>
                    <td>
                        <span class="badge <?= $isExp ? 'status-expired' : ($s['status']==='active'?'status-active':'status-pending') ?>">
                            <?= ($isExp && $s['status']==='active') ? 'Expired' : ucfirst($s['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($allSubs)): ?>
                <tr><td colspan="6" class="text-center py-3 text-muted">No subscription history</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Payment History (Read-Only) -->
<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-cash-coin text-success me-1"></i>Payment History
        <span class="badge bg-secondary ms-1">Read Only</span>
        <span class="ms-auto badge bg-success">
            Total Paid: Rs. <?= number_format(array_sum(array_column($payments, 'amount')), 0) ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr><th>Amount (PKR)</th><th>Method</th><th>Reference</th><th>Date</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="fw-bold text-success">Rs. <?= number_format($p['amount'], 0) ?></td>
                    <td>
                        <?php
                        $methodIcon = match($p['payment_method']) {
                            'cash' => '💵', 'bank' => '🏦',
                            'easypaisa' => '📱', 'jazzcash' => '📱',
                            default => '🌐'
                        };
                        echo $methodIcon . ' ' . ucfirst($p['payment_method']);
                        ?>
                    </td>
                    <td><small class="text-muted"><?= htmlspecialchars($p['reference_no'] ?? '-') ?></small></td>
                    <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                    <td><span class="badge status-active">✓ Completed</span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($payments)): ?>
                <tr><td colspan="5" class="text-center py-3 text-muted">No payments recorded yet</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php shopFooter(); ?>

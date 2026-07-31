<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();
$filterShopId = safeInt($_GET['shop_id'] ?? 0);

// ─── Handle POST (PRG pattern – always redirect after POST) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // ── Add / Renew Subscription ──────────────────────────────────────────────
    if ($postAction === 'add_subscription' || $postAction === 'renew_subscription') {
        $shopId   = safeInt($_POST['shop_id'] ?? 0);
        $months   = safeInt($_POST['months'] ?? 1);
        $amount   = safeFloat($_POST['amount'] ?? SUBSCRIPTION_PRICE);
        $payMethod= sanitize($_POST['payment_method'] ?? 'cash');
        $notes    = sanitize($_POST['notes'] ?? '');
        $refNo    = sanitize($_POST['reference_no'] ?? '');

        if ($shopId < 1) {
            $_SESSION['flash'] = ['type'=>'error','text'=>'Please select a shop.'];
        } else {
            $today = date('Y-m-d');

            // Get latest ACTIVE (non-cancelled) subscription end date
            $stmt = $db->prepare("SELECT end_date FROM subscriptions
                                   WHERE shop_id=? AND status='active' AND end_date >= ?
                                   ORDER BY end_date DESC LIMIT 1");
            $stmt->execute([$shopId, $today]);
            $lastActive = $stmt->fetch();

            // Start from last active end date if still in future, otherwise today
            $startDate  = ($lastActive && $lastActive['end_date'] > $today)
                          ? $lastActive['end_date']
                          : $today;

            $planDays  = [1=>30, 3=>90, 6=>180, 12=>365];
            $daysToAdd = $planDays[$months] ?? ($months * 30);
            $endDate   = date('Y-m-d', strtotime($startDate) + ($daysToAdd * 86400));

            $planLabel = match($months) {
                1  => '1 Month',
                3  => '3 Months',
                6  => '6 Months',
                12 => '12 Months',
                default => $months.' Months'
            };

            $db->beginTransaction();
            try {
                // Mark truly expired ones (end_date in past) as expired – leave cancelled alone
                $db->prepare("UPDATE subscriptions SET status='expired'
                               WHERE shop_id=? AND status='active' AND end_date < ?")
                   ->execute([$shopId, $today]);

                // Insert new subscription
                $stmt = $db->prepare("INSERT INTO subscriptions
                    (shop_id, plan_name, amount, start_date, end_date, status,
                     payment_method, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?)");
                $stmt->execute([
                    $shopId, $planLabel, $amount, $startDate, $endDate,
                    $payMethod, $notes, $_SESSION['admin_id']
                ]);
                $subId = $db->lastInsertId();

                // Record payment
                $db->prepare("INSERT INTO payments
                    (shop_id, subscription_id, amount, payment_date,
                     payment_method, reference_no, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 'completed', ?)")
                   ->execute([$shopId, $subId, $amount, $today,
                              $payMethod, $refNo, $_SESSION['admin_id']]);

                // Activate shop
                $db->prepare("UPDATE shops SET status='active' WHERE id=?")
                   ->execute([$shopId]);

                $db->commit();

                $verb = ($postAction === 'renew_subscription') ? 'Renewed' : 'Added';
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'text' => "Subscription {$verb}! Valid from "
                              . date('d M Y', strtotime($startDate))
                              . " to " . date('d M Y', strtotime($endDate))
                              . ". Amount: Rs. " . number_format($amount)
                ];
            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['flash'] = ['type'=>'error','text'=>'Error: '.$e->getMessage()];
            }
        }
    }

    // ── Cancel Subscription ───────────────────────────────────────────────────
    elseif ($postAction === 'cancel_sub') {
        $subId = safeInt($_POST['sub_id'] ?? 0);
        $db->prepare("UPDATE subscriptions SET status='cancelled' WHERE id=?")
           ->execute([$subId]);
        $_SESSION['flash'] = ['type'=>'success','text'=>'Subscription cancelled.'];
    }

    // ── Delete Subscription (superadmin only) ─────────────────────────────────
    elseif ($postAction === 'delete_sub') {
        $subId = safeInt($_POST['sub_id'] ?? 0);
        if ($subId > 0) {
            $db->beginTransaction();
            try {
                // Delete linked payments first
                $db->prepare("DELETE FROM payments WHERE subscription_id=?")
                   ->execute([$subId]);
                // Delete subscription
                $db->prepare("DELETE FROM subscriptions WHERE id=?")
                   ->execute([$subId]);
                $db->commit();
                $_SESSION['flash'] = ['type'=>'success','text'=>'Subscription deleted permanently.'];
            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['flash'] = ['type'=>'error','text'=>'Delete failed: '.$e->getMessage()];
            }
        }
    }

    // ── Suspend Shop ──────────────────────────────────────────────────────────
    elseif ($postAction === 'suspend_shop') {
        $shopId = safeInt($_POST['shop_id'] ?? 0);
        $db->prepare("UPDATE shops SET status='suspended' WHERE id=?")
           ->execute([$shopId]);
        $db->prepare("UPDATE subscriptions SET status='expired'
                       WHERE shop_id=? AND status='active'")
           ->execute([$shopId]);
        $_SESSION['flash'] = ['type'=>'success','text'=>'Shop suspended successfully.'];
    }

    // ─── PRG: redirect to prevent duplicate on F5 / reload ───────────────────
    $qs = $filterShopId ? '?shop_id='.$filterShopId : '';
    header('Location: subscriptions.php'.$qs);
    exit;
}

// ─── Flash message (set by POST redirect) ────────────────────────────────────
$msg = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ─── Data ─────────────────────────────────────────────────────────────────────
$today = date('Y-m-d');

$query = "SELECT s.*, sh.name as shop_name, sh.phone as shop_phone, sh.status as shop_status
          FROM subscriptions s
          JOIN shops sh ON sh.id = s.shop_id";
if ($filterShopId) {
    $query .= " WHERE s.shop_id = " . (int)$filterShopId;
}
$query .= " ORDER BY s.created_at DESC";
$subscriptions = $db->query($query)->fetchAll();

$shops = $db->query("SELECT id, name, status FROM shops ORDER BY name")->fetchAll();

$selectedShop = null;
if ($filterShopId) {
    $stmt = $db->prepare("SELECT * FROM shops WHERE id=?");
    $stmt->execute([$filterShopId]);
    $selectedShop = $stmt->fetch();
}

// Stats
$totalActive   = count(array_filter($subscriptions, fn($s)=>$s['status']==='active' && $s['end_date']>=$today));
$totalExpired  = count(array_filter($subscriptions, fn($s)=>$s['end_date']<$today || $s['status']==='expired'));
$totalRevenue  = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed'")->fetchColumn();
$stmtM = $db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='completed' AND DATE_FORMAT(payment_date, '%Y-%m')=?");
$stmtM->execute([date('Y-m')]);
$thisMonthRev  = (float)$stmtM->fetch()['t'];

adminHeader('Subscriptions', 'subscriptions');
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg['type']==='error'?'danger':'success' ?> alert-dismissible fade show rounded-3">
    <i class="bi bi-<?= $msg['type']==='error'?'x-circle':'check-circle' ?>-fill me-2"></i>
    <?= htmlspecialchars($msg['text']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-calendar-check me-2 text-primary"></i>Subscriptions</h1>
        <p class="page-subtitle">Manage shop subscriptions &amp; payments</p>
    </div>
    <button class="btn btn-primary" onclick="showAddSubModal()">
        <i class="bi bi-plus-circle me-1"></i>Add / Renew Subscription
    </button>
</div>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-check-circle"></i></div>
            <div class="stat-card-value"><?= $totalActive ?></div>
            <div class="stat-card-label">Active</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-danger">
            <div class="stat-card-icon"><i class="bi bi-x-circle"></i></div>
            <div class="stat-card-value"><?= $totalExpired ?></div>
            <div class="stat-card-label">Expired</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-card-value">Rs.<?= number_format($totalRevenue/1000,0) ?>K</div>
            <div class="stat-card-label">Total Revenue</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-calendar-month"></i></div>
            <div class="stat-card-value">Rs.<?= number_format($thisMonthRev/1000,0) ?>K</div>
            <div class="stat-card-label">This Month</div>
        </div>
    </div>
</div>

<!-- Filter & Table -->
<div class="card">
    <div class="card-header d-flex gap-2 flex-wrap align-items-center">
        <span class="me-auto">
            <i class="bi bi-list me-2"></i>Subscription History
            <?php if ($selectedShop): ?>
            – <strong><?= htmlspecialchars($selectedShop['name']) ?></strong>
            <a href="<?= BASE_URL ?>subscriptions.php" class="btn btn-xs btn-outline-secondary ms-2"
               style="padding:0.15rem 0.4rem;font-size:0.75rem;">× Clear</a>
            <?php endif; ?>
        </span>
        <select class="form-select form-select-sm w-auto"
                onchange="window.location='<?= BASE_URL ?>/admin/subscriptions.php?shop_id='+this.value">
            <option value="">All Shops</option>
            <?php foreach ($shops as $sh): ?>
            <option value="<?= $sh['id'] ?>" <?= $filterShopId==$sh['id']?'selected':'' ?>>
                <?= htmlspecialchars($sh['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Shop</th>
                    <th>Plan</th>
                    <th>Amount (PKR)</th>
                    <th>Start</th>
                    <th>Expires</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subscriptions as $i => $sub): ?>
                <?php
                    $isExpired  = ($sub['end_date'] < $today) || $sub['status']==='expired';
                    $isCancelled= $sub['status'] === 'cancelled';
                    $isActive   = $sub['status'] === 'active' && !$isExpired;
                    $daysLeft   = $isActive ? max(0,(int)((strtotime($sub['end_date'])-time())/86400)) : 0;

                    // Row colour
                    if ($isActive)           $rowClass = 'table-success';
                    elseif ($isCancelled)    $rowClass = 'table-secondary';
                    elseif ($isExpired)      $rowClass = 'table-danger';
                    else                     $rowClass = '';
                ?>
                <tr class="<?= $rowClass ?>">
                    <td><?= $i+1 ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>?shop_id=<?= $sub['shop_id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($sub['shop_name']) ?>
                        </a>
                        <?php if ($sub['shop_status']==='suspended'): ?>
                        <span class="badge bg-secondary ms-1" style="font-size:0.65rem;">Suspended</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($sub['plan_name']) ?></td>
                    <td class="fw-bold">Rs. <?= number_format($sub['amount'],0) ?></td>
                    <td><?= date('d M Y', strtotime($sub['start_date'])) ?></td>
                    <td>
                        <?= date('d M Y', strtotime($sub['end_date'])) ?>
                        <?php if ($isExpired): ?>
                            <span class="badge bg-danger ms-1" style="font-size:0.65rem;">EXPIRED</span>
                        <?php elseif ($isCancelled): ?>
                            <span class="badge bg-secondary ms-1" style="font-size:0.65rem;">CANCELLED</span>
                        <?php elseif ($isActive): ?>
                            <span class="badge <?= $daysLeft<=7?'bg-warning text-dark':'bg-success' ?> ms-1"
                                  style="font-size:0.65rem;"><?= $daysLeft ?>d left</span>
                        <?php endif; ?>
                    </td>
                    <td><small><?= htmlspecialchars($sub['payment_method'] ?? 'Cash') ?></small></td>
                    <td>
                        <?php
                        if ($isActive)        echo '<span class="badge status-active">Active</span>';
                        elseif ($isCancelled) echo '<span class="badge bg-secondary">Cancelled</span>';
                        elseif ($isExpired)   echo '<span class="badge status-expired">Expired</span>';
                        else                  echo '<span class="badge bg-secondary">'.ucfirst($sub['status']).'</span>';
                        ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1 flex-wrap">
                            <?php if ($isActive): ?>
                            <!-- Extend active subscription -->
                            <button onclick="renewSub(<?= $sub['shop_id'] ?>, '<?= htmlspecialchars(addslashes($sub['shop_name'])) ?>', false)"
                                    class="btn btn-xs btn-outline-success"
                                    style="padding:0.2rem 0.5rem;font-size:0.72rem;">
                                <i class="bi bi-arrow-repeat"></i> Extend
                            </button>
                            <!-- Cancel active subscription -->
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Cancel this subscription?')">
                                <input type="hidden" name="action"  value="cancel_sub">
                                <input type="hidden" name="sub_id"  value="<?= $sub['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger"
                                        style="padding:0.2rem 0.5rem;font-size:0.72rem;">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </button>
                            </form>

                            <?php elseif ($isExpired || $isCancelled): ?>
                            <!-- Renew expired/cancelled subscription -->
                            <button onclick="renewSub(<?= $sub['shop_id'] ?>, '<?= htmlspecialchars(addslashes($sub['shop_name'])) ?>', true)"
                                    class="btn btn-xs btn-warning"
                                    style="padding:0.2rem 0.5rem;font-size:0.72rem;">
                                <i class="bi bi-arrow-repeat"></i> Renew Now
                            </button>
                            <?php endif; ?>

                            <!-- Delete button (always visible for superadmin) -->
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('⚠️ Permanently DELETE this subscription and its payment record?\n\nThis cannot be undone!')">
                                <input type="hidden" name="action"  value="delete_sub">
                                <input type="hidden" name="sub_id"  value="<?= $sub['id'] ?>">
                                <button type="button" class="btn btn-xs btn-danger"
                                        onclick="confirmDeleteSubscription(<?= (int)$sub['id'] ?>)"
                                        style="padding:0.2rem 0.5rem;font-size:0.72rem;"
                                        title="Delete permanently">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($subscriptions)): ?>
                <tr><td colspan="9" class="text-center py-4 text-muted">No subscriptions found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Delete Subscription Confirmation Modal -->
<div class="modal fade" id="deleteSubscriptionModal" tabindex="-1" aria-labelledby="deleteSubscriptionModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger" id="deleteSubscriptionModalTitle"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Subscription?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
                <p class="mb-1">This will permanently delete the subscription and its linked payment record.</p>
                <p class="text-danger fw-semibold mb-0">This action cannot be undone.</p>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_sub">
                <input type="hidden" name="sub_id" id="deleteSubscriptionId">
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1"></i>Yes, Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add / Renew Subscription Modal -->
<div class="modal fade" id="addSubModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="subModalTitle">
                    <i class="bi bi-calendar-plus me-2"></i>Add / Renew Subscription
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" id="subAction" value="add_subscription">
                <div class="modal-body">
                    <div id="expiredAlert" class="alert alert-warning py-2 d-none">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Renewing from today</strong> – previous subscription was expired/cancelled.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Shop <span class="text-danger">*</span></label>
                        <select class="form-select" name="shop_id" id="subShopSelect" required>
                            <option value="">Choose shop...</option>
                            <?php foreach ($shops as $sh): ?>
                            <option value="<?= $sh['id'] ?>" <?= $filterShopId==$sh['id']?'selected':'' ?>>
                                <?= htmlspecialchars($sh['name']) ?>
                                <?= $sh['status']==='suspended' ? ' [Suspended]' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Duration</label>
                            <select class="form-select" name="months" id="subMonths"
                                    onchange="updateSubAmount()">
                                <option value="1">1 Month</option>
                                <option value="3">3 Months (−5%)</option>
                                <option value="6">6 Months (−10%)</option>
                                <option value="12">12 Months (−15%)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Amount (PKR) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold">Rs.</span>
                                <input type="number" class="form-control" name="amount"
                                       id="subAmount" value="10000" required min="0" step="100">
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mt-0">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="cash">💵 Cash</option>
                                <option value="bank">🏦 Bank Transfer</option>
                                <option value="easypaisa">📱 EasyPaisa</option>
                                <option value="jazzcash">📱 JazzCash</option>
                                <option value="online">🌐 Online</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Reference No.</label>
                            <input type="text" class="form-control" name="reference_no"
                                   placeholder="TXN ID / Receipt No.">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"
                                  placeholder="Optional notes..."></textarea>
                    </div>
                    <div class="mt-3 p-3 rounded-3" style="background:rgba(108,99,255,.1);border:1px solid rgba(108,99,255,.25);">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Total Payable:</span>
                            <strong class="text-primary fs-5" id="subTotal">Rs. 10,000</strong>
                        </div>
                        <div class="small text-muted mt-1" id="subDateInfo">Starting today</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" id="subSubmitBtn">
                        <i class="bi bi-check-circle me-1"></i>Confirm &amp; Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const PRICE_PER_MONTH = <?= SUBSCRIPTION_PRICE ?>;

function confirmDeleteSubscription(subscriptionId) {
    document.getElementById('deleteSubscriptionId').value = subscriptionId;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteSubscriptionModal')).show();
}

function showAddSubModal(shopId = null, shopName = null, isExpired = false) {
    // Reset double-submit guard each time modal is opened
    const btn = document.getElementById('subSubmitBtn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Confirm &amp; Save'; }
    document.querySelector('#subForm')?.removeAttribute('data-submitted');

    const modal = new bootstrap.Modal(document.getElementById('addSubModal'));
    if (shopId) {
        document.getElementById('subShopSelect').value = shopId;
        document.getElementById('subAction').value = isExpired ? 'renew_subscription' : 'add_subscription';
    } else {
        document.getElementById('subAction').value = 'add_subscription';
    }
    document.getElementById('expiredAlert').classList.toggle('d-none', !isExpired);
    document.getElementById('subModalTitle').innerHTML = isExpired
        ? '<i class="bi bi-arrow-repeat me-2 text-warning"></i>Renew Subscription'
        : '<i class="bi bi-calendar-plus me-2"></i>Add / Renew Subscription';
    updateSubAmount();
    modal.show();
}

function renewSub(shopId, shopName, isExpired) {
    showAddSubModal(shopId, shopName, isExpired);
}

function updateSubAmount() {
    const months = parseInt(document.getElementById('subMonths').value);
    let discount = 0;
    if (months === 3)  discount = 0.05;
    else if (months === 6)  discount = 0.10;
    else if (months === 12) discount = 0.15;
    const total = Math.round(PRICE_PER_MONTH * months * (1 - discount));
    document.getElementById('subAmount').value = total;
    document.getElementById('subTotal').textContent = 'Rs. ' + total.toLocaleString();
    const planLabel = months === 1 ? '1 month' : months + ' months';
    document.getElementById('subDateInfo').textContent = planLabel + ' plan — valid from today';
}

updateSubAmount();

// JS double-submit guard (server-side PRG is the primary protection)
const subForm = document.getElementById('addSubModal')?.querySelector('form');
if (subForm) {
    subForm.id = 'subForm';
    subForm.addEventListener('submit', function() {
        const btn = document.getElementById('subSubmitBtn');
        if (this.dataset.submitted) { return false; }
        this.dataset.submitted = 'true';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
        }
    });
}
</script>
<?php adminFooter(); ?>

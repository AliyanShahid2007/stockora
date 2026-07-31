<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$shopId = safeInt($_GET['id'] ?? 0);
$msg = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    
    if ($postAction === 'create_shop') {
        $name = sanitize($_POST['shop_name'] ?? '');
        $ownerName = sanitize($_POST['owner_name'] ?? '');
        $email = strtolower(trim($_POST['owner_email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city = sanitize($_POST['city'] ?? '');
        $subMonths = safeInt($_POST['sub_months'] ?? 1);
        
        if (!$name || !$email || !$password) {
            redirect('shops.php', 'Shop name, email and password are required.', 'error');
        }
        // Check email unique
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            redirect('shops.php', 'Email already in use.', 'error');
        }
        $logo = null;
        if (!empty($_FILES['logo']['name'])) { $logo = uploadLogo($_FILES['logo'], 'shop'); }
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO shops (name, owner_name, email, phone, address, city, logo, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$name, $ownerName, $email, $phone, $address, $city, $logo]);
            $newShopId = $db->lastInsertId();
            $stmt = $db->prepare("INSERT INTO users (shop_id, name, email, password, role, status) VALUES (?, ?, ?, ?, 'owner', 'active')");
            $stmt->execute([$newShopId, $ownerName, $email, hashPassword($password)]);
            if ($subMonths > 0) {
                $startDate = date('Y-m-d');
                $planDays  = [1=>30, 3=>90, 6=>180, 12=>365];
                $daysToAdd = $planDays[$subMonths] ?? ($subMonths * 30);
                $endDate   = date('Y-m-d', strtotime($startDate) + ($daysToAdd * 86400));
                $amount    = SUBSCRIPTION_PRICE * $subMonths;
                $planNames = [1=>'1 Month',3=>'3 Months',6=>'6 Months',12=>'12 Months'];
                $planLabel = $planNames[$subMonths] ?? $subMonths.' Months';
                $stmt = $db->prepare("INSERT INTO subscriptions (shop_id, plan_name, amount, start_date, end_date, status, created_by) VALUES (?, ?, ?, ?, ?, 'active', ?)");
                $stmt->execute([$newShopId, $planLabel, $amount, $startDate, $endDate, $_SESSION['admin_id']]);
                $subId = $db->lastInsertId();
                $stmt = $db->prepare("INSERT INTO payments (shop_id, subscription_id, amount, payment_date, status, created_by) VALUES (?, ?, ?, ?, 'completed', ?)");
                $stmt->execute([$newShopId, $subId, $amount, $startDate, $_SESSION['admin_id']]);
            }
            $db->commit();
            redirect('shops.php', "Shop '{$name}' created! Login: {$email} / {$password}");
        } catch (Exception $e) {
            $db->rollback();
            redirect('shops.php', 'Error: ' . $e->getMessage(), 'error');
        }
    }
    
    if ($postAction === 'update_shop') {
        $id = safeInt($_POST['shop_id'] ?? 0);
        $name = sanitize($_POST['shop_name'] ?? '');
        $ownerName = sanitize($_POST['owner_name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city = sanitize($_POST['city'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active','inactive','suspended']) ? $_POST['status'] : 'active';
        $newPassword = $_POST['new_password'] ?? '';
        $logo = null;
        if (!empty($_FILES['logo']['name'])) { $logo = uploadLogo($_FILES['logo'], 'shop'); }
        $db->beginTransaction();
        try {
            if ($logo) {
                $db->prepare("UPDATE shops SET name=?, owner_name=?, phone=?, address=?, city=?, status=?, logo=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$name, $ownerName, $phone, $address, $city, $status, $logo, $id]);
            } else {
                $db->prepare("UPDATE shops SET name=?, owner_name=?, phone=?, address=?, city=?, status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$name, $ownerName, $phone, $address, $city, $status, $id]);
            }
            if ($newPassword) {
                $db->prepare("UPDATE users SET password=? WHERE shop_id=? AND role='owner'")->execute([hashPassword($newPassword), $id]);
            }
            $db->commit();
            redirect('shops.php', 'Shop updated successfully!');
        } catch (Exception $e) {
            $db->rollback();
            redirect('shops.php', 'Update failed: ' . $e->getMessage(), 'error');
        }
    }
    
    if ($postAction === 'delete_shop') {
        $id = safeInt($_POST['shop_id'] ?? 0);
        $db->prepare("DELETE FROM shops WHERE id=?")->execute([$id]);
        redirect('shops.php', 'Shop deleted.');
    }
    
    if ($postAction === 'toggle_status') {
        $id = safeInt($_POST['shop_id'] ?? 0);
        $db->prepare("UPDATE shops SET status = CASE WHEN status='active' THEN 'inactive' ELSE 'active' END WHERE id=?")->execute([$id]);
        redirect('shops.php', 'Status updated.');
    }
    
    if ($postAction === 'suspend_shop') {
        $id = safeInt($_POST['shop_id'] ?? 0);
        $reason = sanitize($_POST['suspend_reason'] ?? 'No reason given');
        $db->prepare("UPDATE shops SET status='suspended', notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$reason, $id]);
        redirect('shops.php', 'Shop suspended. Reason saved.');
    }
    
    if ($postAction === 'unsuspend_shop') {
        $id = safeInt($_POST['shop_id'] ?? 0);
        $db->prepare("UPDATE shops SET status='active', notes=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
        redirect('shops.php', 'Shop reactivated successfully!');
    }
    
    if ($postAction === 'reset_password') {
        $id = safeInt($_POST['shop_id'] ?? 0);
        $newPass = trim($_POST['new_password'] ?? '');
        if (strlen($newPass) < 6) {
            redirect('shops.php', 'Password must be at least 6 characters.', 'error');
        }
        $db->prepare("UPDATE users SET password=?, updated_at=CURRENT_TIMESTAMP WHERE shop_id=? AND role='owner'")->execute([hashPassword($newPass), $id]);
        redirect('shops.php', "Password reset! New: {$newPass}");
    }
    
    if ($postAction === 'quick_payment') {
        $id = safeInt($_POST['shop_id'] ?? 0);
        $amt = safeFloat($_POST['payment_amount'] ?? 0);
        $method = sanitize($_POST['payment_method'] ?? 'cash');
        $ref = sanitize($_POST['payment_ref'] ?? '');
        $months = safeInt($_POST['payment_months'] ?? 1);
        $notes = sanitize($_POST['payment_notes'] ?? '');
        if ($amt <= 0) { redirect('shops.php', 'Invalid payment amount.', 'error'); }
        $latestSub = $db->prepare("SELECT * FROM subscriptions WHERE shop_id=? ORDER BY end_date DESC LIMIT 1");
        $latestSub->execute([$id]);
        $sub = $latestSub->fetch();
        $startDate = ($sub && $sub['end_date'] >= date('Y-m-d')) ? $sub['end_date'] : date('Y-m-d');
        $planDays  = [1=>30, 3=>90, 6=>180, 12=>365];
        $daysToAdd = $planDays[$months] ?? ($months * 30);
        $endDate   = date('Y-m-d', strtotime($startDate) + ($daysToAdd * 86400));
        $planNames = [1=>'1 Month',3=>'3 Months',6=>'6 Months',12=>'12 Months'];
        $planLabel = $planNames[$months] ?? $months.' Months';
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO subscriptions (shop_id, plan_name, amount, months, start_date, end_date, status, created_by) VALUES (?,?,?,?,?,?,'active',?)")
               ->execute([$id, $planLabel, $amt, $months, $startDate, $endDate, $_SESSION['admin_id']]);
            $subId = $db->lastInsertId();
            $db->prepare("UPDATE subscriptions SET status='expired' WHERE shop_id=? AND end_date < ? AND status='active'")->execute([$id, date('Y-m-d')]);
            $db->prepare("UPDATE shops SET status='active' WHERE id=?")->execute([$id]);
            $db->prepare("INSERT INTO payments (shop_id, subscription_id, amount, payment_method, reference_no, notes, payment_date, status, created_by) VALUES (?,?,?,?,?,?,?,'completed',?)")
               ->execute([$id, $subId, $amt, $method, $ref, $notes, date('Y-m-d'), $_SESSION['admin_id']]);
            $db->commit();
            redirect('shops.php', 'Payment recorded! Sub extended to ' . date('d M Y', strtotime($endDate)));
        } catch (Exception $e) {
            $db->rollback();
            redirect('shops.php', 'Error: ' . $e->getMessage(), 'error');
        }
    }
}

// Get shop for edit
$editShop = null;
if ($action === 'edit' && $shopId) {
    $editShop = $db->prepare("SELECT s.*, u.email as owner_email FROM shops s LEFT JOIN users u ON u.shop_id=s.id AND u.role='owner' WHERE s.id=?")->execute([$shopId]) ? null : null;
    $stmt = $db->prepare("SELECT s.*, u.email as owner_email FROM shops s LEFT JOIN users u ON u.shop_id=s.id AND u.role='owner' WHERE s.id=?");
    $stmt->execute([$shopId]);
    $editShop = $stmt->fetch();
}

// Get all shops
$shops = $db->query("SELECT s.*, u.email as owner_email, u.last_login,
    (SELECT sub.status FROM subscriptions sub WHERE sub.shop_id=s.id ORDER BY sub.end_date DESC LIMIT 1) as sub_status,
    (SELECT sub.end_date FROM subscriptions sub WHERE sub.shop_id=s.id ORDER BY sub.end_date DESC LIMIT 1) as sub_end,
    (SELECT COUNT(*) FROM sales WHERE shop_id=s.id) as total_sales,
    (SELECT COUNT(*) FROM products WHERE shop_id=s.id) as total_products
    FROM shops s LEFT JOIN users u ON u.shop_id=s.id AND u.role='owner'
    ORDER BY s.created_at DESC")->fetchAll();

adminHeader('Manage Shops', 'shops');
?>

<?php flashMessage(); ?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-shop me-2 text-primary"></i>Manage Shops</h1>
        <p class="page-subtitle"><?= count($shops) ?> shops registered on the platform</p>
    </div>
    <button class="btn btn-primary" onclick="showCreateModal()">
        <i class="bi bi-plus-circle me-1"></i>Create New Shop
    </button>
</div>

<!-- Search -->
<div class="card mb-3">
    <div class="card-body p-3">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" id="shopSearch" placeholder="Search shops by name, email, city..." oninput="filterTable('shopSearch','shopsTable')">
        </div>
    </div>
</div>

<!-- Shops Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table" id="shopsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Shop</th>
                    <th>Owner / Email</th>
                    <th>City</th>
                    <th>Subscription</th>
                    <th>Expires</th>
                    <th>Sales</th>
                    <th>Products</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shops as $i => $shop): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($shop['logo']): ?>
                            <img src="<?= BASE_URL ?>/assets/uploads/<?= htmlspecialchars($shop['logo']) ?>" width="36" height="36" style="border-radius:8px;object-fit:cover;" alt="">
                            <?php else: ?>
                            <div style="width:36px;height:36px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);border-radius:8px;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;">
                                <?= strtoupper(substr($shop['name'],0,2)) ?>
                            </div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($shop['name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($shop['phone'] ?? '') ?></small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div><?= htmlspecialchars($shop['owner_name']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($shop['owner_email'] ?? '') ?></small>
                    </td>
                    <td><?= htmlspecialchars($shop['city'] ?? '-') ?></td>
                    <td>
                        <?php
                        $subSt = $shop['sub_status'];
                        if ($shop['sub_end'] && $shop['sub_end'] < date('Y-m-d')) $subSt = 'expired';
                        $cls = match($subSt) { 'active'=>'status-active','expired'=>'status-expired','pending'=>'status-pending',default=>'status-inactive' };
                        ?>
                        <span class="badge <?= $cls ?>"><?= ucfirst($subSt ?: 'None') ?></span>
                    </td>
                    <td><small><?= $shop['sub_end'] ? date('d M Y', strtotime($shop['sub_end'])) : '-' ?></small></td>
                    <td><span class="badge" style="background:rgba(167,139,250,.15);color:#C4B5FD;"><?= $shop['total_sales'] ?></span></td>
                    <td><span class="badge" style="background:rgba(167,139,250,.15);color:#C4B5FD;"><?= $shop['total_products'] ?></span></td>
                    <td>
                        <span class="badge <?= $shop['status']==='active'?'status-active':'status-inactive' ?>"><?= ucfirst($shop['status']) ?></span>
                    </td>
                    <td>
                        <div class="d-flex gap-1 flex-wrap">
                            <button onclick="editShop(<?= htmlspecialchars(json_encode($shop)) ?>)" class="btn btn-xs btn-outline-primary" style="padding:0.2rem 0.5rem;font-size:0.75rem;" title="Edit Shop">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="<?= BASE_URL ?>/admin/subscriptions.php?shop_id=<?= $shop['id'] ?>" class="btn btn-xs btn-outline-success" style="padding:0.2rem 0.5rem;font-size:0.75rem;" title="Manage Subscription">
                                <i class="bi bi-calendar-plus"></i>
                            </a>
                            <button onclick="quickPayment(<?= $shop['id'] ?>, '<?= htmlspecialchars(addslashes($shop['name'])) ?>')" class="btn btn-xs btn-outline-info" style="padding:0.2rem 0.5rem;font-size:0.75rem;" title="Quick Payment">
                                <i class="bi bi-cash"></i>
                            </button>
                            <button onclick="resetPassword(<?= $shop['id'] ?>, '<?= htmlspecialchars(addslashes($shop['name'])) ?>')" class="btn btn-xs btn-outline-warning" style="padding:0.2rem 0.5rem;font-size:0.75rem;" title="Reset Password">
                                <i class="bi bi-key"></i>
                            </button>
                            <?php if ($shop['status'] === 'suspended'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="unsuspend_shop">
                                <input type="hidden" name="shop_id" value="<?= $shop['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-success" style="padding:0.2rem 0.5rem;font-size:0.75rem;" title="Reactivate">
                                    <i class="bi bi-play-circle"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <button onclick="suspendShop(<?= $shop['id'] ?>, '<?= htmlspecialchars(addslashes($shop['name'])) ?>')" class="btn btn-xs btn-outline-danger" style="padding:0.2rem 0.5rem;font-size:0.75rem;" title="Suspend Shop">
                                <i class="bi bi-slash-circle"></i>
                            </button>
                            <?php endif; ?>
                            <button onclick="deleteShop(<?= $shop['id'] ?>, '<?= htmlspecialchars(addslashes($shop['name'])) ?>')" class="btn btn-xs btn-outline-danger" style="padding:0.2rem 0.5rem;font-size:0.75rem;" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($shops)): ?>
                <tr><td colspan="10" class="text-center py-5">
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="bi bi-shop"></i></div>
                        <h5>No Shops Yet</h5>
                        <p class="text-muted">Create your first shop to get started</p>
                        <button class="btn btn-primary" onclick="showCreateModal()"><i class="bi bi-plus me-1"></i>Create Shop</button>
                    </div>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Shop Modal -->
<div class="modal fade" id="createShopModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shop me-2"></i>Create New Shop</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="createShopForm">
                <input type="hidden" name="action" value="create_shop">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Shop Name *</label>
                            <input type="text" class="form-control" name="shop_name" required placeholder="e.g. Ahmed General Store">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Owner Name *</label>
                            <input type="text" class="form-control" name="owner_name" required placeholder="e.g. Muhammad Ahmed">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Owner Email (Login) *</label>
                            <input type="email" class="form-control" name="owner_email" required placeholder="owner@example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password *</label>
                            <input type="text" class="form-control" name="password" required placeholder="Set login password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="text" class="form-control" name="phone" placeholder="03XX-XXXXXXX">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city" placeholder="e.g. Lahore">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Shop Address</label>
                            <textarea class="form-control" name="address" rows="2" placeholder="Full shop address"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Subscription (Months)</label>
                            <select class="form-select" name="sub_months">
                                <option value="0">No subscription now</option>
                                <option value="1" selected>1 Month - Rs. 10,000</option>
                                <option value="3">3 Months - Rs. 30,000</option>
                                <option value="6">6 Months - Rs. 60,000</option>
                                <option value="12">12 Months - Rs. 120,000</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Shop Logo</label>
                            <input type="file" class="form-control" name="logo" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Create Shop</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Shop Modal -->
<div class="modal fade" id="editShopModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Shop</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_shop">
                <input type="hidden" name="shop_id" id="editShopId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Shop Name *</label>
                            <input type="text" class="form-control" name="shop_name" id="editShopName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Owner Name</label>
                            <input type="text" class="form-control" name="owner_name" id="editOwnerName">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" id="editPhone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city" id="editCity">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" id="editAddress" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="editStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Logo (optional)</label>
                            <input type="file" class="form-control" name="logo" accept="image/*">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Change Password (leave empty to keep current)</label>
                            <input type="text" class="form-control" name="new_password" placeholder="New password for shop owner">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteShopModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <i class="bi bi-trash-fill text-danger" style="font-size:3rem;"></i>
                <h5 class="mt-3 mb-2">Delete Shop?</h5>
                <p class="text-muted mb-1">Shop: <strong id="deleteShopName"></strong></p>
                <p class="text-muted small mb-4">This will delete all products, sales, and data for this shop.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_shop">
                    <input type="hidden" name="shop_id" id="deleteShopId">
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Suspend Shop Modal -->
<div class="modal fade" id="suspendModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-slash-circle me-2"></i>Suspend Shop</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="suspend_shop">
                <input type="hidden" name="shop_id" id="suspendShopId">
                <div class="modal-body">
                    <p>Suspending: <strong id="suspendShopName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason for Suspension *</label>
                        <select class="form-select mb-2" id="suspendReasonSelect" onchange="if(this.value) document.getElementById('suspendReason').value=this.value;">
                            <option value="">-- Select reason --</option>
                            <option value="Payment overdue">Payment overdue</option>
                            <option value="Violation of terms">Violation of terms</option>
                            <option value="Fraudulent activity">Fraudulent activity</option>
                            <option value="Request by owner">Request by owner</option>
                            <option value="Temporary hold">Temporary hold</option>
                        </select>
                        <textarea class="form-control" name="suspend_reason" id="suspendReason" rows="3" placeholder="Enter suspension reason..." required></textarea>
                    </div>
                    <div class="alert alert-warning py-2 mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Shop owner will not be able to login until unsuspended.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-slash-circle me-1"></i>Suspend Shop</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPassModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="shop_id" id="resetPassShopId">
                <div class="modal-body">
                    <p class="text-muted small">Shop: <strong id="resetPassShopName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">New Password *</label>
                        <input type="text" class="form-control" name="new_password" id="resetPassInput" required placeholder="Min 6 characters">
                        <div class="form-text">This will immediately replace the shop owner's password.</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('resetPassInput').value=generatePass()">
                        <i class="bi bi-shuffle me-1"></i>Generate Password
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-key me-1"></i>Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Payment Modal -->
<div class="modal fade" id="quickPayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Quick Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="quick_payment">
                <input type="hidden" name="shop_id" id="qpShopId">
                <div class="modal-body">
                    <p class="mb-3">Recording payment for: <strong id="qpShopName" class="text-success"></strong></p>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Months *</label>
                            <select class="form-select" name="payment_months" id="qpMonths" onchange="calcQpAmount()">
                                <option value="1">1 Month</option>
                                <option value="3">3 Months</option>
                                <option value="6">6 Months</option>
                                <option value="12">12 Months</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Amount (Rs.) *</label>
                            <input type="number" class="form-control" name="payment_amount" id="qpAmount" min="1" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="easypaisa">EasyPaisa</option>
                                <option value="jazzcash">JazzCash</option>
                                <option value="online">Online</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Reference No.</label>
                            <input type="text" class="form-control" name="payment_ref" placeholder="Optional">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea class="form-control" name="payment_notes" rows="2" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 py-2 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Subscription will be automatically extended from current expiry date.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const SUBSCRIPTION_PRICE = <?= SUBSCRIPTION_PRICE ?>;

function showCreateModal() {
    new bootstrap.Modal(document.getElementById('createShopModal')).show();
}

function editShop(shop) {
    document.getElementById('editShopId').value = shop.id;
    document.getElementById('editShopName').value = shop.name;
    document.getElementById('editOwnerName').value = shop.owner_name || '';
    document.getElementById('editPhone').value = shop.phone || '';
    document.getElementById('editCity').value = shop.city || '';
    document.getElementById('editAddress').value = shop.address || '';
    document.getElementById('editStatus').value = shop.status || 'active';
    new bootstrap.Modal(document.getElementById('editShopModal')).show();
}

function deleteShop(id, name) {
    document.getElementById('deleteShopId').value = id;
    document.getElementById('deleteShopName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteShopModal')).show();
}

function suspendShop(id, name) {
    document.getElementById('suspendShopId').value = id;
    document.getElementById('suspendShopName').textContent = name;
    document.getElementById('suspendReason').value = '';
    new bootstrap.Modal(document.getElementById('suspendModal')).show();
}

function resetPassword(id, name) {
    document.getElementById('resetPassShopId').value = id;
    document.getElementById('resetPassShopName').textContent = name;
    document.getElementById('resetPassInput').value = '';
    new bootstrap.Modal(document.getElementById('resetPassModal')).show();
}

function quickPayment(id, name) {
    document.getElementById('qpShopId').value = id;
    document.getElementById('qpShopName').textContent = name;
    calcQpAmount();
    new bootstrap.Modal(document.getElementById('quickPayModal')).show();
}

function calcQpAmount() {
    const months = parseInt(document.getElementById('qpMonths').value) || 1;
    let base = SUBSCRIPTION_PRICE * months;
    if (months === 3) base = Math.round(base * 0.95);
    else if (months === 6) base = Math.round(base * 0.90);
    else if (months === 12) base = Math.round(base * 0.85);
    document.getElementById('qpAmount').value = base;
}

function generatePass() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    let pass = '';
    for (let i = 0; i < 8; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    return pass;
}
</script>

<?php adminFooter(); ?>

<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_shop') {
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city = sanitize($_POST['city'] ?? '');
        
        $logo = null;
        if (!empty($_FILES['logo']['name'])) {
            $logo = uploadLogo($_FILES['logo'], 'shop');
        }
        
        if ($logo) {
            $db->prepare("UPDATE shops SET phone=?, address=?, city=?, logo=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$phone, $address, $city, $logo, $shopId]);
        } else {
            $db->prepare("UPDATE shops SET phone=?, address=?, city=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$phone, $address, $city, $shopId]);
        }
        
        $settings = ['thank_you_msg', 'invoice_footer', 'tax_rate', 'show_company_info'];
        foreach ($settings as $key) {
            if (isset($_POST[$key])) {
                setShopSetting($shopId, $key, sanitize($_POST[$key]));
            }
        }
        redirect('settings.php', 'Settings saved successfully!');
    }
    
    if ($action === 'change_password') {
        $currentPwd = $_POST['current_password'] ?? '';
        $newPwd = $_POST['new_password'] ?? '';
        $confirmPwd = $_POST['confirm_password'] ?? '';
        
        if (!$currentPwd || !$newPwd) {
            redirect('settings.php', 'Please fill all password fields', 'error');
        } elseif ($newPwd !== $confirmPwd) {
            redirect('settings.php', 'New passwords do not match', 'error');
        } elseif (strlen($newPwd) < 6) {
            redirect('settings.php', 'Password must be at least 6 characters', 'error');
        } else {
            $stmt = $db->prepare("SELECT password FROM users WHERE id=?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            if (!verifyPassword($currentPwd, $user['password'])) {
                redirect('settings.php', 'Current password is incorrect', 'error');
            } else {
                $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([hashPassword($newPwd), $_SESSION['user_id']]);
                redirect('settings.php', 'Password changed successfully!');
            }
        }
    }
}

$shop = getCurrentShop();
$thankYou = getShopSetting($shopId, 'thank_you_msg', 'Thank you for your purchase!');
$footerNote = getShopSetting($shopId, 'invoice_footer', 'Goods once sold will not be returned.');
$taxRate = getShopSetting($shopId, 'tax_rate', '0');

shopHeader('Settings', 'settings');
?>

<?php flashMessage(); ?>

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-gear me-2 text-primary"></i>Shop Settings</h1>
    <p class="page-subtitle">Manage your shop profile and preferences</p>
</div>

<div class="row g-3">
    <!-- Shop Info -->
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-shop me-2 text-primary"></i>Shop Information</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_shop">
                    
                    <!-- Current Logo -->
                    <?php if (!empty($shop['logo'])): ?>
                    <div class="text-center mb-3">
                        <img src="<?= BASE_URL ?>/assets/uploads/<?= htmlspecialchars($shop['logo']) ?>" width="80" height="80" style="border-radius:12px;object-fit:cover;" alt="Logo">
                        <p class="text-muted small mt-1">Current Logo</p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Shop Name</label>
                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($shop['name'] ?? '') ?>" readonly>
                        <small class="text-muted">Contact admin to change shop name</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($shop['phone'] ?? '') ?>" placeholder="03XX-XXXXXXX">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">City</label>
                        <input type="text" class="form-control" name="city" value="<?= htmlspecialchars($shop['city'] ?? '') ?>" placeholder="Your city">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address (shown on invoice)</label>
                        <textarea class="form-control" name="address" rows="3" placeholder="Full shop address"><?= htmlspecialchars($shop['address'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Shop Logo (for invoice)</label>
                        <input type="file" class="form-control" name="logo" accept="image/*">
                        <small class="text-muted">Recommended: Square image, max 2MB</small>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Save Shop Info</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Invoice Settings -->
    <div class="col-12 col-lg-6">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-receipt me-2 text-primary"></i>Invoice Settings</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_shop">
                    <div class="mb-3">
                        <label class="form-label">Thank You Message</label>
                        <input type="text" class="form-control" name="thank_you_msg" value="<?= htmlspecialchars($thankYou) ?>" placeholder="Thank you for your purchase!">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Invoice Footer Note</label>
                        <textarea class="form-control" name="invoice_footer" rows="2" placeholder="Return policy, notes..."><?= htmlspecialchars($footerNote) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tax Rate (%)</label>
                        <input type="number" class="form-control" name="tax_rate" value="<?= htmlspecialchars($taxRate) ?>" min="0" max="100" step="0.01" placeholder="0 = No tax">
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Save Invoice Settings</button>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-header"><i class="bi bi-lock me-2 text-primary"></i>Change Password</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-2">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required>
                    </div>
                    <button type="submit" class="btn btn-warning w-100"><i class="bi bi-lock me-1"></i>Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php shopFooter(); ?>

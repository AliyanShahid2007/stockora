<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_admin') {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        if ($name && $email) {
            $db->prepare("UPDATE admins SET name=?, email=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$name, $email, $_SESSION['admin_id']]);
            if ($newPassword && strlen($newPassword) >= 6) {
                $db->prepare("UPDATE admins SET password=? WHERE id=?")->execute([hashPassword($newPassword), $_SESSION['admin_id']]);
            }
            $_SESSION['admin_name'] = $name;
            redirect('settings.php', 'Settings updated!');
        }
    }
}

$admin = $db->prepare("SELECT * FROM admins WHERE id=?")->execute([$_SESSION['admin_id']]) ? null : null;
$stmt = $db->prepare("SELECT * FROM admins WHERE id=?");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch();

adminHeader('Settings', 'settings');
?>
<?php flashMessage(); ?>

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-gear me-2 text-primary"></i>Admin Settings</h1>
</div>

<div class="row g-3">
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-person me-2"></i>Admin Profile</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_admin">
                    <div class="mb-3"><label class="form-label">Name</label><input type="text" class="form-control" name="name" value="<?= htmlspecialchars($admin['name'] ?? '') ?>" required></div>
                    <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= htmlspecialchars($admin['email'] ?? '') ?>" required></div>
                    <div class="mb-3"><label class="form-label">New Password (leave blank to keep)</label><input type="password" class="form-control" name="new_password" minlength="6" placeholder="Min 6 characters"></div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Save Changes</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>System Info</div>
            <div class="card-body">
                <div class="mb-3 d-flex justify-content-between border-bottom pb-2"><span class="text-muted">App Name</span><strong><?= APP_NAME ?></strong></div>
                <div class="mb-3 d-flex justify-content-between border-bottom pb-2"><span class="text-muted">Version</span><strong><?= APP_VERSION ?></strong></div>
                <div class="mb-3 d-flex justify-content-between border-bottom pb-2"><span class="text-muted">PHP Version</span><strong><?= PHP_VERSION ?></strong></div>
                <div class="mb-3 d-flex justify-content-between border-bottom pb-2"><span class="text-muted">Database</span><strong>MySQL (MariaDB)</strong></div>
                <div class="mb-3 d-flex justify-content-between border-bottom pb-2"><span class="text-muted">Subscription Price</span><strong>Rs. <?= number_format(SUBSCRIPTION_PRICE) ?>/month</strong></div>
                <div class="d-flex justify-content-between"><span class="text-muted">Server Time</span><strong><?= date('d M Y, h:i A') ?></strong></div>
            </div>
        </div>
    </div>
</div>
<?php adminFooter(); ?>

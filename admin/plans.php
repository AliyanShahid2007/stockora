<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();
$db->exec("CREATE TABLE IF NOT EXISTS subscription_plans (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE,
    monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0, original_price DECIMAL(10,2) NULL,
    description VARCHAR(255) NULL, features TEXT NULL, trial_days INT NOT NULL DEFAULT 0,
    offer_valid_months INT NULL, badge_text VARCHAR(100) NULL, is_featured TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active', sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$seedPlan = $db->prepare('INSERT IGNORE INTO subscription_plans (name,monthly_price,original_price,description,features,trial_days,offer_valid_months,badge_text,is_featured,status,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$seedPlan->execute(['Basic',8000,8000,'Perfect for small shops just getting started with digital POS.',"POS Billing (Unlimited)\nInventory Management\nBasic Sales Reports\nCustomer Management",7,3,'7 Days Free Trial',0,'active',1]);
$seedPlan->execute(['Professional',15000,null,'Full-featured plan for growing businesses who need every tool.',"Everything in Basic\nAI Smart Lab Access\nAdvanced Analytics\nExpense & Finance Tracking\nDaily Target Monitoring\nCommerce Cloud Store\nPriority Support",0,null,'Most Popular',1,'active',2]);
$seedPlan->execute(['Enterprise',0,null,'For multi-branch chains and wholesale businesses.',"Everything in Pro\nMulti-Branch Management\nCustom Integrations\nDedicated Account Manager\nOn-site Training\nSLA Guarantee",0,null,'',0,'active',3]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $db->prepare('DELETE FROM subscription_plans WHERE id=?')->execute([safeInt($_POST['id'] ?? 0)]);
        $_SESSION['flash'] = ['type'=>'success','text'=>'Plan deleted.'];
    } elseif ($action === 'save') {
        $id = safeInt($_POST['id'] ?? 0);
        $data = [
            sanitize($_POST['name'] ?? ''), safeFloat($_POST['monthly_price'] ?? 0),
            ($_POST['original_price'] ?? '') === '' ? null : safeFloat($_POST['original_price']),
            sanitize($_POST['description'] ?? ''), trim($_POST['features'] ?? ''),
            safeInt($_POST['trial_days'] ?? 0), ($_POST['offer_valid_months'] ?? '') === '' ? null : safeInt($_POST['offer_valid_months']),
            sanitize($_POST['badge_text'] ?? ''), isset($_POST['is_featured']) ? 1 : 0,
            $_POST['status'] === 'inactive' ? 'inactive' : 'active', safeInt($_POST['sort_order'] ?? 0)
        ];
        if ($data[0] === '') {
            $_SESSION['flash'] = ['type'=>'error','text'=>'Plan name is required.'];
        } else {
            try {
                if ($id) {
                    $data[] = $id;
                    $db->prepare('UPDATE subscription_plans SET name=?,monthly_price=?,original_price=?,description=?,features=?,trial_days=?,offer_valid_months=?,badge_text=?,is_featured=?,status=?,sort_order=? WHERE id=?')->execute($data);
                    $_SESSION['flash'] = ['type'=>'success','text'=>'Plan updated.'];
                } else {
                    $db->prepare('INSERT INTO subscription_plans (name,monthly_price,original_price,description,features,trial_days,offer_valid_months,badge_text,is_featured,status,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute($data);
                    $_SESSION['flash'] = ['type'=>'success','text'=>'Plan added.'];
                }
            } catch (Exception $e) { $_SESSION['flash'] = ['type'=>'error','text'=>'Could not save plan. Plan names must be unique.']; }
        }
    }
    header('Location: plans.php'); exit;
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$plans = $db->query('SELECT * FROM subscription_plans ORDER BY sort_order, id')->fetchAll();
adminHeader('Plans Management', 'plans');
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash['type']==='error'?'danger':'success' ?> alert-dismissible fade show rounded-3"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div><h1 class="page-title"><i class="bi bi-tags-fill me-2 text-primary"></i>Plans Management</h1><p class="page-subtitle">Create pricing plans, free trials, offers and strike-through prices for the landing page.</p></div>
  <button class="btn btn-primary" onclick="openPlanModal()"><i class="bi bi-plus-circle me-1"></i>Add Plan</button>
</div>
<div class="card"><div class="card-header"><strong>Subscription Plans</strong></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Plan</th><th>Price</th><th>Trial / Offer</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($plans as $plan): ?>
<tr><td><strong><?= htmlspecialchars($plan['name']) ?></strong><?php if ($plan['is_featured']): ?><span class="badge bg-primary ms-1">Featured</span><?php endif; ?><div class="small text-muted mt-1"><?= htmlspecialchars($plan['description'] ?? '') ?></div></td>
<td><?php if ($plan['original_price']): ?><del class="text-danger me-1">Rs.<?= number_format($plan['original_price']) ?></del><?php endif; ?><strong>Rs.<?= number_format($plan['monthly_price']) ?>/mo</strong></td>
<td><?php if ($plan['trial_days']): ?><span class="badge bg-success"><?= $plan['trial_days'] ?> days free trial</span><?php endif; ?> <?php if ($plan['offer_valid_months']): ?><span class="small text-muted">Valid <?= $plan['offer_valid_months'] ?> months</span><?php endif; ?><div class="small text-warning"><?= htmlspecialchars($plan['badge_text'] ?? '') ?></div></td>
<td><span class="badge <?= $plan['status']==='active'?'bg-success':'bg-secondary' ?>"><?= ucfirst($plan['status']) ?></span></td>
<td><button class="btn btn-xs btn-outline-primary" onclick='openPlanModal(<?= htmlspecialchars(json_encode($plan), ENT_QUOTES, "UTF-8") ?>)'><i class="bi bi-pencil"></i></button> <button class="btn btn-xs btn-outline-danger" onclick="confirmPlanDelete(<?= (int)$plan['id'] ?>)"><i class="bi bi-trash3"></i></button></td></tr>
<?php endforeach; ?>
<?php if (!$plans): ?><tr><td colspan="5" class="text-center text-muted py-4">No plans yet. Add your first plan.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="modal fade" id="planModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><form method="POST"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="planId"><div class="modal-header"><h5 class="modal-title" id="planModalTitle">Add Plan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label">Plan name *</label><input class="form-control" required name="name" id="planName"></div><div class="col-md-3"><label class="form-label">Monthly price *</label><input class="form-control" required min="0" type="number" name="monthly_price" id="planPrice"></div><div class="col-md-3"><label class="form-label">Old price (strike)</label><input class="form-control" min="0" type="number" name="original_price" id="planOriginalPrice"></div><div class="col-md-6"><label class="form-label">Trial days</label><input class="form-control" min="0" type="number" name="trial_days" id="planTrialDays" value="0"></div><div class="col-md-6"><label class="form-label">Offer valid (months)</label><input class="form-control" min="0" type="number" name="offer_valid_months" id="planOfferMonths"></div><div class="col-md-6"><label class="form-label">Offer / badge text</label><input class="form-control" name="badge_text" id="planBadge" placeholder="7 Days Free Trial"></div><div class="col-md-3"><label class="form-label">Sort order</label><input class="form-control" type="number" name="sort_order" id="planSort" value="0"></div><div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status" id="planStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description" id="planDescription"></div><div class="col-12"><label class="form-label">Features <small class="text-muted">(one per line)</small></label><textarea class="form-control" rows="5" name="features" id="planFeatures"></textarea></div><div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="is_featured" id="planFeatured"><label class="form-check-label" for="planFeatured">Mark as featured / most popular</label></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Plan</button></div></form></div></div></div>
<form method="POST" id="deletePlanForm"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deletePlanId"></form>
<div class="modal fade" id="deletePlanModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete plan?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">This plan will be removed from the landing page. This cannot be undone.</div><div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" onclick="document.getElementById('deletePlanForm').submit()">Yes, Delete</button></div></div></div></div>
<script>
function openPlanModal(plan){ plan=plan||{}; document.getElementById('planModalTitle').textContent=plan.id?'Edit Plan':'Add Plan'; ['Id','Name','Price','OriginalPrice','TrialDays','OfferMonths','Badge','Sort','Description','Features'].forEach(function(k){var e=document.getElementById('plan'+k);if(e)e.value=plan[{Id:'id',Name:'name',Price:'monthly_price',OriginalPrice:'original_price',TrialDays:'trial_days',OfferMonths:'offer_valid_months',Badge:'badge_text',Sort:'sort_order',Description:'description',Features:'features'}[k]]||'';}); document.getElementById('planStatus').value=plan.status||'active'; document.getElementById('planFeatured').checked=!!Number(plan.is_featured); bootstrap.Modal.getOrCreateInstance(document.getElementById('planModal')).show(); }
function confirmPlanDelete(id){ document.getElementById('deletePlanId').value=id; bootstrap.Modal.getOrCreateInstance(document.getElementById('deletePlanModal')).show(); }
</script>
<?php adminFooter(); ?>

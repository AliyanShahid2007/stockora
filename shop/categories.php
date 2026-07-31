<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
        $id = safeInt($_POST['cat_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $desc = sanitize($_POST['description'] ?? '');
        if (!$name) {
            redirect('categories.php', 'Category name required', 'error');
        }
        if ($action === 'create') {
            $db->prepare("INSERT INTO categories (shop_id, name, description) VALUES (?,?,?)")->execute([$shopId, $name, $desc]);
            redirect('categories.php', "Category '{$name}' created!");
        } else {
            $db->prepare("UPDATE categories SET name=?, description=? WHERE id=? AND shop_id=?")->execute([$name, $desc, $id, $shopId]);
            redirect('categories.php', 'Category updated!');
        }
    }
    if ($action === 'delete') {
        $id = safeInt($_POST['cat_id'] ?? 0);
        $cnt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id=? AND shop_id=?");
        $cnt->execute([$id, $shopId]);
        if ($cnt->fetchColumn() > 0) {
            redirect('categories.php', 'Cannot delete category with products. Remove products first.', 'error');
        } else {
            $db->prepare("DELETE FROM categories WHERE id=? AND shop_id=?")->execute([$id, $shopId]);
            redirect('categories.php', 'Category deleted.');
        }
    }
}

$categories = $db->prepare("SELECT c.*, COUNT(p.id) as product_count FROM categories c LEFT JOIN products p ON p.category_id=c.id AND p.status='active' WHERE c.shop_id=? GROUP BY c.id ORDER BY c.name");
$categories->execute([$shopId]);
$categories = $categories->fetchAll();

shopHeader('Categories', 'categories');
?>
<?php flashMessage(); ?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h1 class="page-title"><i class="bi bi-tags me-2 text-primary"></i>Categories</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#catModal" onclick="setCatMode('create')">
        <i class="bi bi-plus-circle me-1"></i>Add Category
    </button>
</div>

<div class="row g-3">
    <?php foreach ($categories as $cat): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <div class="card text-center py-3 px-2">
            <div style="width:50px;height:50px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.4rem;margin:0 auto 0.75rem;">🏷</div>
            <h6 class="fw-bold mb-1"><?= htmlspecialchars($cat['name']) ?></h6>
            <small class="text-muted"><?= $cat['product_count'] ?> products</small>
            <?php if ($cat['description']): ?><p class="text-muted small mt-1 mb-2"><?= htmlspecialchars($cat['description']) ?></p><?php endif; ?>
            <div class="d-flex gap-1 justify-content-center mt-2">
                <button onclick="editCat(<?= htmlspecialchars(json_encode($cat)) ?>)" class="btn btn-xs btn-outline-primary" style="font-size:0.75rem;padding:0.2rem 0.5rem;"><i class="bi bi-pencil"></i></button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                    <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:0.75rem;padding:0.2rem 0.5rem;" onclick="return confirm('Delete this category?')"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($categories)): ?>
    <div class="col-12 text-center py-5">
        <div class="empty-state">
            <div class="empty-state-icon"><i class="bi bi-tags"></i></div>
            <h5>No Categories Yet</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#catModal" onclick="setCatMode('create')">Add First Category</button>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="catModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="catModalTitle">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" id="catAction" value="create">
                <input type="hidden" name="cat_id" id="catId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" class="form-control" name="name" id="catName" required placeholder="e.g. Grocery, Electronics">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" id="catDesc" placeholder="Optional">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function setCatMode(mode) {
    document.getElementById('catAction').value = mode;
    document.getElementById('catModalTitle').textContent = mode === 'create' ? 'Add Category' : 'Edit Category';
    if (mode === 'create') { document.getElementById('catName').value = ''; document.getElementById('catDesc').value = ''; }
}
function editCat(cat) {
    document.getElementById('catAction').value = 'update';
    document.getElementById('catId').value = cat.id;
    document.getElementById('catName').value = cat.name;
    document.getElementById('catDesc').value = cat.description || '';
    document.getElementById('catModalTitle').textContent = 'Edit Category';
    new bootstrap.Modal(document.getElementById('catModal')).show();
}
</script>
<?php shopFooter(); ?>

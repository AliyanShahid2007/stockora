<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title   = sanitize($_POST['title'] ?? '');
        $message = sanitize($_POST['message'] ?? '');
        $type    = in_array($_POST['type'] ?? '', ['info','warning','success','danger']) ? $_POST['type'] : 'info';
        $target  = sanitize($_POST['target'] ?? 'all');
        $expires = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        if (!$title || !$message) {
            redirect('announcements.php', 'Title and message are required.', 'error');
        }
        $db->prepare("INSERT INTO announcements (title,message,type,status,created_by) VALUES (?,?,?,'active',?)")
           ->execute([$title, $message, $type, $_SESSION['admin_id']]);
        redirect('announcements.php', 'Announcement broadcasted!');
    }
    if ($action === 'delete') {
        $id = safeInt($_POST['ann_id'] ?? 0);
        $db->prepare("DELETE FROM announcements WHERE id=?")->execute([$id]);
        redirect('announcements.php', 'Announcement deleted.');
    }
    if ($action === 'toggle') {
        $id = safeInt($_POST['ann_id'] ?? 0);
        $db->prepare("UPDATE announcements SET status=CASE WHEN status='active' THEN 'inactive' ELSE 'active' END WHERE id=?")->execute([$id]);
        redirect('announcements.php', 'Status toggled.');
    }
}

$announcements = $db->query("SELECT * FROM announcements ORDER BY created_at DESC")->fetchAll();
$shopCount = (int)$db->query("SELECT COUNT(*) FROM shops WHERE status='active'")->fetchColumn();

adminHeader('Announcements', 'announcements');
?>

<?php flashMessage(); ?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-megaphone me-2 text-primary"></i>Announcements</h1>
        <p class="page-subtitle">Broadcast messages to <?= $shopCount ?> active shop owners</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('addAnnModal').classList.add('show');document.getElementById('addAnnModal').style.display='block'">
        <i class="bi bi-plus-circle me-1"></i>New Announcement
    </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <?php
    $active = count(array_filter($announcements, fn($a) => $a['status']==='active'));
    $inactive = count($announcements) - $active;
    $types = array_count_values(array_column($announcements, 'type'));
    ?>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-check-circle"></i></div>
            <div class="stat-card-value"><?= $active ?></div>
            <div class="stat-card-label">Active</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-secondary">
            <div class="stat-card-icon"><i class="bi bi-pause-circle"></i></div>
            <div class="stat-card-value"><?= $inactive ?></div>
            <div class="stat-card-label">Inactive</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-shop"></i></div>
            <div class="stat-card-value"><?= $shopCount ?></div>
            <div class="stat-card-label">Shops Reached</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-megaphone"></i></div>
            <div class="stat-card-value"><?= count($announcements) ?></div>
            <div class="stat-card-label">Total Sent</div>
        </div>
    </div>
</div>

<!-- Announcements List -->
<div class="card">
    <div class="card-header"><i class="bi bi-list me-2"></i>All Announcements</div>
    <?php if (empty($announcements)): ?>
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-megaphone fs-1 d-block mb-3 opacity-25"></i>
        <h6>No announcements yet</h6>
        <p class="small">Create your first announcement to notify all shop owners</p>
    </div>
    <?php else: ?>
    <div class="card-body p-3">
        <?php foreach ($announcements as $ann):
            $typeColors = ['info'=>['bg'=>'rgba(59,130,246,.12)','border'=>'#60a5fa','text'=>'#93c5fd','icon'=>'info-circle'],
                           'warning'=>['bg'=>'rgba(245,158,11,.12)','border'=>'#f59e0b','text'=>'#fcd34d','icon'=>'exclamation-triangle'],
                           'success'=>['bg'=>'rgba(16,185,129,.12)','border'=>'#34d399','text'=>'#6ee7b7','icon'=>'check-circle'],
                           'danger'=>['bg'=>'rgba(239,68,68,.12)','border'=>'#f87171','text'=>'#fca5a5','icon'=>'x-circle']];
            $tc = $typeColors[$ann['type']] ?? $typeColors['info'];
            $isExpired = $ann['expires_at'] && $ann['expires_at'] < date('Y-m-d H:i:s');
        ?>
        <div class="mb-3 p-3 rounded-3 <?= $ann['status']==='inactive' ? 'opacity-50' : '' ?>" style="background:<?= $tc['bg'] ?>;border-left:4px solid <?= $tc['border'] ?>;">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <i class="bi bi-<?= $tc['icon'] ?>" style="color:<?= $tc['text'] ?>;"></i>
                        <strong><?= htmlspecialchars($ann['title']) ?></strong>
                        <span class="badge" style="background:<?= $tc['text'] ?>;color:white;"><?= ucfirst($ann['type']) ?></span>
                        <?php if ($ann['status']==='active' && !$isExpired): ?>
                        <span class="badge bg-success">Active</span>
                        <?php elseif ($isExpired): ?>
                        <span class="badge bg-secondary">Expired</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </div>
                    <p class="mb-1 small" style="color:#f0ecff!important;"><?= nl2br(htmlspecialchars($ann['message'])) ?></p>
                    <div class="d-flex gap-3 flex-wrap" style="font-size:0.75rem;color:var(--text2,#8eb8c4);">
                        <span><i class="bi bi-calendar3 me-1"></i><?= date('d M Y H:i', strtotime($ann['created_at'])) ?></span>
                        <span><i class="bi bi-people me-1"></i>Target: <?= htmlspecialchars($ann['target']) ?></span>
                        <?php if ($ann['expires_at']): ?>
                        <span><i class="bi bi-clock me-1"></i>Expires: <?= date('d M Y', strtotime($ann['expires_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
                        <button type="submit" class="btn btn-xs btn-outline-secondary" style="padding:.2rem .5rem;font-size:.72rem;" title="Toggle">
                            <i class="bi bi-toggle-<?= $ann['status']==='active'?'on':'off' ?>"></i>
                        </button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this announcement?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
                        <button type="submit" class="btn btn-xs btn-outline-danger" style="padding:.2rem .5rem;font-size:.72rem;">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add Announcement Modal -->
<div class="modal fade" id="addAnnModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-megaphone me-2"></i>New Announcement</h5>
                <button type="button" class="btn-close" onclick="document.getElementById('addAnnModal').classList.remove('show');document.getElementById('addAnnModal').style.display='none'"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" required placeholder="e.g., System Maintenance Notice">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="message" rows="4" required placeholder="Write your announcement message here..."></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Type</label>
                            <select class="form-select" name="type">
                                <option value="info">ℹ️ Info</option>
                                <option value="warning">⚠️ Warning</option>
                                <option value="success">✅ Success</option>
                                <option value="danger">🚨 Urgent</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Target</label>
                            <select class="form-select" name="target">
                                <option value="all">All Shops</option>
                                <option value="active">Active Only</option>
                                <option value="expiring">Expiring Soon</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Expires On (optional)</label>
                        <input type="date" class="form-control" name="expires_at" min="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('addAnnModal').classList.remove('show');document.getElementById('addAnnModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Broadcast Now</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php adminFooter(); ?>

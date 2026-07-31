<?php
require_once '../includes/functions.php';
requireAdmin();

$db = getDB();
$isReport = ($_GET['report'] ?? '') === '1';

// One row per active shop makes it easy to compare adoption of both features.
$shopRows = $db->query("SELECT s.id AS shop_id, s.name AS shop_name,
    COALESCE(MAX(CASE WHEN u.feature_key='commerce_cloud' THEN u.use_count END), 0) AS commerce_uses,
    COALESCE(MAX(CASE WHEN u.feature_key='ai_lab' THEN u.use_count END), 0) AS ai_uses,
    MIN(CASE WHEN u.feature_key='commerce_cloud' THEN u.first_used_at END) AS commerce_first_used,
    MAX(CASE WHEN u.feature_key='commerce_cloud' THEN u.last_used_at END) AS commerce_last_used,
    MIN(CASE WHEN u.feature_key='ai_lab' THEN u.first_used_at END) AS ai_first_used,
    MAX(CASE WHEN u.feature_key='ai_lab' THEN u.last_used_at END) AS ai_last_used,
    MAX(u.last_used_at) AS last_used_at
    FROM shops s
    LEFT JOIN shop_feature_usage u ON u.shop_id=s.id AND u.feature_key IN ('commerce_cloud','ai_lab')
    WHERE s.status='active'
    GROUP BY s.id, s.name
    HAVING commerce_uses > 0 OR ai_uses > 0
    ORDER BY last_used_at DESC, s.name ASC")->fetchAll();

$activeShops = (int)$db->query("SELECT COUNT(*) FROM shops WHERE status='active'")->fetchColumn();
$commerceShops = count(array_filter($shopRows, fn($row) => (int)$row['commerce_uses'] > 0));
$aiShops = count(array_filter($shopRows, fn($row) => (int)$row['ai_uses'] > 0));
$bothShops = count(array_filter($shopRows, fn($row) => (int)$row['commerce_uses'] > 0 && (int)$row['ai_uses'] > 0));
$adoptedShops = count($shopRows);
$commerceOpens = array_sum(array_column($shopRows, 'commerce_uses'));
$aiOpens = array_sum(array_column($shopRows, 'ai_uses'));
$commerceRate = $activeShops ? round($commerceShops / $activeShops * 100, 1) : 0;
$aiRate = $activeShops ? round($aiShops / $activeShops * 100, 1) : 0;
$overallRate = $activeShops ? round($adoptedShops / $activeShops * 100, 1) : 0;

if (($_GET['download'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stockora_feature_adoption_report_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Stockora Feature Adoption Report']);
    fputcsv($out, ['Generated at', date('d M Y H:i')]);
    fputcsv($out, ['Active shops', $activeShops]);
    fputcsv($out, ['Overall feature success rate', $overallRate . '%']);
    fputcsv($out, ['Commerce Cloud success rate', $commerceRate . '%']);
    fputcsv($out, ['AI Engine success rate', $aiRate . '%']);
    fputcsv($out, []);
    fputcsv($out, ['Shop','Commerce Cloud Uses','Commerce First Used','Commerce Last Used','AI Engine Uses','AI First Used','AI Last Used','Total Uses','Latest Activity']);
    foreach ($shopRows as $row) {
        fputcsv($out, [$row['shop_name'], $row['commerce_uses'], $row['commerce_first_used'], $row['commerce_last_used'], $row['ai_uses'], $row['ai_first_used'], $row['ai_last_used'], (int)$row['commerce_uses'] + (int)$row['ai_uses'], $row['last_used_at']]);
    }
    fclose($out);
    exit;
}

require_once '../includes/admin_layout.php';
adminHeader('Feature Usage Report', 'feature_usage');
?>
<style>
  .feature-hero { background:linear-gradient(135deg,rgba(124,58,237,.23),rgba(6,182,212,.14)); border:1px solid rgba(167,139,250,.26); border-radius:18px; padding:1.5rem; }
  .rate-ring { width:66px;height:66px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#8b5cf6 calc(var(--rate) * 1%),rgba(255,255,255,.09) 0); }
  .rate-ring > span { width:54px;height:54px;border-radius:50%;display:grid;place-items:center;background:#241850;color:#fff;font-weight:800;font-size:.78rem; }
  .report-panel { border:1px dashed rgba(167,139,250,.55); background:rgba(124,58,237,.08); }
  @media print {
    /* The global stylesheet hides all elements for receipt-only printing.
       Restore this report explicitly before hiding application navigation. */
    body *, .app-wrapper, .main-content, .page-content { visibility:visible!important; }
    .sidebar,.topbar,.mobile-bottom-nav,.no-print { display:none!important; }
    .main-content,.page-content { margin:0!important;padding:0!important;min-height:auto!important; }
    body { background:#fff!important;color:#111!important; }
    .card,.feature-hero { box-shadow:none!important;border:1px solid #ddd!important;background:#fff!important;color:#111!important; }
    .card *, .feature-hero *, .table *, h1,h2,h3,h4,h5,p,small { color:#111!important; }
    @page { margin:12mm; }
  }
</style>

<div class="feature-hero mb-4 d-flex justify-content-between align-items-start gap-3 flex-wrap">
  <div>
    <div class="text-uppercase small fw-bold text-info mb-2">Platform intelligence</div>
    <h1 class="page-title mb-1"><i class="bi bi-bar-chart-line-fill me-2 text-primary"></i>Feature Usage Report</h1>
    <p class="page-subtitle mb-0">Measure Commerce Cloud and AI Engine adoption across active shops.</p>
  </div>
  <div class="d-flex gap-2 no-print">
    <?php if (!$isReport): ?><a href="?report=1" class="btn btn-primary"><i class="bi bi-file-earmark-bar-graph me-1"></i>Create Report</a><?php endif; ?>
    <?php if ($isReport): ?><a href="<?= BASE_URL ?>/admin/feature_usage.php?download=csv" download="stockora_feature_adoption_report.csv" class="btn btn-success"><i class="bi bi-download me-1"></i>Download CSV</a><button type="button" class="btn btn-outline-light" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print / Save PDF</button><?php endif; ?>
  </div>
</div>

<?php if ($isReport): $rating = $overallRate >= 75 ? 'Excellent' : ($overallRate >= 45 ? 'Growing' : 'Needs attention'); ?>
<div class="card report-panel mb-4">
  <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><div class="fw-bold fs-5"><i class="bi bi-patch-check-fill text-success me-2"></i>Feature Adoption Performance: <?= $rating ?></div><div class="text-muted small">Generated <?= date('d M Y, h:i A') ?> · Based on <?= $activeShops ?> active shop<?= $activeShops === 1 ? '' : 's' ?>.</div></div>
    <div class="text-end"><div class="small text-muted">Overall success rate</div><div class="h3 mb-0 text-primary"><?= $overallRate ?>%</div></div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-lg-3 col-md-6"><div class="card h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="small text-muted">Overall Success Rate</div><div class="h2 mb-1 text-success"><?= $overallRate ?>%</div><small><?= $adoptedShops ?> of <?= $activeShops ?> active shops</small></div><div class="rate-ring" style="--rate:<?= $overallRate ?>"><span><?= $overallRate ?>%</span></div></div></div></div>
  <div class="col-lg-3 col-md-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">Commerce Cloud Success</div><div class="h2 mb-1 text-primary"><?= $commerceRate ?>%</div><div class="fw-semibold"><?= $commerceShops ?> shop<?= $commerceShops === 1 ? '' : 's' ?> · <?= $commerceOpens ?> opens</div><small class="text-muted">Active shops using Commerce Cloud</small></div></div></div>
  <div class="col-lg-3 col-md-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">AI Engine Success</div><div class="h2 mb-1 text-info"><?= $aiRate ?>%</div><div class="fw-semibold"><?= $aiShops ?> shop<?= $aiShops === 1 ? '' : 's' ?> · <?= $aiOpens ?> opens</div><small class="text-muted">Active shops using AI Engine</small></div></div></div>
  <div class="col-lg-3 col-md-6"><div class="card h-100"><div class="card-body"><div class="small text-muted">Power Users</div><div class="h2 mb-1 text-warning"><?= $bothShops ?></div><div class="fw-semibold">Both features adopted</div><small class="text-muted">Commerce Cloud + AI Engine</small></div></div></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-5"><div class="card h-100"><div class="card-body"><h5 class="card-title">Feature adoption rate</h5><p class="text-muted small">Percentage of active shops that have used each feature.</p><canvas id="featureChart" height="200"></canvas></div></div></div>
  <div class="col-lg-7"><div class="card h-100"><div class="card-body"><h5 class="card-title">Report summary</h5><div class="row g-3 mt-1"><div class="col-6"><div class="p-3 rounded" style="background:rgba(124,58,237,.1)"><div class="small text-muted">Feature adopters</div><div class="h4 mb-0"><?= $adoptedShops ?></div></div></div><div class="col-6"><div class="p-3 rounded" style="background:rgba(6,182,212,.1)"><div class="small text-muted">Total tracked opens</div><div class="h4 mb-0"><?= $commerceOpens + $aiOpens ?></div></div></div><div class="col-12 text-muted small"><i class="bi bi-info-circle me-1"></i>Success rate = shops that used the feature ÷ all active shops. The report updates whenever a shop opens Commerce Cloud or AI Engine.</div></div></div></div></div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><div><strong>Shop-wise feature usage</strong><div class="small text-muted">Only active shops with at least one tracked feature use are listed.</div></div><?php if (!$isReport): ?><a class="btn btn-sm btn-outline-primary no-print" href="?report=1"><i class="bi bi-file-earmark-bar-graph me-1"></i>Create Report</a><?php endif; ?></div>
  <div class="table-responsive"><table class="table mb-0 align-middle"><thead><tr><th>Shop</th><th>Commerce Cloud</th><th>AI Engine</th><th>Total opens</th><th>Latest activity</th></tr></thead><tbody>
  <?php foreach ($shopRows as $row): ?><tr><td><a href="<?= BASE_URL ?>/admin/shops.php?id=<?= (int)$row['shop_id'] ?>" class="fw-semibold"><?= htmlspecialchars($row['shop_name']) ?></a></td><td><?php if ((int)$row['commerce_uses']): ?><span class="badge bg-primary"><?= (int)$row['commerce_uses'] ?> opens</span><div class="small text-muted mt-1"><?= date('d M Y', strtotime($row['commerce_last_used'])) ?></div><?php else: ?><span class="text-muted">Not used</span><?php endif; ?></td><td><?php if ((int)$row['ai_uses']): ?><span class="badge bg-info text-dark"><?= (int)$row['ai_uses'] ?> opens</span><div class="small text-muted mt-1"><?= date('d M Y', strtotime($row['ai_last_used'])) ?></div><?php else: ?><span class="text-muted">Not used</span><?php endif; ?></td><td class="fw-bold"><?= (int)$row['commerce_uses'] + (int)$row['ai_uses'] ?></td><td><?= $row['last_used_at'] ? date('d M Y, h:i A', strtotime($row['last_used_at'])) : '—' ?></td></tr><?php endforeach; ?>
  <?php if (!$shopRows): ?><tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-bar-chart-line d-block fs-3 mb-2"></i>No feature usage recorded yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<script>
new Chart(document.getElementById('featureChart'), { type:'bar', data:{ labels:['Commerce Cloud','AI Engine','Any feature'], datasets:[{ data:[<?= $commerceRate ?>,<?= $aiRate ?>,<?= $overallRate ?>], backgroundColor:['#7c3aed','#06b6d4','#10b981'], borderRadius:8 }] }, options:{ scales:{ y:{ beginAtZero:true, max:100, ticks:{ callback:value=>value+'%' } }, x:{ grid:{ display:false } } }, plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:ctx=>ctx.raw+'% adoption' } } } } });
</script>
<?php adminFooter(); ?>

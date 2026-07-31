<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();
$today = date('Y-m-d');
$shopsStmt = $db->query("SELECT s.id,s.name,s.city,s.status,s.created_at,
    u.name owner_name,u.email owner_email,u.last_login,
    sub.status sub_status,sub.end_date,sub.plan_name,
    COALESCE(sales.sales_30,0) sales_30,COALESCE(sales.gmv_30,0) gmv_30,sales.last_sale,
    COALESCE(features.feature_opens,0) feature_opens
    FROM shops s
    LEFT JOIN users u ON u.shop_id=s.id AND u.role='owner'
    LEFT JOIN subscriptions sub ON sub.id=(SELECT ss.id FROM subscriptions ss WHERE ss.shop_id=s.id ORDER BY ss.end_date DESC,ss.id DESC LIMIT 1)
    LEFT JOIN (SELECT shop_id,COUNT(*) sales_30,SUM(grand_total) gmv_30,MAX(sale_date) last_sale FROM sales WHERE sale_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY shop_id) sales ON sales.shop_id=s.id
    LEFT JOIN (SELECT shop_id,SUM(use_count) feature_opens FROM shop_feature_usage GROUP BY shop_id) features ON features.shop_id=s.id
    ORDER BY s.name");
$shops = $shopsStmt->fetchAll();
$metrics = ['healthy'=>0,'attention'=>0,'critical'=>0,'dormant'=>0,'active_7d'=>0];
foreach ($shops as &$shop) {
    $expired = !$shop['end_date'] || $shop['sub_status'] !== 'active' || $shop['end_date'] < $today;
    $expiring = !$expired && $shop['end_date'] <= date('Y-m-d', strtotime('+7 days'));
    $dormant = !$shop['last_sale'];
    if ($expired) { $shop['health'] = 'critical'; $shop['reason'] = 'Subscription inactive / expired'; }
    elseif ($expiring) { $shop['health'] = 'attention'; $shop['reason'] = 'Subscription expires soon'; }
    elseif ($dormant) { $shop['health'] = 'attention'; $shop['reason'] = 'No sales in last 30 days'; }
    else { $shop['health'] = 'healthy'; $shop['reason'] = 'Trading normally'; }
    $metrics[$shop['health']]++;
    if ($dormant) $metrics['dormant']++;
    if ($shop['last_sale'] && $shop['last_sale'] >= date('Y-m-d', strtotime('-7 days'))) $metrics['active_7d']++;
}
unset($shop);
$filter = $_GET['filter'] ?? 'all';
$visible = array_filter($shops, fn($shop) => $filter === 'all' || $shop['health'] === $filter || ($filter === 'dormant' && !$shop['last_sale']));
$mrr = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed' AND payment_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn();

adminHeader('Platform Health', 'platform_health');
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div><h1 class="page-title"><i class="bi bi-heart-pulse me-2 text-primary"></i>Platform Health</h1><p class="page-subtitle">Merchant activity, renewal risk and growth signals — updated live</p></div>
  <div class="d-flex gap-2"><a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/admin/feature_usage.php"><i class="bi bi-bar-chart me-1"></i>Feature Adoption</a><a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/admin/announcements.php"><i class="bi bi-megaphone me-1"></i>Send Announcement</a></div>
</div>
<div class="row g-3 mb-4">
 <div class="col-6 col-lg-3"><a href="?filter=healthy" class="text-decoration-none"><div class="stat-card stat-success"><div class="stat-card-icon"><i class="bi bi-heart-fill"></i></div><div class="stat-card-value"><?= $metrics['healthy'] ?></div><div class="stat-card-label">Healthy Shops</div><div class="stat-card-change">Active subscription + recent sales</div></div></a></div>
 <div class="col-6 col-lg-3"><a href="?filter=attention" class="text-decoration-none"><div class="stat-card stat-warning"><div class="stat-card-icon"><i class="bi bi-exclamation-circle"></i></div><div class="stat-card-value"><?= $metrics['attention'] ?></div><div class="stat-card-label">Need Attention</div><div class="stat-card-change">Renewal or activity risk</div></div></a></div>
 <div class="col-6 col-lg-3"><a href="?filter=critical" class="text-decoration-none"><div class="stat-card stat-danger"><div class="stat-card-icon"><i class="bi bi-shield-exclamation"></i></div><div class="stat-card-value"><?= $metrics['critical'] ?></div><div class="stat-card-label">Critical Accounts</div><div class="stat-card-change">Expired / no active subscription</div></div></a></div>
 <div class="col-6 col-lg-3"><div class="stat-card stat-primary"><div class="stat-card-icon"><i class="bi bi-cash-stack"></i></div><div class="stat-card-value"><?= formatCurrency($mrr) ?></div><div class="stat-card-label">This Month Revenue</div><div class="stat-card-change"><?= $metrics['active_7d'] ?> shops sold in last 7 days</div></div></div>
</div>
<div class="card mb-3"><div class="card-body py-3 d-flex flex-wrap align-items-center gap-2"><span class="fw-semibold me-1">View:</span><?php foreach(['all'=>'All shops','healthy'=>'Healthy','attention'=>'Needs attention','critical'=>'Critical','dormant'=>'No sales (30d)'] as $key=>$label): ?><a href="?filter=<?= $key ?>" class="btn btn-sm btn-<?= $filter===$key?'primary':'outline-secondary' ?>"><?= $label ?></a><?php endforeach; ?><span class="ms-auto text-muted small"><?= count($visible) ?> of <?= count($shops) ?> shops shown</span></div></div>
<div class="card"><div class="card-header"><i class="bi bi-radar me-2 text-primary"></i>Merchant Health Monitor</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Shop / Owner</th><th>Health Signal</th><th>30d Sales</th><th>30d GMV</th><th>Last Sale</th><th>Subscription</th><th>Feature Opens</th><th></th></tr></thead><tbody>
<?php foreach($visible as $shop): $healthClass=['healthy'=>'success','attention'=>'warning','critical'=>'danger'][$shop['health']]; ?><tr>
 <td><div class="fw-semibold"><?= htmlspecialchars($shop['name']) ?></div><small class="text-muted"><?= htmlspecialchars($shop['owner_name'] ?: $shop['owner_email'] ?: 'No owner') ?><?= $shop['city'] ? ' · '.htmlspecialchars($shop['city']) : '' ?></small></td>
 <td><span class="badge bg-<?= $healthClass ?>"><?= ucfirst($shop['health']) ?></span><div class="small text-muted mt-1"><?= htmlspecialchars($shop['reason']) ?></div></td>
 <td class="fw-semibold"><?= number_format($shop['sales_30']) ?></td><td><?= formatCurrency($shop['gmv_30']) ?></td>
 <td><small><?= $shop['last_sale'] ? date('d M Y',strtotime($shop['last_sale'])) : 'No sale in 30d' ?></small></td>
 <td><span class="badge <?= $shop['end_date'] && $shop['end_date'] >= $today && $shop['sub_status']==='active'?'bg-success':'bg-danger' ?>"><?= htmlspecialchars($shop['plan_name'] ?: 'No plan') ?></span><div class="small text-muted mt-1"><?= $shop['end_date'] ? 'Ends '.date('d M Y',strtotime($shop['end_date'])) : '-' ?></div></td>
 <td><?= number_format($shop['feature_opens']) ?></td>
 <td><div class="d-flex gap-1"><a href="<?= BASE_URL ?>/admin/shops.php?action=edit&id=<?= $shop['id'] ?>" class="btn btn-sm btn-outline-primary" title="Manage shop"><i class="bi bi-pencil"></i></a><a href="<?= BASE_URL ?>/admin/subscriptions.php?shop_id=<?= $shop['id'] ?>" class="btn btn-sm btn-outline-success" title="Subscription"><i class="bi bi-calendar-plus"></i></a></div></td>
</tr><?php endforeach; ?>
<?php if(!$visible): ?><tr><td colspan="8" class="text-center text-muted py-4">No shops match this health filter.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="row g-3 mt-1"><div class="col-md-6"><div class="card h-100"><div class="card-body"><h6><i class="bi bi-lightbulb me-2 text-warning"></i>Admin playbook</h6><p class="small text-muted mb-0"><strong>Critical:</strong> renew manually or contact the owner. <strong>Needs attention:</strong> send a targeted reminder or offer. <strong>No sales:</strong> help the merchant activate POS, products or Commerce Cloud.</p></div></div></div><div class="col-md-6"><div class="card h-100"><div class="card-body"><h6><i class="bi bi-info-circle me-2 text-primary"></i>How health is calculated</h6><p class="small text-muted mb-0">Healthy = an active subscription and at least one sale in 30 days. Attention = expiring within 7 days or no sale in 30 days. Critical = no active subscription.</p></div></div></div></div>
<?php adminFooter(); ?>

<?php
require_once '../includes/functions.php';
requireShop();
requirePremiumFeature((int)$_SESSION['shop_id'], 'Commerce Cloud');
require_once '../includes/shop_layout.php';
$shopId   = (int)$_SESSION['shop_id'];
$shopName = $_SESSION['shop_name'] ?? 'My Shop';
$pageTitle = 'Online Orders';

$db = getDB();

// ── AJAX: Update order status ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    if ($_POST['action'] === 'update_status') {
        $ordId  = (int)($_POST['order_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $allowed = ['pending','confirmed','processing','delivered','cancelled'];
        if ($ordId && in_array($status, $allowed)) {
            $db->prepare("UPDATE online_orders SET status=?, updated_at=NOW() WHERE id=? AND shop_id=?")
               ->execute([$status, $ordId, $shopId]);
            echo json_encode(['success'=>true]);
        } else {
            echo json_encode(['success'=>false,'message'=>'Invalid']);
        }
    }
    exit;
}

// ── Load orders ──────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$sql = "SELECT * FROM online_orders WHERE shop_id=?";
$params = [$shopId];
if ($statusFilter !== 'all') { $sql .= " AND status=?"; $params[] = $statusFilter; }
$sql .= " ORDER BY created_at DESC LIMIT 200";
$orderRows = $db->prepare($sql);
$orderRows->execute($params);
$orders = $orderRows->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$metrics = $db->prepare("SELECT
  COUNT(*) AS total,
  SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_count,
  SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS delivered_count,
  SUM(CASE WHEN status='delivered' THEN total ELSE 0 END) AS revenue
  FROM online_orders WHERE shop_id=?");
$metrics->execute([$shopId]);
$m = $metrics->fetch(PDO::FETCH_ASSOC);

shopHeader($pageTitle, 'online_orders');
?>

<style>
/* ══════════════════════════════════════════
   ONLINE ORDERS — Commerce Cloud
   ══════════════════════════════════════════ */
:root {
  --oo-teal:   #06B6D4;
  --oo-cyan:   #22D3EE;
  --oo-purple: #7C3AED;
  --oo-violet: #8B5CF6;
  --oo-green:  #10B981;
  --oo-gold:   #F59E0B;
  --oo-red:    #EF4444;
  --oo-glass:  #0d1526;
  --oo-border: rgba(14,206,206,.12);
}

/* ── Header ── */
.oo-header {
  background: linear-gradient(135deg, rgba(6,182,212,.1), rgba(124,58,237,.07));
  border: 1px solid rgba(6,182,212,.15);
  border-radius: 20px; padding: 1.75rem 2rem;
  margin-bottom: 1.75rem; position: relative; overflow: hidden;
}

/* ── Metric Cards ── */
.oo-metric {
  background: var(--oo-glass);
  border: 1px solid var(--oo-border);
  border-radius: 15px; padding: 1.25rem 1.4rem;
  transition: all .3s;
}
.oo-metric:hover { border-color: rgba(6,182,212,.25); transform: translateY(-2px); }
.oo-metric-icon {
  width: 40px; height: 40px; border-radius: 11px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; margin-bottom: .85rem;
}
.oo-metric-num { font-size: 1.6rem; font-weight: 900; color: #fff; line-height: 1; }
.oo-metric-lbl { font-size: .73rem; color: var(--text2); margin-top: .25rem; }

/* ── Status Tabs ── */
.order-status-tabs {
  display: flex; gap: .5rem; flex-wrap: wrap;
  margin-bottom: 1.5rem;
}
.ost-tab {
  background: var(--oo-glass);
  border: 1px solid rgba(14,206,206,.16);
  border-radius: 30px; padding: .38rem 1rem;
  font-size: .78rem; font-weight: 700; color: var(--text2);
  cursor: pointer; transition: all .2s; display: flex; align-items: center; gap: .4rem;
  text-decoration: none;
}
.ost-tab:hover { border-color: rgba(6,182,212,.3); color: rgba(255,255,255,.8); }
.ost-tab.active {
  background: linear-gradient(135deg, rgba(6,182,212,.15), rgba(124,58,237,.1));
  border-color: rgba(6,182,212,.35); color: var(--oo-cyan);
}
.ost-count {
  background: rgba(255,255,255,.1); color: rgba(255,255,255,.5);
  border-radius: 20px; padding: .04rem .42rem; font-size: .68rem;
}

/* ── Search / Filter Bar ── */
.oo-action-bar {
  display: flex; align-items: center; gap: .75rem;
  flex-wrap: wrap; margin-bottom: 1.25rem;
}
.oo-search {
  flex: 1; min-width: 200px;
  background: #111f35;
  border: 1px solid rgba(14,206,206,.18);
  border-radius: 11px; padding: .6rem 1rem;
  color: rgba(255,255,255,.85); font-size: .85rem;
  display: flex; align-items: center; gap: .6rem;
}
.oo-search input {
  background: none; border: none; outline: none;
  color: rgba(255,255,255,.85); font-size: .85rem; flex: 1;
  font-family: 'Inter', sans-serif;
}
.oo-search input::placeholder { color: rgba(255,255,255,.3); }

/* ── Orders Table / Cards ── */
.orders-table-wrap {
  background: var(--oo-glass);
  border: 1px solid var(--oo-border);
  border-radius: 16px; overflow: hidden;
}
.orders-table {
  width: 100%; border-collapse: collapse;
  font-size: .83rem;
}
.orders-table th {
  background: rgba(6,182,212,.05);
  padding: .85rem 1rem;
  font-size: .72rem; font-weight: 800;
  color: rgba(255,255,255,.4); text-transform: uppercase;
  letter-spacing: .6px; text-align: left;
  border-bottom: 1px solid var(--oo-border);
  white-space: nowrap;
}
.orders-table td {
  padding: .9rem 1rem;
  border-bottom: 1px solid rgba(14,206,206,.10);
  color: rgba(255,255,255,.78);
  vertical-align: middle;
}
.orders-table tr:last-child td { border-bottom: none; }
.orders-table tr:hover td { background: rgba(255,255,255,.025); }

/* Order status badges */
.order-status {
  display: inline-flex; align-items: center; gap: .35rem;
  font-size: .7rem; font-weight: 800; padding: .2rem .65rem;
  border-radius: 20px; letter-spacing: .2px; white-space: nowrap;
}
.os-pending    { background: rgba(245,158,11,.15); color: #FCD34D; border: 1px solid rgba(245,158,11,.25); }
.os-confirmed  { background: rgba(14,165,233,.15); color: #38BDF8; border: 1px solid rgba(14,165,233,.25); }
.os-processing { background: rgba(124,58,237,.15); color: #A78BFA; border: 1px solid rgba(124,58,237,.25); }
.os-shipped    { background: rgba(6,182,212,.15);  color: #22D3EE; border: 1px solid rgba(6,182,212,.25); }
.os-delivered  { background: rgba(16,185,129,.15); color: #34D399; border: 1px solid rgba(16,185,129,.25); }
.os-cancelled  { background: rgba(239,68,68,.1);   color: #F87171; border: 1px solid rgba(239,68,68,.2); }
.os-refunded   { background: rgba(156,163,175,.1); color: #9CA3AF; border: 1px solid rgba(156,163,175,.2); }

.order-dot {
  width: 7px; height: 7px; border-radius: 50%; display: inline-block;
}

/* Native select menu: keep the status picker dark on Windows/Chrome too. */
.status-sel { color-scheme: dark; }
.status-sel option { background: #0d1526; color: #fff; }
.dot-pending    { background: #FCD34D; }
.dot-confirmed  { background: #38BDF8; }
.dot-processing { background: #A78BFA; animation: pulseOO 2s ease-in-out infinite; }
.dot-shipped    { background: #22D3EE; }
.dot-delivered  { background: #34D399; }
.dot-cancelled  { background: #F87171; }
@keyframes pulseOO { 0%,100%{opacity:1} 50%{opacity:.4} }

/* Action buttons */
.tbl-action {
  background: #111f35;
  border: 1px solid rgba(14,206,206,.18);
  color: rgba(255,255,255,.6);
  border-radius: 7px; padding: .28rem .65rem;
  font-size: .72rem; font-weight: 700;
  cursor: pointer; transition: all .2s;
  display: inline-flex; align-items: center; gap: .3rem;
}
.tbl-action:hover { background: rgba(255,255,255,.1); color: #fff; border-color: rgba(255,255,255,.2); }
.tbl-action.primary { background: rgba(6,182,212,.12); color: #22D3EE; border-color: rgba(6,182,212,.25); }
.tbl-action.primary:hover { background: rgba(6,182,212,.22); }

/* Empty state */
.orders-empty {
  text-align: center; padding: 4rem 2rem;
}
.orders-empty-icon {
  width: 80px; height: 80px; border-radius: 20px;
  background: rgba(6,182,212,.1);
  display: flex; align-items: center; justify-content: center;
  font-size: 2rem; color: var(--oo-teal);
  margin: 0 auto 1.5rem;
}

/* Order detail panel (slide-in) */
.order-detail-panel {
  display: none; position: fixed; top: 0; right: 0; bottom: 0; width: 420px;
  background: rgba(10,8,30,.97); backdrop-filter: blur(24px);
  border-left: 1px solid rgba(6,182,212,.2);
  z-index: 9000; overflow-y: auto;
  padding: 1.5rem; box-shadow: -20px 0 60px rgba(0,0,0,.5);
  transition: transform .35s cubic-bezier(.4,0,.2,1);
  transform: translateX(100%);
}
.order-detail-panel.open { display: block; transform: translateX(0); }
.panel-close {
  position: sticky; top: 0; z-index: 10;
  background: none; border: none; color: var(--text2);
  font-size: 1.1rem; cursor: pointer; float: right; margin-bottom: .5rem;
  padding: .25rem;
}
.panel-section-title {
  font-size: .72rem; font-weight: 800; color: rgba(255,255,255,.4);
  text-transform: uppercase; letter-spacing: .6px;
  margin: 1.25rem 0 .75rem;
  padding-bottom: .5rem; border-bottom: 1px solid rgba(14,206,206,.10);
}

/* Timeline */
.order-timeline { position: relative; padding-left: 1.5rem; }
.order-timeline::before { content:''; position:absolute;left:7px;top:0;bottom:0;width:2px;background:rgba(255,255,255,.07); }
.tl-item { position: relative; margin-bottom: 1rem; }
.tl-dot {
  position: absolute; left: -1.5rem; top: .2rem;
  width: 14px; height: 14px; border-radius: 50%;
  border: 2px solid rgba(255,255,255,.15);
  background: rgba(10,8,30,.9);
}
.tl-dot.done { background: #34D399; border-color: #34D399; }
.tl-dot.current { background: #A78BFA; border-color: #A78BFA; animation: pulseOO 2s ease-in-out infinite; }
.tl-label { font-size: .8rem; font-weight: 700; color: rgba(255,255,255,.75); }
.tl-time  { font-size: .7rem; color: var(--text2); }

/* Responsive */
@media(max-width: 992px) {
  .orders-table th:nth-child(3),
  .orders-table td:nth-child(3),
  .orders-table th:nth-child(5),
  .orders-table td:nth-child(5) { display: none; }
}
@media(max-width: 768px) {
  .oo-header { padding: 1.25rem 1rem; }
  .order-detail-panel { width: 100%; border-left: none; border-top: 1px solid rgba(6,182,212,.2); top: auto; height: 80vh; bottom: 0; transform: translateY(100%); }
  .order-detail-panel.open { transform: translateY(0); }
  .orders-table th:nth-child(4),
  .orders-table td:nth-child(4) { display: none; }
}
@media(max-width: 576px) {
  .oo-action-bar { flex-direction: column; align-items: stretch; }
  .oo-search { min-width: 100%; }
}
</style>

<div class="container-fluid px-3 px-md-4">

  <!-- ══ PAGE HEADER ══ -->
  <div class="oo-header">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem">
      <div>
        <a href="<?= BASE_URL ?>/shop/commerce_cloud.php" style="color:var(--text2);text-decoration:none;font-size:.78rem;display:inline-flex;align-items:center;gap:.35rem;margin-bottom:.6rem">
          <i class="bi bi-arrow-left"></i> Commerce Cloud
        </a>
        <div style="display:inline-flex;align-items:center;gap:.4rem;background:rgba(6,182,212,.1);border:1px solid rgba(6,182,212,.2);border-radius:30px;padding:.25rem .8rem;font-size:.67rem;font-weight:700;color:#22D3EE;letter-spacing:.5px;text-transform:uppercase;margin-bottom:.5rem">
          <i class="bi bi-bag-check-fill"></i> Online Orders
        </div>
        <h1 style="font-size:1.65rem;font-weight:900;color:#fff;letter-spacing:-.7px;margin:0">Order Management</h1>
        <p style="font-size:.85rem;color:var(--text2);margin-top:.3rem">All online orders from your ecommerce store appear here in real-time</p>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm" style="background:rgba(6,182,212,.12);color:#22D3EE;border:1px solid rgba(6,182,212,.25);font-size:.78rem;font-weight:700;display:flex;align-items:center;gap:.4rem">
          <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
        <button class="btn btn-sm" style="background:rgba(16,185,129,.1);color:#34D399;border:1px solid rgba(16,185,129,.2);font-size:.78rem;font-weight:700;display:flex;align-items:center;gap:.4rem">
          <i class="bi bi-download"></i> Export
        </button>
      </div>
    </div>
  </div>

  <!-- ══ METRICS ══ -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="oo-metric">
        <div class="oo-metric-icon" style="background:rgba(6,182,212,.15)"><i class="bi bi-bag-check-fill" style="color:#22D3EE"></i></div>
        <div class="oo-metric-num"><?= (int)($m['total'] ?? 0) ?></div>
        <div class="oo-metric-lbl">Total Orders</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="oo-metric">
        <div class="oo-metric-icon" style="background:rgba(245,158,11,.15)"><i class="bi bi-hourglass-split" style="color:#FCD34D"></i></div>
        <div class="oo-metric-num"><?= (int)($m['pending_count'] ?? 0) ?></div>
        <div class="oo-metric-lbl">Pending Orders</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="oo-metric">
        <div class="oo-metric-icon" style="background:rgba(16,185,129,.15)"><i class="bi bi-currency-exchange" style="color:#34D399"></i></div>
        <div class="oo-metric-num">Rs <?= number_format((float)($m['revenue'] ?? 0), 0) ?></div>
        <div class="oo-metric-lbl">Online Revenue</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="oo-metric">
        <div class="oo-metric-icon" style="background:rgba(124,58,237,.15)"><i class="bi bi-truck" style="color:#A78BFA"></i></div>
        <div class="oo-metric-num"><?= (int)($m['delivered_count'] ?? 0) ?></div>
        <div class="oo-metric-lbl">Delivered</div>
      </div>
    </div>
  </div>

  <!-- ══ STATUS TABS ══ -->
  <div class="order-status-tabs">
    <a href="<?= BASE_URL ?>?status=all"        class="ost-tab <?= $statusFilter==='all'       ?'active':'' ?>">All Orders <span class="ost-count"><?= (int)($m['total']??0) ?></span></a>
    <a href="<?= BASE_URL ?>?status=pending"    class="ost-tab <?= $statusFilter==='pending'   ?'active':'' ?>"><span class="order-dot dot-pending"></span> Pending <span class="ost-count"><?= (int)($m['pending_count']??0) ?></span></a>
    <a href="<?= BASE_URL ?>?status=confirmed"  class="ost-tab <?= $statusFilter==='confirmed' ?'active':'' ?>"><span class="order-dot dot-confirmed"></span> Confirmed</a>
    <a href="<?= BASE_URL ?>?status=processing" class="ost-tab <?= $statusFilter==='processing'?'active':'' ?>"><span class="order-dot dot-processing"></span> Processing</a>
    <a href="<?= BASE_URL ?>?status=delivered"  class="ost-tab <?= $statusFilter==='delivered' ?'active':'' ?>"><span class="order-dot dot-delivered"></span> Delivered <span class="ost-count"><?= (int)($m['delivered_count']??0) ?></span></a>
    <a href="<?= BASE_URL ?>?status=cancelled"  class="ost-tab <?= $statusFilter==='cancelled' ?'active':'' ?>"><span class="order-dot dot-cancelled"></span> Cancelled</a>
  </div>

  <!-- ══ ACTION BAR ══ -->
  <div class="oo-action-bar">
    <div class="oo-search">
      <i class="bi bi-search" style="color:rgba(255,255,255,.3)"></i>
      <input type="text" id="orderSearch" placeholder="Search order ID, customer..." oninput="searchOrders(this.value)">
    </div>
    <button onclick="location.reload()" class="btn btn-sm" style="background:rgba(6,182,212,.12);color:#22D3EE;border:1px solid rgba(6,182,212,.25);font-size:.78rem;font-weight:700;display:flex;align-items:center;gap:.4rem;white-space:nowrap">
      <i class="bi bi-arrow-clockwise"></i> Refresh
    </button>
  </div>

  <!-- ══ ORDERS TABLE ══ -->
  <div class="orders-table-wrap">
    <?php if (empty($orders)): ?>
    <div class="orders-empty">
      <div class="orders-empty-icon"><i class="bi bi-bag-x"></i></div>
      <h3 style="font-size:1.2rem;font-weight:800;color:#fff;margin-bottom:.5rem">No Orders Yet</h3>
      <p style="font-size:.85rem;color:var(--text2);max-width:420px;margin:0 auto 1.5rem;line-height:1.65">
        Your ecommerce store hasn't received any orders yet. Share your store link with customers!
      </p>
      <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/shop/commerce_cloud.php" class="btn" style="background:linear-gradient(135deg,#7C3AED,#8B5CF6);color:#fff;font-size:.85rem;font-weight:700;padding:.7rem 1.5rem;border-radius:12px;border:none;display:inline-flex;align-items:center;gap:.5rem">
          <i class="bi bi-cloud-fill"></i> Go to Commerce Cloud
        </a>
      </div>
    </div>
    <?php else: ?>
    <table class="orders-table" id="ordersTable">
      <thead>
        <tr>
          <th>Order #</th>
          <th>Customer</th>
          <th class="d-none d-md-table-cell">Items</th>
          <th>Amount</th>
          <th class="d-none d-lg-table-cell">Date</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="ordersTbody">
        <?php foreach ($orders as $ord):
          $items = json_decode($ord['items'] ?? '[]', true);
          $itemCount = count($items);
          $itemPreview = !empty($items) ? htmlspecialchars($items[0]['name'] ?? '') . ($itemCount > 1 ? ' +'.($itemCount-1).' more' : '') : '—';
          $statusClass = ['pending'=>'os-pending','confirmed'=>'os-confirmed','processing'=>'os-processing','delivered'=>'os-delivered','cancelled'=>'os-cancelled'][$ord['status']] ?? 'os-pending';
          $initials2 = strtoupper(substr($ord['customer_name'] ?: 'C', 0, 2));
          $custColors = ['#7C3AED','#06B6D4','#10B981','#F59E0B','#EC4899','#6366f1'];
          $col = $custColors[$ord['id'] % count($custColors)];
        ?>
        <tr class="order-row"
            data-id="<?= $ord['id'] ?>"
            data-name="<?= htmlspecialchars(strtolower($ord['customer_name'])) ?>"
            data-order="<?= htmlspecialchars(strtolower($ord['order_number'])) ?>">
          <td><span style="font-family:monospace;color:#A78BFA;font-weight:700;font-size:.78rem"><?= htmlspecialchars($ord['order_number']) ?></span></td>
          <td>
            <div style="display:flex;align-items:center;gap:.55rem">
              <div style="width:30px;height:30px;border-radius:50%;background:<?= $col ?>;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:800;color:#fff;flex-shrink:0"><?= $initials2 ?></div>
              <div>
                <div style="font-weight:600;font-size:.82rem;color:rgba(255,255,255,.88)"><?= htmlspecialchars($ord['customer_name'] ?: 'Unknown') ?></div>
                <?php if ($ord['customer_phone']): ?>
                <div style="font-size:.7rem;color:rgba(255,255,255,.35)"><?= htmlspecialchars($ord['customer_phone']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="d-none d-md-table-cell" style="color:rgba(255,255,255,.55);font-size:.8rem"><?= htmlspecialchars($itemPreview) ?></td>
          <td style="font-weight:800;color:#fff">Rs <?= number_format((float)$ord['total'], 0) ?></td>
          <td class="d-none d-lg-table-cell" style="color:rgba(255,255,255,.4);font-size:.76rem"><?php $odt=new DateTime($ord['created_at'],new DateTimeZone('UTC')); $odt->setTimezone(new DateTimeZone('Asia/Karachi')); echo $odt->format('d M, h:i A'); ?></td>
          <td>
            <select class="status-sel" onchange="updateStatus(<?= $ord['id'] ?>, this.value, this)" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:.72rem;padding:.28rem .5rem;outline:none;cursor:pointer;font-family:'Inter',sans-serif;">
              <?php foreach (['pending','confirmed','processing','delivered','cancelled'] as $st): ?>
              <option value="<?= $st ?>" <?= $ord['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <div style="display:flex;gap:.3rem">
              <button class="tbl-action primary" onclick="viewOrder(<?= $ord['id'] ?>)" title="View"><i class="bi bi-eye"></i></button>
              <?php if ($ord['customer_phone']): ?>
              <a href=tel:<?= preg_replace('/[^0-9+]/','',$ord['customer_phone']) ?>" class="tbl-action" title="Call" style="text-decoration:none"><i class="bi bi-telephone"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ══ ORDER DETAIL MODAL ══ -->
  <div class="modal fade" id="orderDetailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="background:#0d1526;border:1px solid rgba(14,206,206,.15);border-radius:18px;">
        <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,.08);">
          <h5 class="modal-title" style="color:#fff;font-weight:800;font-size:.95rem;">Order Details</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="orderDetailBody" style="padding:1.25rem;">
          <p style="color:rgba(255,255,255,.4);text-align:center;">Loading...</p>
        </div>
      </div>
    </div>
  </div>

<script>
// ── Search ──────────────────────────────────────────
function searchOrders(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('.order-row').forEach(function(r) {
    var name  = r.dataset.name || '';
    var order = r.dataset.order || '';
    r.style.display = (!q || name.includes(q) || order.includes(q)) ? '' : 'none';
  });
}

// ── Update Status via AJAX ──────────────────────────
function updateStatus(id, status, sel) {
  var fd = new URLSearchParams();
  fd.append('action','update_status');
  fd.append('order_id', id);
  fd.append('status', status);
  fetch(window.location.pathname, {method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
  .then(function(r){return r.json();})
  .then(function(d){
    if(d.success) showToast('Status updated','success');
    else showToast('Update failed','danger');
  });
}

// ── View Order Detail ───────────────────────────────
var _ordersData = <?= json_encode(array_values($orders), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>;
function viewOrder(id) {
  var ord = _ordersData.find(function(o){return o.id == id;});
  if (!ord) return;
  var items = JSON.parse(ord.items || '[]');
  var html = '<div style="font-size:.78rem;">';
  html += '<div style="display:flex;justify-content:space-between;margin-bottom:.75rem;">';
  html += '<span style="color:rgba(255,255,255,.4);">Order #</span><strong style="color:#A78BFA;font-family:monospace">'+esc(ord.order_number)+'</strong></div>';
  html += '<div style="display:flex;justify-content:space-between;margin-bottom:.4rem;"><span style="color:rgba(255,255,255,.4);">Customer</span><span style="color:#fff">'+esc(ord.customer_name||'—')+'</span></div>';
  if(ord.customer_phone) html += '<div style="display:flex;justify-content:space-between;margin-bottom:.4rem;"><span style="color:rgba(255,255,255,.4);">Phone</span><a href="tel:'+esc(ord.customer_phone)+'" style="color:#22D3EE">'+esc(ord.customer_phone)+'</a></div>';
  if(ord.customer_address) html += '<div style="display:flex;justify-content:space-between;margin-bottom:.4rem;"><span style="color:rgba(255,255,255,.4);">Address</span><span style="color:#fff;text-align:right;max-width:60%">'+esc(ord.customer_address)+'</span></div>';
  if(ord.customer_note) html += '<div style="display:flex;justify-content:space-between;margin-bottom:.4rem;"><span style="color:rgba(255,255,255,.4);">Note</span><span style="color:#fff;text-align:right;max-width:60%">'+esc(ord.customer_note)+'</span></div>';
  html += '<div style="display:flex;justify-content:space-between;margin-bottom:.4rem;"><span style="color:rgba(255,255,255,.4);">Payment</span><span style="color:#FCD34D">'+esc(ord.payment_method||'cod').toUpperCase()+'</span></div>';
  html += '<div style="display:flex;justify-content:space-between;margin-bottom:.85rem;"><span style="color:rgba(255,255,255,.4);">Date</span><span style="color:rgba(255,255,255,.55)">'+esc(ord.created_at)+'</span></div>';
  html += '<div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;overflow:hidden;margin-bottom:.85rem;">';
  html += '<div style="padding:.5rem .75rem;background:rgba(255,255,255,.04);font-size:.7rem;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.5px;">Items</div>';
  items.forEach(function(it){
    html += '<div style="display:flex;justify-content:space-between;padding:.45rem .75rem;border-top:1px solid rgba(255,255,255,.05);">';
    html += '<span style="color:rgba(255,255,255,.8)">'+esc(it.name)+' × '+it.qty+'</span>';
    html += '<span style="color:#34D399;font-weight:700">Rs '+(it.line_total||it.price*it.qty).toLocaleString()+'</span>';
    html += '</div>';
  });
  html += '<div style="display:flex;justify-content:space-between;padding:.55rem .75rem;border-top:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);">';
  html += '<span style="font-weight:800;color:#fff">Total</span>';
  html += '<span style="font-size:1rem;font-weight:900;color:#22D3EE">Rs '+parseFloat(ord.total).toLocaleString()+'</span>';
  html += '</div></div>';
  html += '</div>';
  document.getElementById('orderDetailBody').innerHTML = html;
  new bootstrap.Modal(document.getElementById('orderDetailModal')).show();
}
function esc(s){ var d=document.createElement('div');d.appendChild(document.createTextNode(String(s||'')));return d.innerHTML; }
</script>

</div><!-- /container -->

<?php shopFooter(); ?>

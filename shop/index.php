<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId   = (int)$_SESSION['shop_id'];
$stats    = getShopDashboardStats($shopId);
$chartData= getSalesChartData($shopId);
$lowStockProducts = getLowStockProducts($shopId);
$db       = getDB();

// ── Autopilot toggle ────────────────────────────────────────
$autopilotOn = getShopSetting($shopId, 'autopilot_mode', '0') === '1';
if (isset($_GET['autopilot'])) {
    $newVal = $_GET['autopilot'] === '1' ? '1' : '0';
    setShopSetting($shopId, 'autopilot_mode', $newVal);
    header('Location: ' . BASE_URL . '/shop/index.php');
    exit;
}

// ── Smart data (only when autopilot ON) ─────────────────────
$insights      = [];
$productEngine = ['winners'=>[],'losers'=>[],'summary'=>['winners'=>0,'losers'=>0,'neutral'=>0]];
$brandScore    = ['score'=>0,'grade'=>'—','color'=>'#adb5bd','label'=>'—','breakdown'=>[],'meta'=>[]];
$allProducts   = [];
if ($autopilotOn) {
    $insights      = getAutopilotInsights($shopId);
    $productEngine = getProductEngineData($shopId);
    $brandScore    = getBrandScore($shopId);

    // Products list for AI tester (with 30-day sales history)
    $apQ = $db->prepare(
        "SELECT p.id, p.name, p.retail_price, p.company_price, p.stock_quantity,
                p.unit, p.min_stock_alert, c.name as category_name,
                COALESCE(s30.qty_30d,   0) as qty_30d,
                COALESCE(s30.profit_30d,0) as profit_30d,
                COALESCE(s30.txn_30d,   0) as txn_30d,
                COALESCE(s7.qty_7d,     0) as qty_7d
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN (
             SELECT si.product_id,
                    SUM(si.quantity)     AS qty_30d,
                    SUM(si.profit)       AS profit_30d,
                    COUNT(DISTINCT s.id) AS txn_30d
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.shop_id = ?
               AND DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY si.product_id
         ) s30 ON s30.product_id = p.id
         LEFT JOIN (
             SELECT si.product_id, SUM(si.quantity) AS qty_7d
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.shop_id = ?
               AND DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY si.product_id
         ) s7 ON s7.product_id = p.id
         WHERE p.shop_id = ? AND p.status = 'active'
         ORDER BY qty_30d DESC, p.name ASC"
    );
    $apQ->execute([$shopId, $shopId, $shopId]);
    $allProducts = $apQ->fetchAll();
}

// ── Standard dashboard data ─────────────────────────────────
$recentSales = $db->prepare(
    "SELECT s.*, COUNT(si.id) as item_count
     FROM sales s LEFT JOIN sale_items si ON si.sale_id=s.id
     WHERE s.shop_id=? GROUP BY s.id ORDER BY s.sale_date DESC LIMIT 8"
);
$recentSales->execute([$shopId]);
$recentSales = $recentSales->fetchAll();

$topProducts = $db->prepare(
    "SELECT p.name, SUM(si.quantity) as total_qty, SUM(si.total_price) as total_rev
     FROM sale_items si
     JOIN sales s ON s.id=si.sale_id
     JOIN products p ON p.id=si.product_id
     WHERE s.shop_id=? AND DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY si.product_id ORDER BY total_qty DESC LIMIT 5"
);
$topProducts->execute([$shopId]);
$topProducts = $topProducts->fetchAll();

$lastMonthQ = $db->prepare(
    "SELECT COALESCE(SUM(grand_total),0) as t FROM sales
     WHERE shop_id=?
       AND DATE(sale_date) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
       AND DATE(sale_date) <  DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
);
$lastMonthQ->execute([$shopId]);
$lastMonthTotal = $lastMonthQ->fetch()['t'];
$growthPct = $lastMonthTotal > 0
    ? (($stats['monthly_sales'] - $lastMonthTotal) / $lastMonthTotal * 100)
    : ($stats['monthly_sales'] > 0 ? 100 : 0);

// ── 7-day profit chart ───────────────────────────────────────
$profitChart = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $r = $db->prepare(
        "SELECT COALESCE(SUM(si.profit),0) p FROM sale_items si
         JOIN sales s ON s.id=si.sale_id
         WHERE s.shop_id=? AND DATE(s.sale_date)=?"
    );
    $r->execute([$shopId, $d]);
    $profitChart[] = (float)$r->fetch()['p'];
}

shopHeader('Dashboard', 'dashboard');
?>

<style>
/* ─── Autopilot Toggle ─── */
.ap-toggle-btn {
  display:inline-flex; align-items:center; gap:.45rem;
  padding:.42rem 1rem; border-radius:50px;
  font-size:.8rem; font-weight:700; cursor:pointer;
  border:none; transition:all .3s; text-decoration:none;
}
.ap-toggle-btn.on {
  background:linear-gradient(135deg,#6C63FF,#3ECFCF);
  color:#fff; box-shadow:0 4px 18px rgba(108,99,255,.4);
}
.ap-toggle-btn.off {
  background:#f0f2f8; color:#6c757d; border:1.5px solid #dee2e6;
}
.ap-dot {
  width:8px; height:8px; border-radius:50%;
  background:rgba(255,255,255,.8);
  animation:apPulse 1.4s ease-in-out infinite;
}
.ap-toggle-btn.off .ap-dot { background:#adb5bd; animation:none; }
@keyframes apPulse{0%,100%{transform:scale(1);opacity:.7}50%{transform:scale(1.5);opacity:1}}

/* ─── Autopilot Banner ─── */
.ap-banner {
  background:linear-gradient(135deg,#070f1e 0%,#0d1b35 60%,#0a1628 100%);
  border-radius:16px; padding:1.2rem 1.4rem 1rem;
  border:1px solid rgba(108,99,255,.25); position:relative; overflow:hidden;
}
.ap-banner::before {
  content:''; position:absolute; top:-40px; right:-40px;
  width:180px; height:180px; border-radius:50%;
  background:radial-gradient(circle,rgba(108,99,255,.18),transparent 70%);
}
.ap-banner::after {
  content:''; position:absolute; bottom:-30px; left:30%;
  width:120px; height:120px; border-radius:50%;
  background:radial-gradient(circle,rgba(62,207,207,.12),transparent 70%);
}
.ap-scan-bar { flex:1; height:3px; border-radius:3px; background:rgba(255,255,255,.1); overflow:hidden; max-width:110px; }
.ap-scan-fill { height:100%; border-radius:3px; background:linear-gradient(90deg,#6C63FF,#3ECFCF); animation:scanFill 2s ease-in-out infinite; }
@keyframes scanFill{0%{width:0%;opacity:1}60%{width:100%;opacity:1}100%{width:100%;opacity:0}}

/* ─── Insight Card ─── */
.insight-card {
  border-radius:14px; padding:1rem 1.1rem;
  border:1px solid rgba(0,0,0,.07); background:#fff;
  transition:transform .2s, box-shadow .2s; position:relative; overflow:hidden;
}
.insight-card:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(0,0,0,.1); }
.insight-stripe { position:absolute; left:0; top:0; bottom:0; width:4px; border-radius:4px 0 0 4px; }
.insight-icon-box {
  width:40px; height:40px; border-radius:12px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:1.1rem;
}
.ins-badge {
  font-size:.6rem; font-weight:800; text-transform:uppercase;
  letter-spacing:.8px; padding:.18rem .5rem; border-radius:20px;
}

/* ─── Brand Score ─── */
.brand-score-card {
  background:linear-gradient(135deg,#0d0d1a 0%,#12122a 100%);
  border-radius:16px; padding:1.3rem 1.5rem;
  border:1px solid rgba(108,99,255,.2); position:relative; overflow:hidden;
}
.brand-score-card::before {
  content:''; position:absolute; top:-50px; right:-50px;
  width:200px; height:200px; border-radius:50%;
  background:radial-gradient(circle,rgba(108,99,255,.15),transparent 70%);
}
.brand-ring {
  width:90px; height:90px; border-radius:50%; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; flex-direction:column;
  position:relative;
}
.brand-ring-svg { position:absolute; top:0; left:0; width:100%; height:100%; transform:rotate(-90deg); }
.brand-ring-inner { z-index:1; text-align:center; line-height:1; }
.brand-ring-score { font-size:1.6rem; font-weight:900; color:#fff; }
.brand-ring-grade { font-size:.7rem; font-weight:700; letter-spacing:.5px; margin-top:2px; }
.bs-bar { height:6px; border-radius:6px; background:rgba(255,255,255,.08); overflow:hidden; flex:1; }
.bs-bar-fill { height:100%; border-radius:6px; transition:width .8s ease; }
.bs-item { display:flex; align-items:center; gap:.6rem; margin-bottom:.55rem; }
.bs-item:last-child { margin-bottom:0; }
.bs-icon { width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.75rem; flex-shrink:0; }
.bs-label { font-size:.75rem; color:rgba(255,255,255,.65); min-width:110px; }
.bs-val { font-size:.72rem; font-weight:700; white-space:nowrap; }

/* ─── Product Engine ─── */
.pe-header {
  background:linear-gradient(135deg,#070f1e,#0d1b35);
  border-radius:12px 12px 0 0; padding:.9rem 1.2rem;
}
.pe-pill {
  border-radius:30px; padding:.3rem .8rem;
  font-size:.75rem; font-weight:700;
  display:inline-flex; align-items:center; gap:.35rem;
}
.pe-card {
  border-radius:13px; padding:.8rem 1rem;
  border:1px solid rgba(20,220,200,.14); background:#101e30;
  transition:transform .18s, box-shadow .18s;
}
.pe-card:hover { transform:translateY(-2px); box-shadow:0 6px 22px rgba(0,0,0,.28),0 0 14px rgba(20,220,200,.08); }
.pe-rank {
  width:30px; height:30px; border-radius:9px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  font-size:.78rem; font-weight:800; color:#fff;
}
.pe-score-bar { height:5px; border-radius:5px; background:rgba(255,255,255,.1); margin-top:3px; overflow:hidden; }
.pe-score-fill { height:100%; border-radius:5px; }
.pe-tag { font-size:.6rem; font-weight:700; padding:.13rem .42rem; border-radius:10px; }
.view-tab {
  padding:.32rem .85rem; border-radius:8px; font-size:.78rem;
  font-weight:600; cursor:pointer; border:1.5px solid rgba(255,255,255,.14);
  color:#8eb8c4; background:rgba(255,255,255,.06); transition:all .18s;
}
.view-tab:hover:not(.active) { color:#e8f4f4; background:rgba(20,220,200,.1); border-color:rgba(20,220,200,.3); }
.view-tab.active { background:#6C63FF; color:#fff; border-color:#6C63FF; }
.ribbon {
  position:absolute; top:7px; right:-5px;
  background:linear-gradient(135deg,#28c76f,#20a85e);
  color:#fff; font-size:.58rem; font-weight:800;
  padding:.17rem .55rem .17rem .45rem; border-radius:3px 0 0 3px;
  letter-spacing:.5px; text-transform:uppercase;
}
.ribbon::after {
  content:''; position:absolute; bottom:-4px; right:0;
  border-top:4px solid #15703f; border-right:6px solid transparent;
}

/* ─── AI Product Tester ─── */
.ai-tester-card {
  background:linear-gradient(135deg,#0a0a1a,#12122a);
  border-radius:16px; border:1px solid rgba(108,99,255,.22);
  overflow:hidden; position:relative;
}
.ai-tester-card::before {
  content:''; position:absolute; top:-60px; right:-60px;
  width:220px; height:220px; border-radius:50%;
  background:radial-gradient(circle,rgba(62,207,207,.1),transparent 70%);
  pointer-events:none;
}
.ai-product-select {
  background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12);
  border-radius:10px; color:#fff; padding:.6rem .9rem;
  font-size:.85rem; width:100%; outline:none; cursor:pointer;
}
.ai-product-select option { background:#1a1a2e; color:#fff; }
.ai-product-select:focus { border-color:#6C63FF; box-shadow:0 0 0 3px rgba(108,99,255,.15); }
.ai-test-btn {
  background:linear-gradient(135deg,#6C63FF,#3ECFCF);
  border:none; border-radius:10px; color:#fff;
  padding:.6rem 1.3rem; font-size:.83rem; font-weight:700;
  cursor:pointer; transition:all .25s; display:inline-flex;
  align-items:center; gap:.4rem; white-space:nowrap;
}
.ai-test-btn:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(108,99,255,.45); }
.ai-test-btn:disabled { opacity:.55; cursor:not-allowed; transform:none; }
.ai-result-box {
  background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08);
  border-radius:12px; padding:1rem 1.2rem; margin-top:.8rem;
  display:none;
}
.ai-metric {
  display:flex; align-items:center; justify-content:space-between;
  padding:.45rem 0; border-bottom:1px solid rgba(255,255,255,.06);
}
.ai-metric:last-child { border-bottom:none; }
.ai-verdict {
  border-radius:12px; padding:1rem 1.2rem;
  margin-top:.75rem; display:none;
}
.ai-spin { display:inline-block; animation:spin .7s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.ai-rating-stars span { font-size:1.1rem; }
</style>

<!-- ═══ PAGE HEADER ═══════════════════════════════════════════ -->
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h1 class="page-title">Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Owner') ?>! 👋</h1>
        <p class="page-subtitle mb-0"><?= date('l, d F Y') ?> · <?= htmlspecialchars($_SESSION['shop_name'] ?? '') ?></p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <!-- Autopilot Toggle -->
        <a href="<?= BASE_URL ?>/shop/index.php?autopilot=<?= $autopilotOn ? '0' : '1' ?>"
           class="ap-toggle-btn <?= $autopilotOn ? 'on' : 'off' ?>">
            <?php if ($autopilotOn): ?>
                <span class="ap-dot"></span>
                <i class="bi bi-cpu-fill"></i> Autopilot ON
            <?php else: ?>
                <i class="bi bi-cpu"></i> Autopilot OFF
            <?php endif; ?>
        </a>
        <a href="<?= BASE_URL ?>/shop/pos.php" class="btn btn-primary">
            <i class="bi bi-cart3 me-1"></i>Open POS
        </a>
    </div>
</div>

<!-- ═══ AUTOPILOT OFF HINT ══════════════════════════════════════ -->
<?php if (!$autopilotOn): ?>
<div style="background:linear-gradient(135deg,rgba(108,99,255,.08) 0%,rgba(14,206,206,.05) 100%);border:2px dashed rgba(108,99,255,.3);border-radius:18px;padding:2rem 1.5rem;text-align:center;margin-bottom:1.5rem;">
    <div style="width:64px;height:64px;background:rgba(108,99,255,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .9rem;font-size:1.8rem;color:#6C63FF;">
        <i class="bi bi-cpu"></i>
    </div>
    <h5 style="color:#f0ecff;font-weight:800;margin-bottom:.4rem;">Autopilot Mode is OFF</h5>
    <p style="color:#adb5bd;font-size:.88rem;margin-bottom:1.3rem;max-width:460px;margin-left:auto;margin-right:auto;line-height:1.6;">
        Turn ON Autopilot to unlock
        <strong style="color:#6C63FF;">Business Insights</strong>,
        <strong style="color:#f59e0b;">Brand Score</strong>,
        <strong style="color:#28c76f;">Winning &amp; Losing Products Engine</strong>, and
        <strong style="color:#3ECFCF;">AI Product Tester</strong>.
    </p>
    <div class="d-flex justify-content-center gap-3 flex-wrap mb-3">
        <div style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:.65rem 1rem;display:flex;align-items:center;gap:.5rem;min-width:160px;">
            <div style="width:32px;height:32px;background:rgba(108,99,255,.1);border-radius:9px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-bar-chart-line" style="color:#6C63FF;"></i></div>
            <div style="text-align:left;"><div style="font-size:.75rem;font-weight:700;color:#f0ecff;">Business Insights</div><div style="font-size:.67rem;color:#adb5bd;">Smart alerts & tips</div></div>
        </div>
        <div style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:.65rem 1rem;display:flex;align-items:center;gap:.5rem;min-width:160px;">
            <div style="width:32px;height:32px;background:rgba(245,158,11,.1);border-radius:9px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-patch-check-fill" style="color:#f59e0b;"></i></div>
            <div style="text-align:left;"><div style="font-size:.75rem;font-weight:700;color:#f0ecff;">Brand Score</div><div style="font-size:.67rem;color:#adb5bd;">Overall health rating</div></div>
        </div>
        <div style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:.65rem 1rem;display:flex;align-items:center;gap:.5rem;min-width:160px;">
            <div style="width:32px;height:32px;background:rgba(40,199,111,.1);border-radius:9px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-lightning-charge-fill" style="color:#28c76f;"></i></div>
            <div style="text-align:left;"><div style="font-size:.75rem;font-weight:700;color:#f0ecff;">Products Engine</div><div style="font-size:.67rem;color:#adb5bd;">Winners & losers</div></div>
        </div>
        <div style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:.65rem 1rem;display:flex;align-items:center;gap:.5rem;min-width:160px;">
            <div style="width:32px;height:32px;background:rgba(62,207,207,.1);border-radius:9px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-robot" style="color:#3ECFCF;"></i></div>
            <div style="text-align:left;"><div style="font-size:.75rem;font-weight:700;color:#f0ecff;">AI Product Tester</div><div style="font-size:.67rem;color:#adb5bd;">Instant AI analysis</div></div>
        </div>
    </div>
    <a href="<?= BASE_URL ?>/shop/index.php?autopilot=1"
       class="ap-toggle-btn on" style="display:inline-flex;text-decoration:none;padding:.6rem 1.6rem;font-size:.88rem;">
        <span class="ap-dot"></span>
        <i class="bi bi-cpu-fill"></i> Turn On Autopilot
    </a>
</div>
<?php endif; ?>

<!-- ═══ AUTOPILOT SMART SECTION (only when ON) ════════════════ -->
<?php if ($autopilotOn): ?>

<!-- ── Autopilot Banner ── -->
<div class="ap-banner mb-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3" style="position:relative;z-index:1;">
        <div class="d-flex align-items-center gap-3">
            <div style="width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);display:flex;align-items:center;justify-content:center;font-size:1.3rem;box-shadow:0 6px 18px rgba(108,99,255,.45);flex-shrink:0;">
                <i class="bi bi-cpu-fill text-white"></i>
            </div>
            <div>
                <div style="color:#fff;font-weight:800;font-size:1rem;letter-spacing:.3px;">Autopilot Insight Mode</div>
                <div class="d-flex align-items-center gap-2 mt-1" style="color:rgba(255,255,255,.55);font-size:.78rem;">
                    <span>Analyzing your business</span>
                    <div class="ap-scan-bar"><div class="ap-scan-fill"></div></div>
                    <span><?= count($insights) ?> insights found</span>
                </div>
            </div>
        </div>
        <!-- Chips -->
        <div class="d-flex gap-2 flex-wrap" style="position:relative;z-index:1;">
            <?php
            $riskC = count(array_filter($insights, fn($i) => $i['type']==='risk'));
            $warnC = count(array_filter($insights, fn($i) => $i['type']==='warning'));
            $oppC  = count(array_filter($insights, fn($i) => $i['type']==='opportunity'));
            ?>
            <?php if ($riskC > 0): ?>
            <span class="pe-pill" style="background:rgba(234,84,85,.2);color:#ea5455;">
                <i class="bi bi-shield-exclamation"></i><?= $riskC ?> Risk<?= $riskC>1?'s':'' ?>
            </span>
            <?php endif; ?>
            <?php if ($warnC > 0): ?>
            <span class="pe-pill" style="background:rgba(255,159,67,.2);color:#ff9f43;">
                <i class="bi bi-exclamation-triangle"></i><?= $warnC ?> Warning<?= $warnC>1?'s':'' ?>
            </span>
            <?php endif; ?>
            <?php if ($oppC > 0): ?>
            <span class="pe-pill" style="background:rgba(40,199,111,.2);color:#28c76f;">
                <i class="bi bi-lightning-charge"></i><?= $oppC ?> Opportunit<?= $oppC>1?'ies':'y' ?>
            </span>
            <?php endif; ?>
            <?php if (empty($insights)): ?>
            <span class="pe-pill" style="background:rgba(62,207,207,.15);color:#3ECFCF;">
                <i class="bi bi-check2-circle"></i>Business looks healthy
            </span>
            <?php endif; ?>
        </div>
    </div>
    <!-- Insight Cards -->
    <?php if ($insights): ?>
    <div class="row g-2" style="position:relative;z-index:1;">
        <?php foreach ($insights as $ins):
            $typeMap = [
                'risk'        => ['bg'=>'rgba(234,84,85,.08)',  'bb'=>'rgba(234,84,85,.18)',  'bc'=>'#ea5455','lbl'=>'Risk'],
                'warning'     => ['bg'=>'rgba(255,159,67,.08)', 'bb'=>'rgba(255,159,67,.18)', 'bc'=>'#ff9f43','lbl'=>'Warning'],
                'opportunity' => ['bg'=>'rgba(40,199,111,.07)', 'bb'=>'rgba(40,199,111,.18)', 'bc'=>'#28c76f','lbl'=>'Opportunity'],
                'info'        => ['bg'=>'rgba(108,99,255,.07)', 'bb'=>'rgba(108,99,255,.18)', 'bc'=>'#6C63FF','lbl'=>'Info'],
                'action'      => ['bg'=>'rgba(0,207,232,.07)',  'bb'=>'rgba(0,207,232,.18)',  'bc'=>'#00cfe8','lbl'=>'Action'],
            ];
            $tm = $typeMap[$ins['type']] ?? $typeMap['info'];
        ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="insight-card" style="background:<?= $tm['bg'] ?>;border-color:<?= $ins['color'] ?>22;">
                <div class="insight-stripe" style="background:<?= $ins['color'] ?>;"></div>
                <div class="d-flex gap-2 align-items-start ps-1">
                    <div class="insight-icon-box" style="background:<?= $ins['color'] ?>22;color:<?= $ins['color'] ?>;">
                        <i class="bi bi-<?= htmlspecialchars($ins['icon']) ?>"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div class="d-flex align-items-center gap-1 mb-1 flex-wrap">
                            <span style="font-size:.84rem;font-weight:700;color:#1e1e2d;"><?= htmlspecialchars($ins['title']) ?></span>
                            <span class="ins-badge" style="background:<?= $tm['bb'] ?>;color:<?= $tm['bc'] ?>;"><?= $tm['lbl'] ?></span>
                        </div>
                        <p class="mb-0" style="font-size:.79rem;color:#5a5a72;line-height:1.5;"><?= htmlspecialchars($ins['text']) ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:1.2rem 0;color:rgba(255,255,255,.35);position:relative;z-index:1;">
        <i class="bi bi-check2-all" style="font-size:1.8rem;display:block;margin-bottom:.4rem;color:#3ECFCF;"></i>
        <span style="font-size:.83rem;">No critical issues found. Business looks healthy!</span>
    </div>
    <?php endif; ?>
</div>

<!-- ── Brand Score + AI Product Tester ROW ── -->
<div class="row g-3 mb-4">

    <!-- BRAND SCORE -->
    <div class="col-12 col-lg-5">
        <div class="brand-score-card h-100">
            <div class="d-flex align-items-center gap-2 mb-3" style="position:relative;z-index:1;">
                <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-patch-check-fill text-white" style="font-size:.85rem;"></i>
                </div>
                <div>
                    <div style="color:#fff;font-weight:800;font-size:.92rem;">Brand Score</div>
                    <div style="color:rgba(255,255,255,.4);font-size:.72rem;">Overall business health</div>
                </div>
            </div>

            <div class="d-flex align-items-center gap-4" style="position:relative;z-index:1;">
                <!-- Ring -->
                <div class="brand-ring">
                    <svg class="brand-ring-svg" viewBox="0 0 90 90">
                        <circle cx="45" cy="45" r="38" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="8"/>
                        <circle cx="45" cy="45" r="38" fill="none"
                            stroke="<?= $brandScore['color'] ?>" stroke-width="8"
                            stroke-linecap="round"
                            stroke-dasharray="<?= round(2*M_PI*38) ?>"
                            stroke-dashoffset="<?= round(2*M_PI*38 * (1 - $brandScore['score']/100)) ?>"/>
                    </svg>
                    <div class="brand-ring-inner">
                        <div class="brand-ring-score" style="color:<?= $brandScore['color'] ?>;"><?= $brandScore['score'] ?></div>
                        <div class="brand-ring-grade" style="color:<?= $brandScore['color'] ?>;"><?= $brandScore['grade'] ?></div>
                    </div>
                </div>
                <!-- Label + meta -->
                <div>
                    <div style="color:<?= $brandScore['color'] ?>;font-weight:800;font-size:1.05rem;"><?= $brandScore['label'] ?></div>
                    <div style="color:rgba(255,255,255,.4);font-size:.75rem;margin-top:3px;">out of 100 points</div>
                    <div class="mt-2 d-flex flex-wrap gap-1">
                        <?php if ($brandScore['meta']['out_of_stock'] > 0): ?>
                        <span style="font-size:.68rem;background:rgba(234,84,85,.2);color:#ea5455;padding:.15rem .45rem;border-radius:8px;">
                            <?= $brandScore['meta']['out_of_stock'] ?> Out of Stock
                        </span>
                        <?php endif; ?>
                        <?php if ($brandScore['meta']['margin_pct'] > 0): ?>
                        <span style="font-size:.68rem;background:rgba(40,199,111,.15);color:#28c76f;padding:.15rem .45rem;border-radius:8px;">
                            <?= $brandScore['meta']['margin_pct'] ?>% Margin
                        </span>
                        <?php endif; ?>
                        <?php if ($brandScore['meta']['days_with_sales'] > 0): ?>
                        <span style="font-size:.68rem;background:rgba(108,99,255,.15);color:#8B83FF;padding:.15rem .45rem;border-radius:8px;">
                            <?= $brandScore['meta']['days_with_sales'] ?>/7 Active Days
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Breakdown bars -->
            <div class="mt-3" style="position:relative;z-index:1;">
                <?php foreach ($brandScore['breakdown'] as $br): ?>
                <div class="bs-item">
                    <div class="bs-icon" style="background:<?= $br['color'] ?>22;color:<?= $br['color'] ?>;">
                        <i class="bi bi-<?= $br['icon'] ?>"></i>
                    </div>
                    <span class="bs-label"><?= $br['label'] ?></span>
                    <div class="bs-bar">
                        <div class="bs-bar-fill" style="width:<?= $br['score'] ?>%;background:<?= $br['color'] ?>;"></div>
                    </div>
                    <span class="bs-val" style="color:<?= $br['color'] ?>;"><?= $br['score'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- AI SMART LAB SHORTCUT -->
    <div class="col-12 col-lg-7">
        <div class="ai-tester-card h-100 p-0">
            <!-- Header -->
            <div class="p-3" style="border-bottom:1px solid rgba(255,255,255,.07);position:relative;z-index:1;">
                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-robot text-white" style="font-size:.85rem;"></i>
                        </div>
                        <div>
                            <div style="color:#fff;font-weight:800;font-size:.92rem;">AI Smart Lab</div>
                            <div style="color:rgba(255,255,255,.4);font-size:.72rem;">6 powerful AI tools — product test, price, demand & more</div>
                        </div>
                    </div>
                    <a href="<?= BASE_URL ?>/shop/ai_lab.php"
                       style="background:linear-gradient(135deg,#6C63FF,#3ECFCF);border:none;border-radius:10px;
                              color:#fff;padding:.45rem 1.1rem;font-size:.78rem;font-weight:700;
                              text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;
                              box-shadow:0 4px 14px rgba(108,99,255,.4);white-space:nowrap;">
                        <i class="bi bi-box-arrow-up-right"></i> Open AI Lab
                    </a>
                </div>
            </div>
            <!-- Feature Grid -->
            <div class="p-3" style="position:relative;z-index:1;">
                <div class="row g-2">
                    <?php
                    $aiFeatures = [
                        ['icon'=>'cpu-fill',             'color'=>'#6C63FF', 'title'=>'AI Product Test',    'desc'=>'Full analysis + forecast chart'],
                        ['icon'=>'tags-fill',             'color'=>'#ff9f43', 'title'=>'Smart Price',        'desc'=>'Best price recommendation'],
                        ['icon'=>'graph-up-arrow',        'color'=>'#3ECFCF', 'title'=>'Demand Predict',    'desc'=>'7-day demand forecast'],
                        ['icon'=>'shield-exclamation',   'color'=>'#ea5455', 'title'=>'Loss Prevention',    'desc'=>'Auto loss detection'],
                        ['icon'=>'chat-dots-fill',        'color'=>'#a55eea', 'title'=>'AI Advisor',        'desc'=>'Chat-like business Q&A'],
                        ['icon'=>'lightning-charge-fill', 'color'=>'#00cfe8', 'title'=>'Auto Tags',         'desc'=>'Smart product categorization'],
                    ];
                    foreach ($aiFeatures as $f): ?>
                    <div class="col-6">
                        <a href="<?= BASE_URL ?>/shop/ai_lab.php" style="text-decoration:none;">
                            <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);
                                        border-radius:12px;padding:.65rem .85rem;display:flex;align-items:center;
                                        gap:.65rem;transition:all .2s;cursor:pointer;"
                                 onmouseover="this.style.borderColor='<?= $f['color'] ?>44';this.style.background='<?= $f['color'] ?>0d';"
                                 onmouseout="this.style.borderColor='rgba(255,255,255,.07)';this.style.background='rgba(255,255,255,.03)';">
                                <div style="width:30px;height:30px;border-radius:8px;background:<?= $f['color'] ?>22;
                                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="bi bi-<?= $f['icon'] ?>" style="color:<?= $f['color'] ?>;font-size:.8rem;"></i>
                                </div>
                                <div style="min-width:0;">
                                    <div style="color:#fff;font-weight:700;font-size:.78rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $f['title'] ?></div>
                                    <div style="color:rgba(255,255,255,.32);font-size:.65rem;"><?= $f['desc'] ?></div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Quick stats from last run -->
                <?php
                $winCount  = $productEngine['summary']['winners'] ?? 0;
                $loseCount = $productEngine['summary']['losers']  ?? 0;
                ?>
                <div class="mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,.06);">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex gap-3">
                            <span style="font-size:.72rem;color:rgba(255,255,255,.4);">
                                <i class="bi bi-trophy-fill me-1" style="color:#28c76f;"></i><?= $winCount ?> winners
                            </span>
                            <span style="font-size:.72rem;color:rgba(255,255,255,.4);">
                                <i class="bi bi-arrow-down-circle-fill me-1" style="color:#ea5455;"></i><?= $loseCount ?> losing
                            </span>
                            <span style="font-size:.72rem;color:rgba(255,255,255,.4);">
                                <i class="bi bi-box-seam me-1" style="color:#6C63FF;"></i><?= count($allProducts) ?> products scanned
                            </span>
                        </div>
                        <a href="<?= BASE_URL ?>/shop/ai_lab.php" style="font-size:.72rem;color:#6C63FF;text-decoration:none;font-weight:700;">
                            Full Analysis <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Winning & Losing Products Engine ── -->
<div class="card mb-4">
    <div class="pe-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <div style="width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-lightning-charge-fill text-white"></i>
            </div>
            <div>
                <div style="color:#fff;font-weight:800;font-size:.93rem;">Winning &amp; Losing Products Engine</div>
                <div style="color:rgba(255,255,255,.4);font-size:.73rem;">AI-scored performance · last 30 days</div>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <span class="pe-pill" style="background:rgba(40,199,111,.2);color:#28c76f;">
                <i class="bi bi-trophy-fill"></i><?= $productEngine['summary']['winners'] ?> Winners
            </span>
            <span class="pe-pill" style="background:rgba(234,84,85,.2);color:#ea5455;">
                <i class="bi bi-arrow-down-circle-fill"></i><?= $productEngine['summary']['losers'] ?> Losing
            </span>
            <span class="pe-pill" style="background:rgba(255,255,255,.08);color:rgba(255,255,255,.45);">
                <i class="bi bi-dash-circle"></i><?= $productEngine['summary']['neutral'] ?> Neutral
            </span>
            <div class="d-flex gap-1 ms-1">
                <button class="view-tab active" id="tab-winners" onclick="peTab('winners',this)">🏆 Winners</button>
                <button class="view-tab" id="tab-losers"  onclick="peTab('losers',this)">📉 Losers</button>
            </div>
        </div>
    </div>

    <div class="card-body p-3">
        <!-- WINNERS -->
        <div id="pe-winners">
            <?php if ($productEngine['winners']): ?>
            <div class="d-flex align-items-center gap-2 mb-3">
                <span style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#28c76f;">
                    <i class="bi bi-trophy-fill me-1"></i>WINNING PRODUCTS
                </span>
                <div style="flex:1;height:1px;background:linear-gradient(90deg,rgba(40,199,111,.3),transparent);"></div>
                <small class="text-muted">Top <?= count($productEngine['winners']) ?> by score</small>
            </div>
            <div class="row g-2">
                <?php
                $rankColors = ['#28c76f,#20a85e','#6C63FF,#4f47d4','#ff9f43,#e08c37','#00cfe8,#00b4cc','#3ECFCF,#2eb8b8','#ea5455,#c44b4b','#a55eea,#8e44d9','#fd7e14,#e6710f'];
                foreach ($productEngine['winners'] as $i => $p): ?>
                <div class="col-12 col-sm-6 col-xl-4">
                    <div class="pe-card" style="position:relative;box-shadow:0 0 0 2px rgba(40,199,111,.12);">
                        <?php if ($i < 3): ?>
                        <div class="ribbon"><?= ['🥇 Best','🥈 2nd','🥉 3rd'][$i] ?></div>
                        <?php endif; ?>
                        <div class="d-flex align-items-start gap-2">
                            <div class="pe-rank" style="background:linear-gradient(135deg,<?= $rankColors[$i % 8] ?>);"><?= $i+1 ?></div>
                            <div style="flex:1;min-width:0;">
                                <div class="fw-bold small mb-1" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="d-flex align-items-center gap-1 mb-1">
                                    <div class="pe-score-bar" style="flex:1;">
                                        <div class="pe-score-fill" style="width:<?= min(100,$p['score']) ?>%;background:linear-gradient(90deg,#28c76f,#3ECFCF);"></div>
                                    </div>
                                    <span style="font-size:.67rem;font-weight:800;color:#28c76f;"><?= $p['score'] ?>/100</span>
                                </div>
                                <div class="d-flex gap-2 flex-wrap mb-1">
                                    <span style="font-size:.69rem;color:var(--text2,#8eb8c4);"><i class="bi bi-bag-check text-success me-1"></i><?= number_format($p['qty_30d']) ?> sold</span>
                                    <span style="font-size:.69rem;color:var(--text2,#8eb8c4);"><i class="bi bi-currency-rupee text-primary me-1"></i><?= formatCurrency($p['rev_30d']) ?></span>
                                    <span style="font-size:.69rem;color:<?= $p['margin_pct']>=20?'#28c76f':($p['margin_pct']>=10?'#ff9f43':'#ea5455') ?>;"><i class="bi bi-percent me-1"></i><?= $p['margin_pct'] ?>%</span>
                                </div>
                                <?php if ($p['tags']): ?>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php foreach (array_slice($p['tags'],0,3) as $tag): ?>
                                    <span class="pe-tag" style="background:<?= $tag['color'] ?>22;color:<?= $tag['color'] ?>;"><?= htmlspecialchars($tag['label']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <span class="badge rounded-pill <?= $p['stock_quantity']<=0?'bg-danger':($p['stock_quantity']<=$p['min_stock_alert']?'bg-warning text-dark':'bg-success bg-opacity-10 text-success') ?>" style="font-size:.63rem;">
                                    <?= $p['stock_quantity'] ?> <?= htmlspecialchars($p['unit']?:'pcs') ?>
                                </span>
                                <?php if ($p['category_name']): ?>
                                <div style="font-size:.6rem;color:#adb5bd;margin-top:3px;"><?= htmlspecialchars($p['category_name']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3 p-3 rounded-3 d-flex gap-2 align-items-start" style="background:rgba(40,199,111,.06);border:1px dashed rgba(40,199,111,.25);">
                <i class="bi bi-lightbulb-fill text-success mt-1 flex-shrink-0"></i>
                <div style="font-size:.8rem;color:#94dcb8;"><strong>Action:</strong> Always keep these products in stock. Create bundle deals and expand retail display space — these are your highest revenue generators.</div>
            </div>
            <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-trophy" style="font-size:2.8rem;opacity:.2;display:block;margin-bottom:.6rem;"></i>
                <strong>No winning products yet</strong><br>
                <small>Make more sales and data will appear here automatically.</small>
            </div>
            <?php endif; ?>
        </div>

        <!-- LOSERS -->
        <div id="pe-losers" style="display:none;">
            <?php if ($productEngine['losers']): ?>
            <div class="d-flex align-items-center gap-2 mb-3">
                <span style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#ea5455;">
                    <i class="bi bi-arrow-down-circle-fill me-1"></i>LOSING PRODUCTS
                </span>
                <div style="flex:1;height:1px;background:linear-gradient(90deg,rgba(234,84,85,.3),transparent);"></div>
                <small class="text-muted"><?= count($productEngine['losers']) ?> items need attention</small>
            </div>
            <div class="row g-2">
                <?php foreach ($productEngine['losers'] as $i => $p): ?>
                <div class="col-12 col-sm-6 col-xl-4">
                    <div class="pe-card" style="box-shadow:0 0 0 2px rgba(234,84,85,.1);">
                        <div class="d-flex align-items-start gap-2">
                            <div class="pe-rank" style="background:linear-gradient(135deg,#ea5455,#c44b4b);">
                                <i class="bi bi-exclamation" style="font-size:.85rem;"></i>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div class="fw-bold small mb-1" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="d-flex align-items-center gap-1 mb-1">
                                    <div class="pe-score-bar" style="flex:1;">
                                        <div class="pe-score-fill" style="width:<?= min(100,max(2,$p['score'])) ?>%;background:linear-gradient(90deg,#ea5455,#ff9f43);"></div>
                                    </div>
                                    <span style="font-size:.67rem;font-weight:800;color:#ea5455;"><?= $p['score'] ?>/100</span>
                                </div>
                                <div class="d-flex gap-2 flex-wrap mb-1">
                                    <span style="font-size:.69rem;color:var(--text2,#8eb8c4);"><i class="bi bi-bag-x text-danger me-1"></i><?= $p['qty_30d']>0 ? number_format($p['qty_30d']).' sold' : 'No sales (30d)' ?></span>
                                    <span style="font-size:.69rem;color:var(--text2,#8eb8c4);"><i class="bi bi-boxes text-muted me-1"></i><?= $p['stock_quantity'] ?> in stock</span>
                                </div>
                                <?php if ($p['tags']): ?>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php foreach (array_slice($p['tags'],0,3) as $tag): ?>
                                    <span class="pe-tag" style="background:<?= $tag['color'] ?>22;color:<?= $tag['color'] ?>;"><?= htmlspecialchars($tag['label']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3 p-3 rounded-3 d-flex gap-2 align-items-start" style="background:rgba(234,84,85,.05);border:1px dashed rgba(234,84,85,.2);">
                <i class="bi bi-lightbulb-fill text-warning mt-1 flex-shrink-0"></i>
                <div style="font-size:.8rem;color:#ffb0b0;"><strong>Action:</strong> Slow-moving stock clear karne ke liye discount promotions try karein. Reorder quantity reduce karein ya in products ko better alternatives se replace karein.</div>
            </div>
            <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-check2-circle" style="font-size:2.8rem;color:#28c76f;opacity:.35;display:block;margin-bottom:.6rem;"></i>
                <strong>No under-performing products!</strong><br>
                <small>All products are performing at an acceptable level.</small>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endif; // $autopilotOn ?>

<!-- ═══ STATS ROW ══════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-calendar-day"></i></div>
            <div class="stat-card-value"><?= formatCurrency($stats['today_sales']) ?></div>
            <div class="stat-card-label">Today's Sales</div>
            <div class="stat-card-change"><i class="bi bi-receipt me-1"></i><?= $stats['today_count'] ?> transactions</div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-graph-up"></i></div>
            <div class="stat-card-value"><?= formatCurrency($stats['today_profit']) ?></div>
            <div class="stat-card-label">Today's Profit</div>
            <div class="stat-card-change"><i class="bi bi-currency-rupee me-1"></i><?= formatCurrency($stats['monthly_profit']) ?> monthly</div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-calendar-month"></i></div>
            <div class="stat-card-value"><?= formatCurrency($stats['monthly_sales']) ?></div>
            <div class="stat-card-label">Monthly Sales</div>
            <div class="stat-card-change">
                <i class="bi bi-arrow-<?= $growthPct>=0?'up':'down' ?> me-1"></i><?= abs(round($growthPct)) ?>% vs last month
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-<?= $stats['low_stock']>0?'danger':'info' ?>">
            <div class="stat-card-icon"><i class="bi bi-box-seam"></i></div>
            <div class="stat-card-value"><?= $stats['total_products'] ?></div>
            <div class="stat-card-label">Total Products</div>
            <div class="stat-card-change">
                <?php if ($stats['low_stock'] > 0): ?>
                <i class="bi bi-exclamation-triangle me-1"></i><?= $stats['low_stock'] ?> low stock!
                <?php else: ?>
                <i class="bi bi-check-circle me-1"></i>All stock OK
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══ CHARTS ROW ═════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-bar-chart-line me-2 text-primary"></i>Sales &amp; Profit — Last 7 Days</span>
                <a href="<?= BASE_URL ?>/shop/sales.php" class="btn btn-sm btn-outline-primary">Full Report</a>
            </div>
            <div class="card-body p-3">
                <canvas id="salesChart" height="120"></canvas>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-trophy me-2 text-warning"></i>Top Products (30d)</span>
                <a href="<?= BASE_URL ?>/shop/products.php" class="btn btn-sm btn-outline-primary">All</a>
            </div>
            <div class="card-body p-3">
                <?php if ($topProducts):
                $maxQty = max(array_column($topProducts,'total_qty')) ?: 1;
                $clrs   = ['#6C63FF,#8B83FF','#28c76f,#48DA89','#ff9f43,#FFBC67','#00cfe8,#1CE7FF','#ea5455,#F08182'];
                foreach ($topProducts as $i => $tp): ?>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div style="width:28px;height:28px;background:linear-gradient(135deg,<?= $clrs[$i] ?>);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.74rem;font-weight:700;"><?= $i+1 ?></div>
                    <div style="flex:1;min-width:0;">
                        <div class="fw-semibold small" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($tp['name']) ?></div>
                        <div class="progress mt-1" style="height:5px;border-radius:10px;">
                            <div class="progress-bar" style="width:<?= ($tp['total_qty']/$maxQty*100) ?>%;background:linear-gradient(90deg,#6C63FF,#3ECFCF);border-radius:10px;"></div>
                        </div>
                    </div>
                    <div class="text-end flex-shrink-0">
                        <div class="fw-bold small text-success"><?= formatCurrency($tp['total_rev']) ?></div>
                        <div class="text-muted" style="font-size:.71rem;"><?= $tp['total_qty'] ?> sold</div>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-bar-chart" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
                    No sales yet<br>
                    <a href="<?= BASE_URL ?>/shop/pos.php" class="btn btn-sm btn-primary mt-2">Start Selling</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══ RECENT SALES + LOW STOCK ══════════════════════════════ -->
<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-receipt me-2 text-primary"></i>Recent Sales</span>
                <a href="<?= BASE_URL ?>/shop/sales.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Invoice</th><th>Type</th><th>Customer</th><th>Items</th><th>Total</th><th>Time</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($recentSales as $sale): ?>
                        <tr>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($sale['invoice_no']) ?></td>
                            <td><span class="badge <?= $sale['sale_type']==='retail'?'bg-info':'bg-warning' ?> text-white"><?= ucfirst($sale['sale_type']) ?></span></td>
                            <td><small><?= htmlspecialchars($sale['customer_name'] ?: 'Walk-in') ?></small></td>
                            <td><span class="badge" style="background:rgba(255,255,255,.1);color:#e0d8ff;"><?= $sale['item_count'] ?></span></td>
                            <td class="fw-bold"><?= formatCurrency($sale['grand_total']) ?></td>
                            <td><small class="text-muted"><?php $sidt=new DateTime($sale['sale_date'],new DateTimeZone('UTC')); $sidt->setTimezone(new DateTimeZone('Asia/Karachi')); echo $sidt->format('h:i A'); ?></small></td>
                            <td><a href="<?= BASE_URL ?>/shop/invoice.php?id=<?= $sale['id'] ?>" class="btn btn-xs btn-outline-primary" style="padding:.2rem .5rem;font-size:.75rem;"><i class="bi bi-printer"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentSales)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No sales yet. <a href="<?= BASE_URL ?>/shop/pos.php">Open POS →</a></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Low Stock Alert</span>
                <a href="<?= BASE_URL ?>/shop/reorder_alerts.php" class="btn btn-sm btn-outline-warning">Manage</a>
            </div>
            <div class="card-body p-0">
                <?php if ($lowStockProducts): ?>
                <div class="list-group list-group-flush">
                    <?php foreach (array_slice($lowStockProducts,0,6) as $p): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                        <div style="min-width:0;">
                            <div class="fw-semibold small" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($p['name']) ?></div>
                            <small class="text-muted">Min: <?= $p['min_stock_alert'] ?></small>
                        </div>
                        <span class="badge <?= $p['stock_quantity']<=0?'bg-danger':'bg-warning text-dark' ?> rounded-pill flex-shrink-0 ms-2">
                            <?= $p['stock_quantity'] ?> left
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($lowStockProducts) > 6): ?>
                <div class="text-center py-2">
                    <a href="<?= BASE_URL ?>/shop/reorder_alerts.php" class="btn btn-sm btn-outline-danger">+<?= count($lowStockProducts)-6 ?> more</a>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-check-circle text-success" style="font-size:2.2rem;display:block;margin-bottom:.4rem;opacity:.6;"></i>
                    <span class="text-success fw-semibold">All products well stocked!</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══ SCRIPTS ════════════════════════════════════════════════ -->
<script>
// ── Chart ────────────────────────────────────────────────────
window.addEventListener('load', function(){
    const ctx = document.getElementById('salesChart').getContext('2d');
    new Chart(ctx, {
        type:'bar',
        data:{
            labels: <?= json_encode(array_column($chartData,'date')) ?>,
            datasets:[
                {
                    label:'Sales',
                    data: <?= json_encode(array_column($chartData,'total')) ?>,
                    backgroundColor:'rgba(108,99,255,0.14)',
                    borderColor:'#6C63FF', borderWidth:2, borderRadius:8, order:2
                },
                {
                    label:'Profit', type:'line',
                    data: <?= json_encode($profitChart) ?>,
                    borderColor:'#28c76f', backgroundColor:'rgba(40,199,111,0.1)',
                    borderWidth:2.5, pointBackgroundColor:'#28c76f',
                    pointRadius:4, pointHoverRadius:6,
                    fill:true, tension:0.4, order:1
                }
            ]
        },
        options:{
            responsive:true,
            interaction:{mode:'index',intersect:false},
            plugins:{
                legend:{display:true,position:'top',labels:{usePointStyle:true,boxWidth:8,font:{size:11}}},
                tooltip:{callbacks:{label:c=>' Rs. '+c.parsed.y.toLocaleString()}}
            },
            scales:{
                y:{beginAtZero:true, ticks:{callback:v=>'Rs.'+(v>=1000?(v/1000).toFixed(0)+'K':v)}, grid:{color:'#f0f0f0'}},
                x:{grid:{display:false}}
            }
        }
    });
});

// ── Product Engine tabs ──────────────────────────────────────
function peTab(tab, btn) {
    document.getElementById('pe-winners').style.display = tab==='winners' ? '' : 'none';
    document.getElementById('pe-losers').style.display  = tab==='losers'  ? '' : 'none';
    document.querySelectorAll('.view-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// ── AI Product Tester ────────────────────────────────────────
const aiSelect  = document.getElementById('aiProductSelect');
const aiTestBtn = document.getElementById('aiTestBtn');

if (aiSelect) {
    aiSelect.addEventListener('change', function(){
        aiTestBtn.disabled = !this.value;
        document.getElementById('aiResultBox').style.display  = 'none';
        document.getElementById('aiEmptyState').style.display = this.value ? 'none' : '';
    });
}

function runAiTest() {
    const sel = document.getElementById('aiProductSelect');
    if (!sel || !sel.value) return;

    const opt      = sel.options[sel.selectedIndex];
    const name     = opt.dataset.name;
    const retail   = parseFloat(opt.dataset.retail)   || 0;
    const cost     = parseFloat(opt.dataset.cost)      || 0;
    const stock    = parseInt(opt.dataset.stock)       || 0;
    const unit     = opt.dataset.unit  || 'pcs';
    const min      = parseInt(opt.dataset.min)         || 5;
    const cat      = opt.dataset.cat   || 'General';
    const qty30    = parseInt(opt.dataset.qty30)       || 0;   // real 30-day sales
    const profit30 = parseFloat(opt.dataset.profit30)  || 0;   // real 30-day profit
    const txn30    = parseInt(opt.dataset.txn30)        || 0;   // transactions count
    const qty7     = parseInt(opt.dataset.qty7)        || 0;   // last 7-day sales

    // Show loading
    aiTestBtn.disabled = true;
    aiTestBtn.innerHTML = '<i class="bi bi-arrow-repeat ai-spin"></i> AI Analyzing...';

    setTimeout(() => {
        // ── Core metrics ─────────────────────────────────────
        const margin      = retail > 0 ? ((retail - cost) / retail * 100) : 0;
        const markup      = cost   > 0 ? ((retail - cost) / cost   * 100) : 0;
        const profitRs    = retail - cost;
        const stockStatus = stock <= 0 ? 'danger' : stock <= min ? 'warning' : 'good';
        const revTotal30  = qty30 * retail;
        const avgDailySales = qty30 / 30;
        const stockDays   = avgDailySales > 0 ? Math.round(stock / avgDailySales) : (stock > 0 ? 999 : 0);
        const velocity    = qty7 > qty30/4 ? 'fast' : qty30 > 0 ? 'normal' : 'dead';

        // ── AI Score (0-100) ──────────────────────────────────
        let score = 0;
        // Margin score (0-30)
        score += margin >= 35 ? 30 : margin >= 25 ? 24 : margin >= 15 ? 16 : margin >= 8 ? 8 : 2;
        // Sales velocity (0-25)
        score += qty30 >= 30 ? 25 : qty30 >= 15 ? 20 : qty30 >= 5 ? 13 : qty30 >= 1 ? 7 : 0;
        // Stock health (0-20)
        score += stockStatus === 'good' ? 20 : stockStatus === 'warning' ? 10 : 0;
        // Turnover (0-15)
        score += stockDays < 10 ? 15 : stockDays < 30 ? 11 : stockDays < 60 ? 6 : 2;
        // Profitability 30d (0-10)
        score += profit30 >= 500 ? 10 : profit30 >= 100 ? 7 : profit30 > 0 ? 4 : 0;
        score  = Math.min(100, Math.max(1, score));

        // Stars out of 5
        const rating = score >= 85 ? 5 : score >= 70 ? 4 : score >= 50 ? 3 : score >= 30 ? 2 : 1;
        const stars  = '★'.repeat(rating) + '☆'.repeat(5 - rating);

        // ── Verdict ──────────────────────────────────────────
        let verdict;
        if (score >= 75) {
            const txt = qty30 > 0
                ? `"${name}" is a top-performing product. Sold ${qty30} ${unit} in 30 days with Rs.${profit30.toFixed(0)} net profit. Margin ${margin.toFixed(1)}% is strong. Always keep it in stock and promote it!`
                : `"${name}" has excellent pricing and margin (${margin.toFixed(1)}%). Sales are slow right now — give it a marketing boost to unlock its full potential.`;
            verdict = {bg:'rgba(40,199,111,.1)',bc:'rgba(40,199,111,.3)',tc:'#28c76f',ic:'trophy-fill',title:`Star Product 🏆 — Score ${score}/100`,text:txt};
        } else if (score >= 55) {
            const txt = qty30 > 0
                ? `"${name}" is performing at an average level. Sold ${qty30} ${unit} in 30 days. Margin ${margin.toFixed(1)}% is okay but could be improved. Review pricing or supplier rate.`
                : `"${name}" has a ${margin.toFixed(1)}% margin but no sales in the last 30 days. Improve visibility or shelf placement.`;
            verdict = {bg:'rgba(255,159,67,.08)',bc:'rgba(255,159,67,.25)',tc:'#ff9f43',ic:'exclamation-circle-fill',title:`Good Product ⚠️ — Score ${score}/100`,text:txt};
        } else if (score >= 35) {
            const txt = qty30 > 0
                ? `"${name}" is showing weak performance. Margin is only ${margin.toFixed(1)}%${qty30 < 5?' and sales are very slow too':''}. Consider re-pricing or switching supplier.`
                : `"${name}" has ${stock} ${unit} in stock but zero sales in 30 days. This could become dead stock — consider a discount sale or returning to supplier.`;
            verdict = {bg:'rgba(234,84,85,.08)',bc:'rgba(234,84,85,.25)',tc:'#ea5455',ic:'x-circle-fill',title:`Weak Product ❌ — Score ${score}/100`,text:txt};
        } else {
            const txt = qty30 === 0 && stock > 0
                ? `"${name}" — ${stock} ${unit} in stock, ZERO sales in 30 days! This is locking up your capital. Take action now: apply a discount, bundle it, or return stock to supplier.`
                : `"${name}" performance is critically low. Score: ${score}/100. Margin ${margin.toFixed(1)}%, sales almost zero. Immediate review required.`;
            verdict = {bg:'rgba(234,84,85,.12)',bc:'rgba(234,84,85,.35)',tc:'#ff4455',ic:'radioactive',title:`Critical Risk 🚨 — Score ${score}/100`,text:txt};
        }

        // ── Suggestions ──────────────────────────────────────
        const suggestions = [];
        if (margin < 10)
            suggestions.push({ic:'arrow-up-circle-fill',cl:'#ea5455',
                tx:`Margin is only ${margin.toFixed(1)}% — critically low! Raise retail price to Rs.${(cost/0.85).toFixed(0)} or negotiate a better rate from your supplier. Target minimum 15% margin.`});
        else if (margin < 20)
            suggestions.push({ic:'arrow-up-circle',cl:'#ff9f43',
                tx:`Margin ${margin.toFixed(1)}% — could be improved. Consider pricing at Rs.${(cost/0.80).toFixed(0)} or Rs.${(cost/0.75).toFixed(0)} and check competitor prices.`});
        else if (margin >= 30)
            suggestions.push({ic:'star-fill',cl:'#28c76f',
                tx:`Excellent margin ${margin.toFixed(1)}%! Create bundle deals (e.g. "Buy 2, save 5%") or offer bulk discounts — both revenue and volume will increase.`});

        if (stock <= 0)
            suggestions.push({ic:'box-seam',cl:'#ea5455',
                tx:`⚠️ OUT OF STOCK! Every time a customer asks and it's unavailable, you lose a sale. Reorder today — minimum ${Math.max(10, min*4)} ${unit} recommended.`});
        else if (stock <= min)
            suggestions.push({ic:'exclamation-triangle-fill',cl:'#ff9f43',
                tx:`Stock low — only ${stock} ${unit} remaining (min alert: ${min}). ${qty30 > 0 ? `At current rate, only ${stockDays} days of stock left.` : ''} Reorder soon: ${min*4} ${unit} suggested.`});
        else if (stockDays > 90 && qty30 === 0)
            suggestions.push({ic:'archive',cl:'#ff9f43',
                tx:`${stock} ${unit} sitting idle for 90+ days with zero sales. Dead stock risk! Offer a 15–20% discount or bundle with a related product to clear it.`});

        if (qty30 === 0 && stock > 0)
            suggestions.push({ic:'megaphone-fill',cl:'#6C63FF',
                tx:`Zero sales in 30 days — change the display placement, mark it as "Featured" on the POS screen, or personally recommend it to regular customers.`});
        else if (qty30 > 0 && velocity === 'fast')
            suggestions.push({ic:'rocket-takeoff-fill',cl:'#28c76f',
                tx:`Sales are trending fast! ${qty7} ${unit} sold in the last 7 days. Keep stock full at all times and introduce quantity discounts to maximise momentum.`});
        else if (qty30 >= 10 && txn30 >= 3)
            suggestions.push({ic:'people-fill',cl:'#3ECFCF',
                tx:`${txn30} customers bought this product in 30 days. Display it with a "Popular Pick" badge — social proof will attract even more customers.`});

        if (suggestions.length === 0)
            suggestions.push({ic:'check-circle-fill',cl:'#28c76f',
                tx:`All metrics healthy! Keep stocking "${name}". Consider bulk purchasing in the future to improve the margin further.`});

        // ── Render metrics row ────────────────────────────────
        const metricsEl = document.getElementById('aiMetrics');
        const metricCards = [
            {lbl:'Profit Margin', val:margin.toFixed(1)+'%',   sub:'Per sale',      col:margin>=25?'#28c76f':margin>=12?'#ff9f43':'#ea5455'},
            {lbl:'Net Profit/Unit',val:'Rs.'+profitRs.toFixed(0),sub:'Per '+unit,   col:profitRs>0?'#28c76f':'#ea5455'},
            {lbl:'Sold (30d)',     val:qty30+' '+unit,          sub:txn30+' txn',    col:qty30>=10?'#28c76f':qty30>=3?'#ff9f43':'#ea5455'},
            {lbl:'Stock Left',    val:stock+' '+unit,           sub:stockDays<999?stockDays+'d stock':'In stock', col:stockStatus==='good'?'#28c76f':stockStatus==='warning'?'#ff9f43':'#ea5455'},
            {lbl:'30d Revenue',   val:'Rs.'+(revTotal30>=1000?(revTotal30/1000).toFixed(1)+'K':revTotal30.toFixed(0)), sub:'Gross', col:revTotal30>=5000?'#28c76f':revTotal30>=500?'#ff9f43':'#ea5455'},
            {lbl:'AI Score',      val:score+'/100',             sub:'★'.repeat(rating)+'☆'.repeat(5-rating), col:score>=75?'#28c76f':score>=50?'#ff9f43':'#ea5455'},
        ];
        metricsEl.innerHTML = metricCards.map(m => `
            <div style="flex:1;min-width:110px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:.6rem .85rem;">
                <div style="font-size:.63rem;color:rgba(255,255,255,.38);text-transform:uppercase;letter-spacing:.6px;margin-bottom:3px;">${m.lbl}</div>
                <div style="font-size:1.05rem;font-weight:800;color:${m.col};">${m.val}</div>
                <div style="font-size:.62rem;color:rgba(255,255,255,.28);">${m.sub}</div>
            </div>
        `).join('');

        // ── Render verdict ────────────────────────────────────
        const verdictEl = document.getElementById('aiVerdict');
        verdictEl.style.cssText = `display:block;background:${verdict.bg};border:1px solid ${verdict.bc};border-radius:11px;padding:.9rem 1rem;`;
        verdictEl.innerHTML = `
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <i class="bi bi-${verdict.ic}" style="color:${verdict.tc};font-size:1.05rem;"></i>
                <span style="color:${verdict.tc};font-weight:800;font-size:.9rem;">${verdict.title}</span>
                <span style="margin-left:auto;font-size:1rem;color:#ff9f43;" title="${rating}/5 stars">${stars}</span>
            </div>
            <p style="color:rgba(255,255,255,.65);font-size:.8rem;margin:0;line-height:1.6;">${verdict.text}</p>
        `;

        // ── Render suggestions ────────────────────────────────
        const suggEl = document.getElementById('aiSuggestionsList');
        suggEl.innerHTML = suggestions.map((s,i) => `
            <div style="display:flex;align-items:flex-start;gap:.65rem;margin-bottom:.48rem;
                        background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);
                        border-radius:9px;padding:.6rem .85rem;">
                <div style="width:22px;height:22px;border-radius:6px;background:${s.cl}22;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
                    <i class="bi bi-${s.ic}" style="color:${s.cl};font-size:.72rem;"></i>
                </div>
                <span style="font-size:.78rem;color:rgba(255,255,255,.62);line-height:1.55;">${s.tx}</span>
            </div>
        `).join('');
        document.getElementById('aiSuggestions').style.display = '';

        // Show result, hide empty state
        document.getElementById('aiResultBox').style.display  = '';
        document.getElementById('aiEmptyState').style.display = 'none';

        // Reset button
        aiTestBtn.disabled = false;
        aiTestBtn.innerHTML = '<i class="bi bi-robot"></i> Test Again';
    }, 800);
}
</script>

<?php shopFooter(); ?>

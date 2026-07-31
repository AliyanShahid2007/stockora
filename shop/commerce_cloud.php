<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';
$shopId   = (int)$_SESSION['shop_id'];
requirePremiumFeature($shopId, 'Commerce Cloud');
$shopName = $_SESSION['shop_name'] ?? 'My Shop';
$pageTitle = 'Commerce Cloud';

// ── Load real store settings from DB ──────────────────────────────────────
$db = getDB();
$db && trackShopFeatureUsage($shopId, 'commerce_cloud');
$settingsRows = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE shop_id=? AND setting_key LIKE 'store_%'");
$settingsRows->execute([$shopId]);
$storeSettings = [];
foreach ($settingsRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $storeSettings[$row['setting_key']] = $row['setting_value'];
}

$storeActive  = !empty($storeSettings['store_launched']) && $storeSettings['store_launched'] === '1';
$storeTheme   = $storeSettings['store_theme']    ?? 'Tech Pro';
$storeName    = $storeSettings['store_name']     ?? $shopName;
$storeLaunchedAt = $storeSettings['store_launched_at'] ?? '';

// Build real in-app store URL using saved slug (or fall back to name-based slug)
$storeSlug = $storeSettings['store_slug'] ?? strtolower(preg_replace('/[^a-z0-9-]/', '', str_replace(' ', '-', strtolower($storeName))));
$storeSlug = $storeSlug ?: 'shop-' . $shopId;
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
// If DB has a stored URL use it, otherwise generate from slug
$storeUrl  = $storeSettings['store_url'] ?? (BASE_URL . '/store.php?s=' . urlencode($storeSlug));
// Rebuild with current host so it always points to running server
if ($storeActive && $storeSlug) {
    $storeUrl = BASE_URL . '/store.php?s=' . urlencode($storeSlug);
}
$storeDomain = preg_replace('#^https?://#', '', BASE_URL) . '/store.php?s=' . urlencode($storeSlug);

$totalOrders  = 0;
$totalRevenue = 0;
$publishedProducts = 0;

shopHeader($pageTitle, 'commerce_cloud');
?>

<style>
/* ══════════════════════════════════════════════
   STOCKORA COMMERCE CLOUD — Dashboard v1.0
   Deep teal dark theme with commerce accents
   ══════════════════════════════════════════════ */
:root {
  --cc-purple: #7C3AED;
  --cc-violet: #8B5CF6;
  --cc-teal:   #06B6D4;
  --cc-cyan:   #22D3EE;
  --cc-gold:   #F59E0B;
  --cc-green:  #10B981;
  --cc-pink:   #EC4899;
  --cc-glass:  #0d1526;
  --cc-border: rgba(14,206,206,.12);
}

/* ── Page Header ── */
.cc-header {
  background: linear-gradient(135deg, rgba(124,58,237,.25), rgba(6,182,212,.15));
  border: 1px solid rgba(14,206,206,.15);
  border-radius: 20px;
  padding: 2rem 2.25rem;
  margin-bottom: 2rem;
  position: relative;
  overflow: hidden;
}
.cc-header::before {
  content: '';
  position: absolute; top: -60px; right: -60px;
  width: 220px; height: 220px;
  background: radial-gradient(circle, rgba(124,58,237,.18), transparent 65%);
  pointer-events: none;
}
.cc-header::after {
  content: '';
  position: absolute; bottom: -40px; left: 20%;
  width: 160px; height: 160px;
  background: radial-gradient(circle, rgba(6,182,212,.12), transparent 65%);
  pointer-events: none;
}
.cc-badge {
  display: inline-flex; align-items: center; gap: .4rem;
  background: linear-gradient(135deg, rgba(124,58,237,.2), rgba(6,182,212,.15));
  border: 1px solid rgba(167,139,250,.3);
  border-radius: 30px; padding: .28rem .85rem;
  font-size: .7rem; font-weight: 700; color: #A78BFA;
  letter-spacing: .5px; text-transform: uppercase;
  margin-bottom: .65rem;
}
.cc-page-title {
  font-size: 1.75rem; font-weight: 900;
  color: #fff; letter-spacing: -.8px; line-height: 1.15;
}
.cc-page-title .grad {
  background: linear-gradient(135deg, #A78BFA, #22D3EE);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.cc-page-sub {
  font-size: .88rem; color: var(--text2); line-height: 1.6; margin-top: .4rem;
}

/* ── Store Status Banner ── */
.store-status-banner {
  border-radius: 16px; padding: 1.5rem 1.75rem;
  display: flex; align-items: center; justify-content: space-between;
  gap: 1rem; flex-wrap: wrap;
  margin-bottom: 1.75rem;
}
.ssb-offline {
  background: linear-gradient(135deg, rgba(239,68,68,.1), rgba(245,158,11,.07));
  border: 1px solid rgba(239,68,68,.25);
}
.ssb-online {
  background: linear-gradient(135deg, rgba(16,185,129,.1), rgba(6,182,212,.13));
  border: 1px solid rgba(16,185,129,.25);
}
.ssb-dot {
  width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: .45rem;
}
.ssb-dot.offline { background: #EF4444; box-shadow: 0 0 8px rgba(239,68,68,.6); }
.ssb-dot.online  { background: #10B981; box-shadow: 0 0 8px rgba(16,185,129,.6); animation: pulseDot 2s ease-in-out infinite; }
@keyframes pulseDot { 0%,100%{opacity:1} 50%{opacity:.5} }

/* ── Commerce Cards ── */
.cc-metric {
  background: var(--cc-glass);
  border: 1px solid var(--cc-border);
  border-radius: 16px; padding: 1.4rem 1.5rem;
  transition: all .3s;
}
.cc-metric:hover {
  border-color: rgba(14,206,206,.25);
  transform: translateY(-3px);
  box-shadow: 0 12px 32px rgba(0,0,0,.25);
}
.cc-metric-icon {
  width: 44px; height: 44px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.15rem; margin-bottom: 1rem;
}
.cc-metric-num {
  font-size: 1.8rem; font-weight: 900; color: #fff; line-height: 1;
  letter-spacing: -.5px;
}
.cc-metric-lbl {
  font-size: .75rem; color: var(--text2); margin-top: .3rem; font-weight: 500;
}
.cc-metric-change {
  font-size: .72rem; font-weight: 700; margin-top: .4rem;
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .15rem .5rem; border-radius: 20px;
}
.cc-metric-change.up   { color: #34D399; background: rgba(16,185,129,.12); }
.cc-metric-change.zero { color: var(--text2); background: #111f35; }

/* ── Feature Cards Grid ── */
.cc-features-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1.1rem;
}
.cc-feat {
  background: var(--cc-glass);
  border: 1px solid var(--cc-border);
  border-radius: 16px; padding: 1.4rem;
  transition: all .3s; cursor: pointer; text-decoration: none;
  display: block; position: relative; overflow: hidden;
}
.cc-feat::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: var(--cc-feat-color, linear-gradient(90deg, var(--cc-purple), var(--cc-teal)));
  opacity: 0; transition: opacity .3s;
}
.cc-feat:hover { border-color: rgba(14,206,206,.28); transform: translateY(-4px); box-shadow: 0 14px 40px rgba(0,0,0,.3); }
.cc-feat:hover::before { opacity: 1; }
.cc-feat-icon {
  width: 46px; height: 46px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem; margin-bottom: 1rem;
}
.cc-feat-title { font-size: .95rem; font-weight: 800; color: #fff; margin-bottom: .4rem; }
.cc-feat-desc  { font-size: .8rem; color: var(--text2); line-height: 1.55; }
.cc-feat-arrow {
  position: absolute; top: 1.2rem; right: 1.2rem;
  color: rgba(255,255,255,.2); font-size: .85rem; transition: all .25s;
}
.cc-feat:hover .cc-feat-arrow { color: var(--cc-teal); transform: translateX(3px); }
.cc-feat-badge {
  display: inline-block; margin-top: .85rem;
  font-size: .63rem; font-weight: 700; letter-spacing: .4px;
  padding: .18rem .55rem; border-radius: 20px;
}

/* ── Quick Launch CTA ── */
.launch-cta {
  background: linear-gradient(135deg, rgba(124,58,237,.18), rgba(6,182,212,.1));
  border: 1px solid rgba(167,139,250,.25);
  border-radius: 20px; padding: 2.5rem;
  text-align: center; position: relative; overflow: hidden;
}
.launch-cta::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse 80% 60% at 50% 0%, rgba(124,58,237,.15), transparent 60%);
  pointer-events: none;
}
.launch-btn {
  display: inline-flex; align-items: center; gap: .6rem;
  background: linear-gradient(135deg, var(--cc-purple), var(--cc-violet));
  color: #fff; font-size: .95rem; font-weight: 800;
  padding: .9rem 2.2rem; border-radius: 13px; text-decoration: none;
  box-shadow: 0 6px 28px rgba(124,58,237,.45);
  transition: all .3s; border: none; cursor: pointer;
}
.launch-btn:hover { transform: translateY(-3px); box-shadow: 0 10px 36px rgba(124,58,237,.6); color: #fff; }

/* ── AI Recommendations ── */
.ai-rec-card {
  background: var(--cc-glass);
  border: 1px solid rgba(236,72,153,.2);
  border-radius: 14px; padding: 1.1rem 1.25rem;
  margin-bottom: .75rem;
  display: flex; align-items: flex-start; gap: .9rem;
  transition: all .25s;
}
.ai-rec-card:hover { border-color: rgba(236,72,153,.35); background: rgba(236,72,153,.1); }
.ai-rec-icon {
  width: 38px; height: 38px; border-radius: 10px;
  background: rgba(236,72,153,.15);
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; color: #F472B6; flex-shrink: 0;
}
.ai-rec-title  { font-size: .85rem; font-weight: 700; color: #fff; margin-bottom: .2rem; }
.ai-rec-reason { font-size: .76rem; color: var(--text2); line-height: 1.5; }
.ai-rec-action {
  font-size: .72rem; font-weight: 700; color: #A78BFA;
  display: inline-flex; align-items: center; gap: .25rem;
  margin-top: .4rem; cursor: pointer;
  background: none; border: none; padding: 0;
}
.ai-rec-action:hover { color: #C4B5FD; }

/* ── Steps progress ── */
.steps-row {
  display: flex; gap: .75rem; overflow-x: auto;
  padding-bottom: .25rem;
}
.step-pill {
  flex: 1; min-width: 140px;
  background: var(--cc-glass);
  border: 1px solid var(--cc-border);
  border-radius: 12px; padding: .9rem 1rem;
  text-align: center; transition: all .25s;
}
.step-pill.done    { border-color: rgba(16,185,129,.3); background: rgba(16,185,129,.07); }
.step-pill.current { border-color: rgba(124,58,237,.4); background: rgba(124,58,237,.1); }
.step-num {
  width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .75rem; font-weight: 800; margin: 0 auto .5rem;
  background: #111e34; color: var(--text2);
}
.step-pill.done .step-num    { background: rgba(16,185,129,.2); color: #34D399; }
.step-pill.current .step-num { background: rgba(124,58,237,.25); color: #A78BFA; }
.step-lbl  { font-size: .72rem; font-weight: 600; color: var(--text2); }
.step-pill.done .step-lbl    { color: #34D399; }
.step-pill.current .step-lbl { color: #A78BFA; }

/* ── Section heading ── */
.cc-section-title {
  font-size: 1rem; font-weight: 800; color: #fff;
  display: flex; align-items: center; gap: .5rem;
  margin-bottom: 1.1rem;
}
.cc-section-title i { color: var(--cc-teal); }

/* ── Responsive ── */
@media(max-width: 768px) {
  .cc-header         { padding: 1.4rem 1.25rem; }
  .cc-page-title     { font-size: 1.4rem; }
  .cc-features-grid  { grid-template-columns: 1fr; }
  .launch-cta        { padding: 1.75rem 1.25rem; }
  .store-status-banner { flex-direction: column; align-items: flex-start; }
  .steps-row         { gap: .5rem; }
  .step-pill         { min-width: 120px; }
}
@media(max-width: 576px) {
  .cc-page-title     { font-size: 1.25rem; }
  .cc-header         { padding: 1.1rem 1rem; }
  .cc-features-grid  { gap: .85rem; }
}
</style>

<div class="container-fluid px-3 px-md-4">

  <!-- ══ PAGE HEADER ══ -->
  <div class="cc-header">
    <div style="position:relative;z-index:1">
      <div class="cc-badge"><i class="bi bi-cloud-fill"></i> Commerce Cloud</div>
      <h1 class="cc-page-title">Stockora <span class="grad">Commerce Cloud</span></h1>
      <p class="cc-page-sub">Launch your ecommerce store, sync inventory in real-time, and manage online orders — all from one platform.</p>
    </div>
    <div style="position:relative;z-index:1;flex-shrink:0">
      <?php if ($storeActive): ?>
      <a href="<?= htmlspecialchars($storeUrl) ?>" target="_blank" class="launch-btn" style="background:linear-gradient(135deg,#10B981,#34D399)">
        <i class="bi bi-box-arrow-up-right"></i> View Live Store
      </a>
      <?php else: ?>
      <a href="<?= BASE_URL ?>/shop/store_wizard.php" class="launch-btn">
        <i class="bi bi-rocket-takeoff-fill"></i> Launch Your Store
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ STORE STATUS BANNER ══ -->
  <?php if (!$storeActive): ?>
  <div class="store-status-banner ssb-offline">
    <div>
      <div style="font-size:.8rem;font-weight:700;color:rgba(239,68,68,.9);margin-bottom:.3rem">
        <span class="ssb-dot offline"></span> Store Not Launched
      </div>
      <div style="font-size:.83rem;color:var(--text2)">Your ecommerce store is ready to go live. Complete the 4-step setup wizard to launch.</div>
    </div>
    <a href="<?= BASE_URL ?>/shop/store_wizard.php" class="btn btn-sm" style="background:linear-gradient(135deg,#7C3AED,#8B5CF6);color:#fff;border:none;font-size:.8rem;font-weight:700;padding:.5rem 1.1rem;border-radius:9px;white-space:nowrap">
      <i class="bi bi-play-fill me-1"></i> Start Setup Wizard
    </a>
  </div>
  <?php else: ?>
  <div class="store-status-banner ssb-online">
    <div style="min-width:0;flex:1">
      <div style="font-size:.8rem;font-weight:700;color:#34D399;margin-bottom:.3rem">
        <span class="ssb-dot online"></span> Store is Live
        <?php if ($storeLaunchedAt): ?><span style="font-size:.7rem;color:var(--text2);font-weight:500;margin-left:.5rem">· since <?= date('d M Y', strtotime($storeLaunchedAt)) ?></span><?php endif; ?>
      </div>
      <div style="font-size:.83rem;color:var(--text2);display:flex;align-items:center;gap:.35rem;flex-wrap:wrap">
        <i class="bi bi-link-45deg" style="color:#22D3EE"></i>
        <a href="<?= htmlspecialchars($storeUrl) ?>" target="_blank" style="color:var(--cc-teal);text-decoration:none;word-break:break-all"><?= htmlspecialchars($storeDomain) ?></a>
        <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($storeUrl) ?>').then(function(){showToast('Store URL copied!','success')})" style="background:none;border:none;color:#A78BFA;cursor:pointer;font-size:.75rem;padding:0" title="Copy URL"><i class="bi bi-clipboard"></i></button>
      </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
      <a href="<?= htmlspecialchars($storeUrl) ?>" target="_blank" class="btn btn-sm" style="background:rgba(16,185,129,.15);color:#34D399;border:1px solid rgba(16,185,129,.3);font-size:.78rem">
        <i class="bi bi-box-arrow-up-right me-1"></i> View Store
      </a>
      <a href="<?= BASE_URL ?>/shop/store_customize.php" class="btn btn-sm" style="background:linear-gradient(135deg,rgba(99,102,241,.25),rgba(139,92,246,.2));color:#a5b4fc;border:1px solid rgba(99,102,241,.35);font-size:.78rem;font-weight:700">
        <i class="bi bi-palette-fill me-1"></i> Customize
      </a>
      <a href="<?= BASE_URL ?>/shop/store_wizard.php" class="btn btn-sm" style="background:rgba(255,255,255,.07);color:var(--text2);border:1px solid rgba(255,255,255,.1);font-size:.78rem">
        <i class="bi bi-gear me-1"></i> Edit Settings
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ SETUP PROGRESS ══ -->
  <div class="mb-4">
    <div class="cc-section-title"><i class="bi bi-ui-checks-grid"></i> Store Setup Progress</div>
    <div class="steps-row">
      <div class="step-pill done">
        <div class="step-num"><i class="bi bi-check-lg"></i></div>
        <div class="step-lbl">Account Created</div>
      </div>
      <div class="step-pill <?= $storeActive ? 'done' : 'current' ?>">
        <div class="step-num"><?= $storeActive ? '<i class="bi bi-check-lg"></i>' : '2' ?></div>
        <div class="step-lbl">Business Info</div>
      </div>
      <div class="step-pill <?= $storeActive ? 'done' : '' ?>">
        <div class="step-num"><?= $storeActive ? '<i class="bi bi-check-lg"></i>' : '3' ?></div>
        <div class="step-lbl">Choose Theme</div>
      </div>
      <div class="step-pill <?= $storeActive ? 'done' : '' ?>">
        <div class="step-num"><?= $storeActive ? '<i class="bi bi-check-lg"></i>' : '4' ?></div>
        <div class="step-lbl">Connect Domain</div>
      </div>
      <div class="step-pill <?= $storeActive ? 'done' : '' ?>">
        <div class="step-num"><?= $storeActive ? '<i class="bi bi-check-lg"></i>' : '5' ?></div>
        <div class="step-lbl">Launch Store</div>
      </div>
    </div>
  </div>

  <!-- ══ COMMERCE METRICS ══ -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="cc-metric">
        <div class="cc-metric-icon" style="background:rgba(124,58,237,.18)"><i class="bi bi-bag-check-fill" style="color:#A78BFA"></i></div>
        <div class="cc-metric-num"><?= number_format($totalOrders) ?></div>
        <div class="cc-metric-lbl">Online Orders</div>
        <div class="cc-metric-change zero"><i class="bi bi-dash"></i> <?= $storeActive ? 'No orders yet' : 'Not launched yet' ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="cc-metric">
        <div class="cc-metric-icon" style="background:rgba(16,185,129,.15)"><i class="bi bi-currency-exchange" style="color:#34D399"></i></div>
        <div class="cc-metric-num">Rs.<?= number_format($totalRevenue) ?></div>
        <div class="cc-metric-lbl">Online Revenue</div>
        <div class="cc-metric-change zero"><i class="bi bi-dash"></i> <?= $storeActive ? 'No sales yet' : 'No data yet' ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="cc-metric">
        <div class="cc-metric-icon" style="background:rgba(6,182,212,.15)"><i class="bi bi-boxes" style="color:#22D3EE"></i></div>
        <div class="cc-metric-num"><?= $publishedProducts ?></div>
        <div class="cc-metric-lbl">Published Products</div>
        <div class="cc-metric-change zero"><i class="bi bi-dash"></i> 0 of 0 online</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="cc-metric">
        <div class="cc-metric-icon" style="background:rgba(245,158,11,.15)"><i class="bi bi-eye-fill" style="color:#FCD34D"></i></div>
        <div class="cc-metric-num">0</div>
        <div class="cc-metric-lbl">Store Visitors</div>
        <div class="cc-metric-change zero"><i class="bi bi-dash"></i> <?= $storeActive ? 'Analytics soon' : 'Store offline' ?></div>
      </div>
    </div>
  </div>

  <div class="row g-4">

    <!-- ══ LEFT COLUMN: Feature Modules ══ -->
    <div class="col-lg-8">
      <div class="cc-section-title mb-3"><i class="bi bi-grid-3x3-gap-fill"></i> Commerce Modules</div>
      <div class="cc-features-grid">

        <!-- Store Wizard -->
        <a href="<?= BASE_URL ?>/shop/store_wizard.php" class="cc-feat" style="--cc-feat-color:linear-gradient(90deg,#7C3AED,#A78BFA)">
          <i class="bi bi-chevron-right cc-feat-arrow"></i>
          <div class="cc-feat-icon" style="background:rgba(124,58,237,.18)"><i class="bi bi-rocket-takeoff-fill" style="color:#A78BFA"></i></div>
          <div class="cc-feat-title">Store Launch Wizard</div>
          <div class="cc-feat-desc">4-step guided setup to launch your professional ecommerce store in minutes. Business info → Theme → Domain → Live.</div>
          <span class="cc-feat-badge" style="background:rgba(124,58,237,.15);color:#A78BFA;border:1px solid rgba(167,139,250,.2)">Required First</span>
        </a>

        <!-- Theme Marketplace -->
        <a href="<?= BASE_URL ?>/shop/theme_marketplace.php" class="cc-feat" style="--cc-feat-color:linear-gradient(90deg,#EC4899,#F472B6)">
          <i class="bi bi-chevron-right cc-feat-arrow"></i>
          <div class="cc-feat-icon" style="background:rgba(236,72,153,.15)"><i class="bi bi-palette-fill" style="color:#F472B6"></i></div>
          <div class="cc-feat-title">Theme Marketplace</div>
          <div class="cc-feat-desc">Choose from 20 enterprise-grade premium themes across 8 categories. AI recommends the best theme for your business.</div>
          <span class="cc-feat-badge" style="background:rgba(236,72,153,.12);color:#F472B6;border:1px solid rgba(244,114,182,.2)">20 Themes</span>
        </a>

        <!-- Online Orders -->
        <a href="<?= BASE_URL ?>/shop/online_orders.php" class="cc-feat" style="--cc-feat-color:linear-gradient(90deg,#06B6D4,#22D3EE)">
          <i class="bi bi-chevron-right cc-feat-arrow"></i>
          <div class="cc-feat-icon" style="background:rgba(6,182,212,.15)"><i class="bi bi-bag-check-fill" style="color:#22D3EE"></i></div>
          <div class="cc-feat-title">Order Management</div>
          <div class="cc-feat-desc">All online orders land directly in your Stockora dashboard. Track status, process fulfillment, generate invoices.</div>
          <span class="cc-feat-badge" style="background:rgba(6,182,212,.1);color:#22D3EE;border:1px solid rgba(34,211,238,.2)">Real-time</span>
        </a>

        <!-- Inventory Sync -->
        <div class="cc-feat" style="--cc-feat-color:linear-gradient(90deg,#10B981,#34D399);cursor:default">
          <i class="bi bi-chevron-right cc-feat-arrow"></i>
          <div class="cc-feat-icon" style="background:rgba(16,185,129,.15)"><i class="bi bi-arrow-repeat" style="color:#34D399"></i></div>
          <div class="cc-feat-title">Real-time Inventory Sync</div>
          <div class="cc-feat-desc">Every product update in Stockora instantly reflects on your store. One product record — everywhere in sync.</div>
          <span class="cc-feat-badge" style="background:rgba(16,185,129,.1);color:#34D399;border:1px solid rgba(52,211,153,.2)">Auto Sync</span>
        </div>

        <!-- Domain Connection -->
        <div class="cc-feat" style="--cc-feat-color:linear-gradient(90deg,#F59E0B,#FCD34D);cursor:default">
          <i class="bi bi-chevron-right cc-feat-arrow"></i>
          <div class="cc-feat-icon" style="background:rgba(245,158,11,.15)"><i class="bi bi-globe" style="color:#FCD34D"></i></div>
          <div class="cc-feat-title">Domain Connection</div>
          <div class="cc-feat-desc">Connect your custom domain (www.mybusiness.com) or use a free Stockora subdomain. No hosting needed.</div>
          <span class="cc-feat-badge" style="background:rgba(245,158,11,.1);color:#FCD34D;border:1px solid rgba(252,211,77,.2)">SSL Included</span>
        </div>

        <!-- AI Commerce Intelligence -->
        <div class="cc-feat" style="--cc-feat-color:linear-gradient(90deg,#EF4444,#F87171);cursor:default">
          <i class="bi bi-chevron-right cc-feat-arrow"></i>
          <div class="cc-feat-icon" style="background:rgba(239,68,68,.15)"><i class="bi bi-robot" style="color:#F87171"></i></div>
          <div class="cc-feat-title">AI Commerce Intelligence</div>
          <div class="cc-feat-desc">AI continuously analyzes your inventory, sales & behavior to suggest what to publish, promote, and price for maximum revenue.</div>
          <span class="cc-feat-badge" style="background:rgba(239,68,68,.1);color:#F87171;border:1px solid rgba(248,113,113,.2)">AI Powered</span>
        </div>

      </div><!-- /cc-features-grid -->
    </div>

    <!-- ══ RIGHT COLUMN: AI Recommendations + Quick Actions ══ -->
    <div class="col-lg-4">

      <!-- AI Commerce Intelligence Panel -->
      <div class="mb-4">
        <div class="cc-section-title"><i class="bi bi-robot"></i> AI Commerce Intelligence</div>
        <div style="background:rgba(236,72,153,.18);border:1px solid rgba(236,72,153,.15);border-radius:14px;padding:1rem 1.15rem;margin-bottom:1rem">
          <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem">
            <div style="width:8px;height:8px;border-radius:50%;background:#F472B6;animation:pulseDot 2s ease-in-out infinite"></div>
            <span style="font-size:.72rem;font-weight:700;color:#F472B6;letter-spacing:.5px;text-transform:uppercase">AI Analysis Active</span>
          </div>
          <div style="font-size:.78rem;color:var(--text2);line-height:1.55">AI is analyzing your inventory of <strong style="color:#fff">0 products</strong> to generate commerce recommendations. Launch your store to unlock full AI intelligence.</div>
        </div>

        <div class="ai-rec-card">
          <div class="ai-rec-icon"><i class="bi bi-shop"></i></div>
          <div>
            <div class="ai-rec-title">Launch Your Store First</div>
            <div class="ai-rec-reason">Complete the 4-step wizard to go live. Once launched, AI will analyze sales patterns and suggest which products to feature online.</div>
            <button class="ai-rec-action" onclick="window.location='<?= BASE_URL ?>/shop/store_wizard.php'">
              <i class="bi bi-arrow-right-circle-fill"></i> Start Setup Wizard
            </button>
          </div>
        </div>

        <div class="ai-rec-card">
          <div class="ai-rec-icon"><i class="bi bi-palette2"></i></div>
          <div>
            <div class="ai-rec-title">Pick Your Perfect Theme</div>
            <div class="ai-rec-reason">AI will analyze your product category and recommend the highest-converting theme for your business type.</div>
            <button class="ai-rec-action" onclick="window.location='<?= BASE_URL ?>/shop/theme_marketplace.php'">
              <i class="bi bi-arrow-right-circle-fill"></i> Browse 20 Themes
            </button>
          </div>
        </div>

        <div class="ai-rec-card">
          <div class="ai-rec-icon"><i class="bi bi-boxes"></i></div>
          <div>
            <div class="ai-rec-title">Publish Your Inventory</div>
            <div class="ai-rec-reason">After launch, publish your best-selling products online. AI will continuously optimize which products to show first.</div>
            <button class="ai-rec-action" onclick="window.location='<?= BASE_URL ?>/shop/inventory.php'">
              <i class="bi bi-arrow-right-circle-fill"></i> View Inventory
            </button>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="mb-4">
        <div class="cc-section-title"><i class="bi bi-lightning-charge-fill"></i> Quick Actions</div>
        <div class="d-grid gap-2">
          <a href="<?= BASE_URL ?>/shop/store_wizard.php" class="btn" style="background:linear-gradient(135deg,#7C3AED,#8B5CF6);color:#fff;font-size:.82rem;font-weight:700;padding:.65rem;border-radius:10px;text-align:left;display:flex;align-items:center;gap:.6rem">
            <i class="bi bi-rocket-takeoff-fill"></i> Launch Store Wizard
          </a>
          <a href="<?= BASE_URL ?>/shop/theme_marketplace.php" class="btn" style="background:rgba(236,72,153,.12);color:#F472B6;border:1px solid rgba(236,72,153,.25);font-size:.82rem;font-weight:700;padding:.65rem;border-radius:10px;text-align:left;display:flex;align-items:center;gap:.6rem">
            <i class="bi bi-palette-fill"></i> Browse Themes
          </a>
          <a href="<?= BASE_URL ?>/shop/online_orders.php" class="btn" style="background:rgba(6,182,212,.1);color:#22D3EE;border:1px solid rgba(6,182,212,.2);font-size:.82rem;font-weight:700;padding:.65rem;border-radius:10px;text-align:left;display:flex;align-items:center;gap:.6rem">
            <i class="bi bi-bag-check-fill"></i> View Online Orders
          </a>
          <a href="<?= BASE_URL ?>/shop/inventory.php" class="btn" style="background:#0f1a2e;color:var(--text2);border:1px solid rgba(14,206,206,.14);font-size:.82rem;font-weight:700;padding:.65rem;border-radius:10px;text-align:left;display:flex;align-items:center;gap:.6rem">
            <i class="bi bi-box-seam"></i> Manage Products
          </a>
        </div>
      </div>

      <!-- Platform Advantages -->
      <div>
        <div class="cc-section-title"><i class="bi bi-award-fill"></i> Why Commerce Cloud</div>
        <div style="background:var(--cc-glass);border:1px solid var(--cc-border);border-radius:14px;padding:1.1rem 1.25rem">
          <?php
          $advantages = [
            ['bi-x-circle-fill','#EF4444','No separate hosting needed'],
            ['bi-x-circle-fill','#EF4444','No developer required'],
            ['bi-x-circle-fill','#EF4444','No third-party platform fees'],
            ['bi-check-circle-fill','#34D399','Real-time inventory sync'],
            ['bi-check-circle-fill','#34D399','Custom or Stockora domain'],
            ['bi-check-circle-fill','#34D399','Online orders in dashboard'],
            ['bi-check-circle-fill','#34D399','AI-powered recommendations'],
          ];
          foreach($advantages as [$icon,$color,$label]):
          ?>
          <div style="display:flex;align-items:center;gap:.6rem;font-size:.8rem;color:rgba(255,255,255,.7);padding:.35rem 0;border-bottom:1px solid rgba(14,206,206,.1)">
            <i class="bi <?= $icon ?>" style="color:<?= $color ?>;flex-shrink:0"></i>
            <?= $label ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- /right col -->
  </div><!-- /row -->

  <!-- ══ CTA: Launch Store ══ -->
  <?php if (!$storeActive): ?>
  <div class="launch-cta mt-4">
    <div style="position:relative;z-index:1;text-align:center">
      <div style="font-size:2.5rem;margin-bottom:.75rem">🚀</div>
      <h3 style="font-size:1.5rem;font-weight:900;color:#fff;letter-spacing:-.6px;margin-bottom:.6rem">Your Online Store Awaits</h3>
      <p style="font-size:.9rem;color:var(--text2);max-width:480px;margin:0 auto 1.5rem;line-height:1.65">
        Turn your Stockora inventory into a live ecommerce store in under 5 minutes. No coding. No hosting. No hassle.
      </p>
      <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/shop/store_wizard.php" class="launch-btn">
          <i class="bi bi-rocket-takeoff-fill"></i> Start 4-Step Setup Wizard
        </a>
        <a href="<?= BASE_URL ?>/shop/theme_marketplace.php" class="btn" style="background:rgba(255,255,255,.08);color:rgba(255,255,255,.8);border:1px solid rgba(255,255,255,.15);font-size:.88rem;font-weight:700;padding:.85rem 1.8rem;border-radius:13px;display:inline-flex;align-items:center;gap:.5rem">
          <i class="bi bi-palette-fill"></i> Preview Themes First
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<?php shopFooter(); ?>

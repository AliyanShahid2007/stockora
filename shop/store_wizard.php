<?php
require_once '../includes/functions.php';
requireShop();
requirePremiumFeature((int)$_SESSION['shop_id'], 'Commerce Cloud');
require_once '../includes/shop_layout.php';
$shopId   = (int)$_SESSION['shop_id'];
$shopName = $_SESSION['shop_name'] ?? 'My Shop';
$pageTitle = 'Store Launch Wizard';

// Load saved store settings from DB
$db = getDB();
$savedSettings = [];
$ssRows = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE shop_id=? AND setting_key LIKE 'store_%'");
$ssRows->execute([$shopId]);
foreach ($ssRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $savedSettings[$r['setting_key']] = $r['setting_value'];
}
$savedTheme    = $savedSettings['store_theme']    ?? '';
$savedThemeId  = $savedSettings['store_theme_id'] ?? '';
$alreadyLaunched = !empty($savedSettings['store_launched']) && $savedSettings['store_launched'] === '1';

// Handle AJAX form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wizard_action'])) {
    ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['wizard_action'];

    if ($action === 'launch_store') {
        $db = getDB();

        // Collect wizard data
        $storeName    = sanitize($_POST['store_name']    ?? ($shopName));
        $category     = sanitize($_POST['category']      ?? 'general');
        $description  = sanitize($_POST['description']   ?? '');
        $phone        = sanitize($_POST['phone']         ?? '');
        $whatsapp     = sanitize($_POST['whatsapp']      ?? '');
        $city         = sanitize($_POST['city']          ?? '');
        $theme        = sanitize($_POST['theme']         ?? 'Tech Pro');
        $domainType   = sanitize($_POST['domain_type']   ?? 'stockora');
        $customDomain = sanitize($_POST['custom_domain'] ?? '');
        $facebook     = sanitize($_POST['facebook']      ?? '');
        $instagram    = sanitize($_POST['instagram']     ?? '');

        // Build store URL — points to real in-app store page /store.php?s={slug}
        if ($domainType === 'custom' && $customDomain) {
            $storeUrl = 'https://' . ltrim($customDomain, 'https://');
        } else {
            $slug = strtolower(preg_replace('/[^a-z0-9-]/', '', str_replace(' ', '-', strtolower($storeName))));
            $slug = $slug ?: 'shop-' . $shopId;
            // Save slug separately for the /store.php route
            $storeUrl = BASE_URL . '/store.php?s=' . urlencode($slug);
        }
        $upsertSlug = strtolower(preg_replace('/[^a-z0-9-]/', '', str_replace(' ', '-', strtolower($storeName))));
        $upsertSlug = $upsertSlug ?: 'shop-' . $shopId;

        // Helper: upsert a setting
        $upsert = function($key, $value) use ($db, $shopId) {
            $existing = $db->prepare("SELECT id FROM settings WHERE shop_id=? AND setting_key=?")->execute([$shopId, $key]);
            $row = $db->prepare("SELECT id FROM settings WHERE shop_id=? AND setting_key=?")->execute([$shopId, $key]) ? null : null;
            // Use INSERT ... ON DUPLICATE KEY or just delete+insert for reliability
            $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key=?")->execute([$shopId, $key]);
            $db->prepare("INSERT INTO settings (shop_id, setting_key, setting_value, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())")->execute([$shopId, $key, $value]);
        };

        $upsert('store_launched',     '1');
        $upsert('store_url',          $storeUrl);
        $upsert('store_slug',         $upsertSlug);
        $upsert('store_name',         $storeName);
        $upsert('store_theme',        $theme);
        $upsert('store_category',     $category);
        $upsert('store_description',  $description);
        $upsert('store_phone',        $phone);
        $upsert('store_whatsapp',     $whatsapp);
        $upsert('store_city',         $city);
        $upsert('store_facebook',     $facebook);
        $upsert('store_instagram',    $instagram);
        $upsert('store_domain_type',  $domainType);
        $upsert('store_launched_at',  date('Y-m-d H:i:s'));

        echo json_encode([
            'success'   => true,
            'message'   => 'Store launched successfully!',
            'store_url' => $storeUrl,
            'store_name'=> $storeName,
            'theme'     => $theme,
        ]);
        exit;
    }

    // Generic step save
    echo json_encode(['success' => true, 'message' => 'Saved']);
    exit;
}

shopHeader($pageTitle, 'store_wizard');
?>

<style>
/* ══════════════════════════════════════════
   STORE LAUNCH WIZARD — 4-Step UI
   ══════════════════════════════════════════ */
:root {
  --wz-purple: #7C3AED;
  --wz-violet: #8B5CF6;
  --wz-teal:   #06B6D4;
  --wz-green:  #10B981;
  --wz-glass:  #0d1526;
  --wz-border: rgba(14,206,206,.12);
}

/* ── Wizard Wrapper ── */
.wizard-outer {
  max-width: 860px; margin: 0 auto;
}

/* ── Step Progress Bar ── */
.wiz-progress {
  display: flex; align-items: center;
  margin-bottom: 2.5rem; gap: 0;
}
.wiz-step-item {
  display: flex; flex-direction: column; align-items: center;
  flex: 1; position: relative; cursor: pointer;
}
.wiz-step-item:not(:last-child)::after {
  content: '';
  position: absolute; top: 22px; left: 50%; width: 100%; height: 2px;
  background: #111e34;
  z-index: 0;
}
.wiz-step-item.done:not(:last-child)::after   { background: linear-gradient(90deg, var(--wz-green), var(--wz-teal)); }
.wiz-step-item.active:not(:last-child)::after { background: rgba(124,58,237,.3); }
.wiz-circle {
  width: 44px; height: 44px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .9rem; font-weight: 800; position: relative; z-index: 1;
  border: 2px solid rgba(255,255,255,.1);
  background: #111f35; color: var(--text2);
  transition: all .35s;
}
.wiz-step-item.done   .wiz-circle { background: linear-gradient(135deg,#10B981,#34D399); border-color: #34D399; color: #fff; }
.wiz-step-item.active .wiz-circle { background: linear-gradient(135deg,var(--wz-purple),var(--wz-violet)); border-color: var(--wz-violet); color: #fff; box-shadow: 0 0 0 6px rgba(124,58,237,.18); }
.wiz-step-lbl {
  font-size: .72rem; font-weight: 600; color: var(--text2);
  margin-top: .55rem; text-align: center; line-height: 1.3;
}
.wiz-step-item.done   .wiz-step-lbl { color: #34D399; }
.wiz-step-item.active .wiz-step-lbl { color: #A78BFA; font-weight: 700; }

/* ── Wizard Card ── */
.wizard-card {
  background: #080c1e;
  border: 1px solid var(--wz-border);
  border-radius: 22px; padding: 2.25rem;
  
}
.wizard-step-panel { display: none; }
.wizard-step-panel.active { display: block; animation: wStepIn .35s ease; }
@keyframes wStepIn { from{opacity:0;transform:translateX(18px)} to{opacity:1;transform:translateX(0)} }

.wiz-step-badge {
  display: inline-flex; align-items: center; gap: .4rem;
  background: rgba(124,58,237,.25); border: 1px solid rgba(167,139,250,.2);
  border-radius: 30px; padding: .25rem .8rem;
  font-size: .67rem; font-weight: 700; color: #A78BFA;
  letter-spacing: .5px; text-transform: uppercase; margin-bottom: .6rem;
}
.wiz-step-title { font-size: 1.5rem; font-weight: 900; color: #fff; letter-spacing: -.6px; margin-bottom: .35rem; }
.wiz-step-sub   { font-size: .85rem; color: var(--text2); margin-bottom: 1.75rem; line-height: 1.6; }

/* ── Form Styling ── */
.wiz-label {
  display: block; font-size: .8rem; font-weight: 700;
  color: rgba(255,255,255,.75); margin-bottom: .45rem;
}
.wiz-input, .wiz-textarea, .wiz-select {
  width: 100%; background: #111f35;
  border: 1px solid rgba(14,206,206,.18);
  border-radius: 11px; padding: .7rem 1rem;
  color: rgba(255,255,255,.88); font-size: .88rem;
  font-family: 'Inter', sans-serif;
  transition: all .25s; outline: none;
}
.wiz-input:focus, .wiz-textarea:focus, .wiz-select:focus {
  border-color: rgba(124,58,237,.5);
  box-shadow: 0 0 0 3px rgba(124,58,237,.25);
  background: rgba(124,58,237,.07);
}
.wiz-textarea { resize: vertical; min-height: 90px; }
.wiz-select option { background: #0a0820; color: #fff; }
.wiz-hint { font-size: .73rem; color: var(--text2); margin-top: .35rem; }
.wiz-form-group { margin-bottom: 1.25rem; }

/* ── Logo Upload ── */
.logo-upload-area {
  border: 2px dashed rgba(139,92,246,.3); border-radius: 14px;
  padding: 2rem; text-align: center; cursor: pointer;
  transition: all .25s;
  background: rgba(124,58,237,.04);
}
.logo-upload-area:hover { border-color: rgba(139,92,246,.6); background: rgba(124,58,237,.08); }
.logo-preview {
  width: 80px; height: 80px; border-radius: 16px;
  background: linear-gradient(135deg, #7C3AED, #06B6D4);
  display: flex; align-items: center; justify-content: center;
  font-size: 2rem; color: #fff; margin: 0 auto .75rem;
}

/* ── Theme Selection (mini) ── */
.theme-mini-grid {
  display: grid; grid-template-columns: repeat(3,1fr); gap: .85rem;
}
.theme-mini-card {
  border-radius: 14px; overflow: hidden; cursor: pointer;
  border: 2px solid transparent; transition: all .25s;
  position: relative;
}
.theme-mini-card:hover { border-color: rgba(139,92,246,.4); transform: translateY(-2px); }
.theme-mini-card.selected { border-color: #A78BFA; box-shadow: 0 0 0 3px rgba(124,58,237,.2); }
.theme-mini-preview {
  height: 80px; display: flex; align-items: flex-end;
  padding: .5rem .6rem;
}
.theme-mini-name {
  font-size: .72rem; font-weight: 700; color: #fff;
  background: rgba(0,0,0,.6); backdrop-filter: blur(4px);
  border-radius: 6px; padding: .2rem .5rem;
}
.theme-mini-card.selected .selected-badge {
  position: absolute; top: .4rem; right: .4rem;
  background: linear-gradient(135deg, #7C3AED, #8B5CF6);
  color: #fff; font-size: .58rem; font-weight: 800;
  padding: .15rem .4rem; border-radius: 20px;
}
.theme-mini-card .selected-badge { display: none; }
.theme-mini-card.selected .selected-badge { display: block; }

.ai-recommend-strip {
  background: linear-gradient(135deg, rgba(236,72,153,.1), rgba(124,58,237,.08));
  border: 1px solid rgba(236,72,153,.2);
  border-radius: 12px; padding: .85rem 1.1rem;
  display: flex; align-items: center; gap: .75rem;
  margin-bottom: 1.25rem;
}
.ai-rec-chip {
  background: rgba(236,72,153,.15); border-radius: 8px;
  padding: .5rem .65rem; font-size: 1rem; color: #F472B6;
}

/* ── Domain Options ── */
.domain-option {
  background: var(--wz-glass);
  border: 2px solid rgba(255,255,255,.08);
  border-radius: 14px; padding: 1.25rem;
  cursor: pointer; transition: all .25s; margin-bottom: .85rem;
}
.domain-option:hover  { border-color: rgba(124,58,237,.35); }
.domain-option.active { border-color: #A78BFA; background: rgba(124,58,237,.08); }
.domain-radio { display: none; }
.domain-type-icon {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; margin-right: 1rem; flex-shrink: 0;
}
.domain-name-preview {
  font-family: 'Inter', monospace; font-size: .92rem; font-weight: 700;
  color: var(--wz-teal); margin-top: .35rem; word-break: break-all;
}

/* ── Launch Panel ── */
.launch-checklist-item {
  display: flex; align-items: center; gap: .75rem;
  padding: .7rem 0; border-bottom: 1px solid rgba(14,206,206,.10);
  font-size: .84rem; color: rgba(255,255,255,.75);
}
.launch-checklist-item:last-child { border-bottom: none; }
.check-circle {
  width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: .75rem;
}
.check-circle.ok  { background: rgba(16,185,129,.2); color: #34D399; }
.check-circle.pending { background: rgba(245,158,11,.15); color: #FCD34D; }

.launch-final-btn {
  width: 100%; padding: 1.1rem; border: none;
  background: linear-gradient(135deg, var(--wz-purple), var(--wz-teal));
  color: #fff; font-size: 1.05rem; font-weight: 900;
  border-radius: 14px; cursor: pointer;
  box-shadow: 0 6px 32px rgba(124,58,237,.45);
  transition: all .3s; letter-spacing: -.3px;
  display: flex; align-items: center; justify-content: center; gap: .7rem;
}
.launch-final-btn:hover { transform: translateY(-3px); box-shadow: 0 12px 40px rgba(124,58,237,.6); }
.launch-final-btn:active { transform: translateY(0); }

/* ── Navigation Buttons ── */
.wiz-nav {
  display: flex; align-items: center; justify-content: space-between;
  margin-top: 2rem; padding-top: 1.25rem;
  border-top: 1px solid rgba(255,255,255,.06);
  gap: 1rem;
}
.wiz-btn-back {
  background: #0f1d32; color: rgba(255,255,255,.7);
  border: 1px solid rgba(14,206,206,.20); border-radius: 11px;
  padding: .7rem 1.5rem; font-size: .88rem; font-weight: 700;
  cursor: pointer; display: flex; align-items: center; gap: .5rem;
  transition: all .25s;
}
.wiz-btn-back:hover { background: rgba(255,255,255,.12); color: #fff; }
.wiz-btn-next {
  background: linear-gradient(135deg, var(--wz-purple), var(--wz-violet));
  color: #fff; border: none; border-radius: 11px;
  padding: .7rem 1.8rem; font-size: .9rem; font-weight: 800;
  cursor: pointer; display: flex; align-items: center; gap: .5rem;
  box-shadow: 0 4px 18px rgba(124,58,237,.4);
  transition: all .25s; margin-left: auto;
}
.wiz-btn-next:hover { transform: translateY(-2px); box-shadow: 0 8px 26px rgba(124,58,237,.55); }

/* ── Success Overlay ── */
.launch-success {
  display: none; position: fixed; inset: 0; z-index: 9999;
  background: rgba(5,5,21,.95); backdrop-filter: blur(24px);
  flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 2rem;
}
.launch-success.show { display: flex; animation: fadeInSc .5s ease; }
@keyframes fadeInSc { from{opacity:0;transform:scale(.95)} to{opacity:1;transform:scale(1)} }
.success-emoji { font-size: 5rem; margin-bottom: 1rem; animation: bounce .6s ease .3s both; }
@keyframes bounce { 0%,100%{transform:translateY(0)} 40%{transform:translateY(-20px)} }

/* ── Responsive ── */
@media(max-width: 768px) {
  .wizard-card       { padding: 1.5rem 1.25rem; border-radius: 16px; }
  .wiz-step-title    { font-size: 1.25rem; }
  .theme-mini-grid   { grid-template-columns: repeat(2,1fr); }
  .wiz-circle        { width: 36px; height: 36px; font-size: .8rem; }
  .wiz-step-item:not(:last-child)::after { top: 18px; }
}
@media(max-width: 576px) {
  .wiz-progress      { gap: 0; }
  .wiz-step-lbl      { font-size: .62rem; }
  .theme-mini-grid   { grid-template-columns: repeat(2,1fr); gap: .6rem; }
  .wizard-card       { padding: 1.1rem 1rem; }
  .wiz-step-title    { font-size: 1.1rem; }
  .wiz-nav           { flex-wrap: wrap; }
  .wiz-btn-next      { width: 100%; justify-content: center; }
  .wiz-btn-back      { width: 100%; justify-content: center; order: 2; }
}
</style>

<!-- Launch Success Overlay -->
<div class="launch-success" id="launchSuccessOverlay">
  <div class="success-emoji">🚀</div>
  <h2 style="font-size:2rem;font-weight:900;color:#fff;letter-spacing:-.8px;margin-bottom:.35rem">
    <span id="liveStoreName">Your Store</span> is Live!
  </h2>
  <p style="font-size:.95rem;color:var(--text2);max-width:420px;line-height:1.65;margin-bottom:.75rem">
    Congratulations! Your ecommerce store has been launched successfully.
  </p>
  <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:12px;padding:.85rem 1.25rem;margin-bottom:1.75rem;max-width:400px;width:100%">
    <div style="font-size:.7rem;color:#34D399;font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.3rem"><i class="bi bi-globe2 me-1"></i>Your Store URL</div>
    <a id="liveStoreUrl" href="#" target="_blank" style="font-size:.9rem;font-weight:700;color:#fff;word-break:break-all;text-decoration:none">
      store.stockora.com/your-store
    </a>
  </div>
  <div style="display:flex;flex-direction:column;gap:.75rem;width:100%;max-width:320px">
    <a href="<?= BASE_URL ?>/shop/commerce_cloud.php" class="launch-final-btn" style="text-decoration:none">
      <i class="bi bi-cloud-fill"></i> Go to Commerce Cloud
    </a>
    <a href="<?= BASE_URL ?>/shop/theme_marketplace.php" style="color:#A78BFA;text-decoration:none;font-size:.85rem">
      <i class="bi bi-palette-fill me-1"></i> Customize Theme
    </a>
  </div>
</div>

<div class="container-fluid px-3 px-md-4">
<div class="wizard-outer">

  <!-- Page Header -->
  <div class="mb-3">
    <a href="<?= BASE_URL ?>/shop/commerce_cloud.php" style="color:var(--text2);text-decoration:none;font-size:.82rem;display:inline-flex;align-items:center;gap:.4rem;margin-bottom:.75rem">
      <i class="bi bi-arrow-left"></i> Back to Commerce Cloud
    </a>
    <h1 style="font-size:1.6rem;font-weight:900;color:#fff;letter-spacing:-.6px;margin:0">Store Launch Wizard</h1>
    <p style="font-size:.85rem;color:var(--text2);margin-top:.3rem">Set up your ecommerce store in 4 simple steps</p>
  </div>

  <!-- ══ STEP PROGRESS ══ -->
  <div class="wiz-progress" id="wizProgress">
    <div class="wiz-step-item done" data-step="1" onclick="goToStep(1)">
      <div class="wiz-circle"><i class="bi bi-check-lg"></i></div>
      <div class="wiz-step-lbl">Business<br>Info</div>
    </div>
    <div class="wiz-step-item active" data-step="2" onclick="goToStep(2)">
      <div class="wiz-circle">2</div>
      <div class="wiz-step-lbl">Choose<br>Theme</div>
    </div>
    <div class="wiz-step-item" data-step="3" onclick="goToStep(3)">
      <div class="wiz-circle">3</div>
      <div class="wiz-step-lbl">Connect<br>Domain</div>
    </div>
    <div class="wiz-step-item" data-step="4" onclick="goToStep(4)">
      <div class="wiz-circle">4</div>
      <div class="wiz-step-lbl">Launch<br>Store</div>
    </div>
  </div>

  <!-- ══ WIZARD CARD ══ -->
  <div class="wizard-card">

    <!-- ══ STEP 1: BUSINESS INFO ══ -->
    <div class="wizard-step-panel" id="step1">
      <div class="wiz-step-badge"><i class="bi bi-building"></i> Step 1 of 4</div>
      <h2 class="wiz-step-title">Business Information</h2>
      <p class="wiz-step-sub">Tell us about your store. This information will appear on your public ecommerce storefront.</p>

      <div class="row g-3">
        <div class="col-md-6">
          <div class="wiz-form-group">
            <label class="wiz-label">Store Name *</label>
            <input type="text" class="wiz-input" id="storeName" value="<?= htmlspecialchars($shopName) ?>" placeholder="e.g. Ahmed Electronics">
            <div class="wiz-hint">This will be shown as your store's brand name</div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="wiz-form-group">
            <label class="wiz-label">Business Category *</label>
            <select class="wiz-select" id="bizCategory">
              <option value="">Select Category</option>
              <option value="fashion">Fashion & Clothing</option>
              <option value="electronics" selected>Electronics & Gadgets</option>
              <option value="beauty">Beauty & Cosmetics</option>
              <option value="grocery">Grocery & Food</option>
              <option value="furniture">Furniture & Home</option>
              <option value="sports">Sports & Fitness</option>
              <option value="pharmacy">Pharmacy & Health</option>
              <option value="general">General Store</option>
            </select>
          </div>
        </div>
        <div class="col-12">
          <div class="wiz-form-group">
            <label class="wiz-label">Store Description</label>
            <textarea class="wiz-textarea" id="storeDesc" placeholder="Describe what your store sells and what makes it special...">Quality electronics and gadgets at the best prices in Pakistan.</textarea>
          </div>
        </div>
        <div class="col-md-6">
          <div class="wiz-form-group">
            <label class="wiz-label">Contact Phone</label>
            <input type="tel" class="wiz-input" id="contactPhone" placeholder="+92 300 1234567">
          </div>
        </div>
        <div class="col-md-6">
          <div class="wiz-form-group">
            <label class="wiz-label">WhatsApp Number</label>
            <input type="tel" class="wiz-input" id="whatsappNum" placeholder="+92 300 1234567">
            <div class="wiz-hint">Customers can contact you via WhatsApp</div>
          </div>
        </div>
        <div class="col-12">
          <div class="wiz-form-group">
            <label class="wiz-label">City / Address</label>
            <input type="text" class="wiz-input" id="storeCity" placeholder="e.g. Lahore, Punjab, Pakistan">
          </div>
        </div>

        <!-- Logo Upload -->
        <div class="col-12">
          <label class="wiz-label">Store Logo</label>
          <div class="logo-upload-area" onclick="document.getElementById('logoInput').click()">
            <div class="logo-preview" id="logoPreview"><i class="bi bi-shop"></i></div>
            <div style="font-size:.85rem;font-weight:700;color:rgba(255,255,255,.75)">Click to upload your logo</div>
            <div style="font-size:.75rem;color:var(--text2);margin-top:.3rem">PNG, JPG — Max 2MB — Recommended: 400×400px</div>
            <input type="file" id="logoInput" accept="image/*" style="display:none" onchange="previewLogo(this)">
          </div>
        </div>

        <!-- Social Media -->
        <div class="col-md-6">
          <div class="wiz-form-group">
            <label class="wiz-label"><i class="bi bi-facebook me-1" style="color:#1877F2"></i>Facebook Page URL</label>
            <input type="url" class="wiz-input" placeholder="https://facebook.com/yourpage">
          </div>
        </div>
        <div class="col-md-6">
          <div class="wiz-form-group">
            <label class="wiz-label"><i class="bi bi-instagram me-1" style="color:#E4405F"></i>Instagram Handle</label>
            <input type="text" class="wiz-input" placeholder="@yourhandle">
          </div>
        </div>
      </div>

      <div class="wiz-nav">
        <div style="font-size:.8rem;color:var(--text2)"><i class="bi bi-info-circle me-1"></i>All info can be changed later</div>
        <button class="wiz-btn-next" onclick="nextStep(1)">
          Continue to Theme <i class="bi bi-arrow-right"></i>
        </button>
      </div>
    </div>

    <!-- ══ STEP 2: THEME ══ -->
    <div class="wizard-step-panel" id="step2">
      <div class="wiz-step-badge"><i class="bi bi-palette-fill"></i> Step 2 of 4</div>
      <h2 class="wiz-step-title">Choose Your Theme</h2>
      <p class="wiz-step-sub">Select a premium theme that matches your business. All themes are mobile-first and conversion-optimized.</p>

      <!-- AI Recommendation -->
      <div class="ai-recommend-strip">
        <div class="ai-rec-chip"><i class="bi bi-robot"></i></div>
        <div>
          <div style="font-size:.8rem;font-weight:800;color:#F472B6;margin-bottom:.2rem">🤖 AI Recommendation for Electronics</div>
          <div style="font-size:.78rem;color:var(--text2);line-height:1.5">
            Based on your category, AI recommends <strong style="color:#fff">Tech Pro</strong> — optimized for electronics stores with high-volume product catalogs and feature-rich layouts.
          </div>
        </div>
      </div>

      <!-- Theme Grid -->
      <div class="theme-mini-grid" id="themeGrid">
        <?php
        $themes = [
          ['Tech Pro',        'linear-gradient(135deg,#1e3a5f,#0f2340)',      'electronics'],
          ['Gadget Hub',      'linear-gradient(135deg,#0d1b2a,#1a2e44)',      'electronics'],
          ['Luxury Fashion',  'linear-gradient(135deg,#1a0a2e,#2d1256)',      'fashion'],
          ['Modern Apparel',  'linear-gradient(135deg,#0a1628,#182640)',      'fashion'],
          ['Beauty Luxe',     'linear-gradient(135deg,#2d0a1e,#1a0d14)',      'beauty'],
          ['Fresh Mart',      'linear-gradient(135deg,#0a2e1a,#0d3b22)',      'grocery'],
          ['Modern Furniture','linear-gradient(135deg,#1e1a0e,#2e2410)',      'furniture'],
          ['Sports Hub',      'linear-gradient(135deg,#1a0a0a,#2d1010)',      'sports'],
          ['MedCare',         'linear-gradient(135deg,#0a1e2e,#0d2a3b)',      'pharmacy'],
          ['Enterprise',      'linear-gradient(135deg,#0a0a1e,#141428)',      'general'],
          ['Dark Commerce',   'linear-gradient(135deg,#0d0d0d,#1a1a2e)',      'general'],
          ['Premium Commerce','linear-gradient(135deg,#0f0a2e,#1e1450)',      'general'],
        ];
        foreach($themes as $i=>[$name,$bg,$cat]):
          $sel = ($i === 0) ? 'selected' : '';
        ?>
        <div class="theme-mini-card <?= $sel ?>" onclick="selectTheme(this,'<?= $name ?>')" data-theme="<?= $name ?>">
          <div class="theme-mini-preview" style="background:<?= $bg ?>">
            <div class="theme-mini-name"><?= $name ?></div>
          </div>
          <div style="padding:.45rem .55rem;background:rgba(0,0,0,.4)">
            <div style="font-size:.68rem;color:rgba(255,255,255,.5)"><?= ucfirst($cat) ?></div>
          </div>
          <span class="selected-badge">✓ Selected</span>
        </div>
        <?php endforeach; ?>
      </div>

      <div style="margin-top:1rem;text-align:center">
        <a href="<?= BASE_URL ?>/shop/theme_marketplace.php" target="_blank" style="font-size:.8rem;color:#A78BFA;text-decoration:none">
          <i class="bi bi-grid-3x3-gap-fill me-1"></i> View all 20 themes in full detail <i class="bi bi-arrow-right"></i>
        </a>
      </div>

      <div id="selectedThemeName" style="margin-top:1rem;background:rgba(124,58,237,.1);border:1px solid rgba(167,139,250,.25);border-radius:10px;padding:.7rem 1rem;font-size:.83rem;font-weight:700;color:#A78BFA">
        <i class="bi bi-check-circle-fill me-1"></i> Selected Theme: <span id="themeNameDisplay">Tech Pro</span>
      </div>

      <div class="wiz-nav">
        <button class="wiz-btn-back" onclick="prevStep(2)">
          <i class="bi bi-arrow-left"></i> Back
        </button>
        <button class="wiz-btn-next" onclick="nextStep(2)">
          Continue to Domain <i class="bi bi-arrow-right"></i>
        </button>
      </div>
    </div>

    <!-- ══ STEP 3: DOMAIN ══ -->
    <div class="wizard-step-panel" id="step3">
      <div class="wiz-step-badge"><i class="bi bi-globe"></i> Step 3 of 4</div>
      <h2 class="wiz-step-title">Connect Your Domain</h2>
      <p class="wiz-step-sub">Choose how your customers will find your store. Use a free Stockora subdomain or connect your own domain.</p>

      <!-- Option 1: Stockora Subdomain -->
      <div class="domain-option active" id="domOpt1" onclick="selectDomain('stockora')">
        <div style="display:flex;align-items:center;gap:1rem">
          <div class="domain-type-icon" style="background:rgba(124,58,237,.18)">
            <i class="bi bi-cloud-fill" style="color:#A78BFA"></i>
          </div>
          <div style="flex:1">
            <div style="font-size:.9rem;font-weight:800;color:#fff;margin-bottom:.25rem">
              Free Stockora Subdomain
              <span style="background:rgba(16,185,129,.15);color:#34D399;font-size:.62rem;padding:.15rem .5rem;border-radius:20px;margin-left:.5rem;font-weight:700">FREE</span>
            </div>
            <div style="font-size:.78rem;color:var(--text2)">Instant activation. No setup required. SSL included.</div>
            <div class="domain-name-preview" id="subdomainPreview">
              store.stockora.com/<?= strtolower(str_replace(' ','-', htmlspecialchars($shopName))) ?>
            </div>
          </div>
          <div id="domRadio1" style="width:20px;height:20px;border-radius:50%;border:2px solid #A78BFA;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <div style="width:10px;height:10px;border-radius:50%;background:#A78BFA"></div>
          </div>
        </div>
      </div>

      <!-- Option 2: Custom Domain -->
      <div class="domain-option" id="domOpt2" onclick="selectDomain('custom')">
        <div style="display:flex;align-items:center;gap:1rem">
          <div class="domain-type-icon" style="background:rgba(6,182,212,.15)">
            <i class="bi bi-globe2" style="color:#22D3EE"></i>
          </div>
          <div style="flex:1">
            <div style="font-size:.9rem;font-weight:800;color:#fff;margin-bottom:.25rem">Connect Custom Domain</div>
            <div style="font-size:.78rem;color:var(--text2)">Use www.yourbusiness.com. Requires DNS verification (5–10 min).</div>
            <div class="domain-name-preview" style="color:var(--text2)" id="customDomainPreview">e.g. www.ahmedelectronics.com</div>
          </div>
          <div id="domRadio2" style="width:20px;height:20px;border-radius:50%;border:2px solid rgba(255,255,255,.2);flex-shrink:0"></div>
        </div>
        <!-- Custom domain input (hidden by default) -->
        <div id="customDomainInput" style="display:none;margin-top:1rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,.07)">
          <label class="wiz-label">Enter Your Domain</label>
          <input type="text" class="wiz-input" id="customDomainVal" placeholder="www.yourbusiness.com" oninput="updateCustomPreview(this.value)">
          <div class="wiz-hint">
            <i class="bi bi-info-circle me-1"></i>
            After clicking "Launch", you'll receive DNS CNAME instructions to verify your domain.
          </div>
        </div>
      </div>

      <!-- SSL Notice -->
      <div style="background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);border-radius:11px;padding:.85rem 1rem;font-size:.8rem;color:rgba(255,255,255,.65);display:flex;align-items:center;gap:.65rem;margin-top:1rem">
        <i class="bi bi-shield-lock-fill" style="color:#34D399;font-size:1.1rem;flex-shrink:0"></i>
        <span>Free SSL certificate included with all plans. Your store will always run on HTTPS for secure checkout.</span>
      </div>

      <div class="wiz-nav">
        <button class="wiz-btn-back" onclick="prevStep(3)">
          <i class="bi bi-arrow-left"></i> Back
        </button>
        <button class="wiz-btn-next" onclick="nextStep(3)">
          Review & Launch <i class="bi bi-arrow-right"></i>
        </button>
      </div>
    </div>

    <!-- ══ STEP 4: LAUNCH ══ -->
    <div class="wizard-step-panel" id="step4">
      <div class="wiz-step-badge"><i class="bi bi-rocket-takeoff-fill"></i> Step 4 of 4</div>
      <h2 class="wiz-step-title">Ready to Launch 🚀</h2>
      <p class="wiz-step-sub">Everything looks great! Review your store setup and launch when ready.</p>

      <!-- Summary Card -->
      <div style="background:#0a1020;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:1.4rem;margin-bottom:1.5rem">
        <div style="font-size:.78rem;font-weight:800;color:var(--text2);text-transform:uppercase;letter-spacing:.6px;margin-bottom:1rem">Store Summary</div>
        <div class="row g-3">
          <div class="col-6">
            <div style="font-size:.72rem;color:var(--text2);margin-bottom:.2rem">Store Name</div>
            <div style="font-size:.9rem;font-weight:700;color:#fff" id="summStoreName"><?= htmlspecialchars($shopName) ?></div>
          </div>
          <div class="col-6">
            <div style="font-size:.72rem;color:var(--text2);margin-bottom:.2rem">Category</div>
            <div style="font-size:.9rem;font-weight:700;color:#fff" id="summCategory">Electronics</div>
          </div>
          <div class="col-6">
            <div style="font-size:.72rem;color:var(--text2);margin-bottom:.2rem">Theme</div>
            <div style="font-size:.9rem;font-weight:700;color:#A78BFA" id="summTheme">Tech Pro</div>
          </div>
          <div class="col-6">
            <div style="font-size:.72rem;color:var(--text2);margin-bottom:.2rem">Domain</div>
            <div style="font-size:.82rem;font-weight:700;color:#22D3EE;word-break:break-all" id="summDomain">
              store.stockora.com/<?= strtolower(str_replace(' ','-', htmlspecialchars($shopName))) ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Launch Checklist -->
      <div style="margin-bottom:1.5rem">
        <div style="font-size:.78rem;font-weight:800;color:var(--text2);text-transform:uppercase;letter-spacing:.6px;margin-bottom:.75rem">Launch Checklist</div>
        <div class="launch-checklist-item">
          <div class="check-circle ok"><i class="bi bi-check-lg"></i></div>
          <span>Business information configured</span>
        </div>
        <div class="launch-checklist-item">
          <div class="check-circle ok"><i class="bi bi-check-lg"></i></div>
          <span>Premium theme selected</span>
        </div>
        <div class="launch-checklist-item">
          <div class="check-circle ok"><i class="bi bi-check-lg"></i></div>
          <span>Domain connection configured</span>
        </div>
        <div class="launch-checklist-item">
          <div class="check-circle ok"><i class="bi bi-check-lg"></i></div>
          <span>SSL certificate enabled</span>
        </div>
        <div class="launch-checklist-item">
          <div class="check-circle pending"><i class="bi bi-clock"></i></div>
          <span>Products to publish online (do after launch)</span>
        </div>
      </div>

      <!-- Terms notice -->
      <div style="font-size:.77rem;color:var(--text2);margin-bottom:1.5rem;line-height:1.6;padding:.8rem 1rem;background:#0a1020;border-radius:10px;border:1px solid rgba(255,255,255,.06)">
        <i class="bi bi-info-circle me-1" style="color:#A78BFA"></i>
        By launching your store, you agree to Stockora's Commerce Cloud Terms of Service. Your store will be live within seconds. You can customize it further from Commerce Cloud settings.
      </div>

      <!-- LAUNCH BUTTON -->
      <button class="launch-final-btn" id="launchBtn" onclick="launchStore()">
        <i class="bi bi-rocket-takeoff-fill"></i> Launch My Store Now
      </button>

      <div class="wiz-nav" style="border-top:none;padding-top:.75rem">
        <button class="wiz-btn-back" onclick="prevStep(4)">
          <i class="bi bi-arrow-left"></i> Back to Domain
        </button>
        <div style="font-size:.75rem;color:var(--text2)">
          <i class="bi bi-lightning-charge-fill me-1" style="color:#F59E0B"></i>Goes live instantly
        </div>
      </div>
    </div>

  </div><!-- /wizard-card -->
</div><!-- /wizard-outer -->
</div><!-- /container -->

<script>
var currentStep = 1;
var selectedTheme = '<?= htmlspecialchars(addslashes($savedTheme ?: 'Tech Pro')) ?>';
var selectedDomain = 'stockora';

// ── Wizard Navigation ──
function goToStep(n) {
  if (n > currentStep) return; // can only go back
  currentStep = n;
  updateWizard();
}
function nextStep(from) {
  if (from < 4) {
    currentStep = from + 1;
    updateWizard();
    updateSummary();
  }
}
function prevStep(from) {
  if (from > 1) {
    currentStep = from - 1;
    updateWizard();
  }
}
function updateWizard() {
  // Step panels
  document.querySelectorAll('.wizard-step-panel').forEach(function(p) { p.classList.remove('active'); });
  var panel = document.getElementById('step' + currentStep);
  if (panel) { panel.classList.add('active'); }

  // Step items
  document.querySelectorAll('.wiz-step-item').forEach(function(s) {
    var n = parseInt(s.getAttribute('data-step'));
    s.classList.remove('active','done');
    if (n < currentStep) { s.classList.add('done'); s.querySelector('.wiz-circle').innerHTML = '<i class="bi bi-check-lg"></i>'; }
    else if (n === currentStep) { s.classList.add('active'); s.querySelector('.wiz-circle').textContent = n; }
    else { s.querySelector('.wiz-circle').textContent = n; }
  });

  window.scrollTo({top: 0, behavior: 'smooth'});
}

// ── Theme Selection ──
function selectTheme(card, name) {
  document.querySelectorAll('.theme-mini-card').forEach(function(c){ c.classList.remove('selected'); });
  card.classList.add('selected');
  selectedTheme = name;
  document.getElementById('themeNameDisplay').textContent = name;
}

// ── Domain Selection ──
function selectDomain(type) {
  selectedDomain = type;
  var opt1 = document.getElementById('domOpt1');
  var opt2 = document.getElementById('domOpt2');
  var r1   = document.getElementById('domRadio1');
  var r2   = document.getElementById('domRadio2');
  var cdi  = document.getElementById('customDomainInput');

  if (type === 'stockora') {
    opt1.classList.add('active'); opt2.classList.remove('active');
    r1.innerHTML = '<div style="width:10px;height:10px;border-radius:50%;background:#A78BFA"></div>';
    r1.style.borderColor = '#A78BFA';
    r2.innerHTML = ''; r2.style.borderColor = 'rgba(255,255,255,.2)';
    cdi.style.display = 'none';
  } else {
    opt2.classList.add('active'); opt1.classList.remove('active');
    r2.innerHTML = '<div style="width:10px;height:10px;border-radius:50%;background:#22D3EE"></div>';
    r2.style.borderColor = '#22D3EE';
    r1.innerHTML = ''; r1.style.borderColor = 'rgba(255,255,255,.2)';
    cdi.style.display = 'block';
  }
  updateSummary();
}

function updateCustomPreview(val) {
  document.getElementById('customDomainPreview').textContent = val || 'e.g. www.yourbusiness.com';
  updateSummary();
}

function updateSummary() {
  var sn = document.getElementById('storeName');
  if (sn) document.getElementById('summStoreName').textContent = sn.value || '<?= htmlspecialchars($shopName) ?>';
  document.getElementById('summTheme').textContent = selectedTheme;

  var domainText = '';
  if (selectedDomain === 'stockora') {
    var slug = (sn ? sn.value : '<?= htmlspecialchars($shopName) ?>').toLowerCase().replace(/\s+/g,'-').replace(/[^a-z0-9-]/g,'');
    domainText = 'store.stockora.com/' + slug;
  } else {
    var cd = document.getElementById('customDomainVal');
    domainText = cd ? (cd.value || 'your-domain.com') : 'your-domain.com';
  }
  document.getElementById('summDomain').textContent = domainText;

  var cat = document.getElementById('bizCategory');
  if (cat) document.getElementById('summCategory').textContent = cat.options[cat.selectedIndex].text;
}

// ── Logo Preview ──
function previewLogo(input) {
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      var prev = document.getElementById('logoPreview');
      prev.innerHTML = '<img src="'+e.target.result+'" style="width:100%;height:100%;object-fit:cover;border-radius:16px;">';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

// ── Store Launch (Real AJAX) ──
function launchStore() {
  var btn = document.getElementById('launchBtn');
  if (!btn) return;

  // Collect data from wizard fields
  var storeName   = (document.getElementById('storeName')   || {value:''}).value.trim();
  var category    = (document.getElementById('bizCategory') || {value:''}).value;
  var description = (document.getElementById('storeDesc')   || {value:''}).value.trim();
  var phone       = (document.getElementById('contactPhone')|| {value:''}).value.trim();
  var whatsapp    = (document.getElementById('whatsappNum') || {value:''}).value.trim();
  var city        = (document.getElementById('storeCity')   || {value:''}).value.trim();
  var customDom   = (document.getElementById('customDomainVal') || {value:''}).value.trim();

  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Launching Your Store...';
  btn.disabled  = true;
  btn.style.opacity = '0.85';

  var formData = new URLSearchParams();
  formData.append('wizard_action',  'launch_store');
  formData.append('store_name',     storeName || '<?= htmlspecialchars(addslashes($shopName)) ?>');
  formData.append('category',       category);
  formData.append('description',    description);
  formData.append('phone',          phone);
  formData.append('whatsapp',       whatsapp);
  formData.append('city',           city);
  formData.append('theme',          selectedTheme || 'Tech Pro');
  formData.append('domain_type',    selectedDomain || 'stockora');
  formData.append('custom_domain',  customDom);
  formData.append('facebook',       '');
  formData.append('instagram',      '');

  fetch(window.location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData.toString()
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.success) {
      // Show real store URL in overlay
      var urlEl = document.getElementById('liveStoreUrl');
      if (urlEl && data.store_url) {
        urlEl.textContent = data.store_url;
        urlEl.href = data.store_url;
      }
      var nameEl = document.getElementById('liveStoreName');
      if (nameEl && data.store_name) nameEl.textContent = data.store_name;

      var overlay = document.getElementById('launchSuccessOverlay');
      if (overlay) overlay.classList.add('show');
    } else {
      btn.innerHTML = '<i class="bi bi-rocket-takeoff-fill"></i> Launch My Store Now';
      btn.disabled = false;
      btn.style.opacity = '1';
      alert('Launch failed: ' + (data.message || 'Unknown error'));
    }
  })
  .catch(function(err) {
    btn.innerHTML = '<i class="bi bi-rocket-takeoff-fill"></i> Launch My Store Now';
    btn.disabled = false;
    btn.style.opacity = '1';
    console.error('Launch error:', err);
    alert('Network error. Please try again.');
  });
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
  updateWizard();

  // Pre-select saved theme if available
  var savedThemeName = '<?= htmlspecialchars(addslashes($savedTheme)) ?>';
  if (savedThemeName) {
    document.querySelectorAll('.theme-mini-card').forEach(function(c) {
      if (c.getAttribute('data-theme') === savedThemeName) {
        document.querySelectorAll('.theme-mini-card').forEach(function(x){ x.classList.remove('selected'); });
        c.classList.add('selected');
      }
    });
    var disp = document.getElementById('themeNameDisplay');
    if (disp) disp.textContent = savedThemeName;
  }

  // Also load from sessionStorage as fallback
  var sst = sessionStorage.getItem('selectedTheme');
  if (sst && !savedThemeName) {
    selectedTheme = sst;
    document.querySelectorAll('.theme-mini-card').forEach(function(c) {
      if (c.getAttribute('data-theme') === sst) {
        document.querySelectorAll('.theme-mini-card').forEach(function(x){ x.classList.remove('selected'); });
        c.classList.add('selected');
      }
    });
    var disp2 = document.getElementById('themeNameDisplay');
    if (disp2) disp2.textContent = sst;
  }
});
</script>

<?php shopFooter(); ?>

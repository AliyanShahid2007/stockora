<?php
function shopHeader(string $pageTitle = 'Dashboard', string $activePage = ''): void {
    $shop     = getCurrentShop();
    $shopName = $shop['name'] ?? $_SESSION['shop_name'] ?? 'My Shop';
    $userName = $_SESSION['user_name'] ?? 'Owner';
    $shopId   = (int)($_SESSION['shop_id'] ?? 0);
    $initials = strtoupper(substr($userName, 0, 2));
    $lowStock = count(getLowStockProducts($shopId));
    $subStatus= getSubscriptionStatus($shopId);
    $premiumAccess = hasPremiumFeatureAccess($shopId);
    $logoUrl  = !empty($shop['logo']) ? BASE_URL . '/assets/uploads/' . $shop['logo'] : null;

    $subDaysLeft = null;
    $subEndDate  = null;
    if ($subStatus === 'active') {
        $subDb  = getDB();
        $subRow = $subDb->prepare("SELECT MAX(end_date) as latest_end FROM subscriptions WHERE shop_id=? AND status='active' AND end_date >= ?");
        $subRow->execute([$shopId, date('Y-m-d')]);
        $subRow = $subRow->fetch();
        if ($subRow && $subRow['latest_end']) {
            $subDaysLeft = (int)ceil((strtotime($subRow['latest_end'] . ' 23:59:59') - time()) / 86400);
            $subEndDate  = $subRow['latest_end'];
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#070f1e">
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/stockora-favicon.png">
<title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($shopName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ══════════════════════════════════════════
   SHOP OWNER PANEL — DEEP DARK THEME v2.0
   Ultra-dark navy · Teal/cyan accent glow
   ══════════════════════════════════════════ */

/* ── Base font ── */
body {
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  background: #0d1829 !important;
  --bg:      #0d1829;
  --bg2:     #101e30;
  --card-bg: #162236;
  --border:  rgba(20,220,200,.18);
  --border2: rgba(20,220,200,.10);
  --text:    #e8f4f4;
  --text2:   #8eb8c4;
  --text3:   #4a7a88;
}

/* ── SIDEBAR — Lighter deep navy ── */
.sidebar {
  background: linear-gradient(180deg,
    #0a1526 0%,
    #0d1a2e 30%,
    #101e34 65%,
    #0d1a2e 100%
  ) !important;
  border-right: 1px solid rgba(20,220,200,.1) !important;
  box-shadow: 4px 0 28px rgba(0,0,0,.3) !important;
}

/* Inner glow on sidebar */
.sidebar::after {
  content: '';
  position: absolute; top: 0; right: 0; width: 1px; height: 100%;
  background: linear-gradient(180deg,
    transparent 5%,
    rgba(20,220,200,.15) 35%,
    rgba(108,99,255,.12) 65%,
    transparent 95%
  );
  pointer-events: none;
}

/* Logo icon */
.sidebar-logo-icon {
  background: linear-gradient(135deg, #0ECECE 0%, #6C63FF 100%) !important;
  box-shadow: 0 4px 20px rgba(14,206,206,.35), 0 0 0 1px rgba(14,206,206,.15) !important;
}

/* Brand text */
.sidebar-logo-text { color: rgba(255,255,255,.92) !important; font-weight: 700 !important; }
.sidebar-logo-text small {
  color: #0ECECE !important;
  font-weight: 800 !important;
  letter-spacing: .5px;
  background: linear-gradient(135deg, #0ECECE, #6C63FF);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}

/* Section labels */
.nav-section-label {
  color: rgba(14,206,206,.4) !important;
  text-transform: uppercase;
  font-size: .59rem;
  font-weight: 700;
  letter-spacing: 1px;
}

/* Nav links default */
.nav-item-link { color: rgba(255,255,255,.45) !important; }
.nav-item-link:not(.active):hover {
  background: rgba(14,206,206,.07) !important;
  color: rgba(255,255,255,.85) !important;
}
.nav-item-link:not(.active):hover .nav-icon { color: #0ECECE !important; }
.nav-icon { color: rgba(255,255,255,.3) !important; }

/* Active nav */
.nav-item-link.active {
  background: rgba(14,206,206,.1) !important;
  color: #fff !important;
  font-weight: 600 !important;
}
.nav-item-link.active::before {
  background: linear-gradient(180deg, #0ECECE, #6C63FF) !important;
}
.nav-item-link.active .nav-icon { color: #0ECECE !important; }

/* ── TOPBAR — dark matching sidebar ── */
.topbar {
  background: #0d1a2e !important;
  border-bottom: 1px solid rgba(14,206,206,.18) !important;
  box-shadow: 0 2px 20px rgba(0,0,0,.25) !important;
  position: relative !important;
  z-index: 1030 !important;
  overflow: visible !important;
}
.topbar::after { display: none !important; }
.topbar-title { color: rgba(255,255,255,.95) !important; font-weight: 700 !important; }
.topbar-title::before {
  content: '';
  display: inline-block; width: 3px; height: 14px;
  background: linear-gradient(135deg, #0ECECE, #6C63FF);
  border-radius: 3px; margin-right: 9px; vertical-align: middle;
}
.topbar-toggle {
  color: rgba(255,255,255,.65) !important;
  background: transparent !important;
  border-color: transparent !important;
}
.topbar-toggle:hover {
  color: #0ECECE !important;
  background: rgba(14,206,206,.12) !important;
  border-color: rgba(14,206,206,.2) !important;
}
.topbar-btn {
  color: rgba(255,255,255,.65) !important;
  background: rgba(255,255,255,.06) !important;
  border-color: rgba(255,255,255,.1) !important;
}
.topbar-btn:hover {
  color: #0ECECE !important;
  background: rgba(14,206,206,.15) !important;
  border-color: rgba(14,206,206,.3) !important;
  box-shadow: 0 0 10px rgba(14,206,206,.2) !important;
}

/* ── Shop chip ── */
.shop-chip { background: rgba(14,206,206,.08) !important; border: 1px solid rgba(14,206,206,.15) !important; }
.shop-chip-av { background: linear-gradient(135deg, #0ECECE, #6C63FF) !important; color: #fff !important; }
.shop-chip-name { color: rgba(255,255,255,.85) !important; }
.shop-chip-role { color: #0ECECE !important; font-size: .65rem !important; }

/* ── Subscription pill ── */
.days-pill.ok { background: rgba(14,206,206,.1) !important; color: #0ECECE !important; border-color: rgba(14,206,206,.25) !important; }

/* ── Sidebar footer ── */
.sidebar-footer { border-top: 1px solid rgba(255,255,255,.06) !important; background: rgba(0,0,0,.2) !important; }
.sidebar-user-avatar { background: linear-gradient(135deg, #0ECECE, #6C63FF) !important; color: #fff !important; font-weight: 800 !important; }
.sidebar-user-name { color: rgba(255,255,255,.88) !important; font-weight: 700 !important; }
.sidebar-user-role { color: #0ECECE !important; font-size: .68rem !important; }

/* ── Mobile bottom nav (Shop DARK) ── */
.mobile-bottom-nav {
  background: #0d1a2e !important;
  border-top: 1px solid rgba(14,206,206,.14) !important;
  box-shadow: 0 -4px 20px rgba(0,0,0,.3) !important;
}
.mobile-bottom-nav a { color: rgba(255,255,255,.38) !important; }
.mobile-bottom-nav a.active,
.mobile-bottom-nav a:hover { color: #0ECECE !important; }
/* ── FORM OVERRIDES — inline so always wins ── */
label, .form-label, .col-form-label {
  color: #e8f4f4 !important;
  font-weight: 600 !important;
  font-size: .82rem !important;
  margin-bottom: .4rem !important;
}
label small, .form-label small { color: #8eb8c4 !important; font-weight: 400 !important; }

.form-control, .form-select {
  background: rgba(255,255,255,.09) !important;
  border: 1.5px solid rgba(255,255,255,.2) !important;
  color: #e8f4f4 !important;
  border-radius: 10px !important;
  font-size: .875rem !important;
}
.form-control::placeholder { color: #4a7a88 !important; font-style: italic; }
.form-control:focus, .form-select:focus {
  background: rgba(255,255,255,.14) !important;
  border-color: #0ECECE !important;
  box-shadow: 0 0 0 3px rgba(14,206,206,.22) !important;
  color: #e8f4f4 !important;
  outline: none !important;
}
.form-control:disabled, .form-control[readonly], .form-select:disabled {
  background: rgba(255,255,255,.04) !important;
  color: #8eb8c4 !important;
  cursor: not-allowed !important;
  opacity: .7 !important;
}
textarea.form-control { min-height: 90px !important; resize: vertical !important; }

.form-select {
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%238eb8c4' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e") !important;
  background-repeat: no-repeat !important;
  background-position: right .75rem center !important;
  background-size: 12px !important;
  padding-right: 2.2rem !important;
}
.form-select option { background: #162236 !important; color: #e8f4f4 !important; }

.input-group-text {
  background: rgba(255,255,255,.08) !important;
  border: 1.5px solid rgba(255,255,255,.2) !important;
  color: #8eb8c4 !important;
}
.input-group > .form-control, .input-group > .form-select { border-left: none !important; }
.input-group > .input-group-text { border-right: none !important; }

.form-check-input {
  background-color: rgba(255,255,255,.1) !important;
  border: 1.5px solid rgba(255,255,255,.28) !important;
}
.form-check-input:checked { background-color: #0ECECE !important; border-color: #0ECECE !important; }
.form-check-label { color: #e8f4f4 !important; font-size: .85rem !important; font-weight: 500 !important; }
.form-text        { color: #8eb8c4 !important; font-size: .78rem !important; }
.invalid-feedback { color: #FCA5A5 !important; }

.card { background: #162236 !important; border: 1px solid rgba(20,220,200,.15) !important; border-radius: 14px !important; }
.card-header { background: rgba(255,255,255,.04) !important; border-bottom: 1px solid rgba(20,220,200,.12) !important; color: #fff !important; font-weight: 700 !important; border-radius: 14px 14px 0 0 !important; }
.card-body   { background: transparent !important; }

h1,h2,h3,h4,h5,h6 { color: #e8f4f4 !important; }
p { color: #8eb8c4 !important; }
.text-muted     { color: #8eb8c4 !important; }
.text-secondary { color: #8eb8c4 !important; }
.text-dark      { color: #e8f4f4 !important; }
.text-body      { color: #e8f4f4 !important; }
small           { color: #8eb8c4 !important; }
li, dt, dd      { color: #e8f4f4 !important; }
td, th          { color: #e8f4f4 !important; }

.modal-content { background: #162236 !important; border: 1px solid rgba(20,220,200,.2) !important; border-radius: 16px !important; color: #e8f4f4 !important; }
.modal-header  { background: rgba(255,255,255,.04) !important; border-bottom: 1px solid rgba(20,220,200,.12) !important; border-radius: 16px 16px 0 0 !important; }
.modal-title   { color: #e8f4f4 !important; font-weight: 700 !important; }
.modal-header .btn-close { filter: invert(1) opacity(.6) !important; }
.modal-body    { background: transparent !important; color: #e8f4f4 !important; }
.modal-footer  { background: rgba(255,255,255,.025) !important; border-top: 1px solid rgba(20,220,200,.1) !important; border-radius: 0 0 16px 16px !important; }

/* ── RECEIPT MODAL — white bg, black text (overrides dark modal) ── */
#receiptContent,
#receiptContent .invoice-wrapper { background: #ffffff !important; }
#receiptContent .invoice-wrapper,
#receiptContent .invoice-wrapper *,
#receiptContent .invoice-header,
#receiptContent .invoice-shop-name,
#receiptContent .invoice-footer,
#receiptContent .invoice-table th,
#receiptContent .invoice-table td,
#receiptContent .invoice-total-row,
#receiptContent .invoice-grand-total,
#receiptContent p,
#receiptContent small,
#receiptContent td, #receiptContent th {
  color: #000000 !important;
  -webkit-text-fill-color: #000000 !important;
  background: transparent !important;
}
#receiptContent .invoice-wrapper { background: #ffffff !important; padding: 1rem !important; }
#receiptContent .invoice-footer   { color: #444444 !important; -webkit-text-fill-color: #444444 !important; }

.table { color: #e8f4f4 !important; --bs-table-bg: transparent !important; }
.table thead th { background: rgba(255,255,255,.05) !important; color: #8eb8c4 !important; border-bottom: 1.5px solid rgba(20,220,200,.15) !important; font-size: .72rem !important; font-weight: 700 !important; text-transform: uppercase !important; letter-spacing: .7px !important; }
.table tbody tr { border-bottom: 1px solid rgba(20,220,200,.08) !important; }
.table tbody tr:hover { background: rgba(14,206,206,.06) !important; }
.table tbody td { color: #e8f4f4 !important; vertical-align: middle !important; border: none !important; }

.bg-success{background:rgba(16,185,129,.22)!important;color:#6EE7B7!important}
.bg-danger {background:rgba(239,68,68,.2)!important;color:#FCA5A5!important}
.bg-warning{background:rgba(245,158,11,.2)!important;color:#FDE68A!important}
.bg-info   {background:rgba(6,182,212,.2)!important;color:#67E8F9!important}
.bg-primary{background:rgba(14,206,206,.2)!important;color:#0ECECE!important}
.bg-secondary{background:rgba(255,255,255,.12)!important;color:#8eb8c4!important}
.text-success{color:#6EE7B7!important} .text-danger{color:#FCA5A5!important}
.text-warning{color:#FDE68A!important} .text-info{color:#67E8F9!important}

.alert-success{background:rgba(16,185,129,.12)!important;border-left-color:#10B981!important;color:#6EE7B7!important}
.alert-danger {background:rgba(239,68,68,.12)!important;border-left-color:#EF4444!important;color:#FCA5A5!important}
.alert-warning{background:rgba(245,158,11,.12)!important;border-left-color:#F59E0B!important;color:#FDE68A!important}
.alert-info   {background:rgba(6,182,212,.12)!important;border-left-color:#06B6D4!important;color:#67E8F9!important}

/* Keep metric labels readable on every page. */
.app-wrapper .stat-card-label { color: #fff !important; opacity: 1 !important; }

/* Force all global floating notifications to the dark shop theme. */
#globalToast { background: #162236 !important; color: #fff !important; }
#globalToast span { color: #fff !important; }

hr { border-color: rgba(20,220,200,.12) !important; opacity: 1 !important; }
</style>
</head>
<body>
<div class="app-wrapper">
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ════════════ SIDEBAR ════════════ -->
<nav class="sidebar" id="sidebar">
  <button type="button" class="sidebar-close" onclick="closeSidebar()" aria-label="Close menu">
    <i class="bi bi-x-lg"></i>
  </button>
  <a href="<?= BASE_URL ?>/shop/index.php" class="sidebar-logo">
    <div class="sidebar-logo-icon">
      <?php if ($logoUrl): ?>
      <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" style="width:100%;height:100%;object-fit:cover;border-radius:10px;">
      <?php else: ?>
      <i class="bi bi-shop-window"></i>
      <?php endif; ?>
    </div>
    <div class="sidebar-logo-text"><?= htmlspecialchars(mb_strimwidth($shopName,0,18,'…')) ?><small>AI</small></div>
  </a>

  <div class="sidebar-nav">

    <div class="nav-section-label">Main</div>
    <a href="<?= BASE_URL ?>/shop/index.php"       class="nav-item-link <?= $activePage==='dashboard'?'active':'' ?>">
      <i class="bi bi-speedometer2 nav-icon"></i>Dashboard
    </a>
    <a href="<?= BASE_URL ?>/shop/analytics.php"   class="nav-item-link <?= $activePage==='analytics'?'active':'' ?>">
      <i class="bi bi-bar-chart-line nav-icon"></i>Analytics &amp; Tools
    </a>
    <a href="<?= BASE_URL ?>/shop/daily_target.php" class="nav-item-link <?= in_array($activePage,['target','daily_target'])?'active':'' ?>">
      <i class="bi bi-bullseye nav-icon"></i>Daily Target
    </a>
    <a href="<?= BASE_URL ?>/shop/pos.php"         class="nav-item-link <?= $activePage==='pos'?'active':'' ?>">
      <i class="bi bi-cart3 nav-icon"></i>POS Billing
    </a>

    <div class="nav-section-label">Inventory</div>
    <a href="<?= BASE_URL ?>/shop/products.php"    class="nav-item-link <?= $activePage==='products'?'active':'' ?>">
      <i class="bi bi-box-seam nav-icon"></i>Products
      <?php if ($lowStock > 0): ?>
      <span class="nav-badge low-stock-badge"><?= $lowStock ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/shop/categories.php"  class="nav-item-link <?= $activePage==='categories'?'active':'' ?>">
      <i class="bi bi-tags nav-icon"></i>Categories
    </a>
    <a href="<?= BASE_URL ?>/shop/purchases.php"   class="nav-item-link <?= $activePage==='purchases'?'active':'' ?>">
      <i class="bi bi-truck nav-icon"></i>Purchase Entry
    </a>
    <a href="<?= BASE_URL ?>/shop/suppliers.php"   class="nav-item-link <?= $activePage==='suppliers'?'active':'' ?>">
      <i class="bi bi-person-vcard nav-icon"></i>Suppliers &amp; Dues
    </a>
    <a href="<?= BASE_URL ?>/shop/stock.php"       class="nav-item-link <?= $activePage==='stock'?'active':'' ?>">
      <i class="bi bi-clipboard-data nav-icon"></i>Stock Report
    </a>

    <div class="nav-section-label">Sales</div>
    <a href="<?= BASE_URL ?>/shop/sales.php"       class="nav-item-link <?= $activePage==='sales'?'active':'' ?>">
      <i class="bi bi-receipt nav-icon"></i>Sales History
    </a>
    <a href="<?= BASE_URL ?>/shop/customer_returns.php" class="nav-item-link <?= $activePage==='customer_returns'?'active':'' ?>">
      <i class="bi bi-arrow-return-left nav-icon"></i>Customer Returns
    </a>
    <a href="<?= BASE_URL ?>/shop/customers.php"   class="nav-item-link <?= $activePage==='customers'?'active':'' ?>">
      <i class="bi bi-people nav-icon"></i>Customers
    </a>
    <a href="<?= BASE_URL ?>/shop/bulk_buyers.php" class="nav-item-link <?= $activePage==='buyers'?'active':'' ?>">
      <i class="bi bi-building nav-icon"></i>Bulk Buyers
    </a>
    <a href="<?= BASE_URL ?>/shop/credit_dues.php" class="nav-item-link <?= $activePage==='credit_dues'?'active':'' ?>">
      <i class="bi bi-person-badge nav-icon"></i>Credit &amp; Dues
    </a>

    <div class="nav-section-label">Finance</div>
    <a href="<?= BASE_URL ?>/shop/expenses.php"          class="nav-item-link <?= $activePage==='expenses'?'active':'' ?>">
      <i class="bi bi-wallet2 nav-icon"></i>Expenses
    </a>
    <a href="<?= BASE_URL ?>/shop/zreport.php"           class="nav-item-link <?= $activePage==='zreport'?'active':'' ?>">
      <i class="bi bi-journal-check nav-icon"></i>Z-Report (EOD)
    </a>
    <a href="<?= BASE_URL ?>/shop/profit_calculator.php" class="nav-item-link <?= $activePage==='profit_calc'?'active':'' ?>">
      <i class="bi bi-calculator nav-icon"></i>Profit &amp; Prices
    </a>

    <div class="nav-section-label">AI Features</div>
    <a href="<?= $premiumAccess ? BASE_URL . '/shop/ai_lab.php' : BASE_URL . '/shop/subscription.php?msg=AI+Engine+is+available+on+paid+plans.&type=warning' ?>" class="nav-item-link <?= $activePage==='ai_lab'?'active':'' ?>">
      <i class="bi bi-robot nav-icon" style="color:#a78bfa;"></i>AI Smart Lab
      <span class="nav-badge" style="background:linear-gradient(135deg,#6C63FF,#3ECFCF);font-size:.55rem;padding:.1rem .38rem;border-radius:20px;"><?= $premiumAccess ? 'AI' : 'LOCKED' ?></span>
    </a>

    <div class="nav-section-label" style="color:rgba(167,139,250,.5)!important">Commerce Cloud</div>
    <a href="<?= $premiumAccess ? BASE_URL . '/shop/commerce_cloud.php' : BASE_URL . '/shop/subscription.php?msg=Commerce+Cloud+is+available+on+paid+plans.&type=warning' ?>" class="nav-item-link <?= $activePage==='commerce_cloud'?'active':'' ?>" style="<?= $activePage==='commerce_cloud'?'':'background:rgba(124,58,237,.04)!important;' ?>">
      <i class="bi bi-cloud-fill nav-icon" style="color:#A78BFA;"></i>Commerce Cloud
      <span class="nav-badge" style="background:linear-gradient(135deg,#7C3AED,#06B6D4);font-size:.5rem;padding:.1rem .38rem;border-radius:20px;letter-spacing:.3px"><?= $premiumAccess ? 'NEW' : 'LOCKED' ?></span>
    </a>
    <a href="<?= $premiumAccess ? BASE_URL . '/shop/store_wizard.php' : BASE_URL . '/shop/subscription.php' ?>" class="nav-item-link <?= $activePage==='store_wizard'?'active':'' ?>">
      <i class="bi bi-rocket-takeoff-fill nav-icon" style="color:#8B5CF6;"></i>Store Wizard
    </a>
    <a href="<?= $premiumAccess ? BASE_URL . '/shop/theme_marketplace.php' : BASE_URL . '/shop/subscription.php' ?>" class="nav-item-link <?= $activePage==='theme_marketplace'?'active':'' ?>">
      <i class="bi bi-palette-fill nav-icon" style="color:#EC4899;"></i>Theme Marketplace
    </a>
    <a href="<?= $premiumAccess ? BASE_URL . '/shop/store_customize.php' : BASE_URL . '/shop/subscription.php' ?>" class="nav-item-link <?= $activePage==='store_customize'?'active':'' ?>">
      <i class="bi bi-sliders nav-icon" style="color:#6366f1;"></i>Store Customizer
    </a>
    <a href="<?= $premiumAccess ? BASE_URL . '/shop/online_orders.php' : BASE_URL . '/shop/subscription.php' ?>" class="nav-item-link <?= $activePage==='online_orders'?'active':'' ?>">
      <i class="bi bi-bag-check-fill nav-icon" style="color:#22D3EE;"></i>Online Orders
    </a>

    <div class="nav-section-label">Alerts &amp; Data</div>
    <a href="<?= BASE_URL ?>/shop/reorder_alerts.php" class="nav-item-link <?= $activePage==='reorder_alerts'?'active':'' ?>">
      <i class="bi bi-bell-fill nav-icon"></i>Reorder Alerts
      <?php if ($lowStock > 0): ?>
      <span class="nav-badge" style="background:#ea5455;"><?= $lowStock ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/shop/import.php" class="nav-item-link <?= $activePage==='import'?'active':'' ?>">
      <i class="bi bi-upload nav-icon"></i>Import Data
    </a>
    <a href="<?= BASE_URL ?>/shop/export.php" class="nav-item-link <?= $activePage==='export'?'active':'' ?>">
      <i class="bi bi-download nav-icon"></i>Export Data
    </a>

    <div class="nav-section-label">Account</div>
    <a href="<?= BASE_URL ?>/shop/subscription.php" class="nav-item-link <?= $activePage==='subscription'?'active':'' ?>">
      <i class="bi bi-calendar-check nav-icon"></i>Subscription
      <?php if ($subStatus==='expired'): ?>
      <span class="nav-badge">!</span>
      <?php elseif ($subDaysLeft !== null && $subDaysLeft <= 7): ?>
      <span class="nav-badge" style="background:#ff9f43;"><?= $subDaysLeft ?>d</span>
      <?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/shop/settings.php" class="nav-item-link <?= $activePage==='settings'?'active':'' ?>">
      <i class="bi bi-gear nav-icon"></i>Settings
    </a>
    <a href="<?= BASE_URL ?>/logout.php" class="nav-item-link" style="color:rgba(234,84,85,.75)!important;">
      <i class="bi bi-box-arrow-right nav-icon"></i>Logout
    </a>

  </div>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-user-avatar"><?= htmlspecialchars($initials) ?></div>
      <div class="sidebar-user-info">
        <div class="sidebar-user-name"><?= htmlspecialchars($userName) ?></div>
        <div class="sidebar-user-role"><?= ucfirst($_SESSION['user_role'] ?? 'owner') ?></div>
      </div>
    </div>
  </div>

</nav>

<!-- ════════════ MAIN CONTENT ════════════ -->
<div class="main-content">

  <!-- TOPBAR -->
  <header class="topbar">
    <button class="topbar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle menu">
      <i class="bi bi-list"></i>
    </button>

    <div class="topbar-title">
      <?= htmlspecialchars(mb_strimwidth($pageTitle, 0, 26, '…')) ?>
    </div>

    <div class="topbar-actions ms-auto">

      <!-- Subscription days pill (md+) -->
      <?php if ($subDaysLeft !== null): ?>
      <a href="<?= BASE_URL ?>/shop/subscription.php"
         class="days-pill d-none d-md-inline-flex <?= $subDaysLeft<=3?'urgent':($subDaysLeft<=7?'warn':'ok') ?>">
        <i class="bi bi-calendar-check"></i>
        <?= $subDaysLeft ?>d left
      </a>
      <?php endif; ?>

      <!-- Low stock alert -->
      <?php if ($lowStock > 0): ?>
      <a href="<?= BASE_URL ?>/shop/reorder_alerts.php" class="topbar-btn" title="<?= $lowStock ?> low stock" style="position:relative;">
        <i class="bi bi-exclamation-triangle-fill" style="color:#ff9f43;"></i>
        <span style="position:absolute;top:-3px;right:-3px;min-width:15px;height:15px;border-radius:8px;background:#ea5455;color:#fff;font-size:.52rem;font-weight:800;display:flex;align-items:center;justify-content:center;padding:0 2px;"><?= $lowStock ?></span>
      </a>
      <?php endif; ?>

      <!-- POS quick link -->
      <a href="<?= BASE_URL ?>/shop/pos.php" class="topbar-btn" title="Open POS">
        <i class="bi bi-cart3"></i>
      </a>

      <!-- Shop chip (md+) -->
      <div class="shop-chip d-none d-md-flex">
        <div class="shop-chip-av"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="shop-chip-name"><?= htmlspecialchars($userName) ?></div>
          <div class="shop-chip-role"><?= ucfirst($_SESSION['user_role'] ?? 'owner') ?></div>
        </div>
      </div>

      <!-- Logout -->
      <a href="<?= BASE_URL ?>/logout.php" class="topbar-btn" title="Logout" style="color:#ea5455;">
        <i class="bi bi-box-arrow-right"></i>
      </a>
    </div>
  </header>

  <?php
  // Subscription status banner
  if ($subStatus === 'expired' || $subStatus === 'no_subscription'):
  ?>
  <div class="alert alert-danger mx-3 mt-3 mb-0 rounded-3 d-flex align-items-center gap-2 py-2 pe-2" style="border-left:4px solid #ea5455;">
    <i class="bi bi-x-octagon-fill flex-shrink-0"></i>
    <div class="flex-grow-1 small">
      <strong>Subscription Expired!</strong>
      <span class="d-none d-md-inline"> Contact admin to renew.</span>
    </div>
    <a href="<?= BASE_URL ?>/shop/subscription.php" class="btn btn-sm btn-danger flex-shrink-0">Renew</a>
  </div>
  <?php elseif ($subDaysLeft !== null && $subDaysLeft <= 7): ?>
  <div class="alert alert-warning mx-3 mt-3 mb-0 rounded-3 d-flex align-items-center gap-2 py-2 pe-2" style="border-left:4px solid #ff9f43;">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="color:#ff9f43;"></i>
    <div class="flex-grow-1 small">
      <strong><?= $subDaysLeft ?>d left!</strong>
      <span class="d-none d-md-inline"> Expires <?= date('d M Y', strtotime($subEndDate)) ?>.</span>
    </div>
    <a href="<?= BASE_URL ?>/shop/subscription.php" class="btn btn-sm btn-warning flex-shrink-0">View</a>
  </div>
  <?php endif; ?>

  <div class="page-content">
<?php } ?>

<?php function shopFooter(): void { ?>
  </div><!-- /page-content -->
</div><!-- /main-content -->

<!-- ════════════ MOBILE BOTTOM NAV (Shop) ════════════ -->
<nav class="mobile-bottom-nav" id="mobileBottomNav">
  <a href="<?= BASE_URL ?>/shop/index.php"    id="mbn-home">  <i class="bi bi-speedometer2"></i><span>Home</span></a>
  <a href="<?= BASE_URL ?>/shop/pos.php"      id="mbn-pos">   <i class="bi bi-cart3"></i><span>POS</span></a>
  <a href="<?= BASE_URL ?>/shop/products.php" id="mbn-prod">  <i class="bi bi-box-seam"></i><span>Products</span>
    <?php
    $ls = count(getLowStockProducts((int)($_SESSION['shop_id']??0)));
    if ($ls > 0): ?>
    <span class="mnav-badge"><?= $ls ?></span>
    <?php endif; ?>
  </a>
  <a href="<?= BASE_URL ?>/shop/sales.php"    id="mbn-sales"> <i class="bi bi-receipt"></i><span>Sales</span></a>
  <a href="#" onclick="toggleSidebar();return false;" id="mbn-more"><i class="bi bi-grid-3x3-gap"></i><span>More</span></a>
</nav>

</div><!-- /app-wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=3"></script>
<script>
var BASE_URL = '<?= BASE_URL ?>';
// Highlight active bottom nav item
(function(){
  const path = window.location.pathname;
  const base_url = document.querySelector('script[src*="app.js"]') ? BASE_URL || '' : '';
  const map = {
    '/shop/index.php':    'mbn-home',
    '/shop/pos.php':      'mbn-pos',
    '/shop/products.php': 'mbn-prod',
    '/shop/sales.php':    'mbn-sales',
  };
  for (const [p, id] of Object.entries(map)) {
    if (path === p || path.endsWith(p)) {
      const el = document.getElementById(id);
      if (el) el.classList.add('active');
    }
  }
})();
</script>
</body>
</html>
<?php } ?>

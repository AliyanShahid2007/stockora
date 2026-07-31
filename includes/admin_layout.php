<?php
function adminHeader(string $pageTitle = 'Dashboard', string $activePage = ''): void {
    $adminName  = $_SESSION['admin_name'] ?? 'Admin';
    $initials   = strtoupper(substr($adminName, 0, 2));
    $db         = getDB();
    $stats      = getAdminDashboardStats();

    $expiringSoon         = (int)$db->query("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
    $pendingAnnouncements = (int)$db->query("SELECT COUNT(*) FROM announcements WHERE status='active'")->fetchColumn();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#0e0520">
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/stockora-favicon.png">
<title><?= htmlspecialchars($pageTitle) ?> — <?= APP_NAME ?> Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>var BASE_URL = '<?= BASE_URL ?>';</script>
<style>
/* ══════════════════════════════════════════
   ADMIN PANEL — DEEP PURPLE DARK THEME v2.0
   Rich deep purple/violet · Premium dark luxury
   ══════════════════════════════════════════ */

body {
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  background: #1a1035 !important;
  --bg:      #1a1035;
  --bg2:     #1e1440;
  --card-bg: #241850;
  --border:  rgba(167,139,250,.18);
  --border2: rgba(167,139,250,.10);
  --text:    #f0ecff;
  --text2:   #b8aee8;
  --text3:   #7b6faa;
}

/* ── SIDEBAR — Medium purple dark ── */
.sidebar {
  background: linear-gradient(180deg,
    #160d36 0%,
    #1a1040 35%,
    #1e1448 65%,
    #1a1040 100%
  ) !important;
  border-right: 1px solid rgba(167,139,250,.2) !important;
  box-shadow: 4px 0 32px rgba(0,0,0,.35) !important;
}
.sidebar::after {
  content: '';
  position: absolute; top: 0; right: 0; width: 1px; height: 100%;
  background: linear-gradient(180deg,
    transparent 5%,
    rgba(139,92,246,.3) 30%,
    rgba(167,139,250,.2) 60%,
    transparent 95%
  );
  pointer-events: none;
}

/* Logo icon */
.sidebar-logo-icon {
  background: linear-gradient(135deg, #7C3AED 0%, #A78BFA 100%) !important;
  box-shadow: 0 4px 20px rgba(124,58,237,.55), 0 0 0 1px rgba(167,139,250,.2) !important;
}

/* Brand text */
.sidebar-logo-text { color: rgba(255,255,255,.95) !important; font-weight: 700 !important; }
.sidebar-logo-text small {
  background: linear-gradient(135deg, #A78BFA, #C4B5FD) !important;
  -webkit-background-clip: text !important; -webkit-text-fill-color: transparent !important; background-clip: text !important;
  font-weight: 800 !important;
}

/* Section labels */
.nav-section-label {
  color: rgba(167,139,250,.45) !important;
  font-size: .59rem; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
}

/* Nav links */
.nav-item-link { color: rgba(255,255,255,.45) !important; }
.nav-item-link:not(.active):hover {
  background: rgba(139,92,246,.12) !important;
  color: rgba(255,255,255,.9) !important;
}
.nav-item-link:not(.active):hover .nav-icon { color: #A78BFA !important; }
.nav-icon { color: rgba(255,255,255,.3) !important; }

/* Active nav */
.nav-item-link.active {
  background: linear-gradient(135deg, rgba(124,58,237,.85), rgba(139,92,246,.75)) !important;
  color: #fff !important; font-weight: 700 !important;
  box-shadow: 0 4px 18px rgba(124,58,237,.4) !important;
}
.nav-item-link.active::before {
  background: linear-gradient(180deg, #C4B5FD, #7C3AED) !important;
}
.nav-item-link.active .nav-icon { color: #fff !important; }

/* Nav badge */
.nav-badge {
  background: linear-gradient(135deg, #7C3AED, #A78BFA) !important;
  color: #fff !important;
}

/* ── TOPBAR — Medium purple dark ── */
.topbar {
  background: linear-gradient(90deg, #1a1040 0%, #1e1448 100%) !important;
  border-bottom: 1px solid rgba(167,139,250,.28) !important;
  box-shadow: 0 2px 24px rgba(0,0,0,.25) !important;
  position: relative !important;
  z-index: 1030 !important;
}
.topbar::after { display: none !important; }
.topbar-title { color: rgba(255,255,255,.95) !important; font-weight: 700 !important; }
.topbar-title::before {
  content: '';
  display: inline-block; width: 4px; height: 16px;
  background: linear-gradient(135deg, #7C3AED, #A78BFA);
  border-radius: 4px; margin-right: 10px; vertical-align: middle;
}
.topbar-toggle {
  color: rgba(255,255,255,.65) !important;
  background: transparent !important;
  border-color: transparent !important;
}
.topbar-toggle:hover {
  color: #A78BFA !important;
  background: rgba(139,92,246,.15) !important;
  border-color: rgba(167,139,250,.25) !important;
}
.topbar-btn {
  color: rgba(255,255,255,.65) !important;
  background: rgba(255,255,255,.06) !important;
  border-color: rgba(255,255,255,.1) !important;
}
.topbar-btn:hover {
  color: #A78BFA !important;
  background: rgba(139,92,246,.18) !important;
  border-color: rgba(167,139,250,.35) !important;
  box-shadow: 0 0 12px rgba(139,92,246,.25) !important;
}

/* ── Admin chip ── */
.admin-chip {
  background: rgba(139,92,246,.1) !important;
  border: 1px solid rgba(167,139,250,.2) !important;
}
.admin-chip-av {
  background: linear-gradient(135deg, #7C3AED, #A78BFA) !important;
  color: #fff !important; font-weight: 800 !important;
}
.admin-chip-name { color: rgba(255,255,255,.88) !important; font-weight: 700 !important; }
.admin-chip-role { color: #A78BFA !important; font-size: .65rem !important; }

/* ── Shops pill ── */
.shops-pill {
  background: rgba(139,92,246,.12) !important;
  color: #A78BFA !important;
  border: 1px solid rgba(167,139,250,.2) !important;
}
.live-dot { background: #10B981 !important; box-shadow: 0 0 8px rgba(16,185,129,.6) !important; }

/* ── Sidebar footer ── */
.sidebar-footer {
  border-top: 1px solid rgba(139,92,246,.12) !important;
  background: rgba(0,0,0,.25) !important;
}
.sidebar-user-avatar {
  background: linear-gradient(135deg, #7C3AED, #A78BFA) !important;
  color: #fff !important; font-weight: 800 !important;
}
.sidebar-user-name { color: rgba(255,255,255,.9) !important; font-weight: 700 !important; }
.sidebar-user-role { color: #A78BFA !important; font-size: .68rem !important; }

/* Sidebar version */
.sidebar-version {
  background: linear-gradient(135deg, #7C3AED, #6D28D9) !important;
  color: #E9D5FF !important;
  text-align: center; font-size: .63rem; font-weight: 700; padding: .45rem;
  letter-spacing: .5px;
}

/* ── Mobile bottom nav (Admin DEEP PURPLE) ── */
.mobile-bottom-nav {
  display: none;
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 1035;
  background: #1a1040;
  border-top: 1px solid rgba(167,139,250,.2);
  padding: .4rem 0 calc(.4rem + env(safe-area-inset-bottom));
  box-shadow: 0 -4px 24px rgba(0,0,0,.35);
}
.mobile-bottom-nav a {
  flex: 1; display: flex; flex-direction: column; align-items: center;
  gap: .18rem; text-decoration: none; color: rgba(255,255,255,.35);
  font-size: .58rem; font-weight: 600; padding: .3rem .2rem;
  transition: color .2s; position: relative; min-width: 0;
}
.mobile-bottom-nav a i { font-size: 1.15rem; }
.mobile-bottom-nav a.active,
.mobile-bottom-nav a:hover { color: #A78BFA; }
.mobile-bottom-nav a .mnav-badge {
  position: absolute; top: .1rem; right: calc(50% - 18px);
  min-width: 15px; height: 15px; border-radius: 8px;
  background: #ea5455; color: #fff;
  font-size: .52rem; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  padding: 0 3px; line-height: 1;
}

@media (max-width: 991.98px) {
  .mobile-bottom-nav { display: flex; }
  .page-content { padding-bottom: calc(70px + env(safe-area-inset-bottom)) !important; }
}
@media (max-width: 575.98px) {
  .mobile-bottom-nav a span { display: none; }
  .mobile-bottom-nav a { font-size: 0; }
  .mobile-bottom-nav a i { font-size: 1.25rem; }
}
/* ── FORM OVERRIDES — inline so always wins ── */
label, .form-label, .col-form-label {
  color: #f0ecff !important;
  font-weight: 600 !important;
  font-size: .82rem !important;
  margin-bottom: .4rem !important;
}
label small, .form-label small { color: #b8aee8 !important; font-weight: 400 !important; }

.form-control, .form-select {
  background: rgba(255,255,255,.1) !important;
  border: 1.5px solid rgba(255,255,255,.22) !important;
  color: #f0ecff !important;
  border-radius: 10px !important;
  font-size: .875rem !important;
}
.form-control::placeholder { color: #7b6faa !important; font-style: italic; }
.form-control:focus, .form-select:focus {
  background: rgba(255,255,255,.15) !important;
  border-color: #A78BFA !important;
  box-shadow: 0 0 0 3px rgba(167,139,250,.25) !important;
  color: #f0ecff !important;
  outline: none !important;
}
.form-control:disabled, .form-control[readonly], .form-select:disabled {
  background: rgba(255,255,255,.05) !important;
  color: #b8aee8 !important;
  cursor: not-allowed !important;
  opacity: .7 !important;
}
textarea.form-control { min-height: 90px !important; resize: vertical !important; }

.form-select {
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23b8aee8' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e") !important;
  background-repeat: no-repeat !important;
  background-position: right .75rem center !important;
  background-size: 12px !important;
  padding-right: 2.2rem !important;
}
.form-select option { background: #241850 !important; color: #f0ecff !important; }

.input-group-text {
  background: rgba(255,255,255,.08) !important;
  border: 1.5px solid rgba(255,255,255,.22) !important;
  color: #b8aee8 !important;
}
.input-group > .form-control, .input-group > .form-select { border-left: none !important; }
.input-group > .input-group-text { border-right: none !important; }

.form-check-input {
  background-color: rgba(255,255,255,.1) !important;
  border: 1.5px solid rgba(255,255,255,.28) !important;
}
.form-check-input:checked { background-color: #7C3AED !important; border-color: #7C3AED !important; }
.form-check-label { color: #f0ecff !important; font-size: .85rem !important; font-weight: 500 !important; }
.form-text        { color: #b8aee8 !important; font-size: .78rem !important; }
.invalid-feedback { color: #FCA5A5 !important; }

.card { background: #241850 !important; border: 1px solid rgba(167,139,250,.18) !important; border-radius: 14px !important; }
.card-header { background: rgba(255,255,255,.04) !important; border-bottom: 1px solid rgba(167,139,250,.15) !important; color: #f0ecff !important; font-weight: 700 !important; border-radius: 14px 14px 0 0 !important; }
.card-body   { background: transparent !important; }

h1,h2,h3,h4,h5,h6 { color: #f0ecff !important; }
p { color: #b8aee8 !important; }
.text-muted     { color: #b8aee8 !important; }
.text-secondary { color: #b8aee8 !important; }
.text-dark      { color: #f0ecff !important; }
.text-body      { color: #f0ecff !important; }
small           { color: #b8aee8 !important; }
li, dt, dd      { color: #f0ecff !important; }
td, th          { color: #f0ecff !important; }

.modal-content { background: #241850 !important; border: 1px solid rgba(167,139,250,.25) !important; border-radius: 16px !important; color: #f0ecff !important; }
.modal-header  { background: rgba(255,255,255,.04) !important; border-bottom: 1px solid rgba(167,139,250,.15) !important; border-radius: 16px 16px 0 0 !important; }
.modal-title   { color: #f0ecff !important; font-weight: 700 !important; }
.modal-header .btn-close { filter: invert(1) opacity(.6) !important; }
.modal-body    { background: transparent !important; color: #f0ecff !important; }
.modal-footer  { background: rgba(255,255,255,.025) !important; border-top: 1px solid rgba(167,139,250,.12) !important; border-radius: 0 0 16px 16px !important; }

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

.table { color: #f0ecff !important; --bs-table-bg: transparent !important; }
.table thead th { background: rgba(255,255,255,.05) !important; color: #b8aee8 !important; border-bottom: 1.5px solid rgba(167,139,250,.18) !important; font-size: .72rem !important; font-weight: 700 !important; text-transform: uppercase !important; letter-spacing: .7px !important; }
.table tbody tr { border-bottom: 1px solid rgba(167,139,250,.1) !important; }
.table tbody tr:hover { background: rgba(167,139,250,.07) !important; }
.table tbody td { color: #f0ecff !important; vertical-align: middle !important; border: none !important; }

.bg-success{background:rgba(16,185,129,.22)!important;color:#6EE7B7!important}
.bg-danger {background:rgba(239,68,68,.2)!important;color:#FCA5A5!important}
.bg-warning{background:rgba(245,158,11,.2)!important;color:#FDE68A!important}
.bg-info   {background:rgba(6,182,212,.2)!important;color:#67E8F9!important}
.bg-primary{background:rgba(124,58,237,.25)!important;color:#C4B5FD!important}
.bg-secondary{background:rgba(255,255,255,.12)!important;color:#b8aee8!important}
.text-success{color:#6EE7B7!important} .text-danger{color:#FCA5A5!important}
.text-warning{color:#FDE68A!important} .text-info{color:#67E8F9!important}
.text-primary{color:#C4B5FD!important}

.alert-success{background:rgba(16,185,129,.12)!important;border-left-color:#10B981!important;color:#6EE7B7!important}
.alert-danger {background:rgba(239,68,68,.12)!important;border-left-color:#EF4444!important;color:#FCA5A5!important}
.alert-warning{background:rgba(245,158,11,.12)!important;border-left-color:#F59E0B!important;color:#FDE68A!important}
.alert-info   {background:rgba(6,182,212,.12)!important;border-left-color:#06B6D4!important;color:#67E8F9!important}

hr { border-color: rgba(167,139,250,.15) !important; opacity: 1 !important; }
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

  <a href="<?= BASE_URL ?>/admin/index.php" class="sidebar-logo">
    <div class="sidebar-logo-icon"><i class="bi bi-shield-check"></i></div>
    <div class="sidebar-logo-text">Stockora <span style="background:linear-gradient(135deg,#7C3AED,#06B6D4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;font-weight:900;">AI</span><small>Super Admin Panel</small></div>
  </a>

  <div class="sidebar-nav">

<div class="nav-section-label">Overview</div>
    <a href="<?= BASE_URL ?>/admin/index.php" class="nav-item-link <?= $activePage==='dashboard'?'active':'' ?>">
      <i class="bi bi-speedometer2 nav-icon"></i>Dashboard
    </a>
    <a href="<?= BASE_URL ?>/admin/shops.php" class="nav-item-link <?= $activePage==='shops'?'active':'' ?>">
      <i class="bi bi-shop nav-icon"></i>Manage Shops
    </a>

    <div class="nav-section-label">Billing</div>
    <a href="<?= BASE_URL ?>/admin/subscriptions.php" class="nav-item-link <?= $activePage==='subscriptions'?'active':'' ?>" style="position:relative;">
      <i class="bi bi-calendar-check nav-icon"></i>Subscriptions
      <?php if ($expiringSoon > 0): ?>
      <span class="nav-badge" style="background:#ff9f43;"><?= $expiringSoon ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/admin/plans.php" class="nav-item-link <?= $activePage==='plans'?'active':'' ?>">
      <i class="bi bi-tags nav-icon"></i>Plans Management
    </a>
    <a href="<?= BASE_URL ?>/admin/payments.php" class="nav-item-link <?= $activePage==='payments'?'active':'' ?>">
      <i class="bi bi-cash-coin nav-icon"></i>Payments
    </a>
    <a href="<?= BASE_URL ?>/admin/subscription_calendar.php" class="nav-item-link <?= $activePage==='sub_calendar'?'active':'' ?>">
      <i class="bi bi-calendar3 nav-icon"></i>Sub Calendar
    </a>
    <a href="<?= BASE_URL ?>/admin/expiry_alerts.php" class="nav-item-link <?= $activePage==='expiry_alerts'?'active':'' ?>" style="position:relative;">
      <i class="bi bi-bell-fill nav-icon"></i>Expiry Alerts
      <?php if ($expiringSoon > 0): ?>
      <span class="nav-badge" style="background:#ea5455;"><?= $expiringSoon ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-section-label">Analytics</div>
    <a href="<?= BASE_URL ?>/admin/analytics.php" class="nav-item-link <?= $activePage==='analytics'?'active':'' ?>">
      <i class="bi bi-graph-up-arrow nav-icon"></i>Platform Analytics
    </a>
    <a href="<?= BASE_URL ?>/admin/revenue.php" class="nav-item-link <?= $activePage==='revenue'?'active':'' ?>">
      <i class="bi bi-bar-chart-line nav-icon"></i>Revenue Reports
    </a>
    <a href="<?= BASE_URL ?>/admin/top_shops.php" class="nav-item-link <?= $activePage==='top_shops'?'active':'' ?>">
      <i class="bi bi-trophy nav-icon"></i>Top Shops
    </a>
    <a href="<?= BASE_URL ?>/admin/feature_usage.php" class="nav-item-link <?= $activePage==='feature_usage'?'active':'' ?>">
      <i class="bi bi-bar-chart-line-fill nav-icon"></i>Feature Usage
    </a>
    <a href="<?= BASE_URL ?>/admin/platform_health.php" class="nav-item-link <?= $activePage==='platform_health'?'active':'' ?>">
      <i class="bi bi-heart-pulse-fill nav-icon"></i>Platform Health
    </a>

    <div class="nav-section-label">Tools</div>
    <a href="<?= BASE_URL ?>/admin/announcements.php" class="nav-item-link <?= $activePage==='announcements'?'active':'' ?>">
      <i class="bi bi-megaphone nav-icon"></i>Announcements
      <?php if ($pendingAnnouncements > 0): ?>
      <span class="nav-badge" style="background:#28c76f;"><?= $pendingAnnouncements ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/admin/invoice_generator.php" class="nav-item-link <?= $activePage==='invoice_gen'?'active':'' ?>">
      <i class="bi bi-receipt-cutoff nav-icon"></i>Invoice Generator
    </a>
    <a href="<?= BASE_URL ?>/admin/activity_log.php" class="nav-item-link <?= $activePage==='activity_log'?'active':'' ?>">
      <i class="bi bi-activity nav-icon"></i>Activity Log
    </a>
    <a href="<?= BASE_URL ?>/admin/shops_export.php" class="nav-item-link <?= $activePage==='shops_export'?'active':'' ?>">
      <i class="bi bi-download nav-icon"></i>Export Data
    </a>

    <div class="nav-section-label">System</div>
    <a href="<?= BASE_URL ?>/admin/settings.php" class="nav-item-link <?= $activePage==='settings'?'active':'' ?>">
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
        <div class="sidebar-user-name"><?= htmlspecialchars($adminName) ?></div>
        <div class="sidebar-user-role">Super Administrator</div>
      </div>
    </div>
  </div>
  <div class="sidebar-version">Stockora AI v2.0 · Admin Panel</div>

</nav>

<!-- ════════════ MAIN CONTENT ════════════ -->
<div class="main-content">

  <!-- TOPBAR -->
  <header class="topbar">
    <button class="topbar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle menu">
      <i class="bi bi-list"></i>
    </button>

    <div class="topbar-title">
      <?= htmlspecialchars(mb_strimwidth($pageTitle, 0, 28, '…')) ?>
    </div>

    <div class="topbar-actions ms-auto">

      <!-- Expiry alert bell -->
      <?php if ($expiringSoon > 0): ?>
      <a href="<?= BASE_URL ?>/admin/expiry_alerts.php" class="topbar-btn" title="<?= $expiringSoon ?> expiring soon" style="position:relative;color:#ff9f43;">
        <i class="bi bi-bell-fill"></i>
        <span style="position:absolute;top:-3px;right:-3px;min-width:15px;height:15px;border-radius:8px;background:#ea5455;color:#fff;font-size:.52rem;font-weight:800;display:flex;align-items:center;justify-content:center;padding:0 2px;"><?= $expiringSoon ?></span>
      </a>
      <?php endif; ?>

      <!-- Active shops pill (lg+) -->
      <span class="shops-pill d-none d-lg-inline-flex">
        <span class="live-dot"></span>
        <?= $stats['active_shops'] ?> Active
      </span>

      <!-- Admin chip (md+) -->
      <div class="admin-chip d-none d-md-flex">
        <div class="admin-chip-av"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="admin-chip-name"><?= htmlspecialchars($adminName) ?></div>
          <div class="admin-chip-role">Super Admin</div>
        </div>
      </div>

      <!-- Logout -->
      <a href="<?= BASE_URL ?>/logout.php" class="topbar-btn" title="Logout" style="color:#ea5455;">
        <i class="bi bi-box-arrow-right"></i>
      </a>
    </div>
  </header>

  <div class="page-content">
<?php } ?>

<?php function adminFooter(): void { ?>
  </div><!-- /page-content -->
</div><!-- /main-content -->

<!-- ════════════ MOBILE BOTTOM NAV (Admin) ════════════ -->
<nav class="mobile-bottom-nav" id="mobileBottomNav">
  <a href="<?= BASE_URL ?>/admin/index.php"         id="mbn-dashboard">    <i class="bi bi-speedometer2"></i><span>Home</span></a>
  <a href="<?= BASE_URL ?>/admin/shops.php"         id="mbn-shops">        <i class="bi bi-shop"></i><span>Shops</span></a>
  <a href="<?= BASE_URL ?>/admin/subscriptions.php" id="mbn-subs">         <i class="bi bi-calendar-check"></i><span>Subs</span></a>
  <a href="<?= BASE_URL ?>/admin/payments.php"      id="mbn-payments">     <i class="bi bi-cash-coin"></i><span>Payments</span></a>
  <a href="#" onclick="toggleSidebar();return false;"  id="mbn-more">       <i class="bi bi-grid-3x3-gap"></i><span>More</span></a>
</nav>

</div><!-- /app-wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=3"></script>
<script>
// Highlight active bottom nav item
(function(){
  const path = window.location.pathname;
  const map = {
    '/admin/index.php':         'mbn-dashboard',
    '/admin/shops.php':         'mbn-shops',
    '/admin/subscriptions.php': 'mbn-subs',
    '/admin/payments.php':      'mbn-payments',
  };
  for (const [p, id] of Object.entries(map)) {
    if (path === p || path.startsWith(p.replace('.php',''))) {
      const el = document.getElementById(id);
      if (el) el.classList.add('active');
    }
  }
})();
</script>
</body>
</html>
<?php } ?>

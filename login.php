<?php
require_once 'includes/functions.php';
startSession();

if (isAdminLoggedIn()) { header('Location:' . BASE_URL .'/admin/index.php'); exit; }
if (isShopLoggedIn())  { header('Location:' . BASE_URL .'/shop/index.php');  exit; }

$role  = $_GET['role'] ?? 'shop';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email     = trim($_POST['email']    ?? '');
    $password  = $_POST['password']      ?? '';
    $loginRole = $_POST['role']          ?? 'shop';

    if (empty($email) || empty($password)) {
        $error = 'Please enter email and password.';
    } else {
        $db = getDB();
        if ($loginRole === 'admin') {
            $stmt = $db->prepare("SELECT * FROM admins WHERE email=? LIMIT 1");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();
            if ($admin && verifyPassword($password, $admin['password'])) {
                $_SESSION['admin_id']    = $admin['id'];
                $_SESSION['admin_name']  = $admin['name'];
                $_SESSION['admin_role']  = $admin['role'];
                $_SESSION['admin_email'] = $admin['email'];
                header('Location: ' . BASE_URL . '/admin/index.php'); exit;
            } else { $error = 'Invalid admin credentials.'; }
        } else {
            $stmt = $db->prepare("SELECT u.*,s.name as shop_name,s.status as shop_status FROM users u JOIN shops s ON u.shop_id=s.id WHERE u.email=? AND u.status='active' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && verifyPassword($password, $user['password'])) {
                if ($user['shop_status'] !== 'active') {
                    $error = 'Your shop is suspended. Contact admin.';
                } else {
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['shop_id']   = $user['shop_id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['shop_name'] = $user['shop_name'];
                    $db->prepare("UPDATE users SET last_login=CURRENT_TIMESTAMP WHERE id=?")->execute([$user['id']]);
                    header('Location: ' . BASE_URL . '/shop/index.php'); exit;
                }
            } else { $error = 'Invalid credentials or account inactive.'; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/stockora-favicon.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stockora AI — Sign In</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --pr:#7C3AED;--pr2:#8B5CF6;--pr3:#A78BFA;
  --sc:#06B6D4;--sc2:#22D3EE;
  --acc:#F59E0B;
  --dk:#05050F;--dk2:#0a0a1f;--dk3:#0f0f2a;
  --text-dim:rgba(255,255,255,.5);
  --border-glass:rgba(255,255,255,.08);
  --glass:rgba(255,255,255,.04);

/* ── Custom Scrollbar ── */
::-webkit-scrollbar{width:6px}
::-webkit-scrollbar-track{background:#05050F}
::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--pr),var(--pr3));border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:linear-gradient(180deg,var(--pr2),var(--sc))}
* { scrollbar-width:thin; scrollbar-color:var(--pr) #05050F; }
  --card-bg:rgba(10,10,35,.75);
}
html,body{height:100%;font-family:'Inter',system-ui,-apple-system,sans-serif;overflow-x:hidden}
body{background:var(--dk);display:flex;align-items:stretch;min-height:100vh}

/* ══════════ ANIMATED BACKGROUND ══════════ */
.bg-canvas{
  position:fixed;inset:0;z-index:0;overflow:hidden;
  background:radial-gradient(ellipse 80% 80% at 20% -20%,rgba(124,58,237,.18) 0%,transparent 55%),
             radial-gradient(ellipse 60% 60% at 80% 120%,rgba(6,182,212,.14) 0%,transparent 55%),
             linear-gradient(160deg,#05050f 0%,#080818 40%,#060c1a 100%);
}

/* Particle dots */
.particles{position:absolute;inset:0;overflow:hidden}
.particle{
  position:absolute;border-radius:50%;
  animation:particleDrift linear infinite;
  opacity:0;
}
@keyframes particleDrift{
  0%  {opacity:0;transform:translateY(0) scale(0)}
  10% {opacity:1}
  90% {opacity:.6}
  100%{opacity:0;transform:translateY(-120vh) scale(1.5)}
}

/* Glow orbs */
.orb{position:absolute;border-radius:50%;filter:blur(100px);pointer-events:none}
.orb-a{width:600px;height:600px;background:radial-gradient(circle,rgba(124,58,237,.28),transparent 65%);top:-150px;left:-150px;animation:orbPulse 14s ease-in-out infinite}
.orb-b{width:500px;height:500px;background:radial-gradient(circle,rgba(6,182,212,.2),transparent 65%);bottom:-150px;right:-100px;animation:orbPulse 17s ease-in-out infinite;animation-delay:-6s}
.orb-c{width:350px;height:350px;background:radial-gradient(circle,rgba(139,92,246,.16),transparent 65%);top:45%;left:35%;animation:orbPulse 11s ease-in-out infinite;animation-delay:-3s}
@keyframes orbPulse{0%,100%{transform:scale(1) translate(0,0)}50%{transform:scale(1.12) translate(25px,-25px)}}

/* Noise texture */
.noise{position:absolute;inset:0;opacity:.025;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");background-repeat:repeat;background-size:180px 180px}

/* Fine grid overlay */
.grid-veil{
  position:fixed;inset:0;z-index:0;
  background-image:
    linear-gradient(rgba(124,58,237,.025) 1px,transparent 1px),
    linear-gradient(90deg,rgba(124,58,237,.025) 1px,transparent 1px);
  background-size:60px 60px;
}

/* ══════════ LAYOUT ══════════ */
.page-wrap{position:relative;z-index:1;display:flex;width:100%;min-height:100vh}

/* ══════════ LEFT PANEL ══════════ */
.left-panel{
  flex:0 0 46%;max-width:46%;
  display:flex;flex-direction:column;justify-content:space-between;
  padding:3rem 3rem 2.5rem;
  border-right:1px solid rgba(124,58,237,.12);
  position:relative;overflow:hidden;
}
.left-panel::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,transparent,var(--pr),var(--sc),transparent);
  opacity:.7;
}

/* Brand */
.brand-wrap{margin-bottom:3.5rem}
.brand-row{display:flex;align-items:center;gap:1rem;margin-bottom:.75rem}
.brand-icon{
  width:52px;height:52px;border-radius:14px;
  background:linear-gradient(135deg,var(--pr),var(--sc));
  display:flex;align-items:center;justify-content:center;
  font-size:1.45rem;color:#fff;
  box-shadow:0 0 0 1px rgba(124,58,237,.3),0 8px 32px rgba(124,58,237,.5);
  position:relative;
}
.brand-icon::after{
  content:'';position:absolute;inset:-3px;border-radius:17px;
  background:linear-gradient(135deg,var(--pr),var(--sc));
  z-index:-1;opacity:.2;filter:blur(6px);
}
.brand-name{
  font-size:1.85rem;font-weight:900;color:#fff;letter-spacing:-1px;line-height:1;
}
.brand-name .ai-badge{
  background:linear-gradient(135deg,var(--pr),var(--sc));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
  font-weight:900;
}
.brand-tagline{
  font-size:.78rem;color:var(--text-dim);letter-spacing:.8px;font-weight:500;
  text-transform:uppercase;
}

/* Stats strip */
.stats-strip{
  display:flex;gap:1rem;margin-bottom:3rem;
}
.stat-chip{
  flex:1;background:var(--glass);border:1px solid var(--border-glass);
  border-radius:12px;padding:.8rem 1rem;text-align:center;
}
.stat-chip-num{font-size:1.25rem;font-weight:800;color:#fff;line-height:1}
.stat-chip-lbl{font-size:.65rem;color:var(--text-dim);font-weight:500;text-transform:uppercase;letter-spacing:.5px;margin-top:.2rem}

/* Feature cards */
.features-grid{display:flex;flex-direction:column;gap:.65rem}
.feature-card{
  display:flex;align-items:center;gap:1rem;
  padding:.9rem 1.1rem;
  background:var(--glass);
  border:1px solid var(--border-glass);
  border-radius:14px;
  transition:all .3s cubic-bezier(.25,.8,.25,1);
  cursor:default;
}
.feature-card:hover{
  background:rgba(124,58,237,.1);
  border-color:rgba(124,58,237,.3);
  transform:translateX(6px);
  box-shadow:0 4px 20px rgba(124,58,237,.15);
}
.f-icon{
  width:40px;height:40px;flex-shrink:0;
  border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;
}
.f-text h6{margin:0;font-size:.83rem;font-weight:700;color:#fff}
.f-text p{margin:0;font-size:.72rem;color:var(--text-dim);line-height:1.4;margin-top:.15rem}

/* Left footer */
.left-bottom{
  padding-top:2rem;
  border-top:1px solid var(--border-glass);
}
.trust-badges{display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:.75rem}
.trust-badge{
  display:inline-flex;align-items:center;gap:.35rem;
  font-size:.67rem;color:var(--text-dim);font-weight:600;
  background:var(--glass);border:1px solid var(--border-glass);
  border-radius:20px;padding:.25rem .65rem;
}
.trust-badge i{font-size:.75rem;color:var(--sc)}
.left-copyright{font-size:.68rem;color:rgba(255,255,255,.18);line-height:1.6}

/* ══════════ RIGHT PANEL ══════════ */
.right-panel{
  flex:1;
  display:flex;align-items:center;justify-content:center;
  padding:2.5rem 2rem;
}
.login-card{
  width:100%;max-width:430px;
  background:var(--card-bg);
  backdrop-filter:blur(32px) saturate(1.3);
  -webkit-backdrop-filter:blur(32px) saturate(1.3);
  border:1px solid rgba(124,58,237,.2);
  border-radius:28px;
  padding:2.8rem 2.4rem;
  box-shadow:
    0 0 0 1px rgba(255,255,255,.04),
    0 25px 60px rgba(0,0,0,.55),
    0 0 80px rgba(124,58,237,.08);
  position:relative;overflow:hidden;
}
.login-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent 5%,var(--pr) 30%,var(--sc) 70%,transparent 95%);
  opacity:.8;
}
.login-card::after{
  content:'';position:absolute;top:-60px;right:-60px;
  width:200px;height:200px;
  background:radial-gradient(circle,rgba(124,58,237,.12),transparent 70%);
  pointer-events:none;
}

/* Card header */
.card-top{text-align:center;margin-bottom:2rem}
.card-top-icon{
  width:56px;height:56px;border-radius:16px;margin:0 auto .9rem;
  background:linear-gradient(135deg,rgba(124,58,237,.25),rgba(6,182,212,.2));
  border:1px solid rgba(124,58,237,.3);
  display:flex;align-items:center;justify-content:center;
  font-size:1.5rem;color:var(--pr3);
}
.card-top h2{font-size:1.55rem;font-weight:800;color:#fff;letter-spacing:-.5px;margin-bottom:.3rem}
.card-top p{font-size:.82rem;color:var(--text-dim)}

/* Role switcher */
.role-tabs{
  display:flex;background:rgba(0,0,0,.35);border-radius:14px;padding:4px;
  margin-bottom:1.8rem;gap:4px;
  border:1px solid rgba(255,255,255,.07);
}
.role-tab{
  flex:1;padding:.62rem 1rem;border:none;background:transparent;
  color:rgba(255,255,255,.4);font-size:.8rem;font-weight:600;
  border-radius:10px;cursor:pointer;transition:all .25s;
  display:flex;align-items:center;justify-content:center;gap:.42rem;
  letter-spacing:.2px;
}
.role-tab.active{
  background:linear-gradient(135deg,var(--pr),var(--pr2));
  color:#fff;box-shadow:0 4px 18px rgba(124,58,237,.45);
}
.role-tab:not(.active):hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.7)}

/* Form */
.field-group{margin-bottom:1.2rem}
.field-label{
  display:flex;align-items:center;gap:.35rem;
  font-size:.7rem;font-weight:700;
  color:rgba(255,255,255,.45);margin-bottom:.5rem;
  letter-spacing:.6px;text-transform:uppercase;
}
.field-wrap{position:relative}
.field-icon{
  position:absolute;left:.95rem;top:50%;transform:translateY(-50%);
  color:rgba(255,255,255,.25);font-size:.9rem;pointer-events:none;
  transition:color .2s;z-index:2;
}
.field-input{
  width:100%;padding:.78rem 1rem .78rem 2.65rem;
  background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.1);
  border-radius:12px;color:#fff;font-size:.88rem;
  outline:none;transition:all .25s;font-family:'Inter',sans-serif;
}
.field-input::placeholder{color:rgba(255,255,255,.18)}
.field-input:focus{
  border-color:var(--pr2);
  background:rgba(124,58,237,.1);
  box-shadow:0 0 0 4px rgba(124,58,237,.15),0 2px 12px rgba(124,58,237,.1);
}
.field-wrap:focus-within .field-icon{color:var(--pr3)}

.toggle-pw{
  position:absolute;right:.9rem;top:50%;transform:translateY(-50%);
  background:none;border:none;color:rgba(255,255,255,.25);
  cursor:pointer;padding:.2rem;transition:color .2s;font-size:.9rem;z-index:2;
}
.toggle-pw:hover{color:rgba(255,255,255,.65)}

/* Error */
.login-error{
  background:rgba(239,68,68,.1);
  border:1px solid rgba(239,68,68,.3);
  border-radius:11px;padding:.75rem 1rem;
  color:#fca5a5;font-size:.81rem;
  display:flex;align-items:center;gap:.5rem;
  margin-bottom:1.2rem;
  animation:slideDown .3s ease;
}
@keyframes slideDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}

/* Login button */
.btn-login{
  width:100%;padding:.9rem 1rem;
  background:linear-gradient(135deg,var(--pr) 0%,var(--pr2) 60%,#9F7AEA 100%);
  border:none;border-radius:13px;color:#fff;
  font-size:.9rem;font-weight:700;letter-spacing:.3px;
  cursor:pointer;transition:all .3s;
  box-shadow:0 4px 24px rgba(124,58,237,.5),0 1px 3px rgba(0,0,0,.3);
  margin-top:.5rem;
  display:flex;align-items:center;justify-content:center;gap:.5rem;
  font-family:'Inter',sans-serif;
  position:relative;overflow:hidden;
}
.btn-login::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(135deg,rgba(255,255,255,.12),transparent);
  border-radius:13px;
}
.btn-login:hover{transform:translateY(-2px);box-shadow:0 8px 32px rgba(124,58,237,.65)}
.btn-login:active{transform:translateY(0)}
.btn-login:disabled{opacity:.65;cursor:not-allowed;transform:none}
.btn-login .spinner-border{width:.95rem;height:.95rem;border-width:2px}

/* Demo box */
.demo-box{
  margin-top:1.5rem;
  background:rgba(6,182,212,.05);
  border:1px solid rgba(6,182,212,.15);
  border-radius:14px;padding:1rem;
}
.demo-box-title{
  font-size:.67rem;color:var(--text-dim);margin:0 0 .65rem;
  text-transform:uppercase;font-weight:700;letter-spacing:.6px;
  display:flex;align-items:center;gap:.35rem;
}
.demo-item{
  display:flex;align-items:center;justify-content:space-between;
  padding:.45rem .7rem;border-radius:9px;margin-bottom:.35rem;
  background:rgba(255,255,255,.04);cursor:pointer;
  transition:all .2s;border:1px solid transparent;
}
.demo-item:last-child{margin-bottom:0}
.demo-item:hover{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.1)}
.demo-item span{font-size:.73rem;color:rgba(255,255,255,.65)}
.demo-item .badge-role{font-size:.62rem;padding:.2rem .55rem;border-radius:20px;font-weight:700}

/* Divider */
.form-divider{
  display:flex;align-items:center;gap:.75rem;margin:.75rem 0;
}
.form-divider::before,.form-divider::after{
  content:'';flex:1;height:1px;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.1),transparent);
}
.form-divider span{font-size:.67rem;color:var(--text-dim);font-weight:600;letter-spacing:.5px;white-space:nowrap}

/* Card shine sweep */
.login-card{transition:box-shadow .3s}
.login-card:hover{box-shadow:0 30px 80px rgba(0,0,0,.65),0 0 0 1px rgba(124,58,237,.25),0 0 100px rgba(124,58,237,.1)!important}

/* Back to home */
.back-home{
  display:inline-flex;align-items:center;gap:.4rem;
  font-size:.75rem;color:rgba(255,255,255,.35);text-decoration:none;
  position:fixed;top:1.2rem;left:1.5rem;z-index:100;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);
  border-radius:30px;padding:.35rem .85rem;
  transition:all .25s;
}
.back-home:hover{color:#A78BFA;background:rgba(124,58,237,.12);border-color:rgba(167,139,250,.2)}

/* Typing effect text */
.typing-text{color:var(--sc2);font-weight:600}

/* Input focus glow enhanced */
.field-input:focus{
  border-color:var(--pr2)!important;
  background:rgba(124,58,237,.1)!important;
  box-shadow:0 0 0 3px rgba(124,58,237,.2),0 2px 12px rgba(124,58,237,.15)!important;
}

/* ══════════ RESPONSIVE ══════════ */
@media(max-width:860px){.left-panel{display:none}.right-panel{padding:2rem 1.2rem}}
@media(max-width:480px){.login-card{padding:2rem 1.4rem;border-radius:22px}}
</style>
</head>
<body>

<!-- Background -->
<a href="<?= BASE_URL ?>/landing.php" class="back-home"><i class="bi bi-arrow-left"></i> Back to Home</a>
<div class="bg-canvas">
  <div class="orb orb-a"></div>
  <div class="orb orb-b"></div>
  <div class="orb orb-c"></div>
  <div class="noise"></div>
  <div class="particles" id="particles"></div>
</div>
<div class="grid-veil"></div>

<div class="page-wrap">

  <!-- ══ LEFT PANEL ══ -->
  <div class="left-panel">

    <!-- Brand -->
    <div class="brand-wrap">
      <div class="brand-row">
        <div class="brand-icon"><i class="bi bi-cpu-fill"></i></div>
        <div>
          <div class="brand-name">Stockora&nbsp;<span class="ai-badge">AI</span></div>
        </div>
      </div>
      <div class="brand-tagline"><span id="typingText">Intelligent POS &amp; Inventory Platform</span></div>
    </div>

    <!-- Mini stats -->
    <div class="stats-strip">
      <div class="stat-chip">
        <div class="stat-chip-num">POS</div>
        <div class="stat-chip-lbl">Billing</div>
      </div>
      <div class="stat-chip">
        <div class="stat-chip-num">AI</div>
        <div class="stat-chip-lbl">Smart Lab</div>
      </div>
      <div class="stat-chip">
        <div class="stat-chip-num">∞</div>
        <div class="stat-chip-lbl">Analytics</div>
      </div>
    </div>

    <!-- Features -->
    <div class="features-grid">
      <div class="feature-card">
        <div class="f-icon" style="background:rgba(124,58,237,.2)">
          <i class="bi bi-cart3" style="color:#A78BFA"></i>
        </div>
        <div class="f-text">
          <h6>Point of Sale Billing</h6>
          <p>Instant retail &amp; wholesale invoicing with multi-payment support</p>
        </div>
      </div>
      <div class="feature-card">
        <div class="f-icon" style="background:rgba(6,182,212,.15)">
          <i class="bi bi-box-seam" style="color:#22D3EE"></i>
        </div>
        <div class="f-text">
          <h6>Smart Inventory Control</h6>
          <p>Real-time stock tracking, low-stock alerts &amp; auto reorder management</p>
        </div>
      </div>
      <div class="feature-card">
        <div class="f-icon" style="background:rgba(16,185,129,.15)">
          <i class="bi bi-graph-up-arrow" style="color:#34D399"></i>
        </div>
        <div class="f-text">
          <h6>Analytics &amp; Reports</h6>
          <p>Daily Z-reports, profit analysis, sales trends &amp; KPI dashboard</p>
        </div>
      </div>
      <div class="feature-card">
        <div class="f-icon" style="background:rgba(245,158,11,.15)">
          <i class="bi bi-people" style="color:#FCD34D"></i>
        </div>
        <div class="f-text">
          <h6>Customer Management</h6>
          <p>Credit &amp; dues tracking, bulk buyer profiles, full payment history</p>
        </div>
      </div>
      <div class="feature-card">
        <div class="f-icon" style="background:rgba(236,72,153,.15)">
          <i class="bi bi-robot" style="color:#F472B6"></i>
        </div>
        <div class="f-text">
          <h6>AI Smart Lab</h6>
          <p>AI-powered insights, demand forecasting &amp; intelligent business tips</p>
        </div>
      </div>
    </div>

    <!-- Bottom -->
    <div class="left-bottom">
      <div class="trust-badges">
        <span class="trust-badge"><i class="bi bi-shield-check"></i>Secure Login</span>
        <span class="trust-badge"><i class="bi bi-lock-fill"></i>Encrypted Data</span>
        <span class="trust-badge"><i class="bi bi-lightning-charge-fill"></i>Fast &amp; Reliable</span>
      </div>
      <div class="left-copyright">
        © 2026 Stockora AI &nbsp;·&nbsp; v2.0 &nbsp;·&nbsp; All rights reserved
      </div>
    </div>

  </div><!-- /left-panel -->

  <!-- ══ RIGHT PANEL ══ -->
  <div class="right-panel">
    <div class="login-card">

      <div class="card-top">
        <div class="card-top-icon"><i class="bi bi-person-lock"></i></div>
        <h2>Welcome Back</h2>
        <p>Sign in to your Stockora AI dashboard</p>
      </div>

      <!-- Role Switcher -->
      <div class="role-tabs" id="roleTabs">
        <button class="role-tab <?= ($role !== 'admin') ? 'active' : '' ?>" onclick="setRole('shop')" id="tab-shop">
          <i class="bi bi-shop-window"></i> Shop Owner
        </button>
        <button class="role-tab <?= ($role === 'admin') ? 'active' : '' ?>" onclick="setRole('admin')" id="tab-admin">
          <i class="bi bi-shield-check"></i> Super Admin
        </button>
      </div>

      <!-- Error Message -->
      <?php if ($error): ?>
      <div class="login-error">
        <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <!-- Login Form -->
      <form method="POST" action="" id="loginForm" onsubmit="handleSubmit(this)">
        <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($role === 'admin' ? 'admin' : 'shop') ?>">

        <div class="field-group">
          <label class="field-label"><i class="bi bi-envelope" style="font-size:.8rem;"></i> Email Address</label>
          <div class="field-wrap">
            <span class="field-icon"><i class="bi bi-envelope"></i></span>
            <input type="email" name="email" class="field-input"
                   placeholder="Enter your email"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   required autocomplete="email">
          </div>
        </div>

        <div class="field-group">
          <label class="field-label"><i class="bi bi-lock" style="font-size:.8rem;"></i> Password</label>
          <div class="field-wrap">
            <span class="field-icon"><i class="bi bi-lock"></i></span>
            <input type="password" name="password" class="field-input" id="pwInput"
                   placeholder="Enter your password"
                   required autocomplete="current-password">
            <button type="button" class="toggle-pw" onclick="togglePw()" title="Show/Hide password">
              <i class="bi bi-eye" id="pwEye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-login" id="loginBtn">
          <i class="bi bi-arrow-right-circle-fill"></i>
          <span id="btnText">Sign In to Stockora AI</span>
          <span class="spinner-border d-none" id="spinner"></span>
        </button>
      </form>

      <div class="form-divider"><span>Demo Access</span></div>

      <!-- Demo Credentials -->
      <div class="demo-box">
        <div class="demo-box-title"><i class="bi bi-info-circle"></i> Quick Demo Credentials</div>
        <div class="demo-item" onclick="fillDemo('ahmed@demo.com','shop123','shop')">
          <span><i class="bi bi-shop me-1" style="color:#22D3EE"></i><strong style="color:rgba(255,255,255,.82)">Shop Owner:</strong> ahmed@demo.com / shop123</span>
          <span class="badge-role" style="background:rgba(6,182,212,.18);color:#22D3EE;border:1px solid rgba(6,182,212,.3)">Shop</span>
        </div>
        <div class="demo-item" onclick="fillDemo('admin@stockora.com','admin123','admin')">
          <span><i class="bi bi-shield me-1" style="color:#FCD34D"></i><strong style="color:rgba(255,255,255,.82)">Admin:</strong> admin@stockora.com / admin123</span>
          <span class="badge-role" style="background:rgba(245,158,11,.18);color:#FCD34D;border:1px solid rgba(245,158,11,.3)">Admin</span>
        </div>
      </div>

    </div>
  </div><!-- /right-panel -->

</div><!-- /page-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
var currentRole = '<?= htmlspecialchars($role === 'admin' ? 'admin' : 'shop') ?>';

function setRole(r) {
  currentRole = r;
  document.getElementById('roleInput').value = r;
  document.getElementById('tab-shop').classList.toggle('active', r === 'shop');
  document.getElementById('tab-admin').classList.toggle('active', r === 'admin');
}

function togglePw() {
  var inp = document.getElementById('pwInput');
  var eye = document.getElementById('pwEye');
  if (inp.type === 'password') {
    inp.type = 'text';
    eye.className = 'bi bi-eye-slash';
  } else {
    inp.type = 'password';
    eye.className = 'bi bi-eye';
  }
}

function fillDemo(email, pw, role) {
  document.querySelector('input[name="email"]').value = email;
  document.getElementById('pwInput').value = pw;
  setRole(role);
}

function handleSubmit(form) {
  var btn  = document.getElementById('loginBtn');
  var txt  = document.getElementById('btnText');
  var spin = document.getElementById('spinner');
  btn.disabled = true;
  txt.textContent = 'Signing in...';
  spin.classList.remove('d-none');
}

/* ── Floating Particles ── */
(function(){
  var c = document.getElementById('particles');
  if (!c) return;
  var colors = ['rgba(124,58,237,.7)','rgba(6,182,212,.7)','rgba(139,92,246,.6)','rgba(34,211,238,.5)'];
  for (var i = 0; i < 28; i++) {
    var p = document.createElement('div');
    p.className = 'particle';
    var sz = Math.random() * 3 + 1.5;
    p.style.cssText = [
      'width:' + sz + 'px',
      'height:' + sz + 'px',
      'left:' + (Math.random() * 100) + '%',
      'top:' + (Math.random() * 100 + 20) + '%',
      'background:' + colors[Math.floor(Math.random() * colors.length)],
      'animation-duration:' + (Math.random() * 18 + 10) + 's',
      'animation-delay:' + (Math.random() * -20) + 's',
    ].join(';');
    c.appendChild(p);
  }
})();

/* ── Typing Effect for tagline ── */
(function(){
  var el = document.getElementById('typingText');
  if (!el) return;
  var phrases = [
    'Intelligent POS & Inventory Platform',
    'AI-Powered Sales Analytics',
    'Smart Stock Management',
    'Customer Credit Tracking',
    'Daily Target Monitoring',
  ];
  var idx = 0, charIdx = 0, deleting = false;
  function type() {
    var phrase = phrases[idx];
    if (!deleting) {
      el.textContent = phrase.substring(0, charIdx + 1);
      charIdx++;
      if (charIdx === phrase.length) { deleting = true; setTimeout(type, 2200); return; }
    } else {
      el.textContent = phrase.substring(0, charIdx - 1);
      charIdx--;
      if (charIdx === 0) { deleting = false; idx = (idx + 1) % phrases.length; }
    }
    setTimeout(type, deleting ? 38 : 58);
  }
  setTimeout(type, 1200);
})();
</script>
</body>
</html>

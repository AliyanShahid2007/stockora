<?php
require_once 'includes/functions.php';
startSession();
// If already logged in, redirect to appropriate dashboard
if (isAdminLoggedIn()) { header('Location: ' . BASE_URL . '/admin/index.php'); exit; }
if (isShopLoggedIn())  { header('Location: ' . BASE_URL . '/shop/index.php');  exit; }
$landingPlans = [];
try {
    $landingDb = getDB();
    if ($landingDb->query("SHOW TABLES LIKE 'subscription_plans'")->fetchColumn()) {
        $landingPlans = $landingDb->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY sort_order, id")->fetchAll();
    }
} catch (Exception $e) { $landingPlans = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Stockora AI — Intelligent POS & Inventory Management Platform for modern businesses">
<meta name="theme-color" content="#050515">
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/stockora-favicon.png">
<title>Stockora AI — Intelligent POS & Inventory Platform</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════
   STOCKORA AI — LANDING PAGE v1.0
   Premium dark design with purple/teal/violet brand
   ══════════════════════════════════════════════════ */
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --pr:#7C3AED; --pr2:#8B5CF6; --pr3:#A78BFA; --pr4:#C4B5FD;
  --sc:#06B6D4; --sc2:#22D3EE;
  --gr:#10B981; --or:#F59E0B;
  --dk:#050515; --dk2:#080820; --dk3:#0c0c28;
  --text:#e2e8f0; --text-dim:rgba(226,232,240,.55);
  --border:rgba(139,92,246,.15);
  --glass:rgba(255,255,255,.04);
  --glass2:rgba(139,92,246,.06);
  font-family:'Inter',system-ui,-apple-system,sans-serif;
}
html{scroll-behavior:smooth}

/* ── Custom Scrollbar ── */
::-webkit-scrollbar{width:7px;height:7px}
::-webkit-scrollbar-track{background:var(--dk2)}
::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--pr),var(--pr3));border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:linear-gradient(180deg,var(--pr2),var(--sc))}
* { scrollbar-width:thin; scrollbar-color:var(--pr) var(--dk2); }

body{background:var(--dk);color:var(--text);overflow-x:hidden}

/* ══════════ BACKGROUND ══════════ */
.site-bg{
  position:fixed;inset:0;z-index:0;overflow:hidden;
  background:radial-gradient(ellipse 90% 60% at 10% -10%, rgba(124,58,237,.22) 0%,transparent 55%),
             radial-gradient(ellipse 60% 50% at 90% 110%, rgba(6,182,212,.16) 0%,transparent 50%),
             radial-gradient(ellipse 50% 40% at 50% 50%, rgba(109,40,217,.08) 0%,transparent 60%),
             linear-gradient(160deg,#050515 0%,#07071e 40%,#060616 100%);
}
.orb{position:absolute;border-radius:50%;filter:blur(120px);pointer-events:none;animation:orbFloat ease-in-out infinite}
.orb1{width:700px;height:700px;background:radial-gradient(circle,rgba(124,58,237,.2),transparent 60%);top:-200px;left:-200px;animation-duration:18s}
.orb2{width:600px;height:600px;background:radial-gradient(circle,rgba(6,182,212,.15),transparent 60%);bottom:-200px;right:-100px;animation-duration:22s;animation-delay:-8s}
.orb3{width:400px;height:400px;background:radial-gradient(circle,rgba(167,139,250,.12),transparent 60%);top:50%;left:50%;animation-duration:14s;animation-delay:-4s}
@keyframes orbFloat{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(40px,-40px) scale(1.08)}66%{transform:translate(-30px,30px) scale(.95)}}

.grid-over{
  position:fixed;inset:0;z-index:0;
  background-image:linear-gradient(rgba(139,92,246,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(139,92,246,.03) 1px,transparent 1px);
  background-size:70px 70px;
}
.noise{position:absolute;inset:0;opacity:.02;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");background-size:200px 200px}

/* ══════════ NAVBAR ══════════ */
.navbar-land{
  position:fixed;top:0;left:0;right:0;z-index:1000;
  padding:.9rem 0;
  transition:all .35s;
}
.navbar-land.scrolled{
  background:rgba(5,5,21,.88);
  backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);
  padding:.65rem 0;
  box-shadow:0 4px 30px rgba(0,0,0,.4);
}
.nav-brand{display:flex;align-items:center;gap:.75rem;text-decoration:none}
.nav-brand-icon{
  width:40px;height:40px;border-radius:11px;
  background:linear-gradient(135deg,var(--pr),var(--sc));
  display:flex;align-items:center;justify-content:center;
  font-size:1.15rem;color:#fff;
  box-shadow:0 4px 16px rgba(124,58,237,.45);
}
.nav-brand-name{font-size:1.25rem;font-weight:900;color:#fff;letter-spacing:-.5px}
.nav-brand-name .ai{
  background:linear-gradient(135deg,var(--pr3),var(--sc2));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.nav-links{display:flex;align-items:center;gap:.25rem}
.nav-link-item{
  color:rgba(255,255,255,.6)!important;font-size:.88rem;font-weight:500;
  padding:.45rem .9rem!important;border-radius:8px;transition:all .2s;
  text-decoration:none;
}
.nav-link-item:hover{color:#fff!important;background:rgba(255,255,255,.07)!important}
.nav-cta{
  background:linear-gradient(135deg,var(--pr),var(--pr2));
  color:#fff!important;font-weight:700;font-size:.85rem;
  padding:.5rem 1.2rem!important;border-radius:10px;
  box-shadow:0 4px 16px rgba(124,58,237,.4);
  transition:all .25s;text-decoration:none;
}
.nav-cta:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(124,58,237,.55);color:#fff!important}

/* ══════════ HERO ══════════ */
.hero{
  min-height:100vh;display:flex;align-items:center;
  padding:8rem 0 6rem;position:relative;z-index:1;
}
.hero-badge{
  display:inline-flex;align-items:center;gap:.5rem;
  background:rgba(124,58,237,.12);border:1px solid rgba(167,139,250,.25);
  border-radius:30px;padding:.35rem 1rem;
  font-size:.75rem;font-weight:700;color:var(--pr3);
  letter-spacing:.5px;text-transform:uppercase;
  margin-bottom:1.5rem;
  animation:fadeUp .6s ease .2s both;
}
.hero-badge i{color:var(--sc);font-size:.85rem}
.hero-title{
  font-size:clamp(2.8rem,6vw,5.5rem);font-weight:900;
  line-height:1.05;letter-spacing:-2.5px;
  color:#fff;
  animation:fadeUp .6s ease .3s both;
}
.hero-title .grad{
  background:linear-gradient(135deg,var(--pr3) 0%,var(--sc2) 50%,var(--pr4) 100%);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.hero-sub{
  font-size:1.15rem;color:var(--text-dim);line-height:1.7;
  max-width:540px;margin:1.5rem 0 2.5rem;
  animation:fadeUp .6s ease .4s both;
}
.hero-btns{
  display:flex;flex-wrap:wrap;gap:1rem;
  animation:fadeUp .6s ease .5s both;
}
.btn-hero-primary{
  display:inline-flex;align-items:center;gap:.5rem;
  background:linear-gradient(135deg,var(--pr),var(--pr2));
  color:#fff;font-size:.95rem;font-weight:700;
  padding:.85rem 1.8rem;border-radius:13px;text-decoration:none;
  box-shadow:0 6px 28px rgba(124,58,237,.5);
  transition:all .3s;border:none;cursor:pointer;
}
.btn-hero-primary:hover{transform:translateY(-3px);box-shadow:0 10px 36px rgba(124,58,237,.65);color:#fff}
.btn-hero-sec{
  display:inline-flex;align-items:center;gap:.5rem;
  background:rgba(255,255,255,.06);
  color:rgba(255,255,255,.85);font-size:.95rem;font-weight:600;
  padding:.85rem 1.8rem;border-radius:13px;text-decoration:none;
  border:1px solid rgba(255,255,255,.12);
  transition:all .3s;
}
.btn-hero-sec:hover{background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.22)}

.hero-stats{
  display:flex;justify-content:center;flex-wrap:wrap;gap:2.5rem;margin-top:3.5rem;width:100%;
  animation:fadeUp .6s ease .65s both;
}
.hstat{text-align:center}
.hstat-num{font-size:2.45rem;font-weight:900;color:#fff;line-height:1;letter-spacing:-1.2px}
.hstat-lbl{font-size:.75rem;color:var(--text-dim);font-weight:500;margin-top:.2rem}
.hstat-num .gr{background:linear-gradient(135deg,var(--pr3),var(--sc2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}

/* Hero right — video mockup */
.hero-visual{
  position:relative;
  animation:fadeLeft .8s ease .4s both;
}
.browser-mock{
  background:rgba(12,12,40,.9);
  border:1px solid var(--border);
  border-radius:16px;
  box-shadow:0 30px 80px rgba(0,0,0,.6),0 0 0 1px rgba(139,92,246,.1),0 0 60px rgba(124,58,237,.12);
  overflow:hidden;
}
.browser-bar{
  background:#0e0a28;
  padding:.6rem 1rem;
  display:flex;align-items:center;gap:.75rem;
  border-bottom:1px solid rgba(139,92,246,.12);
}
.browser-dots{display:flex;gap:.4rem}
.browser-dots span{width:10px;height:10px;border-radius:50%;display:block}
.browser-dots span:nth-child(1){background:#ff5f57}
.browser-dots span:nth-child(2){background:#ffbd2e}
.browser-dots span:nth-child(3){background:#28c840}
.browser-url{
  flex:1;background:rgba(255,255,255,.06);border-radius:6px;
  padding:.25rem .75rem;font-size:.72rem;color:rgba(255,255,255,.4);
  border:1px solid rgba(255,255,255,.07);
}
.video-container{position:relative;overflow:hidden;background:#000;}
.video-container video{width:100%;display:block;aspect-ratio:16/9;object-fit:cover}
.video-overlay-badge{
  position:absolute;bottom:12px;right:12px;
  background:rgba(5,5,21,.85);backdrop-filter:blur(12px);
  border:1px solid rgba(139,92,246,.3);
  border-radius:10px;padding:.5rem .9rem;
  display:flex;align-items:center;gap:.5rem;
  font-size:.72rem;font-weight:700;color:#A78BFA;
}
.rec-dot{width:7px;height:7px;border-radius:50%;background:#ea5455;animation:blink 1.2s ease-in-out infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}

/* Floating info cards on hero */
.float-card{
  position:absolute;
  background:rgba(10,8,35,.92);backdrop-filter:blur(16px);
  border:1px solid rgba(139,92,246,.25);border-radius:12px;
  padding:.65rem .9rem;
  display:flex;align-items:center;gap:.6rem;
  box-shadow:0 8px 32px rgba(0,0,0,.4);
  animation:floatBob 4s ease-in-out infinite;
  white-space:nowrap;
}
.float-card-1{top:-20px;left:-30px;animation-delay:0s}
.float-card-2{bottom:30px;left:-40px;animation-delay:-2s}
@keyframes floatBob{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.fc-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.fc-num{font-size:1.05rem;font-weight:800;color:#fff;line-height:1}
.fc-lbl{font-size:.62rem;color:var(--text-dim);font-weight:500}

@keyframes fadeUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeLeft{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:translateX(0)}}

/* ══════════ SECTION COMMON ══════════ */
.section{padding:6rem 0;position:relative;z-index:1}
.section-badge{
  display:inline-flex;align-items:center;gap:.45rem;
  background:var(--glass2);border:1px solid var(--border);
  border-radius:30px;padding:.3rem .9rem;
  font-size:.72rem;font-weight:700;color:var(--pr3);
  letter-spacing:.6px;text-transform:uppercase;margin-bottom:1rem;
}
.section-title{
  font-size:clamp(1.9rem,4vw,3rem);font-weight:900;
  color:#fff;letter-spacing:-1.2px;line-height:1.1;
  margin-bottom:.75rem;
}
.section-sub{font-size:1rem;color:var(--text-dim);max-width:520px;line-height:1.7}

/* ══════════ VIDEO DEMO SECTION ══════════ */
.video-section{
  padding:6rem 0;position:relative;z-index:1;
}
.video-section-wrap{
  background:rgba(12,12,32,.6);
  border:1px solid var(--border);border-radius:24px;
  padding:3rem;
  backdrop-filter:blur(12px);
  box-shadow:0 20px 60px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.05);
}
.video-full-mock{
  background:#000;border-radius:16px;overflow:hidden;
  border:1px solid rgba(139,92,246,.2);
  box-shadow:0 20px 60px rgba(0,0,0,.6);
}
.video-full-bar{
  background:#0a0820;padding:.7rem 1.2rem;
  display:flex;align-items:center;gap:.9rem;
  border-bottom:1px solid rgba(139,92,246,.1);
}
.video-full-bar .dots{display:flex;gap:.4rem}
.video-full-bar .dots span{width:11px;height:11px;border-radius:50%;display:block}
.video-full-bar .dots span:nth-child(1){background:#ff5f57}
.video-full-bar .dots span:nth-child(2){background:#ffbd2e}
.video-full-bar .dots span:nth-child(3){background:#28c840}
.video-full-bar .url-bar{
  flex:1;background:rgba(255,255,255,.05);
  border-radius:6px;padding:.28rem .85rem;
  font-size:.72rem;color:rgba(255,255,255,.35);
  border:1px solid rgba(255,255,255,.06);
}
.video-screen{position:relative;background:#060a18;aspect-ratio:16/9;overflow:hidden}
.video-screen video{width:100%;height:100%;object-fit:cover;display:block}
.video-play-overlay{
  position:absolute;inset:0;display:flex;flex-direction:column;
  align-items:center;justify-content:center;
  background:rgba(5,5,21,.45);
  cursor:pointer;transition:background .25s;
}
.video-play-overlay:hover{background:rgba(5,5,21,.2)}
.play-btn{
  width:72px;height:72px;border-radius:50%;
  background:linear-gradient(135deg,var(--pr),var(--pr2));
  display:flex;align-items:center;justify-content:center;
  font-size:1.6rem;color:#fff;
  box-shadow:0 0 0 12px rgba(124,58,237,.2),0 8px 32px rgba(124,58,237,.5);
  transition:all .3s;
}
.play-btn:hover{transform:scale(1.1);box-shadow:0 0 0 18px rgba(124,58,237,.15),0 12px 40px rgba(124,58,237,.65)}
.play-label{margin-top:1rem;font-size:.85rem;font-weight:600;color:rgba(255,255,255,.7)}
.video-caption{
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;
  padding:.85rem 1.2rem;background:#040d1a;border-top:1px solid rgba(139,92,246,.1);
}
.vc-left{display:flex;align-items:center;gap:.6rem;font-size:.8rem;color:rgba(255,255,255,.65)}
.vc-left i{color:var(--pr3)}
.vc-right{display:flex;gap:.5rem}
.vc-tag{
  font-size:.65rem;padding:.2rem .6rem;border-radius:20px;font-weight:700;
  background:rgba(124,58,237,.15);color:var(--pr3);
  border:1px solid rgba(167,139,250,.2);
}

/* ══════════ FEATURES GRID ══════════ */
.features-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
  gap:1.25rem;margin-top:3rem;
}
.feat-card{
  background:var(--glass);border:1px solid var(--border);
  border-radius:18px;padding:1.75rem 1.5rem;
  transition:all .35s cubic-bezier(.25,.8,.25,1);
  position:relative;overflow:hidden;
}
.feat-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:var(--feat-color,linear-gradient(90deg,var(--pr),var(--sc)));
  opacity:0;transition:opacity .3s;
}
.feat-card:hover{
  border-color:rgba(139,92,246,.3);
  transform:translateY(-6px);
  box-shadow:0 16px 48px rgba(0,0,0,.35),0 0 0 1px rgba(139,92,246,.15);
  background:rgba(124,58,237,.05);
}
.feat-card:hover::before{opacity:1}
.feat-icon-wrap{
  width:52px;height:52px;border-radius:14px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.3rem;margin-bottom:1.25rem;
}
.feat-title{font-size:1rem;font-weight:800;color:#fff;margin-bottom:.5rem}
.feat-desc{font-size:.83rem;color:var(--text-dim);line-height:1.6}
.feat-tag{
  display:inline-block;margin-top:.9rem;
  font-size:.67rem;font-weight:700;letter-spacing:.5px;
  padding:.2rem .6rem;border-radius:20px;
}

/* ══════════ STATS SHOWCASE ══════════ */
.stats-section{
  background:linear-gradient(135deg,rgba(124,58,237,.08),rgba(6,182,212,.05));
  border:1px solid var(--border);border-radius:24px;
  padding:3.5rem;margin:2rem 0;
}
.stat-showcase{text-align:center}
.ss-num{
  font-size:clamp(2.2rem,5vw,3.5rem);font-weight:900;line-height:1;
  background:linear-gradient(135deg,var(--pr3),var(--sc2));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.ss-lbl{font-size:.85rem;color:var(--text-dim);font-weight:500;margin-top:.4rem}
.ss-divider{width:1px;background:var(--border)}

/* ══════════ PRICING ══════════ */
.price-card{
  background:var(--glass);border:1px solid var(--border);
  border-radius:22px;padding:2.2rem;transition:all .35s;
  position:relative;overflow:hidden;
}
.price-card.featured{
  background:linear-gradient(145deg,rgba(124,58,237,.15),rgba(6,182,212,.08));
  border-color:rgba(167,139,250,.35);
  box-shadow:0 20px 60px rgba(124,58,237,.2);
  transform:scale(1.03);
}
.price-card:hover{transform:translateY(-6px);box-shadow:0 20px 50px rgba(0,0,0,.3)}
.price-card.featured:hover{transform:scale(1.03) translateY(-6px)}
.price-badge{
  position:absolute;top:1.25rem;right:1.25rem;
  background:linear-gradient(135deg,var(--pr),var(--pr2));
  color:#fff;font-size:.65rem;font-weight:800;
  padding:.25rem .7rem;border-radius:20px;letter-spacing:.5px;
}
.price-trial{background:linear-gradient(135deg,#059669,#10B981);box-shadow:0 4px 14px rgba(16,185,129,.28)}
.price-original{text-decoration:line-through;text-decoration-thickness:2px;text-decoration-color:#F87171;opacity:.7}
.price-offer-note{display:inline-flex;align-items:center;gap:.35rem;margin:.75rem 0 -.2rem;padding:.32rem .65rem;border-radius:8px;background:rgba(16,185,129,.12);color:#6EE7B7;font-size:.72rem;font-weight:700}
.price-name{font-size:.85rem;font-weight:700;color:var(--pr3);letter-spacing:.5px;text-transform:uppercase;margin-bottom:.75rem}
.price-amount{font-size:2.6rem;font-weight:900;color:#fff;line-height:1;letter-spacing:-1px}
.price-amount sup{font-size:1rem;font-weight:700;vertical-align:super}
.price-amount sub{font-size:.85rem;color:var(--text-dim);font-weight:500}
.price-desc{font-size:.82rem;color:var(--text-dim);margin:1rem 0 1.5rem;line-height:1.6}
.price-feature{
  display:flex;align-items:center;gap:.6rem;
  font-size:.82rem;color:rgba(255,255,255,.7);
  margin-bottom:.6rem;
}
.price-feature i{font-size:.9rem;flex-shrink:0}
.price-feature i.ok{color:var(--gr)}
.price-feature i.no{color:rgba(255,255,255,.2)}
.price-feature.faded{color:rgba(255,255,255,.3)}
.price-btn{
  width:100%;padding:.85rem;border-radius:13px;
  font-size:.88rem;font-weight:700;border:none;
  cursor:pointer;transition:all .3s;margin-top:1.5rem;
  display:flex;align-items:center;justify-content:center;gap:.5rem;
  text-decoration:none;
}
.price-btn-primary{
  background:linear-gradient(135deg,var(--pr),var(--pr2));
  color:#fff;box-shadow:0 4px 20px rgba(124,58,237,.45);
}
.price-btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(124,58,237,.6);color:#fff}
.price-btn-sec{background:rgba(255,255,255,.07);color:rgba(255,255,255,.75);border:1px solid rgba(255,255,255,.12)!important}
.price-btn-sec:hover{background:rgba(255,255,255,.12);color:#fff}

/* ══════════ TESTIMONIALS ══════════ */
.testi-card{
  background:var(--glass);border:1px solid var(--border);
  border-radius:18px;padding:1.75rem;transition:all .35s;
}
.testi-card:hover{border-color:rgba(139,92,246,.3);transform:translateY(-4px);box-shadow:0 12px 40px rgba(0,0,0,.3)}
.testi-stars{color:#F59E0B;font-size:.85rem;margin-bottom:.9rem}
.testi-text{font-size:.88rem;color:rgba(255,255,255,.75);line-height:1.7;font-style:italic;margin-bottom:1.25rem}
.testi-author{display:flex;align-items:center;gap:.75rem}
.testi-av{
  width:40px;height:40px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:.85rem;font-weight:800;color:#fff;flex-shrink:0;
}
.testi-name{font-size:.85rem;font-weight:700;color:#fff}
.testi-role{font-size:.72rem;color:var(--text-dim)}

/* ══════════ FAQ ══════════ */
.faq-item{
  background:var(--glass);border:1px solid var(--border);
  border-radius:14px;margin-bottom:.75rem;overflow:hidden;
  transition:all .25s;
}
.faq-item:hover{border-color:rgba(139,92,246,.25)}
.faq-q{
  padding:1.1rem 1.4rem;display:flex;align-items:center;justify-content:space-between;
  cursor:pointer;font-weight:600;color:rgba(255,255,255,.85);font-size:.9rem;
  gap:1rem;
}
.faq-q i{color:var(--pr3);font-size:1.1rem;flex-shrink:0;transition:transform .3s}
.faq-a{
  padding:0 1.4rem;max-height:0;overflow:hidden;
  transition:max-height .35s ease, padding .25s;
  font-size:.85rem;color:var(--text-dim);line-height:1.7;
}
.faq-item.open .faq-a{max-height:200px;padding-bottom:1.1rem}
.faq-item.open .faq-q i{transform:rotate(45deg)}

/* ══════════ FOOTER ══════════ */
.site-footer{
  position:relative;z-index:1;
  border-top:1px solid var(--border);
  background:rgba(5,5,21,.8);
  padding:4rem 0 2rem;
  backdrop-filter:blur(12px);
}
.footer-logo{display:flex;align-items:center;gap:.65rem;margin-bottom:.75rem}
.footer-logo-icon{
  width:36px;height:36px;border-radius:9px;
  background:linear-gradient(135deg,var(--pr),var(--sc));
  display:flex;align-items:center;justify-content:center;font-size:1rem;color:#fff;
}
.footer-logo-name{font-size:1.1rem;font-weight:900;color:#fff}
.footer-logo-name .ai{
  background:linear-gradient(135deg,var(--pr3),var(--sc2));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.footer-tagline{font-size:.8rem;color:var(--text-dim);line-height:1.6;margin-bottom:1.5rem;max-width:260px}
.footer-col-title{font-size:.78rem;font-weight:800;color:rgba(255,255,255,.5);letter-spacing:.8px;text-transform:uppercase;margin-bottom:1rem}
.footer-link{display:block;font-size:.84rem;color:rgba(255,255,255,.5);text-decoration:none;margin-bottom:.55rem;transition:color .2s}
.footer-link:hover{color:var(--pr3)}
.footer-bottom{
  border-top:1px solid var(--border);margin-top:3rem;padding-top:1.5rem;
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;
  font-size:.78rem;color:rgba(255,255,255,.3);
}
.footer-bottom a{color:rgba(255,255,255,.35);text-decoration:none}
.footer-bottom a:hover{color:var(--pr3)}
.footer-socials{display:flex;gap:.5rem}
.social-btn{
  width:34px;height:34px;border-radius:8px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
  display:flex;align-items:center;justify-content:center;
  color:rgba(255,255,255,.5);font-size:.9rem;text-decoration:none;
  transition:all .2s;
}
.social-btn:hover{background:rgba(124,58,237,.2);border-color:rgba(167,139,250,.3);color:var(--pr3)}

/* ══════════ SCROLL REVEAL ══════════ */
.reveal{opacity:0;transform:translateY(28px);transition:opacity .65s ease, transform .65s ease}
.reveal.visible{opacity:1;transform:translateY(0)}
.reveal-l{opacity:0;transform:translateX(-28px);transition:opacity .65s ease, transform .65s ease}
.reveal-l.visible{opacity:1;transform:translateX(0)}
.reveal-r{opacity:0;transform:translateX(28px);transition:opacity .65s ease, transform .65s ease}
.reveal-r.visible{opacity:1;transform:translateX(0)}

/* ══════════════════════════════════════════════════
   MOBILE-FIRST RESPONSIVE SYSTEM v2.0
   Breakpoints: 320 · 480 · 576 · 768 · 992 · 1200
   ══════════════════════════════════════════════════ */

/* ── Mobile Hamburger Menu ── */
.nav-hamburger{
  display:none;
  flex-direction:column;gap:5px;
  background:none;border:1px solid rgba(255,255,255,.12);
  border-radius:8px;padding:.45rem .55rem;cursor:pointer;
}
.nav-hamburger span{
  display:block;width:20px;height:2px;
  background:rgba(255,255,255,.75);border-radius:2px;
  transition:all .3s;
}
.nav-hamburger.open span:nth-child(1){transform:rotate(45deg) translate(5px,5px)}
.nav-hamburger.open span:nth-child(2){opacity:0}
.nav-hamburger.open span:nth-child(3){transform:rotate(-45deg) translate(5px,-5px)}

.mobile-menu{
  display:none;
  position:fixed;top:0;left:0;right:0;bottom:0;z-index:999;
  background:rgba(5,5,21,.97);backdrop-filter:blur(24px);
  flex-direction:column;align-items:center;justify-content:center;
  gap:1.5rem;padding:2rem;
}
.mobile-menu.open{display:flex}
.mobile-menu-close{
  position:absolute;top:1.2rem;right:1.5rem;
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
  border-radius:8px;padding:.45rem .7rem;cursor:pointer;
  color:rgba(255,255,255,.7);font-size:1.2rem;
}
.mobile-menu a{
  font-size:1.4rem;font-weight:700;color:rgba(255,255,255,.85);
  text-decoration:none;transition:color .2s;
  display:flex;align-items:center;gap:.75rem;
}
.mobile-menu a:hover{color:var(--pr3)}
.mobile-menu-cta{
  margin-top:1rem;
  background:linear-gradient(135deg,var(--pr),var(--pr2))!important;
  color:#fff!important;padding:.9rem 2.5rem!important;
  border-radius:14px!important;font-size:1rem!important;
  box-shadow:0 6px 24px rgba(124,58,237,.5)!important;
}

/* ── ≤1200px ── */
@media(max-width:1200px){
  .hero-title{font-size:clamp(2.4rem,5vw,4.5rem)}
  .orb1{width:500px;height:500px}
  .orb2{width:450px;height:450px}
}

/* ── ≤992px (Tablet) ── */
@media(max-width:992px){
  /* Navbar */
  .nav-links{display:none!important}
  .nav-hamburger{display:flex}

  /* Hero */
  .hero{padding:6.5rem 0 4rem;min-height:auto;align-items:flex-start}
  .hero .col-lg-6:first-child{text-align:center}
  .hero-badge{margin-left:auto;margin-right:auto}
  .hero-visual{margin-top:2.5rem}
  .float-card{display:none}
  .hero-title{font-size:clamp(2.2rem,6vw,3.8rem);letter-spacing:-1.5px}
  .hero-sub{font-size:1rem;max-width:100%;margin-left:auto;margin-right:auto}
  .hero-btns{justify-content:center}
  .hero-stats{gap:1.5rem;margin-top:2.5rem}
  .hstat-num{font-size:1.7rem}

  /* Sections */
  .section{padding:4.5rem 0}
  .section-title{font-size:clamp(1.7rem,4vw,2.5rem)}

  /* Video */
  .video-section-wrap{padding:1.5rem}
  .video-full-bar .url-bar{font-size:.62rem}

  /* Stats */
  .stats-section{padding:2.5rem 1.75rem}
  .ss-divider{display:none}

  /* Pricing */
  .price-card.featured{transform:none}
  .price-card.featured:hover{transform:translateY(-6px)}
  .price-amount{font-size:2.2rem}

  /* Features grid */
  .features-grid{grid-template-columns:repeat(2,1fr);gap:1rem}

  /* FAQ */
  .faq-q{font-size:.85rem}

  /* Footer */
  .footer-tagline{max-width:100%}
}

/* ── ≤768px (Large Mobile / Small Tablet) ── */
@media(max-width:768px){
  /* Navbar */
  .nav-brand-name{font-size:1.1rem}
  .nav-cta{font-size:.78rem;padding:.45rem 1rem!important}

  /* Hero */
  .hero{padding:5.5rem 0 3.5rem}
  .hero-title{font-size:clamp(2rem,7vw,3.2rem);letter-spacing:-1px}
  .hero-sub{font-size:.95rem}
  .hero-badge{font-size:.68rem}
  .hero-btns{flex-direction:column;align-items:stretch}
  .btn-hero-primary,.btn-hero-sec{justify-content:center;width:100%;font-size:.9rem;padding:.8rem 1.5rem}
  .hero-stats{gap:1rem;flex-wrap:wrap}
  .hstat{min-width:calc(50% - .5rem)}
  .hstat-num{font-size:1.7rem}
  .hstat-lbl{font-size:.7rem}

  /* Browser mock */
  .browser-url{font-size:.62rem}
  .browser-dots span{width:8px;height:8px}

  /* Video section */
  .video-section-wrap{padding:1.25rem}
  .video-full-bar{padding:.6rem .9rem}
  .video-full-bar .url-bar{display:none}
  .video-caption{flex-direction:column;gap:.5rem;padding:.75rem 1rem}
  .vc-right{flex-wrap:wrap}

  /* Features grid */
  .features-grid{grid-template-columns:1fr;gap:.9rem}
  .feat-card{padding:1.4rem 1.25rem}

  /* Stats */
  .stats-section{padding:2rem 1.25rem;border-radius:18px}
  .ss-num{font-size:2rem}
  .ss-lbl{font-size:.78rem}

  /* Section headers */
  .section-title{font-size:clamp(1.6rem,5vw,2.2rem);letter-spacing:-.8px}
  .section-sub{font-size:.9rem}

  /* Pricing */
  .price-card{padding:1.75rem 1.5rem}
  .price-amount{font-size:2rem}

  /* Testimonials */
  .testi-card{padding:1.4rem}
  .testi-text{font-size:.84rem}

  /* FAQ */
  .faq-q{padding:.9rem 1.1rem;font-size:.84rem}
  .faq-a{padding:0 1.1rem}
  .faq-item.open .faq-a{padding-bottom:.9rem}

  /* CTA Banner */
  .cta-banner-inner{padding:2.5rem 1.5rem!important;border-radius:20px!important}

  /* Footer */
  .site-footer{padding:3rem 0 1.5rem}
  .footer-bottom{flex-direction:column;text-align:center;gap:.5rem}
  .footer-socials{justify-content:center}
}

/* ── ≤576px (Mobile) ── */
@media(max-width:576px){
  /* Container padding fix */
  .container{padding-left:1rem;padding-right:1rem}

  /* Navbar */
  .nav-brand-icon{width:34px;height:34px;font-size:1rem}
  .nav-brand-name{font-size:1rem}
  .nav-cta{font-size:.74rem;padding:.4rem .85rem!important}

  /* Hero */
  .hero{padding:5rem 0 3rem}
  .hero-title{font-size:clamp(1.85rem,8vw,2.8rem);letter-spacing:-.8px;line-height:1.1}
  .hero-sub{font-size:.88rem;margin:1.1rem 0 1.8rem;line-height:1.65}
  .hero-badge{font-size:.65rem;padding:.28rem .8rem}
  .hero-stats{gap:.85rem;margin-top:2rem}
  .hstat{min-width:calc(50% - .45rem)}
  .hstat-num{font-size:1.6rem}

  /* Sections */
  .section{padding:3.5rem 0}
  .video-section{padding:3.5rem 0}
  .section-badge{font-size:.65rem;padding:.25rem .75rem}
  .section-title{font-size:clamp(1.5rem,6.5vw,2rem);letter-spacing:-.6px}
  .section-sub{font-size:.85rem;max-width:100%}

  /* Video section */
  .video-section-wrap{padding:1rem;border-radius:16px}
  .video-full-bar{padding:.5rem .75rem}
  .video-full-bar .dots span{width:9px;height:9px}
  .video-caption{padding:.65rem .75rem}
  .vc-left{font-size:.73rem}
  .vc-tag{font-size:.6rem}
  .video-play-overlay .play-btn{width:58px;height:58px;font-size:1.3rem}
  .play-label{font-size:.78rem}

  /* Features */
  .features-grid{grid-template-columns:1fr;gap:.85rem}
  .feat-card{padding:1.25rem 1.1rem;border-radius:14px}
  .feat-icon-wrap{width:44px;height:44px;font-size:1.1rem;margin-bottom:.9rem}
  .feat-title{font-size:.92rem}
  .feat-desc{font-size:.8rem}

  /* Stats */
  .stats-section{padding:1.75rem 1rem;border-radius:14px}
  .ss-num{font-size:1.8rem}
  .ss-lbl{font-size:.75rem}

  /* Pricing */
  .price-card{padding:1.4rem 1.25rem;border-radius:16px}
  .price-name{font-size:.78rem}
  .price-amount{font-size:1.8rem;letter-spacing:-.5px}
  .price-desc{font-size:.78rem}
  .price-feature{font-size:.78rem;margin-bottom:.5rem}
  .price-btn{padding:.75rem;font-size:.82rem;border-radius:11px}
  .price-badge{font-size:.6rem;top:1rem;right:1rem}

  /* Testimonials */
  .testi-card{padding:1.25rem}
  .testi-stars{font-size:.8rem}
  .testi-text{font-size:.82rem}
  .testi-name{font-size:.82rem}
  .testi-role{font-size:.68rem}
  .testi-av{width:36px;height:36px;font-size:.78rem}

  /* FAQ */
  .faq-q{font-size:.82rem;padding:.85rem 1rem}
  .faq-q i{font-size:1rem}
  .faq-a{font-size:.8rem;padding:0 1rem}
  .faq-item.open .faq-a{padding-bottom:.85rem}
  .faq-item{border-radius:11px}

  /* CTA Banner */
  .cta-banner-inner{padding:2rem 1.25rem!important;border-radius:16px!important}
  .cta-banner-inner h2{font-size:clamp(1.5rem,6vw,2.2rem)!important;letter-spacing:-.8px!important}
  .cta-banner-inner p{font-size:.88rem!important}
  .cta-banner-btns{flex-direction:column;align-items:stretch!important}
  .cta-banner-btns a{justify-content:center;width:100%}

  /* Footer */
  .site-footer{padding:2.5rem 0 1.25rem}
  .footer-logo-icon{width:30px;height:30px;font-size:.9rem}
  .footer-logo-name{font-size:1rem}
  .footer-col-title{font-size:.72rem}
  .footer-link{font-size:.8rem;margin-bottom:.45rem}
  .footer-bottom{padding-top:1.25rem;margin-top:2rem;font-size:.72rem}
  .social-btn{width:30px;height:30px;font-size:.82rem}

  /* Orbs — smaller on mobile to avoid overflow */
  .orb1{width:280px;height:280px;top:-100px;left:-100px}
  .orb2{width:260px;height:260px;bottom:-100px;right:-80px}
  .orb3{width:200px;height:200px}

  /* Video highlight cards */
  .col-6 [style*="text-align:center"]{padding:.75rem!important}
}

/* ── ≤480px (Small Mobile) ── */
@media(max-width:480px){
  .hero-title{font-size:clamp(1.7rem,8.5vw,2.4rem)}
  .hero-sub{font-size:.85rem}
  .btn-hero-primary,.btn-hero-sec{font-size:.85rem;padding:.75rem 1.2rem}
  .hstat{min-width:calc(50% - .4rem)}
  .hstat-num{font-size:1.5rem;letter-spacing:-.5px}

  /* Stat cards in video section */
  .col-6 .stat-mini{padding:.6rem!important}

  /* Pricing fix on tiny screens */
  .price-amount{font-size:1.6rem}
  .price-amount sup{font-size:.85rem}
}

/* ── ≤360px (Very Small) ── */
@media(max-width:360px){
  .container{padding-left:.75rem;padding-right:.75rem}
  .hero-title{font-size:1.65rem}
  .hero-badge{flex-wrap:wrap}
  .nav-brand-name{font-size:.9rem}
  .btn-hero-primary,.btn-hero-sec{font-size:.82rem}
}
</style>
</head>
<body>

<!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
  <button class="mobile-menu-close" id="mobileMenuClose"><i class="bi bi-x-lg"></i></button>
  <a href="#features"        onclick="closeMobileMenu()"><i class="bi bi-grid-3x3-gap-fill"></i> Features</a>
  <a href="#commerce-cloud" onclick="closeMobileMenu()" style="color:#A78BFA!important"><i class="bi bi-cloud-fill"></i> Commerce Cloud</a>
  <a href="#demo"            onclick="closeMobileMenu()"><i class="bi bi-play-circle-fill"></i> Demo</a>
  <a href="#pricing"         onclick="closeMobileMenu()"><i class="bi bi-tag-fill"></i> Pricing</a>
  <a href="#testimonials"    onclick="closeMobileMenu()"><i class="bi bi-star-fill"></i> Reviews</a>
  <a href="#faq"             onclick="closeMobileMenu()"><i class="bi bi-question-circle-fill"></i> FAQ</a>
  <a href="<?= BASE_URL ?>/register.php" class="mobile-menu-cta"><i class="bi bi-rocket-takeoff-fill"></i> Start Free Trial</a>
</div>

<!-- Background -->
<div class="site-bg">
  <div class="orb orb1"></div>
  <div class="orb orb2"></div>
  <div class="orb orb3"></div>
  <div class="noise"></div>
</div>
<div class="grid-over"></div>

<!-- ══════════════════════ NAVBAR ══════════════════════ -->
<nav class="navbar-land" id="navbar">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between">
      <a href="<?= BASE_URL ?>/landing.php" class="nav-brand">
        <div class="nav-brand-icon"><i class="bi bi-cpu-fill"></i></div>
        <span class="nav-brand-name">Stockora&nbsp;<span class="ai">AI</span></span>
      </a>
      <div class="nav-links d-none d-md-flex">
        <a href="#features"        class="nav-link-item">Features</a>
        <a href="#commerce-cloud"  class="nav-link-item" style="color:rgba(167,139,250,.8)!important"><i class="bi bi-cloud-fill me-1" style="font-size:.75rem"></i>Commerce</a>
        <a href="#demo"            class="nav-link-item">Demo</a>
        <a href="#pricing"         class="nav-link-item">Pricing</a>
        <a href="#testimonials"    class="nav-link-item">Reviews</a>
        <a href="#faq"             class="nav-link-item">FAQ</a>
      </div>
      <div class="d-flex align-items-center gap-1">
        <a href="<?= BASE_URL ?>/login.php" class="nav-cta d-none d-sm-inline-flex">
          <i class="bi bi-arrow-right-circle-fill me-2"></i> Sign In
        </a>
        
        <button class="nav-hamburger d-md-none" id="hamburgerBtn" aria-label="Menu">
          <span></span><span></span><span></span>
        </button>
      </div>  
    </div>
  </div>
</nav>

<!-- ══════════════════════ HERO ══════════════════════ -->
<section class="hero" id="home">
  <div class="container">
    <div class="row align-items-center g-5">

      <!-- Left: Text -->
      <div class="col-lg-6">
        <div class="hero-badge">
          <i class="bi bi-stars"></i> AI-Powered Business Platform
        </div>
        <h1 class="hero-title">
          Run Your Shop<br>
          <span class="grad">Smarter with AI</span>
        </h1>
        <p class="hero-sub">
          Stockora AI is the all-in-one POS, inventory & analytics platform designed for Pakistani businesses.
          Billing, stock alerts, customer credit, and AI-powered insights — all in one place.
        </p>
        <div class="hero-btns">
          <a href="<?= BASE_URL ?>/register.php" class="btn-hero-primary">
            <i class="bi bi-play-circle-fill"></i> Start Free Trial
          </a>
          <a href="#demo" class="btn-hero-sec">
            <i class="bi bi-play-fill"></i> Watch Demo
          </a>
        </div>
      </div>

      <!-- Right: Browser + Video -->
      <div class="col-lg-6">
        <div class="hero-visual">
          <!-- Floating cards -->
          <div class="float-card float-card-1">
            <div class="fc-icon" style="background:rgba(16,185,129,.18)"><i class="bi bi-graph-up-arrow" style="color:#34D399"></i></div>
            <div>
              <div class="fc-num">+34%</div>
              <div class="fc-lbl">Sales Today</div>
            </div>
          </div>
          <div class="float-card float-card-2">
            <div class="fc-icon" style="background:rgba(245,158,11,.15)"><i class="bi bi-exclamation-triangle-fill" style="color:#FCD34D"></i></div>
            <div>
              <div class="fc-num">5 Items</div>
              <div class="fc-lbl">Low Stock Alert</div>
            </div>
          </div>

          <div class="browser-mock">
            <div class="browser-bar">
              <div class="browser-dots">
                <span></span><span></span><span></span>
              </div>
              <div class="browser-url">stockora.ai/shop/dashboard</div>
            </div>
            <div class="video-container">
              <!-- Embedded YouTube demo video (shop owner dashboard walkthrough) -->
              <div style="position:relative;aspect-ratio:16/9;background:linear-gradient(135deg,#0a0820,#060e1c);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;cursor:pointer;" onclick="window.location.href='#demo'">
                <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#7C3AED,#8B5CF6);display:flex;align-items:center;justify-content:center;font-size:2rem;color:#fff;box-shadow:0 0 0 16px rgba(124,58,237,.18),0 8px 40px rgba(124,58,237,.5);">
                  <i class="bi bi-play-fill"></i>
                </div>
                <div style="text-align:center">
                  <div style="font-size:1rem;font-weight:700;color:rgba(255,255,255,.9)">Watch Dashboard Demo</div>
                  <div style="font-size:.78rem;color:rgba(255,255,255,.45);margin-top:.25rem">45 sec · Shop Owner Walkthrough</div>
                </div>
                <!-- Fake dashboard preview -->
                <div style="position:absolute;inset:0;opacity:.18;background:url('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 400 225%22><rect width=%22400%22 height=%22225%22 fill=%22%230a0820%22/><rect x=%2210%22 y=%2210%22 width=%2270%22 height=%22205%22 rx=%228%22 fill=%22%23120828%22 opacity=%22.8%22/><rect x=%2290%22 y=%2210%22 width=%22140%22 height=%2250%22 rx=%228%22 fill=%22%237C3AED%22 opacity=%22.3%22/><rect x=%22240%22 y=%2210%22 width=%22150%22 height=%2250%22 rx=%228%22 fill=%22%2306B6D4%22 opacity=%22.2%22/><rect x=%2290%22 y=%2270%22 width=%22300%22 height=%22145%22 rx=%228%22 fill=%22%23120828%22 opacity=%22.6%22/></svg>') center/cover no-repeat;pointer-events:none"></div>
              </div>
              <div class="video-overlay-badge">
                <span class="rec-dot"></span>
                Live Dashboard Preview
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
    <div class="hero-stats">
      <div class="hstat">
        <div class="hstat-num"><span class="gr" data-counter-target="500" data-counter-suffix="+">500+</span></div>
        <div class="hstat-lbl">Active Shops</div>
      </div>
      <div class="hstat">
        <div class="hstat-num"><span class="gr" data-counter-target="2000000" data-counter-suffix="M+" data-counter-format="millions">2M+</span></div>
        <div class="hstat-lbl">Transactions</div>
      </div>
      <div class="hstat">
        <div class="hstat-num"><span class="gr" data-counter-target="99.9" data-counter-suffix="%" data-counter-decimals="1">99.9%</span></div>
        <div class="hstat-lbl">Uptime</div>
      </div>
      <div class="hstat">
        <div class="hstat-num"><span class="gr" data-counter-target="4.9" data-counter-suffix="★" data-counter-decimals="1">4.9★</span></div>
        <div class="hstat-lbl">User Rating</div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════ VIDEO DEMO SECTION ══════════════════════ -->
<section class="video-section" id="demo">
  <div class="container">
    <div class="text-center mb-4 reveal">
      <span class="section-badge"><i class="bi bi-play-circle"></i> Live Demo</span>
      <h2 class="section-title">See Stockora AI in Action</h2>
      <p class="section-sub mx-auto">Watch how a shop owner manages their entire business — from billing to stock reports — in under a minute.</p>
    </div>

    <div class="video-section-wrap reveal">
      <div class="video-full-mock">
        <div class="video-full-bar">
          <div class="dots"><span></span><span></span><span></span></div>
          <div class="url-bar">🔒 stockora.ai/shop/dashboard — Shop Owner Control Panel</div>
          <div style="font-size:.7rem;color:rgba(255,255,255,.3);display:flex;align-items:center;gap:.4rem"><span class="rec-dot"></span>Live Session</div>
        </div>
        <div class="video-screen" id="demoVideoWrap">
          <!-- YouTube embed — replace VIDEO_ID with actual walkthrough video -->
          <iframe
            id="demoVideo"
            width="100%"
            style="aspect-ratio:16/9;border:none;display:block;"
            src="assets/images/demo.mp4"
            title="Stockora AI — Shop Owner Dashboard Walkthrough"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen>
          </iframe>
        </div>
        <div class="video-caption">
          <div class="vc-left">
            <i class="bi bi-camera-video-fill"></i>
            <strong>Dashboard Walkthrough</strong>
            <span style="color:rgba(255,255,255,.35)">· Shop Owner Panel · Full Control Demo</span>
          </div>
          <div class="vc-right">
            <span class="vc-tag">POS Billing</span>
            <span class="vc-tag">Inventory</span>
            <span class="vc-tag">Analytics</span>
            <span class="vc-tag">AI Lab</span>
          </div>
        </div>
      </div>

      <!-- Video highlights row -->
      <div class="row g-3 mt-3">
        <div class="col-6 col-md-3 reveal">
          <div style="text-align:center;padding:1rem;background:var(--glass);border:1px solid var(--border);border-radius:12px;">
            <div style="font-size:1.6rem;color:#A78BFA;margin-bottom:.4rem"><i class="bi bi-stopwatch-fill"></i></div>
            <div style="font-size:.85rem;font-weight:700;color:#fff">~45 Seconds</div>
            <div style="font-size:.72rem;color:var(--text-dim)">Full Demo Duration</div>
          </div>
        </div>
        <div class="col-6 col-md-3 reveal">
          <div style="text-align:center;padding:1rem;background:var(--glass);border:1px solid var(--border);border-radius:12px;">
            <div style="font-size:1.6rem;color:#22D3EE;margin-bottom:.4rem"><i class="bi bi-layout-sidebar-inset"></i></div>
            <div style="font-size:.85rem;font-weight:700;color:#fff">Full Dashboard</div>
            <div style="font-size:.72rem;color:var(--text-dim)">Shop Owner Panel</div>
          </div>
        </div>
        <div class="col-6 col-md-3 reveal">
          <div style="text-align:center;padding:1rem;background:var(--glass);border:1px solid var(--border);border-radius:12px;">
            <div style="font-size:1.6rem;color:#34D399;margin-bottom:.4rem"><i class="bi bi-cart3"></i></div>
            <div style="font-size:.85rem;font-weight:700;color:#fff">POS Billing</div>
            <div style="font-size:.72rem;color:var(--text-dim)">Live Transaction</div>
          </div>
        </div>
        <div class="col-6 col-md-3 reveal">
          <div style="text-align:center;padding:1rem;background:var(--glass);border:1px solid var(--border);border-radius:12px;">
            <div style="font-size:1.6rem;color:#F472B6;margin-bottom:.4rem"><i class="bi bi-robot"></i></div>
            <div style="font-size:.85rem;font-weight:700;color:#fff">AI Smart Lab</div>
            <div style="font-size:.72rem;color:var(--text-dim)">AI Insights Demo</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════ FEATURES ══════════════════════ -->
<section class="section" id="features">
  <div class="container">
    <div class="text-center reveal">
      <span class="section-badge"><i class="bi bi-grid-3x3-gap-fill"></i> Core Features</span>
      <h2 class="section-title">Everything Your Business Needs</h2>
      <p class="section-sub mx-auto">15+ powerful modules built specifically for Pakistani retail & wholesale businesses.</p>
    </div>

    <div class="features-grid">
      <!-- POS -->
      <div class="feat-card reveal" style="--feat-color:linear-gradient(90deg,#7C3AED,#8B5CF6)">
        <div class="feat-icon-wrap" style="background:rgba(124,58,237,.18)">
          <i class="bi bi-cart3" style="color:#A78BFA"></i>
        </div>
        <div class="feat-title">Point of Sale (POS)</div>
        <div class="feat-desc">Lightning-fast billing with barcode scanning, multiple payment methods, instant invoice printing & WhatsApp sharing.</div>
        <span class="feat-tag" style="background:rgba(124,58,237,.15);color:#A78BFA;border:1px solid rgba(167,139,250,.2)">Core Module</span>
      </div>

      <!-- Inventory -->
      <div class="feat-card reveal" style="--feat-color:linear-gradient(90deg,#06B6D4,#22D3EE)">
        <div class="feat-icon-wrap" style="background:rgba(6,182,212,.15)">
          <i class="bi bi-box-seam" style="color:#22D3EE"></i>
        </div>
        <div class="feat-title">Smart Inventory</div>
        <div class="feat-desc">Real-time stock tracking across all products. Auto low-stock alerts, reorder management, and bulk import/export via Excel.</div>
        <span class="feat-tag" style="background:rgba(6,182,212,.12);color:#22D3EE;border:1px solid rgba(34,211,238,.2)">Inventory</span>
      </div>

      <!-- Analytics -->
      <div class="feat-card reveal" style="--feat-color:linear-gradient(90deg,#10B981,#34D399)">
        <div class="feat-icon-wrap" style="background:rgba(16,185,129,.15)">
          <i class="bi bi-graph-up-arrow" style="color:#34D399"></i>
        </div>
        <div class="feat-title">Analytics & Reports</div>
        <div class="feat-desc">Daily Z-reports, profit & loss analysis, sales trends, category performance, and revenue forecasting dashboards.</div>
        <span class="feat-tag" style="background:rgba(16,185,129,.12);color:#34D399;border:1px solid rgba(52,211,153,.2)">Analytics</span>
      </div>

      <!-- Customers -->
      <div class="feat-card reveal" style="--feat-color:linear-gradient(90deg,#F59E0B,#FCD34D)">
        <div class="feat-icon-wrap" style="background:rgba(245,158,11,.15)">
          <i class="bi bi-people" style="color:#FCD34D"></i>
        </div>
        <div class="feat-title">Customer Management</div>
        <div class="feat-desc">Full customer profiles, credit & dues tracking, payment history, bulk buyer management with outstanding balance alerts.</div>
        <span class="feat-tag" style="background:rgba(245,158,11,.12);color:#FCD34D;border:1px solid rgba(252,211,77,.2)">CRM</span>
      </div>

      <!-- AI Lab -->
      <div class="feat-card reveal" style="--feat-color:linear-gradient(90deg,#EC4899,#F472B6)">
        <div class="feat-icon-wrap" style="background:rgba(236,72,153,.15)">
          <i class="bi bi-robot" style="color:#F472B6"></i>
        </div>
        <div class="feat-title">AI Smart Lab</div>
        <div class="feat-desc">AI-powered business insights, demand forecasting, automated tips to boost profits, and intelligent stock recommendations.</div>
        <span class="feat-tag" style="background:rgba(236,72,153,.12);color:#F472B6;border:1px solid rgba(244,114,182,.2)">AI Feature</span>
      </div>

      <!-- Finances -->
      <div class="feat-card reveal" style="--feat-color:linear-gradient(90deg,#EF4444,#F87171)">
        <div class="feat-icon-wrap" style="background:rgba(239,68,68,.15)">
          <i class="bi bi-wallet2" style="color:#F87171"></i>
        </div>
        <div class="feat-title">Finance & Expenses</div>
        <div class="feat-desc">Track all business expenses, calculate net profit, manage daily cash flow, and generate detailed financial reports.</div>
        <span class="feat-tag" style="background:rgba(239,68,68,.1);color:#F87171;border:1px solid rgba(248,113,113,.2)">Finance</span>
      </div>

      <!-- Purchase Entry -->
      <div class="feat-card reveal" style="--feat-color:linear-gradient(90deg,#8B5CF6,#A78BFA)">
        <div class="feat-icon-wrap" style="background:rgba(139,92,246,.15)">
          <i class="bi bi-truck" style="color:#A78BFA"></i>
        </div>
        <div class="feat-title">Purchase Entry</div>
        <div class="feat-desc">Record supplier purchases, auto-update stock, track purchase history, and manage vendor payment status.</div>
        <span class="feat-tag" style="background:rgba(139,92,246,.12);color:#A78BFA;border:1px solid rgba(167,139,250,.2)">Procurement</span>
      </div>

      <!-- Daily Target -->
      <div class="feat-card reveal" style="--feat-color:linear-gradient(90deg,#0EA5E9,#38BDF8)">
        <div class="feat-icon-wrap" style="background:rgba(14,165,233,.15)">
          <i class="bi bi-bullseye" style="color:#38BDF8"></i>
        </div>
        <div class="feat-title">Daily Sales Target</div>
        <div class="feat-desc">Set daily revenue goals, track progress in real-time with visual gauges, and motivate your team with achievement tracking.</div>
        <span class="feat-tag" style="background:rgba(14,165,233,.12);color:#38BDF8;border:1px solid rgba(56,189,248,.2)">Productivity</span>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════ COMMERCE CLOUD FEATURE HIGHLIGHT ══════════════════════ -->
<section class="section" id="commerce-cloud" style="padding:5rem 0">
  <div class="container">
    <!-- Section Header -->
    <div class="text-center reveal">
      <span class="section-badge" style="background:rgba(124,58,237,.15);border-color:rgba(167,139,250,.3);color:#A78BFA">
        <i class="bi bi-cloud-fill"></i> Commerce Cloud — New
      </span>
      <h2 class="section-title">
        Launch Your <span style="background:linear-gradient(135deg,#A78BFA,#22D3EE);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">Ecommerce Store</span>
      </h2>
      <p class="section-sub mx-auto">Transform Stockora from an inventory system into a complete Commerce Operating System. Sell online without hiring developers or buying separate hosting.</p>
    </div>

    <!-- ── Main Commerce Cloud Card ── -->
    <div class="reveal" style="margin-top:3rem;background:linear-gradient(135deg,rgba(124,58,237,.12),rgba(6,182,212,.08));border:1px solid rgba(167,139,250,.25);border-radius:26px;padding:3rem;position:relative;overflow:hidden">
      <!-- Background orbs -->
      <div style="position:absolute;top:-80px;right:-80px;width:280px;height:280px;background:radial-gradient(circle,rgba(124,58,237,.2),transparent 60%);pointer-events:none"></div>
      <div style="position:absolute;bottom:-60px;left:10%;width:200px;height:200px;background:radial-gradient(circle,rgba(6,182,212,.15),transparent 60%);pointer-events:none"></div>

      <div class="row g-5 align-items-center" style="position:relative;z-index:1">
        <!-- Left: Text -->
        <div class="col-lg-5">
          <div style="display:inline-flex;align-items:center;gap:.45rem;background:rgba(124,58,237,.15);border:1px solid rgba(167,139,250,.25);border-radius:30px;padding:.28rem .8rem;font-size:.68rem;font-weight:700;color:#A78BFA;letter-spacing:.5px;text-transform:uppercase;margin-bottom:.85rem">
            <i class="bi bi-cloud-fill"></i> Stockora Commerce Cloud
          </div>
          <h3 style="font-size:clamp(1.5rem,3.5vw,2.2rem);font-weight:900;color:#fff;letter-spacing:-.8px;line-height:1.15;margin-bottom:.9rem">
            Your Inventory.<br>Your Online Store.<br>
            <span style="background:linear-gradient(135deg,#A78BFA,#22D3EE);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">One Platform.</span>
          </h3>
          <p style="font-size:.9rem;color:rgba(226,232,240,.6);line-height:1.7;margin-bottom:1.5rem">
            Products in Stockora automatically appear in your online store. Stock updates reflect instantly. Online orders land directly in your dashboard.
          </p>
          <!-- Benefits list -->
          <?php
          $ccBenefits = [
            ['bi-check-circle-fill','#34D399','Launch store in 4 simple steps'],
            ['bi-check-circle-fill','#34D399','20 enterprise premium themes'],
            ['bi-check-circle-fill','#34D399','Custom domain or free subdomain'],
            ['bi-check-circle-fill','#34D399','Real-time inventory sync'],
            ['bi-check-circle-fill','#34D399','AI theme & product recommendations'],
            ['bi-check-circle-fill','#34D399','Online orders in your dashboard'],
          ];
          foreach($ccBenefits as [$icon,$color,$text]):
          ?>
          <div style="display:flex;align-items:center;gap:.6rem;font-size:.84rem;color:rgba(255,255,255,.75);margin-bottom:.5rem">
            <i class="bi <?= $icon ?>" style="color:<?= $color ?>;flex-shrink:0"></i> <?= $text ?>
          </div>
          <?php endforeach; ?>
          <div style="margin-top:1.75rem;display:flex;gap:.85rem;flex-wrap:wrap">
            <a href="<?= BASE_URL ?>/register.php" style="display:inline-flex;align-items:center;gap:.5rem;background:linear-gradient(135deg,#7C3AED,#8B5CF6);color:#fff;font-size:.9rem;font-weight:800;padding:.8rem 1.75rem;border-radius:13px;text-decoration:none;box-shadow:0 6px 24px rgba(124,58,237,.45);transition:all .3s">
              <i class="bi bi-rocket-takeoff-fill"></i> Launch Your Store
            </a>
            <a href="#demo" style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.07);color:rgba(255,255,255,.8);font-size:.9rem;font-weight:600;padding:.8rem 1.5rem;border-radius:13px;text-decoration:none;border:1px solid rgba(255,255,255,.15)">
              <i class="bi bi-play-fill"></i> See How It Works
            </a>
          </div>
        </div>

        <!-- Right: Feature Grid -->
        <div class="col-lg-7">
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:1rem">
            <?php
            $ccFeats = [
              ['bi-rocket-takeoff-fill','rgba(124,58,237,.18)','#A78BFA','4-Step Launch Wizard','Business info → Theme → Domain → Live. Go online in minutes with zero coding.'],
              ['bi-palette-fill',       'rgba(236,72,153,.15)', '#F472B6','20 Premium Themes',  'Enterprise themes for Fashion, Electronics, Beauty, Grocery, Furniture & more.'],
              ['bi-arrow-repeat',       'rgba(16,185,129,.15)', '#34D399','Real-time Sync',     'Every stock change in Stockora reflects instantly on your online storefront.'],
              ['bi-globe2',             'rgba(6,182,212,.15)',  '#22D3EE','Custom Domain',      'Connect your own domain or use store.stockora.com — with free SSL included.'],
              ['bi-bag-check-fill',     'rgba(14,165,233,.15)', '#38BDF8','Order Management',  'Online orders appear directly in your Stockora dashboard with full tracking.'],
              ['bi-robot',              'rgba(244,114,182,.15)','#F472B6','AI Commerce Intelligence','AI analyzes your business and recommends what to publish, price & promote.'],
            ];
            foreach($ccFeats as [$icon,$bg,$color,$title,$desc]):
            ?>
            <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:1.1rem;transition:all .3s" onmouseover="this.style.borderColor='rgba(139,92,246,.3)';this.style.transform='translateY(-3px)'" onmouseout="this.style.borderColor='rgba(255,255,255,.08)';this.style.transform='translateY(0)'">
              <div style="width:38px;height:38px;border-radius:10px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;font-size:.95rem;color:<?= $color ?>;margin-bottom:.7rem">
                <i class="bi <?= $icon ?>"></i>
              </div>
              <div style="font-size:.85rem;font-weight:800;color:#fff;margin-bottom:.3rem"><?= $title ?></div>
              <div style="font-size:.75rem;color:rgba(226,232,240,.5);line-height:1.5"><?= $desc ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ── 4-Step Process Row ── -->
    <div class="reveal" style="margin-top:2rem">
      <div style="display:flex;gap:.75rem;overflow-x:auto;padding-bottom:.5rem">
        <?php
        $steps = [
          ['1','bi-building','#7C3AED','Business Info','Store name, logo, contact details, and business category'],
          ['2','bi-palette-fill','#EC4899','Choose Theme','Pick from 20 enterprise premium themes across 8 categories'],
          ['3','bi-globe2','#06B6D4','Connect Domain','Free subdomain or your custom domain with SSL'],
          ['4','bi-rocket-takeoff-fill','#10B981','Go Live!','Store launches instantly — start receiving online orders'],
        ];
        foreach($steps as [$num,$icon,$color,$title,$desc]):
        ?>
        <div style="flex:1;min-width:180px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:15px;padding:1.1rem;text-align:center">
          <div style="width:40px;height:40px;border-radius:50%;background:<?= $color ?>22;border:2px solid <?= $color ?>44;display:flex;align-items:center;justify-content:center;font-size:.95rem;color:<?= $color ?>;margin:0 auto .7rem">
            <i class="bi <?= $icon ?>"></i>
          </div>
          <div style="font-size:.65rem;font-weight:800;color:rgba(255,255,255,.35);letter-spacing:.5px;margin-bottom:.3rem">STEP <?= $num ?></div>
          <div style="font-size:.85rem;font-weight:800;color:#fff;margin-bottom:.3rem"><?= $title ?></div>
          <div style="font-size:.73rem;color:rgba(226,232,240,.45);line-height:1.5"><?= $desc ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════ STATS ══════════════════════ -->
<section class="section" style="padding:3rem 0">
  <div class="container">
    <div class="stats-section reveal">
      <div class="row g-4 align-items-center">
        <div class="col-6 col-md-3">
          <div class="stat-showcase">
            <div class="ss-num" data-counter-target="500" data-counter-suffix="+">500+</div>
            <div class="ss-lbl">Active Shop Owners</div>
          </div>
        </div>
        <div class="col-md-1 d-none d-md-block"><div class="ss-divider" style="height:80px;margin:auto"></div></div>
        <div class="col-6 col-md-2">
          <div class="stat-showcase">
            <div class="ss-num" data-counter-target="2000000" data-counter-suffix="M+" data-counter-format="millions">2M+</div>
            <div class="ss-lbl">Transactions Processed</div>
          </div>
        </div>
        <div class="col-md-1 d-none d-md-block"><div class="ss-divider" style="height:80px;margin:auto"></div></div>
        <div class="col-6 col-md-2">
          <div class="stat-showcase">
            <div class="ss-num" data-counter-target="15" data-counter-suffix="+">15+</div>
            <div class="ss-lbl">Powerful Modules</div>
          </div>
        </div>
        <div class="col-md-1 d-none d-md-block"><div class="ss-divider" style="height:80px;margin:auto"></div></div>
        <div class="col-6 col-md-2">
          <div class="stat-showcase">
            <div class="ss-num" data-counter-target="99.9" data-counter-suffix="%" data-counter-decimals="1">99.9%</div>
            <div class="ss-lbl">System Uptime</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════ PRICING ══════════════════════ -->
<section class="section" id="pricing">
  <div class="container">
    <div class="text-center reveal">
      <span class="section-badge"><i class="bi bi-tag-fill"></i> Pricing</span>
      <h2 class="section-title">Simple, Transparent Pricing</h2>
      <p class="section-sub mx-auto">All plans include unlimited POS billing, inventory management, and 24/7 support.</p>
    </div>
    <div class="row g-4 justify-content-center mt-3">
      <?php if ($landingPlans): foreach ($landingPlans as $plan):
          $features = array_filter(array_map('trim', preg_split('/\R/', $plan['features'] ?? '')));
          $isCustom = (float)$plan['monthly_price'] <= 0;
      ?>
      <div class="col-md-4 col-lg-4 reveal">
        <div class="price-card <?= $plan['is_featured'] ? 'featured' : '' ?> h-100">
          <?php if ($plan['badge_text']): ?><span class="price-badge <?= $plan['trial_days'] ? 'price-trial' : '' ?>"><?= htmlspecialchars($plan['badge_text']) ?></span><?php endif; ?>
          <div class="price-name"><?= htmlspecialchars($plan['name']) ?></div>
          <?php if ($isCustom): ?><div class="price-amount" style="font-size:1.8rem">Custom</div><?php else: ?><div class="price-amount"><sup>Rs.</sup><?php if ($plan['original_price']): ?><span class="price-original"><?= number_format($plan['original_price']) ?></span><?php else: ?><?= number_format($plan['monthly_price']) ?><?php endif; ?><sub>/mo</sub></div><?php endif; ?>
          <?php if ($plan['trial_days']): ?><div class="price-offer-note"><i class="bi bi-calendar-check-fill"></i> <?= (int)$plan['trial_days'] ?> days free trial<?= $plan['offer_valid_months'] ? ' — offer valid for '.(int)$plan['offer_valid_months'].' months' : '' ?></div><?php endif; ?>
          <div class="price-desc"><?= htmlspecialchars($plan['description'] ?? '') ?></div>
          <?php foreach ($features as $feature): ?><div class="price-feature"><i class="bi bi-check-circle-fill ok"></i><?= htmlspecialchars($feature) ?></div><?php endforeach; ?>
          <a href="<?= $isCustom ? 'mailto:support@stockora.ai' : BASE_URL.'/register.php' ?>" class="price-btn <?= $plan['is_featured'] ? 'price-btn-primary' : 'price-btn-sec' ?>"><?= $isCustom ? 'Contact Sales' : ($plan['trial_days'] ? 'Start Free Trial' : 'Get Started Now') ?></a>
        </div>
      </div>
      <?php endforeach; else: ?>
      <!-- Basic -->
      <div class="col-md-4 col-lg-4 reveal">
        <div class="price-card h-100">
          <span class="price-badge price-trial"><i class="bi bi-gift-fill me-1"></i>7 Days Free Trial</span>
          <div class="price-name">Basic</div>
          <div class="price-amount"><sup>Rs.</sup><span class="price-original">8,000</span><sub>/mo</sub></div>
          <div class="price-offer-note"><i class="bi bi-calendar-check-fill"></i> 7 days free trial — offer valid for 3 months</div>
          <div class="price-desc">Perfect for small shops just getting started with digital POS.</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>POS Billing (Unlimited)</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Inventory Management</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Basic Sales Reports</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Customer Management</div>
          <div class="price-feature faded"><i class="bi bi-dash-circle no"></i>AI Smart Lab</div>
          <div class="price-feature faded"><i class="bi bi-dash-circle no"></i>Advanced Analytics</div>
          <a href="<?= BASE_URL ?>/register.php" class="price-btn price-btn-sec">Start Free Trial</a>
        </div>
      </div>
      <!-- Pro (Featured) -->
      <div class="col-md-4 col-lg-4 reveal">
        <div class="price-card featured h-100">
          <span class="price-badge">⭐ Most Popular</span>
          <div class="price-name">Professional</div>
          <div class="price-amount"><sup>Rs.</sup>15,000<sub>/mo</sub></div>
          <div class="price-desc">Full-featured plan for growing businesses who need every tool.</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Everything in Basic</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>AI Smart Lab Access</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Advanced Analytics</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Expense & Finance Tracking</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Daily Target Monitoring</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i><span style="color:#A78BFA;font-weight:700">Commerce Cloud</span> Store</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Priority Support</div>
          <a href="<?= BASE_URL ?>/register.php" class="price-btn price-btn-primary">Get Started Now</a>
        </div>
      </div>
      <!-- Enterprise -->
      <div class="col-md-4 col-lg-4 reveal">
        <div class="price-card h-100">
          <div class="price-name">Enterprise</div>
          <div class="price-amount" style="font-size:1.8rem">Custom</div>
          <div class="price-desc" style="margin-top:.75rem">For multi-branch chains and wholesale businesses.</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Everything in Pro</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Multi-Branch Management</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Custom Integrations</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>Dedicated Account Manager</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>On-site Training</div>
          <div class="price-feature"><i class="bi bi-check-circle-fill ok"></i>SLA Guarantee</div>
          <a href="mailto:support@stockora.ai" class="price-btn price-btn-sec">Contact Sales</a>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ══════════════════════ TESTIMONIALS ══════════════════════ -->
<section class="section" id="testimonials">
  <div class="container">
    <div class="text-center reveal">
      <span class="section-badge"><i class="bi bi-star-fill"></i> Testimonials</span>
      <h2 class="section-title">Loved by Shop Owners</h2>
      <p class="section-sub mx-auto">Real feedback from real businesses across Pakistan using Stockora AI daily.</p>
    </div>
    <div class="row g-4 mt-2">
      <div class="col-md-4 reveal">
        <div class="testi-card h-100">
          <div class="testi-stars">★★★★★</div>
          <p class="testi-text">"Stockora AI ne meri dukaan completely badal di. Ab roz ki billing, stock or credit sab ek jagah track hoti hai. Bohot zyada fark para."</p>
          <div class="testi-author">
            <div class="testi-av" style="background:linear-gradient(135deg,#7C3AED,#A78BFA)">AK</div>
            <div>
              <div class="testi-name">Ahmed Khan</div>
              <div class="testi-role">General Store Owner, Lahore</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4 reveal">
        <div class="testi-card h-100">
          <div class="testi-stars">★★★★★</div>
          <p class="testi-text">"The AI Smart Lab gives me daily suggestions that actually work. I increased my profit by 28% in just 2 months by following the AI recommendations."</p>
          <div class="testi-author">
            <div class="testi-av" style="background:linear-gradient(135deg,#06B6D4,#22D3EE)">SR</div>
            <div>
              <div class="testi-name">Sara Raza</div>
              <div class="testi-role">Boutique Owner, Karachi</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4 reveal">
        <div class="testi-card h-100">
          <div class="testi-stars">★★★★★</div>
          <p class="testi-text">"POS billing itni fast hai ke customers wait nahi karte. Inventory alerts ne mujhe kabhi bhi out-of-stock situation se bachaya. Highly recommended!"</p>
          <div class="testi-author">
            <div class="testi-av" style="background:linear-gradient(135deg,#10B981,#34D399)">MF</div>
            <div>
              <div class="testi-name">Muhammad Farhan</div>
              <div class="testi-role">Electronics Shop, Islamabad</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════ FAQ ══════════════════════ -->
<section class="section" id="faq">
  <div class="container">
    <div class="row g-5 align-items-start">
      <div class="col-lg-4 reveal-l">
        <span class="section-badge"><i class="bi bi-question-circle-fill"></i> FAQ</span>
        <h2 class="section-title">Frequently Asked Questions</h2>
        <p class="section-sub">Can't find your answer? <a href="mailto:support@stockora.ai" style="color:var(--pr3)">Contact our support team</a>.</p>
      </div>
      <div class="col-lg-8 reveal-r">
        <div class="faq-item" onclick="toggleFaq(this)">
          <div class="faq-q">What is Stockora AI and how does it work? <i class="bi bi-plus-lg"></i></div>
          <div class="faq-a">Stockora AI is a cloud-based POS and inventory management system for Pakistani businesses. Shop owners get their own dashboard to manage billing, stock, customers, and finances. Admins can manage all shops, subscriptions, and view platform analytics.</div>
        </div>
        <div class="faq-item" onclick="toggleFaq(this)">
          <div class="faq-q">Do I need technical knowledge to use it? <i class="bi bi-plus-lg"></i></div>
          <div class="faq-a">No! Stockora AI is designed for everyday shop owners. The interface is simple, intuitive, and available in a way that's easy to understand. If you need help, our support team is just a message away.</div>
        </div>
        <div class="faq-item" onclick="toggleFaq(this)">
          <div class="faq-q">Can I access it from my mobile phone? <i class="bi bi-plus-lg"></i></div>
          <div class="faq-a">Yes! Stockora AI is fully responsive and works perfectly on mobile phones, tablets, and desktops. You can manage your shop from anywhere, anytime.</div>
        </div>
        <div class="faq-item" onclick="toggleFaq(this)">
          <div class="faq-q">Is my data safe and secure? <i class="bi bi-plus-lg"></i></div>
          <div class="faq-a">Absolutely. All data is encrypted, backed up regularly, and stored securely. We use industry-standard security practices to protect your business data at all times.</div>
        </div>
        <div class="faq-item" onclick="toggleFaq(this)">
          <div class="faq-q">Can I print receipts and invoices? <i class="bi bi-plus-lg"></i></div>
          <div class="faq-a">Yes! You can print thermal receipts, A4 invoices, or share them directly via WhatsApp with your customers. Multiple invoice formats are supported.</div>
        </div>
        <div class="faq-item" onclick="toggleFaq(this)">
          <div class="faq-q">What's included in the free trial? <i class="bi bi-plus-lg"></i></div>
          <div class="faq-a">The free trial gives you full access to all features including POS billing, inventory, analytics, and AI Smart Lab for 7 days. No credit card required to start.</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════ CTA BANNER ══════════════════════ -->
<section class="section" style="padding:4rem 0">
  <div class="container">
    <div class="reveal cta-banner-inner" style="background:linear-gradient(135deg,rgba(124,58,237,.2),rgba(6,182,212,.12));border:1px solid rgba(139,92,246,.25);border-radius:28px;padding:4rem 3rem;text-align:center;position:relative;overflow:hidden;">
      <div style="position:absolute;top:-80px;left:-80px;width:300px;height:300px;background:radial-gradient(circle,rgba(124,58,237,.2),transparent 65%);pointer-events:none"></div>
      <div style="position:absolute;bottom:-80px;right:-80px;width:300px;height:300px;background:radial-gradient(circle,rgba(6,182,212,.15),transparent 65%);pointer-events:none"></div>
      <div style="position:relative;z-index:1">
        <div style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(124,58,237,.15);border:1px solid rgba(167,139,250,.25);border-radius:30px;padding:.35rem 1rem;font-size:.75rem;font-weight:700;color:#A78BFA;letter-spacing:.5px;text-transform:uppercase;margin-bottom:1.25rem">
          <i class="bi bi-lightning-charge-fill"></i> Start Today, Free
        </div>
        <h2 style="font-size:clamp(2rem,4vw,3.2rem);font-weight:900;color:#fff;letter-spacing:-1.5px;margin-bottom:1rem">Ready to Transform Your Business?</h2>
        <p style="font-size:1rem;color:rgba(226,232,240,.6);max-width:500px;margin:0 auto 2rem;line-height:1.7">Join 500+ shop owners who already use Stockora AI to run smarter, sell more, and grow faster.</p>
        <div class="cta-banner-btns" style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap">
          <a href="<?= BASE_URL ?>/register.php" class="btn-hero-primary" style="font-size:1rem;padding:1rem 2.2rem">
            <i class="bi bi-rocket-takeoff-fill"></i> Start Free Trial — 7 Days
          </a>
          <a href="#demo" class="btn-hero-sec" style="font-size:1rem;padding:1rem 2.2rem">
            <i class="bi bi-play-fill"></i> Watch Demo First
          </a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════ FOOTER ══════════════════════ -->
<footer class="site-footer">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-4">
        <div class="footer-logo">
          <div class="footer-logo-icon"><i class="bi bi-cpu-fill"></i></div>
          <span class="footer-logo-name">Stockora&nbsp;<span class="ai">AI</span></span>
        </div>
        <p class="footer-tagline">Intelligent POS & Inventory Management Platform for modern Pakistani businesses.</p>
        <div class="footer-socials">
          <a href="#" class="social-btn"><i class="bi bi-facebook"></i></a>
          <a href="#" class="social-btn"><i class="bi bi-instagram"></i></a>
          <a href="#" class="social-btn"><i class="bi bi-whatsapp"></i></a>
          <a href="#" class="social-btn"><i class="bi bi-linkedin"></i></a>
        </div>
      </div>
      <div class="col-6 col-md-2 offset-md-1">
        <div class="footer-col-title">Product</div>
        <a href="#features"  class="footer-link">Features</a>
        <a href="#pricing"   class="footer-link">Pricing</a>
        <a href="#demo"      class="footer-link">Demo</a>
        <a href="<?= BASE_URL ?>/login.php" class="footer-link">Login</a>
      </div>
      <div class="col-6 col-md-2">
        <div class="footer-col-title">Company</div>
        <a href="#" class="footer-link">About Us</a>
        <a href="#" class="footer-link">Blog</a>
        <a href="#" class="footer-link">Careers</a>
        <a href="#" class="footer-link">Contact</a>
      </div>
      <div class="col-6 col-md-3">
        <div class="footer-col-title">Support</div>
        <a href="mailto:support@stockora.ai" class="footer-link">support@stockora.ai</a>
        <a href="#faq"  class="footer-link">FAQ</a>
        <a href="#"     class="footer-link">Privacy Policy</a>
        <a href="#"     class="footer-link">Terms of Service</a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© 2026 Stockora AI · v2.0 · All rights reserved</span>
      <span>Made with <i class="bi bi-heart-fill" style="color:#ea5455;font-size:.75rem"></i> for Pakistani Businesses</span>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Mobile hamburger menu */
var hamburgerBtn = document.getElementById('hamburgerBtn');
var mobileMenu   = document.getElementById('mobileMenu');
var mobileClose  = document.getElementById('mobileMenuClose');
function openMobileMenu(){
  mobileMenu.classList.add('open');
  hamburgerBtn.classList.add('open');
  document.body.style.overflow='hidden';
}
function closeMobileMenu(){
  mobileMenu.classList.remove('open');
  hamburgerBtn.classList.remove('open');
  document.body.style.overflow='';
}
if(hamburgerBtn) hamburgerBtn.addEventListener('click', openMobileMenu);
if(mobileClose)  mobileClose.addEventListener('click', closeMobileMenu);

/* Navbar scroll effect */
var navbar = document.getElementById('navbar');
window.addEventListener('scroll', function() {
  if (window.scrollY > 60) navbar.classList.add('scrolled');
  else navbar.classList.remove('scrolled');
}, {passive:true});

/* Scroll reveal */
var revealEls = document.querySelectorAll('.reveal,.reveal-l,.reveal-r');
var observer = new IntersectionObserver(function(entries) {
  entries.forEach(function(e) {
    if (e.isIntersecting) {
      e.target.classList.add('visible');
      observer.unobserve(e.target);
    }
  });
}, {threshold:0.12, rootMargin:'0px 0px -40px 0px'});
revealEls.forEach(function(el) { observer.observe(el); });

/* FAQ toggle */
function toggleFaq(item) {
  var wasOpen = item.classList.contains('open');
  document.querySelectorAll('.faq-item.open').forEach(function(i){ i.classList.remove('open'); });
  if (!wasOpen) item.classList.add('open');
}

/* Smooth scroll for anchor links */
document.querySelectorAll('a[href^="#"]').forEach(function(a) {
  a.addEventListener('click', function(e) {
    var target = document.querySelector(this.getAttribute('href'));
    if (target) {
      e.preventDefault();
      target.scrollIntoView({behavior:'smooth', block:'start'});
    }
  });
});

/* Stats counter animation */
function animateCounter(el) {
  var target = Number(el.dataset.counterTarget);
  var suffix = el.dataset.counterSuffix || '';
  var decimals = Number(el.dataset.counterDecimals || 0);
  var format = el.dataset.counterFormat || '';
  var start = null;
  var duration = 1700;

  function formatValue(value) {
    if (format === 'millions') return (value / 1000000).toFixed(1).replace(/\.0$/, '') + suffix;
    return value.toFixed(decimals) + suffix;
  }

  function step(timestamp) {
    if (start === null) start = timestamp;
    var progress = Math.min((timestamp - start) / duration, 1);
    var eased = 1 - Math.pow(1 - progress, 3);
    el.textContent = formatValue(target * eased);
    if (progress < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}
var statsObserver = new IntersectionObserver(function(entries) {
  entries.forEach(function(e) {
    if (e.isIntersecting) {
      animateCounter(e.target);
      statsObserver.unobserve(e.target);
    }
  });
}, {threshold: 0.5});
document.querySelectorAll('[data-counter-target]').forEach(function(el) {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  statsObserver.observe(el);
});
</script>
</body>
</html>

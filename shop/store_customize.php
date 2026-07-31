<?php
require_once '../includes/functions.php';
requireShop();
requirePremiumFeature((int)$_SESSION['shop_id'], 'Commerce Cloud');
require_once '../includes/shop_layout.php';
$shopId    = (int)$_SESSION['shop_id'];
$shopName  = $_SESSION['shop_name'] ?? 'My Shop';
$pageTitle = 'Store Customizer';
$shopSlug  = $db = null;

/* ══════════════════════════════════════════════════════
   DB + SLUG
   ══════════════════════════════════════════════════════ */
$db = getDB();
$slugRow = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='store_slug' LIMIT 1");
$slugRow->execute([$shopId]);
$slug = $slugRow->fetchColumn() ?: '';
$previewUrl = BASE_URL . '/store.php?s=' . urlencode($slug);

/* ══════════════════════════════════════════════════════
   LOAD ALL SETTINGS
   ══════════════════════════════════════════════════════ */
$allSettings = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE shop_id=?");
$allSettings->execute([$shopId]);
$cfg = [];
while ($row = $allSettings->fetch(PDO::FETCH_ASSOC)) {
    $cfg[$row['setting_key']] = $row['setting_value'];
}

/* ══════════════════════════════════════════════════════
   AJAX HANDLERS
   ══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');

    $action = $_POST['action'];

    /* ── Save all customization fields ── */
    if ($action === 'save_customization') {
        $allowedKeys = [
            'store_name','store_tagline','store_description','store_city','store_address',
            'store_phone','store_whatsapp','store_email',
            'store_theme','store_accent_color','store_font','store_font_size',
            'store_heading_font','store_body_font',
            'store_announcement','store_announcement_on','store_announcement_bg',
            'store_hero_style','store_hero_height','store_hero_overlay',
            'store_grid_columns','store_card_style','store_card_radius','store_card_shadow',
            'store_show_search','store_show_categories','store_show_featured','store_show_reviews',
            'store_currency','store_free_delivery_min','store_footer_text',
            'store_facebook','store_instagram','store_tiktok','store_youtube','store_twitter',
            'store_header_layout','store_nav_style','store_footer_columns',
            'store_btn_style','store_btn_radius','store_spacing',
            'store_container_width','store_animations',
            /* SEO */
            'seo_title','seo_description','seo_keywords',
            'seo_og_title','seo_og_description','seo_og_image',
            /* Analytics */
            'analytics_ga_id','analytics_gtm_id','analytics_fb_pixel',
            'analytics_header_scripts','analytics_footer_scripts',
            /* Custom code */
            'custom_html_head','custom_css','custom_js',
            'custom_html_body_start','custom_html_body_end',
            /* Maintenance */
            'maintenance_mode','maintenance_message',
            /* Sections order/config */
            'sections_order','sections_config',
        ];

        $saved = 0;
        foreach ($allowedKeys as $key) {
            if (isset($_POST[$key])) {
                $val = $_POST[$key];
                $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key=?")->execute([$shopId,$key]);
                $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES (?,?,?,NOW(),NOW())")->execute([$shopId,$key,$val]);
                $saved++;
            }
        }
        echo json_encode(['success'=>true,'saved'=>$saved]);
        exit;
    }

    /* ── Save sections order/config ── */
    if ($action === 'save_sections') {
        $sectionsJson = $_POST['sections'] ?? '[]';
        $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='sections_config'")->execute([$shopId]);
        $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES (?,?,?,NOW(),NOW())")->execute([$shopId,'sections_config',$sectionsJson]);
        echo json_encode(['success'=>true]);
        exit;
    }

    /* ── Save custom code only ── */
    if ($action === 'save_code') {
        $codeKeys = ['custom_html_head','custom_css','custom_js','custom_html_body_start','custom_html_body_end'];
        foreach ($codeKeys as $key) {
            if (isset($_POST[$key])) {
                $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key=?")->execute([$shopId,$key]);
                $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES (?,?,?,NOW(),NOW())")->execute([$shopId,$key,$_POST[$key]]);
            }
        }
        echo json_encode(['success'=>true]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

/* ── Sections default config ── */
$sectionsRaw = $cfg['sections_config'] ?? '';
$sections = $sectionsRaw ? (json_decode($sectionsRaw, true) ?: []) : [];
$defaultSections = [
    ['id'=>'hero',           'label'=>'Hero Banner',        'icon'=>'bi-image-fill',         'enabled'=>true,  'bg'=>'','padding'=>'lg','animation'=>'fade'],
    ['id'=>'announcement',   'label'=>'Announcement Bar',   'icon'=>'bi-megaphone-fill',      'enabled'=>true,  'bg'=>'','padding'=>'sm','animation'=>'none'],
    ['id'=>'featured',       'label'=>'Featured Products',  'icon'=>'bi-star-fill',           'enabled'=>true,  'bg'=>'','padding'=>'lg','animation'=>'fade'],
    ['id'=>'categories',     'label'=>'Categories Grid',    'icon'=>'bi-grid-fill',           'enabled'=>true,  'bg'=>'','padding'=>'md','animation'=>'slide'],
    ['id'=>'flash_sale',     'label'=>'Flash Sale',         'icon'=>'bi-lightning-charge-fill','enabled'=>false,'bg'=>'','padding'=>'md','animation'=>'bounce'],
    ['id'=>'countdown',      'label'=>'Countdown Timer',    'icon'=>'bi-alarm-fill',          'enabled'=>false, 'bg'=>'','padding'=>'md','animation'=>'none'],
    ['id'=>'best_sellers',   'label'=>'Best Sellers',       'icon'=>'bi-trophy-fill',         'enabled'=>true,  'bg'=>'','padding'=>'lg','animation'=>'fade'],
    ['id'=>'new_arrivals',   'label'=>'New Arrivals',       'icon'=>'bi-bag-plus-fill',       'enabled'=>true,  'bg'=>'','padding'=>'lg','animation'=>'fade'],
    ['id'=>'testimonials',   'label'=>'Testimonials',       'icon'=>'bi-chat-quote-fill',     'enabled'=>false, 'bg'=>'','padding'=>'lg','animation'=>'fade'],
    ['id'=>'newsletter',     'label'=>'Newsletter',         'icon'=>'bi-envelope-fill',       'enabled'=>true,  'bg'=>'','padding'=>'md','animation'=>'none'],
    ['id'=>'brands',         'label'=>'Brand Logos',        'icon'=>'bi-patch-check-fill',    'enabled'=>false, 'bg'=>'','padding'=>'sm','animation'=>'none'],
    ['id'=>'blog',           'label'=>'Blog Posts',         'icon'=>'bi-newspaper',           'enabled'=>false, 'bg'=>'','padding'=>'lg','animation'=>'fade'],
    ['id'=>'gallery',        'label'=>'Image Gallery',      'icon'=>'bi-images',              'enabled'=>false, 'bg'=>'','padding'=>'md','animation'=>'zoom'],
    ['id'=>'video_banner',   'label'=>'Video Banner',       'icon'=>'bi-play-circle-fill',    'enabled'=>false, 'bg'=>'','padding'=>'none','animation'=>'none'],
    ['id'=>'services',       'label'=>'Services',           'icon'=>'bi-tools',               'enabled'=>false, 'bg'=>'','padding'=>'lg','animation'=>'fade'],
    ['id'=>'faq',            'label'=>'FAQ',                'icon'=>'bi-question-circle-fill','enabled'=>false, 'bg'=>'','padding'=>'lg','animation'=>'none'],
    ['id'=>'stats',          'label'=>'Store Statistics',   'icon'=>'bi-bar-chart-fill',      'enabled'=>false, 'bg'=>'','padding'=>'md','animation'=>'count'],
    ['id'=>'custom_html',    'label'=>'Custom HTML Block',  'icon'=>'bi-code-slash',          'enabled'=>false, 'bg'=>'','padding'=>'none','animation'=>'none'],
];
if (empty($sections)) $sections = $defaultSections;

/* Merge any new default sections not yet in DB */
$existingIds = array_column($sections, 'id');
foreach ($defaultSections as $ds) {
    if (!in_array($ds['id'], $existingIds)) $sections[] = $ds;
}

shopHeader($pageTitle, 'store_customize');
?>

<!-- ════════════════════════════════════════════════════════════
     ENTERPRISE LIVE STORE CUSTOMIZER STYLES
     ════════════════════════════════════════════════════════════ -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<!-- CodeMirror -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css">
<!-- SortableJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>

<style>
/* ══ Global Reset ══ */
.cz-app { font-family:'Inter',sans-serif; height:calc(100vh - 60px); display:flex; overflow:hidden; }

/* ══ SIDEBAR ══ */
.cz-sidebar {
  width:340px;flex-shrink:0;
  background:#080e1c;
  border-right:1px solid rgba(255,255,255,.06);
  display:flex;flex-direction:column;
  overflow:hidden;
}
.cz-topbar {
  display:flex;align-items:center;gap:.6rem;
  padding:.75rem 1rem;
  border-bottom:1px solid rgba(255,255,255,.06);
  background:#060c18;
  flex-shrink:0;
}
.cz-back-link {
  display:flex;align-items:center;justify-content:center;
  width:30px;height:30px;border-radius:8px;
  background:rgba(255,255,255,.06);color:rgba(255,255,255,.5);
  text-decoration:none;font-size:.9rem;transition:all .18s;flex-shrink:0;
}
.cz-back-link:hover { background:rgba(255,255,255,.1);color:#fff; }
.cz-brand { font-size:.82rem;font-weight:800;color:#fff;flex:1; }
.cz-brand span { color:#6366f1; }
.cz-preview-open {
  display:flex;align-items:center;gap:.3rem;font-size:.7rem;font-weight:700;
  padding:.3rem .65rem;border-radius:7px;
  background:rgba(99,102,241,.12);color:#818cf8;border:1px solid rgba(99,102,241,.2);
  text-decoration:none;white-space:nowrap;transition:all .18s;
}
.cz-preview-open:hover { background:rgba(99,102,241,.2);color:#a5b4fc; }

/* ══ TAB NAV ══ */
.cz-tabs {
  display:flex;overflow-x:auto;border-bottom:1px solid rgba(255,255,255,.05);
  flex-shrink:0;scrollbar-width:none;
}
.cz-tabs::-webkit-scrollbar { display:none; }
.cz-tab {
  display:flex;flex-direction:column;align-items:center;gap:.18rem;
  padding:.55rem .5rem;min-width:52px;flex-shrink:0;
  font-size:.58rem;font-weight:700;color:rgba(255,255,255,.38);
  cursor:pointer;background:none;border:none;
  border-bottom:2px solid transparent;transition:all .2s;
  font-family:'Inter',sans-serif;text-transform:uppercase;letter-spacing:.3px;
}
.cz-tab i { font-size:.95rem; }
.cz-tab:hover { color:rgba(255,255,255,.65); }
.cz-tab.active { color:#a5b4fc;border-bottom-color:#6366f1; }

/* ══ PANEL AREA ══ */
.cz-panels { flex:1;overflow-y:auto;padding:.85rem .9rem; }
.cz-panels::-webkit-scrollbar { width:3px; }
.cz-panels::-webkit-scrollbar-track { background:transparent; }
.cz-panels::-webkit-scrollbar-thumb { background:rgba(255,255,255,.08);border-radius:4px; }
.cz-panel { display:none; }
.cz-panel.active { display:block; }

/* ══ Section helpers ══ */
.cz-sec { margin-bottom:1.35rem; }
.cz-sec-label {
  font-size:.6rem;font-weight:800;color:rgba(255,255,255,.22);
  text-transform:uppercase;letter-spacing:1.2px;margin-bottom:.65rem;
  padding-bottom:.35rem;border-bottom:1px solid rgba(255,255,255,.05);
  display:flex;align-items:center;gap:.35rem;
}
.cz-sec-label i { font-size:.75rem; }
.cz-row { margin-bottom:.65rem; }
.cz-lbl { font-size:.7rem;font-weight:600;color:rgba(255,255,255,.48);margin-bottom:.28rem;display:block; }
.cz-inp,.cz-sel,.cz-ta {
  width:100%;padding:.5rem .72rem;
  background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.08);
  border-radius:9px;color:#fff;font-size:.78rem;
  outline:none;font-family:'Inter',sans-serif;transition:all .18s;
}
.cz-inp::placeholder,.cz-ta::placeholder { color:rgba(255,255,255,.18); }
.cz-inp:focus,.cz-sel:focus,.cz-ta:focus { border-color:rgba(99,102,241,.45);background:rgba(255,255,255,.07); }
.cz-sel option { background:#0e1628;color:#fff; }
.cz-ta { resize:vertical;min-height:58px;line-height:1.5; }

/* ══ Toggle ══ */
.cz-toggle-row {
  display:flex;align-items:center;justify-content:space-between;
  padding:.4rem 0;
}
.cz-toggle-lbl { font-size:.76rem;font-weight:600;color:rgba(255,255,255,.6);flex:1; }
.cz-toggle-sub { font-size:.65rem;color:rgba(255,255,255,.3);margin-top:.08rem; }
.cz-toggle { position:relative;width:36px;height:19px;flex-shrink:0; }
.cz-toggle input { opacity:0;width:0;height:0; }
.cz-toggle-slider {
  position:absolute;inset:0;background:rgba(255,255,255,.1);
  border-radius:20px;cursor:pointer;transition:.22s;
}
.cz-toggle-slider::before {
  content:'';position:absolute;height:13px;width:13px;
  left:3px;bottom:3px;background:rgba(255,255,255,.4);
  border-radius:50%;transition:.22s;
}
.cz-toggle input:checked + .cz-toggle-slider { background:#6366f1; }
.cz-toggle input:checked + .cz-toggle-slider::before { transform:translateX(17px);background:#fff; }

/* ══ Color swatches ══ */
.cz-swatches { display:flex;flex-wrap:wrap;gap:.45rem;margin-bottom:.45rem; }
.cz-swatch {
  width:28px;height:28px;border-radius:8px;cursor:pointer;
  border:2px solid transparent;transition:all .18s;flex-shrink:0;
}
.cz-swatch.active { border-color:#fff;transform:scale(1.18); }
.cz-swatch:hover:not(.active) { transform:scale(1.1); }
.cz-color-custom { display:flex;align-items:center;gap:.5rem;margin-top:.35rem; }
.cz-color-custom input[type=color] { width:32px;height:26px;border:none;background:none;cursor:pointer;border-radius:6px;overflow:hidden; }
.cz-color-custom span { font-size:.7rem;color:rgba(255,255,255,.35); }

/* ══ Font grid ══ */
.cz-font-grid { display:grid;grid-template-columns:1fr 1fr;gap:.4rem; }
.cz-font-opt {
  padding:.6rem .55rem;border-radius:10px;cursor:pointer;
  border:1.5px solid rgba(255,255,255,.07);background:rgba(255,255,255,.03);
  transition:all .2s;text-align:center;
}
.cz-font-opt .font-sample { font-size:.95rem;font-weight:700;color:rgba(255,255,255,.8);display:block;margin-bottom:.1rem; }
.cz-font-opt .font-meta   { font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:rgba(255,255,255,.28); }
.cz-font-opt.active { border-color:#6366f1;background:rgba(99,102,241,.12); }
.cz-font-opt.active .font-sample { color:#c4b5fd; }
.cz-font-opt:hover:not(.active) { border-color:rgba(255,255,255,.18);background:rgba(255,255,255,.05); }

/* ══ Range slider ══ */
.cz-range { width:100%;accent-color:#6366f1;cursor:pointer; }
.cz-range-row { display:flex;align-items:center;gap:.6rem; }
.cz-range-val { font-size:.72rem;font-weight:800;color:#a5b4fc;min-width:28px;text-align:right; }

/* ══ Section Builder ══ */
.sec-list { display:flex;flex-direction:column;gap:.45rem; }
.sec-item {
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);
  border-radius:11px;padding:.65rem .75rem;
  display:flex;align-items:center;gap:.65rem;
  cursor:grab;transition:all .2s;position:relative;
}
.sec-item:active { cursor:grabbing; }
.sec-item:hover { background:rgba(255,255,255,.07);border-color:rgba(99,102,241,.25); }
.sec-item.sec-disabled { opacity:.45; }
.sec-item.sortable-ghost { opacity:.3;background:rgba(99,102,241,.1);border-color:rgba(99,102,241,.4); }
.sec-item.sortable-chosen { border-color:rgba(99,102,241,.5);box-shadow:0 4px 16px rgba(99,102,241,.2); }
.sec-drag-handle { color:rgba(255,255,255,.25);font-size:.9rem;cursor:grab;flex-shrink:0; }
.sec-icon { font-size:.9rem;flex-shrink:0;width:28px;height:28px;border-radius:7px;background:rgba(99,102,241,.12);display:flex;align-items:center;justify-content:center;color:#818cf8; }
.sec-info { flex:1;min-width:0; }
.sec-name { font-size:.76rem;font-weight:800;color:rgba(255,255,255,.8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.sec-status { font-size:.6rem;color:rgba(255,255,255,.3);margin-top:.05rem; }
.sec-actions { display:flex;gap:.25rem;flex-shrink:0; }
.sec-action-btn {
  width:24px;height:24px;border-radius:6px;border:none;cursor:pointer;
  background:rgba(255,255,255,.06);color:rgba(255,255,255,.4);
  font-size:.72rem;display:flex;align-items:center;justify-content:center;
  transition:all .18s;
}
.sec-action-btn:hover { background:rgba(99,102,241,.15);color:#818cf8; }
.sec-action-btn.del:hover { background:rgba(239,68,68,.12);color:#f87171; }

/* ══ CodeMirror overrides ══ */
.CodeMirror {
  height:200px;border-radius:9px;
  font-size:.78rem;font-family:'Fira Code','Monaco',monospace;
  border:1.5px solid rgba(255,255,255,.08);
}
.CodeMirror-focused { border-color:rgba(99,102,241,.45) !important; }
.cz-code-tabs { display:flex;gap:.3rem;margin-bottom:.5rem;flex-wrap:wrap; }
.cz-code-tab {
  font-size:.68rem;font-weight:700;padding:.28rem .7rem;border-radius:7px;cursor:pointer;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);
  color:rgba(255,255,255,.4);transition:all .18s;font-family:'Inter',sans-serif;
}
.cz-code-tab.active { background:rgba(99,102,241,.15);color:#a5b4fc;border-color:rgba(99,102,241,.3); }
.cz-code-editor-wrap { display:none; }
.cz-code-editor-wrap.active { display:block; }

/* ══ Inline sub-toggle groups ══ */
.cz-option-grid { display:grid;grid-template-columns:1fr 1fr;gap:.4rem; }
.cz-option-item {
  padding:.55rem .6rem;border-radius:9px;cursor:pointer;
  border:1.5px solid rgba(255,255,255,.07);background:rgba(255,255,255,.03);
  font-size:.72rem;font-weight:700;color:rgba(255,255,255,.55);
  text-align:center;transition:all .2s;
}
.cz-option-item.active { border-color:#6366f1;background:rgba(99,102,241,.12);color:#a5b4fc; }
.cz-option-item:hover:not(.active) { border-color:rgba(255,255,255,.15);color:rgba(255,255,255,.8); }

/* ══ Save bar ══ */
.cz-save-bar {
  padding:.7rem .9rem;border-top:1px solid rgba(255,255,255,.06);
  background:#060c18;display:flex;gap:.45rem;flex-shrink:0;
}
.cz-save-btn {
  flex:1;padding:.6rem;border-radius:10px;
  background:linear-gradient(135deg,#6366f1,#8b5cf6);
  border:none;color:#fff;font-size:.8rem;font-weight:800;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.4rem;
  transition:all .2s;font-family:'Inter',sans-serif;
}
.cz-save-btn:hover { transform:translateY(-1px);box-shadow:0 6px 18px rgba(99,102,241,.4); }
.cz-save-btn:disabled { opacity:.6;cursor:not-allowed;transform:none; }
.cz-open-btn {
  padding:.6rem .85rem;border-radius:10px;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);
  color:rgba(255,255,255,.55);font-size:.8rem;font-weight:700;
  cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:.35rem;
  transition:all .18s;font-family:'Inter',sans-serif;
}
.cz-open-btn:hover { background:rgba(255,255,255,.09);color:#fff; }

/* ══ PREVIEW PANE ══ */
.cz-preview {
  flex:1;display:flex;flex-direction:column;
  background:#050a14;overflow:hidden;
}
.cz-preview-bar {
  height:44px;background:#07101e;
  border-bottom:1px solid rgba(255,255,255,.05);
  display:flex;align-items:center;gap:.75rem;padding:0 1rem;
  flex-shrink:0;
}
.cz-dev-btns { display:flex;gap:.3rem; }
.cz-dev-btn {
  width:30px;height:28px;border-radius:7px;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.07);
  color:rgba(255,255,255,.35);cursor:pointer;font-size:.85rem;
  display:flex;align-items:center;justify-content:center;transition:all .18s;
}
.cz-dev-btn.active,.cz-dev-btn:hover { background:rgba(99,102,241,.18);border-color:rgba(99,102,241,.3);color:#a5b4fc; }
.cz-url-bar {
  flex:1;height:26px;border-radius:20px;
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);
  display:flex;align-items:center;padding:0 .75rem;gap:.4rem;
  font-size:.68rem;color:rgba(255,255,255,.25);overflow:hidden;white-space:nowrap;
}
.cz-preview-frame {
  flex:1;border:none;display:block;
  background:#050a14;transition:max-width .35s,margin .35s;
}
.cz-preview-frame.tablet { max-width:768px;margin:0 auto;border-left:1px solid rgba(255,255,255,.06);border-right:1px solid rgba(255,255,255,.06); }
.cz-preview-frame.mobile { max-width:390px;margin:0 auto;border-left:1px solid rgba(255,255,255,.06);border-right:1px solid rgba(255,255,255,.06); }
.cz-preview-overlay {
  position:absolute;inset:0;display:none;align-items:center;justify-content:center;
  background:rgba(5,10,20,.8);backdrop-filter:blur(4px);z-index:5;
  flex-direction:column;gap:.75rem;color:rgba(255,255,255,.5);font-size:.85rem;
}
.cz-preview-wrapper { flex:1;position:relative;overflow:hidden; }

/* ══ Toast ══ */
.cz-toast {
  position:fixed;bottom:1.5rem;right:1.5rem;z-index:999999;
  background:#0c1828;border-radius:14px;padding:.85rem 1.2rem;
  font-size:.82rem;font-weight:700;
  display:flex;align-items:center;gap:.55rem;
  box-shadow:0 8px 40px rgba(0,0,0,.5);max-width:300px;
  animation:czToastIn .3s cubic-bezier(.4,0,.2,1);
}
@keyframes czToastIn { from{opacity:0;transform:translateY(14px) scale(.95)} to{opacity:1;transform:none} }

/* ══ Theme mini-grid in sidebar ══ */
.cz-theme-grid { display:grid;grid-template-columns:1fr 1fr;gap:.55rem; }
.cz-theme-card {
  border-radius:11px;overflow:hidden;cursor:pointer;
  border:2px solid rgba(255,255,255,.07);transition:all .22s;
  background:#0a0e1a;
}
.cz-theme-card.active { border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.2); }
.cz-theme-card:hover:not(.active) { border-color:rgba(99,102,241,.35);transform:translateY(-2px); }
.cz-theme-prev { height:90px;position:relative;overflow:hidden; }
.cz-theme-foot { padding:.45rem .6rem;display:flex;align-items:center;justify-content:space-between; }
.cz-theme-name { font-size:.66rem;font-weight:800;color:rgba(255,255,255,.8); }
.cz-theme-tick {
  width:16px;height:16px;border-radius:50%;
  background:linear-gradient(135deg,#6366f1,#8b5cf6);
  display:none;align-items:center;justify-content:center;
  font-size:.55rem;color:#fff;flex-shrink:0;
}
.cz-theme-card.active .cz-theme-tick { display:flex; }

/* ══ Responsive ══ */
@media(max-width:900px) { .cz-sidebar{width:280px} }
@media(max-width:700px) { .cz-app{flex-direction:column;height:auto} .cz-sidebar{width:100%;max-height:55vh} .cz-preview{height:50vh;min-height:300px} }
</style>

<!-- ════════════════════════════════════════════════════════════
     MAIN APP LAYOUT
     ════════════════════════════════════════════════════════════ -->
<div class="cz-app">

  <!-- ████████████████ SIDEBAR ████████████████ -->
  <div class="cz-sidebar">

    <!-- Top bar -->
    <div class="cz-topbar">
      <a href="<?= BASE_URL ?>/shop/commerce_cloud.php" class="cz-back-link" title="Back"><i class="bi bi-arrow-left"></i></a>
      <span class="cz-brand">Store <span>Customizer</span></span>
      <a href="<?= htmlspecialchars($previewUrl) ?>" target="_blank" class="cz-preview-open">
        <i class="bi bi-box-arrow-up-right"></i> Live
      </a>
    </div>

    <!-- Tab navigation -->
    <div class="cz-tabs" id="czTabs">
      <button class="cz-tab active" data-panel="theme"    onclick="czShowPanel('theme',this)"><i class="bi bi-palette-fill"></i>Theme</button>
      <button class="cz-tab"         data-panel="colors"   onclick="czShowPanel('colors',this)"><i class="bi bi-droplet-fill"></i>Colors</button>
      <button class="cz-tab"         data-panel="typo"     onclick="czShowPanel('typo',this)"><i class="bi bi-type"></i>Fonts</button>
      <button class="cz-tab"         data-panel="store"    onclick="czShowPanel('store',this)"><i class="bi bi-shop"></i>Store</button>
      <button class="cz-tab"         data-panel="layout"   onclick="czShowPanel('layout',this)"><i class="bi bi-layout-wtf"></i>Layout</button>
      <button class="cz-tab"         data-panel="sections" onclick="czShowPanel('sections',this)"><i class="bi bi-stack"></i>Sections</button>
      <button class="cz-tab"         data-panel="contact"  onclick="czShowPanel('contact',this)"><i class="bi bi-telephone-fill"></i>Contact</button>
      <button class="cz-tab"         data-panel="social"   onclick="czShowPanel('social',this)"><i class="bi bi-share-fill"></i>Social</button>
      <button class="cz-tab"         data-panel="seo"      onclick="czShowPanel('seo',this)"><i class="bi bi-graph-up-arrow"></i>SEO</button>
      <button class="cz-tab"         data-panel="analytics"onclick="czShowPanel('analytics',this)"><i class="bi bi-bar-chart-fill"></i>Analytics</button>
      <button class="cz-tab"         data-panel="code"     onclick="czShowPanel('code',this)"><i class="bi bi-code-slash"></i>Code</button>
    </div>

    <!-- ═══ PANELS ═══ -->
    <div class="cz-panels" id="czPanels">

      <!-- ████ THEME PANEL ████ -->
      <div class="cz-panel active" id="panel-theme">
        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-palette-fill"></i> Active Theme</div>
          <div class="cz-theme-grid" id="czThemeGrid">
            <?php
            $themeList = [
              ['Luxe Dark','linear-gradient(160deg,#060d1a,#0d1626)','#6366f1'],
              ['Pure White','linear-gradient(160deg,#f8fafc,#eef2ff)','#6366f1'],
              ['Neon City','linear-gradient(160deg,#0d0d1a,#1a0533)','#f0abfc'],
              ['Forest','linear-gradient(160deg,#052e16,#064e3b)','#4ade80'],
              ['Sunset','linear-gradient(160deg,#1c0a00,#431407)','#fb923c'],
              ['Ocean Blue','linear-gradient(160deg,#030d1f,#0c2461)','#38bdf8'],
              ['Metro Store','linear-gradient(160deg,#0c1828,#152338)','#6366f1'],
              ['Tech Pro','linear-gradient(160deg,#071628,#0f2340)','#06b6d4'],
            ];
            $curTheme = $cfg['store_theme'] ?? 'Luxe Dark';
            foreach ($themeList as [$tname,$tbg,$tacc]): ?>
            <div class="cz-theme-card <?= $curTheme===$tname?'active':'' ?>"
                 onclick="czSelectTheme('<?= addslashes($tname) ?>',this)">
              <div class="cz-theme-prev" style="background:<?= $tbg ?>">
                <div style="position:absolute;top:8px;left:8px;right:8px;height:6px;border-radius:3px;background:<?= $tacc ?>;opacity:.8"></div>
                <div style="position:absolute;top:20px;left:8px;width:50%;height:5px;border-radius:2px;background:rgba(255,255,255,.5)"></div>
                <div style="position:absolute;top:30px;left:8px;width:35%;height:3px;border-radius:2px;background:rgba(255,255,255,.3)"></div>
                <div style="position:absolute;bottom:8px;left:8px;right:8px;display:flex;gap:3px">
                  <?php for($ti=0;$ti<3;$ti++): ?>
                  <div style="flex:1;height:18px;border-radius:4px;background:rgba(255,255,255,0.<?=8+$ti*2?>)"></div>
                  <?php endfor; ?>
                </div>
              </div>
              <div class="cz-theme-foot">
                <span class="cz-theme-name"><?= $tname ?></span>
                <span class="cz-theme-tick"><i class="bi bi-check-lg"></i></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="f_theme" value="<?= htmlspecialchars($curTheme) ?>">
          <a href="<?= BASE_URL ?>/shop/theme_marketplace.php" style="display:flex;align-items:center;justify-content:center;gap:.4rem;margin-top:.7rem;padding:.5rem;background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.18);border-radius:9px;color:#818cf8;text-decoration:none;font-size:.74rem;font-weight:700;transition:all .18s" onmouseover="this.style.background='rgba(99,102,241,.15)'" onmouseout="this.style.background='rgba(99,102,241,.08)'">
            <i class="bi bi-grid-1x2-fill"></i> Browse All 20 Themes →
          </a>
        </div>
      </div><!-- /panel-theme -->

      <!-- ████ COLORS PANEL ████ -->
      <div class="cz-panel" id="panel-colors">

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-palette"></i> Accent Color</div>
          <?php
          $accentPalettes = [
            ['#6366f1','Indigo'],['#8b5cf6','Violet'],['#ec4899','Pink'],['#f43f5e','Rose'],
            ['#f59e0b','Amber'],['#10b981','Emerald'],['#06b6d4','Cyan'],['#3b82f6','Blue'],
            ['#ef4444','Red'],['#14b8a6','Teal'],['#f97316','Orange'],['#ffffff','White'],
          ];
          $curAccent = $cfg['store_accent_color'] ?? '#6366f1';
          ?>
          <div class="cz-swatches" id="czAccentSwatches">
            <?php foreach($accentPalettes as [$hex,$nm]): ?>
            <div class="cz-swatch <?= $curAccent===$hex?'active':'' ?>"
                 style="background:<?= $hex ?>" title="<?= $nm ?>"
                 onclick="czSelectAccent('<?= $hex ?>',this)"></div>
            <?php endforeach; ?>
          </div>
          <div class="cz-color-custom">
            <input type="color" id="customAccentPicker" value="<?= htmlspecialchars($curAccent) ?>" onchange="czSelectAccent(this.value,null,true)">
            <span>Custom accent color</span>
          </div>
          <input type="hidden" id="f_accent" value="<?= htmlspecialchars($curAccent) ?>">
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-megaphone"></i> Announcement Bar Color</div>
          <?php
          $annBgs = ['#6366f1','#ec4899','#10b981','#f59e0b','#ef4444','#06b6d4','#7c3aed','#1e1e2e'];
          $curAnn = $cfg['store_announcement_bg'] ?? '#6366f1';
          ?>
          <div class="cz-swatches" id="czAnnSwatches">
            <?php foreach($annBgs as $hex): ?>
            <div class="cz-swatch <?= $curAnn===$hex?'active':'' ?>"
                 style="background:<?= $hex ?>" title="<?= $hex ?>"
                 onclick="czSelectAnnBg('<?= $hex ?>',this)"></div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="f_ann_bg" value="<?= htmlspecialchars($curAnn) ?>">
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-card-image"></i> Hero Overlay Opacity</div>
          <div class="cz-range-row">
            <input type="range" class="cz-range" id="f_hero_overlay" min="0" max="80" value="<?= (int)($cfg['store_hero_overlay'] ?? 40) ?>" oninput="czRange('hero_overlay',this.value)">
            <span class="cz-range-val" id="rv_hero_overlay"><?= (int)($cfg['store_hero_overlay'] ?? 40) ?>%</span>
          </div>
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-shadows"></i> Card Shadow</div>
          <div class="cz-option-grid">
            <?php foreach(['none'=>'None','sm'=>'Soft','md'=>'Medium','lg'=>'Strong','glow'=>'Glow'] as $sv=>$sl): ?>
            <div class="cz-option-item <?= ($cfg['store_card_shadow']??'md')===$sv?'active':'' ?>"
                 onclick="czOption('f_card_shadow',this,'<?= $sv ?>')">
              <?= $sl ?>
            </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="f_card_shadow" value="<?= htmlspecialchars($cfg['store_card_shadow'] ?? 'md') ?>">
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-border-width"></i> Card Border Radius</div>
          <div class="cz-range-row">
            <input type="range" class="cz-range" id="f_card_radius" min="0" max="24" value="<?= (int)($cfg['store_card_radius'] ?? 12) ?>" oninput="czRange('card_radius',this.value)">
            <span class="cz-range-val" id="rv_card_radius"><?= (int)($cfg['store_card_radius'] ?? 12) ?>px</span>
          </div>
        </div>

      </div><!-- /panel-colors -->

      <!-- ████ TYPOGRAPHY PANEL ████ -->
      <div class="cz-panel" id="panel-typo">

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-type-h1"></i> Heading Font</div>
          <?php
          $fonts = ['Inter'=>'Modern','Poppins'=>'Rounded','Playfair Display'=>'Elegant','Space Grotesk'=>'Techy','Nunito'=>'Friendly','Roboto'=>'Classic','Lora'=>'Serif','DM Sans'=>'Clean'];
          $curFont = $cfg['store_heading_font'] ?? ($cfg['store_font'] ?? 'Inter');
          ?>
          <div class="cz-font-grid">
            <?php foreach($fonts as $fn=>$fl): ?>
            <div class="cz-font-opt <?= $curFont===$fn?'active':'' ?>"
                 style="font-family:'<?= $fn ?>',sans-serif"
                 onclick="czSelectFont('heading','<?= $fn ?>',this)">
              <span class="font-sample"><?= $fl ?></span>
              <span class="font-meta"><?= $fn ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="f_heading_font" value="<?= htmlspecialchars($curFont) ?>">
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-paragraph"></i> Body Font</div>
          <?php $curBodyFont = $cfg['store_body_font'] ?? ($cfg['store_font'] ?? 'Inter'); ?>
          <div class="cz-font-grid" id="bodyFontGrid">
            <?php foreach($fonts as $fn=>$fl): ?>
            <div class="cz-font-opt <?= $curBodyFont===$fn?'active':'' ?>"
                 style="font-family:'<?= $fn ?>',sans-serif"
                 onclick="czSelectFont('body','<?= $fn ?>',this)">
              <span class="font-sample"><?= $fl ?></span>
              <span class="font-meta"><?= $fn ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="f_body_font" value="<?= htmlspecialchars($curBodyFont) ?>">
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-text-paragraph"></i> Base Font Size</div>
          <div class="cz-range-row">
            <input type="range" class="cz-range" id="f_font_size" min="13" max="20" value="<?= (int)($cfg['store_font_size'] ?? 16) ?>" oninput="czRange('font_size',this.value)">
            <span class="cz-range-val" id="rv_font_size"><?= (int)($cfg['store_font_size'] ?? 16) ?>px</span>
          </div>
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-arrows-expand"></i> Spacing Scale</div>
          <div class="cz-option-grid">
            <?php foreach(['compact'=>'Compact','normal'=>'Normal','relaxed'=>'Relaxed','spacious'=>'Spacious'] as $sv=>$sl): ?>
            <div class="cz-option-item <?= ($cfg['store_spacing']??'normal')===$sv?'active':'' ?>"
                 onclick="czOption('f_spacing',this,'<?= $sv ?>')">
              <?= $sl ?>
            </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="f_spacing" value="<?= htmlspecialchars($cfg['store_spacing'] ?? 'normal') ?>">
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-magic"></i> Animations</div>
          <div class="cz-toggle-row">
            <div>
              <div class="cz-toggle-lbl">Enable Animations</div>
              <div class="cz-toggle-sub">Fade-in, slide, hover effects</div>
            </div>
            <label class="cz-toggle">
              <input type="checkbox" id="f_animations" <?= ($cfg['store_animations']??'1')!=='0'?'checked':'' ?>>
              <span class="cz-toggle-slider"></span>
            </label>
          </div>
        </div>

      </div><!-- /panel-typo -->

      <!-- ████ STORE PANEL ████ -->
      <div class="cz-panel" id="panel-store">

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-shop"></i> Store Identity</div>
          <div class="cz-row">
            <label class="cz-lbl">Store Name</label>
            <input class="cz-inp" id="f_store_name" value="<?= htmlspecialchars($cfg['store_name']??$shopName) ?>" placeholder="Ahmed General Store">
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Tagline</label>
            <input class="cz-inp" id="f_store_tagline" value="<?= htmlspecialchars($cfg['store_tagline']??'') ?>" placeholder="Quality products, best prices">
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Description</label>
            <textarea class="cz-ta" id="f_store_description" placeholder="Tell customers about your store..."><?= htmlspecialchars($cfg['store_description']??'') ?></textarea>
          </div>
          <div class="cz-row">
            <label class="cz-lbl">City / Location</label>
            <input class="cz-inp" id="f_store_city" value="<?= htmlspecialchars($cfg['store_city']??'') ?>" placeholder="Karachi, Pakistan">
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Full Address</label>
            <input class="cz-inp" id="f_store_address" value="<?= htmlspecialchars($cfg['store_address']??'') ?>" placeholder="Shop #5, Main Market...">
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Store Email</label>
            <input class="cz-inp" id="f_store_email" type="email" value="<?= htmlspecialchars($cfg['store_email']??'') ?>" placeholder="store@example.com">
          </div>
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-megaphone-fill"></i> Announcement Bar</div>
          <div class="cz-toggle-row">
            <span class="cz-toggle-lbl">Show Announcement Bar</span>
            <label class="cz-toggle"><input type="checkbox" id="f_announcement_on" <?= !empty($cfg['store_announcement_on'])?'checked':'' ?>><span class="cz-toggle-slider"></span></label>
          </div>
          <div class="cz-row" style="margin-top:.4rem">
            <label class="cz-lbl">Announcement Text</label>
            <input class="cz-inp" id="f_announcement" value="<?= htmlspecialchars($cfg['store_announcement']??'🎉 Free delivery on orders above Rs 1500!') ?>">
          </div>
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-currency-exchange"></i> Commerce</div>
          <div class="cz-row">
            <label class="cz-lbl">Currency Symbol</label>
            <select class="cz-sel" id="f_currency">
              <?php foreach(['Rs'=>'Rs (PKR)','$'=>'$ (USD)','€'=>'€ (EUR)','£'=>'£ (GBP)','AED'=>'AED (UAE)','SAR'=>'SAR (Saudi)'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= ($cfg['store_currency']??'Rs')===$v?'selected':'' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Free Delivery Threshold</label>
            <input class="cz-inp" id="f_free_delivery" type="number" value="<?= htmlspecialchars($cfg['store_free_delivery_min']??'1500') ?>" placeholder="1500">
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Footer Text</label>
            <input class="cz-inp" id="f_footer_text" value="<?= htmlspecialchars($cfg['store_footer_text']??'') ?>" placeholder="© 2025 My Store. All rights reserved.">
          </div>
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-gear-fill"></i> Maintenance Mode</div>
          <div class="cz-toggle-row">
            <div>
              <div class="cz-toggle-lbl">Maintenance Mode</div>
              <div class="cz-toggle-sub">Hide store from public</div>
            </div>
            <label class="cz-toggle"><input type="checkbox" id="f_maintenance_mode" <?= !empty($cfg['maintenance_mode'])?'checked':'' ?>><span class="cz-toggle-slider"></span></label>
          </div>
          <div class="cz-row" style="margin-top:.4rem">
            <label class="cz-lbl">Maintenance Message</label>
            <input class="cz-inp" id="f_maintenance_message" value="<?= htmlspecialchars($cfg['maintenance_message']??'We\'ll be back soon!') ?>">
          </div>
        </div>

      </div><!-- /panel-store -->

      <!-- ████ LAYOUT PANEL ████ -->
      <div class="cz-panel" id="panel-layout">

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-layout-wtf"></i> Header Style</div>
          <div class="cz-option-grid">
            <?php foreach(['default'=>'Default','centered'=>'Centered','minimal'=>'Minimal','mega'=>'Mega Menu'] as $sv=>$sl): ?>
            <div class="cz-option-item <?= ($cfg['store_header_layout']??'default')===$sv?'active':'' ?>"
                 onclick="czOption('f_header_layout',this,'<?= $sv ?>')">
              <?= $sl ?>
            </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="f_header_layout" value="<?= htmlspecialchars($cfg['store_header_layout'] ?? 'default') ?>">
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-image-fill"></i> Hero Section</div>
          <div class="cz-row">
            <label class="cz-lbl">Hero Style</label>
            <select class="cz-sel" id="f_hero_style">
              <option value="gradient" <?= ($cfg['store_hero_style']??'gradient')==='gradient'?'selected':'' ?>>Gradient Banner</option>
              <option value="minimal"  <?= ($cfg['store_hero_style']??'')==='minimal'?'selected':'' ?>>Minimal Clean</option>
              <option value="bold"     <?= ($cfg['store_hero_style']??'')==='bold'?'selected':'' ?>>Bold Full-Width</option>
              <option value="split"    <?= ($cfg['store_hero_style']??'')==='split'?'selected':'' ?>>Split Layout</option>
              <option value="video"    <?= ($cfg['store_hero_style']??'')==='video'?'selected':'' ?>>Video Background</option>
              <option value="none"     <?= ($cfg['store_hero_style']??'')==='none'?'selected':'' ?>>No Hero</option>
            </select>
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Hero Height</label>
            <select class="cz-sel" id="f_hero_height">
              <?php foreach(['sm'=>'Small (50vh)','md'=>'Medium (65vh)','lg'=>'Large (80vh)','full'=>'Full Screen (100vh)'] as $sv=>$sl): ?>
              <option value="<?= $sv ?>" <?= ($cfg['store_hero_height']??'md')===$sv?'selected':'' ?>><?= $sl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-grid-fill"></i> Product Grid</div>
          <div class="cz-row">
            <label class="cz-lbl">Columns (Desktop)</label>
            <select class="cz-sel" id="f_grid_cols">
              <option value="3" <?= ($cfg['store_grid_columns']??'4')==='3'?'selected':'' ?>>3 Columns</option>
              <option value="4" <?= ($cfg['store_grid_columns']??'4')==='4'?'selected':'' ?>>4 Columns</option>
              <option value="5" <?= ($cfg['store_grid_columns']??'')==='5'?'selected':'' ?>>5 Columns</option>
            </select>
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Card Style</label>
            <select class="cz-sel" id="f_card_style">
              <?php foreach(['default'=>'Default','minimal'=>'Minimal','bordered'=>'Bordered','elevated'=>'Elevated','glass'=>'Glassmorphism'] as $sv=>$sl): ?>
              <option value="<?= $sv ?>" <?= ($cfg['store_card_style']??'default')===$sv?'selected':'' ?>><?= $sl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-eye-fill"></i> Visibility</div>
          <div class="cz-toggle-row">
            <span class="cz-toggle-lbl">Search Bar</span>
            <label class="cz-toggle"><input type="checkbox" id="f_show_search" <?= ($cfg['store_show_search']??'1')!=='0'?'checked':'' ?>><span class="cz-toggle-slider"></span></label>
          </div>
          <div class="cz-toggle-row">
            <span class="cz-toggle-lbl">Category Filter</span>
            <label class="cz-toggle"><input type="checkbox" id="f_show_categories" <?= ($cfg['store_show_categories']??'1')!=='0'?'checked':'' ?>><span class="cz-toggle-slider"></span></label>
          </div>
          <div class="cz-toggle-row">
            <span class="cz-toggle-lbl">Featured Section</span>
            <label class="cz-toggle"><input type="checkbox" id="f_show_featured" <?= ($cfg['store_show_featured']??'1')==='1'?'checked':'' ?>><span class="cz-toggle-slider"></span></label>
          </div>
          <div class="cz-toggle-row">
            <span class="cz-toggle-lbl">Customer Reviews</span>
            <label class="cz-toggle"><input type="checkbox" id="f_show_reviews" <?= ($cfg['store_show_reviews']??'0')==='1'?'checked':'' ?>><span class="cz-toggle-slider"></span></label>
          </div>
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-fullscreen"></i> Container Width</div>
          <div class="cz-option-grid">
            <?php foreach(['boxed'=>'Boxed','wide'=>'Wide','full'=>'Full Width'] as $sv=>$sl): ?>
            <div class="cz-option-item <?= ($cfg['store_container_width']??'boxed')===$sv?'active':'' ?>"
                 onclick="czOption('f_container_width',this,'<?= $sv ?>')">
              <?= $sl ?>
            </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="f_container_width" value="<?= htmlspecialchars($cfg['store_container_width'] ?? 'boxed') ?>">
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-layout-sidebar-inset"></i> Navigation Style</div>
          <div class="cz-option-grid">
            <?php foreach(['default'=>'Standard','sticky'=>'Sticky','floating'=>'Floating','minimal'=>'Minimal'] as $sv=>$sl): ?>
            <div class="cz-option-item <?= ($cfg['store_nav_style']??'default')===$sv?'active':'' ?>"
                 onclick="czOption('f_nav_style',this,'<?= $sv ?>')">
              <?= $sl ?>
            </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="f_nav_style" value="<?= htmlspecialchars($cfg['store_nav_style'] ?? 'default') ?>">
        </div>

      </div><!-- /panel-layout -->

      <!-- ████ SECTIONS BUILDER PANEL ████ -->
      <div class="cz-panel" id="panel-sections">
        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-stack"></i> Homepage Sections
            <span style="font-size:.58rem;color:rgba(255,255,255,.2);font-weight:500;text-transform:none;letter-spacing:0;margin-left:.35rem">Drag to reorder</span>
          </div>
          <div class="sec-list" id="secSortable">
            <?php foreach($sections as $sec):
              $isEnabled = $sec['enabled'] ?? false;
            ?>
            <div class="sec-item <?= !$isEnabled?'sec-disabled':'' ?>"
                 data-id="<?= $sec['id'] ?>"
                 data-enabled="<?= $isEnabled?'1':'0' ?>"
                 data-bg="<?= htmlspecialchars($sec['bg'] ?? '') ?>"
                 data-padding="<?= htmlspecialchars($sec['padding'] ?? '') ?>"
                 data-animation="<?= htmlspecialchars($sec['animation'] ?? '') ?>">
              <i class="bi bi-grip-vertical sec-drag-handle"></i>
              <div class="sec-icon"><i class="bi <?= htmlspecialchars($sec['icon']??'bi-layout-text-window') ?>"></i></div>
              <div class="sec-info">
                <div class="sec-name"><?= htmlspecialchars($sec['label']) ?></div>
                <div class="sec-status"><?= $isEnabled ? '✓ Visible' : '— Hidden' ?></div>
              </div>
              <div class="sec-actions">
                <button class="sec-action-btn" onclick="secToggle(this)" title="Toggle visibility">
                  <i class="bi <?= $isEnabled?'bi-eye-fill':'bi-eye-slash' ?>"></i>
                </button>
                <button class="sec-action-btn del" onclick="secRemove(this)" title="Remove">
                  <i class="bi bi-x-lg"></i>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <button onclick="saveSections()" style="width:100%;margin-top:.85rem;padding:.55rem;border-radius:9px;background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.2);color:#818cf8;font-size:.76rem;font-weight:800;cursor:pointer;font-family:'Inter',sans-serif;transition:all .18s" onmouseover="this.style.background='rgba(99,102,241,.18)'" onmouseout="this.style.background='rgba(99,102,241,.1)'">
            <i class="bi bi-cloud-check-fill me-1"></i> Save Section Order
          </button>
        </div>
      </div><!-- /panel-sections -->

      <!-- ████ CONTACT PANEL ████ -->
      <div class="cz-panel" id="panel-contact">
        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-telephone-fill"></i> Contact Info</div>
          <div class="cz-row">
            <label class="cz-lbl"><i class="bi bi-telephone-fill" style="color:#34d399"></i> Phone Number</label>
            <input class="cz-inp" id="f_phone" type="tel" value="<?= htmlspecialchars($cfg['store_phone']??'') ?>" placeholder="03XX-XXXXXXX">
          </div>
          <div class="cz-row">
            <label class="cz-lbl"><i class="bi bi-whatsapp" style="color:#25d366"></i> WhatsApp Number</label>
            <input class="cz-inp" id="f_whatsapp" type="tel" value="<?= htmlspecialchars($cfg['store_whatsapp']??'') ?>" placeholder="923XXXXXXXXX">
          </div>
          <div class="cz-row">
            <label class="cz-lbl"><i class="bi bi-envelope-fill" style="color:#818cf8"></i> Email</label>
            <input class="cz-inp" id="f_email_contact" type="email" value="<?= htmlspecialchars($cfg['store_email']??'') ?>" placeholder="store@example.com">
          </div>
        </div>
      </div>

      <!-- ████ SOCIAL PANEL ████ -->
      <div class="cz-panel" id="panel-social">
        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-share-fill"></i> Social Media</div>
          <?php
          $socials = [
            'facebook'  => ['bi-facebook','#1877f2','Facebook','https://facebook.com/yourpage'],
            'instagram' => ['bi-instagram','#e1306c','Instagram','https://instagram.com/yourstore'],
            'tiktok'    => ['bi-tiktok','#fe2c55','TikTok','https://tiktok.com/@yourstore'],
            'youtube'   => ['bi-youtube','#ff0000','YouTube','https://youtube.com/@yourstore'],
            'twitter'   => ['bi-twitter-x','#1da1f2','X / Twitter','https://x.com/yourstore'],
          ];
          foreach($socials as $key=>[$icon,$color,$label,$ph]): ?>
          <div class="cz-row">
            <label class="cz-lbl"><i class="bi <?= $icon ?>" style="color:<?= $color ?>"></i> <?= $label ?></label>
            <input class="cz-inp" id="f_<?= $key ?>" value="<?= htmlspecialchars($cfg['store_'.$key]??'') ?>" placeholder="<?= $ph ?>">
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ████ SEO PANEL ████ -->
      <div class="cz-panel" id="panel-seo">
        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-graph-up-arrow"></i> Search Engine Optimization</div>
          <div class="cz-row">
            <label class="cz-lbl">SEO Title <span style="color:rgba(255,255,255,.25);font-weight:500">(50-60 chars)</span></label>
            <input class="cz-inp" id="f_seo_title" value="<?= htmlspecialchars($cfg['seo_title']??'') ?>" placeholder="Best Online Store | <?= htmlspecialchars($shopName) ?>">
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Meta Description <span style="color:rgba(255,255,255,.25);font-weight:500">(150-160 chars)</span></label>
            <textarea class="cz-ta" id="f_seo_description" placeholder="Shop the best products at unbeatable prices..."><?= htmlspecialchars($cfg['seo_description']??'') ?></textarea>
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Keywords</label>
            <input class="cz-inp" id="f_seo_keywords" value="<?= htmlspecialchars($cfg['seo_keywords']??'') ?>" placeholder="online shop, best prices, delivery">
          </div>
        </div>

        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-share-fill"></i> Open Graph (Social Sharing)</div>
          <div class="cz-row">
            <label class="cz-lbl">OG Title</label>
            <input class="cz-inp" id="f_og_title" value="<?= htmlspecialchars($cfg['seo_og_title']??'') ?>" placeholder="Same as SEO title if empty">
          </div>
          <div class="cz-row">
            <label class="cz-lbl">OG Description</label>
            <textarea class="cz-ta" id="f_og_description" placeholder="Description for WhatsApp/Facebook sharing..."><?= htmlspecialchars($cfg['seo_og_description']??'') ?></textarea>
          </div>
          <div class="cz-row">
            <label class="cz-lbl">OG Image URL</label>
            <input class="cz-inp" id="f_og_image" value="<?= htmlspecialchars($cfg['seo_og_image']??'') ?>" placeholder="https://yourdomain.com/og-image.jpg">
          </div>
        </div>
      </div><!-- /panel-seo -->

      <!-- ████ ANALYTICS PANEL ████ -->
      <div class="cz-panel" id="panel-analytics">
        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-graph-up"></i> Google Analytics</div>
          <div class="cz-row">
            <label class="cz-lbl">GA4 Measurement ID</label>
            <input class="cz-inp" id="f_ga_id" value="<?= htmlspecialchars($cfg['analytics_ga_id']??'') ?>" placeholder="G-XXXXXXXXXX">
          </div>
          <div style="font-size:.68rem;color:rgba(255,255,255,.25);margin-top:-.3rem;margin-bottom:.6rem;padding-left:.1rem">Paste your GA4 Measurement ID from Google Analytics</div>
        </div>
        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-tag-fill"></i> Google Tag Manager</div>
          <div class="cz-row">
            <label class="cz-lbl">GTM Container ID</label>
            <input class="cz-inp" id="f_gtm_id" value="<?= htmlspecialchars($cfg['analytics_gtm_id']??'') ?>" placeholder="GTM-XXXXXXX">
          </div>
        </div>
        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-facebook"></i> Meta (Facebook) Pixel</div>
          <div class="cz-row">
            <label class="cz-lbl">Pixel ID</label>
            <input class="cz-inp" id="f_fb_pixel" value="<?= htmlspecialchars($cfg['analytics_fb_pixel']??'') ?>" placeholder="1234567890123456">
          </div>
        </div>
        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-code-square"></i> Additional Scripts</div>
          <div class="cz-row">
            <label class="cz-lbl">Header Scripts <span style="color:rgba(255,255,255,.2)">(before &lt;/head&gt;)</span></label>
            <textarea class="cz-ta" id="f_header_scripts" placeholder="<!-- Verification tags, custom tracking scripts... -->"><?= htmlspecialchars($cfg['analytics_header_scripts']??'') ?></textarea>
          </div>
          <div class="cz-row">
            <label class="cz-lbl">Footer Scripts <span style="color:rgba(255,255,255,.2)">(before &lt;/body&gt;)</span></label>
            <textarea class="cz-ta" id="f_footer_scripts" placeholder="<!-- Chatbot widgets, live chat scripts... -->"><?= htmlspecialchars($cfg['analytics_footer_scripts']??'') ?></textarea>
          </div>
        </div>
      </div><!-- /panel-analytics -->

      <!-- ████ CODE EDITOR PANEL ████ -->
      <div class="cz-panel" id="panel-code">
        <div class="cz-sec">
          <div class="cz-sec-label"><i class="bi bi-code-slash"></i> Custom Code Editor
            <a href="#" onclick="saveCode()" style="color:#818cf8;text-decoration:none;font-size:.6rem;font-weight:800;margin-left:auto;display:flex;align-items:center;gap:.2rem;text-transform:none;letter-spacing:0">
              <i class="bi bi-cloud-check-fill"></i> Save Code
            </a>
          </div>
          <div class="cz-code-tabs">
            <button class="cz-code-tab active" onclick="czCodeTab('css',this)">CSS</button>
            <button class="cz-code-tab" onclick="czCodeTab('js',this)">JS</button>
            <button class="cz-code-tab" onclick="czCodeTab('html_head',this)">HTML Head</button>
            <button class="cz-code-tab" onclick="czCodeTab('html_body_start',this)">Body Start</button>
            <button class="cz-code-tab" onclick="czCodeTab('html_body_end',this)">Body End</button>
          </div>

          <!-- CSS Editor -->
          <div class="cz-code-editor-wrap active" id="code-css">
            <label class="cz-lbl">Custom CSS <span style="color:rgba(255,255,255,.2)">(injected into &lt;style&gt; tag)</span></label>
            <textarea id="codeCss"><?= htmlspecialchars($cfg['custom_css']??'/* Custom CSS for your store */\n') ?></textarea>
          </div>
          <!-- JS Editor -->
          <div class="cz-code-editor-wrap" id="code-js">
            <label class="cz-lbl">Custom JavaScript <span style="color:rgba(255,255,255,.2)">(injected before &lt;/body&gt;)</span></label>
            <textarea id="codeJs"><?= htmlspecialchars($cfg['custom_js']??'// Custom JavaScript for your store\n') ?></textarea>
          </div>
          <!-- HTML Head -->
          <div class="cz-code-editor-wrap" id="code-html_head">
            <label class="cz-lbl">Custom HTML &lt;head&gt; <span style="color:rgba(255,255,255,.2)">(meta tags, fonts, etc.)</span></label>
            <textarea id="codeHtmlHead"><?= htmlspecialchars($cfg['custom_html_head']??'<!-- Custom head HTML -->\n') ?></textarea>
          </div>
          <!-- HTML Body Start -->
          <div class="cz-code-editor-wrap" id="code-html_body_start">
            <label class="cz-lbl">HTML at Body Start</label>
            <textarea id="codeHtmlBodyStart"><?= htmlspecialchars($cfg['custom_html_body_start']??'<!-- Placed at the top of <body> -->\n') ?></textarea>
          </div>
          <!-- HTML Body End -->
          <div class="cz-code-editor-wrap" id="code-html_body_end">
            <label class="cz-lbl">HTML at Body End <span style="color:rgba(255,255,255,.2)">(chat widgets, etc.)</span></label>
            <textarea id="codeHtmlBodyEnd"><?= htmlspecialchars($cfg['custom_html_body_end']??'<!-- Placed at the end of <body> -->\n') ?></textarea>
          </div>

          <button onclick="saveCode()" style="width:100%;margin-top:.7rem;padding:.55rem;border-radius:9px;background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.2);color:#818cf8;font-size:.76rem;font-weight:800;cursor:pointer;font-family:'Inter',sans-serif;transition:all .18s">
            <i class="bi bi-cloud-check-fill me-1"></i> Save Code Changes
          </button>
        </div>
      </div><!-- /panel-code -->

    </div><!-- /cz-panels -->

    <!-- Save bar -->
    <div class="cz-save-bar">
      <button class="cz-save-btn" onclick="czSaveAll()" id="czSaveBtn">
        <i class="bi bi-cloud-check-fill"></i> Save All Changes
      </button>
      <a class="cz-open-btn" href="<?= htmlspecialchars($previewUrl) ?>" target="_blank" title="Open store in new tab">
        <i class="bi bi-box-arrow-up-right"></i>
      </a>
    </div>

  </div><!-- /cz-sidebar -->

  <!-- ████████████████ PREVIEW PANE ████████████████ -->
  <div class="cz-preview">

    <!-- Preview toolbar -->
    <div class="cz-preview-bar">
      <div class="cz-dev-btns">
        <button class="cz-dev-btn active" onclick="czSetDevice('desktop',this)" title="Desktop"><i class="bi bi-display"></i></button>
        <button class="cz-dev-btn" onclick="czSetDevice('tablet',this)" title="Tablet"><i class="bi bi-tablet-landscape"></i></button>
        <button class="cz-dev-btn" onclick="czSetDevice('mobile',this)" title="Mobile"><i class="bi bi-phone"></i></button>
      </div>
      <div class="cz-url-bar">
        <i class="bi bi-lock-fill" style="color:#34d399;font-size:.65rem;flex-shrink:0"></i>
        <span id="czUrlText"><?= htmlspecialchars($previewUrl) ?></span>
      </div>
      <div style="display:flex;gap:.35rem">
        <button onclick="czReloadPreview()" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.07);border-radius:7px;color:rgba(255,255,255,.4);padding:.3rem .5rem;cursor:pointer;font-size:.78rem;transition:all .18s" title="Refresh preview" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.4)'">
          <i class="bi bi-arrow-clockwise"></i>
        </button>
        <a href="<?= htmlspecialchars($previewUrl) ?>" target="_blank" style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:7px;color:#34d399;padding:.3rem .55rem;cursor:pointer;font-size:.72rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:.3rem;transition:all .18s">
          <i class="bi bi-box-arrow-up-right"></i> Open
        </a>
      </div>
    </div>

    <!-- Iframe wrapper -->
    <div class="cz-preview-wrapper">
      <iframe class="cz-preview-frame" id="czPreviewFrame"
              src="<?= htmlspecialchars($previewUrl) ?>"
              loading="lazy"></iframe>
    </div>

  </div><!-- /cz-preview -->

</div><!-- /cz-app -->

<!-- CodeMirror scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/closebrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/matchbrackets.min.js"></script>

<script>
/* ══════════════════════════════════════════════════════════════
   ENTERPRISE CUSTOMIZER JS
   ══════════════════════════════════════════════════════════════ */

/* ── State ── */
var CZ = {
  theme:   '<?= addslashes($cfg['store_theme'] ?? 'Luxe Dark') ?>',
  accent:  '<?= addslashes($cfg['store_accent_color'] ?? '#6366f1') ?>',
  heading: '<?= addslashes($cfg['store_heading_font'] ?? ($cfg['store_font'] ?? 'Inter')) ?>',
  body:    '<?= addslashes($cfg['store_body_font']    ?? ($cfg['store_font'] ?? 'Inter')) ?>',
  dirty:   false,
  reloadT: null,
  device:  'desktop'
};

/* ── Panel switching ── */
function czShowPanel(id, btn) {
  document.querySelectorAll('.cz-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.cz-tab').forEach(function(t){ t.classList.remove('active'); });
  var panel = document.getElementById('panel-'+id);
  if (panel) panel.classList.add('active');
  btn.classList.add('active');

  /* Init code editors when entering code panel */
  if (id === 'code' && !CZ.cmInited) { initCodeMirror(); }
}

/* ── Theme selection ── */
function czSelectTheme(name, card) {
  CZ.theme = name;
  document.querySelectorAll('.cz-theme-card').forEach(function(c){ c.classList.remove('active'); });
  card.classList.add('active');
  document.getElementById('f_theme').value = name;
  czTriggerReload();
}

/* ── Accent color ── */
function czSelectAccent(hex, swatch, fromPicker) {
  CZ.accent = hex;
  document.querySelectorAll('#czAccentSwatches .cz-swatch').forEach(function(s){ s.classList.remove('active'); });
  if (swatch) swatch.classList.add('active');
  document.getElementById('f_accent').value = hex;
  document.getElementById('customAccentPicker').value = hex;
  czTriggerReload();
}

/* ── Announcement bg ── */
function czSelectAnnBg(hex, swatch) {
  document.querySelectorAll('#czAnnSwatches .cz-swatch').forEach(function(s){ s.classList.remove('active'); });
  if (swatch) swatch.classList.add('active');
  document.getElementById('f_ann_bg').value = hex;
  czTriggerReload();
}

/* ── Font selection ── */
function czSelectFont(type, font, el) {
  if (type === 'heading') {
    CZ.heading = font;
    document.querySelectorAll('#panel-typo .cz-font-grid:first-of-type .cz-font-opt').forEach(function(f){ f.classList.remove('active'); });
    document.getElementById('f_heading_font').value = font;
  } else {
    CZ.body = font;
    document.querySelectorAll('#bodyFontGrid .cz-font-opt').forEach(function(f){ f.classList.remove('active'); });
    document.getElementById('f_body_font').value = font;
  }
  el.classList.add('active');
  czTriggerReload();
}

/* ── Option grid ── */
function czOption(fieldId, el, val) {
  var parent = el.parentElement;
  parent.querySelectorAll('.cz-option-item').forEach(function(i){ i.classList.remove('active'); });
  el.classList.add('active');
  var field = document.getElementById(fieldId);
  if (field) field.value = val;
  czTriggerReload();
}

/* ── Range inputs ── */
function czRange(name, val) {
  var el = document.getElementById('rv_'+name);
  if (el) el.textContent = val + (name === 'font_size' || name === 'card_radius' ? 'px' : '%');
  czTriggerReload();
}

/* ── Device toggle ── */
function czSetDevice(dev, btn) {
  CZ.device = dev;
  document.querySelectorAll('.cz-dev-btn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  var frame = document.getElementById('czPreviewFrame');
  frame.className = 'cz-preview-frame' + (dev !== 'desktop' ? ' '+dev : '');
}

/* ── Reload preview ── */
function czReloadPreview() {
  var frame = document.getElementById('czPreviewFrame');
  var base  = '<?= htmlspecialchars($previewUrl) ?>';

  /* Collect all values */
  var params = {
    theme:    document.getElementById('f_theme').value,
    accent:   document.getElementById('f_accent').value,
    font:     document.getElementById('f_heading_font').value,
    name:     (document.getElementById('f_store_name')     || {}).value || '',
    tagline:  (document.getElementById('f_store_tagline')  || {}).value || '',
    hero:     (document.getElementById('f_hero_style')     || {}).value || 'gradient',
    ann:      (document.getElementById('f_announcement')   || {}).value || '',
    ann_on:   (document.getElementById('f_announcement_on') || {}).checked ? '1' : '0',
    grid:     (document.getElementById('f_grid_cols')      || {}).value || '4',
    t:        Date.now()
  };

  var qs = Object.keys(params).map(function(k){ return k+'='+encodeURIComponent(params[k]); }).join('&');
  frame.src = base + '&' + qs;
}

function czTriggerReload() {
  clearTimeout(CZ.reloadT);
  CZ.reloadT = setTimeout(czReloadPreview, 700);
  CZ.dirty = true;
}

/* ── Live input binding ── */
document.querySelectorAll('.cz-inp,.cz-sel,.cz-ta').forEach(function(el){
  el.addEventListener('input', function(){ czTriggerReload(); CZ.dirty = true; });
});
document.querySelectorAll('input[type=checkbox]').forEach(function(el){
  el.addEventListener('change', function(){ czTriggerReload(); CZ.dirty = true; });
});

/* ── Save all ── */
function czSaveAll() {
  var btn = document.getElementById('czSaveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';

  var fields = {
    'store_theme':          document.getElementById('f_theme').value,
    'store_accent_color':   document.getElementById('f_accent').value,
    'store_heading_font':   document.getElementById('f_heading_font').value,
    'store_body_font':      document.getElementById('f_body_font').value,
    'store_font':           document.getElementById('f_heading_font').value,
    'store_font_size':      document.getElementById('f_font_size').value,
    'store_spacing':        document.getElementById('f_spacing').value,
    'store_animations':     document.getElementById('f_animations').checked ? '1' : '0',
    'store_card_shadow':    document.getElementById('f_card_shadow').value,
    'store_card_radius':    document.getElementById('f_card_radius').value,
    'store_announcement_bg':document.getElementById('f_ann_bg').value,
    'store_hero_overlay':   document.getElementById('f_hero_overlay').value,
    'store_header_layout':  document.getElementById('f_header_layout').value,
    'store_container_width':document.getElementById('f_container_width').value,
    'store_nav_style':      document.getElementById('f_nav_style').value,
    'store_name':           document.getElementById('f_store_name').value,
    'store_tagline':        document.getElementById('f_store_tagline').value,
    'store_description':    document.getElementById('f_store_description').value,
    'store_city':           document.getElementById('f_store_city').value,
    'store_address':        document.getElementById('f_store_address').value,
    'store_email':          document.getElementById('f_store_email').value,
    'store_announcement':   document.getElementById('f_announcement').value,
    'store_announcement_on':document.getElementById('f_announcement_on').checked ? '1' : '0',
    'store_currency':       document.getElementById('f_currency').value,
    'store_free_delivery_min': document.getElementById('f_free_delivery').value,
    'store_footer_text':    document.getElementById('f_footer_text').value,
    'maintenance_mode':     document.getElementById('f_maintenance_mode').checked ? '1' : '0',
    'maintenance_message':  document.getElementById('f_maintenance_message').value,
    'store_hero_style':     document.getElementById('f_hero_style').value,
    'store_hero_height':    document.getElementById('f_hero_height').value,
    'store_grid_columns':   document.getElementById('f_grid_cols').value,
    'store_card_style':     document.getElementById('f_card_style').value,
    'store_show_search':    document.getElementById('f_show_search').checked ? '1' : '0',
    'store_show_categories':document.getElementById('f_show_categories').checked ? '1' : '0',
    'store_show_featured':  document.getElementById('f_show_featured').checked ? '1' : '0',
    'store_show_reviews':   document.getElementById('f_show_reviews').checked ? '1' : '0',
    'store_phone':          document.getElementById('f_phone').value,
    'store_whatsapp':       document.getElementById('f_whatsapp').value,
    'store_facebook':       document.getElementById('f_facebook').value,
    'store_instagram':      document.getElementById('f_instagram').value,
    'store_tiktok':         document.getElementById('f_tiktok').value,
    'store_youtube':        document.getElementById('f_youtube').value,
    'store_twitter':        document.getElementById('f_twitter').value,
    'seo_title':            document.getElementById('f_seo_title').value,
    'seo_description':      document.getElementById('f_seo_description').value,
    'seo_keywords':         document.getElementById('f_seo_keywords').value,
    'seo_og_title':         document.getElementById('f_og_title').value,
    'seo_og_description':   document.getElementById('f_og_description').value,
    'seo_og_image':         document.getElementById('f_og_image').value,
    'analytics_ga_id':      document.getElementById('f_ga_id').value,
    'analytics_gtm_id':     document.getElementById('f_gtm_id').value,
    'analytics_fb_pixel':   document.getElementById('f_fb_pixel').value,
    'analytics_header_scripts': document.getElementById('f_header_scripts').value,
    'analytics_footer_scripts': document.getElementById('f_footer_scripts').value,
  };

  /* Include CodeMirror values if inited */
  if (CZ.cm_css)  fields['custom_css']             = CZ.cm_css.getValue();
  if (CZ.cm_js)   fields['custom_js']              = CZ.cm_js.getValue();
  if (CZ.cm_head) fields['custom_html_head']        = CZ.cm_head.getValue();
  if (CZ.cm_bs)   fields['custom_html_body_start']  = CZ.cm_bs.getValue();
  if (CZ.cm_be)   fields['custom_html_body_end']    = CZ.cm_be.getValue();

  var fd = new URLSearchParams();
  fd.append('action','save_customization');
  Object.keys(fields).forEach(function(k){ fd.append(k, fields[k]); });

  fetch(window.location.pathname, {
    method:'POST', body:fd.toString(),
    headers:{'Content-Type':'application/x-www-form-urlencoded'}
  })
  .then(function(r){ return r.json(); })
  .then(function(d) {
    btn.disabled = false;
    if (d.success) {
      btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Saved!';
      CZ.dirty = false;
      czReloadPreview();
      setTimeout(function(){ btn.innerHTML = '<i class="bi bi-cloud-check-fill"></i> Save All Changes'; }, 2500);
      czToast('✓ All changes saved successfully', '#34D399');
    } else {
      btn.innerHTML = '<i class="bi bi-x-circle-fill"></i> Error';
      setTimeout(function(){ btn.innerHTML = '<i class="bi bi-cloud-check-fill"></i> Save All Changes'; }, 2000);
    }
  })
  .catch(function() {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-x-circle-fill"></i> Error';
    setTimeout(function(){ btn.innerHTML = '<i class="bi bi-cloud-check-fill"></i> Save All Changes'; }, 2000);
  });
}

/* ── Section builder ── */
(function initSortable() {
  var el = document.getElementById('secSortable');
  if (!el || typeof Sortable === 'undefined') return;
  Sortable.create(el, {
    animation: 180,
    handle: '.sec-drag-handle',
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
    onEnd: function() { CZ.dirty = true; }
  });
})();

function secToggle(btn) {
  var item = btn.closest('.sec-item');
  var icon = btn.querySelector('i');
  var enabled = item.dataset.enabled === '1';
  var newState = !enabled;
  item.dataset.enabled = newState ? '1' : '0';
  item.classList.toggle('sec-disabled', !newState);
  icon.className = 'bi ' + (newState ? 'bi-eye-fill' : 'bi-eye-slash');
  var statusEl = item.querySelector('.sec-status');
  if (statusEl) statusEl.textContent = newState ? '✓ Visible' : '— Hidden';
  CZ.dirty = true;
  saveSections(true);
}

function secRemove(btn) {
  var item = btn.closest('.sec-item');
  item.style.opacity = '0';item.style.height = item.offsetHeight+'px';
  item.style.transition = 'all .2s';
  setTimeout(function(){ item.style.height = '0';item.style.padding = '0';item.style.margin = '0'; }, 10);
  setTimeout(function(){ item.remove(); }, 220);
  CZ.dirty = true;
}

function saveSections(silent) {
  var items = document.querySelectorAll('#secSortable .sec-item');
  var data = [];
  items.forEach(function(item) {
    data.push({
      id:      item.dataset.id,
      label:   item.querySelector('.sec-name').textContent,
      icon:    (item.querySelector('.sec-icon i') || {}).className || '',
      enabled: item.dataset.enabled === '1',
      bg:      item.dataset.bg || '',
      padding: item.dataset.padding || '',
      animation: item.dataset.animation || ''
    });
  });

  var fd = new URLSearchParams();
  fd.append('action','save_sections');
  fd.append('sections', JSON.stringify(data));

  fetch(window.location.pathname, {
    method:'POST', body:fd.toString(),
    headers:{'Content-Type':'application/x-www-form-urlencoded'}
  })
  .then(function(r){ return r.json(); })
  .then(function(d) {
    if (d.success) {
      if (!silent) czToast('✓ Section order saved', '#34D399');
      CZ.dirty = false;
      czReloadPreview();
    }
    else           { czToast('Could not save sections', '#ef4444'); }
  });
}

/* ── Code Editor ── */
CZ.cm_css = null; CZ.cm_js = null; CZ.cm_head = null; CZ.cm_bs = null; CZ.cm_be = null;
CZ.cmInited = false;

function initCodeMirror() {
  if (CZ.cmInited || typeof CodeMirror === 'undefined') return;
  CZ.cmInited = true;

  var opts = { theme:'dracula', lineNumbers:true, autoCloseBrackets:true, matchBrackets:true };

  CZ.cm_css  = CodeMirror.fromTextArea(document.getElementById('codeCss'),  Object.assign({},opts,{mode:'css'}));
  CZ.cm_js   = CodeMirror.fromTextArea(document.getElementById('codeJs'),   Object.assign({},opts,{mode:'javascript'}));
  CZ.cm_head = CodeMirror.fromTextArea(document.getElementById('codeHtmlHead'),      Object.assign({},opts,{mode:'htmlmixed'}));
  CZ.cm_bs   = CodeMirror.fromTextArea(document.getElementById('codeHtmlBodyStart'), Object.assign({},opts,{mode:'htmlmixed'}));
  CZ.cm_be   = CodeMirror.fromTextArea(document.getElementById('codeHtmlBodyEnd'),   Object.assign({},opts,{mode:'htmlmixed'}));

  [CZ.cm_css, CZ.cm_js, CZ.cm_head, CZ.cm_bs, CZ.cm_be].forEach(function(cm){
    cm.on('change', function(){ CZ.dirty = true; });
  });
}

function czCodeTab(name, btn) {
  document.querySelectorAll('.cz-code-tab').forEach(function(b){ b.classList.remove('active'); });
  document.querySelectorAll('.cz-code-editor-wrap').forEach(function(w){ w.classList.remove('active'); });
  btn.classList.add('active');
  document.getElementById('code-'+name).classList.add('active');
  /* Refresh CodeMirror layout */
  setTimeout(function(){
    var cm = {css:CZ.cm_css, js:CZ.cm_js, html_head:CZ.cm_head, html_body_start:CZ.cm_bs, html_body_end:CZ.cm_be}[name];
    if (cm) cm.refresh();
  }, 50);
}

function saveCode() {
  if (!CZ.cmInited) { czToast('Open code tab first', '#FBBF24'); return; }
  var fd = new URLSearchParams();
  fd.append('action','save_code');
  fd.append('custom_css',            CZ.cm_css.getValue());
  fd.append('custom_js',             CZ.cm_js.getValue());
  fd.append('custom_html_head',      CZ.cm_head.getValue());
  fd.append('custom_html_body_start',CZ.cm_bs.getValue());
  fd.append('custom_html_body_end',  CZ.cm_be.getValue());

  fetch(window.location.pathname, {
    method:'POST', body:fd.toString(),
    headers:{'Content-Type':'application/x-www-form-urlencoded'}
  })
  .then(function(r){ return r.json(); })
  .then(function(d) {
    if (d.success) { czToast('✓ Code saved', '#34D399'); czReloadPreview(); }
    else           { czToast('Save failed', '#ef4444'); }
  });
}

/* ── Toast ── */
function czToast(msg, color) {
  var t = document.createElement('div');
  t.className = 'cz-toast';
  t.style.borderLeft = '3px solid '+color;
  t.innerHTML = '<i class="bi bi-check-circle-fill" style="color:'+color+'"></i> <span style="color:'+color+'">'+msg+'</span>';
  document.body.appendChild(t);
  setTimeout(function(){
    t.style.opacity='0';t.style.transition='opacity .3s';
    setTimeout(function(){ t.remove(); },300);
  }, 3000);
}

/* ── Warn on leave ── */
window.addEventListener('beforeunload', function(e){
  if (CZ.dirty) { e.preventDefault(); e.returnValue=''; }
});

/* ── Google Fonts preload for selected fonts ── */
(function loadFonts() {
  var fonts = ['Inter','Poppins','Playfair+Display','Space+Grotesk','Nunito','Lora','DM+Sans'];
  var link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = 'https://fonts.googleapis.com/css2?'+fonts.map(function(f){ return 'family='+f+':wght@400;700;900'; }).join('&')+'&display=swap';
  document.head.appendChild(link);
})();
</script>

<?php shopFooter(); ?>

<?php
/**
 * Stockora — Professional Public Store v3.0
 * Shopify-quality storefront with full customizer support
 * URL: /store.php?s={slug}
 * Preview: /store.php?s={slug}&preview=1&theme=...&accent=...&font=...
 */
require_once 'includes/functions.php';
startSession();

$db   = getDB();
$slug = trim($_GET['s'] ?? '');

/* ════════════════════════════════════════════
   AJAX: Place Order
   POST store.php?s=slug&action=place_order
════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'place_order') {
    ob_clean(); // Discard any accidental output before JSON
    header('Content-Type: application/json');
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $ajaxSlug = trim($input['slug'] ?? $slug);

    $sRow = $db->prepare("SELECT shop_id FROM settings WHERE setting_key='store_slug' AND setting_value=? LIMIT 1");
    $sRow->execute([$ajaxSlug]);
    $sRow = $sRow->fetch(PDO::FETCH_ASSOC);
    if (!$sRow) { echo json_encode(['success'=>false,'message'=>'Store not found']); exit; }
    $shopId = (int)$sRow['shop_id'];

    $custName  = trim($input['customer_name']   ?? '');
    $custPhone = trim($input['customer_phone']  ?? '');
    $custAddr  = trim($input['customer_address']?? '');
    $custNote  = trim($input['customer_note']   ?? '');
    $payMethod = trim($input['payment_method']  ?? 'cod');
    $items     = $input['items'] ?? [];

    if (empty($items)) { echo json_encode(['success'=>false,'message'=>'Cart is empty']); exit; }

    $subtotal   = 0;
    $itemsClean = [];
    foreach ($items as $it) {
        $pid  = (int)($it['id']  ?? 0);
        $qty  = max(1,(int)($it['qty'] ?? 1));
        $pRow = $db->prepare("SELECT name,retail_price,stock_quantity FROM products WHERE id=? AND shop_id=?");
        $pRow->execute([$pid, $shopId]);
        $pRow = $pRow->fetch(PDO::FETCH_ASSOC);
        if (!$pRow) continue;
        $lineTotal   = $pRow['retail_price'] * $qty;
        $subtotal   += $lineTotal;
        $itemsClean[] = ['id'=>$pid,'name'=>$pRow['name'],'price'=>(float)$pRow['retail_price'],'qty'=>$qty,'line_total'=>$lineTotal];
    }

    $orderNum = 'ORD-'.strtoupper($ajaxSlug[0] ?? 'S').date('ymd').'-'.strtoupper(substr(md5(uniqid()),0,5));
    $db->prepare("INSERT INTO online_orders
        (shop_id,order_number,customer_name,customer_phone,customer_address,customer_note,items,subtotal,total,payment_method,source,status)
        VALUES (?,?,?,?,?,?,?,?,?,?,'store','pending')")
       ->execute([$shopId,$orderNum,$custName,$custPhone,$custAddr,$custNote,json_encode($itemsClean,JSON_UNESCAPED_UNICODE),$subtotal,$subtotal,$payMethod]);

    echo json_encode(['success'=>true,'order_number'=>$orderNum,'total'=>$subtotal]);
    exit;
}

/* ════════════════════════════════════════════
   LOAD STORE DATA
════════════════════════════════════════════ */
$shop     = null;
$settings = [];
$shopId   = 0;

if ($slug !== '') {
    $sRow = $db->prepare("SELECT shop_id FROM settings WHERE setting_key='store_slug' AND setting_value=? LIMIT 1");
    $sRow->execute([$slug]);
    $sRow = $sRow->fetch(PDO::FETCH_ASSOC);
    if ($sRow) {
        $shopId = (int)$sRow['shop_id'];
        $shop   = $db->prepare("SELECT * FROM shops WHERE id=? LIMIT 1");
        $shop->execute([$shopId]);
        $shop   = $shop->fetch(PDO::FETCH_ASSOC);
        $sRows  = $db->prepare("SELECT setting_key,setting_value FROM settings WHERE shop_id=? AND (setting_key LIKE 'store_%' OR setting_key='sections_config')");
        $sRows->execute([$shopId]);
        foreach ($sRows->fetchAll(PDO::FETCH_ASSOC) as $r) $settings[$r['setting_key']] = $r['setting_value'];
    }
}

$notFound    = !$shop;
$notLaunched = $shop && empty($settings['store_launched']);
$isPreview   = !empty($_GET['preview']);

/* ── Preview mode: override settings from query params ── */
if ($isPreview) {
    if (isset($_GET['theme']))  $settings['store_theme']           = trim($_GET['theme']);
    if (isset($_GET['accent'])) $settings['store_accent_color']    = trim($_GET['accent']);
    if (isset($_GET['font']))   $settings['store_font']            = trim($_GET['font']);
    if (isset($_GET['name']))   $settings['store_name']            = trim($_GET['name']);
    if (isset($_GET['tagline'])) $settings['store_tagline']        = trim($_GET['tagline']);
    if (isset($_GET['hero']))   $settings['store_hero_style']      = trim($_GET['hero']);
    if (isset($_GET['ann']))    $settings['store_announcement']    = trim($_GET['ann']);
    if (isset($_GET['ann_on'])) $settings['store_announcement_on'] = trim($_GET['ann_on']);
    // Allow preview even if not launched
    $notLaunched = false;
}

/* ── Marketplace Live Preview: ?preview_theme=ThemeName
   Applies a named marketplace theme instantly without saving.
   Works even if store is not launched (preview mode bypass). ── */
if (!empty($_GET['preview_theme'])) {
    $settings['store_theme'] = trim($_GET['preview_theme']);
    $isPreview   = true;
    $notLaunched = false;
}

/* ── Load Products & Categories ── */
$products   = [];
$categories = [];
if ($shop && !$notLaunched) {
    $prodRows = $db->prepare("
        SELECT p.id, p.name, p.retail_price, p.stock_quantity, p.image, p.description, p.unit,
               c.id AS cat_id, c.name AS cat_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.shop_id=? AND p.status='active' AND p.stock_quantity > 0
        ORDER BY c.name ASC, p.name ASC
        LIMIT 200
    ");
    $prodRows->execute([$shopId]);
    $products = $prodRows->fetchAll(PDO::FETCH_ASSOC);
    foreach ($products as $p) {
        if (!empty($p['cat_id']) && !isset($categories[$p['cat_id']]))
            $categories[$p['cat_id']] = $p['cat_name'];
    }
}

/* ═══════════════════════════════════════════
   RESOLVE ALL CUSTOMIZATION SETTINGS
═══════════════════════════════════════════ */
$storeName        = $settings['store_name']           ?? ($shop['name'] ?? 'Online Store');
$storeTagline     = $settings['store_tagline']        ?? '';
$storeTheme       = $settings['store_theme']          ?? 'Luxe Dark';
$storeAccent      = $settings['store_accent_color']   ?? '';
$storeFont        = $settings['store_font']           ?? 'Inter';
$storeCity        = $settings['store_city']           ?? '';
$storeAddress     = $settings['store_address']        ?? '';
$storePhone       = $settings['store_phone']          ?? '';
$storeWa          = $settings['store_whatsapp']       ?? $storePhone;
$storeDesc        = $settings['store_description']    ?? '';
$storeCat         = $settings['store_category']       ?? 'General Store';
$storeHero        = $settings['store_hero_style']     ?? 'gradient';
$storeGridCols    = (int)($settings['store_grid_columns']    ?? 4);
$storeShowSearch  = ($settings['store_show_search']   ?? '1') !== '0';
$storeShowCats    = ($settings['store_show_categories']?? '1') !== '0';
$storeShowFeatured= ($settings['store_show_featured'] ?? '1') !== '0';
$storeCurrency    = $settings['store_currency']       ?? 'Rs';
$storeFreeMin     = (float)($settings['store_free_delivery_min'] ?? 0);
$storeFooterTxt   = $settings['store_footer_text']    ?? '';
$storeAnn         = $settings['store_announcement']   ?? '';
$storeAnnOn       = !empty($settings['store_announcement_on']) && $settings['store_announcement_on'] === '1';
$storeFacebook    = $settings['store_facebook']       ?? '';
$storeInstagram   = $settings['store_instagram']      ?? '';
$storeTiktok      = $settings['store_tiktok']         ?? '';
$shopLogo         = !empty($shop['logo']) ? BASE_URL . '/assets/uploads/' . $shop['logo'] : null;
$waNumRaw         = preg_replace('/[^0-9]/', '', $storeWa ?: $storePhone);

/* Section visibility is stored by Store Customizer. Stores created before
   this setting existed retain their current visible sections by default. */
$sectionStates = [];
$sectionsConfig = json_decode($settings['sections_config'] ?? '[]', true);
if (is_array($sectionsConfig)) {
    foreach ($sectionsConfig as $sectionConfig) {
        if (!empty($sectionConfig['id'])) {
            $sectionStates[$sectionConfig['id']] = !empty($sectionConfig['enabled']);
        }
    }
}
$sectionEnabled = static function (string $id) use ($sectionStates): bool {
    return !array_key_exists($id, $sectionStates) || $sectionStates[$id];
};

/* ═══════════════════════════════════════════
   THEME PALETTE SYSTEM — 6 themes
   Each theme: bg, card, nav, hero_from, hero_to, text
   accent can be overridden by store_accent_color
═══════════════════════════════════════════ */
$themeMap = [
    'Luxe Dark'  => [
        'bg'       => '#060d1a',
        'card'     => '#0d1626',
        'nav'      => '#080f1e',
        'hero_from'=> '#10173a',
        'hero_to'  => '#060d1a',
        'acc'      => '#6366f1',
        'acc2'     => '#a5b4fc',
        'txt'      => '#e2e8f0',
        'txt2'     => 'rgba(226,232,240,.55)',
        'txt3'     => 'rgba(226,232,240,.25)',
        'border'   => 'rgba(255,255,255,.08)',
        'is_light' => false,
    ],
    'Pure White' => [
        'bg'       => '#f8fafc',
        'card'     => '#ffffff',
        'nav'      => '#ffffff',
        'hero_from'=> '#eef2ff',
        'hero_to'  => '#f8fafc',
        'acc'      => '#6366f1',
        'acc2'     => '#4f46e5',
        'txt'      => '#0f172a',
        'txt2'     => 'rgba(15,23,42,.55)',
        'txt3'     => 'rgba(15,23,42,.3)',
        'border'   => 'rgba(0,0,0,.09)',
        'is_light' => true,
    ],
    'Neon City'  => [
        'bg'       => '#0d0d1a',
        'card'     => '#13132a',
        'nav'      => '#0a0a18',
        'hero_from'=> '#1a0533',
        'hero_to'  => '#0d0d1a',
        'acc'      => '#f0abfc',
        'acc2'     => '#c084fc',
        'txt'      => '#fdf4ff',
        'txt2'     => 'rgba(253,244,255,.55)',
        'txt3'     => 'rgba(253,244,255,.25)',
        'border'   => 'rgba(240,171,252,.08)',
        'is_light' => false,
    ],
    'Forest'     => [
        'bg'       => '#052e16',
        'card'     => '#063a1d',
        'nav'      => '#041f10',
        'hero_from'=> '#064e3b',
        'hero_to'  => '#052e16',
        'acc'      => '#4ade80',
        'acc2'     => '#22d3ee',
        'txt'      => '#f0fdf4',
        'txt2'     => 'rgba(240,253,244,.55)',
        'txt3'     => 'rgba(240,253,244,.25)',
        'border'   => 'rgba(74,222,128,.1)',
        'is_light' => false,
    ],
    'Sunset'     => [
        'bg'       => '#1c0a00',
        'card'     => '#261100',
        'nav'      => '#140700',
        'hero_from'=> '#431407',
        'hero_to'  => '#1c0a00',
        'acc'      => '#fb923c',
        'acc2'     => '#fbbf24',
        'txt'      => '#fff7ed',
        'txt2'     => 'rgba(255,247,237,.55)',
        'txt3'     => 'rgba(255,247,237,.25)',
        'border'   => 'rgba(251,146,60,.09)',
        'is_light' => false,
    ],
    'Ocean Blue' => [
        'bg'       => '#030d1f',
        'card'     => '#061525',
        'nav'      => '#020b18',
        'hero_from'=> '#0c2461',
        'hero_to'  => '#030d1f',
        'acc'      => '#38bdf8',
        'acc2'     => '#818cf8',
        'txt'      => '#e0f2fe',
        'txt2'     => 'rgba(224,242,254,.55)',
        'txt3'     => 'rgba(224,242,254,.25)',
        'border'   => 'rgba(56,189,248,.09)',
        'is_light' => false,
    ],
    // Legacy theme names (backward compat)
    'Tech Pro'   => ['bg'=>'#060d1a','card'=>'#0d1626','nav'=>'#080f1e','hero_from'=>'#10173a','hero_to'=>'#060d1a','acc'=>'#6366f1','acc2'=>'#8b5cf6','txt'=>'#e2e8f0','txt2'=>'rgba(226,232,240,.55)','txt3'=>'rgba(226,232,240,.25)','border'=>'rgba(255,255,255,.08)','is_light'=>false],
    'Emerald'    => ['bg'=>'#071812','card'=>'#0d2318','nav'=>'#040f0b','hero_from'=>'#064e3b','hero_to'=>'#071812','acc'=>'#10b981','acc2'=>'#34d399','txt'=>'#d1fae5','txt2'=>'rgba(209,250,229,.55)','txt3'=>'rgba(209,250,229,.25)','border'=>'rgba(16,185,129,.09)','is_light'=>false],
    'Crimson'    => ['bg'=>'#170808','card'=>'#220f0f','nav'=>'#100505','hero_from'=>'#450a0a','hero_to'=>'#170808','acc'=>'#ef4444','acc2'=>'#f87171','txt'=>'#fee2e2','txt2'=>'rgba(254,226,226,.55)','txt3'=>'rgba(254,226,226,.25)','border'=>'rgba(239,68,68,.09)','is_light'=>false],
    'Ocean'      => ['bg'=>'#040e1e','card'=>'#071525','nav'=>'#030b18','hero_from'=>'#0c2461','hero_to'=>'#040e1e','acc'=>'#0ea5e9','acc2'=>'#38bdf8','txt'=>'#e0f2fe','txt2'=>'rgba(224,242,254,.55)','txt3'=>'rgba(224,242,254,.25)','border'=>'rgba(14,165,233,.09)','is_light'=>false],
    'Gold'       => ['bg'=>'#130e03','card'=>'#1c1404','nav'=>'#0d0b02','hero_from'=>'#451a03','hero_to'=>'#130e03','acc'=>'#f59e0b','acc2'=>'#fbbf24','txt'=>'#fef3c7','txt2'=>'rgba(254,243,199,.55)','txt3'=>'rgba(254,243,199,.25)','border'=>'rgba(245,158,11,.09)','is_light'=>false],
    'Rose'       => ['bg'=>'#170611','card'=>'#210b18','nav'=>'#100409','hero_from'=>'#4a0d30','hero_to'=>'#170611','acc'=>'#ec4899','acc2'=>'#f472b6','txt'=>'#fce7f3','txt2'=>'rgba(252,231,243,.55)','txt3'=>'rgba(252,231,243,.25)','border'=>'rgba(236,72,153,.09)','is_light'=>false],

    /* ═══════════════════════════════════════════════════════
       MARKETPLACE THEMES — 25 Premium Industry Themes v3.0
       All unique design languages, auto-compatible with customizer
       ═══════════════════════════════════════════════════════ */

    // ── FASHION ──
    'Luxury Fashion'   => ['bg'=>'#1a0a2e','card'=>'#2d1256','nav'=>'#0d0520','hero_from'=>'#1a0a2e','hero_to'=>'#0d0520','acc'=>'#C4B5FD','acc2'=>'#F59E0B','txt'=>'#f5f3ff','txt2'=>'rgba(245,243,255,.55)','txt3'=>'rgba(245,243,255,.25)','border'=>'rgba(196,181,253,.1)','is_light'=>false],
    'Premium Boutique' => ['bg'=>'#0f1724','card'=>'#1e2a3a','nav'=>'#0a1018','hero_from'=>'#1e1535','hero_to'=>'#0f1724','acc'=>'#F472B6','acc2'=>'#A78BFA','txt'=>'#fdf2f8','txt2'=>'rgba(253,242,248,.55)','txt3'=>'rgba(253,242,248,.25)','border'=>'rgba(244,114,182,.1)','is_light'=>false],
    'Streetwear Hub'   => ['bg'=>'#0a0a0a','card'=>'#141414','nav'=>'#050505','hero_from'=>'#141414','hero_to'=>'#0a0a0a','acc'=>'#22D3EE','acc2'=>'#F59E0B','txt'=>'#ffffff','txt2'=>'rgba(255,255,255,.55)','txt3'=>'rgba(255,255,255,.25)','border'=>'rgba(34,211,238,.12)','is_light'=>false],

    // ── ELECTRONICS ──
    'Tech Pro'         => ['bg'=>'#071628','card'=>'#0f2340','nav'=>'#040f1c','hero_from'=>'#1e3a5f','hero_to'=>'#071628','acc'=>'#06B6D4','acc2'=>'#34D399','txt'=>'#e0f2fe','txt2'=>'rgba(224,242,254,.55)','txt3'=>'rgba(224,242,254,.25)','border'=>'rgba(6,182,212,.1)','is_light'=>false],
    'Gadget Hub'       => ['bg'=>'#06101c','card'=>'#0d1b2a','nav'=>'#040c16','hero_from'=>'#1a2e44','hero_to'=>'#06101c','acc'=>'#38BDF8','acc2'=>'#818CF8','txt'=>'#e0f7fa','txt2'=>'rgba(224,247,250,.55)','txt3'=>'rgba(224,247,250,.25)','border'=>'rgba(56,189,248,.1)','is_light'=>false],

    // ── FURNITURE ──
    'Nordic Home'      => ['bg'=>'#1a1510','card'=>'#2a2018','nav'=>'#100e08','hero_from'=>'#2a2018','hero_to'=>'#1a1510','acc'=>'#D4B896','acc2'=>'#A3A99C','txt'=>'#faf7f4','txt2'=>'rgba(250,247,244,.55)','txt3'=>'rgba(250,247,244,.25)','border'=>'rgba(212,184,150,.1)','is_light'=>false],
    'Interior Studio'  => ['bg'=>'#0e0a18','card'=>'#1c1428','nav'=>'#080612','hero_from'=>'#1c1428','hero_to'=>'#0e0a18','acc'=>'#C084FC','acc2'=>'#E879F9','txt'=>'#f5f0ff','txt2'=>'rgba(245,240,255,.55)','txt3'=>'rgba(245,240,255,.25)','border'=>'rgba(192,132,252,.1)','is_light'=>false],

    // ── JEWELLERY ──
    'Diamond Jewels'   => ['bg'=>'#0a0800','card'=>'#1a1400','nav'=>'#050400','hero_from'=>'#1a1400','hero_to'=>'#0a0800','acc'=>'#D4AF37','acc2'=>'#F5E6A3','txt'=>'#fff9e6','txt2'=>'rgba(255,249,230,.55)','txt3'=>'rgba(255,249,230,.25)','border'=>'rgba(212,175,55,.12)','is_light'=>false],
    'Silver Craft'     => ['bg'=>'#0a0e18','card'=>'#141e2e','nav'=>'#050810','hero_from'=>'#141e2e','hero_to'=>'#0a0e18','acc'=>'#94A3B8','acc2'=>'#CBD5E1','txt'=>'#f1f5f9','txt2'=>'rgba(241,245,249,.55)','txt3'=>'rgba(241,245,249,.25)','border'=>'rgba(148,163,184,.1)','is_light'=>false],

    // ── BEAUTY / COSMETICS ──
    'Glow Beauty'      => ['bg'=>'#120615','card'=>'#1e0a25','nav'=>'#0d0410','hero_from'=>'#2d1035','hero_to'=>'#120615','acc'=>'#F9A8D4','acc2'=>'#FB923C','txt'=>'#fff0f6','txt2'=>'rgba(255,240,246,.55)','txt3'=>'rgba(255,240,246,.25)','border'=>'rgba(249,168,212,.1)','is_light'=>false],
    'Natural Wellness' => ['bg'=>'#081208','card'=>'#0c1c12','nav'=>'#050e05','hero_from'=>'#142a1c','hero_to'=>'#081208','acc'=>'#6EE7B7','acc2'=>'#FDE68A','txt'=>'#f0fdf4','txt2'=>'rgba(240,253,244,.55)','txt3'=>'rgba(240,253,244,.25)','border'=>'rgba(110,231,183,.1)','is_light'=>false],

    // ── FOOD & DRINK ──
    'Bistro Dark'      => ['bg'=>'#0e0805','card'=>'#1a0f08','nav'=>'#080502','hero_from'=>'#2d1e10','hero_to'=>'#0e0805','acc'=>'#D97706','acc2'=>'#FDE68A','txt'=>'#fffbeb','txt2'=>'rgba(255,251,235,.55)','txt3'=>'rgba(255,251,235,.25)','border'=>'rgba(217,119,6,.1)','is_light'=>false],
    'Café Vibes'       => ['bg'=>'#100806','card'=>'#1c0e08','nav'=>'#0a0504','hero_from'=>'#2d1a10','hero_to'=>'#100806','acc'=>'#C2956C','acc2'=>'#FEF3C7','txt'=>'#fffaf5','txt2'=>'rgba(255,250,245,.55)','txt3'=>'rgba(255,250,245,.25)','border'=>'rgba(194,149,108,.1)','is_light'=>false],
    'Sweet Bakery'     => ['bg'=>'#120810','card'=>'#1c0a14','nav'=>'#0d0509','hero_from'=>'#2e1422','hero_to'=>'#120810','acc'=>'#F472B6','acc2'=>'#FDE68A','txt'=>'#fdf2f8','txt2'=>'rgba(253,242,248,.55)','txt3'=>'rgba(253,242,248,.25)','border'=>'rgba(244,114,182,.1)','is_light'=>false],

    // ── MEDICAL ──
    'Pharma Care'      => ['bg'=>'#030e18','card'=>'#051520','nav'=>'#020a12','hero_from'=>'#0a2535','hero_to'=>'#030e18','acc'=>'#38BDF8','acc2'=>'#34D399','txt'=>'#f0f9ff','txt2'=>'rgba(240,249,255,.55)','txt3'=>'rgba(240,249,255,.25)','border'=>'rgba(56,189,248,.1)','is_light'=>false],

    // ── SPORTS ──
    'Sport Zone'       => ['bg'=>'#050505','card'=>'#0a0a0a','nav'=>'#020202','hero_from'=>'#111111','hero_to'=>'#050505','acc'=>'#22D3EE','acc2'=>'#F59E0B','txt'=>'#e0f7fa','txt2'=>'rgba(224,247,250,.55)','txt3'=>'rgba(224,247,250,.25)','border'=>'rgba(34,211,238,.12)','is_light'=>false],

    // ── BOOKS ──
    'Page Turner'      => ['bg'=>'#100c05','card'=>'#1c140a','nav'=>'#0a0803','hero_from'=>'#2d2010','hero_to'=>'#100c05','acc'=>'#D97706','acc2'=>'#FDE68A','txt'=>'#fffbeb','txt2'=>'rgba(255,251,235,.55)','txt3'=>'rgba(255,251,235,.25)','border'=>'rgba(217,119,6,.1)','is_light'=>false],

    // ── KIDS ──
    'Kiddoland'        => ['bg'=>'#080614','card'=>'#0d0a1e','nav'=>'#050410','hero_from'=>'#1a1430','hero_to'=>'#080614','acc'=>'#A78BFA','acc2'=>'#34D399','txt'=>'#f9f0ff','txt2'=>'rgba(249,240,255,.55)','txt3'=>'rgba(249,240,255,.25)','border'=>'rgba(167,139,250,.12)','is_light'=>false],

    // ── DIGITAL ──
    'Digital Depot'    => ['bg'=>'#040812','card'=>'#080e1c','nav'=>'#02050d','hero_from'=>'#0d1828','hero_to'=>'#040812','acc'=>'#6366F1','acc2'=>'#34D399','txt'=>'#eef2ff','txt2'=>'rgba(238,242,255,.55)','txt3'=>'rgba(238,242,255,.25)','border'=>'rgba(99,102,241,.1)','is_light'=>false],

    // ── LUXURY ──
    'Obsidian Luxury'  => ['bg'=>'#020202','card'=>'#050505','nav'=>'#010101','hero_from'=>'#0a0a0a','hero_to'=>'#020202','acc'=>'#D4AF37','acc2'=>'#F5E6A3','txt'=>'#fffce6','txt2'=>'rgba(255,252,230,.55)','txt3'=>'rgba(255,252,230,.25)','border'=>'rgba(212,175,55,.15)','is_light'=>false],

    // ── GROCERY ──
    'Fresh Market'     => ['bg'=>'#041808','card'=>'#062310','nav'=>'#021005','hero_from'=>'#0d3b1e','hero_to'=>'#041808','acc'=>'#22C55E','acc2'=>'#FCD34D','txt'=>'#f0fdf4','txt2'=>'rgba(240,253,244,.55)','txt3'=>'rgba(240,253,244,.25)','border'=>'rgba(34,197,94,.12)','is_light'=>false],

    // ── MINIMAL ──
    'Minimal Zen'      => ['bg'=>'#050608','card'=>'#0a0c0f','nav'=>'#030405','hero_from'=>'#12161c','hero_to'=>'#050608','acc'=>'#A0AEC0','acc2'=>'#FFFFFF','txt'=>'#f8fafc','txt2'=>'rgba(248,250,252,.55)','txt3'=>'rgba(248,250,252,.25)','border'=>'rgba(160,174,192,.1)','is_light'=>false],

    // ── GENERAL ──
    'Metro Store'      => ['bg'=>'#060e1c','card'=>'#0c1828','nav'=>'#040a14','hero_from'=>'#152338','hero_to'=>'#060e1c','acc'=>'#6366F1','acc2'=>'#A78BFA','txt'=>'#eef2ff','txt2'=>'rgba(238,242,255,.55)','txt3'=>'rgba(238,242,255,.25)','border'=>'rgba(99,102,241,.1)','is_light'=>false],
    'Neon City'        => ['bg'=>'#080818','card'=>'#0d0d1a','nav'=>'#050510','hero_from'=>'#1a0533','hero_to'=>'#080818','acc'=>'#F0ABFC','acc2'=>'#818CF8','txt'=>'#fdf4ff','txt2'=>'rgba(253,244,255,.55)','txt3'=>'rgba(253,244,255,.25)','border'=>'rgba(240,171,252,.1)','is_light'=>false],

    // ── ENTERPRISE ──
    'Enterprise X'     => ['bg'=>'#020812','card'=>'#050e1c','nav'=>'#01050d','hero_from'=>'#0a1a2e','hero_to'=>'#020812','acc'=>'#2563EB','acc2'=>'#60A5FA','txt'=>'#eff6ff','txt2'=>'rgba(239,246,255,.55)','txt3'=>'rgba(239,246,255,.25)','border'=>'rgba(37,99,235,.1)','is_light'=>false],
];

$t = $themeMap[$storeTheme] ?? $themeMap['Luxe Dark'];

// Override accent color if store has custom accent
if ($storeAccent && preg_match('/^#[0-9a-fA-F]{3,6}$/', $storeAccent)) {
    $t['acc']  = $storeAccent;
    $t['acc2'] = $storeAccent; // user-picked colour used for both
}

$isLight  = $t['is_light'];
$gridMap  = [3=>'repeat(auto-fill,minmax(220px,1fr))', 4=>'repeat(auto-fill,minmax(190px,1fr))', 5=>'repeat(auto-fill,minmax(165px,1fr))'];
$gridCss  = $gridMap[$storeGridCols] ?? $gridMap[4];

/* ── Google Fonts map ── */
$fontMap = [
    'Inter'           => 'Inter:wght@300;400;500;600;700;800;900',
    'Poppins'         => 'Poppins:wght@300;400;500;600;700;800;900',
    'Playfair Display'=> 'Playfair+Display:wght@400;600;700;800;900',
    'Space Grotesk'   => 'Space+Grotesk:wght@300;400;500;600;700',
    'Nunito'          => 'Nunito:wght@300;400;500;600;700;800;900',
    'Roboto'          => 'Roboto:wght@300;400;500;700;900',
];
$fontFamily    = htmlspecialchars($storeFont);
$fontQuery     = $fontMap[$storeFont] ?? 'Inter:wght@300;400;500;600;700;800;900';
$fontFallback  = "'{$fontFamily}',system-ui,sans-serif";
$fontStack     = $fontFallback;

// Light theme text adjustments
$navTextColor   = $isLight ? '#0f172a'            : '#fff';
$navSubColor    = $isLight ? 'rgba(15,23,42,.5)'  : 'var(--txt2)';
$navBtnColor    = $isLight ? 'rgba(15,23,42,.45)' : 'var(--txt2)';
$sortBg         = $isLight ? 'rgba(0,0,0,.04)'    : 'rgba(255,255,255,.04)';
$sortColor      = $isLight ? '#0f172a'            : 'var(--txt2)';
$sortOptBg      = $isLight ? '#fff'               : '#1a1a2e';
$cardBg         = $isLight ? '#fff'               : 'var(--card)';
$cardHoverShadow= $isLight ? '0 12px 36px rgba(0,0,0,.12)' : '0 12px 36px rgba(0,0,0,.4)';
/* ── Extra derived vars ── */
$featuredProducts = array_slice($products, 0, 8); // first 8 as featured
$catList = []; // cat_id => ['name'=>..,'products'=>[...]]
foreach ($products as $p) {
    $cid = $p['cat_id'] ?? 0;
    if (!isset($catList[$cid])) $catList[$cid] = ['name'=>$p['cat_name']??'General','products'=>[]];
    $catList[$cid]['products'][] = $p;
}
$totalProducts = count($products);
$totalCats     = count($catList);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/stockora-favicon.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5">
<meta name="theme-color" content="<?= $t['bg'] ?>">
<meta name="description" content="<?= htmlspecialchars($storeTagline ?: $storeName.' — Shop online') ?>">
<title><?= htmlspecialchars($storeName) ?> — Shop Online</title>
<?php if ($storeFont && $storeFont !== 'Inter'): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=<?= $fontQuery ?>&family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<?php else: ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<?php endif; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════════
   STOCKORA STORE v4.0 — Shopify-Quality Professional Storefront
   Homepage · Product Detail · Categories · Cart · Order System
   ═══════════════════════════════════════════════════════════════ */
:root {
  --bg:       <?= $t['bg'] ?>;
  --card:     <?= $t['card'] ?>;
  --nav-bg:   <?= $t['nav'] ?>;
  --acc:      <?= $t['acc'] ?>;
  --acc2:     <?= $t['acc2'] ?>;
  --txt:      <?= $t['txt'] ?>;
  --txt2:     <?= $t['txt2'] ?>;
  --txt3:     <?= $t['txt3'] ?>;
  --border:   <?= $t['border'] ?>;
  --border2:  color-mix(in srgb, var(--acc) 38%, transparent);
  --shadow:   0 8px 32px rgba(0,0,0,.45);
  --radius:   16px;
  --radius-sm:10px;
  --nav-h:    64px;
  --cart-w:   400px;
  --font:     <?= $fontStack ?>;
  --hero-from:<?= $t['hero_from'] ?>;
  --hero-to:  <?= $t['hero_to'] ?>;
}

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body {
  font-family: var(--font);
  background: var(--bg);
  color: var(--txt);
  min-height: 100vh;
  overflow-x: hidden;
  <?= $isLight ? '-webkit-font-smoothing:subpixel-antialiased;' : '' ?>
}
a { text-decoration: none; color: inherit; }

/* ═══════════════ STORE HEADER ═══════════════ */
/* Keep the announcement and navigation in one sticky stack.  Individual
   sticky elements both using top:0 can otherwise overlap while scrolling. */
.store-header {
  position: sticky;
  top: 0;
  z-index: 300;
}

/* ═══════════════ ANNOUNCEMENT BAR ═══════════════ */
.ann-bar {
  background: linear-gradient(90deg, var(--acc), var(--acc2));
  color: #fff;
  text-align: center;
  padding: .38rem 1rem;
  font-size: .73rem;
  font-weight: 700;
  letter-spacing: .2px;
  position: relative;
  z-index: 1;
}

/* ═══════════════ NAVBAR ═══════════════ */
.s-nav {
  height: var(--nav-h);
  background: color-mix(in srgb, var(--nav-bg) 97%, transparent);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
  position: relative;
  z-index: 1;
  display: flex; align-items: center;
  padding: 0 max(1.5rem, env(safe-area-inset-left));
  gap: .75rem;
  transition: box-shadow .2s;
}
.s-nav.scrolled { box-shadow: 0 4px 24px rgba(0,0,0,.25); }
.s-nav-logo {
  width: 42px; height: 42px; border-radius: 12px;
  background: linear-gradient(135deg, var(--acc), var(--acc2));
  display: flex; align-items: center; justify-content: center;
  font-size: 1.15rem; font-weight: 900; color: #fff;
  flex-shrink: 0; overflow: hidden; cursor: pointer;
  box-shadow: 0 4px 16px color-mix(in srgb, var(--acc) 40%, transparent);
  transition: transform .2s;
}
.s-nav-logo:hover { transform: scale(1.05); }
.s-nav-logo img { width:100%;height:100%;object-fit:cover;border-radius:12px; }
.s-nav-brand { flex:1; min-width:0; cursor:pointer; }
.s-nav-name {
  font-size: .95rem; font-weight: 800;
  color: <?= $isLight ? '#0f172a' : '#fff' ?>;
  line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.s-nav-sub { font-size: .62rem; color: <?= $isLight ? 'rgba(15,23,42,.5)' : 'var(--txt2)' ?>; margin-top:.06rem; }
.s-nav-right { display:flex; align-items:center; gap:.45rem; flex-shrink:0; }

/* Nav pill links */
.nav-pill {
  display: flex; align-items: center; gap: .35rem;
  padding: .4rem .9rem; border-radius: 20px;
  background: <?= $isLight ? 'rgba(0,0,0,.05)' : 'rgba(255,255,255,.05)' ?>;
  border: 1px solid var(--border);
  font-size: .75rem; font-weight: 700;
  color: <?= $isLight ? 'rgba(15,23,42,.6)' : 'var(--txt2)' ?>;
  cursor: pointer; transition: all .18s; text-decoration: none;
}
.nav-pill:hover { background: color-mix(in srgb,var(--acc) 14%,transparent); border-color: var(--border2); color: var(--acc); }
.nav-pill.active { background: var(--acc); border-color: var(--acc); color: #fff; }
.live-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: #34d399; animation: livePulse 1.4s ease-in-out infinite;
}
@keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.75)} }
.nav-icon-btn {
  width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--border);
  background: <?= $isLight ? 'rgba(0,0,0,.04)' : 'rgba(255,255,255,.04)' ?>;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.05rem; cursor: pointer; transition: all .18s; flex-shrink: 0;
  color: <?= $isLight ? 'rgba(15,23,42,.55)' : 'var(--txt2)' ?>;
  position: relative; text-decoration: none;
}
.nav-icon-btn:hover { background: color-mix(in srgb,var(--acc) 14%,transparent); border-color: var(--border2); color: var(--acc); }
.nav-icon-btn.wa { background: rgba(34,197,94,.1); border-color: rgba(34,197,94,.25); color: #34d399; }
.nav-icon-btn.wa:hover { background: rgba(34,197,94,.2); }
.cart-badge {
  position: absolute; top: -5px; right: -5px;
  background: var(--acc); color: #fff;
  width: 18px; height: 18px; border-radius: 50%;
  font-size: .58rem; font-weight: 900;
  display: none; align-items: center; justify-content: center;
  border: 2px solid var(--nav-bg);
}
.cart-badge.show { display: flex; }

/* ═══════════════ HERO SECTION ═══════════════ */
.s-hero {
  position: relative; overflow: hidden;
  background: linear-gradient(145deg, var(--hero-from) 0%, var(--hero-to) 100%);
  padding: 5rem 2rem 4.5rem;
}
.s-hero::before {
  content: '';
  position: absolute; inset: 0; pointer-events: none;
  background:
    radial-gradient(ellipse 80% 60% at 50% -5%, color-mix(in srgb,var(--acc) 22%,transparent), transparent 60%),
    radial-gradient(circle at 85% 80%, color-mix(in srgb,var(--acc2) 10%,transparent), transparent 45%);
}
.s-hero::after {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, transparent 5%, var(--acc) 35%, var(--acc2) 65%, transparent 95%);
  opacity: .7;
}
.hero-particles {
  position: absolute; inset: 0; pointer-events: none; overflow: hidden;
}
.hero-particle {
  position: absolute; width: 2px; height: 2px; border-radius: 50%;
  background: var(--acc); opacity: .25;
  animation: floatParticle linear infinite;
}
@keyframes floatParticle { 0%{transform:translateY(0) scale(1)} 100%{transform:translateY(-100vh) scale(0)} }
.hero-inner {
  position: relative; z-index: 1; text-align: center;
  max-width: 760px; margin: 0 auto;
}
.hero-eyebrow {
  display: inline-flex; align-items: center; gap: .45rem;
  background: color-mix(in srgb,var(--acc) 14%,transparent);
  border: 1px solid color-mix(in srgb,var(--acc) 30%,transparent);
  border-radius: 20px; padding: .3rem 1rem;
  font-size: .7rem; font-weight: 800; color: var(--acc2);
  margin-bottom: 1.2rem; letter-spacing: .4px; text-transform: uppercase;
}
.hero-eyebrow .live-dot { display: inline-block; }
.hero-title {
  font-size: clamp(2rem, 5.5vw, 3.6rem);
  font-weight: 900; letter-spacing: -.04em; line-height: 1.1;
  color: <?= $isLight ? '#0f172a' : '#fff' ?>;
  margin-bottom: .6rem;
}
.hero-title span { color: var(--acc); }
.hero-tagline {
  font-size: clamp(.95rem, 2vw, 1.15rem);
  color: <?= $isLight ? 'rgba(15,23,42,.65)' : 'var(--txt2)' ?>;
  margin-bottom: 2rem; line-height: 1.6; max-width: 560px; margin-left: auto; margin-right: auto;
}
.hero-stats {
  display: flex; gap: 1.5rem; justify-content: center; flex-wrap: wrap; margin-bottom: 2rem;
}
.hero-stat {
  display: flex; flex-direction: column; align-items: center;
  gap: .15rem;
}
.hero-stat-num { font-size: 1.6rem; font-weight: 900; color: <?= $isLight ? '#0f172a' : '#fff' ?>; line-height: 1; }
.hero-stat-lbl { font-size: .65rem; font-weight: 700; color: var(--txt2); text-transform: uppercase; letter-spacing: .5px; }
.hero-stat-div { width: 1px; background: var(--border); height: 36px; align-self: center; }
.hero-actions {
  display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap;
}
.hero-btn-primary {
  display: inline-flex; align-items: center; gap: .5rem;
  background: linear-gradient(135deg, var(--acc), var(--acc2));
  color: #fff; border: none; border-radius: 14px;
  padding: .85rem 2rem; font-size: .95rem; font-weight: 800;
  cursor: pointer; transition: all .22s; font-family: var(--font);
  box-shadow: 0 6px 22px color-mix(in srgb, var(--acc) 38%, transparent);
}
.hero-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 30px color-mix(in srgb, var(--acc) 50%, transparent); }
.hero-btn-secondary {
  display: inline-flex; align-items: center; gap: .5rem;
  background: <?= $isLight ? 'rgba(0,0,0,.07)' : 'rgba(255,255,255,.1)' ?>;
  color: <?= $isLight ? '#0f172a' : '#fff' ?>;
  border: 1.5px solid <?= $isLight ? 'rgba(0,0,0,.12)' : 'rgba(255,255,255,.15)' ?>;
  border-radius: 14px; padding: .85rem 1.75rem;
  font-size: .9rem; font-weight: 700; cursor: pointer;
  transition: all .2s; font-family: var(--font);
}
.hero-btn-secondary:hover { background: color-mix(in srgb,var(--acc) 12%,transparent); border-color: var(--border2); color: var(--acc); }
.hero-trust {
  display: flex; align-items: center; justify-content: center; gap: 1.25rem;
  margin-top: 2rem; flex-wrap: wrap;
}
.hero-trust-item {
  display: flex; align-items: center; gap: .4rem;
  font-size: .72rem; font-weight: 600; color: var(--txt2);
}
.hero-trust-item i { color: var(--acc); font-size: .85rem; }

/* Minimal hero variant */
.s-hero-minimal {
  border-bottom: 1px solid var(--border);
  padding: 2.5rem 2rem 2rem;
  display: flex; align-items: center; justify-content: space-between;
  gap: 2rem; max-width: 1280px; margin: 0 auto;
}

/* ═══════════════ TRUST BAR ═══════════════ */
.trust-bar {
  background: color-mix(in srgb, var(--card) 80%, transparent);
  border-bottom: 1px solid var(--border);
  padding: .75rem 1.5rem;
}
.trust-inner {
  max-width: 1280px; margin: 0 auto;
  display: flex; align-items: center; justify-content: center;
  gap: 2rem; flex-wrap: wrap;
}
.trust-item {
  display: flex; align-items: center; gap: .5rem;
  font-size: .72rem; font-weight: 700; color: var(--txt2);
  white-space: nowrap;
}
.trust-item i { font-size: .9rem; color: var(--acc); }

/* ═══════════════ CATEGORIES SHOWCASE ═══════════════ */
.s-section { max-width: 1280px; margin: 0 auto; padding: 2.5rem 1.5rem 0; }
.s-section-head {
  display: flex; align-items: flex-end; justify-content: space-between;
  margin-bottom: 1.25rem; gap: 1rem;
}
.s-section-title-wrap {}
.s-section-eyebrow {
  font-size: .65rem; font-weight: 800; text-transform: uppercase;
  letter-spacing: .8px; color: var(--acc); margin-bottom: .25rem;
}
.s-section-title {
  font-size: 1.35rem; font-weight: 900;
  color: <?= $isLight ? '#0f172a' : '#fff' ?>;
  letter-spacing: -.02em; line-height: 1.2;
}
.s-section-view-all {
  display: flex; align-items: center; gap: .3rem;
  font-size: .75rem; font-weight: 700; color: var(--acc);
  cursor: pointer; white-space: nowrap; transition: gap .18s;
  background: none; border: none; font-family: var(--font);
}
.s-section-view-all:hover { gap: .55rem; }

/* Category cards grid */
.cat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 1rem;
}
.cat-card {
  position: relative; border-radius: var(--radius);
  overflow: hidden; cursor: pointer;
  aspect-ratio: 1 / 1.1;
  background: <?= $isLight ? '#fff' : 'var(--card)' ?>;
  border: 1.5px solid var(--border);
  transition: all .25s;
  display: flex; flex-direction: column; align-items: center; justify-content: flex-end;
  padding: 1rem;
  box-shadow: <?= $isLight ? '0 2px 10px rgba(0,0,0,.06)' : 'none' ?>;
}
.cat-card:hover { transform: translateY(-5px); border-color: var(--border2); box-shadow: 0 12px 32px color-mix(in srgb, var(--acc) 15%, transparent); }
.cat-card-bg {
  position: absolute; inset: 0; pointer-events: none;
  background: linear-gradient(160deg, color-mix(in srgb,var(--acc) 12%,transparent), transparent 60%);
  opacity: 0; transition: opacity .25s;
}
.cat-card:hover .cat-card-bg { opacity: 1; }
.cat-card-icon {
  font-size: 2.5rem; margin-bottom: .85rem;
  filter: <?= $isLight ? 'none' : 'brightness(1.2)' ?>;
  position: relative; z-index: 1;
}
.cat-card-name {
  font-size: .82rem; font-weight: 800;
  color: <?= $isLight ? '#0f172a' : '#fff' ?>;
  text-align: center; z-index: 1; position: relative; line-height: 1.3;
}
.cat-card-count {
  font-size: .62rem; font-weight: 600; color: var(--acc2);
  margin-top: .2rem; z-index: 1; position: relative;
}

/* ═══════════════ FEATURED HORIZONTAL SCROLL ═══════════════ */
.feat-scroll-wrap { position: relative; }
.feat-scroll {
  display: flex; gap: .85rem;
  overflow-x: auto; padding-bottom: 4px; scrollbar-width: none;
}
.feat-scroll::-webkit-scrollbar { display: none; }
.feat-card {
  flex-shrink: 0; width: 180px;
  background: <?= $isLight ? '#fff' : 'var(--card)' ?>;
  border: 1.5px solid var(--border);
  border-radius: var(--radius-sm);
  overflow: hidden; cursor: pointer; transition: all .22s;
  box-shadow: <?= $isLight ? '0 2px 8px rgba(0,0,0,.06)' : 'none' ?>;
  display: flex; flex-direction: column;
}
.feat-card:hover { border-color: var(--border2); transform: translateY(-3px); box-shadow: 0 8px 24px color-mix(in srgb, var(--acc) 14%, transparent); }
.feat-card-img {
  width: 100%; height: 130px; overflow: hidden;
  background: color-mix(in srgb, var(--acc) 7%, <?= $isLight ? '#f8fafc' : 'var(--card)' ?>);
  display: flex; align-items: center; justify-content: center; position: relative;
  flex-shrink: 0;
}
.feat-card-img img { width:100%;height:100%;object-fit:cover;transition:transform .32s; }
.feat-card:hover .feat-card-img img { transform: scale(1.08); }
.feat-card-img-ph { font-size: 2.4rem; opacity: .28; }
.feat-card-badge {
  position: absolute; top: .5rem; left: .5rem;
  background: var(--acc); color: #fff;
  border-radius: 6px; padding: .15rem .45rem;
  font-size: .58rem; font-weight: 800; letter-spacing: .3px;
}
.feat-card-body { padding: .7rem .75rem .75rem; flex: 1; display: flex; flex-direction: column; gap: .25rem; }
.feat-card-cat { font-size: .58rem; font-weight: 700; color: var(--acc2); text-transform: uppercase; letter-spacing: .5px; }
.feat-card-name { font-size: .8rem; font-weight: 700; color: <?= $isLight ? '#0f172a' : '#fff' ?>; line-height: 1.3; flex: 1; }
.feat-card-footer { display: flex; align-items: center; justify-content: space-between; margin-top: auto; gap: .4rem; }
.feat-card-price { font-size: .92rem; font-weight: 900; color: var(--acc2); }
.feat-add-btn {
  width: 30px; height: 30px; border-radius: 8px;
  background: var(--acc); border: none; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .9rem; cursor: pointer; transition: all .18s; flex-shrink: 0;
}
.feat-add-btn:hover { background: var(--acc2); transform: scale(1.1); }

/* ═══════════════ PRODUCT GRID ═══════════════ */
.s-shop-bar {
  background: color-mix(in srgb, var(--bg) 95%, transparent);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border);
  padding: .85rem 1.5rem;
  position: sticky; top: var(--nav-h); z-index: 190;
}
.s-shop-bar-inner {
  max-width: 1280px; margin: 0 auto;
  display: flex; align-items: center; gap: .75rem;
  flex-wrap: wrap;
}
.s-search-wrap { position: relative; flex: 1; min-width: 180px; }
.s-search {
  width: 100%; padding: .6rem 1rem .6rem 2.6rem;
  background: <?= $isLight ? '#fff' : 'rgba(255,255,255,.05)' ?>;
  border: 1.5px solid var(--border); border-radius: 10px;
  color: var(--txt); font-size: .85rem; outline: none;
  font-family: var(--font); transition: all .2s;
  box-shadow: <?= $isLight ? '0 1px 4px rgba(0,0,0,.06)' : 'none' ?>;
}
.s-search::placeholder { color: var(--txt3); }
.s-search:focus { border-color: var(--border2); box-shadow: 0 0 0 3px color-mix(in srgb,var(--acc) 12%,transparent); }
.s-search-ico { position: absolute; left: .85rem; top: 50%; transform: translateY(-50%); color: var(--txt3); font-size: .88rem; pointer-events: none; }
.s-search-clear { position: absolute; right: .7rem; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--txt2); cursor: pointer; display: none; font-size: .88rem; padding: .2rem; }

.sort-select {
  background: <?= $isLight ? '#fff' : 'rgba(255,255,255,.05)' ?>;
  border: 1.5px solid var(--border); border-radius: 10px;
  color: <?= $isLight ? '#0f172a' : 'var(--txt2)' ?>; font-size: .78rem;
  padding: .6rem .85rem; outline: none; font-family: var(--font); cursor: pointer;
}
.sort-select option { background: <?= $isLight ? '#fff' : '#1a1a2e' ?>; }

.cat-pills-row { display: flex; gap: .4rem; overflow-x: auto; scrollbar-width: none; padding-bottom: 2px; width: 100%; }
.cat-pills-row::-webkit-scrollbar { display: none; }
.cat-pill {
  flex-shrink: 0; padding: .32rem .9rem; border-radius: 20px;
  border: 1.5px solid var(--border);
  background: <?= $isLight ? 'rgba(0,0,0,.02)' : 'rgba(255,255,255,.03)' ?>;
  font-size: .73rem; font-weight: 700; cursor: pointer;
  transition: all .18s; color: var(--txt2); white-space: nowrap;
  font-family: var(--font);
}
.cat-pill:hover { border-color: var(--border2); color: var(--acc); }
.cat-pill.active { background: var(--acc); border-color: var(--acc); color: #fff; }

.s-main { max-width: 1280px; margin: 0 auto; padding: 1.75rem 1.5rem 7rem; }
.s-grid-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.1rem; gap: .5rem; }
.s-grid-count { font-size: .78rem; color: var(--txt2); font-weight: 600; }
.s-grid {
  display: grid;
  grid-template-columns: <?= $gridCss ?>;
  gap: 1.1rem;
}

/* ═══════════════ PRODUCT CARD ═══════════════ */
.p-card {
  background: <?= $isLight ? '#fff' : 'var(--card)' ?>;
  border: 1.5px solid var(--border);
  border-radius: var(--radius); overflow: hidden;
  cursor: pointer; transition: all .22s; position: relative;
  display: flex; flex-direction: column;
  box-shadow: <?= $isLight ? '0 2px 8px rgba(0,0,0,.06)' : 'none' ?>;
}
.p-card:hover {
  border-color: var(--border2);
  transform: translateY(-5px);
  box-shadow: <?= $isLight ? '0 14px 40px rgba(0,0,0,.12)' : '0 14px 40px rgba(0,0,0,.4)' ?>, 0 0 0 1px color-mix(in srgb,var(--acc) 20%,transparent);
}
.p-card-img {
  width: 100%; aspect-ratio: 1; overflow: hidden;
  background: color-mix(in srgb, var(--acc) 7%, <?= $isLight ? '#f8fafc' : 'var(--card)' ?>);
  display: flex; align-items: center; justify-content: center; position: relative;
}
.p-card-img img { width:100%;height:100%;object-fit:cover;transition:transform .34s; }
.p-card:hover .p-card-img img { transform: scale(1.07); }
.p-card-img-ph { font-size: 2.8rem; opacity: .3; }
.p-card-badge {
  position: absolute; top: .55rem; left: .55rem;
  background: var(--acc); color: #fff;
  border-radius: 7px; padding: .2rem .55rem;
  font-size: .6rem; font-weight: 800; letter-spacing: .3px;
}
.p-card-badge.new { background: linear-gradient(135deg,#10b981,#34d399); }
/* Quick-view button on card hover */
.p-card-quick {
  position: absolute; bottom: .7rem; left: 50%; transform: translateX(-50%) translateY(12px);
  opacity: 0; transition: all .22s;
  background: rgba(0,0,0,.65); backdrop-filter: blur(6px);
  color: #fff; border: none; border-radius: 20px;
  padding: .35rem 1rem; font-size: .7rem; font-weight: 700; cursor: pointer;
  white-space: nowrap; font-family: var(--font);
  display: flex; align-items: center; gap: .3rem;
}
.p-card:hover .p-card-quick { opacity: 1; transform: translateX(-50%) translateY(0); }
.p-card-body { padding: .9rem .9rem 1rem; flex: 1; display: flex; flex-direction: column; }
.p-card-cat { font-size: .6rem; font-weight: 800; color: var(--acc2); text-transform: uppercase; letter-spacing: .5px; margin-bottom: .3rem; }
.p-card-name { font-size: .87rem; font-weight: 700; color: <?= $isLight ? '#0f172a' : '#fff' ?>; line-height: 1.35; margin-bottom: .35rem; flex: 1; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.p-card-footer { display: flex; align-items: center; justify-content: space-between; margin-top: auto; gap: .35rem; }
.p-price { font-size: 1.05rem; font-weight: 900; color: <?= $isLight ? 'var(--acc)' : 'var(--acc2)' ?>; line-height: 1.1; }
.p-price sub { font-size: .62rem; font-weight: 500; color: var(--txt2); vertical-align: baseline; }

.p-add {
  width: 36px; height: 36px; border-radius: 10px;
  background: var(--acc); border: none; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; cursor: pointer; transition: all .18s; flex-shrink: 0;
  box-shadow: 0 4px 14px color-mix(in srgb,var(--acc) 35%,transparent);
}
.p-add:hover { background: var(--acc2); transform: scale(1.1); }

.p-qty-ctrl { display: none; align-items: center; gap: .3rem; }
.p-card.in-cart .p-add { display: none; }
.p-card.in-cart .p-qty-ctrl { display: flex; }
.p-card.in-cart { border-color: var(--border2); }
.p-qty-btn {
  width: 30px; height: 30px; border-radius: 8px;
  border: 1px solid var(--border);
  background: <?= $isLight ? 'rgba(0,0,0,.05)' : 'rgba(255,255,255,.07)' ?>;
  color: var(--txt); font-size: .95rem; cursor: pointer;
  display: flex; align-items: center; justify-content: center; font-weight: 700;
  transition: all .15s;
}
.p-qty-btn:hover { background: color-mix(in srgb,var(--acc) 22%,transparent); border-color: var(--border2); color: var(--acc); }
.p-qty-btn:disabled { opacity: .35; cursor: not-allowed; pointer-events: none; }
.p-qty-num { font-size: .85rem; font-weight: 900; min-width: 20px; text-align: center; color: <?= $isLight ? '#0f172a' : '#fff' ?>; }

/* ── Stock limit badge on product card image ── */
.stock-left-badge {
  position: absolute; bottom: .4rem; left: .4rem;
  background: rgba(245,158,11,.92); color: #fff;
  font-size: .6rem; font-weight: 800; padding: .15rem .45rem;
  border-radius: 6px; letter-spacing: .3px;
  pointer-events: none;
}
/* ── Stock warning in cart item row ── */
.ci-stock-warn {
  font-size: .65rem; color: #f59e0b; font-weight: 700;
  margin-top: .15rem; display: block;
}

/* Empty grid state */
.grid-empty { grid-column: 1/-1; text-align: center; padding: 4rem 1rem; color: var(--txt2); }
.grid-empty i { font-size: 3.5rem; display: block; margin-bottom: 1rem; opacity: .14; }
.grid-empty-title { font-size: 1rem; font-weight: 800; color: <?= $isLight ? '#0f172a' : '#fff' ?>; margin-bottom: .4rem; }

/* ═══════════════ PRODUCT DETAIL MODAL ═══════════════ */
.pdp-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.72); backdrop-filter: blur(8px);
  z-index: 700; opacity: 0; pointer-events: none; transition: opacity .25s;
  display: flex; align-items: flex-end; justify-content: center;
}
.pdp-overlay.open { opacity: 1; pointer-events: auto; }
.pdp-sheet {
  width: 100%; max-width: 620px;
  background: <?= $t['card'] ?>;
  border-radius: 24px 24px 0 0;
  border: 1px solid var(--border); border-bottom: none;
  transform: translateY(40px); transition: transform .32s cubic-bezier(.22,.68,0,1.2);
  max-height: 90vh; overflow-y: auto; padding: 0 0 2rem;
}
.pdp-overlay.open .pdp-sheet { transform: translateY(0); }
.pdp-sheet::-webkit-scrollbar { width: 4px; }
.pdp-sheet::-webkit-scrollbar-thumb { background: rgba(128,128,128,.15); border-radius: 4px; }
.pdp-handle { width: 36px; height: 4px; background: rgba(128,128,128,.2); border-radius: 2px; margin: .85rem auto .5rem; }
.pdp-head { display: flex; align-items: flex-start; gap: .75rem; padding: .5rem 1.4rem 0; }
.pdp-close {
  width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
  background: <?= $isLight ? 'rgba(0,0,0,.05)' : 'rgba(255,255,255,.07)' ?>;
  border: 1px solid var(--border); cursor: pointer;
  display: flex; align-items: center; justify-content: center; color: var(--txt2);
  transition: all .18s; margin-left: auto;
}
.pdp-close:hover { background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.3); color: #f87171; }
.pdp-img-wrap {
  width: 100%; aspect-ratio: 4/3; overflow: hidden;
  background: color-mix(in srgb,var(--acc) 7%, <?= $isLight ? '#f8fafc' : 'var(--card)' ?>);
  display: flex; align-items: center; justify-content: center;
  margin: .5rem 0; position: relative;
}
.pdp-img-wrap img { width:100%;height:100%;object-fit:cover; }
.pdp-img-ph { font-size: 4.5rem; opacity: .22; }
.pdp-body { padding: 1.2rem 1.4rem 0; }
.pdp-cat { font-size: .65rem; font-weight: 800; color: var(--acc2); text-transform: uppercase; letter-spacing: .6px; margin-bottom: .45rem; }
.pdp-name { font-size: 1.3rem; font-weight: 900; color: <?= $isLight ? '#0f172a' : '#fff' ?>; line-height: 1.25; margin-bottom: .6rem; }
.pdp-price-row { display: flex; align-items: baseline; gap: .75rem; margin-bottom: 1rem; }
.pdp-price { font-size: 1.85rem; font-weight: 900; color: var(--acc2); }
.pdp-price-sub { font-size: .75rem; color: var(--txt2); font-weight: 600; }
.pdp-desc { font-size: .85rem; color: var(--txt2); line-height: 1.65; margin-bottom: 1.2rem; }
.pdp-meta { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1.25rem; }
.pdp-meta-tag {
  display: flex; align-items: center; gap: .35rem;
  background: <?= $isLight ? 'rgba(0,0,0,.04)' : 'rgba(255,255,255,.05)' ?>;
  border: 1px solid var(--border); border-radius: 20px;
  padding: .3rem .75rem; font-size: .7rem; font-weight: 600; color: var(--txt2);
}
.pdp-meta-tag i { color: var(--acc); }
.pdp-qty-row { display: flex; align-items: center; gap: .85rem; margin-bottom: 1.25rem; }
.pdp-qty-lbl { font-size: .75rem; font-weight: 700; color: var(--txt2); text-transform: uppercase; letter-spacing: .4px; }
.pdp-qty-ctrl { display: flex; align-items: center; gap: .5rem; }
.pdp-qty-btn {
  width: 36px; height: 36px; border-radius: 10px;
  border: 1.5px solid var(--border);
  background: <?= $isLight ? 'rgba(0,0,0,.04)' : 'rgba(255,255,255,.06)' ?>;
  color: var(--txt); font-size: 1.1rem; cursor: pointer;
  display: flex; align-items: center; justify-content: center; font-weight: 700;
  transition: all .15s;
}
.pdp-qty-btn:hover { background: color-mix(in srgb,var(--acc) 22%,transparent); border-color: var(--border2); color: var(--acc); }
.pdp-qty-num { font-size: 1.1rem; font-weight: 900; min-width: 32px; text-align: center; color: <?= $isLight ? '#0f172a' : '#fff' ?>; }
.pdp-add-btn {
  width: 100%; padding: .95rem; border-radius: 14px;
  background: linear-gradient(135deg, var(--acc), var(--acc2));
  border: none; color: #fff; font-size: .95rem; font-weight: 800;
  cursor: pointer; transition: all .2s;
  display: flex; align-items: center; justify-content: center; gap: .55rem;
  box-shadow: 0 6px 22px color-mix(in srgb, var(--acc) 35%, transparent);
  font-family: var(--font);
}
.pdp-add-btn:hover { transform: translateY(-1px); box-shadow: 0 10px 30px color-mix(in srgb, var(--acc) 45%, transparent); }

/* ═══════════════ CART DRAWER ═══════════════ */
.cart-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.6);
  backdrop-filter: blur(6px); z-index: 500;
  opacity: 0; pointer-events: none; transition: opacity .28s;
}
.cart-overlay.open { opacity: 1; pointer-events: auto; }
.cart-drawer {
  position: fixed; top: 0; right: 0; bottom: 0;
  width: min(var(--cart-w), 100vw);
  background: linear-gradient(180deg, <?= $t['card'] ?> 0%, <?= $t['bg'] ?> 100%);
  border-left: 1px solid var(--border);
  z-index: 501; display: flex; flex-direction: column;
  transform: translateX(110%); transition: transform .34s cubic-bezier(.22,.68,0,1.2);
  box-shadow: -16px 0 56px rgba(0,0,0,.45);
}
.cart-drawer.open { transform: translateX(0); }
.cart-head {
  padding: 1.2rem 1.4rem; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: .75rem; flex-shrink: 0;
}
.cart-head-icon { font-size: 1.2rem; color: var(--acc); }
.cart-head-title { flex:1; font-size: 1.05rem; font-weight: 900; color: <?= $isLight ? '#0f172a' : '#fff' ?>; }
.cart-head-count {
  background: var(--acc); color: #fff;
  border-radius: 20px; padding: .18rem .6rem;
  font-size: .7rem; font-weight: 800;
}
.cart-close {
  background: <?= $isLight ? 'rgba(0,0,0,.06)' : 'rgba(255,255,255,.07)' ?>;
  border: 1px solid var(--border); color: var(--txt2);
  width: 34px; height: 34px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 1rem; transition: all .18s; flex-shrink: 0;
}
.cart-close:hover { background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.3); color: #f87171; }

/* Free delivery progress */
.cart-progress-wrap { padding: .85rem 1.4rem .6rem; border-bottom: 1px solid var(--border); flex-shrink: 0; }
.cart-progress-label { font-size: .7rem; font-weight: 700; color: var(--txt2); margin-bottom: .45rem; display: flex; align-items: center; gap: .35rem; }
.cart-progress-label i { color: #34d399; }
.cart-progress-bar { height: 5px; background: rgba(128,128,128,.15); border-radius: 3px; overflow: hidden; }
.cart-progress-fill { height: 100%; background: linear-gradient(90deg,#34d399,#10b981); border-radius: 3px; transition: width .3s; }

.cart-items { flex: 1; overflow-y: auto; padding: .85rem 1.4rem; }
.cart-items::-webkit-scrollbar { width: 4px; }
.cart-items::-webkit-scrollbar-thumb { background: rgba(128,128,128,.15); border-radius: 4px; }
.cart-empty { text-align: center; padding: 3.5rem 1rem; color: var(--txt2); }
.cart-empty i { font-size: 3.5rem; display: block; margin-bottom: 1rem; opacity: .14; }
.cart-empty p { font-size: .85rem; line-height: 1.55; }

.ci {
  display: flex; gap: .8rem; padding: .8rem 0;
  border-bottom: 1px solid var(--border); align-items: center;
}
.ci:last-child { border-bottom: none; }
.ci-img {
  width: 56px; height: 56px; border-radius: 10px; overflow: hidden; flex-shrink: 0;
  background: color-mix(in srgb,var(--acc) 8%, <?= $isLight ? '#f8fafc' : 'var(--card)' ?>);
  display: flex; align-items: center; justify-content: center;
  border: 1px solid var(--border);
}
.ci-img img { width:100%;height:100%;object-fit:cover; }
.ci-img-ph { font-size: 1.4rem; opacity: .35; }
.ci-info { flex:1; min-width:0; }
.ci-name { font-size: .83rem; font-weight: 700; color: <?= $isLight ? '#0f172a' : '#fff' ?>; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ci-unit-price { font-size: .7rem; color: var(--txt2); margin-top: .1rem; font-weight: 600; }
.ci-price { font-size: .88rem; color: var(--acc2); font-weight: 900; margin-top: .18rem; }
.ci-qty { display: flex; align-items: center; gap: .35rem; flex-shrink: 0; }
.ci-btn {
  width: 28px; height: 28px; border-radius: 8px;
  border: 1px solid var(--border);
  background: <?= $isLight ? 'rgba(0,0,0,.05)' : 'rgba(255,255,255,.06)' ?>;
  color: var(--txt); font-size: .9rem; cursor: pointer;
  display: flex; align-items: center; justify-content: center; font-weight: 800; transition: all .15s;
}
.ci-btn:hover { background: color-mix(in srgb,var(--acc) 20%,transparent); border-color: var(--border2); }
.ci-btn.del:hover { background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.3); color: #f87171; }
.ci-n { font-size: .85rem; font-weight: 900; min-width: 22px; text-align: center; color: <?= $isLight ? '#0f172a' : '#fff' ?>; }

.cart-foot { padding: 1.1rem 1.4rem 1.25rem; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: .7rem; flex-shrink: 0; }
.cart-summary-rows { display: flex; flex-direction: column; gap: .3rem; }
.cart-sum-row { display: flex; justify-content: space-between; align-items: center; font-size: .8rem; color: var(--txt2); }
.cart-sum-row.total { font-size: 1.2rem; font-weight: 900; color: <?= $isLight ? '#0f172a' : '#fff' ?>; padding-top: .4rem; border-top: 1px solid var(--border); margin-top: .15rem; }
.cart-sum-row.total span:last-child { color: var(--acc2); }
.btn-checkout {
  width: 100%; padding: .95rem; border-radius: 14px;
  background: linear-gradient(135deg, var(--acc), var(--acc2));
  border: none; color: #fff; font-size: .95rem; font-weight: 800;
  cursor: pointer; transition: all .2s;
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  box-shadow: 0 6px 22px color-mix(in srgb, var(--acc) 38%, transparent);
  font-family: var(--font); letter-spacing: .2px;
}
.btn-checkout:hover { transform: translateY(-1px); box-shadow: 0 10px 30px color-mix(in srgb, var(--acc) 48%, transparent); }
.btn-continue-shop {
  width: 100%; padding: .6rem; background: none; border: none;
  color: var(--txt2); font-size: .78rem; cursor: pointer; font-family: var(--font);
  transition: color .18s; font-weight: 600;
}
.btn-continue-shop:hover { color: var(--acc); }

/* ═══════════════ ORDER MODAL (BOTTOM SHEET) ═══════════════ */
.order-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.75);
  backdrop-filter: blur(8px); z-index: 600;
  display: flex; align-items: flex-end; justify-content: center;
  opacity: 0; pointer-events: none; transition: opacity .25s;
}
.order-overlay.open { opacity: 1; pointer-events: auto; }
.order-sheet {
  width: 100%; max-width: 600px;
  background: <?= $t['card'] ?>;
  border: 1px solid var(--border); border-bottom: none;
  border-radius: 24px 24px 0 0; padding: 1.5rem 1.5rem 2rem;
  transform: translateY(60px); transition: transform .34s cubic-bezier(.22,.68,0,1.2);
  max-height: 92vh; overflow-y: auto;
}
.order-sheet::-webkit-scrollbar { width: 4px; }
.order-sheet::-webkit-scrollbar-thumb { background: rgba(128,128,128,.15); border-radius: 4px; }
.order-overlay.open .order-sheet { transform: translateY(0); }
.sheet-handle { width: 36px; height: 4px; background: rgba(128,128,128,.2); border-radius: 2px; margin: 0 auto 1.4rem; }
.sheet-title { font-size: 1.1rem; font-weight: 900; color: <?= $isLight ? '#0f172a' : '#fff' ?>; margin-bottom: 1.35rem; display: flex; align-items: center; gap: .55rem; }
.sheet-title i { color: var(--acc); }

/* Order form */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; }
.form-field { margin-bottom: .8rem; }
.form-lbl { font-size: .68rem; font-weight: 800; color: var(--txt2); margin-bottom: .35rem; display: block; text-transform: uppercase; letter-spacing: .5px; }
.form-inp {
  width: 100%; padding: .72rem .95rem;
  background: <?= $isLight ? 'rgba(0,0,0,.03)' : 'rgba(255,255,255,.04)' ?>;
  border: 1.5px solid var(--border); border-radius: 11px;
  color: var(--txt); font-size: .875rem; outline: none;
  font-family: var(--font); transition: all .18s;
}
.form-inp::placeholder { color: var(--txt3); }
.form-inp:focus { border-color: var(--border2); box-shadow: 0 0 0 3px color-mix(in srgb,var(--acc) 10%,transparent); }

/* Order summary mini */
.order-summary-box {
  background: <?= $isLight ? 'rgba(0,0,0,.03)' : 'rgba(255,255,255,.03)' ?>;
  border: 1px solid var(--border); border-radius: 13px;
  padding: .9rem 1rem; margin-bottom: 1.1rem;
}
.osm-row { display: flex; justify-content: space-between; font-size: .78rem; color: var(--txt2); padding: .18rem 0; }
.osm-total { font-size: .95rem; font-weight: 900; color: <?= $isLight ? '#0f172a' : '#fff' ?>; border-top: 1px solid var(--border); margin-top: .4rem; padding-top: .5rem; }
.osm-total span:last-child { color: var(--acc2); }

/* Payment options */
.pay-opts { display: flex; gap: .6rem; flex-wrap: wrap; margin-bottom: .5rem; }
.pay-opt {
  flex: 1; min-width: 110px; padding: .7rem; border-radius: 12px;
  border: 1.5px solid var(--border);
  background: <?= $isLight ? 'rgba(0,0,0,.02)' : 'rgba(255,255,255,.03)' ?>;
  cursor: pointer; transition: all .18s; text-align: center;
}
.pay-opt:hover { border-color: var(--border2); }
.pay-opt.selected { border-color: var(--acc); background: color-mix(in srgb, var(--acc) 10%, transparent); }
.pay-opt i { font-size: 1.3rem; display: block; margin-bottom: .2rem; }
.pay-opt span { font-size: .68rem; font-weight: 700; color: var(--txt2); }
.pay-opt.selected span { color: var(--acc2); }

.btn-wa {
  width: 100%; padding: .9rem; border-radius: 13px;
  background: linear-gradient(135deg,#22c55e,#16a34a);
  border: none; color: #fff; font-size: .9rem; font-weight: 800;
  cursor: pointer; display: flex; align-items: center; justify-content: center; gap: .6rem;
  transition: all .2s; font-family: var(--font);
  box-shadow: 0 6px 22px rgba(34,197,94,.32); margin-bottom: .5rem;
}
.btn-wa:hover { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(34,197,94,.42); }
.btn-db {
  width: 100%; padding: .78rem; border-radius: 13px;
  background: color-mix(in srgb,var(--acc) 14%,<?= $isLight ? 'rgba(0,0,0,.03)' : 'rgba(255,255,255,.04)' ?>);
  border: 1.5px solid var(--border2); color: var(--acc2);
  font-size: .85rem; font-weight: 700; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  transition: all .2s; font-family: var(--font);
}
.btn-db:hover { background: color-mix(in srgb,var(--acc) 22%,transparent); }

/* ═══════════════ SUCCESS SCREEN ═══════════════ */
.success-screen { text-align: center; padding: 2.5rem 1rem; display: none; flex-direction: column; align-items: center; }
.success-screen.show { display: flex; }
.success-icon-wrap {
  width: 82px; height: 82px; border-radius: 50%;
  background: linear-gradient(135deg,#22c55e,#4ade80);
  display: flex; align-items: center; justify-content: center;
  font-size: 2.2rem; margin-bottom: 1.1rem;
  box-shadow: 0 8px 28px rgba(34,197,94,.35);
  animation: successPop .4s cubic-bezier(.22,.68,0,1.5) both;
}
@keyframes successPop { 0%{transform:scale(.4);opacity:0} 100%{transform:scale(1);opacity:1} }
.success-title { font-size: 1.4rem; font-weight: 900; color: <?= $isLight ? '#0f172a' : '#fff' ?>; margin-bottom: .4rem; }
.success-sub { font-size: .85rem; color: var(--txt2); margin-bottom: 1.2rem; line-height: 1.55; }
.order-badge {
  background: <?= $isLight ? 'rgba(0,0,0,.04)' : 'rgba(255,255,255,.06)' ?>;
  border: 1px solid var(--border);
  border-radius: 11px; padding: .65rem 1.4rem;
  font-size: .9rem; font-weight: 800; color: var(--acc2);
  font-family: monospace; letter-spacing: .6px; margin-bottom: 1.5rem;
}

/* ═══════════════ TOAST ═══════════════ */
.s-toast {
  position: fixed; bottom: 1.5rem; left: 50%;
  transform: translateX(-50%) translateY(90px);
  background: <?= $isLight ? '#fff' : 'rgba(20,20,40,.95)' ?>;
  border: 1px solid var(--border2);
  border-radius: 14px; padding: .65rem 1.2rem;
  display: flex; align-items: center; gap: .55rem;
  font-size: .82rem; font-weight: 600; color: var(--txt);
  z-index: 9999; transition: transform .3s cubic-bezier(.22,.68,0,1.2), opacity .3s;
  opacity: 0; white-space: nowrap;
  box-shadow: 0 10px 28px rgba(0,0,0,<?= $isLight ? '.14' : '.5' ?>);
  backdrop-filter: blur(12px);
}
.s-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }

/* ═══════════════ FOOTER ═══════════════ */
.s-footer {
  border-top: 1px solid var(--border); padding: 3rem 1.5rem 2rem;
  margin-top: 2rem;
  background: color-mix(in srgb, var(--bg) 97%, transparent);
}
.s-footer-inner { max-width: 1280px; margin: 0 auto; }
.s-footer-grid {
  display: grid; grid-template-columns: 2fr repeat(3, 1fr);
  gap: 2.5rem; margin-bottom: 2rem;
}
.s-footer-logo-wrap {
  width: 44px; height: 44px; border-radius: 13px;
  background: linear-gradient(135deg, var(--acc), var(--acc2));
  display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem; font-weight: 900; color: #fff;
  margin-bottom: .85rem; overflow: hidden;
}
.s-footer-logo-wrap img { width:100%;height:100%;object-fit:cover;border-radius:13px; }
.s-footer-brand-name { font-size: 1rem; font-weight: 900; color: <?= $isLight ? '#0f172a' : '#fff' ?>; margin-bottom: .3rem; }
.s-footer-brand-desc { font-size: .78rem; color: var(--txt2); line-height: 1.6; max-width: 240px; margin-bottom: 1rem; }
.s-footer-social { display: flex; gap: .5rem; }
.soc-btn {
  width: 36px; height: 36px; border-radius: 10px;
  background: <?= $isLight ? 'rgba(0,0,0,.05)' : 'rgba(255,255,255,.06)' ?>;
  border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  color: var(--txt2); font-size: 1rem; text-decoration: none; transition: all .18s;
}
.soc-btn:hover { background: color-mix(in srgb,var(--acc) 15%,transparent); border-color: var(--border2); color: var(--acc); }
.s-footer-col-title { font-size: .65rem; font-weight: 900; color: var(--acc2); text-transform: uppercase; letter-spacing: .9px; margin-bottom: .8rem; }
.s-footer-link {
  display: flex; align-items: center; gap: .4rem;
  font-size: .8rem; color: var(--txt2); text-decoration: none;
  margin-bottom: .45rem; transition: color .18s; cursor: pointer;
}
.s-footer-link:hover { color: var(--acc); }
.s-footer-link i { font-size: .88rem; width: 18px; opacity: .7; }
.s-footer-divider { border: none; border-top: 1px solid var(--border); margin: 0 0 1.25rem; }
.s-footer-bottom {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: .5rem;
  font-size: .72rem; color: var(--txt3);
}
.s-footer-bottom a { color: var(--acc2); text-decoration: none; }
.s-footer-trust { display: flex; gap: 1.2rem; flex-wrap: wrap; }
.s-footer-trust-item { display: flex; align-items: center; gap: .35rem; font-size: .72rem; color: var(--txt3); }
.s-footer-trust-item i { color: var(--acc); font-size: .8rem; }

/* ═══════════════ NOT FOUND / COMING SOON ═══════════════ */
.nf-wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; text-align:center; padding:2rem; background:var(--bg); }
.nf-box { max-width:420px; }
.nf-icon { font-size:4.5rem; opacity:.1; display:block; margin-bottom:1.25rem; color:var(--txt); }
.nf-title { font-size:1.6rem; font-weight:900; color:<?= $isLight ? '#0f172a' : 'rgba(255,255,255,.85)' ?>; margin-bottom:.5rem; }
.nf-text { font-size:.9rem; color:var(--txt2); margin-bottom:1.5rem; line-height:1.6; }
.nf-btn {
  display:inline-flex; align-items:center; gap:.4rem;
  border:1.5px solid var(--border); border-radius:12px;
  padding:.65rem 1.5rem; color:var(--txt2); text-decoration:none;
  font-size:.85rem; font-weight:600; transition:all .2s;
}
.nf-btn:hover { border-color:var(--border2); color:var(--acc); }

/* ═══════════════ PREVIEW BADGE ═══════════════ */
.preview-badge {
  position:fixed; bottom:1rem; right:1rem;
  background:rgba(99,102,241,.9); color:#fff;
  border-radius:20px; padding:.35rem .9rem;
  font-size:.68rem; font-weight:800; z-index:9998;
  backdrop-filter:blur(8px); pointer-events:none;
  font-family:'Inter',sans-serif;
}

/* ═══════════════ RESPONSIVE ═══════════════ */
@media (max-width: 900px) {
  .s-footer-grid { grid-template-columns: 1fr 1fr; gap: 2rem; }
}
@media (max-width: 768px) {
  :root { --cart-w: 100vw; }
  .s-hero { padding: 3.5rem 1.25rem 3rem; }
  .hero-stats { gap: 1rem; }
  .hero-stat-num { font-size: 1.3rem; }
  .s-grid { grid-template-columns: repeat(2, 1fr) !important; gap: .7rem; }
  .cat-grid { grid-template-columns: repeat(3, 1fr); gap: .65rem; }
  .s-main { padding: 1.25rem 1rem 7rem; }
  .s-section { padding: 2rem 1rem 0; }
  .form-grid { grid-template-columns: 1fr; gap: .6rem; }
  .s-footer-grid { grid-template-columns: 1fr 1fr; gap: 1.5rem; }
}
@media (max-width: 480px) {
  .cat-grid { grid-template-columns: repeat(2, 1fr); }
  .s-footer-grid { grid-template-columns: 1fr; gap: 1.25rem; }
  .s-nav { padding: 0 1rem; }
  .hero-title { font-size: 1.75rem; }
}
</style>
</head>
<body>

<?php if ($notFound): ?>
<div class="nf-wrap">
  <div class="nf-box">
    <i class="bi bi-shop-window nf-icon"></i>
    <div class="nf-title">Store Not Found</div>
    <p class="nf-text">No store found with this URL. It may have been renamed or not yet launched.</p>
    <a href="<?= BASE_URL ?>/landing.php" class="nf-btn"><i class="bi bi-arrow-left"></i> Back to Stockora</a>
  </div>
</div>

<?php elseif ($notLaunched): ?>
<div class="nf-wrap">
  <div class="nf-box">
    <i class="bi bi-rocket-takeoff nf-icon"></i>
    <div class="nf-title">Coming Soon</div>
    <p class="nf-text"><strong style="color:<?= $isLight?'#0f172a':'#fff' ?>"><?= htmlspecialchars($storeName) ?></strong> is setting up their store. Please check back soon!</p>
    <a href="<?= BASE_URL ?>/landing.php" class="nf-btn"><i class="bi bi-arrow-left"></i> Back to Stockora</a>
  </div>
</div>

<?php else: ?>

<header class="store-header">
<?php /* ──── ANNOUNCEMENT BAR ──── */
if ($sectionEnabled('announcement') && $storeAnnOn && $storeAnn): ?>
<div class="ann-bar" role="complementary">
  <i class="bi bi-megaphone-fill" style="margin-right:.45rem;opacity:.8"></i>
  <?= htmlspecialchars($storeAnn) ?>
</div>
<?php endif; ?>

<!-- ═══════════════ NAVBAR ═══════════════ -->
<nav class="s-nav" id="sNav" role="navigation">
  <div class="s-nav-logo" onclick="scrollToTop()" role="img" aria-label="<?= htmlspecialchars($storeName) ?>">
    <?php if ($shopLogo): ?>
      <img src="<?= htmlspecialchars($shopLogo) ?>" alt="Logo">
    <?php else: ?>
      <?= mb_strtoupper(mb_substr($storeName,0,1)) ?>
    <?php endif; ?>
  </div>
  <div class="s-nav-brand" onclick="scrollToTop()">
    <div class="s-nav-name"><?= htmlspecialchars($storeName) ?></div>
    <div class="s-nav-sub">
      <?php
      $navSubParts = array_filter([
          $storeCat !== 'General Store' ? $storeCat : null,
          $storeCity ?: null
      ]);
      echo htmlspecialchars(implode(' · ', $navSubParts) ?: $storeCat);
      ?>
    </div>
  </div>
  <div class="s-nav-right">
    <div class="nav-pill" style="gap:.3rem;padding:.35rem .65rem">
      <div class="live-dot"></div>
      <span>Live</span>
    </div>
    <?php if ($storeWa || $storePhone): ?>
    <a href="https://wa.me/<?= $waNumRaw ?>" target="_blank" class="nav-icon-btn wa" title="WhatsApp Us" rel="noopener noreferrer">
      <i class="bi bi-whatsapp"></i>
    </a>
    <?php endif; ?>
    <button class="nav-icon-btn" onclick="openCart()" aria-label="Open cart" id="cartNavBtn">
      <i class="bi bi-bag-fill"></i>
      <span class="cart-badge" id="cartBadge">0</span>
    </button>
  </div>
</nav>
</header>

<!-- ═══════════════ HERO SECTION ═══════════════ -->
<?php if ($sectionEnabled('hero') && $storeHero !== 'none'): ?>
<?php if ($storeHero === 'minimal'): ?>
<div class="s-hero-minimal">
  <div>
    <div class="hero-eyebrow">
      <div class="live-dot"></div>
      <?= htmlspecialchars($storeCat) ?>
    </div>
    <h1 class="hero-title" style="font-size:clamp(1.6rem,4vw,2.6rem)"><?= htmlspecialchars($storeName) ?></h1>
    <?php if ($storeTagline): ?><p class="hero-tagline" style="text-align:left;margin-left:0"><?= htmlspecialchars($storeTagline) ?></p><?php endif; ?>
    <div style="display:flex;gap:.65rem;flex-wrap:wrap;margin-top:.85rem">
      <?php if ($storeCity): ?>
      <div class="trust-item"><i class="bi bi-geo-alt-fill"></i><?= htmlspecialchars($storeCity) ?></div>
      <?php endif; ?>
      <div class="trust-item"><i class="bi bi-box-seam-fill"></i><?= $totalProducts ?> Products</div>
      <div class="trust-item"><i class="bi bi-grid-3x3-gap-fill"></i><?= $totalCats ?> Categories</div>
    </div>
  </div>
  <button class="hero-btn-primary" onclick="document.getElementById('s-shop')?.scrollIntoView({behavior:'smooth'})">
    <i class="bi bi-bag-fill"></i> Shop Now
  </button>
</div>
<?php else: ?>
<section class="s-hero" id="s-hero">
  <!-- Floating particles -->
  <div class="hero-particles">
    <?php for ($pi=0; $pi<12; $pi++): $left=rand(5,95); $dur=rand(8,20); $delay=rand(0,12); $size=rand(2,5); ?>
    <div class="hero-particle" style="left:<?=$left?>%;width:<?=$size?>px;height:<?=$size?>px;animation-duration:<?=$dur?>s;animation-delay:<?=-$delay?>s;bottom:<?=rand(0,40)?>%"></div>
    <?php endfor; ?>
  </div>
  <div class="hero-inner">
    <div class="hero-eyebrow">
      <div class="live-dot"></div>
      <?= htmlspecialchars($storeCat ?: 'Online Store') ?>
      <?php if ($storeCity): ?> · <?= htmlspecialchars($storeCity) ?><?php endif; ?>
    </div>
    <h1 class="hero-title">
      <?php
        $parts = explode(' ', $storeName, 2);
        echo htmlspecialchars($parts[0]);
        if (!empty($parts[1])) echo ' <span>'.htmlspecialchars($parts[1]).'</span>';
      ?>
    </h1>
    <?php if ($storeTagline): ?><p class="hero-tagline"><?= htmlspecialchars($storeTagline) ?></p><?php endif; ?>

    <!-- Stats row -->
    <div class="hero-stats">
      <div class="hero-stat">
        <div class="hero-stat-num"><?= $totalProducts ?>+</div>
        <div class="hero-stat-lbl">Products</div>
      </div>
      <div class="hero-stat-div"></div>
      <div class="hero-stat">
        <div class="hero-stat-num"><?= $totalCats ?>+</div>
        <div class="hero-stat-lbl">Categories</div>
      </div>
      <?php if ($storeFreeMin > 0): ?>
      <div class="hero-stat-div"></div>
      <div class="hero-stat">
        <div class="hero-stat-num" style="font-size:1rem;font-weight:800;color:var(--acc)">Free</div>
        <div class="hero-stat-lbl">Delivery Available</div>
      </div>
      <?php endif; ?>
    </div>

    <div class="hero-actions">
      <button class="hero-btn-primary" onclick="document.getElementById('s-shop')?.scrollIntoView({behavior:'smooth'})">
        <i class="bi bi-bag-fill"></i> Shop Collection
      </button>
      <?php if ($storeWa || $storePhone): ?>
      <a href="https://wa.me/<?= $waNumRaw ?>" target="_blank" class="hero-btn-secondary" rel="noopener noreferrer">
        <i class="bi bi-whatsapp"></i> WhatsApp Us
      </a>
      <?php endif; ?>
    </div>

    <div class="hero-trust">
      <div class="hero-trust-item"><i class="bi bi-shield-fill-check"></i> Secure Ordering</div>
      <div class="hero-trust-item"><i class="bi bi-truck"></i> Fast Delivery</div>
      <div class="hero-trust-item"><i class="bi bi-cash-coin"></i> Cash on Delivery</div>
      <?php if ($storeFreeMin > 0): ?>
      <div class="hero-trust-item"><i class="bi bi-gift-fill" style="color:#34d399"></i> Free delivery over <?= htmlspecialchars($storeCurrency) ?> <?= number_format($storeFreeMin) ?></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>

<!-- ═══════════════ TRUST BAR ═══════════════ -->
<div class="trust-bar">
  <div class="trust-inner">
    <div class="trust-item"><i class="bi bi-truck"></i> Fast Delivery</div>
    <div class="trust-item"><i class="bi bi-shield-fill-check"></i> 100% Authentic</div>
    <div class="trust-item"><i class="bi bi-cash-coin"></i> Cash on Delivery</div>
    <div class="trust-item"><i class="bi bi-arrow-counterclockwise"></i> Easy Returns</div>
    <div class="trust-item"><i class="bi bi-headset"></i> Customer Support</div>
  </div>
</div>

<!-- ═══════════════ CATEGORIES SHOWCASE ═══════════════ -->
<?php if ($sectionEnabled('categories') && $storeShowCats && count($catList) > 0): ?>
<div class="s-section">
  <div class="s-section-head">
    <div class="s-section-title-wrap">
      <div class="s-section-eyebrow">Browse</div>
      <div class="s-section-title">Shop by Category</div>
    </div>
    <button class="s-section-view-all" onclick="filterCat('all',document.querySelector('.cat-pill'))">
      All Products <i class="bi bi-arrow-right"></i>
    </button>
  </div>
  <div class="cat-grid">
    <?php
    $catIcons = ['Electronics'=>'💻','Mobile'=>'📱','Clothes'=>'👕','Food'=>'🍔','Grocery'=>'🛒','Beauty'=>'💄','Health'=>'💊','Sports'=>'⚽','Books'=>'📚','Toys'=>'🧸','Furniture'=>'🛋️','Shoes'=>'👟','Bags'=>'👜','Watches'=>'⌚','Jewelry'=>'💍','Home'=>'🏠','Kitchen'=>'🍳','Baby'=>'🍼','Pets'=>'🐾','Auto'=>'🚗','Garden'=>'🌱','Stationery'=>'✏️','General'=>'🛍️'];
    foreach ($catList as $cid => $catData):
      $cname  = $catData['name'];
      $ccount = count($catData['products']);
      // find matching icon
      $icon = '🛍️';
      foreach ($catIcons as $k => $v) { if (stripos($cname,$k)!==false){ $icon=$v; break; } }
    ?>
    <div class="cat-card" onclick="filterCatById(<?= (int)$cid ?>, '<?= addslashes(htmlspecialchars($cname)) ?>')">
      <div class="cat-card-bg"></div>
      <div class="cat-card-icon"><?= $icon ?></div>
      <div class="cat-card-name"><?= htmlspecialchars($cname) ?></div>
      <div class="cat-card-count"><?= $ccount ?> item<?= $ccount!==1?'s':'' ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════ FEATURED PRODUCTS ═══════════════ -->
<?php if ($sectionEnabled('featured') && $storeShowFeatured && count($featuredProducts) > 0): ?>
<div class="s-section">
  <div class="s-section-head">
    <div class="s-section-title-wrap">
      <div class="s-section-eyebrow">Hand Picked</div>
      <div class="s-section-title"><i class="bi bi-fire" style="color:var(--acc)"></i> Featured Products</div>
    </div>
    <button class="s-section-view-all" onclick="document.getElementById('s-shop')?.scrollIntoView({behavior:'smooth'})">
      View All <i class="bi bi-arrow-right"></i>
    </button>
  </div>
  <div class="feat-scroll-wrap">
    <div class="feat-scroll">
      <?php foreach ($featuredProducts as $p):
        $pImg  = !empty($p['image']) ? BASE_URL . '/assets/uploads/'.htmlspecialchars($p['image']) : null;
        $pName = htmlspecialchars($p['name']);
        $pPrice= number_format((float)$p['retail_price'], 0);
        $pCat  = htmlspecialchars($p['cat_name'] ?? '');
        $pId   = (int)$p['id'];
      ?>
      <div class="feat-card"
        data-id="<?=$pId?>"
        data-name="<?= htmlspecialchars($p['name']) ?>"
        data-price="<?=(float)$p['retail_price']?>"
        data-img="<?= $pImg ? htmlspecialchars($pImg) : '' ?>"
        data-cat="<?= htmlspecialchars($p['cat_name']??'') ?>"
        data-desc="<?= htmlspecialchars($p['description']??'') ?>"
        data-unit="<?= htmlspecialchars($p['unit']??'pcs') ?>"
        data-stock="<?= (int)$p['stock_quantity'] ?>"
        onclick="openPDPFromEl(this)">
        <div class="feat-card-img">
          <?php if ($pImg): ?>
            <img src="<?=$pImg?>" alt="<?=$pName?>" loading="lazy">
          <?php else: ?>
            <span class="feat-card-img-ph">🛒</span>
          <?php endif; ?>
          <?php if ($pCat): ?><div class="feat-card-badge"><?=$pCat?></div><?php endif; ?>
        </div>
        <div class="feat-card-body">
          <?php if ($pCat): ?><div class="feat-card-cat"><?=$pCat?></div><?php endif; ?>
          <div class="feat-card-name"><?=$pName?></div>
          <div class="feat-card-footer">
            <div class="feat-card-price"><?= htmlspecialchars($storeCurrency) ?> <?=$pPrice?></div>
            <button class="feat-add-btn" onclick="event.stopPropagation();addToCartFromEl(this.closest('.feat-card'))" aria-label="Add to cart">
              <i class="bi bi-plus-lg"></i>
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════ SHOP SECTION — SEARCH + FILTER + GRID ═══════════════ -->
<div id="s-shop">
  <!-- Sticky toolbar -->
  <div class="s-shop-bar" id="shopBar">
    <div class="s-shop-bar-inner">
      <?php if ($storeShowSearch): ?>
      <div class="s-search-wrap">
        <i class="bi bi-search s-search-ico"></i>
        <input type="search" class="s-search" id="sSearch" placeholder="Search products..." oninput="doSearch(this.value)" autocomplete="off" aria-label="Search products">
        <button class="s-search-clear" id="searchClear" onclick="clearSearch()" aria-label="Clear search">✕</button>
      </div>
      <?php endif; ?>
      <select class="sort-select" onchange="sortGrid(this.value)" aria-label="Sort products">
        <option value="">Sort: Default</option>
        <option value="price_asc">Price: Low → High</option>
        <option value="price_desc">Price: High → Low</option>
        <option value="name_asc">Name: A → Z</option>
      </select>
    </div>
    <?php if ($storeShowCats && count($categories) > 0): ?>
    <div class="s-shop-bar-inner" style="margin-top:.55rem">
      <div class="cat-pills-row" id="catPillsRow">
        <button class="cat-pill active" onclick="filterCat('all',this)">All Products</button>
        <?php foreach ($categories as $cid => $cname): ?>
        <button class="cat-pill" id="catpill-<?= $cid ?>" onclick="filterCat('<?= $cid ?>',this)"><?= htmlspecialchars($cname) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Product grid -->
  <div class="s-main">
    <div class="s-grid-header">
      <div class="s-grid-count" id="gridCount"><?= $totalProducts ?> product<?= $totalProducts!==1?'s':'' ?></div>
    </div>
    <div class="s-grid" id="productGrid">
      <?php foreach ($products as $p):
        $pId   = (int)$p['id'];
        $pName = htmlspecialchars($p['name']);
        $pImg  = !empty($p['image']) ? BASE_URL . '/assets/uploads/'.htmlspecialchars($p['image']) : null;
        $pPrice= number_format((float)$p['retail_price'], 0);
        $pCat  = htmlspecialchars($p['cat_name'] ?? '');
        $pCatId= (int)($p['cat_id'] ?? 0);
        $pDesc = htmlspecialchars(mb_strimwidth($p['description']??'',0,80,'…'));
        $pUnit = htmlspecialchars($p['unit']??'pcs');
      ?>
      <article class="p-card"
        id="pcard-<?=$pId?>"
        data-id="<?=$pId?>"
        data-name="<?= htmlspecialchars($p['name']) ?>"
        data-price="<?=(float)$p['retail_price']?>"
        data-img="<?= $pImg ? htmlspecialchars($pImg) : '' ?>"
        data-cat="<?=$pCatId?>"
        data-cat-name="<?=$pCat?>"
        data-cat-name2="<?= htmlspecialchars($p['cat_name']??'') ?>"
        data-desc="<?= htmlspecialchars($p['description']??'') ?>"
        data-unit="<?= htmlspecialchars($p['unit']??'pcs') ?>"
        data-stock="<?= (int)$p['stock_quantity'] ?>"
        onclick="openPDPFromEl(this)"
      >
        <div class="p-card-img">
          <?php if ($pImg): ?>
            <img src="<?=$pImg?>" alt="<?=$pName?>" loading="lazy">
          <?php else: ?>
            <span class="p-card-img-ph">🛒</span>
          <?php endif; ?>
          <?php if ($pCat): ?><div class="p-card-badge"><?=$pCat?></div><?php endif; ?>
          <button class="p-card-quick" onclick="event.stopPropagation();openPDPFromEl(this.closest('.p-card'))">
            <i class="bi bi-eye-fill"></i> Quick View
          </button>
        </div>
        <div class="p-card-body">
          <?php if ($pCat): ?><div class="p-card-cat"><?=$pCat?></div><?php endif; ?>
          <div class="p-card-name"><?=$pName?></div>
          <div class="p-card-footer">
            <div class="p-price">
              <sub><?= htmlspecialchars($storeCurrency) ?></sub>
              <?=$pPrice?>
            </div>
            <button class="p-add" onclick="event.stopPropagation();addToCartFromEl(this.closest('.p-card'))" aria-label="Add to cart">
              <i class="bi bi-plus-lg"></i>
            </button>
            <div class="p-qty-ctrl">
              <button class="p-qty-btn" onclick="event.stopPropagation();chQty(<?=$pId?>,-1)" aria-label="Decrease quantity">−</button>
              <span class="p-qty-num" id="qnum-<?=$pId?>">1</span>
              <button class="p-qty-btn" onclick="event.stopPropagation();chQty(<?=$pId?>,1)" aria-label="Increase quantity">+</button>
            </div>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
      <?php if (empty($products)): ?>
      <div class="grid-empty">
        <i class="bi bi-box-seam"></i>
        <div class="grid-empty-title">No Products Yet</div>
        <p style="font-size:.82rem">This store is adding products soon. Check back later!</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════════ FOOTER ═══════════════ -->
<footer class="s-footer" role="contentinfo">
  <div class="s-footer-inner">
    <div class="s-footer-grid">
      <!-- Brand col -->
      <div>
        <div class="s-footer-logo-wrap">
          <?php if ($shopLogo): ?><img src="<?= htmlspecialchars($shopLogo) ?>" alt="Logo"><?php else: ?><?= mb_strtoupper(mb_substr($storeName,0,1)) ?><?php endif; ?>
        </div>
        <div class="s-footer-brand-name"><?= htmlspecialchars($storeName) ?></div>
        <?php if ($storeTagline): ?>
        <div class="s-footer-brand-desc"><?= htmlspecialchars($storeTagline) ?></div>
        <?php elseif ($storeDesc): ?>
        <div class="s-footer-brand-desc"><?= htmlspecialchars(mb_strimwidth($storeDesc,0,130,'…')) ?></div>
        <?php endif; ?>
        <div class="s-footer-social">
          <?php if ($storeFacebook): ?><a href="<?= htmlspecialchars($storeFacebook) ?>" target="_blank" class="soc-btn" rel="noopener"><i class="bi bi-facebook"></i></a><?php endif; ?>
          <?php if ($storeInstagram): ?><a href="<?= htmlspecialchars($storeInstagram) ?>" target="_blank" class="soc-btn" rel="noopener"><i class="bi bi-instagram"></i></a><?php endif; ?>
          <?php if ($storeTiktok): ?><a href="<?= htmlspecialchars($storeTiktok) ?>" target="_blank" class="soc-btn" rel="noopener"><i class="bi bi-tiktok"></i></a><?php endif; ?>
          <?php if ($storeWa || $storePhone): ?><a href="https://wa.me/<?= $waNumRaw ?>" target="_blank" class="soc-btn" rel="noopener"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
        </div>
      </div>
      <!-- Shop col -->
      <div>
        <div class="s-footer-col-title">Quick Shop</div>
        <div class="s-footer-link" onclick="document.getElementById('s-shop')?.scrollIntoView({behavior:'smooth'})"><i class="bi bi-grid-3x3-gap"></i> All Products</div>
        <?php foreach (array_slice($catList,0,5,true) as $cid=>$cd): ?>
        <div class="s-footer-link" onclick="filterCatById(<?=(int)$cid?>, '<?=addslashes(htmlspecialchars($cd['name']))?>')">
          <i class="bi bi-tag"></i> <?= htmlspecialchars($cd['name']) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <!-- Info col -->
      <div>
        <div class="s-footer-col-title">Information</div>
        <?php if ($storeAddress || $storeCity): ?>
        <div class="s-footer-link"><i class="bi bi-geo-alt"></i><?= htmlspecialchars(trim($storeAddress.' '.$storeCity)) ?></div>
        <?php endif; ?>
        <?php if ($storePhone): ?>
        <a href="tel:<?= htmlspecialchars($storePhone) ?>" class="s-footer-link"><i class="bi bi-telephone"></i><?= htmlspecialchars($storePhone) ?></a>
        <?php endif; ?>
        <?php if ($storeWa): ?>
        <a href="https://wa.me/<?= $waNumRaw ?>" target="_blank" class="s-footer-link"><i class="bi bi-whatsapp"></i>WhatsApp</a>
        <?php endif; ?>
      </div>
      <!-- Policies col -->
      <div>
        <div class="s-footer-col-title">Policies</div>
        <div class="s-footer-link"><i class="bi bi-truck"></i> Fast Delivery</div>
        <div class="s-footer-link"><i class="bi bi-cash-coin"></i> Cash on Delivery</div>
        <?php if ($storeFreeMin > 0): ?>
        <div class="s-footer-link" style="color:#34d399"><i class="bi bi-gift-fill"></i> Free del. over <?= htmlspecialchars($storeCurrency).' '.number_format($storeFreeMin) ?></div>
        <?php endif; ?>
        <div class="s-footer-link"><i class="bi bi-shield-check"></i> Secure Orders</div>
        <div class="s-footer-link"><i class="bi bi-arrow-counterclockwise"></i> Easy Returns</div>
      </div>
    </div>
    <hr class="s-footer-divider">
    <div class="s-footer-bottom">
      <span><?= htmlspecialchars($storeFooterTxt ?: '© '.date('Y').' '.htmlspecialchars($storeName).'. All rights reserved.') ?></span>
      <div class="s-footer-trust">
        <span class="s-footer-trust-item"><i class="bi bi-lock-fill"></i> Secure</span>
        <span class="s-footer-trust-item"><i class="bi bi-check-circle-fill"></i> Verified</span>
        <span style="opacity:.5">Powered by <a href="/landing.php">Stockora</a></span>
      </div>
    </div>
  </div>
</footer>

<!-- ═══════════════ PRODUCT DETAIL PANEL ═══════════════ -->
<div class="pdp-overlay" id="pdpOverlay" onclick="if(event.target===this)closePDP()">
  <div class="pdp-sheet" role="dialog" aria-modal="true">
    <div class="pdp-handle"></div>
    <div class="pdp-head">
      <button class="pdp-close" onclick="closePDP()" aria-label="Close product detail"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="pdp-img-wrap" id="pdpImgWrap">
      <span class="pdp-img-ph">🛒</span>
    </div>
    <div class="pdp-body">
      <div class="pdp-cat" id="pdpCat"></div>
      <div class="pdp-name" id="pdpName"></div>
      <div class="pdp-price-row">
        <div class="pdp-price" id="pdpPrice"></div>
        <div class="pdp-price-sub" id="pdpUnit"></div>
      </div>
      <div class="pdp-desc" id="pdpDesc"></div>
      <div class="pdp-meta" id="pdpMeta"></div>
      <div class="pdp-qty-row">
        <div class="pdp-qty-lbl">Quantity</div>
        <div class="pdp-qty-ctrl">
          <button class="pdp-qty-btn" onclick="pdpQtyChange(-1)" aria-label="Decrease">−</button>
          <span class="pdp-qty-num" id="pdpQtyNum">1</span>
          <button class="pdp-qty-btn" onclick="pdpQtyChange(1)" aria-label="Increase">+</button>
        </div>
      </div>
      <button class="pdp-add-btn" id="pdpAddBtn" onclick="pdpAddToCart()">
        <i class="bi bi-bag-plus-fill"></i> Add to Cart
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════ CART DRAWER ═══════════════ -->
<div class="cart-overlay" id="cartOverlay" onclick="closeCart()"></div>
<aside class="cart-drawer" id="cartDrawer" role="dialog" aria-modal="true" aria-label="Shopping cart">
  <div class="cart-head">
    <i class="bi bi-bag-fill cart-head-icon"></i>
    <div class="cart-head-title">Your Cart</div>
    <span class="cart-head-count" id="cartHeadCount">0</span>
    <button class="cart-close" onclick="closeCart()" aria-label="Close cart"><i class="bi bi-x-lg"></i></button>
  </div>

  <?php if ($storeFreeMin > 0): ?>
  <div class="cart-progress-wrap" id="cartProgressWrap" style="display:none">
    <div class="cart-progress-label"><i class="bi bi-truck-front-fill"></i><span id="cartProgressLbl">Add <?= htmlspecialchars($storeCurrency) ?> <?= number_format($storeFreeMin) ?> more for free delivery!</span></div>
    <div class="cart-progress-bar"><div class="cart-progress-fill" id="cartProgressFill" style="width:0%"></div></div>
  </div>
  <?php endif; ?>

  <div class="cart-items" id="cartItemsEl">
    <div class="cart-empty">
      <i class="bi bi-bag"></i>
      <p>Your cart is empty.<br>Tap any product to view details and add to cart!</p>
    </div>
  </div>

  <div class="cart-foot" id="cartFoot" style="display:none">
    <div class="cart-summary-rows">
      <div class="cart-sum-row">
        <span>Subtotal</span>
        <span id="cartSubtotalVal"><?= htmlspecialchars($storeCurrency) ?> 0</span>
      </div>
      <div class="cart-sum-row">
        <span>Delivery</span>
        <span id="cartDeliveryVal" style="color:#34d399">Free</span>
      </div>
      <div class="cart-sum-row total">
        <span>Total</span>
        <span id="cartTotalVal"><?= htmlspecialchars($storeCurrency) ?> 0</span>
      </div>
    </div>
    <button class="btn-checkout" onclick="openOrder()">
      <i class="bi bi-lock-fill"></i> Secure Checkout
    </button>
    <button class="btn-continue-shop" onclick="closeCart()">← Continue Shopping</button>
  </div>
</aside>

<!-- ═══════════════ ORDER BOTTOM SHEET ═══════════════ -->
<div class="order-overlay" id="orderOverlay">
  <div class="order-sheet" onclick="event.stopPropagation()">
    <div class="sheet-handle"></div>

    <div id="orderFormWrap">
      <div class="sheet-title"><i class="bi bi-clipboard-check-fill"></i> Complete Your Order</div>

      <!-- Order summary -->
      <div class="order-summary-box" id="orderSummaryMini"></div>

      <!-- Customer form -->
      <div class="form-grid">
        <div class="form-field">
          <label class="form-lbl" for="custName">Full Name *</label>
          <input type="text" class="form-inp" id="custName" placeholder="Ahmed Ali" required autocomplete="name">
        </div>
        <div class="form-field">
          <label class="form-lbl" for="custPhone">Phone Number *</label>
          <input type="tel" class="form-inp" id="custPhone" placeholder="03XX-XXXXXXX" required autocomplete="tel">
        </div>
      </div>
      <div class="form-field">
        <label class="form-lbl" for="custAddr">Delivery Address</label>
        <input type="text" class="form-inp" id="custAddr" placeholder="House #, Street, Area, City" autocomplete="street-address">
      </div>
      <div class="form-field">
        <label class="form-lbl" for="custNote">Special Instructions</label>
        <input type="text" class="form-inp" id="custNote" placeholder="Any special requests...">
      </div>

      <!-- Payment method -->
      <div class="form-field">
        <label class="form-lbl">Payment Method</label>
        <div class="pay-opts">
          <div class="pay-opt selected" onclick="selectPay('cod',this)" id="pay-cod">
            <i class="bi bi-cash-coin" style="color:#f59e0b"></i>
            <span>Cash on Delivery</span>
          </div>
          <div class="pay-opt" onclick="selectPay('easypaisa',this)" id="pay-easypaisa">
            <i class="bi bi-phone-fill" style="color:#22c55e"></i>
            <span>EasyPaisa</span>
          </div>
          <div class="pay-opt" onclick="selectPay('bank',this)" id="pay-bank">
            <i class="bi bi-bank2" style="color:#60a5fa"></i>
            <span>Bank Transfer</span>
          </div>
        </div>
      </div>

      <!-- Action buttons -->
      <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:.25rem">
        <?php if ($waNumRaw): ?>
        <button class="btn-wa" id="btnWA" onclick="placeOrderWA()">
          <i class="bi bi-whatsapp"></i> Order via WhatsApp
        </button>
        <?php endif; ?>
        <button class="btn-db" id="btnDB" onclick="placeOrderDB()">
          <i class="bi bi-send-fill"></i> Send Order to Store
        </button>
      </div>
      <button onclick="closeOrder()" style="width:100%;margin-top:.5rem;background:none;border:none;color:var(--txt2);font-size:.78rem;cursor:pointer;padding:.4rem;font-family:var(--font)">← Back to Cart</button>
    </div>

    <div class="success-screen" id="orderSuccess">
      <div class="success-icon-wrap">✓</div>
      <div class="success-title">Order Placed! 🎉</div>
      <p class="success-sub">Your order has been received.<br>The store will contact you very soon.</p>
      <div class="order-badge" id="successOrderNum">ORD-XXXXX</div>
      <?php if ($waNumRaw): ?>
      <a id="successWaBtn" href="#" target="_blank" class="btn-wa" style="text-decoration:none;display:flex;margin-bottom:.5rem">
        <i class="bi bi-whatsapp"></i> Chat with Store on WhatsApp
      </a>
      <?php endif; ?>
      <button onclick="closeAllModals()" class="btn-db">
        <i class="bi bi-arrow-left"></i> Continue Shopping
      </button>
    </div>
  </div>
</div>

<!-- Toast notification -->
<div class="s-toast" id="sToast" role="alert">
  <i class="bi bi-check-circle-fill" style="color:#34d399"></i>
  <span id="sToastMsg">Added to cart</span>
</div>

<?php if ($isPreview): ?>
<div class="preview-badge">⚡ Preview Mode</div>
<?php endif; ?>

<script>
/* ════════════════════════════════════════════════════════
   STOCKORA STORE v4.0 — Cart · PDP · Orders · Filters · UX
   ════════════════════════════════════════════════════════ */
var CART      = {};
var PAY       = 'cod';
var SLUG      = '<?= addslashes($slug) ?>';
var WA        = '<?= $waNumRaw ?>';
var SHOP_NM   = '<?= addslashes(htmlspecialchars($storeName)) ?>';
var CURRENCY  = '<?= addslashes(htmlspecialchars($storeCurrency)) ?>';
var FREE_MIN  = <?= $storeFreeMin ?>;
var _pdpId    = null;
var _pdpQty   = 1;
var _pdpPrice = 0;
var _pdpName  = '';
var _pdpImg   = null;

/* ── Stock map: id → available quantity ── */
var STOCKS = {};
(function(){
  document.querySelectorAll('[data-id][data-stock]').forEach(function(el){
    var id = parseInt(el.dataset.id);
    var st = parseInt(el.dataset.stock) || 0;
    if (!STOCKS[id] || STOCKS[id] < st) STOCKS[id] = st;
  });
})();

/* ─── dataset helpers — safe alternative to onclick with json_encode ─── */
function openPDPFromEl(el) {
  var d = el.dataset;
  openPDP(
    parseInt(d.id),
    d.name     || '',
    parseFloat(d.price) || 0,
    d.img      || null,
    d.cat      || d.catName2 || '',
    d.desc     || '',
    d.unit     || 'pcs'
  );
}
function addToCartFromEl(el) {
  var d = el.dataset;
  addToCart(parseInt(d.id), d.name||'', parseFloat(d.price)||0, d.img||null, 1);
}

/* ─── Navbar scroll shadow ─── */
window.addEventListener('scroll', function(){
  var nav = document.getElementById('sNav');
  if (nav) nav.classList.toggle('scrolled', window.scrollY > 10);
});
function scrollToTop(){ window.scrollTo({top:0,behavior:'smooth'}); }

/* ════ PRODUCT DETAIL PANEL ════ */
function openPDP(id, name, price, img, cat, desc, unit) {
  _pdpId    = parseInt(id);
  _pdpQty   = 1;
  _pdpPrice = parseFloat(price);
  _pdpName  = name;
  _pdpImg   = img;

  // Populate
  document.getElementById('pdpCat').textContent   = cat || '';
  document.getElementById('pdpName').textContent  = name;
  document.getElementById('pdpPrice').textContent = CURRENCY + ' ' + Math.round(price).toLocaleString();
  document.getElementById('pdpUnit').textContent  = unit ? 'per ' + unit : '';
  document.getElementById('pdpDesc').textContent  = desc || '';
  document.getElementById('pdpQtyNum').textContent = 1;

  // Image
  var imgWrap = document.getElementById('pdpImgWrap');
  if (img) {
    imgWrap.innerHTML = '<img src="' + escHtml(img) + '" alt="' + escHtml(name) + '" style="width:100%;height:100%;object-fit:cover">';
  } else {
    imgWrap.innerHTML = '<span class="pdp-img-ph">🛒</span>';
  }

  // Meta tags
  var meta = '';
  if (cat)  meta += '<div class="pdp-meta-tag"><i class="bi bi-tag-fill"></i>' + escHtml(cat) + '</div>';
  if (unit) meta += '<div class="pdp-meta-tag"><i class="bi bi-box-seam"></i>Per ' + escHtml(unit) + '</div>';
  meta += '<div class="pdp-meta-tag"><i class="bi bi-truck-front"></i>Delivery Available</div>';
  meta += '<div class="pdp-meta-tag"><i class="bi bi-cash-coin"></i>Cash on Delivery</div>';
  document.getElementById('pdpMeta').innerHTML = meta;

  // Update button if already in cart
  if (CART[_pdpId]) {
    _pdpQty = CART[_pdpId].qty;
    document.getElementById('pdpQtyNum').textContent = _pdpQty;
    document.getElementById('pdpAddBtn').innerHTML = '<i class="bi bi-bag-check-fill"></i> Update Cart (' + _pdpQty + ')';
  } else {
    document.getElementById('pdpAddBtn').innerHTML = '<i class="bi bi-bag-plus-fill"></i> Add to Cart';
  }

  /* stock badge in PDP */
  var stock   = getStock(_pdpId);
  var already = CART[_pdpId] ? CART[_pdpId].qty : 0;
  var remaining = stock - already;
  var oldHint = document.getElementById('pdpStockHint');
  if (oldHint) oldHint.remove();
  /* show low stock warning if ≤ 10 left */
  if (stock <= 10 && stock > 0) {
    var hint = document.createElement('div');
    hint.id = 'pdpStockHint';
    hint.style.cssText = 'font-size:.75rem;font-weight:700;margin-top:.4rem;text-align:center;padding:.25rem .6rem;border-radius:8px;';
    if (remaining <= 0) {
      hint.style.color = '#ef4444';
      hint.style.background = 'rgba(239,68,68,.12)';
      hint.textContent = '✗ Cart full — max stock reached';
      document.getElementById('pdpAddBtn').disabled = true;
      document.getElementById('pdpAddBtn').style.opacity = '0.45';
    } else {
      hint.style.color = '#f59e0b';
      hint.style.background = 'rgba(245,158,11,.12)';
      hint.textContent = '⚠ صرف ' + remaining + ' باقی ہے';
      document.getElementById('pdpAddBtn').disabled = false;
      document.getElementById('pdpAddBtn').style.opacity = '';
    }
    document.getElementById('pdpQtyNum').parentNode.insertAdjacentElement('afterend', hint);
  } else if (remaining <= 0) {
    document.getElementById('pdpAddBtn').disabled = true;
    document.getElementById('pdpAddBtn').style.opacity = '0.45';
    var hint2 = document.createElement('div');
    hint2.id = 'pdpStockHint';
    hint2.style.cssText = 'font-size:.75rem;color:#ef4444;font-weight:700;margin-top:.4rem;text-align:center;background:rgba(239,68,68,.12);padding:.25rem .6rem;border-radius:8px;';
    hint2.textContent = '✗ Cart full — max stock reached';
    document.getElementById('pdpQtyNum').parentNode.insertAdjacentElement('afterend', hint2);
  } else {
    document.getElementById('pdpAddBtn').disabled = false;
    document.getElementById('pdpAddBtn').style.opacity = '';
  }

  document.getElementById('pdpOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closePDP() {
  document.getElementById('pdpOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
function pdpQtyChange(delta) {
  var stock   = getStock(_pdpId);
  var already = CART[_pdpId] ? CART[_pdpId].qty : 0;
  var newQty  = _pdpQty + delta;

  if (delta > 0 && (_pdpQty + already) >= stock) {
    stockWarn(_pdpName, stock - already > 0 ? stock - already : stock);
    return;
  }
  _pdpQty = Math.max(1, Math.min(newQty, stock));
  document.getElementById('pdpQtyNum').textContent = _pdpQty;

  /* show stock remaining hint */
  var already2 = CART[_pdpId] ? CART[_pdpId].qty : 0;
  var remaining = stock - already2;
  var hint = document.getElementById('pdpStockHint');
  if (_pdpQty >= remaining && remaining > 0) {
    if (!hint) {
      hint = document.createElement('div');
      hint.id = 'pdpStockHint';
      hint.style.cssText = 'font-size:.75rem;color:#f59e0b;font-weight:600;margin-top:.3rem;text-align:center;';
      document.getElementById('pdpQtyNum').parentNode.insertAdjacentElement('afterend', hint);
    }
    hint.textContent = 'صرف ' + remaining + ' باقی ہے';
  } else if (hint) {
    hint.remove();
  }

  document.getElementById('pdpAddBtn').innerHTML = _pdpQty > 1
    ? '<i class="bi bi-bag-plus-fill"></i> Add ' + _pdpQty + ' to Cart'
    : '<i class="bi bi-bag-plus-fill"></i> Add to Cart';
}
function pdpAddToCart() {
  if (!_pdpId) return;
  var stock   = getStock(_pdpId);
  var already = CART[_pdpId] ? CART[_pdpId].qty : 0;
  if (already >= stock) {
    stockWarn(_pdpName, stock);
    return;
  }
  addToCart(_pdpId, _pdpName, _pdpPrice, _pdpImg, _pdpQty);
  closePDP();
}

/* ════ CART LOGIC ════ */

/* helper: max stock for a product id */
function getStock(id) {
  return STOCKS[id] !== undefined ? STOCKS[id] : 9999;
}

/* helper: show stock-limit error toast */
function stockWarn(name, max) {
  toast('⚠ صرف ' + max + ' ' + (name ? '"'+name+'"' : '') + ' available hai cart mein!', 'warn');
}

/* helper: update the + button / qty display on product card */
function updateCardStockUI(id) {
  var stock = getStock(id);
  var inCart = CART[id] ? CART[id].qty : 0;
  var card = document.getElementById('pcard-'+id);
  if (!card) return;
  var incBtn = card.querySelector('.p-qty-btn:last-child');
  if (incBtn) {
    incBtn.disabled = (inCart >= stock);
    incBtn.style.opacity = (inCart >= stock) ? '0.35' : '';
    incBtn.title = (inCart >= stock) ? 'Stock limit reached' : '';
  }
  /* show stock badge if near limit */
  var badge = card.querySelector('.stock-left-badge');
  if (inCart > 0 && inCart >= stock) {
    if (!badge) {
      badge = document.createElement('div');
      badge.className = 'stock-left-badge';
      card.querySelector('.p-card-img').appendChild(badge);
    }
    badge.textContent = 'Max: ' + stock;
  } else if (badge) {
    badge.remove();
  }
}

function addToCart(id, name, price, img, qty) {
  id  = parseInt(id);
  qty = parseInt(qty) || 1;
  var stock   = getStock(id);
  var already = CART[id] ? CART[id].qty : 0;

  if (already >= stock) {
    stockWarn(name, stock);
    return;
  }
  /* clamp qty to remaining stock */
  var canAdd = Math.min(qty, stock - already);
  if (!CART[id]) {
    CART[id] = {id:id, name:name, price:parseFloat(price), qty:canAdd, img:img||null};
  } else {
    CART[id].qty += canAdd;
  }
  var card = document.getElementById('pcard-'+id);
  if (card) {
    card.classList.add('in-cart');
    var qn = document.getElementById('qnum-'+id);
    if (qn) qn.textContent = CART[id].qty;
  }
  updateCardStockUI(id);
  updateCartUI();
  if (canAdd < qty) {
    toast('⚠ صرف ' + canAdd + ' add hua — stock limit: ' + stock, 'warn');
  } else {
    toast('✓ Added: '+name, 'success');
  }
  /* Briefly flash cart button */
  var cb = document.getElementById('cartNavBtn');
  if (cb) { cb.style.transform='scale(1.2)'; setTimeout(function(){ cb.style.transform=''; },200); }
}

function chQty(id, delta) {
  id = parseInt(id);
  if (!CART[id]) return;
  var stock   = getStock(id);
  var newQty  = CART[id].qty + delta;

  if (delta > 0 && CART[id].qty >= stock) {
    stockWarn(CART[id].name, stock);
    return;
  }
  newQty = Math.max(0, Math.min(newQty, stock));
  CART[id].qty = newQty;
  if (CART[id].qty === 0) {
    delete CART[id];
    var card = document.getElementById('pcard-'+id);
    if (card) { card.classList.remove('in-cart'); var qn=document.getElementById('qnum-'+id); if(qn) qn.textContent=1; }
  } else {
    var qn = document.getElementById('qnum-'+id);
    if (qn) qn.textContent = CART[id].qty;
  }
  updateCardStockUI(id);
  updateCartUI();
}

function cartInc(id) {
  id = parseInt(id);
  if (!CART[id]) return;
  var stock = getStock(id);
  if (CART[id].qty >= stock) {
    stockWarn(CART[id].name, stock);
    return;
  }
  CART[id].qty++;
  updateCardStockUI(id);
  renderCartItems();
  updateCartUI();
}

function cartDec(id) {
  id = parseInt(id);
  if (!CART[id]) return;
  CART[id].qty--;
  if (CART[id].qty <= 0) {
    delete CART[id];
    var card = document.getElementById('pcard-'+id);
    if (card) { card.classList.remove('in-cart'); var qn=document.getElementById('qnum-'+id); if(qn) qn.textContent=1; }
  }
  updateCardStockUI(id);
  renderCartItems();
  updateCartUI();
}

function updateCartUI() {
  var total=0, count=0;
  Object.values(CART).forEach(function(i){ total+=i.price*i.qty; count+=i.qty; });

  // Navbar badge
  var badge = document.getElementById('cartBadge');
  if (badge) { badge.textContent=count; badge.classList.toggle('show', count>0); }
  // Cart head count
  var hc = document.getElementById('cartHeadCount');
  if (hc) hc.textContent = count;

  // Totals
  var stv = document.getElementById('cartSubtotalVal');
  if (stv) stv.textContent = CURRENCY+' '+Math.round(total).toLocaleString();
  var tv = document.getElementById('cartTotalVal');
  if (tv) tv.textContent = CURRENCY+' '+Math.round(total).toLocaleString();

  // Free delivery progress
  var pw = document.getElementById('cartProgressWrap');
  if (pw && FREE_MIN > 0) {
    pw.style.display = count > 0 ? '' : 'none';
    var pct = Math.min(100, (total / FREE_MIN) * 100);
    var pf = document.getElementById('cartProgressFill');
    if (pf) pf.style.width = pct + '%';
    var pl = document.getElementById('cartProgressLbl');
    if (pl) {
      if (total >= FREE_MIN) {
        pl.innerHTML = '<span style="color:#34d399">🎉 You\'ve unlocked FREE delivery!</span>';
      } else {
        var remain = Math.round(FREE_MIN - total);
        pl.textContent = 'Add '+CURRENCY+' '+remain.toLocaleString()+' more for free delivery!';
      }
    }
    // Delivery val
    var dv = document.getElementById('cartDeliveryVal');
    if (dv) { dv.textContent = total >= FREE_MIN ? 'FREE 🎉' : 'COD'; dv.style.color = total>=FREE_MIN ? '#34d399' : 'var(--txt2)'; }
  }

  var foot = document.getElementById('cartFoot');
  if (count > 0) {
    if (foot) foot.style.display = 'flex';
    renderCartItems();
  } else {
    if (foot) foot.style.display = 'none';
    var ci = document.getElementById('cartItemsEl');
    if (ci) ci.innerHTML = '<div class="cart-empty"><i class="bi bi-bag"></i><p>Your cart is empty.<br>Tap any product to add!</p></div>';
  }
}

function renderCartItems() {
  var el = document.getElementById('cartItemsEl');
  if (!el) return;
  var items = Object.values(CART);
  if (!items.length) return;
  el.innerHTML = items.map(function(i) {
    var stock    = getStock(i.id);
    var atMax    = i.qty >= stock;
    var imgHtml  = i.img
      ? '<img src="'+escHtml(i.img)+'" alt="'+escHtml(i.name)+'">'
      : '<span class="ci-img-ph">🛒</span>';
    var stockWarnHtml = atMax
      ? '<span class="ci-stock-warn">⚠ Max stock reached ('+stock+')</span>'
      : '';
    return '<div class="ci">'+
      '<div class="ci-img">'+imgHtml+'</div>'+
      '<div class="ci-info">'+
        '<div class="ci-name">'+escHtml(i.name)+'</div>'+
        '<div class="ci-unit-price">'+CURRENCY+' '+Math.round(i.price).toLocaleString()+' each</div>'+
        stockWarnHtml+
        '<div class="ci-price">'+CURRENCY+' '+Math.round(i.price*i.qty).toLocaleString()+'</div>'+
      '</div>'+
      '<div class="ci-qty">'+
        '<button class="ci-btn" onclick="cartDec('+i.id+')" aria-label="Remove one">−</button>'+
        '<span class="ci-n">'+i.qty+'</span>'+
        '<button class="ci-btn'+(atMax?' ci-btn-disabled':'')+'" onclick="cartInc('+i.id+')" aria-label="Add one"'+(atMax?' disabled style="opacity:.35;cursor:not-allowed"':'')+'>+</button>'+
      '</div>'+
    '</div>';
  }).join('');
}

function openCart() {
  document.getElementById('cartOverlay').classList.add('open');
  document.getElementById('cartDrawer').classList.add('open');
  document.body.style.overflow = 'hidden';
  renderCartItems();
}
function closeCart() {
  document.getElementById('cartOverlay').classList.remove('open');
  document.getElementById('cartDrawer').classList.remove('open');
  document.body.style.overflow = '';
}

/* ════ ORDER ════ */
function openOrder() {
  closeCart();
  buildOrderSummary();
  document.getElementById('orderFormWrap').style.display = 'block';
  document.getElementById('orderSuccess').classList.remove('show');
  document.getElementById('orderOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeOrder() {
  document.getElementById('orderOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
function closeAllModals() {
  closeOrder(); closeCart(); closePDP();
  CART = {};
  document.querySelectorAll('.p-card').forEach(function(c){ c.classList.remove('in-cart'); });
  document.querySelectorAll('.p-qty-num').forEach(function(q){ q.textContent='1'; });
  updateCartUI();
}
function buildOrderSummary() {
  var items = Object.values(CART);
  var total = items.reduce(function(s,i){ return s+i.price*i.qty; }, 0);
  var html = '';
  items.slice(0,4).forEach(function(i){
    html += '<div class="osm-row"><span>'+escHtml(i.name)+' ×'+i.qty+'</span><span>'+CURRENCY+' '+Math.round(i.price*i.qty).toLocaleString()+'</span></div>';
  });
  if (items.length > 4) html += '<div class="osm-row"><span style="opacity:.65">+' +(items.length-4)+' more items</span><span></span></div>';
  html += '<div class="osm-row osm-total"><span>Total</span><span>'+CURRENCY+' '+Math.round(total).toLocaleString()+'</span></div>';
  document.getElementById('orderSummaryMini').innerHTML = html;
}
function selectPay(val, el) {
  PAY = val;
  document.querySelectorAll('.pay-opt').forEach(function(e){ e.classList.remove('selected'); });
  el.classList.add('selected');
}

function placeOrderWA() {
  var name  = document.getElementById('custName').value.trim();
  var phone = document.getElementById('custPhone').value.trim();
  if (!name || !phone) { toast('⚠ Please enter name & phone', 'warn'); return; }
  var items = Object.values(CART);
  if (!items.length) { toast('Cart is empty', 'warn'); return; }
  var total = items.reduce(function(s,i){ return s+i.price*i.qty; },0);
  var addr  = document.getElementById('custAddr').value.trim();
  var note  = document.getElementById('custNote').value.trim();
  var msg = '🛍 *New Order — '+SHOP_NM+'*\n';
  msg += '━━━━━━━━━━━━━━━━\n';
  items.forEach(function(i){ msg += '• '+i.name+' × '+i.qty+' — *'+CURRENCY+' '+Math.round(i.price*i.qty).toLocaleString()+'*\n'; });
  msg += '━━━━━━━━━━━━━━━━\n';
  msg += '💰 *Total: '+CURRENCY+' '+Math.round(total).toLocaleString()+'*\n';
  msg += '💳 Payment: '+PAY.toUpperCase()+'\n';
  msg += '👤 Name: '+name+'\n📞 Phone: '+phone+'\n';
  if (addr) msg += '📍 Address: '+addr+'\n';
  if (note) msg += '📝 Note: '+note+'\n';
  window.open('https://wa.me/'+WA+'?text='+encodeURIComponent(msg), '_blank');
  placeOrderDB(true);
}

function placeOrderDB(silent) {
  var name  = document.getElementById('custName').value.trim();
  var phone = document.getElementById('custPhone').value.trim();
  if (!silent && (!name || !phone)) { toast('⚠ Name & phone required','warn'); return; }
  var items = Object.values(CART);
  if (!items.length) { toast('Cart is empty','warn'); return; }
  var btn = document.getElementById('btnDB');
  if (!silent && btn) { btn.innerHTML='<i class="bi bi-hourglass-split"></i> Sending...'; btn.disabled=true; }
  fetch(window.location.pathname+'?s='+encodeURIComponent(SLUG)+'&action=place_order', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      slug: SLUG, customer_name: name, customer_phone: phone,
      customer_address: document.getElementById('custAddr').value.trim(),
      customer_note: document.getElementById('custNote').value.trim(),
      payment_method: PAY,
      items: items.map(function(i){ return{id:i.id,name:i.name,price:i.price,qty:i.qty}; })
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if (!silent && btn) { btn.innerHTML='<i class="bi bi-send-fill"></i> Send Order to Store'; btn.disabled=false; }
    if (!silent) {
      if (d.success) {
        document.getElementById('successOrderNum').textContent = d.order_number;
        <?php if ($waNumRaw): ?>
        var swa = document.getElementById('successWaBtn');
        if (swa) swa.href='https://wa.me/<?= $waNumRaw ?>?text='+encodeURIComponent('Hi! I just placed order '+d.order_number+' for '+CURRENCY+' '+parseFloat(d.total).toLocaleString());
        <?php endif; ?>
        document.getElementById('orderFormWrap').style.display = 'none';
        document.getElementById('orderSuccess').classList.add('show');
        CART = {};
        document.querySelectorAll('.p-card').forEach(function(c){ c.classList.remove('in-cart'); });
        document.querySelectorAll('.p-qty-num').forEach(function(q){ q.textContent='1'; });
        updateCartUI();
      } else { toast('⚠ '+(d.message||'Error'),'warn'); }
    }
  })
  .catch(function(){
    if (!silent && btn) { btn.innerHTML='<i class="bi bi-send-fill"></i> Send Order to Store'; btn.disabled=false; }
    if (!silent) toast('⚠ Connection error','warn');
  });
}

/* ════ SEARCH ════ */
function doSearch(q) {
  q = q.toLowerCase().trim();
  var cl = document.getElementById('searchClear');
  if (cl) cl.style.display = q ? 'block' : 'none';
  var vis = 0;
  document.querySelectorAll('.p-card').forEach(function(c) {
    var nm  = (c.dataset.name || '').toLowerCase();
    var cat = (c.dataset.catName || '').toLowerCase();
    var show = !q || nm.includes(q) || cat.includes(q);
    c.style.display = show ? '' : 'none';
    if (show) vis++;
  });
  var gc = document.getElementById('gridCount');
  if (gc) gc.textContent = vis+' product'+(vis!==1?'s':'');
}
function clearSearch() {
  var inp = document.getElementById('sSearch'); if(inp) inp.value='';
  var cl  = document.getElementById('searchClear'); if(cl) cl.style.display='none';
  doSearch('');
}

/* ════ CATEGORY FILTER ════ */
var _curCat = 'all';
function filterCat(cat, el) {
  _curCat = cat;
  document.querySelectorAll('.cat-pill').forEach(function(p){ p.classList.remove('active'); });
  if (el) el.classList.add('active');
  var vis = 0;
  document.querySelectorAll('.p-card').forEach(function(c) {
    var show = cat==='all' || c.dataset.cat==cat;
    c.style.display = show ? '' : 'none';
    if (show) vis++;
  });
  var gc = document.getElementById('gridCount');
  if (gc) gc.textContent = vis+' product'+(vis!==1?'s':'');
  // Scroll to grid
  var shop = document.getElementById('s-shop');
  if (shop) shop.scrollIntoView({behavior:'smooth', block:'start'});
}
function filterCatById(catId, catName) {
  _curCat = catId.toString();
  document.querySelectorAll('.cat-pill').forEach(function(p){ p.classList.remove('active'); });
  var pill = document.getElementById('catpill-'+catId);
  if (pill) pill.classList.add('active');
  var vis = 0;
  document.querySelectorAll('.p-card').forEach(function(c) {
    var show = c.dataset.cat == catId;
    c.style.display = show ? '' : 'none';
    if (show) vis++;
  });
  var gc = document.getElementById('gridCount');
  if (gc) gc.textContent = vis+' product'+(vis!==1?'s':'');
  var shop = document.getElementById('s-shop');
  if (shop) shop.scrollIntoView({behavior:'smooth', block:'start'});
}

/* ════ SORT ════ */
function sortGrid(val) {
  var grid = document.getElementById('productGrid'); if(!grid) return;
  var cards = Array.from(grid.querySelectorAll('.p-card'));
  cards.sort(function(a,b) {
    if (val==='price_asc')  return parseFloat(a.dataset.price)-parseFloat(b.dataset.price);
    if (val==='price_desc') return parseFloat(b.dataset.price)-parseFloat(a.dataset.price);
    if (val==='name_asc')   return (a.dataset.name||'').localeCompare(b.dataset.name||'');
    return 0;
  });
  cards.forEach(function(c){ grid.appendChild(c); });
}

/* ════ TOAST ════ */
var _toastT;
function toast(msg, type) {
  var el = document.getElementById('sToast');
  var ic = el.querySelector('i');
  if (type==='warn') { ic.className='bi bi-exclamation-triangle-fill'; ic.style.color='#fbbf24'; }
  else { ic.className='bi bi-check-circle-fill'; ic.style.color='#34d399'; }
  document.getElementById('sToastMsg').textContent = msg;
  el.classList.add('show');
  clearTimeout(_toastT);
  _toastT = setTimeout(function(){ el.classList.remove('show'); }, 2600);
}

/* ════ UTIL ════ */
function escHtml(s) {
  var d=document.createElement('div');
  d.appendChild(document.createTextNode(String(s||'')));
  return d.innerHTML;
}

/* ── Close order overlay on backdrop click ── */
document.getElementById('orderOverlay').addEventListener('click',function(e){ if(e.target===this) closeOrder(); });

/* ── ESC key closes modals ── */
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') { closeCart(); closePDP(); closeOrder(); }
});
</script>

<?php endif; ?>
</body>
</html>

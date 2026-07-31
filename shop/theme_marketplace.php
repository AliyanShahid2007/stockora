<?php
require_once '../includes/functions.php';
requireShop();
requirePremiumFeature((int)$_SESSION['shop_id'], 'Commerce Cloud');
require_once '../includes/shop_layout.php';
$shopId    = (int)$_SESSION['shop_id'];
$shopName  = $_SESSION['shop_name'] ?? 'My Shop';
$pageTitle = 'Theme Marketplace';

/* ══════════════════════════════════════════════════════
   DB  &  SLUG
   ══════════════════════════════════════════════════════ */
$db = getDB();
$slugRow = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='store_slug' LIMIT 1");
$slugRow->execute([$shopId]);
$storeSlug = $slugRow->fetchColumn() ?: '';
$host = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'];
$storeUrl = BASE_URL . '/store.php?s=' . urlencode($storeSlug);

/* ══════════════════════════════════════════════════════
   AJAX HANDLERS
   ══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    ob_clean(); header('Content-Type: application/json');

    /* Install / Activate Theme */
    if ($_POST['action']==='save_theme') {
        $name = sanitize($_POST['theme_name'] ?? '');
        $id   = sanitize($_POST['theme_id']   ?? '');
        if ($name) {
            /* backup previous theme for rollback */
            $prev = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='store_theme'");
            $prev->execute([$shopId]);
            $prevTheme = $prev->fetchColumn() ?: '';
            if ($prevTheme && $prevTheme !== $name) {
                $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='store_theme_rollback'")->execute([$shopId]);
                $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'store_theme_rollback',$prevTheme]);
            }
            $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key IN('store_theme','store_theme_id')")->execute([$shopId]);
            $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'store_theme',$name]);
            if ($id) $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'store_theme_id',$id]);
            echo json_encode(['success'=>true,'theme'=>$name,'previous'=>$prevTheme]);
        } else { echo json_encode(['success'=>false]); }
        exit;
    }

    /* Rollback to previous theme */
    if ($_POST['action']==='rollback_theme') {
        $prev = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='store_theme_rollback'");
        $prev->execute([$shopId]);
        $prevTheme = $prev->fetchColumn() ?: '';
        if ($prevTheme) {
            $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='store_theme'")->execute([$shopId]);
            $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'store_theme',$prevTheme]);
            echo json_encode(['success'=>true,'theme'=>$prevTheme]);
        } else { echo json_encode(['success'=>false,'message'=>'No previous theme found']); }
        exit;
    }

    /* Toggle Favourite */
    if ($_POST['action']==='toggle_fav') {
        $tid = sanitize($_POST['theme_id'] ?? '');
        if ($tid) {
            $r = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='theme_favourites'");
            $r->execute([$shopId]);
            $favs = json_decode($r->fetchColumn() ?: '[]', true) ?: [];
            $isFav = in_array($tid,$favs);
            if ($isFav) $favs = array_values(array_filter($favs,fn($f)=>$f!==$tid));
            else        $favs[] = $tid;
            $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='theme_favourites'")->execute([$shopId]);
            $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'theme_favourites',json_encode($favs)]);
            echo json_encode(['success'=>true,'favourited'=>!$isFav]);
        } else echo json_encode(['success'=>false]);
        exit;
    }

    /* ── Duplicate Theme ── */
    if ($_POST['action']==='duplicate_theme') {
        $name = sanitize($_POST['theme_name'] ?? '');
        $id   = sanitize($_POST['theme_id']   ?? '');
        if ($name) {
            // Load custom themes list
            $r = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='custom_themes'");
            $r->execute([$shopId]);
            $customThemes = json_decode($r->fetchColumn() ?: '[]', true) ?: [];
            // Load current theme settings
            $r2 = $db->prepare("SELECT setting_key,setting_value FROM settings WHERE shop_id=? AND setting_key LIKE 'store_%'");
            $r2->execute([$shopId]);
            $themeSettings = [];
            foreach ($r2->fetchAll(PDO::FETCH_ASSOC) as $row) $themeSettings[$row['setting_key']] = $row['setting_value'];
            $copyName = $name.' (Copy)';
            $newId    = $id.'-copy-'.time();
            $customThemes[] = [
                'id'       => $newId,
                'name'     => $copyName,
                'base'     => $name,
                'created'  => date('Y-m-d H:i:s'),
                'settings' => $themeSettings,
            ];
            $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='custom_themes'")->execute([$shopId]);
            $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'custom_themes',json_encode($customThemes)]);
            echo json_encode(['success'=>true,'name'=>$copyName,'id'=>$newId]);
        } else echo json_encode(['success'=>false]);
        exit;
    }

    /* ── Delete Custom Theme ── */
    if ($_POST['action']==='delete_theme') {
        $id = sanitize($_POST['theme_id'] ?? '');
        if ($id) {
            $r = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='custom_themes'");
            $r->execute([$shopId]);
            $customThemes = json_decode($r->fetchColumn() ?: '[]', true) ?: [];
            $customThemes = array_values(array_filter($customThemes, fn($t)=>$t['id']!==$id));
            $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='custom_themes'")->execute([$shopId]);
            $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'custom_themes',json_encode($customThemes)]);
            echo json_encode(['success'=>true]);
        } else echo json_encode(['success'=>false]);
        exit;
    }

    /* ── Export Theme ── */
    if ($_POST['action']==='export_theme') {
        $name = sanitize($_POST['theme_name'] ?? '');
        // Get all theme-related settings
        $r = $db->prepare("SELECT setting_key,setting_value FROM settings WHERE shop_id=? AND setting_key LIKE 'store_%'");
        $r->execute([$shopId]);
        $themeSettings = [];
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) $themeSettings[$row['setting_key']] = $row['setting_value'];
        // Version history entry
        $vh = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='theme_version_history'");
        $vh->execute([$shopId]);
        $history = json_decode($vh->fetchColumn() ?: '[]', true) ?: [];
        $exportData = [
            'stockora_theme_export' => true,
            'version'    => '1.0',
            'exported_at'=> date('Y-m-d H:i:s'),
            'theme_name' => $name ?: ($themeSettings['store_theme'] ?? 'Unknown'),
            'settings'   => $themeSettings,
            'version_history' => $history,
        ];
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success'=>true,'data'=>$exportData,'filename'=>'theme-'.preg_replace('/[^a-z0-9]+/','-',strtolower($exportData['theme_name'])).'-'.date('Ymd').'.json']);
        exit;
    }

    /* ── Import Theme ── */
    if ($_POST['action']==='import_theme') {
        $jsonData = $_POST['json_data'] ?? '';
        if ($jsonData) {
            $data = json_decode($jsonData, true);
            if ($data && !empty($data['stockora_theme_export']) && !empty($data['settings'])) {
                // Backup current before import
                $cur = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='store_theme'");
                $cur->execute([$shopId]);
                $curTheme = $cur->fetchColumn() ?: '';
                if ($curTheme) {
                    $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='store_theme_rollback'")->execute([$shopId]);
                    $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'store_theme_rollback',$curTheme]);
                }
                // Apply imported settings (only store_* keys)
                foreach ($data['settings'] as $key => $value) {
                    if (preg_match('/^store_[a-z_]+$/', $key)) {
                        $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key=?")->execute([$shopId,$key]);
                        $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,$key,$value]);
                    }
                }
                // Add to version history
                $vhRow = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='theme_version_history'");
                $vhRow->execute([$shopId]);
                $history = json_decode($vhRow->fetchColumn() ?: '[]', true) ?: [];
                array_unshift($history, ['theme'=>$data['theme_name'],'action'=>'imported','ts'=>date('Y-m-d H:i:s')]);
                $history = array_slice($history, 0, 50);
                $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='theme_version_history'")->execute([$shopId]);
                $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'theme_version_history',json_encode($history)]);
                echo json_encode(['success'=>true,'theme'=>$data['theme_name']]);
            } else echo json_encode(['success'=>false,'message'=>'Invalid theme file format']);
        } else echo json_encode(['success'=>false,'message'=>'No data received']);
        exit;
    }

    /* ── Theme Backup ── */
    if ($_POST['action']==='backup_theme') {
        $r = $db->prepare("SELECT setting_key,setting_value FROM settings WHERE shop_id=? AND setting_key LIKE 'store_%'");
        $r->execute([$shopId]);
        $themeSettings = [];
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) $themeSettings[$row['setting_key']] = $row['setting_value'];
        $backupsRow = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='theme_backups'");
        $backupsRow->execute([$shopId]);
        $backups = json_decode($backupsRow->fetchColumn() ?: '[]', true) ?: [];
        array_unshift($backups, [
            'id'        => uniqid('bk_'),
            'theme'     => $themeSettings['store_theme'] ?? 'Unknown',
            'created_at'=> date('Y-m-d H:i:s'),
            'settings'  => $themeSettings,
        ]);
        $backups = array_slice($backups, 0, 10); // Keep last 10 backups
        $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='theme_backups'")->execute([$shopId]);
        $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'theme_backups',json_encode($backups)]);
        echo json_encode(['success'=>true,'backup_count'=>count($backups),'theme'=>$themeSettings['store_theme']??'Unknown']);
        exit;
    }

    /* ── Restore from Backup ── */
    if ($_POST['action']==='restore_backup') {
        $bkId = sanitize($_POST['backup_id'] ?? '');
        $backupsRow = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='theme_backups'");
        $backupsRow->execute([$shopId]);
        $backups = json_decode($backupsRow->fetchColumn() ?: '[]', true) ?: [];
        $found = null;
        foreach ($backups as $bk) if ($bk['id']===$bkId) { $found=$bk; break; }
        if ($found && !empty($found['settings'])) {
            // Save current as rollback first
            $cur = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='store_theme'");
            $cur->execute([$shopId]);
            $curTheme = $cur->fetchColumn() ?: '';
            if ($curTheme) {
                $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='store_theme_rollback'")->execute([$shopId]);
                $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'store_theme_rollback',$curTheme]);
            }
            foreach ($found['settings'] as $key => $value) {
                if (preg_match('/^store_[a-z_]+$/', $key)) {
                    $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key=?")->execute([$shopId,$key]);
                    $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,$key,$value]);
                }
            }
            echo json_encode(['success'=>true,'theme'=>$found['theme']]);
        } else echo json_encode(['success'=>false,'message'=>'Backup not found']);
        exit;
    }

    /* ── Get Version History ── */
    if ($_POST['action']==='get_version_history') {
        $vhRow = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='theme_version_history'");
        $vhRow->execute([$shopId]);
        $history = json_decode($vhRow->fetchColumn() ?: '[]', true) ?: [];
        $backupsRow = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='theme_backups'");
        $backupsRow->execute([$shopId]);
        $backups = json_decode($backupsRow->fetchColumn() ?: '[]', true) ?: [];
        echo json_encode(['success'=>true,'history'=>$history,'backups'=>$backups]);
        exit;
    }

    /* ── Record theme switch in version history ── */
    if ($_POST['action']==='record_history') {
        $themeName = sanitize($_POST['theme_name'] ?? '');
        $actionType = sanitize($_POST['action_type'] ?? 'activated');
        if ($themeName) {
            $vhRow = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='theme_version_history'");
            $vhRow->execute([$shopId]);
            $history = json_decode($vhRow->fetchColumn() ?: '[]', true) ?: [];
            array_unshift($history, ['theme'=>$themeName,'action'=>$actionType,'ts'=>date('Y-m-d H:i:s')]);
            $history = array_slice($history, 0, 50);
            $db->prepare("DELETE FROM settings WHERE shop_id=? AND setting_key='theme_version_history'")->execute([$shopId]);
            $db->prepare("INSERT INTO settings (shop_id,setting_key,setting_value,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())")->execute([$shopId,'theme_version_history',json_encode($history)]);
            echo json_encode(['success'=>true]);
        } else echo json_encode(['success'=>false]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

/* ══════════════════════════════════════════════════════
   LOAD STATE
   ══════════════════════════════════════════════════════ */
$savedTheme   = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='store_theme'");
$savedTheme->execute([$shopId]); $savedTheme = $savedTheme->fetchColumn() ?: '';
$savedThemeId = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='store_theme_id'");
$savedThemeId->execute([$shopId]); $savedThemeId = $savedThemeId->fetchColumn() ?: '';
$rollbackTheme= $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='store_theme_rollback'");
$rollbackTheme->execute([$shopId]); $rollbackTheme= $rollbackTheme->fetchColumn() ?: '';
$favsRow      = $db->prepare("SELECT setting_value FROM settings WHERE shop_id=? AND setting_key='theme_favourites'");
$favsRow->execute([$shopId]); $favourites = json_decode($favsRow->fetchColumn() ?: '[]', true) ?: [];

/* ══════════════════════════════════════════════════════
   25+ ENTERPRISE THEMES  (every theme unique design lang)
   ══════════════════════════════════════════════════════ */
$themes = [

  /* ──── FASHION ──── */
  ['id'=>'luxury-fashion','name'=>'Luxury Fashion','category'=>'fashion','cat_label'=>'Fashion','emoji'=>'👗',
   'industry'=>'High-End Boutique','developer'=>'Stockora Studio','version'=>'3.2.0','downloads'=>'18.4K','last_updated'=>'Jul 2025',
   'description'=>'Ultra-premium monochrome luxury with gold accents, full-bleed editorial hero, lookbook grid and collection-first layout. For designer brands demanding the highest aesthetic.',
   'colors'=>['#1a0a2e','#2d1256','#C4B5FD','#F59E0B','#fff'],
   'badges'=>['responsive','seo','performance','dark_mode'],
   'tags'=>['Boutique','Editorial','Lookbook','Luxury'],
   'features'=>['Full-bleed hero','Lookbook grid','Wishlist','Size guide','Collection showcase'],
   'rating'=>4.9,'reviews'=>142,'popular'=>true,'ai_pick'=>false,'new'=>false,
   'accent'=>'#C4B5FD','bg'=>'linear-gradient(160deg,#0d0520 0%,#1a0a2e 100%)',
  ],
  ['id'=>'premium-boutique','name'=>'Premium Boutique','category'=>'fashion','cat_label'=>'Fashion','emoji'=>'👒',
   'industry'=>'Women\'s Fashion','developer'=>'Stockora Studio','version'=>'2.8.1','downloads'=>'9.7K','last_updated'=>'Jun 2025',
   'description'=>'Soft pastel palette with sophisticated serif typography and collection-focused layouts. Refined minimalism for curated women\'s fashion brands with editorial aspiration.',
   'colors'=>['#0f1724','#1e2a3a','#A78BFA','#F472B6','#fdf2f8'],
   'badges'=>['responsive','seo','accessibility'],
   'tags'=>['Boutique','Pastel','Minimal','Serif'],
   'features'=>['Collection grid','Style blog','Size guide','Outfit builder','Social proof'],
   'rating'=>4.7,'reviews'=>98,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#F472B6','bg'=>'linear-gradient(160deg,#0a0f1e 0%,#1e1535 100%)',
  ],
  ['id'=>'streetwear-hub','name'=>'Streetwear Hub','category'=>'fashion','cat_label'=>'Fashion','emoji'=>'👔',
   'industry'=>'Urban / Streetwear','developer'=>'Stockora Studio','version'=>'2.5.0','downloads'=>'7.2K','last_updated'=>'May 2025',
   'description'=>'High-energy urban streetwear aesthetic with gritty textures, bold oversized typography, animated product reveals and a dark concrete-inspired color system.',
   'colors'=>['#0a0a0a','#141414','#22D3EE','#F59E0B','#fff'],
   'badges'=>['responsive','performance','dark_mode'],
   'tags'=>['Urban','Bold','Streetwear','Grunge'],
   'features'=>['Quick view','Zoom gallery','Color/size filter','New arrivals','Drop calendar'],
   'rating'=>4.6,'reviews'=>87,'popular'=>false,'ai_pick'=>false,'new'=>true,
   'accent'=>'#22D3EE','bg'=>'linear-gradient(160deg,#050505 0%,#0a0a0a 100%)',
  ],

  /* ──── ELECTRONICS ──── */
  ['id'=>'tech-pro','name'=>'Tech Pro','category'=>'electronics','cat_label'=>'Electronics','emoji'=>'💻',
   'industry'=>'Tech & Gadgets','developer'=>'Stockora Studio','version'=>'4.1.0','downloads'=>'24.1K','last_updated'=>'Jul 2025',
   'description'=>'Spec-first electronics store with comparison tables, review integration, deal countdown timers and a deep navy tech-forward UI. Built for high-volume electronics catalogs.',
   'colors'=>['#1e3a5f','#0f2340','#06B6D4','#34D399','#e0f2fe'],
   'badges'=>['responsive','seo','performance','accessibility','dark_mode'],
   'tags'=>['Tech','Spec-heavy','Deals','Reviews'],
   'features'=>['Spec comparison','Deal timer','Review system','Tech categories','Advanced filter'],
   'rating'=>4.9,'reviews'=>203,'popular'=>true,'ai_pick'=>true,'new'=>false,
   'accent'=>'#06B6D4','bg'=>'linear-gradient(160deg,#071628 0%,#0f2340 100%)',
  ],
  ['id'=>'gadget-hub','name'=>'Gadget Hub','category'=>'electronics','cat_label'=>'Electronics','emoji'=>'📱',
   'industry'=>'Gadgets Marketplace','developer'=>'Stockora Studio','version'=>'3.0.2','downloads'=>'15.6K','last_updated'=>'Jun 2025',
   'description'=>'App-store inspired gadget marketplace with category bubbles, video demo embeds, flash deals ticker and a vibrant gradient-on-dark design system for modern gadget lovers.',
   'colors'=>['#0d1b2a','#1a2e44','#38BDF8','#818CF8','#e0f7fa'],
   'badges'=>['responsive','seo','performance'],
   'tags'=>['Gadgets','Marketplace','Deals','Modern'],
   'features'=>['Video demo embed','Compare up to 4','Flash deals ticker','Brand filter','Mega menu'],
   'rating'=>4.7,'reviews'=>156,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#38BDF8','bg'=>'linear-gradient(160deg,#06101c 0%,#0d1b2a 100%)',
  ],

  /* ──── FURNITURE ──── */
  ['id'=>'nordic-home','name'=>'Nordic Home','category'=>'furniture','cat_label'=>'Furniture','emoji'=>'🪑',
   'industry'=>'Home Décor','developer'=>'Stockora Studio','version'=>'3.3.0','downloads'=>'10.1K','last_updated'=>'Jun 2025',
   'description'=>'Scandinavian minimalism with warm birch tones, generous whitespace, room-scene product images and material selector overlays. Aspirational lifestyle-driven furniture browsing.',
   'colors'=>['#1a1510','#2a2018','#D4B896','#A3A99C','#faf7f4'],
   'badges'=>['responsive','seo','performance','dark_mode'],
   'tags'=>['Nordic','Minimal','Lifestyle','Warm'],
   'features'=>['Room visualizer','Material picker','Delivery estimate','Bundle builder','Wishlist'],
   'rating'=>4.7,'reviews'=>92,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#D4B896','bg'=>'linear-gradient(160deg,#100e08 0%,#1a1510 100%)',
  ],
  ['id'=>'interior-studio','name'=>'Interior Studio','category'=>'furniture','cat_label'=>'Furniture','emoji'=>'🛋️',
   'industry'=>'Luxury Interiors','developer'=>'Stockora Studio','version'=>'2.6.0','downloads'=>'7.3K','last_updated'=>'Apr 2025',
   'description'=>'Dark velvet luxury interior design store with portfolio showcase, bespoke order forms, consultation booking and a rich jewel-tone palette suited for premium furnishings.',
   'colors'=>['#0e0a18','#1c1428','#C084FC','#E879F9','#f5f0ff'],
   'badges'=>['responsive','seo','dark_mode'],
   'tags'=>['Luxury','Portfolio','Bespoke','Dark'],
   'features'=>['Portfolio gallery','Custom orders','3D room planner','Consultation','Mood board'],
   'rating'=>4.5,'reviews'=>65,'popular'=>false,'ai_pick'=>false,'new'=>true,
   'accent'=>'#C084FC','bg'=>'linear-gradient(160deg,#080612 0%,#0e0a18 100%)',
  ],

  /* ──── JEWELLERY ──── */
  ['id'=>'diamond-jewels','name'=>'Diamond Jewels','category'=>'jewellery','cat_label'=>'Jewellery','emoji'=>'💎',
   'industry'=>'Fine Jewellery','developer'=>'Stockora Studio','version'=>'3.0.0','downloads'=>'11.4K','last_updated'=>'Jul 2025',
   'description'=>'Black and gold luxury jewellery store with full-screen product photography, 360° ring viewer, certificate verification, metal/stone selector and an opulent dark aesthetic.',
   'colors'=>['#0a0800','#1a1400','#D4AF37','#F5E6A3','#fff9e6'],
   'badges'=>['responsive','seo','performance','dark_mode'],
   'tags'=>['Luxury','Gold','Fine Jewellery','360°'],
   'features'=>['360° ring viewer','Metal/stone selector','Certificate verify','Engraving option','Gift wrap'],
   'rating'=>4.9,'reviews'=>118,'popular'=>true,'ai_pick'=>false,'new'=>false,
   'accent'=>'#D4AF37','bg'=>'linear-gradient(160deg,#050400 0%,#0a0800 100%)',
  ],
  ['id'=>'silver-craft','name'=>'Silver Craft','category'=>'jewellery','cat_label'=>'Jewellery','emoji'=>'🪬',
   'industry'=>'Artisan Jewellery','developer'=>'Stockora Studio','version'=>'2.2.0','downloads'=>'5.6K','last_updated'=>'May 2025',
   'description'=>'Artisan silver jewellery with handcrafted story, workshop gallery, custom sizing tool and a cool blue-silver palette that captures the essence of handmade craftsmanship.',
   'colors'=>['#0a0e18','#141e2e','#94A3B8','#CBD5E1','#f1f5f9'],
   'badges'=>['responsive','seo','accessibility'],
   'tags'=>['Artisan','Handcrafted','Silver','Story'],
   'features'=>['Workshop gallery','Custom sizing','Handcrafted story','Gift messaging','Origin map'],
   'rating'=>4.6,'reviews'=>47,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#94A3B8','bg'=>'linear-gradient(160deg,#050810 0%,#0a0e18 100%)',
  ],

  /* ──── BEAUTY / COSMETICS ──── */
  ['id'=>'glow-beauty','name'=>'Glow Beauty','category'=>'beauty','cat_label'=>'Beauty','emoji'=>'💄',
   'industry'=>'Cosmetics & Skincare','developer'=>'Stockora Studio','version'=>'3.5.0','downloads'=>'19.8K','last_updated'=>'Jul 2025',
   'description'=>'Rose-gold luxury cosmetics with ingredient transparency, before/after gallery, skin-type quiz, shade finder and a sensual gradient-pink aesthetic that converts beautifully.',
   'colors'=>['#1a0820','#2d1035','#F9A8D4','#FB923C','#fff0f6'],
   'badges'=>['responsive','seo','performance','dark_mode'],
   'tags'=>['Cosmetics','Rose Gold','Skincare','Quiz'],
   'features'=>['Shade finder','Skin quiz','Before/after','Ingredient list','Subscriptions'],
   'rating'=>4.8,'reviews'=>189,'popular'=>true,'ai_pick'=>false,'new'=>false,
   'accent'=>'#F9A8D4','bg'=>'linear-gradient(160deg,#120615 0%,#1e0a25 100%)',
  ],
  ['id'=>'natural-wellness','name'=>'Natural Wellness','category'=>'beauty','cat_label'=>'Beauty','emoji'=>'🌿',
   'industry'=>'Organic & Wellness','developer'=>'Stockora Studio','version'=>'2.9.0','downloads'=>'11.2K','last_updated'=>'Jun 2025',
   'description'=>'Earthy botanical wellness brand with plant origin maps, clean ingredient labels, subscription boxes and a calming green palette that builds organic trust.',
   'colors'=>['#0c1c12','#142a1c','#6EE7B7','#FDE68A','#f0fdf4'],
   'badges'=>['responsive','seo','accessibility'],
   'tags'=>['Organic','Botanical','Wellness','Clean'],
   'features'=>['Ingredient transparency','Subscription box','Blog integration','Bundle deals','Origin map'],
   'rating'=>4.6,'reviews'=>103,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#6EE7B7','bg'=>'linear-gradient(160deg,#081208 0%,#0c1c12 100%)',
  ],

  /* ──── RESTAURANT / CAFE / BAKERY ──── */
  ['id'=>'bistro-dark','name'=>'Bistro Dark','category'=>'restaurant','cat_label'=>'Restaurant','emoji'=>'🍽️',
   'industry'=>'Fine Dining Restaurant','developer'=>'Stockora Studio','version'=>'2.8.0','downloads'=>'8.9K','last_updated'=>'Jun 2025',
   'description'=>'Fine dining restaurant store with rich dark walnut tones, reservation integration, chef specials section, menu PDF viewer and an atmosphere-first hero that sets the mood.',
   'colors'=>['#1a0f08','#2d1e10','#D97706','#FDE68A','#fffbeb'],
   'badges'=>['responsive','seo','performance'],
   'tags'=>['Restaurant','Fine Dining','Menu','Reservation'],
   'features'=>['Reservation system','Chef specials','Menu PDF','Table booking','Ambiance gallery'],
   'rating'=>4.7,'reviews'=>84,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#D97706','bg'=>'linear-gradient(160deg,#0e0805 0%,#1a0f08 100%)',
  ],
  ['id'=>'cafe-vibes','name'=>'Café Vibes','category'=>'cafe','cat_label'=>'Café','emoji'=>'☕',
   'industry'=>'Coffee Shop / Café','developer'=>'Stockora Studio','version'=>'2.4.0','downloads'=>'7.1K','last_updated'=>'May 2025',
   'description'=>'Warm coffee-culture aesthetic with bean origins story, seasonal drinks builder, loyalty stamp card, Instagram-worthy flat-lay gallery and a cosy dark roast color palette.',
   'colors'=>['#1c0e08','#2d1a10','#C2956C','#FEF3C7','#fffaf5'],
   'badges'=>['responsive','seo'],
   'tags'=>['Coffee','Cozy','Loyalty','Seasonal'],
   'features'=>['Bean origins','Drink builder','Loyalty stamps','Seasonal menu','Instagram feed'],
   'rating'=>4.6,'reviews'=>72,'popular'=>false,'ai_pick'=>false,'new'=>true,
   'accent'=>'#C2956C','bg'=>'linear-gradient(160deg,#100806 0%,#1c0e08 100%)',
  ],
  ['id'=>'sweet-bakery','name'=>'Sweet Bakery','category'=>'bakery','cat_label'=>'Bakery','emoji'=>'🧁',
   'industry'=>'Artisan Bakery / Sweets','developer'=>'Stockora Studio','version'=>'2.3.0','downloads'=>'6.4K','last_updated'=>'Apr 2025',
   'description'=>'Cheerful pastel bakery with custom cake order form, daily specials countdown, allergen badges, pickup/delivery scheduler and a soft pink-cream visual language.',
   'colors'=>['#1c0a14','#2e1422','#F472B6','#FDE68A','#fdf2f8'],
   'badges'=>['responsive','seo','accessibility'],
   'tags'=>['Bakery','Pastel','Custom Cakes','Allergen'],
   'features'=>['Custom cake order','Daily specials','Allergen badges','Pickup scheduler','Pre-order'],
   'rating'=>4.5,'reviews'=>58,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#F472B6','bg'=>'linear-gradient(160deg,#120810 0%,#1c0a14 100%)',
  ],

  /* ──── MEDICAL / PHARMACY ──── */
  ['id'=>'pharma-care','name'=>'Pharma Care','category'=>'medical','cat_label'=>'Medical','emoji'=>'💊',
   'industry'=>'Pharmacy & Health','developer'=>'Stockora Studio','version'=>'3.6.0','downloads'=>'12.4K','last_updated'=>'Jun 2025',
   'description'=>'Trust-first pharmaceutical store with prescription upload, medicine search, dosage information, health blog integration and a clinical-clean aesthetic that builds patient confidence.',
   'colors'=>['#051520','#0a2535','#38BDF8','#34D399','#f0f9ff'],
   'badges'=>['responsive','seo','performance','accessibility'],
   'tags'=>['Pharmacy','Medical','Trusted','Clinical'],
   'features'=>['Rx upload','Medicine search','Dosage info','Health blog','Refill reminders'],
   'rating'=>4.8,'reviews'=>134,'popular'=>true,'ai_pick'=>false,'new'=>false,
   'accent'=>'#38BDF8','bg'=>'linear-gradient(160deg,#030e18 0%,#051520 100%)',
  ],

  /* ──── SPORTS ──── */
  ['id'=>'sport-zone','name'=>'Sport Zone','category'=>'sports','cat_label'=>'Sports','emoji'=>'⚽',
   'industry'=>'Sports & Fitness','developer'=>'Stockora Studio','version'=>'3.4.0','downloads'=>'16.2K','last_updated'=>'Jul 2025',
   'description'=>'High-octane sports store with action photography hero, performance specs, team shopping, flash sales and a bold electric-blue-on-black system built to motivate buyers.',
   'colors'=>['#0a0a0a','#111111','#22D3EE','#F59E0B','#e0f7fa'],
   'badges'=>['responsive','seo','performance','dark_mode'],
   'tags'=>['Sports','Fitness','Performance','Bold'],
   'features'=>['Performance specs','Team shopping','Flash sales','Training guides','Size charts'],
   'rating'=>4.8,'reviews'=>177,'popular'=>true,'ai_pick'=>false,'new'=>false,
   'accent'=>'#22D3EE','bg'=>'linear-gradient(160deg,#050505 0%,#0a0a0a 100%)',
  ],

  /* ──── BOOKS ──── */
  ['id'=>'page-turner','name'=>'Page Turner','category'=>'books','cat_label'=>'Books','emoji'=>'📚',
   'industry'=>'Books & Publishing','developer'=>'Stockora Studio','version'=>'2.5.0','downloads'=>'6.8K','last_updated'=>'May 2025',
   'description'=>'Warm literary bookstore with author spotlight sections, reading list builder, genre navigation, book club integration, reading tracker and a classic paper-and-ink design language.',
   'colors'=>['#1c140a','#2d2010','#92400E','#FDE68A','#fffbeb'],
   'badges'=>['responsive','seo','accessibility'],
   'tags'=>['Books','Literary','Author','Warm'],
   'features'=>['Author spotlight','Reading list','Book club','Genre navigation','Reading tracker'],
   'rating'=>4.5,'reviews'=>62,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#D97706','bg'=>'linear-gradient(160deg,#100c05 0%,#1c140a 100%)',
  ],

  /* ──── KIDS ──── */
  ['id'=>'kiddoland','name'=>'Kiddoland','category'=>'kids','cat_label'=>'Kids','emoji'=>'🧸',
   'industry'=>'Children\'s Products','developer'=>'Stockora Studio','version'=>'2.7.0','downloads'=>'9.1K','last_updated'=>'Jun 2025',
   'description'=>'Playful, rounded children\'s store with age-group navigation, safety badges, wishlists for parents, birthday reminder feature and a vibrant rainbow-pastel design full of joy.',
   'colors'=>['#0d0a1e','#1a1430','#A78BFA','#34D399','#f9f0ff'],
   'badges'=>['responsive','seo','accessibility'],
   'tags'=>['Kids','Playful','Safe','Colorful'],
   'features'=>['Age-group filter','Safety badges','Parent wishlist','Birthday reminder','Gift wrap'],
   'rating'=>4.7,'reviews'=>88,'popular'=>false,'ai_pick'=>false,'new'=>true,
   'accent'=>'#A78BFA','bg'=>'linear-gradient(160deg,#080614 0%,#0d0a1e 100%)',
  ],

  /* ──── DIGITAL PRODUCTS ──── */
  ['id'=>'digital-depot','name'=>'Digital Depot','category'=>'digital','cat_label'=>'Digital','emoji'=>'⬇️',
   'industry'=>'Digital Downloads','developer'=>'Stockora Studio','version'=>'3.1.0','downloads'=>'13.4K','last_updated'=>'Jul 2025',
   'description'=>'File delivery-first digital products store with instant download workflow, license key management, preview player, bundle deals and a sleek dark SaaS-inspired design.',
   'colors'=>['#080e1c','#0d1828','#6366F1','#34D399','#eef2ff'],
   'badges'=>['responsive','seo','performance'],
   'tags'=>['Digital','Downloads','SaaS','Licenses'],
   'features'=>['Instant download','License keys','Preview player','Bundle deals','Affiliate program'],
   'rating'=>4.8,'reviews'=>121,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#6366F1','bg'=>'linear-gradient(160deg,#040812 0%,#080e1c 100%)',
  ],

  /* ──── LUXURY ──── */
  ['id'=>'obsidian-luxury','name'=>'Obsidian Luxury','category'=>'luxury','cat_label'=>'Luxury','emoji'=>'🖤',
   'industry'=>'Ultra-Premium Retail','developer'=>'Stockora Studio','version'=>'3.8.0','downloads'=>'8.2K','last_updated'=>'Jul 2025',
   'description'=>'The most premium theme in the marketplace. Obsidian black with 24K gold accents, full-page product scroll, monogram personalization, concierge chat and an experience-first layout.',
   'colors'=>['#050505','#0a0a0a','#D4AF37','#F5E6A3','#fffce6'],
   'badges'=>['responsive','seo','performance','dark_mode'],
   'tags'=>['Ultra-Premium','Gold','Concierge','Monogram'],
   'features'=>['Full-page scroll','Monogram service','Concierge chat','VIP membership','Certificate of auth'],
   'rating'=>5.0,'reviews'=>54,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#D4AF37','bg'=>'linear-gradient(160deg,#020202 0%,#050505 100%)',
  ],

  /* ──── GROCERY ──── */
  ['id'=>'fresh-market','name'=>'Fresh Market','category'=>'grocery','cat_label'=>'Grocery','emoji'=>'🛒',
   'industry'=>'Online Grocery','developer'=>'Stockora Studio','version'=>'3.8.0','downloads'=>'21.5K','last_updated'=>'Jul 2025',
   'description'=>'Conversion-optimized online grocery with cart-first UX, quick reorder, delivery slot booking, product freshness badges, weight-based pricing and a vibrant green fresh palette.',
   'colors'=>['#062310','#0d3b1e','#22C55E','#FCD34D','#f0fdf4'],
   'badges'=>['responsive','seo','performance','accessibility'],
   'tags'=>['Grocery','Fresh','Delivery','Fast'],
   'features'=>['Quick reorder','Delivery slots','Weight pricing','Daily deals','Recipe links'],
   'rating'=>4.8,'reviews'=>234,'popular'=>true,'ai_pick'=>false,'new'=>false,
   'accent'=>'#22C55E','bg'=>'linear-gradient(160deg,#041808 0%,#062310 100%)',
  ],

  /* ──── MINIMAL ──── */
  ['id'=>'minimal-zen','name'=>'Minimal Zen','category'=>'minimal','cat_label'=>'Minimal','emoji'=>'☯️',
   'industry'=>'Minimal Lifestyle','developer'=>'Stockora Studio','version'=>'3.1.1','downloads'=>'14.6K','last_updated'=>'Jun 2025',
   'description'=>'Japanese Zen-inspired minimalism with generous whitespace, impeccable type hierarchy and purposeful layouts. Every pixel deliberate, every element essential.',
   'colors'=>['#0a0c0f','#12161c','#A0AEC0','#FFFFFF','#f8fafc'],
   'badges'=>['responsive','seo','performance','accessibility'],
   'tags'=>['Minimal','Zen','Premium','Clean'],
   'features'=>['Full-width images','Minimal navigation','Focus mode','Clean typography','White space'],
   'rating'=>4.8,'reviews'=>167,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#A0AEC0','bg'=>'linear-gradient(160deg,#050608 0%,#0a0c0f 100%)',
  ],

  /* ──── MODERN / GENERAL ──── */
  ['id'=>'metro-store','name'=>'Metro Store','category'=>'general','cat_label'=>'General','emoji'=>'🏢',
   'industry'=>'General eCommerce','developer'=>'Stockora Studio','version'=>'4.0.1','downloads'=>'31.7K','last_updated'=>'Jul 2025',
   'description'=>'The most versatile general-purpose theme. Proven across 31K+ stores with mega-menu, smart search, flash sale ticker, infinite scroll, reviews and a timeless indigo design system.',
   'colors'=>['#0c1828','#152338','#6366F1','#A78BFA','#eef2ff'],
   'badges'=>['responsive','seo','performance','accessibility','dark_mode'],
   'tags'=>['Universal','Versatile','Best Seller','Proven'],
   'features'=>['Mega menu','Smart search','Flash sale','Infinite scroll','Reviews'],
   'rating'=>4.9,'reviews'=>412,'popular'=>true,'ai_pick'=>false,'new'=>false,
   'accent'=>'#6366F1','bg'=>'linear-gradient(160deg,#060e1c 0%,#0c1828 100%)',
  ],
  ['id'=>'neon-city','name'=>'Neon City','category'=>'general','cat_label'=>'General','emoji'=>'🌃',
   'industry'=>'Lifestyle & Pop Culture','developer'=>'Stockora Studio','version'=>'2.9.2','downloads'=>'9.3K','last_updated'=>'May 2025',
   'description'=>'Cyberpunk-neon aesthetic with vibrant gradient glows, animated product reveals, night-market energy and a futuristic UI that stands out in the urban lifestyle niche.',
   'colors'=>['#0d0d1a','#1a0533','#F0ABFC','#818CF8','#fdf4ff'],
   'badges'=>['responsive','performance','dark_mode'],
   'tags'=>['Neon','Cyberpunk','Lifestyle','Animated'],
   'features'=>['Animated hero','Neon cards','Trending section','Countdown deals','Parallax'],
   'rating'=>4.6,'reviews'=>88,'popular'=>false,'ai_pick'=>false,'new'=>true,
   'accent'=>'#F0ABFC','bg'=>'linear-gradient(160deg,#080818 0%,#0d0d1a 100%)',
  ],

  /* ──── PREMIUM / ENTERPRISE ──── */
  ['id'=>'enterprise-x','name'=>'Enterprise X','category'=>'enterprise','cat_label'=>'Enterprise','emoji'=>'🏆',
   'industry'=>'Large Scale Enterprise','developer'=>'Stockora Studio','version'=>'5.0.0','downloads'=>'4.1K','last_updated'=>'Jul 2025',
   'description'=>'Enterprise-grade multi-brand store engine with B2B pricing tiers, bulk order forms, account dashboards, RFQ system, multi-warehouse inventory and a corporate deep-blue design.',
   'colors'=>['#050e1c','#0a1a2e','#2563EB','#60A5FA','#eff6ff'],
   'badges'=>['responsive','seo','performance','accessibility','dark_mode'],
   'tags'=>['B2B','Bulk','Multi-brand','Corporate'],
   'features'=>['B2B pricing','Bulk orders','Account dashboard','RFQ system','Multi-warehouse'],
   'rating'=>4.9,'reviews'=>38,'popular'=>false,'ai_pick'=>false,'new'=>false,
   'accent'=>'#2563EB','bg'=>'linear-gradient(160deg,#020812 0%,#050e1c 100%)',
  ],
];

/* ── Badge definitions ── */
$badgeDefs = [
  'responsive'   =>['label'=>'Responsive',  'icon'=>'bi-phone',           'color'=>'#38BDF8','bg'=>'rgba(56,189,248,.12)'],
  'seo'          =>['label'=>'SEO Ready',   'icon'=>'bi-graph-up-arrow',  'color'=>'#34D399','bg'=>'rgba(52,211,153,.12)'],
  'performance'  =>['label'=>'Performance', 'icon'=>'bi-lightning-charge','color'=>'#FBBF24','bg'=>'rgba(251,191,36,.12)'],
  'accessibility'=>['label'=>'Accessible',  'icon'=>'bi-universal-access','color'=>'#A78BFA','bg'=>'rgba(167,139,250,.12)'],
  'dark_mode'    =>['label'=>'Dark Mode',   'icon'=>'bi-moon-stars-fill', 'color'=>'#818CF8','bg'=>'rgba(129,140,248,.12)'],
];

/* ── Category counts ── */
$catCounts = [];
foreach ($themes as $t) {
    $catCounts[$t['category']] = ($catCounts[$t['category']] ?? 0) + 1;
}

shopHeader($pageTitle, 'theme_marketplace');
?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">

<style>
/* ════ ROOT VARS ════ */
:root {
  --tm-bg:#070c18; --tm-bg2:#0c1322; --tm-card:#0f1a2e;
  --tm-border:rgba(255,255,255,.07); --tm-text:#e2e8f0; --tm-text2:#94a3b8;
  --tm-purple:#7C3AED; --tm-violet:#8B5CF6; --tm-indigo:#6366F1;
}
.tm-wrap { font-family:'Inter',sans-serif; }

/* ════ HERO ════ */
.tm-hero {
  position:relative;overflow:hidden;
  background:linear-gradient(135deg,#040a18 0%,#0a1226 55%,#040a18 100%);
  border-bottom:1px solid rgba(99,102,241,.12);
  padding:2.2rem 2rem 1.8rem;
}
.tm-hero::before {
  content:'';position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(ellipse 70% 55% at 75% 15%,rgba(99,102,241,.1),transparent),
             radial-gradient(ellipse 45% 60% at 8% 85%,rgba(236,72,153,.07),transparent);
}
.tm-hero-label {
  display:inline-flex;align-items:center;gap:.4rem;
  background:rgba(124,58,237,.18);border:1px solid rgba(167,139,250,.22);
  border-radius:30px;padding:.28rem .85rem;
  font-size:.67rem;font-weight:800;color:#c4b5fd;letter-spacing:.8px;text-transform:uppercase;
  margin-bottom:.8rem;
}
.tm-hero h1{font-size:1.9rem;font-weight:900;color:#fff;letter-spacing:-1px;margin:0 0 .4rem;line-height:1.1}
.tm-hero-sub{font-size:.85rem;color:var(--tm-text2);margin:0 0 1.2rem}
.tm-stats{display:flex;flex-wrap:wrap;gap:1.5rem}
.tm-stat{display:flex;flex-direction:column}
.tm-stat-n{font-size:1.1rem;font-weight:900;color:#fff}
.tm-stat-l{font-size:.65rem;color:var(--tm-text2);font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.tm-hero-actions{position:absolute;top:1.1rem;right:1.5rem;z-index:2;display:flex;gap:.6rem;flex-wrap:wrap}
.tm-hero-btn{display:flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:10px;font-size:.76rem;font-weight:800;text-decoration:none;transition:all .18s;white-space:nowrap}
.tm-hero-btn.primary{background:linear-gradient(135deg,#7C3AED,#6366F1);color:#fff;box-shadow:0 4px 14px rgba(99,102,241,.3)}
.tm-hero-btn.primary:hover{box-shadow:0 6px 20px rgba(99,102,241,.45);transform:translateY(-1px)}
.tm-hero-btn.outline{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:rgba(255,255,255,.7)}
.tm-hero-btn.outline:hover{background:rgba(255,255,255,.1);color:#fff}

/* ════ ROLLBACK BANNER ════ */
.tm-rollback-bar{
  display:flex;align-items:center;gap:.8rem;
  background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);
  border-radius:12px;padding:.7rem 1rem;margin:1rem 2rem 0;
}
.tm-rollback-bar i{color:#FBBF24;font-size:1rem;flex-shrink:0}
.tm-rollback-bar span{font-size:.78rem;color:rgba(255,255,255,.7);flex:1}
.tm-rollback-bar button{padding:.35rem .85rem;border-radius:8px;border:none;background:rgba(245,158,11,.15);color:#FBBF24;font-size:.74rem;font-weight:800;cursor:pointer;font-family:'Inter',sans-serif;border:1px solid rgba(245,158,11,.25);transition:all .18s;white-space:nowrap}
.tm-rollback-bar button:hover{background:rgba(245,158,11,.25)}

/* ════ FILTERS ════ */
.tm-filters{
  position:sticky;top:0;z-index:100;
  background:rgba(7,12,24,.97);backdrop-filter:blur(16px);
  border-bottom:1px solid var(--tm-border);
  padding:.65rem 2rem;display:flex;align-items:center;gap:.85rem;flex-wrap:wrap;
}
.tm-pills{display:flex;flex-wrap:wrap;gap:.35rem;flex:1}
.tm-pill{
  display:inline-flex;align-items:center;gap:.3rem;
  padding:.28rem .75rem;border-radius:30px;font-size:.72rem;font-weight:700;cursor:pointer;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.07);
  color:rgba(255,255,255,.5);transition:all .18s;white-space:nowrap;
}
.tm-pill:hover{background:rgba(99,102,241,.1);border-color:rgba(99,102,241,.28);color:#a5b4fc}
.tm-pill.active{background:rgba(99,102,241,.18);border-color:rgba(99,102,241,.42);color:#a5b4fc}
.tm-pill-cnt{font-size:.58rem;font-weight:900;background:rgba(255,255,255,.09);border-radius:8px;padding:.03rem .3rem;color:rgba(255,255,255,.45)}
.tm-pill.active .tm-pill-cnt{background:rgba(99,102,241,.28);color:#c4b5fd}
.tm-filter-r{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.tm-search{display:flex;align-items:center;gap:.35rem;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:.28rem .65rem}
.tm-search input{background:none;border:none;outline:none;color:#fff;font-size:.74rem;width:150px;font-family:'Inter',sans-serif}
.tm-search input::placeholder{color:rgba(255,255,255,.22)}
.tm-search i{color:rgba(255,255,255,.28);font-size:.78rem}
.tm-sort{padding:.28rem .65rem;border-radius:8px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.6);font-size:.74rem;font-weight:600;cursor:pointer;outline:none;font-family:'Inter',sans-serif}
.tm-sort option{background:#0c1322;color:#fff}

/* ════ RESULTS BAR ════ */
.tm-results{display:flex;align-items:center;justify-content:space-between;padding:.6rem 2rem;font-size:.77rem;color:var(--tm-text2)}
.tm-results strong{color:#fff}
.tm-view-btns{display:flex;gap:.28rem}
.tm-view-btn{width:26px;height:26px;border-radius:6px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);color:rgba(255,255,255,.3);cursor:pointer;font-size:.8rem;display:flex;align-items:center;justify-content:center;transition:all .18s}
.tm-view-btn.active{background:rgba(99,102,241,.18);border-color:rgba(99,102,241,.32);color:#a5b4fc}

/* ════ GRID ════ */
.tm-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.2rem;padding:0 2rem 2rem}
.tm-grid.list-view{grid-template-columns:1fr}

/* ════ CARD ════ */
.tm-card{
  background:var(--tm-card);border:1px solid var(--tm-border);
  border-radius:18px;overflow:hidden;
  transition:all .3s cubic-bezier(.4,0,.2,1);
  display:flex;flex-direction:column;position:relative;
}
.tm-card:hover{border-color:rgba(99,102,241,.32);transform:translateY(-5px);box-shadow:0 18px 55px rgba(0,0,0,.5),0 0 0 1px rgba(99,102,241,.12)}
.tm-card.is-active{border-color:rgba(16,185,129,.38)!important;box-shadow:0 0 0 2px rgba(16,185,129,.12),0 12px 40px rgba(0,0,0,.4)!important}
.tm-card.hidden{display:none}

/* Preview */
.tm-prev{height:190px;position:relative;overflow:hidden;cursor:pointer;flex-shrink:0}
.tm-prev-bg{position:absolute;inset:0;display:flex;flex-direction:column}
/* Browser chrome */
.tm-chrome{position:absolute;top:0;left:0;right:0;z-index:5;height:20px;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);border-bottom:1px solid rgba(255,255,255,.05);display:flex;align-items:center;gap:4px;padding:0 7px}
.tm-chrome-dot{width:5px;height:5px;border-radius:50%}
.tm-chrome-bar{flex:1;height:9px;border-radius:20px;background:rgba(255,255,255,.07);margin:0 5px}
/* UI overlay */
.tm-ui{position:absolute;inset:0;z-index:4;padding:24px 8px 6px;display:flex;flex-direction:column;gap:4px}
.tm-ui-nav{display:flex;align-items:center;gap:3px;height:9px}
.tm-ui-logo{height:7px;width:36px;border-radius:3px}
.tm-ui-navlinks{display:flex;gap:3px;margin-left:auto}
.tm-ui-navlink{width:14px;height:4px;border-radius:2px;opacity:.3}
.tm-ui-cart{width:7px;height:7px;border-radius:50%;margin-left:3px}
.tm-ui-hero{display:flex;flex-direction:column;gap:3px;padding:5px 4px}
.tm-ui-h1{height:7px;border-radius:3px}
.tm-ui-h2{height:4px;border-radius:2px;opacity:.5}
.tm-ui-btn{height:6px;width:28%;border-radius:4px;margin-top:2px}
.tm-ui-prods{display:flex;gap:3px;margin-top:auto}
.tm-ui-pcard{flex:1;border-radius:5px;overflow:hidden;display:flex;flex-direction:column}
.tm-ui-pimg{height:22px}
.tm-ui-pname{height:3px;margin:2px 3px 1px;border-radius:2px;opacity:.45}
.tm-ui-pprice{height:4px;margin:0 3px 3px;border-radius:2px}
/* hover overlay */
.tm-hover{position:absolute;inset:0;z-index:10;background:rgba(4,4,20,.8);backdrop-filter:blur(4px);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.6rem;opacity:0;transition:opacity .22s}
.tm-card:hover .tm-hover{opacity:1}
.tm-hbtn{display:flex;align-items:center;gap:.45rem;padding:.55rem 1.3rem;border-radius:9px;font-size:.76rem;font-weight:800;cursor:pointer;border:none;font-family:'Inter',sans-serif;transition:all .18s;width:155px;justify-content:center}
.tm-hbtn.primary{background:linear-gradient(135deg,#7C3AED,#6366F1);color:#fff;box-shadow:0 4px 16px rgba(99,102,241,.35)}
.tm-hbtn.primary:hover{transform:scale(1.03)}
.tm-hbtn.sec{background:rgba(255,255,255,.08);color:rgba(255,255,255,.8);border:1px solid rgba(255,255,255,.14)}
.tm-hbtn.sec:hover{background:rgba(255,255,255,.13);color:#fff}
.tm-hbtn.fav{background:rgba(236,72,153,.1);color:#F9A8D4;border:1px solid rgba(236,72,153,.22)}
.tm-hbtn.fav.on{background:rgba(236,72,153,.2);color:#ec4899;border-color:rgba(236,72,153,.45)}
/* badges */
.tm-prev-badge{position:absolute;top:.5rem;left:.5rem;z-index:8;display:inline-flex;align-items:center;gap:.28rem;font-size:.6rem;font-weight:800;padding:.2rem .5rem;border-radius:20px}
.b-popular{background:rgba(245,158,11,.18);color:#FCD34D;border:1px solid rgba(245,158,11,.28)}
.b-ai{background:rgba(236,72,153,.18);color:#F472B6;border:1px solid rgba(236,72,153,.28)}
.b-new{background:rgba(16,185,129,.18);color:#34D399;border:1px solid rgba(16,185,129,.28)}
.b-active{background:rgba(16,185,129,.18);color:#34D399;border:1px solid rgba(16,185,129,.32)}
/* fav heart */
.tm-fav{position:absolute;top:.5rem;right:.5rem;z-index:8;width:26px;height:26px;border-radius:50%;background:rgba(0,0,0,.45);border:1px solid rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.8rem;color:rgba(255,255,255,.38);transition:all .2s}
.tm-fav:hover{background:rgba(236,72,153,.18);color:#F9A8D4}
.tm-fav.on{color:#ec4899;background:rgba(236,72,153,.14);border-color:rgba(236,72,153,.28)}

/* Card body */
.tm-body{padding:.9rem;flex:1;display:flex;flex-direction:column}
.tm-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.45rem}
.tm-name{font-size:.9rem;font-weight:900;color:#fff;letter-spacing:-.3px;line-height:1.2}
.tm-cat{font-size:.62rem;font-weight:700;color:var(--tm-text2);text-transform:uppercase;letter-spacing:.4px;margin-top:.1rem}
.tm-vpill{font-size:.58rem;font-weight:800;background:rgba(99,102,241,.14);color:#818CF8;border:1px solid rgba(99,102,241,.18);border-radius:6px;padding:.08rem .38rem;flex-shrink:0}
.tm-desc{font-size:.71rem;color:var(--tm-text2);line-height:1.6;margin-bottom:.65rem;flex:1;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.tm-swatches{display:flex;gap:.28rem;margin-bottom:.65rem}
.tm-swatch{width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,.09);flex-shrink:0;transition:transform .15s;cursor:default}
.tm-swatch:hover{transform:scale(1.25)}
/* quality badges */
.tm-qbadges{display:flex;flex-wrap:wrap;gap:.25rem;margin-bottom:.65rem}
.tm-qbadge{display:inline-flex;align-items:center;gap:.22rem;font-size:.58rem;font-weight:700;padding:.15rem .4rem;border-radius:6px;white-space:nowrap}
/* meta grid */
.tm-meta{display:grid;grid-template-columns:1fr 1fr;gap:.28rem;margin-bottom:.7rem}
.tm-meta-item{display:flex;flex-direction:column}
.tm-ml{font-size:.55rem;font-weight:700;color:rgba(255,255,255,.28);text-transform:uppercase;letter-spacing:.4px}
.tm-mv{font-size:.68rem;font-weight:700;color:rgba(255,255,255,.65)}
/* rating */
.tm-rating{display:flex;align-items:center;gap:.35rem;margin-bottom:.75rem}
.tm-stars{display:flex;gap:.08rem}
.tm-stars i{font-size:.68rem}
.tm-rn{font-size:.78rem;font-weight:800;color:#fff}
.tm-rc{font-size:.66rem;color:var(--tm-text2)}
/* CTA */
.tm-cta{display:flex;gap:.4rem;margin-top:auto}
.tm-ibtn{flex:1;padding:.55rem;border-radius:9px;border:none;font-size:.76rem;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.38rem;font-family:'Inter',sans-serif;transition:all .22s}
.tm-ibtn.install{background:linear-gradient(135deg,#7C3AED,#6366F1);color:#fff;box-shadow:0 4px 14px rgba(99,102,241,.28)}
.tm-ibtn.install:hover{transform:translateY(-1px);box-shadow:0 7px 22px rgba(99,102,241,.42)}
.tm-ibtn.active{background:rgba(16,185,129,.1);color:#34D399;border:1px solid rgba(16,185,129,.28)}
.tm-dbtn{width:34px;height:34px;border-radius:9px;border:1px solid rgba(255,255,255,.08);cursor:pointer;background:rgba(255,255,255,.05);color:rgba(255,255,255,.45);font-size:.82rem;display:flex;align-items:center;justify-content:center;transition:all .18s;flex-shrink:0}
.tm-dbtn:hover{background:rgba(255,255,255,.09);color:#fff}

/* ════ LIST VIEW ════ */
.tm-grid.list-view .tm-card{flex-direction:row;height:130px}
.tm-grid.list-view .tm-prev{width:190px;height:130px;flex-shrink:0}
.tm-grid.list-view .tm-body{flex-direction:row;align-items:center;gap:.9rem;padding:.75rem}
.tm-grid.list-view .tm-desc{display:block;-webkit-line-clamp:unset;margin-bottom:0}
.tm-grid.list-view .tm-meta,.tm-grid.list-view .tm-swatches{display:none}

/* ════ LIVE PREVIEW MODAL ════ */
.tm-lpmodal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(2,4,14,.96);backdrop-filter:blur(10px);flex-direction:column}
.tm-lpmodal.open{display:flex}
.tm-lp-topbar{height:52px;flex-shrink:0;background:#07101e;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:.85rem;padding:0 1.1rem}
.tm-lp-dots{display:flex;gap:5px}
.tm-lp-dot{width:10px;height:10px;border-radius:50%}
.tm-lp-title{font-size:.82rem;font-weight:800;color:#fff;flex:1}
.tm-lp-subtitle{font-size:.72rem;color:var(--tm-text2)}
.tm-lp-dev-btns{display:flex;gap:.3rem}
.tm-lp-dev{width:32px;height:28px;border-radius:7px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.4);cursor:pointer;font-size:.85rem;display:flex;align-items:center;justify-content:center;transition:all .18s}
.tm-lp-dev.active,.tm-lp-dev:hover{background:rgba(99,102,241,.18);border-color:rgba(99,102,241,.32);color:#a5b4fc}
.tm-lp-url{flex:1;max-width:380px;height:24px;border-radius:20px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.07);display:flex;align-items:center;padding:0 .65rem;gap:.35rem;font-size:.68rem;color:rgba(255,255,255,.3);overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.tm-lp-close{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:#fff;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;transition:all .18s;flex-shrink:0}
.tm-lp-close:hover{background:rgba(239,68,68,.18);border-color:rgba(239,68,68,.35)}
.tm-lp-install-btn{display:flex;align-items:center;gap:.4rem;padding:.4rem 1rem;border-radius:9px;background:linear-gradient(135deg,#7C3AED,#6366F1);color:#fff;font-size:.76rem;font-weight:800;border:none;cursor:pointer;font-family:'Inter',sans-serif;transition:all .18s;white-space:nowrap}
.tm-lp-install-btn:hover{box-shadow:0 4px 16px rgba(99,102,241,.4)}
.tm-lp-body{flex:1;display:flex;overflow:hidden;position:relative}
.tm-lp-loading{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;background:#07101e;z-index:5}
.tm-lp-spinner{width:40px;height:40px;border:3px solid rgba(99,102,241,.2);border-top-color:#6366f1;border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.tm-lp-iframe{flex:1;border:none;display:block;background:#fff;transition:max-width .3s,margin .3s}
.tm-lp-iframe.tablet{max-width:768px;margin:0 auto;box-shadow:0 0 0 1px rgba(255,255,255,.05)}
.tm-lp-iframe.mobile{max-width:390px;margin:0 auto;box-shadow:0 0 0 1px rgba(255,255,255,.05)}

/* ════ DETAIL MODAL ════ */
.tm-dmodal{display:none;position:fixed;inset:0;z-index:99998;background:rgba(3,5,18,.9);backdrop-filter:blur(10px);overflow-y:auto;padding:2rem 1rem;align-items:flex-start;justify-content:center}
.tm-dmodal.open{display:flex}
.tm-dmodal-inner{background:#0b1225;border:1px solid rgba(99,102,241,.18);border-radius:22px;width:100%;max-width:800px;overflow:hidden;box-shadow:0 40px 120px rgba(0,0,0,.7);animation:modalIn .3s cubic-bezier(.4,0,.2,1)}
@keyframes modalIn{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:none}}
.tm-dm-prev{height:300px;position:relative;overflow:hidden}
.tm-dm-close{position:absolute;top:.7rem;right:.7rem;z-index:20;width:34px;height:34px;border-radius:50%;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.15);color:#fff;cursor:pointer;font-size:.95rem;display:flex;align-items:center;justify-content:center;transition:all .2s}
.tm-dm-close:hover{background:rgba(239,68,68,.2)}
.tm-dm-body{padding:1.6rem 1.8rem}
.tm-dm-title{font-size:1.5rem;font-weight:900;color:#fff;letter-spacing:-.5px;margin-bottom:.3rem}
.tm-dm-sub{font-size:.82rem;color:var(--tm-text2);margin-bottom:1.1rem;line-height:1.6}
.tm-dm-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;margin-bottom:1.1rem}
.tm-info-block{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:11px;padding:.85rem}
.tm-ib-title{font-size:.62rem;font-weight:800;color:rgba(255,255,255,.28);text-transform:uppercase;letter-spacing:1px;margin-bottom:.65rem}
.tm-ib-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:.3rem}
.tm-ib-k{font-size:.72rem;color:var(--tm-text2)}
.tm-ib-v{font-size:.72rem;font-weight:700;color:#fff}
.tm-fpill{display:inline-flex;align-items:center;gap:.28rem;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.18);color:#34D399;font-size:.7rem;font-weight:700;padding:.22rem .6rem;border-radius:20px}
.tm-fpills{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.65rem}
.tm-dm-cta{display:flex;gap:.65rem;margin-top:1.3rem}
.tm-dm-btn{flex:1;padding:.72rem;border-radius:11px;border:none;font-size:.82rem;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.45rem;font-family:'Inter',sans-serif;transition:all .22s;text-decoration:none}
.tm-dm-btn.primary{background:linear-gradient(135deg,#7C3AED,#6366F1);color:#fff;box-shadow:0 5px 20px rgba(99,102,241,.35)}
.tm-dm-btn.primary:hover{box-shadow:0 9px 28px rgba(99,102,241,.5);transform:translateY(-1px)}
.tm-dm-btn.outline{background:rgba(255,255,255,.05);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.1)}
.tm-dm-btn.outline:hover{background:rgba(255,255,255,.09);color:#fff}

/* ════ EMPTY STATE ════ */
.tm-empty{grid-column:1/-1;text-align:center;padding:3.5rem 2rem;display:none}
.tm-empty-icon{font-size:2.5rem;opacity:.25;margin-bottom:.75rem}
.tm-empty-t{font-size:.95rem;font-weight:800;color:rgba(255,255,255,.45);margin-bottom:.3rem}
.tm-empty-s{font-size:.8rem;color:rgba(255,255,255,.22)}

/* ════ TOAST ════ */
.tm-toast{position:fixed;bottom:1.5rem;right:1.5rem;z-index:999999;background:#0d1525;border-radius:13px;padding:.85rem 1.15rem;font-size:.8rem;font-weight:700;display:flex;align-items:center;gap:.55rem;box-shadow:0 8px 36px rgba(0,0,0,.5);max-width:310px;animation:toastIn .28s cubic-bezier(.4,0,.2,1);pointer-events:none}
@keyframes toastIn{from{opacity:0;transform:translateY(14px) scale(.95)}to{opacity:1;transform:none}}

/* ════ RESPONSIVE ════ */
@media(max-width:1200px){.tm-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:880px){.tm-grid{grid-template-columns:repeat(2,1fr);padding:0 1rem 1.5rem}.tm-hero,.tm-filters,.tm-results{padding-left:1rem;padding-right:1rem}}
@media(max-width:580px){.tm-grid{grid-template-columns:1fr;gap:.85rem}.tm-hero h1{font-size:1.35rem}.tm-stats{gap:1rem}.tm-grid.list-view .tm-card{flex-direction:column;height:auto}.tm-grid.list-view .tm-prev{width:100%;height:155px}}

/* ════ ACTION BAR BUTTONS ════ */
.tm-action-bar{display:flex;align-items:center;gap:.35rem;flex-wrap:wrap}
.tm-act-btn{display:inline-flex;align-items:center;gap:.35rem;padding:.38rem .8rem;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:rgba(255,255,255,.7);font-size:.73rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all .18s;white-space:nowrap}
.tm-act-btn:hover{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.22)}
.tm-act-btn.highlight{background:rgba(99,102,241,.12);color:#a5b4fc;border-color:rgba(99,102,241,.28)}
.tm-act-btn.highlight:hover{background:rgba(99,102,241,.22);color:#c7d2fe}
@media(max-width:640px){.tm-action-bar{display:none}}

/* ════ VERSION HISTORY MODAL ════ */
.tm-vh-modal{position:fixed;inset:0;z-index:9999;background:rgba(2,4,12,.88);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:1rem}
.tm-modal-overlay.open .tm-vh-modal,
.tm-modal-overlay.open{display:flex}
#tmVhModal{display:none}
#tmVhModal.open{display:flex;align-items:center;justify-content:center;position:fixed;inset:0;z-index:9999;background:rgba(2,4,12,.88);backdrop-filter:blur(8px);padding:1rem}
.tm-vh-modal{background:#0e1628;border:1px solid rgba(255,255,255,.1);border-radius:18px;width:100%;max-width:680px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.7)}
.tm-vh-header{display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.4rem;border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0}
.tm-vh-title{font-size:.95rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:.5rem}
.tm-vh-title i{color:#6366F1}
.tm-vh-body{overflow-y:auto;padding:1.2rem 1.4rem;flex:1;display:flex;flex-direction:column;gap:1.4rem}
.tm-vh-section{}
.tm-vh-sec-title{font-size:.78rem;font-weight:800;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.7rem;display:flex;align-items:center;gap:.4rem}
.tm-vh-badge{background:rgba(99,102,241,.15);color:#a5b4fc;padding:.08rem .45rem;border-radius:20px;font-size:.65rem;font-weight:800}
.tm-vh-empty{font-size:.78rem;color:rgba(255,255,255,.35);padding:.5rem 0;font-style:italic}
.tm-vh-row{display:flex;align-items:center;gap:.75rem;padding:.65rem .8rem;border-radius:10px;background:rgba(255,255,255,.03);margin-bottom:.4rem;border:1px solid rgba(255,255,255,.06)}
.tm-vh-row-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.tm-vh-row-info{flex:1;display:flex;flex-direction:column;gap:.12rem;min-width:0}
.tm-vh-theme{font-size:.8rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tm-vh-action{font-size:.68rem;font-weight:700;text-transform:capitalize}
.tm-vh-ts{font-size:.65rem;color:rgba(255,255,255,.35)}
.tm-vh-act-btn{padding:.3rem .75rem;border-radius:7px;border:1px solid rgba(56,189,248,.25);background:rgba(56,189,248,.1);color:#38BDF8;font-size:.7rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all .18s;white-space:nowrap;flex-shrink:0}
.tm-vh-act-btn:hover{background:rgba(56,189,248,.2);border-color:rgba(56,189,248,.45)}
.tm-dmbtn-close{width:32px;height:32px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.85rem;transition:all .18s}
.tm-dmbtn-close:hover{background:rgba(239,68,68,.15);color:#f87171;border-color:rgba(239,68,68,.3)}
</style>

<!-- ════ LIVE PREVIEW MODAL ════ -->
<div class="tm-lpmodal" id="tmLpModal">
  <div class="tm-lp-topbar">
    <div class="tm-lp-dots">
      <div class="tm-lp-dot" style="background:#ff5f57"></div>
      <div class="tm-lp-dot" style="background:#ffbd2e"></div>
      <div class="tm-lp-dot" style="background:#28c840"></div>
    </div>
    <div class="tm-lp-dev-btns">
      <button class="tm-lp-dev active" onclick="lpDevice('desktop',this)" title="Desktop"><i class="bi bi-display"></i></button>
      <button class="tm-lp-dev" onclick="lpDevice('tablet',this)" title="Tablet"><i class="bi bi-tablet-landscape"></i></button>
      <button class="tm-lp-dev" onclick="lpDevice('mobile',this)" title="Mobile"><i class="bi bi-phone"></i></button>
    </div>
    <div class="tm-lp-url"><i class="bi bi-lock-fill" style="color:#34d399;font-size:.62rem;flex-shrink:0"></i> <span id="tmLpUrl"></span></div>
    <div style="flex:1;font-size:.78rem;font-weight:800;color:#fff" id="tmLpThemeName"></div>
    <button class="tm-lp-install-btn" id="tmLpInstallBtn" onclick="installFromPreview()">
      <i class="bi bi-cloud-download-fill"></i> Install Theme
    </button>
    <button class="tm-lp-close" onclick="closeLpModal()" title="Close"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="tm-lp-body">
    <div class="tm-lp-loading" id="tmLpLoading">
      <div class="tm-lp-spinner"></div>
      <div style="font-size:.82rem;color:var(--tm-text2)">Loading live preview...</div>
    </div>
    <iframe class="tm-lp-iframe" id="tmLpFrame" src="about:blank" onload="lpFrameLoaded()" sandbox="allow-scripts allow-same-origin allow-forms allow-popups"></iframe>
  </div>
</div>

<!-- ════ DETAIL MODAL ════ -->
<div class="tm-dmodal" id="tmDModal" onclick="if(event.target===this)closeDModal()">
  <div class="tm-dmodal-inner">
    <div class="tm-dm-prev" id="tmDmPrev">
      <button class="tm-dm-close" onclick="closeDModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="tm-dm-body" id="tmDmBody"></div>
  </div>
</div>

<!-- ════ PAGE CONTENT ════ -->
<div class="tm-wrap">

  <!-- HERO -->
  <div class="tm-hero">
    <div style="position:relative;z-index:1">
      <a href="<?= BASE_URL ?>/shop/commerce_cloud.php" style="color:var(--tm-text2);text-decoration:none;font-size:.76rem;display:inline-flex;align-items:center;gap:.3rem;margin-bottom:.8rem"><i class="bi bi-arrow-left"></i> Commerce Cloud</a>
      <div class="tm-hero-label"><i class="bi bi-palette-fill"></i> Enterprise Theme Marketplace</div>
      <h1><?= count($themes) ?> Professionally Designed Store Themes</h1>
      <p class="tm-hero-sub">Covers 15+ industries · Unique design language per theme · Live Preview · One-click install · Theme rollback</p>
      <div class="tm-stats">
        <div class="tm-stat"><span class="tm-stat-n"><?= count($themes) ?></span><span class="tm-stat-l">Premium Themes</span></div>
        <div class="tm-stat"><span class="tm-stat-n"><?= count($catCounts) ?></span><span class="tm-stat-l">Industries</span></div>
        <div class="tm-stat"><span class="tm-stat-n">100%</span><span class="tm-stat-l">Responsive</span></div>
        <div class="tm-stat"><span class="tm-stat-n">Free</span><span class="tm-stat-l">All Themes</span></div>
      </div>
    </div>
    <div class="tm-hero-actions">
      <?php if($savedTheme): ?>
      <span style="display:flex;align-items:center;gap:.4rem;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.22);border-radius:9px;padding:.45rem .9rem;font-size:.74rem;font-weight:800;color:#34D399">
        <i class="bi bi-check-circle-fill"></i> Active: <?= htmlspecialchars($savedTheme) ?>
      </span>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/shop/store_customize.php" class="tm-hero-btn primary"><i class="bi bi-sliders"></i> Customizer</a>
      <a href="<?= BASE_URL ?>/shop/store_wizard.php" class="tm-hero-btn outline"><i class="bi bi-rocket-takeoff-fill"></i> Wizard</a>
    </div>
  </div>

  <!-- ROLLBACK BANNER -->
  <?php if($rollbackTheme): ?>
  <div class="tm-rollback-bar">
    <i class="bi bi-arrow-counterclockwise"></i>
    <span>Previous theme: <strong style="color:#fff"><?= htmlspecialchars($rollbackTheme) ?></strong> — You can roll back anytime without losing your store data.</span>
    <button onclick="rollbackTheme('<?= addslashes($rollbackTheme) ?>')"><i class="bi bi-arrow-counterclockwise"></i> Rollback</button>
  </div>
  <?php endif; ?>

  <!-- STICKY FILTER BAR -->
  <div class="tm-filters">
    <div class="tm-pills" id="tmPills">
      <button class="tm-pill active" onclick="filterBy('all',this)"><i class="bi bi-grid-3x3-gap-fill"></i> All <span class="tm-pill-cnt"><?= count($themes) ?></span></button>
      <?php foreach($catCounts as $cat=>$cnt):
        $catEmojis=['fashion'=>'👗','electronics'=>'💻','furniture'=>'🪑','jewellery'=>'💎','beauty'=>'💄','restaurant'=>'🍽️','cafe'=>'☕','bakery'=>'🧁','medical'=>'💊','sports'=>'⚽','books'=>'📚','kids'=>'🧸','digital'=>'⬇️','luxury'=>'🖤','grocery'=>'🛒','minimal'=>'☯️','general'=>'🏢','enterprise'=>'🏆'];
        $em = $catEmojis[$cat] ?? '🏷️';
      ?>
      <button class="tm-pill" onclick="filterBy('<?= $cat ?>',this)"><?= $em ?> <?= ucfirst($cat) ?> <span class="tm-pill-cnt"><?= $cnt ?></span></button>
      <?php endforeach; ?>
    </div>
    <div class="tm-filter-r">
      <div class="tm-search"><i class="bi bi-search"></i><input type="text" placeholder="Search themes..." oninput="liveSearch(this.value)" id="tmSearchInput"></div>
      <select class="tm-sort" onchange="sortThemes(this.value)">
        <option value="popular">Popular</option>
        <option value="rating">Highest Rated</option>
        <option value="downloads">Most Installed</option>
        <option value="newest">Newest</option>
      </select>
    </div>
  </div>

  <!-- RESULTS BAR -->
  <div class="tm-results">
    <div>Showing <strong id="tmCount"><?= count($themes) ?></strong> themes</div>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
      <!-- Theme Management Actions -->
      <div class="tm-action-bar">
        <button class="tm-act-btn" onclick="backupTheme()" title="Backup current theme settings"><i class="bi bi-hdd-stack-fill"></i> Backup</button>
        <button class="tm-act-btn" onclick="exportTheme('<?= addslashes($savedTheme) ?>')" title="Export theme as JSON"><i class="bi bi-box-arrow-down"></i> Export</button>
        <button class="tm-act-btn" onclick="importTheme()" title="Import theme from JSON file"><i class="bi bi-box-arrow-in-up"></i> Import</button>
        <button class="tm-act-btn highlight" onclick="openVhModal()" title="View version history &amp; backups"><i class="bi bi-clock-history"></i> History</button>
      </div>
      <div class="tm-view-btns">
        <button class="tm-view-btn active" onclick="setView('grid',this)" title="Grid"><i class="bi bi-grid-3x3-gap-fill"></i></button>
        <button class="tm-view-btn" onclick="setView('list',this)" title="List"><i class="bi bi-list-ul"></i></button>
      </div>
    </div>
  </div>

  <!-- GRID -->
  <div class="tm-grid" id="tmGrid">
    <?php foreach($themes as $t):
      $isSaved = ($savedThemeId===$t['id'] || $savedTheme===$t['name']);
      $isFaved = in_array($t['id'],$favourites);
    ?>
    <div class="tm-card <?= $isSaved?'is-active':'' ?>"
         id="tmcard-<?= $t['id'] ?>"
         data-id="<?= $t['id'] ?>"
         data-category="<?= $t['category'] ?>"
         data-rating="<?= $t['rating'] ?>"
         data-downloads="<?= (int)str_replace(['K','.'],['000',''],$t['downloads']) ?>"
         data-new="<?= $t['new']?'1':'0' ?>">

      <!-- PREVIEW -->
      <div class="tm-prev" onclick="openDModal('<?= $t['id'] ?>')">
        <!-- BG bars -->
        <div class="tm-prev-bg">
          <?php foreach($t['preview_bars'] ?? [[$t['colors'][1],'100%'],[$t['colors'][0],'60%'],[$t['accent'],'20%']] as [$bc,$bh]): ?>
          <div style="background:<?= $bc ?>;height:<?= $bh ?>;flex-shrink:0"></div>
          <?php endforeach; ?>
          <div style="background:<?= $t['colors'][0] ?>;flex:1"></div>
        </div>
        <!-- Chrome -->
        <div class="tm-chrome">
          <div class="tm-chrome-dot" style="background:#ff5f57"></div>
          <div class="tm-chrome-dot" style="background:#ffbd2e"></div>
          <div class="tm-chrome-dot" style="background:#28c840"></div>
          <div class="tm-chrome-bar"></div>
        </div>
        <!-- UI Layer -->
        <div class="tm-ui">
          <div class="tm-ui-nav">
            <div class="tm-ui-logo" style="background:<?= $t['accent'] ?>;opacity:.85"></div>
            <div class="tm-ui-navlinks">
              <div class="tm-ui-navlink" style="background:<?= $t['accent'] ?>"></div>
              <div class="tm-ui-navlink" style="background:rgba(255,255,255,.25)"></div>
              <div class="tm-ui-navlink" style="background:rgba(255,255,255,.25)"></div>
            </div>
            <div class="tm-ui-cart" style="background:<?= $t['accent'] ?>"></div>
          </div>
          <div class="tm-ui-hero">
            <div class="tm-ui-h1" style="background:rgba(255,255,255,.85);width:60%"></div>
            <div class="tm-ui-h2" style="background:rgba(255,255,255,.4);width:42%"></div>
            <div class="tm-ui-btn" style="background:<?= $t['accent'] ?>"></div>
          </div>
          <div class="tm-ui-prods">
            <?php for($pi=0;$pi<3;$pi++): ?>
            <div class="tm-ui-pcard" style="background:rgba(255,255,255,<?= .05+$pi*.015 ?>)">
              <div class="tm-ui-pimg" style="background:linear-gradient(135deg,rgba(255,255,255,.1),rgba(255,255,255,.04))"></div>
              <div class="tm-ui-pname" style="background:rgba(255,255,255,.2)"></div>
              <div class="tm-ui-pprice" style="background:<?= $t['accent'] ?>"></div>
            </div>
            <?php endfor; ?>
          </div>
        </div>
        <!-- Status badge -->
        <?php if($isSaved): ?><span class="tm-prev-badge b-active"><i class="bi bi-check-circle-fill"></i> Active</span>
        <?php elseif($t['ai_pick']): ?><span class="tm-prev-badge b-ai"><i class="bi bi-robot"></i> AI Pick</span>
        <?php elseif($t['popular']): ?><span class="tm-prev-badge b-popular">⭐ Popular</span>
        <?php elseif($t['new']): ?><span class="tm-prev-badge b-new">✨ New</span>
        <?php endif; ?>
        <!-- Fav -->
        <div class="tm-fav <?= $isFaved?'on':'' ?>" id="fav-<?= $t['id'] ?>"
             onclick="event.stopPropagation();toggleFav('<?= $t['id'] ?>')"
             title="<?= $isFaved?'Remove favourite':'Add to favourites' ?>">
          <i class="bi <?= $isFaved?'bi-heart-fill':'bi-heart' ?>"></i>
        </div>
        <!-- Hover overlay -->
        <div class="tm-hover">
          <button class="tm-hbtn primary" onclick="event.stopPropagation();installTheme('<?= addslashes($t['name']) ?>','<?= $t['id'] ?>')">
            <i class="bi bi-cloud-download-fill"></i> <?= $isSaved?'Active':'Install' ?>
          </button>
          <button class="tm-hbtn sec" onclick="event.stopPropagation();openLpModal('<?= $t['id'] ?>')">
            <i class="bi bi-play-circle-fill"></i> Live Preview
          </button>
          <button class="tm-hbtn fav <?= $isFaved?'on':'' ?>" onclick="event.stopPropagation();toggleFav('<?= $t['id'] ?>')">
            <i class="bi <?= $isFaved?'bi-heart-fill':'bi-heart' ?>"></i> <?= $isFaved?'Unfavourite':'Favourite' ?>
          </button>
        </div>
      </div>

      <!-- CARD BODY -->
      <div class="tm-body">
        <div class="tm-header">
          <div>
            <div class="tm-name"><?= htmlspecialchars($t['name']) ?></div>
            <div class="tm-cat"><?= $t['emoji'] ?> <?= $t['cat_label'] ?> · <?= $t['industry'] ?></div>
          </div>
          <span class="tm-vpill">v<?= $t['version'] ?></span>
        </div>
        <div class="tm-desc"><?= htmlspecialchars($t['description']) ?></div>
        <div class="tm-swatches">
          <?php foreach(array_slice($t['colors'],0,5) as $col): ?>
          <div class="tm-swatch" style="background:<?= $col ?>" title="<?= $col ?>"></div>
          <?php endforeach; ?>
        </div>
        <div class="tm-qbadges">
          <?php foreach($t['badges'] as $b): $bd=$badgeDefs[$b]??null; if(!$bd) continue; ?>
          <span class="tm-qbadge" style="background:<?= $bd['bg'] ?>;color:<?= $bd['color'] ?>;border:1px solid <?= $bd['color'] ?>33">
            <i class="bi <?= $bd['icon'] ?>"></i><?= $bd['label'] ?>
          </span>
          <?php endforeach; ?>
        </div>
        <div class="tm-meta">
          <div class="tm-meta-item"><span class="tm-ml">Developer</span><span class="tm-mv"><?= $t['developer'] ?></span></div>
          <div class="tm-meta-item"><span class="tm-ml">Downloads</span><span class="tm-mv"><?= $t['downloads'] ?></span></div>
          <div class="tm-meta-item"><span class="tm-ml">Updated</span><span class="tm-mv"><?= $t['last_updated'] ?></span></div>
          <div class="tm-meta-item"><span class="tm-ml">Version</span><span class="tm-mv">v<?= $t['version'] ?></span></div>
        </div>
        <div class="tm-rating">
          <div class="tm-stars">
            <?php for($s=0;$s<5;$s++): ?>
            <i class="bi <?= $s<floor($t['rating'])?'bi-star-fill':'bi-star' ?>" style="color:<?= $s<floor($t['rating'])?'#F59E0B':'rgba(255,255,255,.18)' ?>"></i>
            <?php endfor; ?>
          </div>
          <span class="tm-rn"><?= $t['rating'] ?></span>
          <span class="tm-rc">(<?= $t['reviews'] ?> reviews)</span>
        </div>
        <div class="tm-cta">
          <button class="tm-ibtn <?= $isSaved?'active':'install' ?>" id="ibtn-<?= $t['id'] ?>"
                  onclick="installTheme('<?= addslashes($t['name']) ?>','<?= $t['id'] ?>')">
            <?php if($isSaved): ?>
            <i class="bi bi-check-circle-fill"></i> Active Theme
            <?php else: ?>
            <i class="bi bi-cloud-download-fill"></i> Install Theme
            <?php endif; ?>
          </button>
          <button class="tm-dbtn" onclick="openLpModal('<?= $t['id'] ?>')" title="Live Preview"><i class="bi bi-play-circle-fill" style="color:#34d399"></i></button>
          <button class="tm-dbtn" onclick="openDModal('<?= $t['id'] ?>')" title="Theme Details"><i class="bi bi-info-circle"></i></button>
          <button class="tm-dbtn" onclick="duplicateTheme('<?= addslashes($t['name']) ?>','<?= $t['id'] ?>')" title="Duplicate Theme"><i class="bi bi-copy"></i></button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="tm-empty" id="tmEmpty">
      <div class="tm-empty-icon">🔍</div>
      <div class="tm-empty-t">No themes found</div>
      <div class="tm-empty-s">Try a different filter or search term</div>
    </div>
  </div><!-- /tmGrid -->

</div><!-- /tm-wrap -->

<!-- ════════════════════════════════════════════════════════════
     VERSION HISTORY & BACKUPS MODAL
     ════════════════════════════════════════════════════════════ -->
<div class="tm-modal-overlay" id="tmVhModal">
  <div class="tm-vh-modal">
    <div class="tm-vh-header">
      <div class="tm-vh-title"><i class="bi bi-clock-history"></i> Version History &amp; Backups</div>
      <div style="display:flex;align-items:center;gap:.5rem">
        <button class="tm-act-btn" onclick="backupTheme()" style="padding:.35rem .8rem;font-size:.72rem"><i class="bi bi-hdd-stack-fill"></i> Backup Now</button>
        <button class="tm-dmbtn-close" onclick="closeVhModal()"><i class="bi bi-x-lg"></i></button>
      </div>
    </div>
    <div class="tm-vh-body" id="tmVhBody">
      <div style="text-align:center;padding:2rem;color:rgba(255,255,255,.4)">Loading...</div>
    </div>
  </div>
</div>

<script>
/* ════ DATA ════ */
var TM = <?= json_encode(array_column($themes, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
var BADGES = <?= json_encode($badgeDefs) ?>;
var FAVS = <?= json_encode($favourites) ?>;
var ACTIVE_ID = '<?= htmlspecialchars($savedThemeId) ?>';
var STORE_URL = '<?= addslashes($storeUrl) ?>';
var _filter='all', _search='', _sort='popular';
var _lpCurrentId = null;

/* ════ FILTER / SEARCH / SORT ════ */
function filterBy(cat,btn) {
  _filter=cat;
  document.querySelectorAll('.tm-pill').forEach(function(b){b.classList.remove('active')});
  btn.classList.add('active');
  applyFilters();
}
function liveSearch(q) { _search=q.toLowerCase().trim(); applyFilters(); }
function sortThemes(v) { _sort=v; applyFilters(); }

function applyFilters() {
  var grid=document.getElementById('tmGrid');
  var cards=Array.from(grid.querySelectorAll('.tm-card:not(#tmEmpty)'));
  var vis=[];
  cards.forEach(function(c){
    var cat=c.dataset.category;
    var name=(c.querySelector('.tm-name')||{}).textContent||'';
    var desc=(c.querySelector('.tm-desc')||{}).textContent||'';
    var catMatch=(_filter==='all'||cat===_filter);
    var srchMatch=!_search||(name+' '+desc+' '+cat).toLowerCase().includes(_search);
    if(catMatch&&srchMatch){c.classList.remove('hidden');vis.push(c);}
    else c.classList.add('hidden');
  });
  vis.sort(function(a,b){
    if(_sort==='rating')    return parseFloat(b.dataset.rating)-parseFloat(a.dataset.rating);
    if(_sort==='downloads') return parseInt(b.dataset.downloads||0)-parseInt(a.dataset.downloads||0);
    if(_sort==='newest')    return (b.dataset.new==='1'?1:0)-(a.dataset.new==='1'?1:0);
    var bs=(b.querySelector('.b-ai')?10:0)+(b.querySelector('.b-popular')?5:0)+parseInt(b.dataset.downloads||0)/1000;
    var as=(a.querySelector('.b-ai')?10:0)+(a.querySelector('.b-popular')?5:0)+parseInt(a.dataset.downloads||0)/1000;
    return bs-as;
  });
  vis.forEach(function(c){grid.appendChild(c)});
  document.getElementById('tmCount').textContent=vis.length;
  document.getElementById('tmEmpty').style.display=vis.length===0?'block':'none';
}

/* ════ VIEW TOGGLE ════ */
function setView(v,btn) {
  document.querySelectorAll('.tm-view-btn').forEach(function(b){b.classList.remove('active')});
  btn.classList.add('active');
  document.getElementById('tmGrid').classList.toggle('list-view',v==='list');
}

/* ════ SCROLL & HIGHLIGHT ════ */
function scrollToCard(id) {
  var el=document.getElementById('tmcard-'+id);
  if(!el)return;
  el.scrollIntoView({behavior:'smooth',block:'center'});
  el.style.boxShadow='0 0 0 3px rgba(99,102,241,.6),0 20px 60px rgba(0,0,0,.5)';
  setTimeout(function(){el.style.boxShadow='';},2400);
}

/* ════ INSTALL THEME ════ */
function installTheme(name,id) {
  /* optimistic UI */
  document.querySelectorAll('.tm-ibtn').forEach(function(b){b.className='tm-ibtn install';b.innerHTML='<i class="bi bi-cloud-download-fill"></i> Install Theme'});
  document.querySelectorAll('.tm-card').forEach(function(c){c.classList.remove('is-active')});
  var card=document.getElementById('tmcard-'+id);
  var ibtn=document.getElementById('ibtn-'+id);
  if(card){
    card.classList.add('is-active');
    var ob=card.querySelector('.tm-prev-badge'); if(ob)ob.remove();
    var nb=document.createElement('span');nb.className='tm-prev-badge b-active';nb.innerHTML='<i class="bi bi-check-circle-fill"></i> Active';
    card.querySelector('.tm-prev').appendChild(nb);
  }
  if(ibtn){ibtn.className='tm-ibtn active';ibtn.innerHTML='<i class="bi bi-check-circle-fill"></i> Active Theme';}
  ACTIVE_ID=id;
  sessionStorage.setItem('selectedTheme',name);
  sessionStorage.setItem('selectedThemeId',id||'');
  /* save to DB */
  var fd=new URLSearchParams();
  fd.append('action','save_theme');fd.append('theme_name',name);fd.append('theme_id',id||'');
  fetch(window.location.pathname,{method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
  .then(function(r){return r.json()})
  .then(function(d){
    if(d.success){
      tmToast('✓ Theme "'+name+'" installed!','#34D399','<?= BASE_URL ?>/shop/store_customize.php');
      /* update LP install btn */
      var lbi=document.getElementById('tmLpInstallBtn');
      if(lbi){lbi.innerHTML='<i class="bi bi-check-circle-fill"></i> Active Theme';lbi.style.background='rgba(16,185,129,.15)';lbi.style.color='#34D399';}
      /* record version history */
      var fd2=new URLSearchParams();fd2.append('action','record_history');fd2.append('theme_name',name);fd2.append('action_type','installed');
      fetch(window.location.pathname,{method:'POST',body:fd2.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}});
    }
  }).catch(function(){tmToast('✓ "'+name+'" selected','#34D399')});
  closeDModal();
}

/* ════ ROLLBACK ════ */
function rollbackTheme(prevName) {
  if(!confirm('Roll back to "'+prevName+'"? Your current theme will be preserved for rollback.')) return;
  var fd=new URLSearchParams();fd.append('action','rollback_theme');
  fetch(window.location.pathname,{method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
  .then(function(r){return r.json()})
  .then(function(d){
    if(d.success){tmToast('✓ Rolled back to "'+d.theme+'"','#FBBF24');setTimeout(function(){location.reload();},1500);}
    else tmToast(d.message||'Rollback failed','#ef4444');
  });
}

/* ════ FAVOURITE ════ */
function toggleFav(id) {
  var fd=new URLSearchParams();fd.append('action','toggle_fav');fd.append('theme_id',id);
  fetch(window.location.pathname,{method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
  .then(function(r){return r.json()})
  .then(function(d){
    if(!d.success)return;
    var btn=document.getElementById('fav-'+id);
    var icon=btn?btn.querySelector('i'):null;
    if(d.favourited){
      if(btn)btn.classList.add('on');if(icon)icon.className='bi bi-heart-fill';FAVS.push(id);
      tmToast('❤️ Added to favourites','#ec4899');
    } else {
      if(btn)btn.classList.remove('on');if(icon)icon.className='bi bi-heart';FAVS=FAVS.filter(function(f){return f!==id;});
      tmToast('💔 Removed from favourites','#94a3b8');
    }
    /* update hover fav btn */
    var card=document.getElementById('tmcard-'+id);
    if(card){
      var fb=card.querySelector('.tm-hbtn.fav');
      if(fb){fb.classList.toggle('on',d.favourited);fb.innerHTML='<i class="bi '+(d.favourited?'bi-heart-fill':'bi-heart')+'"></i> '+(d.favourited?'Unfavourite':'Favourite');}
    }
  });
}

/* ════ LIVE PREVIEW MODAL ════ */
var _lpCurrentName='';
function openLpModal(id) {
  var t=TM[id]; if(!t)return;
  _lpCurrentId=id; _lpCurrentName=t.name;
  /* reset loading state */
  document.getElementById('tmLpLoading').style.display='flex';
  document.getElementById('tmLpFrame').src='about:blank';
  /* set labels */
  document.getElementById('tmLpThemeName').textContent=t.name+' — '+t.cat_label;
  document.getElementById('tmLpUrl').textContent=STORE_URL.replace('http://','').replace('https://','')+'&preview_theme='+encodeURIComponent(t.name);
  /* install btn state */
  var lbi=document.getElementById('tmLpInstallBtn');
  var isActive=(ACTIVE_ID===id);
  lbi.innerHTML=isActive?'<i class="bi bi-check-circle-fill"></i> Active Theme':'<i class="bi bi-cloud-download-fill"></i> Install Theme';
  lbi.style.background=isActive?'rgba(16,185,129,.15)':'';
  lbi.style.color=isActive?'#34D399':'';
  lbi.style.boxShadow=isActive?'none':'';
  /* show modal */
  document.getElementById('tmLpModal').classList.add('open');
  document.body.style.overflow='hidden';
  /* load iframe after slight delay */
  setTimeout(function(){
    var url=STORE_URL+'&preview_theme='+encodeURIComponent(t.name)+'&t='+Date.now();
    document.getElementById('tmLpFrame').src=url;
  },200);
}
function lpFrameLoaded() {
  document.getElementById('tmLpLoading').style.display='none';
}
function closeLpModal() {
  document.getElementById('tmLpModal').classList.remove('open');
  document.body.style.overflow='';
  document.getElementById('tmLpFrame').src='about:blank';
}
function lpDevice(dev,btn) {
  document.querySelectorAll('.tm-lp-dev').forEach(function(b){b.classList.remove('active')});
  btn.classList.add('active');
  var frame=document.getElementById('tmLpFrame');
  frame.className='tm-lp-iframe'+(dev!=='desktop'?' '+dev:'');
}
function installFromPreview() {
  if(_lpCurrentId&&_lpCurrentName) installTheme(_lpCurrentName,_lpCurrentId);
}

/* ════ DETAIL MODAL ════ */
function openDModal(id) {
  var t=TM[id]; if(!t)return;
  document.getElementById('tmDModal').classList.add('open');
  document.body.style.overflow='hidden';
  buildDModalPreview(t);
  buildDModalBody(t);
}
function buildDModalPreview(t) {
  var prev=document.getElementById('tmDmPrev');
  /* rebuild bg */
  var bg=prev.querySelector('.dm-bg');if(bg)bg.remove();
  var newbg=document.createElement('div');newbg.className='dm-bg';
  newbg.style.cssText='position:absolute;inset:0;display:flex;flex-direction:column;z-index:0';
  var bars=t.preview_bars||[[t.colors[1],'100%'],[t.colors[0],'60%'],[t.accent,'20%']];
  bars.forEach(function(b){var d=document.createElement('div');d.style.cssText='background:'+b[0]+';height:'+b[1]+';flex-shrink:0';newbg.appendChild(d)});
  var fill=document.createElement('div');fill.style.cssText='background:'+bars[0][0]+';flex:1';newbg.appendChild(fill);
  prev.appendChild(newbg);
  /* chrome */
  var chrome=prev.querySelector('.dm-chrome');if(chrome)chrome.remove();
  var dc=document.createElement('div');dc.className='dm-chrome';
  dc.style.cssText='position:absolute;top:0;left:0;right:0;z-index:6;height:26px;background:rgba(0,0,0,.55);backdrop-filter:blur(5px);border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:5px;padding:0 10px';
  dc.innerHTML='<div style="width:8px;height:8px;border-radius:50%;background:#ff5f57"></div><div style="width:8px;height:8px;border-radius:50%;background:#ffbd2e"></div><div style="width:8px;height:8px;border-radius:50%;background:#28c840"></div><div style="flex:1;height:11px;border-radius:20px;background:rgba(255,255,255,.07);margin:0 10px;display:flex;align-items:center;justify-content:center;font-size:.62rem;color:rgba(255,255,255,.3)">'+STORE_URL.replace('http://','')+'</div>';
  prev.appendChild(dc);
  /* UI layer */
  var ul=prev.querySelector('.dm-ui');if(ul)ul.remove();
  var nui=document.createElement('div');nui.className='dm-ui';
  nui.style.cssText='position:absolute;inset:0;z-index:3;padding:30px 16px 12px;display:flex;flex-direction:column;gap:7px';
  var nav='<div style="display:flex;align-items:center;gap:5px;height:12px"><div style="height:10px;width:55px;border-radius:4px;background:'+t.accent+';opacity:.9"></div><div style="flex:1;height:4px;border-radius:2px;background:rgba(255,255,255,.1)"></div><div style="width:50px;height:8px;border-radius:4px;background:'+t.accent+';opacity:.55"></div></div>';
  var hero='<div style="display:flex;flex-direction:column;gap:5px;padding:10px 4px"><div style="height:13px;width:52%;border-radius:5px;background:rgba(255,255,255,.85)"></div><div style="height:7px;width:38%;border-radius:3px;background:rgba(255,255,255,.4)"></div><div style="height:10px;width:24%;border-radius:5px;background:'+t.accent+';margin-top:5px"></div></div>';
  var prods='<div style="display:flex;gap:7px;margin-top:auto">';
  for(var i=0;i<4;i++){prods+='<div style="flex:1;border-radius:8px;background:rgba(255,255,255,'+(0.05+i*0.01)+');overflow:hidden"><div style="height:40px;background:linear-gradient(135deg,rgba(255,255,255,.1),rgba(255,255,255,.04))"></div><div style="height:4px;margin:4px 4px 2px;border-radius:2px;background:rgba(255,255,255,.2)"></div><div style="height:5px;margin:0 4px 5px;border-radius:2px;background:'+t.accent+';opacity:.85"></div></div>';}
  prods+='</div>';
  nui.innerHTML=nav+hero+prods;
  prev.appendChild(nui);
}
function buildDModalBody(t) {
  var isSaved=(ACTIVE_ID===t.id);
  var stars='';for(var s=0;s<5;s++)stars+='<i class="bi '+(s<Math.floor(t.rating)?'bi-star-fill':'bi-star')+'" style="color:'+(s<Math.floor(t.rating)?'#F59E0B':'rgba(255,255,255,.18)')+'"></i>';
  var bHtml=t.badges.map(function(b){var bd=BADGES[b];if(!bd)return'';return'<span class="tm-qbadge" style="background:'+bd.bg+';color:'+bd.color+';border:1px solid '+bd.color+'33"><i class="bi '+bd.icon+'"></i>'+bd.label+'</span>';}).join('');
  var fHtml=t.features.map(function(f){return'<span class="tm-fpill"><i class="bi bi-check-lg"></i>'+f+'</span>';}).join('');
  var swHtml=t.colors.map(function(c){return'<div class="tm-swatch" style="background:'+c+';width:18px;height:18px" title="'+c+'"></div>';}).join('');
  var tagHtml=t.tags.map(function(tg){return'<span style="font-size:.68rem;font-weight:700;padding:.16rem .5rem;border-radius:20px;background:rgba(99,102,241,.1);color:#a5b4fc;border:1px solid rgba(99,102,241,.16)">'+tg+'</span>';}).join('');
  var html='<div class="tm-dm-title">'+t.name+'</div>';
  html+='<div class="tm-dm-sub">'+t.description+'</div>';
  html+='<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.7rem"><div style="display:flex;gap:.1rem">'+stars+'</div><span style="font-size:.85rem;font-weight:800;color:#fff">'+t.rating+'</span><span style="font-size:.73rem;color:var(--tm-text2)">('+t.reviews+' reviews)</span></div>';
  html+='<div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.7rem">'+bHtml+'</div>';
  html+='<div style="display:flex;gap:.28rem;margin-bottom:.65rem">'+swHtml+'</div>';
  html+='<div style="display:flex;flex-wrap:wrap;gap:.32rem;margin-bottom:1rem">'+tagHtml+'</div>';
  html+='<div class="tm-dm-grid">';
  html+='<div class="tm-info-block"><div class="tm-ib-title">Theme Info</div>';
  html+='<div class="tm-ib-row"><span class="tm-ib-k">Developer</span><span class="tm-ib-v">'+t.developer+'</span></div>';
  html+='<div class="tm-ib-row"><span class="tm-ib-k">Version</span><span class="tm-ib-v">v'+t.version+'</span></div>';
  html+='<div class="tm-ib-row"><span class="tm-ib-k">Downloads</span><span class="tm-ib-v">'+t.downloads+'</span></div>';
  html+='<div class="tm-ib-row"><span class="tm-ib-k">Last Updated</span><span class="tm-ib-v">'+t.last_updated+'</span></div>';
  html+='<div class="tm-ib-row"><span class="tm-ib-k">Industry</span><span class="tm-ib-v">'+t.industry+'</span></div>';
  html+='</div>';
  html+='<div class="tm-info-block"><div class="tm-ib-title">Built-in Features</div><div class="tm-fpills">'+fHtml+'</div></div>';
  html+='</div>';
  var iBtnClass=isSaved?'tm-dm-btn outline':'tm-dm-btn primary';
  var iLabel=isSaved?'<i class="bi bi-check-circle-fill"></i> Active Theme':'<i class="bi bi-cloud-download-fill"></i> Install Theme';
  html+='<div class="tm-dm-cta">';
  html+='<button class="'+iBtnClass+'" onclick="installTheme(\''+t.name.replace(/'/g,"\\'")+'\',\''+t.id+'\')">'+iLabel+'</button>';
  html+='<button class="tm-dm-btn outline" onclick="closeDModal();setTimeout(function(){openLpModal(\''+t.id+'\')},150)"><i class="bi bi-play-circle-fill"></i> Live Preview</button>';
  html+='<a href="<?= BASE_URL ?>/shop/store_customize.php" class="tm-dm-btn outline"><i class="bi bi-sliders"></i> Customizer</a>';
  html+='</div>';
  document.getElementById('tmDmBody').innerHTML=html;
}
function closeDModal() {
  document.getElementById('tmDModal').classList.remove('open');
  document.body.style.overflow='';
}

/* ════ TOAST ════ */
function tmToast(msg,color,link) {
  var t=document.createElement('div');t.className='tm-toast';
  t.style.borderLeft='3px solid '+color;
  var c='<i class="bi bi-check-circle-fill" style="color:'+color+';font-size:.95rem;flex-shrink:0"></i><span style="color:'+color+'">'+msg+'</span>';
  if(link)c+='<a href="'+link+'" style="color:rgba(255,255,255,.45);text-decoration:none;font-size:.7rem;margin-left:.3rem;white-space:nowrap">Go →</a>';
  t.innerHTML=c;document.body.appendChild(t);
  setTimeout(function(){t.style.opacity='0';t.style.transition='opacity .28s';setTimeout(function(){t.remove()},280);},3500);
}

/* ════ KEYBOARD ════ */
document.addEventListener('keydown',function(e){
  if(e.key==='Escape'){closeDModal();closeLpModal();closeVhModal();}
});

/* ════ DUPLICATE THEME ════ */
function duplicateTheme(name,id) {
  var fd=new URLSearchParams();
  fd.append('action','duplicate_theme');fd.append('theme_name',name);fd.append('theme_id',id||'');
  fetch(window.location.pathname,{method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
  .then(function(r){return r.json()})
  .then(function(d){
    if(d.success){tmToast('📋 Duplicated as "'+d.name+'"','#A78BFA');}
    else tmToast('Duplicate failed','#ef4444');
  });
}

/* ════ DELETE THEME ════ */
function deleteTheme(id,name) {
  if(!confirm('Delete custom theme "'+name+'"? This cannot be undone.')) return;
  var fd=new URLSearchParams();
  fd.append('action','delete_theme');fd.append('theme_id',id);
  fetch(window.location.pathname,{method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
  .then(function(r){return r.json()})
  .then(function(d){
    if(d.success){tmToast('🗑️ Theme deleted','#94a3b8');var card=document.getElementById('tmcard-'+id);if(card)card.remove();}
    else tmToast('Delete failed','#ef4444');
  });
}

/* ════ EXPORT THEME ════ */
function exportTheme(name) {
  var fd=new URLSearchParams();fd.append('action','export_theme');fd.append('theme_name',name||'');
  fetch(window.location.pathname,{method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
  .then(function(r){return r.json()})
  .then(function(d){
    if(d.success){
      var blob=new Blob([JSON.stringify(d.data,null,2)],{type:'application/json'});
      var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=d.filename;
      document.body.appendChild(a);a.click();a.remove();
      tmToast('📥 Theme exported!','#34D399');
    } else tmToast('Export failed','#ef4444');
  });
}

/* ════ IMPORT THEME ════ */
function importTheme() {
  var input=document.createElement('input');input.type='file';input.accept='.json,application/json';
  input.onchange=function(e){
    var file=e.target.files[0];if(!file)return;
    var reader=new FileReader();
    reader.onload=function(ev){
      var fd=new URLSearchParams();fd.append('action','import_theme');fd.append('json_data',ev.target.result);
      fetch(window.location.pathname,{method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
      .then(function(r){return r.json()})
      .then(function(d){
        if(d.success){tmToast('📤 Theme "'+d.theme+'" imported!','#34D399');setTimeout(function(){location.reload();},1800);}
        else tmToast(d.message||'Import failed','#ef4444');
      });
    };
    reader.readAsText(file);
  };
  input.click();
}

/* ════ THEME BACKUP ════ */
function backupTheme() {
  var fd=new URLSearchParams();fd.append('action','backup_theme');
  fetch(window.location.pathname,{method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
  .then(function(r){return r.json()})
  .then(function(d){
    if(d.success){tmToast('💾 "'+d.theme+'" backed up ('+d.backup_count+' backups saved)','#38BDF8');}
    else tmToast('Backup failed','#ef4444');
  });
}

/* ════ VERSION HISTORY & BACKUPS MODAL ════ */
function openVhModal() {
  document.getElementById('tmVhModal').classList.add('open');
  document.body.style.overflow='hidden';
  loadVersionHistory();
}
function closeVhModal() {
  document.getElementById('tmVhModal').classList.remove('open');
  document.body.style.overflow='';
}
function loadVersionHistory() {
  var body=document.getElementById('tmVhBody');
  body.innerHTML='<div style="text-align:center;padding:2rem;color:rgba(255,255,255,.4)"><i class="bi bi-hourglass-split" style="font-size:1.8rem"></i><br>Loading...</div>';
  var fd=new URLSearchParams();fd.append('action','get_version_history');
  fetch(window.location.pathname,{method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
  .then(function(r){return r.json()})
  .then(function(d){
    if(!d.success){body.innerHTML='<div style="padding:1.5rem;color:rgba(255,255,255,.4)">Failed to load history.</div>';return;}
    var html='';
    /* ── Backups section ── */
    html+='<div class="tm-vh-section"><div class="tm-vh-sec-title"><i class="bi bi-hdd-stack-fill"></i> Theme Backups <span class="tm-vh-badge">'+(d.backups.length)+'</span></div>';
    if(!d.backups.length){html+='<div class="tm-vh-empty">No backups yet. Click "Backup Theme" to save a restore point.</div>';}
    else{
      d.backups.forEach(function(bk){
        html+='<div class="tm-vh-row">';
        html+='<div class="tm-vh-row-icon" style="background:rgba(56,189,248,.12);color:#38BDF8"><i class="bi bi-cloud-check-fill"></i></div>';
        html+='<div class="tm-vh-row-info"><span class="tm-vh-theme">'+bk.theme+'</span><span class="tm-vh-ts">'+bk.created_at+'</span></div>';
        html+='<button class="tm-vh-act-btn" onclick="restoreBackup(\''+bk.id+'\',\''+bk.theme+'\')"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>';
        html+='</div>';
      });
    }
    html+='</div>';
    /* ── Version history section ── */
    html+='<div class="tm-vh-section"><div class="tm-vh-sec-title"><i class="bi bi-clock-history"></i> Theme Switch History <span class="tm-vh-badge">'+(d.history.length)+'</span></div>';
    if(!d.history.length){html+='<div class="tm-vh-empty">No theme switches recorded yet.</div>';}
    else{
      var actionIcons={activated:'bi-check-circle-fill',installed:'bi-cloud-download-fill',imported:'bi-box-arrow-in-down',rollback:'bi-arrow-counterclockwise',restored:'bi-clock-history'};
      var actionColors={activated:'#34D399',installed:'#38BDF8',imported:'#A78BFA',rollback:'#FBBF24',restored:'#F9A8D4'};
      d.history.forEach(function(h){
        var ico=actionIcons[h.action]||'bi-circle-fill';
        var col=actionColors[h.action]||'#94a3b8';
        html+='<div class="tm-vh-row">';
        html+='<div class="tm-vh-row-icon" style="background:'+col+'1a;color:'+col+'"><i class="bi '+ico+'"></i></div>';
        html+='<div class="tm-vh-row-info"><span class="tm-vh-theme">'+h.theme+'</span><span class="tm-vh-action" style="color:'+col+'">'+h.action+'</span><span class="tm-vh-ts">'+h.ts+'</span></div>';
        html+='</div>';
      });
    }
    html+='</div>';
    body.innerHTML=html;
  }).catch(function(){body.innerHTML='<div style="padding:1.5rem;color:rgba(255,255,255,.4)">Error loading history.</div>';});
}

/* Restore from backup */
function restoreBackup(id,name) {
  if(!confirm('Restore backup "'+name+'"? Your current theme will be saved as rollback.')) return;
  var fd=new URLSearchParams();fd.append('action','restore_backup');fd.append('backup_id',id);
  fetch(window.location.pathname,{method:'POST',body:fd.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}})
  .then(function(r){return r.json()})
  .then(function(d){
    if(d.success){tmToast('✓ Restored to "'+d.theme+'"','#38BDF8');setTimeout(function(){location.reload();},1800);}
    else tmToast(d.message||'Restore failed','#ef4444');
  });
}
</script>

<?php shopFooter(); ?>

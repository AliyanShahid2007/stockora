<?php
// ============================================================
// Stockora POS Pro — Demo Data Seeder (MySQL)
// Usage: php seed.php
// ============================================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

echo "Checking existing data...\n";
$existing = (int)$db->query("SELECT COUNT(*) FROM shops")->fetchColumn();
if ($existing > 0) {
    echo "Data already exists ({$existing} shops). Skipping seed.\n";
    exit(0);
}

echo "Seeding demo data...\n";

$db->beginTransaction();
try {

// ── Super Admin (password: admin123) ──────────────────────
$db->exec("INSERT IGNORE INTO admins (name, email, password, role)
           VALUES ('Super Admin','admin@stockora.com',
                   '\$2y\$12\$v2taboyhS2u40wpyqaGalOgL7IHVR8XgZTznKEPpHVLljljmjNF1K',
                   'superadmin')");
$adminId = $db->query("SELECT id FROM admins WHERE email='admin@stockora.com'")->fetchColumn();

// ── Shop 1 ─────────────────────────────────────────────────
$db->prepare("INSERT INTO shops (name, owner_name, email, phone, address, city, status)
              VALUES ('Ahmed General Store','Muhammad Ahmed','ahmed@demo.com',
                      '0301-1234567','Shop # 12, Main Market, Gulberg','Lahore','active')")->execute();
$shop1 = (int)$db->lastInsertId();

$db->prepare("INSERT INTO users (shop_id, name, email, password, role, status)
              VALUES (?,?,?,?,'owner','active')")
   ->execute([$shop1, 'Muhammad Ahmed', 'ahmed@demo.com', password_hash('demo123', PASSWORD_BCRYPT)]);

$db->prepare("INSERT INTO subscriptions (shop_id, plan_name, months, amount, start_date, end_date, status, payment_method, created_by)
              VALUES (?, '3 Months', 3, 28500, DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active', 'cash', ?)")
   ->execute([$shop1, $adminId]);
$sub1 = (int)$db->lastInsertId();
$db->prepare("INSERT INTO payments (shop_id, subscription_id, amount, payment_date, payment_method, status, created_by)
              VALUES (?, ?, 28500, DATE_SUB(CURDATE(), INTERVAL 60 DAY), 'cash', 'completed', ?)")
   ->execute([$shop1, $sub1, $adminId]);

// ── Shop 2 ─────────────────────────────────────────────────
$db->prepare("INSERT INTO shops (name, owner_name, email, phone, address, city, status)
              VALUES ('Karachi Super Mart','Ali Hassan','ali@demo.com',
                      '0321-9876543','Block 5, Clifton','Karachi','active')")->execute();
$shop2 = (int)$db->lastInsertId();

$db->prepare("INSERT INTO users (shop_id, name, email, password, role, status)
              VALUES (?,?,?,?,'owner','active')")
   ->execute([$shop2, 'Ali Hassan', 'ali@demo.com', password_hash('demo123', PASSWORD_BCRYPT)]);

$db->prepare("INSERT INTO subscriptions (shop_id, plan_name, months, amount, start_date, end_date, status, payment_method, created_by)
              VALUES (?, '1 Month', 1, 10000, DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 'active', 'bank', ?)")
   ->execute([$shop2, $adminId]);
$sub2 = (int)$db->lastInsertId();
$db->prepare("INSERT INTO payments (shop_id, subscription_id, amount, payment_date, payment_method, status, created_by)
              VALUES (?, ?, 10000, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'bank', 'completed', ?)")
   ->execute([$shop2, $sub2, $adminId]);

// ── Categories (Shop 1) ────────────────────────────────────
$cats = ['Grocery','Beverages','Dairy','Detergent','Snacks','Electronics','Medicines'];
foreach ($cats as $c) {
    $db->prepare("INSERT INTO categories (shop_id, name) VALUES (?,?)")->execute([$shop1, $c]);
}
$catMap = [];
foreach ($db->query("SELECT id, name FROM categories WHERE shop_id={$shop1}")->fetchAll() as $r) {
    $catMap[$r['name']] = $r['id'];
}

// ── Products (Shop 1) ──────────────────────────────────────
// [name, category, company_price, retail_price, wholesale_price, stock, min_alert, unit]
$products = [
    ['Rice Basmati 1kg',        'Grocery',     200, 280, 260, 150, 20, 'kg'],
    ['Atta 5kg (Sunridge)',      'Grocery',     380, 450, 420, 100, 15, 'bag'],
    ['Sugar 1kg',               'Grocery',     110, 140, 130, 200, 30, 'kg'],
    ['Dal Chana 1kg',           'Grocery',     160, 200, 185,  80, 10, 'kg'],
    ['Cooking Oil 1L (Dalda)',  'Grocery',     280, 330, 310, 120, 20, 'ltr'],
    ['Salt Iodized 800g',       'Grocery',      35,  50,  45, 300, 50, 'pcs'],
    ['Coca Cola 500ml',         'Beverages',    55,  75,  65, 200, 30, 'pcs'],
    ['Pepsi 1.5L',              'Beverages',    95, 120, 110, 150, 25, 'pcs'],
    ['Nestle Water 1.5L',       'Beverages',    45,  65,  58, 180, 30, 'pcs'],
    ['Red Bull 250ml',          'Beverages',   100, 130, 120,  60, 10, 'pcs'],
    ['Milk 1L (Milkpak)',       'Dairy',        90, 120, 110, 100, 20, 'ltr'],
    ['Yogurt 400g',             'Dairy',        65,  90,  82,  80, 10, 'pcs'],
    ['Butter 200g (Nurpur)',    'Dairy',       110, 145, 135,  60,  8, 'pcs'],
    ['Surf Excel 500g',         'Detergent',   145, 180, 165, 100, 15, 'pcs'],
    ['Ariel 1kg',               'Detergent',   280, 340, 315,  70, 10, 'pcs'],
    ['Vim Bar 150g',            'Detergent',    28,  40,  36, 200, 30, 'pcs'],
    ['Lays Chips Classic',      'Snacks',       55,  75,  68, 150, 25, 'pcs'],
    ['Kurkure Masala',          'Snacks',       40,  55,  50, 200, 30, 'pcs'],
    ['Biscuits (Sooper)',       'Snacks',       55,  70,  65, 120, 20, 'pcs'],
    ['Panadol 10 tabs',         'Medicines',    25,  35,  32, 100, 15, 'strip'],
    ['USB Cable Type-C',        'Electronics', 120, 180, 160,  30,  5, 'pcs'],
    ['Phone Charger (Fast)',    'Electronics', 200, 280, 260,  20,  3, 'pcs'],
];
$prodIds = [];
$stmtP = $db->prepare("INSERT INTO products (shop_id, category_id, name, company_price, retail_price, wholesale_price, stock_quantity, min_stock_alert, unit)
                       VALUES (?,?,?,?,?,?,?,?,?)");
foreach ($products as $p) {
    $stmtP->execute([$shop1, $catMap[$p[1]] ?? null, $p[0], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7]]);
    $prodIds[] = ['id' => (int)$db->lastInsertId(), 'retail' => $p[3], 'wholesale' => $p[4], 'cost' => $p[2]];
}

// ── Customers & Bulk Buyers ────────────────────────────────
$db->prepare("INSERT INTO customers (shop_id, name, phone, email, total_purchases, visit_count)
              VALUES (?,?,?,?,?,?)")
   ->execute([$shop1, 'Ayesha Bibi',  '0301-5555555', 'ayesha@gmail.com', 15800, 12]);
$cust1 = (int)$db->lastInsertId();

$db->prepare("INSERT INTO customers (shop_id, name, phone, total_purchases, visit_count)
              VALUES (?,?,?,?,?)")
   ->execute([$shop1, 'Rashid Ali', '0312-9999999', 8500, 6]);
$cust2 = (int)$db->lastInsertId();

$db->prepare("INSERT INTO bulk_buyers (shop_id, name, business_name, phone, city, credit_limit, total_purchases)
              VALUES (?,?,?,?,?,?,?)")
   ->execute([$shop1, 'Imran Shah', 'Shah Traders', '0321-7777777', 'Lahore', 50000, 85000]);
$buyer1 = (int)$db->lastInsertId();

// ── Sample Sales ───────────────────────────────────────────
$salesData = [
    [date('Y-m-d H:i:s', strtotime('-2 days')),  'retail',    null,   null,   'Walk-in'],
    [date('Y-m-d H:i:s', strtotime('-1 day')),   'retail',    $cust1, null,   'Ayesha Bibi'],
    [date('Y-m-d H:i:s', strtotime('-5 hours')), 'wholesale', null,   $buyer1,'Imran Shah'],
    [date('Y-m-d H:i:s', strtotime('-2 hours')), 'retail',    null,   null,   'Walk-in'],
    [date('Y-m-d H:i:s', strtotime('-30 minutes')),'retail',  $cust2, null,   'Rashid Ali'],
];

$stmtSale = $db->prepare("INSERT INTO sales (shop_id, invoice_no, sale_type, customer_id, buyer_id, customer_name, subtotal, discount, grand_total, amount_paid, payment_method, payment_status, cashier_id, sale_date)
                           VALUES (?,?,?,?,?,?,0,0,0,0,'cash','paid',?,?)");
$stmtItem = $db->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, company_price, total_price, profit)
                           VALUES (?,?,?,?,?,?,?,?)");
$stmtProdName = $db->prepare("SELECT name FROM products WHERE id=?");

foreach ($salesData as $idx => $s) {
    $invNo = 'INV-' . $shop1 . '-' . str_pad($idx + 1, 6, '0', STR_PAD_LEFT);
    $stmtSale->execute([$shop1, $invNo, $s[1], $s[2], $s[3], $s[4], 1, $s[0]]);
    $saleId = (int)$db->lastInsertId();

    $items = array_slice($prodIds, $idx * 2, 3);
    $subtotal = 0;
    foreach ($items as $p) {
        $qty   = rand(1, 3);
        $price = $s[1] === 'wholesale' ? $p['wholesale'] : $p['retail'];
        $tot   = $price * $qty;
        $prof  = ($price - $p['cost']) * $qty;
        $subtotal += $tot;

        $stmtProdName->execute([$p['id']]);
        $pname = $stmtProdName->fetchColumn();

        $stmtItem->execute([$saleId, $p['id'], $pname, $qty, $price, $p['cost'], $tot, $prof]);
        $db->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id=?")->execute([$qty, $p['id']]);
    }
    $db->prepare("UPDATE sales SET subtotal=?, grand_total=?, amount_paid=? WHERE id=?")->execute([$subtotal, $subtotal, $subtotal, $saleId]);
}

// ── Settings ───────────────────────────────────────────────
$db->prepare("INSERT INTO settings (shop_id, setting_key, setting_value)
              VALUES (?, 'thank_you_msg', 'Thank you for shopping at Ahmed General Store!')")
   ->execute([$shop1]);
$db->prepare("INSERT INTO settings (shop_id, setting_key, setting_value)
              VALUES (?, 'invoice_footer', 'Goods once sold will not be returned. Visit Again!')")
   ->execute([$shop1]);

$db->commit();

echo "\n✅ Demo data seeded successfully!\n";
echo "\n=== Demo Login Credentials ===\n";
echo "Super Admin : admin@stockora.com  / admin123\n";
echo "Shop Owner 1: ahmed@demo.com      / demo123  (Ahmed General Store, Lahore)\n";
echo "Shop Owner 2: ali@demo.com        / demo123  (Karachi Super Mart, Karachi)\n";

} catch (Exception $e) {
    $db->rollback();
    echo "❌ Seed failed: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
// ============================================================
// Stockora POS Pro - Helper Functions
// ============================================================
require_once __DIR__ . '/config.php';

// Session Management
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

function isAdminLoggedIn(): bool {
    startSession();
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_role']);
}

function isShopLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['shop_id']);
}

function requireAdmin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php?role=admin&msg=unauthorized');
        exit;
    }
}

function requireShop(): void {
    if (!isShopLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php?role=shop&msg=unauthorized');
        exit;
    }
    // Check subscription — allow access to subscription page and logout always
    $shopId = (int)$_SESSION['shop_id'];
    $allowed = ['subscription.php', 'logout.php', 'settings.php'];
    if (!in_array(basename($_SERVER['PHP_SELF']), $allowed)) {
        $status = getSubscriptionStatus($shopId);
        if ($status === 'expired' || $status === 'no_subscription') {
            header('Location: ' . BASE_URL . '/shop/subscription.php?msg=expired');
            exit;
        }
    }
}

function getSubscriptionStatus(int $shopId): string {
    $db = getDB();
    // Check if there's any active subscription (status=active AND end_date >= today)
    $stmt = $db->prepare("SELECT status, end_date FROM subscriptions WHERE shop_id = ? AND status = 'active' AND end_date >= ? ORDER BY end_date DESC LIMIT 1");
    $stmt->execute([$shopId, date('Y-m-d')]);
    $sub = $stmt->fetch();
    if ($sub) return 'active';
    // No active sub — check if there was any subscription ever
    $stmt2 = $db->prepare("SELECT id FROM subscriptions WHERE shop_id = ? ORDER BY end_date DESC LIMIT 1");
    $stmt2->execute([$shopId]);
    if (!$stmt2->fetch()) return 'no_subscription';
    return 'expired';
}

/** A free trial has normal core-POS access but excludes premium features. */
function isFreeTrial(int $shopId): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM subscriptions
        WHERE shop_id=? AND status='active' AND start_date<=? AND end_date>=? AND plan_name='Free Trial'
        AND NOT EXISTS (
            SELECT 1 FROM subscriptions paid
            WHERE paid.shop_id=subscriptions.shop_id AND paid.status='active'
              AND paid.start_date<=? AND paid.end_date>=? AND paid.plan_name<>'Free Trial'
        )
        LIMIT 1");
    $today = date('Y-m-d');
    $stmt->execute([$shopId, $today, $today, $today, $today]);
    return (bool)$stmt->fetchColumn();
}

function hasPremiumFeatureAccess(int $shopId): bool {
    return getSubscriptionStatus($shopId) === 'active' && !isFreeTrial($shopId);
}

/** Block direct access to a premium page; navigation-only locks are not sufficient. */
function requirePremiumFeature(int $shopId, string $featureName): void {
    if (hasPremiumFeatureAccess($shopId)) return;
    $message = isFreeTrial($shopId)
        ? $featureName . ' is available on paid plans. Upgrade after your free trial to unlock it.'
        : 'An active paid subscription is required to use ' . $featureName . '.';
    header('Location: ' . BASE_URL . '/shop/subscription.php?msg=' . urlencode($message) . '&type=warning');
    exit;
}

// Password Hashing
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

// Invoice Generation
function generateInvoiceNo(int $shopId): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM sales WHERE shop_id = ?");
    $stmt->execute([$shopId]);
    $count = $stmt->fetch()['cnt'] + 1;
    return 'INV-' . $shopId . '-' . str_pad($count, 6, '0', STR_PAD_LEFT);
}

// Format Currency — no .00 decimals (shows as Rs. 2,200 not Rs. 2,200.00)
function formatCurrency(float $amount): string {
    // Show decimals only if amount has meaningful cents (e.g. 2200.50 → Rs. 2,200.50)
    $rounded = round($amount, 2);
    $int = (int)$rounded;
    if ($rounded == $int) {
        return PKR_SYMBOL . ' ' . number_format($int);
    }
    return PKR_SYMBOL . ' ' . number_format($rounded, 2);
}

// Format Number — no trailing .00
function formatNumber(float $num, int $decimals = 0): string {
    $rounded = round($num, $decimals);
    $int = (int)$rounded;
    if ($decimals === 0 || $rounded == $int) {
        return number_format($int);
    }
    return number_format($rounded, $decimals);
}

// Safe Input
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function safeInt($val): int {
    return (int) filter_var($val, FILTER_SANITIZE_NUMBER_INT);
}

function safeFloat($val): float {
    return (float) filter_var($val, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

/** Create the supplier module tables/columns for installations upgraded from v2.0. */
function ensureSupplierSchema(PDO $db): void {
    static $ready = false;
    if ($ready) return;
    $db->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT(11) NOT NULL AUTO_INCREMENT, shop_id INT(11) NOT NULL,
        name VARCHAR(255) NOT NULL, phone VARCHAR(50) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL, address TEXT DEFAULT NULL,
        opening_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_supplier_shop_name (shop_id,name),
        KEY idx_supplier_shop (shop_id),
        CONSTRAINT fk_supplier_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS supplier_payments (
        id INT(11) NOT NULL AUTO_INCREMENT, shop_id INT(11) NOT NULL, supplier_id INT(11) NOT NULL,
        amount DECIMAL(10,2) NOT NULL, payment_date DATE NOT NULL,
        payment_method VARCHAR(50) NOT NULL DEFAULT 'cash', reference_no VARCHAR(100) DEFAULT NULL,
        notes TEXT DEFAULT NULL, created_by INT(11) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_supplier_payment_shop (shop_id), KEY idx_supplier_payment_supplier (supplier_id),
        CONSTRAINT fk_supplier_payment_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
        CONSTRAINT fk_supplier_payment_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS purchase_invoices (
        id INT(11) NOT NULL AUTO_INCREMENT, shop_id INT(11) NOT NULL, supplier_id INT(11) NOT NULL,
        supplier_name VARCHAR(255) NOT NULL, invoice_no VARCHAR(100) NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL, amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        purchase_date DATE NOT NULL, notes TEXT DEFAULT NULL, created_by INT(11) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_purchase_invoice_shop_no (shop_id,invoice_no),
        KEY idx_purchase_invoice_supplier (supplier_id), KEY idx_purchase_invoice_date (purchase_date),
        CONSTRAINT fk_purchase_invoice_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
        CONSTRAINT fk_purchase_invoice_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $column = $db->query("SHOW COLUMNS FROM purchases LIKE 'supplier_id'")->fetch();
    if (!$column) $db->exec("ALTER TABLE purchases ADD COLUMN supplier_id INT(11) DEFAULT NULL AFTER product_id");
    $column = $db->query("SHOW COLUMNS FROM purchases LIKE 'amount_paid'")->fetch();
    if (!$column) $db->exec("ALTER TABLE purchases ADD COLUMN amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_amount");
    $column = $db->query("SHOW COLUMNS FROM purchases LIKE 'purchase_invoice_id'")->fetch();
    if (!$column) $db->exec("ALTER TABLE purchases ADD COLUMN purchase_invoice_id INT(11) DEFAULT NULL AFTER id");
    $ready = true;
}

// JSON Response
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    echo json_encode($data);
    exit;
}

// Get current shop info
function getCurrentShop(): array {
    startSession();
    if (!isset($_SESSION['shop_id'])) return [];
    $db = getDB();
    $stmt = $db->prepare("SELECT s.*, u.name as owner_name FROM shops s LEFT JOIN users u ON u.shop_id = s.id AND u.role = 'owner' WHERE s.id = ? LIMIT 1");
    $stmt->execute([$_SESSION['shop_id']]);
    return $stmt->fetch() ?: [];
}

// Get shop settings
function getShopSetting(int $shopId, string $key, string $default = ''): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE shop_id = ? AND setting_key = ?");
    $stmt->execute([$shopId, $key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

/** Record that a shop has opened a premium platform feature. */
function trackShopFeatureUsage(int $shopId, string $feature): void {
    if ($shopId <= 0 || !in_array($feature, ['commerce_cloud', 'ai_lab'], true)) return;
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO shop_feature_usage (shop_id, feature_key, first_used_at, last_used_at, use_count)
        VALUES (?, ?, NOW(), NOW(), 1)
        ON DUPLICATE KEY UPDATE last_used_at=NOW(), use_count=use_count+1");
    $stmt->execute([$shopId, $feature]);
}

// Set shop settings
function setShopSetting(int $shopId, string $key, string $value): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (shop_id, setting_key, setting_value)
                          VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                                  updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$shopId, $key, $value]);
}

// Low stock products
function getLowStockProducts(int $shopId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM products WHERE shop_id = ? AND stock_quantity <= min_stock_alert AND status = 'active' ORDER BY stock_quantity ASC");
    $stmt->execute([$shopId]);
    return $stmt->fetchAll();
}

// Dashboard stats for shop
function getShopDashboardStats(int $shopId): array {
    $db = getDB();
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    
    // Today's sales
    $stmt = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as total, COUNT(*) as count FROM sales WHERE shop_id = ? AND DATE(sale_date) = ?");
    $stmt->execute([$shopId, $today]);
    $todaySales = $stmt->fetch();
    
    // Monthly sales
    $stmt = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as total, COUNT(*) as count FROM sales WHERE shop_id = ? AND DATE(sale_date) >= ?");
    $stmt->execute([$shopId, $monthStart]);
    $monthlySales = $stmt->fetch();
    
    // Today's profit
    $stmt = $db->prepare("SELECT COALESCE(SUM(si.profit),0) as profit FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.shop_id = ? AND DATE(s.sale_date) = ?");
    $stmt->execute([$shopId, $today]);
    $todayProfit = $stmt->fetch();
    
    // Monthly profit
    $stmt = $db->prepare("SELECT COALESCE(SUM(si.profit),0) as profit FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.shop_id = ? AND DATE(s.sale_date) >= ?");
    $stmt->execute([$shopId, $monthStart]);
    $monthlyProfit = $stmt->fetch();
    
    // Total products
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE shop_id = ? AND status = 'active'");
    $stmt->execute([$shopId]);
    $totalProducts = $stmt->fetch();
    
    // Low stock count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE shop_id = ? AND stock_quantity <= min_stock_alert AND status = 'active'");
    $stmt->execute([$shopId]);
    $lowStock = $stmt->fetch();
    
    // Total customers
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM customers WHERE shop_id = ?");
    $stmt->execute([$shopId]);
    $totalCustomers = $stmt->fetch();
    
    return [
        'today_sales' => $todaySales['total'],
        'today_count' => $todaySales['count'],
        'monthly_sales' => $monthlySales['total'],
        'monthly_count' => $monthlySales['count'],
        'today_profit' => $todayProfit['profit'],
        'monthly_profit' => $monthlyProfit['profit'],
        'total_products' => $totalProducts['count'],
        'low_stock' => $lowStock['count'],
        'total_customers' => $totalCustomers['count'],
    ];
}

// Admin dashboard stats
function getAdminDashboardStats(): array {
    $db = getDB();
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM shops WHERE status = 'active'");
    $activeShops = $stmt->fetch()['count'];
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM shops");
    $totalShops = $stmt->fetch()['count'];
    
    $stmt = $db->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status = 'completed'");
    $totalRevenue = $stmt->fetch()['total'];
    
    $thisMonth = date('Y-m-01');
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status = 'completed' AND payment_date >= ?");
    $stmt->execute([$thisMonth]);
    $monthlyRevenue = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM subscriptions WHERE status = 'expired'");
    $expiredSubs = $stmt->fetch()['count'];
    
    return [
        'active_shops' => $activeShops,
        'total_shops' => $totalShops,
        'total_revenue' => $totalRevenue,
        'monthly_revenue' => $monthlyRevenue,
        'expired_subscriptions' => $expiredSubs,
    ];
}

// Redirect with message
function redirect(string $url, string $msg = '', string $type = 'success'): void {
    $sep = strpos($url, '?') !== false ? '&' : '?';
    if ($msg) $url .= $sep . 'msg=' . urlencode($msg) . '&type=' . $type;
    // Prepend BASE_URL if path starts with /
    if (str_starts_with($url, '/')) {
        $url = BASE_URL . $url;
    }
    header('Location: ' . $url);
    exit;
}

// Flash message display
function flashMessage(): void {
    if (!isset($_GET['msg'])) return;
    $type = $_GET['type'] ?? 'success';
    $class = $type === 'success' ? 'success' : ($type === 'error' ? 'danger' : 'warning');
    $icon = $type === 'success' ? 'check-circle' : ($type === 'error' ? 'x-circle' : 'exclamation-triangle');
    echo "<div class='alert alert-{$class} alert-dismissible fade show' role='alert'>
        <i class='bi bi-{$icon}-fill me-2'></i>" . htmlspecialchars($_GET['msg']) . "
        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
    </div>";
}

// Upload logo
function uploadLogo(array $file, string $prefix = 'shop'): string|false {
    $allowed = ['jpg','jpeg','png','gif','webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return false;
    if ($file['size'] > 2 * 1024 * 1024) return false;
    
    $filename = $prefix . '_' . time() . '_' . uniqid() . '.' . $ext;
    $uploadDir = UPLOAD_PATH;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        return $filename;
    }
    return false;
}

// Get recent sales chart data (last 7 days)
function getSalesChartData(int $shopId): array {
    $db = getDB();
    $data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $stmt = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as total FROM sales WHERE shop_id = ? AND DATE(sale_date) = ?");
        $stmt->execute([$shopId, $date]);
        $row = $stmt->fetch();
        $data[] = ['date' => date('D', strtotime($date)), 'total' => (float)$row['total']];
    }
    return $data;
}

// ============================================================
// AUTOPILOT INSIGHT ENGINE — analyzes all business data and
// returns an array of categorised insight objects
// ============================================================
function getAutopilotInsights(int $shopId): array {
    $db     = getDB();
    $today  = date('Y-m-d');
    $insights = [];

    // ── helpers ────────────────────────────────────────────
    $q = function(string $sql, array $p = []) use ($db) {
        $s = $db->prepare($sql); $s->execute($p); return $s;
    };

    // ── 1. SALES TREND (last 30d vs prev 30d) ──────────────
    $cur30  = (float)$q("SELECT COALESCE(SUM(grand_total),0) t FROM sales WHERE shop_id=? AND sale_date >= DATE_SUB(?,INTERVAL 30 DAY)", [$shopId,$today])->fetch()['t'];
    $prev30 = (float)$q("SELECT COALESCE(SUM(grand_total),0) t FROM sales WHERE shop_id=? AND sale_date >= DATE_SUB(?,INTERVAL 60 DAY) AND sale_date < DATE_SUB(?,INTERVAL 30 DAY)", [$shopId,$today,$today])->fetch()['t'];
    if ($prev30 > 0) {
        $pct = (($cur30 - $prev30) / $prev30) * 100;
        if ($pct >= 15) {
            $insights[] = ['type'=>'opportunity','icon'=>'rocket-takeoff','color'=>'#28c76f','title'=>'Sales Momentum','text'=>'Sales jumped '.round($pct).'% compared to previous 30 days. This is a strong growth signal — capitalise it by ensuring top products are well-stocked.','priority'=>1];
        } elseif ($pct <= -15) {
            $insights[] = ['type'=>'risk','icon'=>'graph-down-arrow','color'=>'#ea5455','title'=>'Sales Decline Alert','text'=>'Sales dropped '.round(abs($pct)).'% vs previous 30 days. Investigate whether low stock, pricing, or reduced footfall is the cause.','priority'=>1];
        } elseif ($pct > 0) {
            $insights[] = ['type'=>'info','icon'=>'arrow-up-circle','color'=>'#00cfe8','title'=>'Steady Growth','text'=>'Sales are up '.round($pct).'% — business is growing moderately. Keep the current strategy and look for new product opportunities.','priority'=>3];
        } else {
            $insights[] = ['type'=>'warning','icon'=>'dash-circle','color'=>'#ff9f43','title'=>'Flat Sales','text'=>'Sales performance is similar to last period. Consider promotions, new products, or revisiting your pricing to stimulate growth.','priority'=>2];
        }
    } elseif ($cur30 > 0) {
        $insights[] = ['type'=>'info','icon'=>'stars','color'=>'#6C63FF','title'=>'Business Is Active','text'=>'Sales are running this month. Start tracking previous periods for trend comparisons as data accumulates.','priority'=>4];
    }

    // ── 2. PROFIT MARGIN HEALTH ─────────────────────────────
    $profitData = $q("SELECT COALESCE(SUM(si.profit),0) prof, COALESCE(SUM(si.total_price),0) rev FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.shop_id=? AND s.sale_date >= DATE_SUB(?,INTERVAL 30 DAY)", [$shopId,$today])->fetch();
    $profitCur  = (float)$profitData['prof'];
    $revCur     = (float)$profitData['rev'];
    $marginCur  = $revCur > 0 ? ($profitCur / $revCur * 100) : 0;
    $profitPrev = (float)$q("SELECT COALESCE(SUM(si.profit),0) p FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.shop_id=? AND s.sale_date >= DATE_SUB(?,INTERVAL 60 DAY) AND s.sale_date < DATE_SUB(?,INTERVAL 30 DAY)", [$shopId,$today,$today])->fetch()['p'];
    $revPrev    = (float)$q("SELECT COALESCE(SUM(si.total_price),0) r FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.shop_id=? AND s.sale_date >= DATE_SUB(?,INTERVAL 60 DAY) AND s.sale_date < DATE_SUB(?,INTERVAL 30 DAY)", [$shopId,$today,$today])->fetch()['r'];
    $marginPrev = $revPrev > 0 ? ($profitPrev / $revPrev * 100) : 0;

    if ($revCur > 0) {
        if ($marginCur < 10) {
            $insights[] = ['type'=>'risk','icon'=>'exclamation-octagon','color'=>'#ea5455','title'=>'Critical: Low Profit Margin','text'=>'Current profit margin is only '.round($marginCur,1).'%. Costs may be too high or prices too low. Review your product pricing immediately to protect the business.','priority'=>1];
        } elseif ($marginCur < 20 && $marginPrev > $marginCur) {
            $insights[] = ['type'=>'warning','icon'=>'arrow-down-circle','color'=>'#ff9f43','title'=>'Profit Margin Shrinking','text'=>'Sales are stable but margin dropped from '.round($marginPrev,1).'% to '.round($marginCur,1).'%. Low-margin products may be diluting overall profitability.','priority'=>1];
        } elseif ($marginCur >= 30) {
            $insights[] = ['type'=>'opportunity','icon'=>'gem','color'=>'#28c76f','title'=>'Excellent Profit Margin','text'=>'Business is operating at a healthy '.round($marginCur,1).'% margin. Focus on scaling sales volume to maximise profits further.','priority'=>3];
        } elseif ($marginPrev > 0 && $marginCur > $marginPrev + 5) {
            $insights[] = ['type'=>'opportunity','icon'=>'graph-up','color'=>'#28c76f','title'=>'Margin Improving','text'=>'Profit margin improved from '.round($marginPrev,1).'% to '.round($marginCur,1).'%. Your product mix or pricing adjustments are working well.','priority'=>3];
        }
    }

    // ── 3. EXPENSE BEHAVIOUR ────────────────────────────────
    $expCur  = (float)$q("SELECT COALESCE(SUM(amount),0) t FROM expenses WHERE shop_id=? AND expense_date >= DATE_SUB(?,INTERVAL 30 DAY)", [$shopId,$today])->fetch()['t'];
    $expPrev = (float)$q("SELECT COALESCE(SUM(amount),0) t FROM expenses WHERE shop_id=? AND expense_date >= DATE_SUB(?,INTERVAL 60 DAY) AND expense_date < DATE_SUB(?,INTERVAL 30 DAY)", [$shopId,$today,$today])->fetch()['t'];
    if ($expPrev > 0 && $expCur > 0) {
        $expPct = (($expCur - $expPrev) / $expPrev) * 100;
        if ($expPct > 25) {
            $insights[] = ['type'=>'risk','icon'=>'wallet2','color'=>'#ea5455','title'=>'Expenses Rising Fast','text'=>'Operating expenses increased by '.round($expPct).'% this period. Review your cost categories and cut unnecessary spending to protect profit.','priority'=>1];
        } elseif ($expPct < -20) {
            $insights[] = ['type'=>'opportunity','icon'=>'piggy-bank','color'=>'#28c76f','title'=>'Cost Efficiency Improving','text'=>'Expenses reduced by '.round(abs($expPct)).'% — excellent cost management. Redirect saved resources into marketing or stock investment.','priority'=>3];
        }
        // Expense to revenue ratio
        if ($revCur > 0) {
            $expRatio = ($expCur / $revCur) * 100;
            if ($expRatio > 40) {
                $insights[] = ['type'=>'risk','icon'=>'cash-stack','color'=>'#ea5455','title'=>'High Expense-to-Revenue Ratio','text'=>'Expenses represent '.round($expRatio).'% of revenue. This leaves little room for profit. Target expense ratio below 30% of revenue.','priority'=>2];
            }
        }
    }

    // ── 4. STOCK / INVENTORY RISK ───────────────────────────
    $outOfStock  = (int)$q("SELECT COUNT(*) c FROM products WHERE shop_id=? AND stock_quantity<=0 AND status='active'", [$shopId])->fetch()['c'];
    $lowStock    = (int)$q("SELECT COUNT(*) c FROM products WHERE shop_id=? AND stock_quantity>0 AND stock_quantity<=min_stock_alert AND status='active'", [$shopId])->fetch()['c'];
    $totalActive = (int)$q("SELECT COUNT(*) c FROM products WHERE shop_id=? AND status='active'", [$shopId])->fetch()['c'];

    if ($outOfStock > 0) {
        $insights[] = ['type'=>'risk','icon'=>'x-circle','color'=>'#ea5455','title'=>$outOfStock.' Products Out of Stock','text'=>$outOfStock.' active product'.($outOfStock>1?'s are':' is').' completely out of stock. You are losing sales on these items right now. Restock immediately.','priority'=>1];
    }
    if ($lowStock > 0) {
        $insights[] = ['type'=>'warning','icon'=>'exclamation-triangle','color'=>'#ff9f43','title'=>$lowStock.' Products Running Low','text'=>$lowStock.' product'.($lowStock>1?'s are':' is').' approaching reorder level. Place purchase orders now to avoid stockout situations.','priority'=>2];
    }
    if ($totalActive > 0) {
        $deadStock = (int)$q("SELECT COUNT(*) c FROM products p WHERE p.shop_id=? AND p.status='active' AND p.stock_quantity > 0 AND NOT EXISTS (SELECT 1 FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.product_id=p.id AND s.sale_date >= DATE_SUB(?,INTERVAL 60 DAY))", [$shopId,$today])->fetch()['c'];
        if ($deadStock > 0) {
            $deadPct = round($deadStock / $totalActive * 100);
            $insights[] = ['type'=>'warning','icon'=>'archive','color'=>'#ff9f43','title'=>$deadStock.' Unsold Products (60 days)','text'=>$deadPct.'% of your product range has not sold in 60 days. Consider promotions, bundle offers, or discontinuing these items to free up capital.','priority'=>2];
        }
    }

    // ── 5. CUSTOMER CREDIT RISK ─────────────────────────────
    $totalDues = (float)$q("SELECT COALESCE(SUM(cc.amount),0) t FROM customer_credit cc WHERE cc.shop_id=? AND cc.type='credit'", [$shopId])->fetch()['t'];
    $totalPaid = (float)$q("SELECT COALESCE(SUM(cc.amount),0) t FROM customer_credit cc WHERE cc.shop_id=? AND cc.type='payment'", [$shopId])->fetch()['t'];
    $netDues   = $totalDues - $totalPaid;
    if ($netDues > 0 && $revCur > 0 && ($netDues / $revCur) > 0.3) {
        $insights[] = ['type'=>'risk','icon'=>'person-exclamation','color'=>'#ea5455','title'=>'High Outstanding Credit','text'=>'Customer credit dues ('.formatCurrency($netDues).') represent more than 30% of monthly revenue. Follow up with customers to collect outstanding payments.','priority'=>1];
    } elseif ($netDues > 0) {
        $insights[] = ['type'=>'warning','icon'=>'person-badge','color'=>'#ff9f43','title'=>'Pending Credit Dues','text'=>formatCurrency($netDues).' outstanding in customer credit. Regular collection will improve your cash flow significantly.','priority'=>3];
    }

    // ── 6. TOP OPPORTUNITY ──────────────────────────────────
    $topCat = $q("SELECT c.name, SUM(si.total_price) rev, SUM(si.profit) prof FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id LEFT JOIN categories c ON c.id=p.category_id WHERE s.shop_id=? AND s.sale_date >= DATE_SUB(?,INTERVAL 30 DAY) GROUP BY p.category_id ORDER BY rev DESC LIMIT 1", [$shopId,$today])->fetch();
    if ($topCat && $topCat['name']) {
        $catMargin = $topCat['rev'] > 0 ? round($topCat['prof'] / $topCat['rev'] * 100, 1) : 0;
        $insights[] = ['type'=>'opportunity','icon'=>'star','color'=>'#6C63FF','title'=>'Best Category: '.$topCat['name'],'text'=>'"'.$topCat['name'].'" is your highest revenue category this month ('.formatCurrency($topCat['rev']).', '.$catMargin.'% margin). Expand this category for more growth.','priority'=>3];
    }

    // ── 7. DAILY TARGET PERFORMANCE ────────────────────────
    $targetVal = (float)getShopSetting($shopId, 'daily_target', '0');
    if ($targetVal > 0) {
        $todaySales = (float)$q("SELECT COALESCE(SUM(grand_total),0) t FROM sales WHERE shop_id=? AND DATE(sale_date)=?", [$shopId,$today])->fetch()['t'];
        $pct = ($todaySales / $targetVal) * 100;
        if ($pct >= 100) {
            $insights[] = ['type'=>'opportunity','icon'=>'trophy','color'=>'#28c76f','title'=>'Daily Target Achieved! 🎉','text'=>'You have hit '.round($pct).'% of today\'s sales target ('.formatCurrency($todaySales).' / '.formatCurrency($targetVal).'). Great performance — push for more!','priority'=>2];
        } elseif ($pct < 50 && date('H') >= 16) {
            $insights[] = ['type'=>'warning','icon'=>'bullseye','color'=>'#ff9f43','title'=>'Behind Daily Target','text'=>'Only '.round($pct).'% of daily target achieved with limited time left today. Focus on closing more sales before end of day.','priority'=>2];
        }
    }

    // sort by priority (lower = more important)
    usort($insights, fn($a,$b) => $a['priority'] <=> $b['priority']);
    return $insights;
}

// ============================================================
// PRODUCT ENGINE — scores every product as WINNING or LOSING
// Returns ['winners'=>[], 'losers'=>[], 'summary'=>[]]
// ============================================================
function getProductEngineData(int $shopId): array {
    $db   = getDB();
    $today = date('Y-m-d');

    // Pull all active products with 30-day + 7-day sales stats
    $stmt = $db->prepare("
        SELECT
            p.id, p.name, p.stock_quantity, p.retail_price, p.company_price,
            p.min_stock_alert, p.unit,
            c.name AS category_name,
            COALESCE(s30.qty,0)   AS qty_30d,
            COALESCE(s30.rev,0)   AS rev_30d,
            COALESCE(s30.prof,0)  AS prof_30d,
            COALESCE(s30.txns,0)  AS txns_30d,
            COALESCE(s7.qty,0)    AS qty_7d,
            COALESCE(s7.rev,0)    AS rev_7d
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN (
            SELECT si.product_id,
                   SUM(si.quantity)    qty,
                   SUM(si.total_price) rev,
                   SUM(si.profit)      prof,
                   COUNT(DISTINCT s.id) txns
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            WHERE s.shop_id = ? AND s.sale_date >= DATE_SUB(?,INTERVAL 30 DAY)
            GROUP BY si.product_id
        ) s30 ON s30.product_id = p.id
        LEFT JOIN (
            SELECT si.product_id,
                   SUM(si.quantity)    qty,
                   SUM(si.total_price) rev
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            WHERE s.shop_id = ? AND s.sale_date >= DATE_SUB(?,INTERVAL 7 DAY)
            GROUP BY si.product_id
        ) s7 ON s7.product_id = p.id
        WHERE p.shop_id = ? AND p.status = 'active'
        ORDER BY rev_30d DESC
    ");
    $stmt->execute([$shopId,$today,$shopId,$today,$shopId]);
    $products = $stmt->fetchAll();

    if (empty($products)) return ['winners'=>[],'losers'=>[],'summary'=>['total'=>0,'winners'=>0,'losers'=>0,'neutral'=>0]];

    // Compute score per product (0–100)
    $maxRev = max(array_column($products,'rev_30d')) ?: 1;
    $maxQty = max(array_column($products,'qty_30d')) ?: 1;

    $scored = [];
    foreach ($products as $p) {
        $margin = $p['retail_price'] > 0
            ? (($p['retail_price'] - $p['company_price']) / $p['retail_price'] * 100)
            : 0;
        // Composite score: 40% revenue share + 30% frequency + 20% margin + 10% recent velocity
        $safeMaxRev    = $maxRev  > 0 ? $maxRev  : 1;
        $safeMaxQty    = $maxQty  > 0 ? $maxQty  : 1;
        $revScore      = ((float)$p['rev_30d'] / $safeMaxRev) * 40;
        $freqScore     = ((float)$p['qty_30d'] / $safeMaxQty) * 30;
        $marginScore   = $margin != 0 ? min($margin / 40, 1) * 20 : 0;
        $qty30f        = (float)$p['qty_30d'];
        $qty30div4     = $qty30f > 0 ? $qty30f / 4 : 0;
        $velocityScore = $qty30div4 > 0 ? (min((float)$p['qty_7d'] / $qty30div4, 1.5)) * 10 : 0;
        $score         = $revScore + $freqScore + $marginScore + $velocityScore;

        // Tags
        $tags = [];
        if ($p['qty_30d'] > 0 && $p['qty_7d'] > ($p['qty_30d']/4 * 1.3)) $tags[] = ['label'=>'Trending Up','color'=>'#28c76f'];
        if ($p['stock_quantity'] <= 0)       $tags[] = ['label'=>'Out of Stock','color'=>'#ea5455'];
        elseif ($p['stock_quantity'] <= $p['min_stock_alert']) $tags[] = ['label'=>'Low Stock','color'=>'#ff9f43'];
        if ($margin < 5 && $p['rev_30d'] > 0)  $tags[] = ['label'=>'Low Margin','color'=>'#ea5455'];
        if ($margin >= 35)                      $tags[] = ['label'=>'High Margin','color'=>'#28c76f'];
        if ($p['qty_30d'] === 0)                $tags[] = ['label'=>'No Sales','color'=>'#6c757d'];
        if ($p['txns_30d'] >= 10)               $tags[] = ['label'=>'High Demand','color'=>'#6C63FF'];

        $scored[] = array_merge($p, [
            'score'      => round($score, 1),
            'margin_pct' => round($margin, 1),
            'tags'       => $tags,
        ]);
    }

    // Winners: score >= 35 OR top 20% by revenue with decent margin
    $threshold = max(25, count($scored) > 5 ? 30 : 20);
    $winners = array_filter($scored, fn($p) => $p['score'] >= $threshold && $p['qty_30d'] > 0);
    $losers  = array_filter($scored, fn($p) => ($p['qty_30d'] === 0 && $p['stock_quantity'] > 0)
                                             || ($p['score'] < 10 && $p['rev_30d'] < ($maxRev * 0.02)));

    // Sort winners by score desc, losers by score asc (worst first)
    usort($winners, fn($a,$b) => $b['score'] <=> $a['score']);
    usort($losers,  fn($a,$b) => $a['score'] <=> $b['score']);

    $wArr = array_values(array_slice($winners, 0, 8));
    $lArr = array_values(array_slice($losers,  0, 8));

    return [
        'winners' => $wArr,
        'losers'  => $lArr,
        'summary' => [
            'total'   => count($scored),
            'winners' => count($winners),
            'losers'  => count($losers),
            'neutral' => count($scored) - count($winners) - count($losers),
        ],
        'all_scored' => $scored,
    ];
}

// ── Brand Score ─────────────────────────────────────────────
function parseCSV(string $content): array {
    $rows = [];
    $lines = explode("\n", trim($content));
    $headers = str_getcsv(array_shift($lines));
    $headers = array_map('trim', $headers);
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        $values = str_getcsv($line);
        if (count($values) !== count($headers)) continue;
        $rows[] = array_combine($headers, array_map('trim', $values));
    }
    return $rows;
}

// ── Brand Score ─────────────────────────────────────────────
function getBrandScore(int $shopId): array {
    $db    = getDB();
    $today = date('Y-m-d');

    $q = function(string $sql, array $params = []) use ($db) {
        $st = $db->prepare($sql); $st->execute($params); return $st->fetchColumn();
    };

    // 1. Sales consistency (days with sales in last 7 days)
    $daysWithSales = (int)$q(
        "SELECT COUNT(DISTINCT DATE(sale_date)) FROM sales
         WHERE shop_id=? AND DATE(sale_date) >= DATE_SUB(?,INTERVAL 7 DAY)",
        [$shopId,$today]);
    $salesConsistency = round(($daysWithSales / 7) * 100);

    // 2. Profit margin health
    $rev30 = (float)$q(
        "SELECT COALESCE(SUM(si.total_price),0) FROM sale_items si
         JOIN sales s ON s.id=si.sale_id
         WHERE s.shop_id=? AND DATE(s.sale_date)>=DATE_SUB(?,INTERVAL 30 DAY)",
        [$shopId,$today]);
    $profit30 = (float)$q(
        "SELECT COALESCE(SUM(si.profit),0) FROM sale_items si
         JOIN sales s ON s.id=si.sale_id
         WHERE s.shop_id=? AND DATE(s.sale_date)>=DATE_SUB(?,INTERVAL 30 DAY)",
        [$shopId,$today]);
    $marginPct   = $rev30 > 0 ? ($profit30 / $rev30 * 100) : 0;
    $marginScore = min(100, round($marginPct / 35 * 100));

    // 3. Stock health
    $totalProds = (int)$q("SELECT COUNT(*) FROM products WHERE shop_id=? AND status='active'",[$shopId]);
    $lowStock   = (int)$q(
        "SELECT COUNT(*) FROM products
         WHERE shop_id=? AND status='active' AND stock_quantity <= min_stock_alert AND stock_quantity > 0",
        [$shopId]);
    $outStock   = (int)$q(
        "SELECT COUNT(*) FROM products WHERE shop_id=? AND status='active' AND stock_quantity <= 0",
        [$shopId]);
    $stockScore = $totalProds > 0
        ? round((($totalProds - $lowStock - $outStock) / $totalProds) * 100)
        : 50;

    // 4. Expense control
    $exp30 = (float)$q(
        "SELECT COALESCE(SUM(amount),0) FROM expenses
         WHERE shop_id=? AND DATE(expense_date)>=DATE_SUB(?,INTERVAL 30 DAY)",
        [$shopId,$today]);
    $expRatio     = $rev30 > 0 ? ($exp30 / $rev30 * 100) : 0;
    $expenseScore = $expRatio === 0.0 ? 70 : max(0, min(100, round((1 - $expRatio/60) * 100)));

    // 5. Customer loyalty
    $totalCust = (int)$q(
        "SELECT COUNT(DISTINCT customer_id) FROM sales
         WHERE shop_id=? AND customer_id IS NOT NULL
           AND DATE(sale_date)>=DATE_SUB(?,INTERVAL 30 DAY)",
        [$shopId,$today]);
    $repeatCust = (int)$q(
        "SELECT COUNT(*) FROM (
            SELECT customer_id FROM sales
            WHERE shop_id=? AND customer_id IS NOT NULL
              AND DATE(sale_date)>=DATE_SUB(?,INTERVAL 30 DAY)
            GROUP BY customer_id HAVING COUNT(*)>1
         ) t",
        [$shopId,$today]);
    $custScore = $totalCust > 0 ? min(100, round($repeatCust / $totalCust * 100) + 30) : 40;

    // Weighted total
    $brandScore = round(
        $salesConsistency * 0.25 +
        $marginScore      * 0.30 +
        $stockScore       * 0.20 +
        $expenseScore     * 0.15 +
        $custScore        * 0.10
    );

    if ($brandScore >= 80)     { $grade='A+'; $color='#28c76f'; $label='Excellent'; }
    elseif ($brandScore >= 65) { $grade='A';  $color='#3ECFCF'; $label='Strong';    }
    elseif ($brandScore >= 50) { $grade='B';  $color='#6C63FF'; $label='Good';      }
    elseif ($brandScore >= 35) { $grade='C';  $color='#ff9f43'; $label='Average';   }
    else                       { $grade='D';  $color='#ea5455'; $label='Needs Work';}

    return [
        'score' => $brandScore,
        'grade' => $grade,
        'color' => $color,
        'label' => $label,
        'breakdown' => [
            ['label'=>'Sales Consistency','score'=>$salesConsistency,'icon'=>'calendar-check', 'color'=>'#6C63FF'],
            ['label'=>'Profit Margin',    'score'=>$marginScore,     'icon'=>'graph-up-arrow', 'color'=>'#28c76f'],
            ['label'=>'Stock Health',     'score'=>$stockScore,      'icon'=>'boxes',          'color'=>'#3ECFCF'],
            ['label'=>'Expense Control',  'score'=>$expenseScore,    'icon'=>'wallet2',        'color'=>'#ff9f43'],
            ['label'=>'Customer Loyalty', 'score'=>$custScore,       'icon'=>'people-fill',    'color'=>'#00cfe8'],
        ],
        'meta' => [
            'margin_pct'      => round($marginPct,1),
            'days_with_sales' => $daysWithSales,
            'out_of_stock'    => $outStock,
            'low_stock'       => $lowStock,
            'exp_ratio'       => round($expRatio,1),
            'repeat_cust'     => $repeatCust,
        ],
    ];
}

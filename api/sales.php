<?php
require_once '../includes/functions.php';
startSession();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!isShopLoggedIn()) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

$shopId = (int)$_SESSION['shop_id'];
$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || empty($data['items'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid sale data']);
    }
    
    // Validate items
    $items = $data['items'];
    $saleType = in_array($data['sale_type'] ?? 'retail', ['retail','wholesale']) ? $data['sale_type'] : 'retail';
    $buyerId = safeInt($data['buyer_id'] ?? 0);
    $totalQty = array_sum(array_map(fn($item) => (int)($item['quantity'] ?? 0), $items));
    $wholesaleApplies = false;
    $buyerDiscount = 0.0;

    if ($saleType === 'wholesale' && $buyerId) {
        $buyerStmt = $db->prepare("SELECT min_qty_wholesale, wholesale_discount FROM bulk_buyers WHERE id=? AND shop_id=? AND status='active'");
        $buyerStmt->execute([$buyerId, $shopId]);
        $buyer = $buyerStmt->fetch();
        if (!$buyer) {
            jsonResponse(['success' => false, 'error' => 'Selected bulk buyer is not valid']);
        }
        $wholesaleApplies = $totalQty >= (int)$buyer['min_qty_wholesale'];
        $buyerDiscount = $wholesaleApplies ? (float)$buyer['wholesale_discount'] : 0.0;
    }

    $productsById = [];
    foreach ($items as $item) {
        if (empty($item['product_id']) || empty($item['quantity']) || $item['quantity'] <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid item data']);
        }
        // Check stock
        $stmt = $db->prepare("SELECT stock_quantity, retail_price, wholesale_price, company_price FROM products WHERE id=? AND shop_id=?");
        $stmt->execute([$item['product_id'], $shopId]);
        $product = $stmt->fetch();
        if (!$product || $product['stock_quantity'] < $item['quantity']) {
            jsonResponse(['success' => false, 'error' => "Insufficient stock for: {$item['product_name']}"]);
        }
        $productsById[(int)$item['product_id']] = $product;
    }

    // Always calculate prices on the server. Wholesale rates only apply when this
    // buyer's configured minimum total cart quantity has been reached.
    $subtotal = 0.0;
    foreach ($items as &$item) {
        $product = $productsById[(int)$item['product_id']];
        $unitPrice = $wholesaleApplies ? (float)$product['wholesale_price'] : (float)$product['retail_price'];
        $item['unit_price'] = $unitPrice;
        $item['company_price'] = (float)$product['company_price'];
        $item['total_price'] = $unitPrice * (int)$item['quantity'];
        $subtotal += $item['total_price'];
    }
    unset($item);
    // Record the actual applied rate, not merely the POS toggle selection.
    $saleType = $wholesaleApplies ? 'wholesale' : 'retail';

    $discountType = ($data['discount_type'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
    $manualInput = max(0, (float)($data['manual_discount'] ?? 0));
    $manualDiscount = $discountType === 'percent' ? ($subtotal * $manualInput / 100) : min($manualInput, $subtotal);
    $buyerDiscountAmount = $subtotal * $buyerDiscount / 100;
    $discountAmount = min($subtotal, $manualDiscount + $buyerDiscountAmount);
    $grandTotal = max(0, $subtotal - $discountAmount);
    $amountPaid = max(0, (float)($data['amount_paid'] ?? 0));
    $changeAmount = max(0, $amountPaid - $grandTotal);
    
    $db->beginTransaction();
    try {
        $invoiceNo = generateInvoiceNo($shopId);
        $payStatus = $amountPaid >= $grandTotal ? 'paid' : 'partial';
        $payMethod = in_array($data['payment_method'] ?? 'cash', ['cash','card','online','credit']) ? $data['payment_method'] : 'cash';
        
        // Insert sale
        $stmt = $db->prepare("INSERT INTO sales (shop_id, invoice_no, sale_type, customer_id, buyer_id, customer_name, subtotal, discount, discount_type, grand_total, amount_paid, change_amount, payment_method, payment_status, cashier_id, sale_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([
            $shopId, $invoiceNo, $saleType,
            $data['customer_id'] ?: null,
            $buyerId ?: null,
            $data['customer_name'] ?? 'Walk-in',
            $subtotal,
            $discountAmount,
            $discountType,
            $grandTotal,
            $amountPaid,
            $changeAmount,
            $payMethod, $payStatus,
            $_SESSION['user_id']
        ]);
        $saleId = $db->lastInsertId();
        
        // Insert sale items & update stock
        $savedItems = [];
        foreach ($items as $item) {
            $profit = ($item['unit_price'] - ($item['company_price'] ?? 0)) * $item['quantity'];
            
            $stmt = $db->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, company_price, total_price, profit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $saleId, $item['product_id'], $item['product_name'],
                $item['quantity'], $item['unit_price'], $item['company_price'] ?? 0,
                $item['total_price'], $profit
            ]);
            
            // Update stock
            $db->prepare("UPDATE products SET stock_quantity = stock_quantity - ?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND shop_id=?")->execute([$item['quantity'], $item['product_id'], $shopId]);
            
            // Stock movement
            $stmt2 = $db->prepare("SELECT stock_quantity FROM products WHERE id=?");
            $stmt2->execute([$item['product_id']]);
            $newStock = $stmt2->fetch()['stock_quantity'];
            
            $db->prepare("INSERT INTO stock_movements (shop_id, product_id, movement_type, quantity, after_quantity, reference_id, created_by) VALUES (?, ?, 'sale', ?, ?, ?, ?)")->execute([$shopId, $item['product_id'], -$item['quantity'], $newStock, $saleId, $_SESSION['user_id']]);
            
            $savedItems[] = $item;
        }
        
        // Update customer stats
        if (!empty($data['customer_id'])) {
            $db->prepare("UPDATE customers SET total_purchases = total_purchases + ?, visit_count = visit_count + 1 WHERE id=? AND shop_id=?")->execute([$grandTotal, $data['customer_id'], $shopId]);
        }
        if (!empty($data['buyer_id'])) {
            $db->prepare("UPDATE bulk_buyers SET total_purchases = total_purchases + ? WHERE id=? AND shop_id=?")->execute([$grandTotal, $buyerId, $shopId]);
        }
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'sale' => [
                'id' => $saleId,
                'invoice_no' => $invoiceNo,
                'sale_type' => $saleType,
                'customer_name' => $data['customer_name'] ?? 'Walk-in',
                'sale_date' => date('Y-m-d H:i:s'),
                'items' => $savedItems,
                'subtotal' => $subtotal,
                'discount' => $discountAmount,
                'grand_total' => $grandTotal,
                'amount_paid' => $amountPaid,
                'change_amount' => $changeAmount,
                'payment_method' => $payMethod
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

if ($method === 'GET') {
    $saleId = safeInt($_GET['id'] ?? 0);
    if ($saleId) {
        $stmt = $db->prepare("SELECT s.*, si.product_name, si.quantity, si.unit_price, si.total_price FROM sales s JOIN sale_items si ON si.sale_id=s.id WHERE s.id=? AND s.shop_id=?");
        $stmt->execute([$saleId, $shopId]);
        jsonResponse(['success' => true, 'sale' => $stmt->fetchAll()]);
    }
    
    // Sales list
    $page = safeInt($_GET['page'] ?? 1);
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $stmt = $db->prepare("SELECT s.*, COUNT(si.id) as item_count FROM sales s LEFT JOIN sale_items si ON si.sale_id=s.id WHERE s.shop_id=? GROUP BY s.id ORDER BY s.sale_date DESC LIMIT ? OFFSET ?");
    $stmt->execute([$shopId, $limit, $offset]);
    jsonResponse(['success' => true, 'sales' => $stmt->fetchAll()]);
}

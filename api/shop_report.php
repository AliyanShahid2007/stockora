<?php
require_once '../includes/functions.php';
startSession();

// Only allow logged-in shop users
if (!isShopLoggedIn()) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$shopId = (int)$_SESSION['shop_id'];
$from   = $_GET['from'] ?? date('Y-m-01');
$to     = $_GET['to']   ?? date('Y-m-d');

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    jsonResponse(['error' => 'Invalid date format']);
}

$db = getDB();

// Summary
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT s.id) as total_invoices,
        COALESCE(SUM(s.grand_total), 0) as total_sales,
        COALESCE(SUM(si.profit), 0) as total_profit
    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id = s.id
    WHERE s.shop_id = ? AND DATE(s.sale_date) >= ? AND DATE(s.sale_date) <= ?
");
$stmt->execute([$shopId, $from, $to]);
$summary = $stmt->fetch();

$margin = $summary['total_sales'] > 0
    ? round(($summary['total_profit'] / $summary['total_sales']) * 100, 1)
    : 0;

// Daily breakdown
$stmt2 = $db->prepare("
    SELECT 
        DATE(s.sale_date) as date,
        COUNT(DISTINCT s.id) as cnt,
        COALESCE(SUM(s.grand_total), 0) as sales,
        COALESCE(SUM(si.profit), 0) as profit
    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id = s.id
    WHERE s.shop_id = ? AND DATE(s.sale_date) >= ? AND DATE(s.sale_date) <= ?
    GROUP BY DATE(s.sale_date)
    ORDER BY date DESC
    LIMIT 31
");
$stmt2->execute([$shopId, $from, $to]);
$daily = $stmt2->fetchAll();

// Format dates nicely
foreach ($daily as &$d) {
    $d['date'] = date('d M Y', strtotime($d['date']));
    $d['sales']  = round((float)$d['sales'],  2);
    $d['profit'] = round((float)$d['profit'], 2);
    $d['cnt']    = (int)$d['cnt'];
}

jsonResponse([
    'total_invoices' => (int)$summary['total_invoices'],
    'total_sales'    => round((float)$summary['total_sales'],  2),
    'total_profit'   => round((float)$summary['total_profit'], 2),
    'margin'         => $margin,
    'daily'          => $daily,
]);

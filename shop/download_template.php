<?php
require_once '../includes/functions.php';
requireShop();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="stockora_product_template.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['name','category','company_price','retail_price','wholesale_price','stock','barcode','unit','min_alert']);
// Sample data
fputcsv($out, ['Rice Basmati 1kg','Grocery','200','280','250','50','8901234567890','kg','10']);
fputcsv($out, ['Surf Excel 500g','Detergent','120','150','135','30','','pcs','5']);
fputcsv($out, ['Coca Cola 500ml','Beverages','60','80','70','100','5449000000996','pcs','20']);
fputcsv($out, ['Milk 1L','Dairy','90','120','105','25','','ltr','5']);
fclose($out);
exit;

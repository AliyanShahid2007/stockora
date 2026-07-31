<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
// Retrieve import results from session (set after PRG redirect)
$importResults = $_SESSION['import_results'] ?? null;
unset($_SESSION['import_results']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['import_file']['name'])) {
    $file = $_FILES['import_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, ['csv', 'txt'])) {
        redirect('import.php', 'Only CSV files are supported. Please export from Excel as CSV.', 'error');
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        redirect('import.php', 'File too large (max 5MB).', 'error');
    } else {
        $content = file_get_contents($file['tmp_name']);
        // Handle BOM
        $content = ltrim($content, "\xEF\xBB\xBF");
        $rows = parseCSV($content);
        
        $imported = 0;
        $failed = 0;
        $errors = [];
        $duplicates = 0;
        
        // Get existing categories
        $catCache = [];
        $existCats = $db->prepare("SELECT id, name FROM categories WHERE shop_id=?");
        $existCats->execute([$shopId]);
        foreach ($existCats->fetchAll() as $c) { $catCache[strtolower($c['name'])] = $c['id']; }
        
        foreach ($rows as $rowNum => $row) {
            // Column mapping (flexible)
            $name = trim($row['name'] ?? $row['Product Name'] ?? $row['product_name'] ?? $row['Name'] ?? '');
            $category = trim($row['category'] ?? $row['Category'] ?? '');
            $companyPrice = safeFloat($row['company_price'] ?? $row['Company Price'] ?? $row['purchase_price'] ?? 0);
            $retailPrice = safeFloat($row['retail_price'] ?? $row['Retail Price'] ?? $row['price'] ?? 0);
            $wholesalePrice = safeFloat($row['wholesale_price'] ?? $row['Wholesale Price'] ?? $retailPrice * 0.9);
            $stock = safeInt($row['stock'] ?? $row['Stock'] ?? $row['quantity'] ?? $row['Quantity'] ?? 0);
            $barcode = trim($row['barcode'] ?? $row['Barcode'] ?? '');
            $minAlert = safeInt($row['min_alert'] ?? $row['Min Alert'] ?? 5);
            $unit = trim($row['unit'] ?? $row['Unit'] ?? 'pcs');
            
            if (!$name) { $failed++; $errors[] = "Row ".($rowNum+2).": Product name is required"; continue; }
            if ($retailPrice <= 0) { $failed++; $errors[] = "Row ".($rowNum+2).": '$name' - Retail price must be > 0"; continue; }
            
            // Check duplicate
            $dup = $db->prepare("SELECT id FROM products WHERE shop_id=? AND name=?");
            $dup->execute([$shopId, $name]);
            if ($dup->fetch()) {
                $duplicates++;
                // Update existing product
                $db->prepare("UPDATE products SET company_price=?, retail_price=?, wholesale_price=?, stock_quantity=stock_quantity+?, updated_at=CURRENT_TIMESTAMP WHERE shop_id=? AND name=?")
                   ->execute([$companyPrice, $retailPrice, $wholesalePrice, $stock, $shopId, $name]);
                $imported++;
                continue;
            }
            
            // Get or create category
            $catId = null;
            if ($category) {
                $catKey = strtolower($category);
                if (!isset($catCache[$catKey])) {
                    $db->prepare("INSERT IGNORE INTO categories (shop_id, name) VALUES (?,?)")->execute([$shopId, $category]);
                    $catCache[$catKey] = $db->lastInsertId();
                    if (!$catCache[$catKey]) {
                        $c = $db->prepare("SELECT id FROM categories WHERE shop_id=? AND name=?");
                        $c->execute([$shopId, $category]);
                        $catCache[$catKey] = $c->fetch()['id'];
                    }
                }
                $catId = $catCache[$catKey];
            }
            
            try {
                $db->prepare("INSERT INTO products (shop_id, category_id, name, barcode, company_price, retail_price, wholesale_price, stock_quantity, min_stock_alert, unit) VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$shopId, $catId, $name, $barcode, $companyPrice, $retailPrice, $wholesalePrice, $stock, $minAlert, $unit]);
                $imported++;
            } catch (Exception $e) {
                $failed++;
                $errors[] = "Row ".($rowNum+2).": '$name' - " . $e->getMessage();
            }
        }
        
        // Log the import
        $db->prepare("INSERT INTO import_logs (shop_id, file_name, import_type, total_rows, success_rows, failed_rows, error_details, imported_by) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$shopId, $file['name'], 'products', count($rows), $imported, $failed, implode("\n", array_slice($errors,0,10)), $_SESSION['user_id']]);
        
        $importResults = ['imported' => $imported, 'failed' => $failed, 'duplicates' => $duplicates, 'errors' => $errors];
        $_SESSION['import_results'] = $importResults;
        redirect('import.php', "Import complete! {$imported} products imported, {$failed} failed, {$duplicates} duplicates updated.");
    }
}

// Import history
$history = $db->prepare("SELECT * FROM import_logs WHERE shop_id=? ORDER BY created_at DESC LIMIT 10");
$history->execute([$shopId]);
$history = $history->fetchAll();

shopHeader('Import Data', 'import');
?>

<?php flashMessage(); ?>

<?php if ($importResults && !empty($importResults['errors'])): ?>
<div class="alert alert-warning rounded-3">
    <h6><i class="bi bi-exclamation-triangle me-2"></i>Import Errors (first 10):</h6>
    <ul class="mb-0 small">
        <?php foreach (array_slice($importResults['errors'],0,10) as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-upload me-2 text-primary"></i>Import Data</h1>
    <p class="page-subtitle">Bulk import products from Excel/CSV files</p>
</div>

<div class="row g-3">
    <!-- Upload Form -->
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-file-earmark-spreadsheet me-2 text-success"></i>Upload CSV/Excel File</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <!-- Drag & Drop Zone -->
                    <div class="border-2 rounded-3 text-center p-5 mb-3" id="dropZone" 
                         style="border: 2px dashed #dee2e6; cursor:pointer; transition:all 0.3s;"
                         onclick="document.getElementById('importFile').click()"
                         ondragover="event.preventDefault();this.style.borderColor='#6C63FF';this.style.background='#f0f2ff';"
                         ondragleave="this.style.borderColor='#dee2e6';this.style.background='';"
                         ondrop="handleDrop(event)">
                        <i class="bi bi-cloud-upload" style="font-size:3rem;color:#6C63FF;"></i>
                        <h5 class="mt-2 mb-1">Drop CSV file here or click to browse</h5>
                        <p class="text-muted small mb-0">Supports: .csv, .txt (exported from Excel)</p>
                        <div id="fileNameDisplay" class="mt-2 fw-semibold text-primary" style="display:none;"></div>
                    </div>
                    <input type="file" name="import_file" id="importFile" class="d-none" accept=".csv,.txt" onchange="showFileName(this)">
                    
                    <button type="submit" class="btn btn-success w-100" id="importBtn" disabled>
                        <i class="bi bi-upload me-2"></i>Import Products
                    </button>
                </form>
            </div>
        </div>

        <!-- Template Download -->
        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-download me-2 text-primary"></i>Download Import Template</div>
            <div class="card-body">
                <p class="text-muted small mb-3">Download the CSV template and fill in your product data, then upload it above.</p>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr><th>Column</th><th>Required</th><th>Example</th></tr>
                        </thead>
                        <tbody style="font-size:0.82rem;">
                            <tr><td><code>name</code></td><td><span class="badge bg-danger">Required</span></td><td>Rice Basmati 1kg</td></tr>
                            <tr><td><code>category</code></td><td><span class="badge bg-secondary">Optional</span></td><td>Grocery</td></tr>
                            <tr><td><code>company_price</code></td><td><span class="badge bg-secondary">Optional</span></td><td>250</td></tr>
                            <tr><td><code>retail_price</code></td><td><span class="badge bg-danger">Required</span></td><td>350</td></tr>
                            <tr><td><code>wholesale_price</code></td><td><span class="badge bg-secondary">Optional</span></td><td>320</td></tr>
                            <tr><td><code>stock</code></td><td><span class="badge bg-secondary">Optional</span></td><td>100</td></tr>
                            <tr><td><code>barcode</code></td><td><span class="badge bg-secondary">Optional</span></td><td>8901234567890</td></tr>
                            <tr><td><code>unit</code></td><td><span class="badge bg-secondary">Optional</span></td><td>pcs / kg / ltr</td></tr>
                            <tr><td><code>min_alert</code></td><td><span class="badge bg-secondary">Optional</span></td><td>5</td></tr>
                        </tbody>
                    </table>
                </div>
                <a href="<?= BASE_URL ?>/shop/download_template.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Download CSV Template
                </a>
                <small class="text-muted d-block mt-2">💡 Tip: Open in Excel, fill data, then Save As → CSV (Comma Separated Values)</small>
            </div>
        </div>
    </div>

    <!-- Import History -->
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>Import History</div>
            <div class="card-body p-0">
                <?php if ($history): ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($history as $h): ?>
                    <div class="list-group-item py-2 px-3">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold small truncate" style="max-width:180px;"><?= htmlspecialchars($h['file_name']) ?></span>
                            <small class="text-muted"><?= date('d M', strtotime($h['created_at'])) ?></small>
                        </div>
                        <div class="d-flex gap-2 mt-1">
                            <span class="badge bg-success" style="font-size:0.65rem;">✓ <?= $h['success_rows'] ?> ok</span>
                            <?php if ($h['failed_rows'] > 0): ?>
                            <span class="badge bg-danger" style="font-size:0.65rem;">✗ <?= $h['failed_rows'] ?> failed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted">No imports yet</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tips -->
        <div class="card mt-3 border-0" style="background:linear-gradient(135deg,#f0f2ff,#fff);">
            <div class="card-body">
                <h6 class="fw-bold text-primary mb-2"><i class="bi bi-lightbulb me-1"></i>Import Tips</h6>
                <ul class="list-unstyled mb-0 small text-muted">
                    <li class="mb-1">✅ First row must be column headers</li>
                    <li class="mb-1">✅ Duplicate products will be updated</li>
                    <li class="mb-1">✅ Categories created automatically</li>
                    <li class="mb-1">✅ Stock quantities will be added</li>
                    <li class="mb-1">⚠️ Save Excel file as CSV first</li>
                    <li class="mb-1">⚠️ Company price hidden from customers</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function showFileName(input) {
    if (input.files.length > 0) {
        document.getElementById('fileNameDisplay').style.display = '';
        document.getElementById('fileNameDisplay').textContent = '📄 ' + input.files[0].name;
        document.getElementById('importBtn').disabled = false;
        document.getElementById('dropZone').style.borderColor = '#28c76f';
        document.getElementById('dropZone').style.background = '#f0fff4';
    }
}

function handleDrop(e) {
    e.preventDefault();
    const dt = e.dataTransfer;
    const file = dt.files[0];
    const input = document.getElementById('importFile');
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    input.files = dataTransfer.files;
    showFileName(input);
    document.getElementById('dropZone').style.borderColor = '#dee2e6';
    document.getElementById('dropZone').style.background = '';
}
</script>
<?php shopFooter(); ?>

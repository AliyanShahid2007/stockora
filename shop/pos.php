<?php
require_once '../includes/functions.php';
requireShop();

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
$shop = getCurrentShop();

// Load categories and products
$categories = $db->prepare("SELECT * FROM categories WHERE shop_id=? AND status='active' ORDER BY name");
$categories->execute([$shopId]);
$categories = $categories->fetchAll();

$products = $db->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.shop_id=? AND p.status='active' ORDER BY p.name");
$products->execute([$shopId]);
$products = $products->fetchAll();

// Get customers and bulk buyers
$customers = $db->prepare("SELECT * FROM customers WHERE shop_id=? ORDER BY name");
$customers->execute([$shopId]);
$customers = $customers->fetchAll();

$bulkBuyers = $db->prepare("SELECT * FROM bulk_buyers WHERE shop_id=? AND status='active' ORDER BY name");
$bulkBuyers->execute([$shopId]);
$bulkBuyers = $bulkBuyers->fetchAll();

include '../includes/shop_layout.php';
shopHeader('POS Billing', 'pos');
?>

<!-- POS is full page, override padding -->
<style>
.page-content { padding: 0.75rem !important; }
.pos-wrapper { height: calc(100vh - 64px - 1.5rem); }
@media(max-width:991px) { .pos-wrapper { height: auto; } .page-content { padding-bottom: 85px !important; } }

/* ── Bulk discount banner ── */
#bulkDiscountBanner {
  background: linear-gradient(90deg,rgba(14,206,206,.18),rgba(108,99,255,.18));
  border: 1px solid rgba(14,206,206,.35);
  border-radius: 8px;
  padding: 0.45rem 0.75rem;
  font-size: .8rem;
  color: #0ECECE;
  font-weight: 600;
  display: none;
  margin-bottom: 0.5rem;
}
#bulkDiscountBanner.show { display: flex !important; align-items: center; gap: .5rem; }

/* ── Receipt modal — always white bg, black text ── */
#receiptModal#receiptModal .modal-content { background: #fff !important; border-radius: 14px !important; border: none !important; box-shadow: 0 8px 40px rgba(0,0,0,.35) !important; }
#receiptModal .modal-header  { background: #10b981 !important; border-radius: 14px 14px 0 0 !important; }
#receiptModal .modal-header .modal-title,
#receiptModal .modal-header h5 { color: #fff !important; }
#receiptModal .modal-footer  {
  display: grid !important; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .4rem;
  background: #f8f9fa !important; border-top: 1px solid #e9ecef !important;
  border-radius: 0 0 14px 14px !important; padding: .7rem !important;
}
#receiptModal .modal-footer .btn {
  width: 100%; min-width: 0; padding: .48rem .25rem !important;
  font-size: .72rem; line-height: 1.15; white-space: nowrap;
}
#receiptModal .modal-footer .btn-secondary {
  background: #475569 !important; border-color: #475569 !important; color: #fff !important;
}
#receiptModal #receiptContent { background: #fff !important; }
#receiptModal #receiptContent .pos-receipt-paper { background: #fff !important; color: #000 !important; }
#receiptContent *,
#receiptContent .invoice-wrapper,
#receiptContent .invoice-wrapper *,
#receiptContent .invoice-header,
#receiptContent .invoice-shop-name,
#receiptContent .invoice-footer,
#receiptContent p, #receiptContent div,
#receiptContent span, #receiptContent small,
#receiptContent td, #receiptContent th {
  color: #000 !important;
  -webkit-text-fill-color: #000 !important;
  background: transparent !important;
}
#receiptContent .invoice-wrapper { background: #fff !important; }
#receiptContent .invoice-footer { color: #555 !important; -webkit-text-fill-color: #555 !important; }

/* Cart quantity controls — roomy and touch-friendly */
#cartItems .cart-qty-control { gap: .42rem; flex-shrink: 0; }
#cartItems .qty-btn {
  width: 34px; height: 34px; min-width: 34px;
  border-radius: 9px; font-size: 1.1rem; line-height: 1;
}
#cartItems .qty-display {
  width: 56px !important; min-width: 56px; height: 34px;
  padding: .25rem .35rem !important; text-align: center;
  background: rgba(255,255,255,.12) !important;
  color: var(--text,#e8f4f4) !important;
  border: 1px solid rgba(255,255,255,.2) !important;
  font-weight: 800; font-size: .95rem;
  appearance: textfield;
}
#cartItems .qty-display::-webkit-inner-spin-button,
#cartItems .qty-display::-webkit-outer-spin-button { appearance: none; margin: 0; }
@media (max-width: 575.98px) {
  #cartItems .qty-btn { width: 38px; height: 38px; min-width: 38px; }
  #cartItems .qty-display { width: 62px !important; min-width: 62px; height: 38px; }
}
</style>

<div class="pos-wrapper">
    <!-- Left: Products Panel -->
    <div class="pos-products-panel" id="productsPanel">
        <!-- Search Bar -->
        <div class="pos-search-bar">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search text-primary"></i></span>
                        <input type="text" class="form-control" id="productSearch" placeholder="Search products or scan barcode..." autocomplete="off" autofocus>
                        <button class="btn btn-outline-secondary" onclick="clearSearch()" title="Clear"><i class="bi bi-x"></i></button>
                    </div>
                </div>
                <div class="col-auto d-lg-none">
                    <button class="btn btn-primary" onclick="toggleCart()">
                        <i class="bi bi-cart3"></i> <span class="badge" style="background:rgba(255,255,255,.15);color:#3ECFCF;" id="mobileCartCount">0</span>
                    </button>
                </div>
            </div>
            <!-- Sale Type Toggle -->
            <div class="sale-type-toggle mt-2" id="saleTypeToggle">
                <button class="sale-type-btn active" id="retailBtn" onclick="setSaleType('retail')">
                    <i class="bi bi-person me-1"></i>Retail
                </button>
                <button class="sale-type-btn" id="wholesaleBtn" onclick="setSaleType('wholesale')">
                    <i class="bi bi-building me-1"></i>Wholesale
                </button>
            </div>
        </div>

        <!-- Category Pills -->
        <div class="category-pills px-0 mb-2">
            <button class="category-pill active" onclick="filterCategory('all', this)">All</button>
            <?php foreach ($categories as $cat): ?>
            <button class="category-pill" onclick="filterCategory(<?= $cat['id'] ?>, this)" data-id="<?= $cat['id'] ?>">
                <?= htmlspecialchars($cat['name']) ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Products Grid -->
        <div class="product-grid" id="productGrid">
            <?php foreach ($products as $p): ?>
            <div class="product-card <?= $p['stock_quantity'] <= 0 ? 'out-of-stock' : '' ?>"
                 onclick="addToCart(<?= $p['id'] ?>)"
                 data-id="<?= $p['id'] ?>"
                 data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
                 data-retail="<?= $p['retail_price'] ?>"
                 data-wholesale="<?= $p['wholesale_price'] ?>"
                 data-company="<?= $p['company_price'] ?>"
                 data-stock="<?= $p['stock_quantity'] ?>"
                 data-category="<?= $p['category_id'] ?>"
                 data-barcode="<?= htmlspecialchars($p['barcode'] ?? '') ?>">
                <div class="product-card-img">
                    <?php if ($p['image']): ?>
                    <img src="<?= BASE_URL ?>/assets/uploads/<?= htmlspecialchars($p['image']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:10px;" alt="">
                    <?php else: ?>
                    <i class="bi bi-box-seam text-primary"></i>
                    <?php endif; ?>
                </div>
                <div class="product-card-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="product-card-price" id="price_<?= $p['id'] ?>"><?= formatCurrency($p['retail_price']) ?></div>
                <div class="product-card-stock <?= $p['stock_quantity'] <= ($p['min_stock_alert'] ?? 5) ? 'stock-low' : '' ?>">
                    <?php if ($p['stock_quantity'] <= 0): ?>
                    <i class="bi bi-x-circle me-1"></i>Out of Stock
                    <?php else: ?>
                    Stock: <?= $p['stock_quantity'] ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($products)): ?>
            <div class="empty-state col-span-full" style="grid-column: 1/-1; padding:3rem 1rem;">
                <div class="empty-state-icon"><i class="bi bi-box-seam"></i></div>
                <h5>No Products Added</h5>
                <p>Add products to start billing</p>
                <a href="<?= BASE_URL ?>/shop/products.php?action=create" class="btn btn-primary">Add Products</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: Cart Panel -->
    <div class="pos-cart-panel" id="cartPanel">
        <!-- Mobile Cart Toggle -->
        <div class="pos-cart-toggle d-lg-none" onclick="toggleCart()" id="cartToggleBar">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-cart3 text-primary fs-5"></i>
                <span class="fw-bold">Cart (<span id="cartCountBadge">0</span> items)</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="fw-bold text-success" id="mobileCartTotal">Rs. 0</span>
                <i class="bi bi-chevron-up" id="cartChevron"></i>
            </div>
        </div>

        <!-- Cart Header -->
        <div class="cart-header d-none d-lg-flex">
            <div>
                <span class="fw-bold fs-5"><i class="bi bi-cart3 me-2 text-primary"></i>Cart</span>
                <span class="badge bg-primary ms-1" id="cartCountDesktop">0</span>
            </div>
            <button class="btn btn-sm btn-outline-danger" onclick="clearCart()" id="clearCartBtn">
                <i class="bi bi-trash me-1"></i>Clear
            </button>
        </div>

        <!-- Customer Section -->
        <div class="px-3 py-2 border-bottom" id="customerSection">
            <select class="form-select form-select-sm" id="customerSelect">
                <option value="">Walk-in Customer</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" data-name="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?> <?= $c['phone'] ? '('.$c['phone'].')' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm mt-1 d-none" id="buyerSelect">
                <option value="">Select Bulk Buyer</option>
                <?php foreach ($bulkBuyers as $b): ?>
                <option value="<?= $b['id'] ?>" data-name="<?= htmlspecialchars($b['name']) ?>" data-min-qty="<?= (int)$b['min_qty_wholesale'] ?>" data-wholesale-discount="<?= (float)$b['wholesale_discount'] ?>">
                    <?= htmlspecialchars($b['name']) ?> <?= $b['business_name'] ? '- '.$b['business_name'] : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Cart Items -->
        <div class="cart-body" id="cartBody">
            <div class="empty-state py-4" id="emptyCart">
                <div class="empty-state-icon" style="font-size:3rem;opacity:0.2;"><i class="bi bi-cart3"></i></div>
                <p class="text-muted small">Cart is empty.<br>Click products to add</p>
            </div>
            <div id="cartItems"></div>
        </div>

        <!-- Cart Footer -->
        <div class="cart-footer">
            <!-- Bulk Discount Banner -->
            <div id="bulkDiscountBanner">
                <i class="bi bi-tags-fill"></i>
                <span id="bulkBannerText">Bulk discount applied!</span>
            </div>

            <!-- Discount -->
            <div class="d-flex gap-2 mb-2">
                <input type="number" class="form-control form-control-sm" id="discountInput" placeholder="Discount" min="0" oninput="updateTotals()">
                <select class="form-select form-select-sm w-auto" id="discountType" onchange="updateTotals()">
                    <option value="amount">PKR</option>
                    <option value="percent">%</option>
                </select>
            </div>
            
            <!-- Totals -->
            <div class="cart-summary-row">
                <span>Subtotal</span>
                <span id="subtotalDisplay">Rs. 0</span>
            </div>
            <div class="cart-summary-row" id="discountRow" style="display:none;">
                <span>Discount</span>
                <span id="discountDisplay" class="text-danger">-Rs. 0</span>
            </div>
            <div class="cart-total-row">
                <span>Total</span>
                <span id="grandTotalDisplay">Rs. 0</span>
            </div>

            <!-- Payment -->
            <div class="d-flex gap-2 mb-2">
                <div class="flex-fill">
                    <input type="number" class="form-control form-control-sm" id="amountPaidInput" placeholder="Amount Paid" min="0" oninput="updateChange()">
                </div>
                <select class="form-select form-select-sm w-auto" id="payMethodSelect">
                    <option value="cash">💵 Cash</option>
                    <option value="card">💳 Card</option>
                    <option value="online">📱 Online</option>
                    <option value="credit">📋 Credit</option>
                </select>
            </div>
            <div class="cart-summary-row" id="changeRow" style="display:none;">
                <span>Change</span>
                <span id="changeDisplay" class="text-success fw-bold">Rs. 0</span>
            </div>
            
            <button class="btn-checkout" id="checkoutBtn" onclick="processCheckout()" disabled>
                <i class="bi bi-check-circle me-2"></i>Process Sale
                <span id="checkoutTotal"></span>
            </button>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="overflow:hidden;">
            <div class="modal-header no-print">
                <h5 class="modal-title" style="color:#fff!important;"><i class="bi bi-check-circle me-2"></i>Sale Complete!</h5>
            </div>
            <div class="modal-body p-0" id="receiptContent"></div>
            <div class="modal-footer no-print">
                <button class="btn btn-success" onclick="printInvoice()"><i class="bi bi-printer me-1"></i>Print</button>
                <button class="btn btn-outline-success" onclick="shareWhatsApp()" id="waShareBtn"><i class="bi bi-whatsapp me-1"></i>WhatsApp</button>
                <button class="btn btn-secondary" onclick="newSale()"><i class="bi bi-plus me-1"></i>New Sale</button>
            </div>
        </div>
    </div>
</div>

<div id="printArea" style="display:none;"></div>

<script>
// =====================================================
// POS SYSTEM JAVASCRIPT
// =====================================================
const SHOP = {
    id: <?= $shopId ?>,
    name: <?= json_encode($shop['name'] ?? '') ?>,
    phone: <?= json_encode($shop['phone'] ?? '') ?>,
    address: <?= json_encode($shop['address'] ?? '') ?>,
    logo: <?= json_encode(!empty($shop['logo']) ? BASE_URL . '/assets/uploads/' . $shop['logo'] : '') ?>
};

let cart = [];
let saleType = 'retail';
let cartOpen = false;

// =====================================================
// CART MANAGEMENT
// =====================================================
function addToCart(productId) {
    const card = document.querySelector(`.product-card[data-id="${productId}"]`);
    if (!card || card.classList.contains('out-of-stock')) {
        showToast('Product is out of stock!', 'danger');
        return;
    }
    
    const stock = parseInt(card.dataset.stock);
    const existing = cart.find(i => i.id === productId);
    
    if (existing) {
        if (existing.qty >= stock) {
            showToast('Not enough stock!', 'warning');
            return;
        }
        existing.qty++;
    } else {
        cart.push({
            id: productId,
            name: card.dataset.name,
            retailPrice: parseFloat(card.dataset.retail),
            wholesalePrice: parseFloat(card.dataset.wholesale),
            companyPrice: parseFloat(card.dataset.company),
            stock: stock,
            qty: 1,
            discount: 0
        });
    }
    
    card.style.border = '2px solid var(--primary)';
    setTimeout(() => { card.style.border = '2px solid transparent'; }, 200);
    
    renderCart();
    updateTotals();
}

function updateQty(productId, delta) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;
    
    item.qty += delta;
    if (item.qty <= 0) {
        cart = cart.filter(i => i.id !== productId);
    } else if (item.qty > item.stock) {
        item.qty = item.stock;
        showToast('Max stock reached', 'warning');
    }
    
    renderCart();
    updateTotals();
}

function setQty(productId, val) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;
    const qty = Math.max(1, Math.min(parseInt(val) || 1, item.stock));
    item.qty = qty;
    renderCart();
    updateTotals();
}

function removeFromCart(productId) {
    cart = cart.filter(i => i.id !== productId);
    renderCart();
    updateTotals();
}

function clearCart() {
    if (cart.length === 0) return;
    cart = [];
    document.getElementById('discountInput').value = '';
    document.getElementById('amountPaidInput').value = '';
    renderCart();
    updateTotals();
}

function getItemPrice(item) {
    return wholesalePricingApplies() ? item.wholesalePrice : item.retailPrice;
}

function getSelectedBulkBuyer() {
    return document.querySelector('#buyerSelect option:checked');
}

function getCartQuantity() {
    return cart.reduce((sum, item) => sum + item.qty, 0);
}

function wholesalePricingApplies() {
    if (saleType !== 'wholesale') return false;
    const buyer = getSelectedBulkBuyer();
    if (!buyer || !buyer.value) return false;
    return getCartQuantity() >= (parseInt(buyer.dataset.minQty, 10) || 0);
}

function getBuyerWholesaleDiscount() {
    const buyer = getSelectedBulkBuyer();
    return wholesalePricingApplies() ? (parseFloat(buyer?.dataset.wholesaleDiscount) || 0) : 0;
}

function refreshProductPrices() {
    const useWholesale = wholesalePricingApplies();
    document.querySelectorAll('.product-card').forEach(card => {
        const priceEl = document.getElementById(`price_${card.dataset.id}`);
        if (priceEl) priceEl.textContent = 'Rs. ' + fmtNum(useWholesale ? card.dataset.wholesale : card.dataset.retail);
    });
}

function renderCart() {
    const container = document.getElementById('cartItems');
    const emptyState = document.getElementById('emptyCart');
    const count = cart.reduce((s, i) => s + i.qty, 0);
    
    // Update counts
    document.getElementById('cartCountBadge').textContent = cart.length;
    document.getElementById('cartCountDesktop').textContent = cart.length;
    document.getElementById('mobileCartCount').textContent = cart.length;
    document.getElementById('clearCartBtn').disabled = cart.length === 0;
    document.getElementById('checkoutBtn').disabled = cart.length === 0;
    
    if (cart.length === 0) {
        emptyState.style.display = '';
        container.innerHTML = '';
        return;
    }
    emptyState.style.display = 'none';
    
    container.innerHTML = cart.map(item => {
        const price = getItemPrice(item);
        const total = price * item.qty;
        return `
            <div class="cart-item fade-in" id="cartItem_${item.id}">
                <div style="flex:1; min-width:0;">
                    <div class="cart-item-name truncate">${item.name}</div>
                    <div class="cart-item-price">${formatCurrency(price)} each</div>
                </div>
                <div class="cart-qty-control">
                    <button class="qty-btn" onclick="updateQty(${item.id}, -1)">−</button>
                    <input type="number" class="qty-display form-control" value="${item.qty}" min="1" max="${item.stock}"
                           onchange="setQty(${item.id}, this.value)">
                    <button class="qty-btn" onclick="updateQty(${item.id}, 1)">+</button>
                </div>
                <div class="cart-item-total">${formatCurrency(total)}</div>
                <button class="cart-remove-btn" onclick="removeFromCart(${item.id})"><i class="bi bi-x"></i></button>
            </div>
        `;
    }).join('');
}

// =====================================================
// BULK DISCOUNT (min 50 total qty → auto discount)
// =====================================================
function getBulkDiscountAmount(subtotal) {
    return (subtotal * getBuyerWholesaleDiscount()) / 100;
}

function updateTotals() {
    refreshProductPrices();
    const subtotal  = cart.reduce((s, i) => s + getItemPrice(i) * i.qty, 0);
    const totalQty  = cart.reduce((s, i) => s + i.qty, 0);

    // Manual discount from input
    const discountInput = parseFloat(document.getElementById('discountInput').value) || 0;
    const discountType  = document.getElementById('discountType').value;
    let manualDiscount  = discountType === 'percent'
        ? (subtotal * discountInput) / 100
        : Math.min(discountInput, subtotal);

    // Auto bulk discount (50+ qty)
    const bulkDiscount  = getBulkDiscountAmount(subtotal);

    // Total discount = manual + bulk (bulk stacks on top)
    const discountAmount = Math.min(subtotal, manualDiscount + bulkDiscount);
    const grandTotal     = Math.max(0, subtotal - discountAmount);

    // Update banner
    const banner = document.getElementById('bulkDiscountBanner');
    const bannerText = document.getElementById('bulkBannerText');
    if (false) {
        banner.classList.add('show');
        bannerText.textContent = `${BULK_DISC_PCT}% bulk discount applied (${totalQty} items ≥ ${BULK_MIN_QTY})`;
    } else {
        banner.classList.remove('show');
    }

    const buyer = getSelectedBulkBuyer();
    const minQty = parseInt(buyer?.dataset.minQty, 10) || 0;
    const buyerDiscount = getBuyerWholesaleDiscount();
    if (wholesalePricingApplies()) {
        banner.classList.add('show');
        bannerText.textContent = buyerDiscount > 0
            ? `Wholesale price + ${buyerDiscount}% buyer discount applied (${totalQty} items >= ${minQty})`
            : `Wholesale price applied (${totalQty} items >= ${minQty})`;
    } else if (saleType === 'wholesale' && buyer?.value) {
        banner.classList.add('show');
        bannerText.textContent = `Retail price: add ${Math.max(0, minQty - totalQty)} more item(s) to unlock wholesale at ${minQty} items`;
    }

    document.getElementById('subtotalDisplay').textContent = formatCurrency(subtotal);
    document.getElementById('grandTotalDisplay').textContent = formatCurrency(grandTotal);
    document.getElementById('mobileCartTotal').textContent  = formatCurrency(grandTotal);
    document.getElementById('checkoutTotal').textContent    = ' - ' + formatCurrency(grandTotal);

    const discountRow = document.getElementById('discountRow');
    if (discountAmount > 0) {
        discountRow.style.display = '';
        document.getElementById('discountDisplay').textContent = '-' + formatCurrency(discountAmount);
    } else {
        discountRow.style.display = 'none';
    }

    updateChange();
}

function updateChange() {
    const grandTotal = parseFloat(document.getElementById('grandTotalDisplay').textContent.replace(/[^0-9.]/g, '')) || 0;
    const amtPaid = parseFloat(document.getElementById('amountPaidInput').value) || 0;
    const change = amtPaid - grandTotal;
    const changeRow = document.getElementById('changeRow');
    
    if (amtPaid > 0) {
        changeRow.style.display = '';
        const changeEl = document.getElementById('changeDisplay');
        changeEl.textContent = formatCurrency(Math.max(0, change));
        changeEl.style.color = change < 0 ? '#ea5455' : '#28c76f';
    } else {
        changeRow.style.display = 'none';
    }
}

// =====================================================
// SALE TYPE
// =====================================================
function setSaleType(type) {
    saleType = type;
    document.getElementById('retailBtn').classList.toggle('active', type === 'retail');
    document.getElementById('wholesaleBtn').classList.toggle('active', type === 'wholesale');
    
    // Show/hide customer selects
    document.getElementById('customerSelect').classList.toggle('d-none', type === 'wholesale');
    document.getElementById('buyerSelect').classList.toggle('d-none', type === 'retail');
    
    refreshProductPrices();
    renderCart();
    updateTotals();
}

document.getElementById('buyerSelect').addEventListener('change', function() {
    refreshProductPrices();
    renderCart();
    updateTotals();
});

// =====================================================
// SEARCH & FILTER
// =====================================================
document.getElementById('productSearch').addEventListener('input', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('.product-card').forEach(card => {
        const match = card.dataset.name.toLowerCase().includes(val) || 
                      (card.dataset.barcode && card.dataset.barcode.toLowerCase().includes(val));
        card.style.display = match ? '' : 'none';
    });
});

function clearSearch() {
    document.getElementById('productSearch').value = '';
    document.querySelectorAll('.product-card').forEach(c => c.style.display = '');
    document.getElementById('productSearch').focus();
}

function filterCategory(catId, btn) {
    document.querySelectorAll('.category-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    
    document.querySelectorAll('.product-card').forEach(card => {
        if (catId === 'all' || card.dataset.category == catId) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}

// =====================================================
// CHECKOUT
// =====================================================
async function processCheckout() {
    if (cart.length === 0) return;
    
    const btn = document.getElementById('checkoutBtn');
    setLoading(btn, true);
    
    const subtotal = cart.reduce((s, i) => s + getItemPrice(i) * i.qty, 0);
    const discountInput = parseFloat(document.getElementById('discountInput').value) || 0;
    const discountType  = document.getElementById('discountType').value;
    const manualDiscount = discountType === 'percent' ? (subtotal * discountInput / 100) : Math.min(discountInput, subtotal);
    const bulkDiscount   = getBulkDiscountAmount(subtotal);
    const discountAmount = Math.min(subtotal, manualDiscount + bulkDiscount);
    const grandTotal = Math.max(0, subtotal - discountAmount);
    const amountPaid = parseFloat(document.getElementById('amountPaidInput').value) || grandTotal;
    const payMethod = document.getElementById('payMethodSelect').value;
    
    const customerId = document.getElementById('customerSelect').value;
    const buyerId = document.getElementById('buyerSelect').value;
    const customerName = saleType === 'retail' 
        ? (document.querySelector('#customerSelect option:checked')?.dataset?.name || document.getElementById('customerSelect').options[document.getElementById('customerSelect').selectedIndex].text || 'Walk-in')
        : (document.querySelector('#buyerSelect option:checked')?.text || 'Bulk Buyer');
    
    const saleData = {
        shop_id: SHOP.id,
        sale_type: saleType,
        customer_id: customerId || null,
        buyer_id: buyerId || null,
        customer_name: customerId === '' && buyerId === '' ? 'Walk-in' : customerName,
        items: cart.map(i => ({
            product_id: i.id,
            product_name: i.name,
            quantity: i.qty,
            unit_price: getItemPrice(i),
            company_price: i.companyPrice,
            total_price: getItemPrice(i) * i.qty,
            profit: (getItemPrice(i) - i.companyPrice) * i.qty
        })),
        subtotal: subtotal,
        discount: discountAmount,
        manual_discount: discountInput,
        discount_type: discountType,
        grand_total: grandTotal,
        amount_paid: amountPaid,
        change_amount: Math.max(0, amountPaid - grandTotal),
        payment_method: payMethod,
        payment_status: amountPaid >= grandTotal ? 'paid' : 'partial'
    };
    
    const result = await apiCall('<?= BASE_URL ?>/api/sales.php', 'POST', saleData);
    setLoading(btn, false);
    
    if (result.success) {
        showReceipt(result.sale);
    } else {
        showToast(result.error || 'Sale failed!', 'danger');
    }
}

function showReceipt(sale) {
    const logoHtml = SHOP.logo
        ? `<img src="${SHOP.logo}" style="max-width:70px;max-height:70px;margin-bottom:.4rem;border-radius:8px;" alt="">`
        : '';

    // Parse server-side PKT datetime (YYYY-MM-DD HH:MM:SS)
    let saleDateTime = 'N/A';
    if (sale.sale_date) {
        const p = sale.sale_date.match(/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/);
        if (p) {
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const h = parseInt(p[4]), ampm = h >= 12 ? 'PM' : 'AM', h12 = h % 12 || 12;
            saleDateTime = `${parseInt(p[3])} ${months[parseInt(p[2])-1]} ${p[1]}, ${h12}:${p[5]} ${ampm}`;
        }
    }

    // Build items rows
    const items = Array.isArray(sale.items) ? sale.items : [];
    const itemsHtml = items.length
        ? items.map(i => `<tr>
            <td style="color:#000;padding:.3rem .2rem;">${i.product_name||'-'}</td>
            <td style="color:#000;text-align:center;padding:.3rem .2rem;">${i.quantity||0}</td>
            <td style="color:#000;text-align:right;padding:.3rem .2rem;">Rs.${fmtNum(i.unit_price||0)}</td>
            <td style="color:#000;text-align:right;padding:.3rem .2rem;">Rs.${fmtNum(i.total_price||0)}</td>
          </tr>`).join('')
        : `<tr><td colspan="4" style="text-align:center;color:#000;">No items</td></tr>`;

    // Discount label
    const discountLabel = sale.discount > 0
        ? `<div style="display:flex;justify-content:space-between;padding:.15rem 0;color:#c00;">
               <span>Discount:</span><span>-Rs.${fmtNum(sale.discount)}</span>
           </div>` : '';

    // Single clean receipt HTML
    const receiptHtml = `
    <div class="pos-receipt-paper" style="font-family:'Courier New',monospace;font-size:13px;max-width:340px;margin:0 auto;padding:1rem;">

      <div style="text-align:center;padding-bottom:.75rem;border-bottom:2px dashed #bbb;">
        ${logoHtml}
        <div style="font-size:1.15rem;font-weight:900;color:#000;margin-bottom:.2rem;">${SHOP.name}</div>
        ${SHOP.phone ? `<div style="color:#333;font-size:.8rem;">${SHOP.phone}</div>` : ''}
        ${SHOP.address ? `<div style="color:#555;font-size:.72rem;">${SHOP.address}</div>` : ''}
        <div style="margin-top:.5rem;padding-top:.5rem;border-top:1px dashed #ccc;text-align:left;">
          <div style="color:#000;">Invoice: <strong>${sale.invoice_no}</strong></div>
          <div style="color:#000;">Date: ${saleDateTime}</div>
          <div style="color:#000;">Type: ${(sale.sale_type||'retail').toUpperCase()}</div>
          ${sale.customer_name && sale.customer_name!=='Walk-in' ? `<div style="color:#000;">Customer: ${sale.customer_name}</div>` : ''}
        </div>
      </div>

      <table style="width:100%;border-collapse:collapse;margin:.5rem 0;">
        <thead>
          <tr style="border-top:1px dashed #bbb;border-bottom:1px dashed #bbb;">
            <th style="color:#000;padding:.3rem .2rem;font-size:.68rem;text-transform:uppercase;text-align:left;">Item</th>
            <th style="color:#000;padding:.3rem .2rem;font-size:.68rem;text-transform:uppercase;text-align:center;">Qty</th>
            <th style="color:#000;padding:.3rem .2rem;font-size:.68rem;text-transform:uppercase;text-align:right;">Price</th>
            <th style="color:#000;padding:.3rem .2rem;font-size:.68rem;text-transform:uppercase;text-align:right;">Total</th>
          </tr>
        </thead>
        <tbody>${itemsHtml}</tbody>
      </table>

      <div style="border-top:1px dashed #bbb;padding-top:.5rem;margin-top:.25rem;">
        <div style="display:flex;justify-content:space-between;padding:.15rem 0;color:#000;"><span>Subtotal:</span><span>Rs.${fmtNum(sale.subtotal)}</span></div>
        ${discountLabel}
        <div style="display:flex;justify-content:space-between;padding:.35rem 0;font-weight:900;font-size:1rem;border-top:2px solid #333;margin-top:.25rem;color:#000;">
          <span>TOTAL:</span><span>Rs.${fmtNum(sale.grand_total)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;padding:.15rem 0;color:#000;"><span>Paid:</span><span>Rs.${fmtNum(sale.amount_paid)}</span></div>
        ${sale.change_amount > 0 ? `<div style="display:flex;justify-content:space-between;padding:.15rem 0;color:#000;"><span>Change:</span><span>Rs.${fmtNum(sale.change_amount)}</span></div>` : ''}
        <div style="display:flex;justify-content:space-between;padding:.15rem 0;color:#000;"><span>Payment:</span><span>${(sale.payment_method||'cash').toUpperCase()}</span></div>
      </div>

      <div style="text-align:center;padding-top:.75rem;border-top:2px dashed #bbb;margin-top:.5rem;font-size:.75rem;color:#555;">
        <div style="color:#000;font-weight:700;">★ Thank you for your purchase! ★</div>
        <div style="color:#555;">Visit again soon</div>
        <div style="margin-top:.2rem;font-size:.65rem;color:#888;">Powered by Stockora POS Pro</div>
      </div>
    </div>`;

    // ── Inject into modal (single source of truth) ───────────────────────────
    const rc = document.getElementById('receiptContent');
    rc.innerHTML = receiptHtml;
    rc.style.background = '#fff';

    // ── Copy to #printArea for @media print (keeps print black-on-white) ─────
    const pa = document.getElementById('printArea');
    if (pa) { pa.innerHTML = receiptHtml; pa.style.display = 'block'; }

    // WhatsApp share
    document.getElementById('waShareBtn').onclick = () => {
        const msg = `*${SHOP.name}*\nInvoice: ${sale.invoice_no}\nDate: ${saleDateTime}\nTotal: Rs.${fmtNum(sale.grand_total)}\nPaid: Rs.${fmtNum(sale.amount_paid)}\nThank you!`;
        window.open(`https://wa.me/?text=${encodeURIComponent(msg)}`, '_blank');
    };

    // Show modal — destroy any previous instance first to avoid duplicate
    const existingModal = bootstrap.Modal.getInstance(document.getElementById('receiptModal'));
    if (existingModal) existingModal.dispose();
    new bootstrap.Modal(document.getElementById('receiptModal')).show();

    clearCart();
    updateTotals();

    // Update stock counts on product cards
    items.forEach(item => {
        const card = document.querySelector(`.product-card[data-id="${item.product_id}"]`);
        if (!card) return;
        const newStock = parseInt(card.dataset.stock) - parseInt(item.quantity);
        card.dataset.stock = newStock;
        const stockEl = card.querySelector('.product-card-stock');
        if (stockEl) {
            if (newStock <= 0) {
                stockEl.innerHTML = '<i class="bi bi-x-circle me-1"></i>Out of Stock';
                card.classList.add('out-of-stock');
            } else {
                stockEl.textContent = 'Stock: ' + newStock;
            }
        }
    });
}

function newSale() {
    bootstrap.Modal.getInstance(document.getElementById('receiptModal')).hide();
    // Hide printArea again after modal close so it doesn't show on screen
    const pa = document.getElementById('printArea');
    if (pa) pa.style.display = 'none';
    document.getElementById('productSearch').focus();
}

// Mobile cart toggle
function toggleCart() {
    cartOpen = !cartOpen;
    const panel = document.getElementById('cartPanel');
    const chevron = document.getElementById('cartChevron');
    panel.classList.toggle('cart-open', cartOpen);
    if (chevron) chevron.style.transform = cartOpen ? 'rotate(180deg)' : '';
}

// Barcode scanner
enableBarcodeScanner((barcode) => {
    const card = document.querySelector(`.product-card[data-barcode="${barcode}"]`);
    if (card) {
        addToCart(parseInt(card.dataset.id));
        showToast('Barcode scanned: ' + card.dataset.name, 'success', 1500);
    } else {
        document.getElementById('productSearch').value = barcode;
        document.getElementById('productSearch').dispatchEvent(new Event('input'));
    }
});

// Initialize
setSaleType('retail');
updateTotals();
</script>

<?php shopFooter(); ?>

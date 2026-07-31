// =====================================================
// Stockora POS Pro - Main JavaScript v3.0
// Full Mobile Responsive Support
// =====================================================

// ── Sidebar Toggle ──────────────────────────────────
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;
    sidebar.classList.toggle('open');
    overlay.classList.toggle('open');
    // Prevent body scroll when sidebar open on mobile
    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
}

// Use delegated handling as a fallback for sidebar close buttons. This also
// works if an inline click handler is blocked by another page script.
document.addEventListener('click', (event) => {
    if (!event.target.closest('.sidebar-close')) return;
    event.preventDefault();
    event.stopPropagation();
    closeSidebar();
});

// Close sidebar on Escape
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });

// Close sidebar when a nav link is clicked on mobile
document.addEventListener('DOMContentLoaded', () => {
    if (window.innerWidth <= 991) {
        document.querySelectorAll('.nav-item-link').forEach(link => {
            link.addEventListener('click', () => closeSidebar());
        });
    }

    // Swipe-to-open/close sidebar
    initSidebarSwipe();

    // Auto-dismiss alerts after 5s
    setTimeout(() => {
        document.querySelectorAll('.alert-dismissible').forEach(alert => {
            try { new bootstrap.Alert(alert).close(); } catch(e) {}
        });
    }, 5000);

    // Init tooltips (desktop only)
    if (window.innerWidth > 768) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            new bootstrap.Tooltip(el, { trigger: 'hover' });
        });
    }

    // Prevent negative number inputs
    document.addEventListener('input', (e) => {
        if (e.target.type === 'number' && e.target.min === '0') {
            if (parseFloat(e.target.value) < 0) e.target.value = 0;
        }
    });
});

// ── Swipe gesture for sidebar ───────────────────────
function initSidebarSwipe() {
    let touchStartX = 0;
    let touchStartY = 0;

    document.addEventListener('touchstart', (e) => {
        touchStartX = e.changedTouches[0].clientX;
        touchStartY = e.changedTouches[0].clientY;
    }, { passive: true });

    document.addEventListener('touchend', (e) => {
        const dx = e.changedTouches[0].clientX - touchStartX;
        const dy = Math.abs(e.changedTouches[0].clientY - touchStartY);
        // Only horizontal swipes (dx > 60px, dy < 80px)
        if (dy > 80) return;
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        // Swipe right from left edge → open
        if (dx > 60 && touchStartX < 30 && !sidebar.classList.contains('open')) {
            toggleSidebar();
        }
        // Swipe left → close
        if (dx < -60 && sidebar.classList.contains('open')) {
            closeSidebar();
        }
    }, { passive: true });
}

// ── Toast notification ──────────────────────────────
function showToast(message, type = 'success', duration = 3500) {
    const existing = document.getElementById('globalToast');
    if (existing) existing.remove();

    const icons  = { success: 'check-circle-fill', danger: 'x-circle-fill', warning: 'exclamation-triangle-fill', info: 'info-circle-fill' };
    const colors = { success: '#28c76f', danger: '#ea5455', warning: '#ff9f43', info: '#00cfe8' };

    const toast = document.createElement('div');
    toast.id = 'globalToast';

    // On mobile: bottom toast; on desktop: top-right
    const isMobile = window.innerWidth <= 575;
    toast.style.cssText = `
        position: fixed;
        ${isMobile ? 'bottom: 80px; left: .75rem; right: .75rem;' : 'top: 1rem; right: 1rem; min-width: 280px; max-width: 360px;'}
        z-index: 9999;
        background: #162236; color: #e8f4f4; border-radius: 12px; padding: .85rem 1.1rem;
        box-shadow: 0 8px 32px rgba(0,0,0,.22);
        display: flex; align-items: center; gap: .7rem;
        border-left: 4px solid ${colors[type] || colors.success};
        animation: toastSlideIn .3s ease;
    `;

    toast.innerHTML = `
        <i class="bi bi-${icons[type]||icons.success}" style="color:${colors[type]||colors.success};font-size:1.25rem;flex-shrink:0;"></i>
        <span style="flex:1;font-size:.875rem;font-weight:500;">${message}</span>
        <button onclick="this.parentElement.remove()" style="background:none;border:none;color:#999;cursor:pointer;font-size:1.1rem;padding:0;line-height:1;flex-shrink:0;">&times;</button>
    `;

    if (!document.getElementById('toastStyle')) {
        const s = document.createElement('style');
        s.id = 'toastStyle';
        s.textContent = `@keyframes toastSlideIn {
            from { transform: ${isMobile ? 'translateY(20px)' : 'translateX(100%)'}; opacity:0; }
            to   { transform: translateY(0) translateX(0); opacity:1; }
        }`;
        document.head.appendChild(s);
    }

    document.body.appendChild(toast);
    setTimeout(() => { if (toast.parentElement) toast.remove(); }, duration);
}

// ── Format helpers ──────────────────────────────────
function fmtNum(amount) {
    const n = parseFloat(amount || 0);
    const rounded = Math.round(n * 100) / 100;
    const int = Math.floor(rounded);
    return rounded === int
        ? int.toLocaleString('en-PK')
        : rounded.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function formatCurrency(amount) { return 'Rs. ' + fmtNum(amount); }
function formatNumber(num)      { return parseFloat(num || 0).toLocaleString('en-PK'); }

// ── API helper ──────────────────────────────────────
async function apiCall(url, method = 'GET', data = null) {
    const opts = { method, headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (data && method !== 'GET') opts.body = JSON.stringify(data);
    try {
        const res = await fetch(url, opts);
        return await res.json();
    } catch (err) {
        console.error('API Error:', err);
        return { success: false, error: err.message };
    }
}

// ── Table search/filter ─────────────────────────────
function filterTable(inputId, tableId) {
    const input  = document.getElementById(inputId);
    const filter = (input?.value || '').toLowerCase();
    const table  = document.getElementById(tableId);
    if (!table) return;
    Array.from(table.getElementsByTagName('tr')).slice(1).forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
    });
}

// ── Debounce ────────────────────────────────────────
function debounce(func, wait) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => func(...args), wait); };
}

// ── Print invoice ───────────────────────────────────
function printInvoice() { window.print(); }

// ── Barcode scanner ─────────────────────────────────
let barcodeBuffer = '', barcodeTimer = null;
function enableBarcodeScanner(callback) {
    document.addEventListener('keypress', (e) => {
        if (barcodeTimer) clearTimeout(barcodeTimer);
        if (e.key === 'Enter') {
            if (barcodeBuffer.length > 3) callback(barcodeBuffer);
            barcodeBuffer = '';
        } else {
            barcodeBuffer += e.key;
            barcodeTimer = setTimeout(() => { barcodeBuffer = ''; }, 120);
        }
    });
}

// ── Clipboard ───────────────────────────────────────
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => showToast('Copied!', 'info'));
}

// ── Button loading state ────────────────────────────
function setLoading(btn, loading) {
    if (loading) {
        btn.disabled = true;
        btn.dataset.originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading…';
    } else {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.originalHtml || btn.innerHTML;
    }
}

// ── Confirm dialog (mobile-friendly) ───────────────
function confirmAction(message, callback) {
    // Remove existing
    document.getElementById('confirmModal')?.remove();

    const div = document.createElement('div');
    div.innerHTML = `
        <div class="modal fade" id="confirmModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width:min(92vw,360px)">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <i class="bi bi-question-circle-fill text-warning" style="font-size:2.8rem;"></i>
                        <h5 class="mt-3 mb-2" style="font-size:1rem;">Confirm Action</h5>
                        <p class="text-muted mb-4" style="font-size:.875rem;">${message}</p>
                        <div class="d-flex gap-2 justify-content-center">
                            <button class="btn btn-outline-secondary btn-sm px-4"
                                    onclick="bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide()">
                                Cancel
                            </button>
                            <button class="btn btn-danger btn-sm px-4" id="confirmYes">Confirm</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
    document.body.appendChild(div.firstElementChild);
    const m = new bootstrap.Modal(document.getElementById('confirmModal'));
    m.show();
    document.getElementById('confirmYes').onclick = () => {
        m.hide();
        document.getElementById('confirmModal').addEventListener('hidden.bs.modal', () => {
            document.getElementById('confirmModal')?.remove();
            callback();
        }, { once: true });
    };
}

// ── Responsive table: make every .table-responsive
//    show a subtle scroll hint on mobile ────────────
document.addEventListener('DOMContentLoaded', () => {
    if (window.innerWidth <= 767) {
        document.querySelectorAll('.table-responsive').forEach(wrap => {
            if (wrap.scrollWidth > wrap.clientWidth) {
                const hint = document.createElement('div');
                hint.style.cssText = 'text-align:center;font-size:.7rem;color:#aaa;padding:.25rem;';
                hint.innerHTML = '<i class="bi bi-arrow-left-right"></i> Scroll to see more';
                wrap.parentNode.insertBefore(hint, wrap);
                // Remove hint after first scroll
                wrap.addEventListener('scroll', () => hint.remove(), { once: true });
            }
        });
    }
});

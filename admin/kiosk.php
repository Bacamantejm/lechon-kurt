<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/security.php';
require_once '../includes/sales_receipt_helper.php';
require_once 'auth.php';

checkAdminAccess();
requireAnyPermission(['orders.view', 'orders.create']);
$csrf_token = generateCSRFToken();
$admin_info = getAdminInfo($conn);
$admin_user_id = intval($_SESSION['user_id'] ?? 0);
$receipt_owner_user_id = $admin_user_id;
if (function_exists('isApprovedFranchiseSellerAccount') && isApprovedFranchiseSellerAccount($conn, $admin_user_id)) {
    $receipt_owner_user_id = (int)getFranchiseSellerScopeOwnerId($conn, $admin_user_id);
}
$receipt_business = srFetchBusinessProfile($conn, (int)$receipt_owner_user_id);
$receipt_business['address'] = trim((string)($receipt_business['address'] ?? '')) !== ''
    ? (string)$receipt_business['address']
    : 'In-store Pickup';
$can_create_orders = true;
if (function_exists('hasPermission')) {
    $can_create_orders = hasPermission($conn, $admin_user_id, 'orders.create');
}
if ((string)($_SESSION['role_name'] ?? '') === 'super_admin' || ((string)($_SESSION['user_type'] ?? '') === 'admin' && intval($_SESSION['role_id'] ?? 0) <= 0)) {
    $can_create_orders = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walk-in Orders</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Dark Mode Styles */
        :root {
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
            --input-bg-dark: #333;
        }

        body.dark-mode {
            background-color: var(--bg-color-dark) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-container {
            background-color: var(--bg-color-dark) !important;
        }

        body.dark-mode .admin-topbar,
        body.dark-mode .card,
        body.dark-mode .product-card,
        body.dark-mode .cart,
        body.dark-mode .cart-header,
        body.dark-mode .cart-footer,
        body.dark-mode .form-control,
        body.dark-mode .category-pill {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, 
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1,
        body.dark-mode .product-name,
        body.dark-mode .text-muted {
            color: var(--text-color-dark) !important;
        }
        
        body.dark-mode .category-pill.active {
            background-color: #c62828 !important;
            color: white !important;
            border-color: #c62828 !important;
        }

        body.dark-mode .qty-ctrl button {
            background-color: #444;
            color: #fff;
            border-color: #555;
        }
        
        body.dark-mode .qty-ctrl input {
            background-color: #333;
            color: #fff;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }

        /* Kiosk Specifics */
        .kiosk-container { display: flex; gap: 20px; height: calc(100vh - 140px); }
        .kiosk-left { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .kiosk-right { width: 350px; flex-shrink: 0; display: flex; flex-direction: column; }
        
        .product-grid-wrapper { overflow-y: auto; padding-right: 5px; flex: 1; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 15px; padding-bottom: 20px; }
        
        .product-card { 
            background: #fff; 
            border-radius: 12px; 
            border: 1px solid #eee; 
            overflow: hidden; 
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .product-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        
        .product-image { width: 100%; height: 120px; object-fit: cover; background: #f8f9fa; }
        .product-body { padding: 12px; display: flex; flex-direction: column; flex: 1; gap: 8px; }
        .product-name { font-weight: 600; font-size: 0.9rem; line-height: 1.3; margin-bottom: auto; }
        .product-price { color: #c62828; font-weight: 700; font-size: 1rem; }
        
        .cart { background: #fff; border-radius: 12px; border: 1px solid #eee; display: flex; flex-direction: column; height: 100%; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .cart-header { padding: 15px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; font-weight: 600; }
        .cart-body { overflow-y: auto; padding: 15px; flex: 1; }
        .cart-footer { border-top: 1px solid #eee; padding: 15px; background: rgba(0,0,0,0.02); }
        
        .cart-item { display: flex; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px dashed #eee; animation: fadeIn 0.3s ease; }
        .cart-item:last-child { border-bottom: none; }
        .cart-item-info { flex: 1; }
        .cart-item-title { font-weight: 600; font-size: 0.9rem; margin-bottom: 2px; }
        .cart-item-price { font-size: 0.85rem; color: #666; }
        
        .qty-ctrl { display: flex; align-items: center; border: 1px solid #ddd; border-radius: 6px; overflow: hidden; height: 28px; }
        .qty-ctrl button { width: 24px; height: 100%; border: none; background: #f1f1f1; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; transition: background 0.2s; }
        .qty-ctrl button:hover { background: #e0e0e0; }
        .qty-ctrl input { width: 30px; height: 100%; border: none; text-align: center; font-size: 0.85rem; font-weight: 600; outline: none; background: transparent; }
        
        .category-scroll { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 10px; scrollbar-width: thin; }
        .category-pill { 
            white-space: nowrap; 
            padding: 6px 14px; 
            border-radius: 20px; 
            border: 1px solid #ddd; 
            background: #fff; 
            cursor: pointer; 
            font-size: 0.85rem; 
            transition: all 0.2s;
            user-select: none;
        }
        .category-pill:hover { border-color: #c62828; color: #c62828; }
        .category-pill.active { background: #c62828; color: #fff; border-color: #c62828; }

        .search-bar { position: relative; max-width: 300px; }
        .search-bar i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999; }
        .search-bar input { padding-left: 35px; border-radius: 20px; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in-up { animation: fadeIn 0.4s ease-out forwards; }
    </style>
</head>
<body class="admin-polish kiosk-page">
    <div class="page-loader">
        <div class="spinner"></div>
    </div>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Walk-in Orders</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="topbar-right">
                        <div class="date-display" id="currentDate"></div>
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="admin-main" style="overflow: hidden; display: flex; flex-direction: column;">
                <div class="d-flex justify-content-between align-items-center mb-3 fade-in-up">
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input id="searchInput" type="text" class="form-control" placeholder="Search products...">
                    </div>
                    <button id="clearCartBtn" class="btn btn-outline-secondary btn-sm rounded-pill">
                        <i class="fas fa-trash-alt me-1"></i> Clear Cart
                    </button>
                </div>

                <div class="kiosk-container fade-in-up" style="animation-delay: 0.1s;">
                    <div class="kiosk-left">
                        <div id="categories" class="category-scroll"></div>
                        <div class="product-grid-wrapper">
                            <div id="productGrid" class="grid"></div>
                        </div>
                    </div>
                    
                    <div class="kiosk-right">
                        <div class="cart">
                            <div class="cart-header">
                                <span><i class="fas fa-shopping-basket me-2"></i>Current Order</span>
                                <span id="cartCount" class="badge bg-danger rounded-pill">0</span>
                            </div>
                            <div id="cartBody" class="cart-body">
                                <div class="text-center text-muted mt-5">
                                    <i class="fas fa-shopping-cart fa-3x mb-3" style="opacity: 0.3;"></i>
                                    <p>Cart is empty</p>
                                </div>
                            </div>
                                                        <div class="cart-footer">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Subtotal</span>
                                    <strong id="subtotal" style="font-size: 1.2rem;">&#8369;0.00</strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">VAT (12%)</span>
                                    <strong id="vatAmount">&#8369;0.00</strong>
                                </div>
                                <div class="d-flex justify-content-between mb-3">
                                    <span class="text-muted">Total Due</span>
                                    <strong id="grandTotal" style="font-size: 1.05rem; color: #c62828;">&#8369;0.00</strong>
                                </div>
                                <?php if (!$can_create_orders): ?>
                                    <div class="small text-danger mb-2">View-only access. Checkout requires <strong>orders.create</strong> permission.</div>
                                <?php endif; ?>
                                <button id="checkoutBtn" class="btn btn-danger w-100 py-2 rounded-pill fw-bold" disabled>
                                    <i class="fas fa-cash-register me-2"></i> Checkout
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="admin.js"></script>
    <script>
        const kioskMeta = {
            storeName: <?php echo json_encode($receipt_business['business_name'] ?? 'Lechon Delights'); ?>,
            branch: <?php echo json_encode($receipt_business['branch_name'] ?? 'Walk-in Counter'); ?>,
            address: <?php echo json_encode($receipt_business['address'] ?? 'In-store Pickup'); ?>,
            phone: <?php echo json_encode($receipt_business['phone'] ?? ''); ?>,
            taxId: <?php echo json_encode($receipt_business['tax_id'] ?? ''); ?>,
            businessStyle: <?php echo json_encode($receipt_business['business_style'] ?? ''); ?>,
            permitNo: <?php echo json_encode($receipt_business['permit_no'] ?? ''); ?>,
            ptuNo: <?php echo json_encode($receipt_business['ptu_no'] ?? ''); ?>,
            accreditationNo: <?php echo json_encode($receipt_business['accreditation_no'] ?? ''); ?>,
            serialNo: <?php echo json_encode($receipt_business['serial_no'] ?? ''); ?>,
            footerText: <?php echo json_encode($receipt_business['footer_text'] ?? ''); ?>,
            cashier: <?php echo json_encode($admin_info['full_name']); ?>
        };
        const kioskVatRate = 0.12;
        const kioskCsrfToken = <?php echo json_encode($csrf_token); ?>;
        const canCreateOrders = <?php echo $can_create_orders ? 'true' : 'false'; ?>;

        // Theme Toggler Logic (copied from index.php pattern)
        const themeToggler = document.getElementById('themeToggler');
        const body = document.body;
        const icon = themeToggler.querySelector('i');

        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        }

        themeToggler.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });

        // Date Display
        function updateDate() {
            const now = new Date();
            const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', options);
        }
        updateDate();

        // Kiosk Logic
        const peso = v => '\u20B1' + (Number(v)||0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const kioskEscapeHtml = (str) => String(str ?? '').replace(/[&<>'"]/g, (ch) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        }[ch]));

        let products = [];
        let cart = {};
        let categories = [];
        let activeCategory = '';

        function computeKioskTotals(items) {
            const subtotal = items.reduce((sum, item) => sum + (Number(item.total) || 0), 0);
            const vatAmount = Math.round(subtotal * kioskVatRate * 100) / 100;
            const totalAmount = subtotal + vatAmount;
            return { subtotal, vatAmount, totalAmount };
        }

        function buildReceiptHtml(receiptData) {
            const totals = {
                subtotal: Number(receiptData.subtotal || 0),
                vatAmount: Number(receiptData.vat_amount || 0),
                totalAmount: Number(receiptData.total_amount || 0),
                vatableSales: Number(receiptData.vatable_sales || receiptData.subtotal || 0),
                vatExemptSales: Number(receiptData.vat_exempt_sales || 0),
                zeroRatedSales: Number(receiptData.zero_rated_sales || 0)
            };
            const itemsHtml = receiptData.items.map((it) => `
                <tr>
                    <td>${kioskEscapeHtml(it.name)}</td>
                    <td>${it.quantity}</td>
                    <td>${peso(it.price)}</td>
                    <td>${peso(it.total)}</td>
                </tr>
            `).join('');
            const footerHtml = kioskMeta.footerText
                ? kioskMeta.footerText.split(/\r?\n/).filter(Boolean).map((line) => `<div>${kioskEscapeHtml(line)}</div>`).join('')
                : '<div>Thank you for your order.</div><div class="muted">Please keep this receipt for your records.</div>';

            return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Invoice ${kioskEscapeHtml(receiptData.order_number)}</title>
    <style>
        body { margin: 0; padding: 16px; background: #eef2f7; color: #111827; font-family: Arial, sans-serif; }
        .screen-only { display: flex; gap: 8px; justify-content: center; margin-bottom: 14px; }
        .btn { border: 1px solid #d1d5db; background: #fff; padding: 9px 14px; border-radius: 999px; cursor: pointer; font-size: 12px; font-weight: 700; }
        .receipt-wrap { width: 360px; margin: 0 auto; background: #fff; color: #111; box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16); padding: 18px 18px 22px; font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.32; }
        .center { text-align: center; }
        .muted { color: #4b5563; }
        .business-name { margin: 0 0 4px; font-size: 24px; line-height: 1.1; font-weight: 700; }
        .heading { text-transform: uppercase; letter-spacing: 0.08em; font-size: 13px; margin: 6px 0 0; font-weight: 700; }
        .line { border-top: 1px dashed #6b7280; margin: 10px 0; }
        .meta-row, .amount-row { display: flex; justify-content: space-between; gap: 12px; margin: 3px 0; }
        .meta-row span:first-child, .amount-row span:first-child { min-width: 116px; }
        .section-title { text-align: center; font-size: 12px; font-weight: 700; letter-spacing: 0.12em; margin: 10px 0 6px; }
        .receipt-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .receipt-table th, .receipt-table td { padding: 3px 0; vertical-align: top; }
        .receipt-table th { border-bottom: 1px solid #111; font-weight: 700; }
        .receipt-table th:nth-child(2), .receipt-table td:nth-child(2) { width: 42px; text-align: center; }
        .receipt-table th:nth-child(3), .receipt-table th:nth-child(4), .receipt-table td:nth-child(3), .receipt-table td:nth-child(4) { width: 74px; text-align: right; }
        .receipt-table td:first-child { padding-right: 8px; }
        .grand-total { font-weight: 700; font-size: 13px; }
        .bottom-note { text-align: center; margin-top: 12px; }
        .claim { text-align: center; margin-top: 16px; font-size: 18px; font-weight: 700; letter-spacing: 0.08em; }
        @media print {
            .screen-only { display: none !important; }
            body { padding: 0; background: #fff; }
            .receipt-wrap { width: auto; box-shadow: none; padding: 0; }
            @page { size: auto; margin: 6mm; }
        }
    </style>
</head>
<body>
    <div class="screen-only">
        <button class="btn" onclick="window.print()">Print Sales Invoice</button>
        <button class="btn" onclick="window.close()">Close</button>
    </div>
    <div class="receipt-wrap">
        <div class="center">
            <div class="business-name">${kioskEscapeHtml(kioskMeta.storeName)}</div>
            ${kioskMeta.branch ? `<div class="muted">${kioskEscapeHtml(kioskMeta.branch)}</div>` : ''}
            <div class="muted">${kioskEscapeHtml(kioskMeta.address)}</div>
            ${kioskMeta.phone ? `<div class="muted">Tel: ${kioskEscapeHtml(kioskMeta.phone)}</div>` : ''}
            ${kioskMeta.taxId ? `<div class="muted">VAT Reg TIN: ${kioskEscapeHtml(kioskMeta.taxId)}</div>` : ''}
            ${kioskMeta.businessStyle ? `<div class="muted">Business Style: ${kioskEscapeHtml(kioskMeta.businessStyle)}</div>` : ''}
            ${kioskMeta.permitNo ? `<div class="muted">Permit No.: ${kioskEscapeHtml(kioskMeta.permitNo)}</div>` : ''}
            ${kioskMeta.ptuNo ? `<div class="muted">PTU No.: ${kioskEscapeHtml(kioskMeta.ptuNo)}</div>` : ''}
            ${kioskMeta.accreditationNo ? `<div class="muted">Accreditation No.: ${kioskEscapeHtml(kioskMeta.accreditationNo)}</div>` : ''}
            ${kioskMeta.serialNo ? `<div class="muted">Serial No.: ${kioskEscapeHtml(kioskMeta.serialNo)}</div>` : ''}
            <div class="heading">Sales Invoice</div>
        </div>

        <div class="line"></div>

        <div class="meta-row"><span>Invoice No.</span><span>${kioskEscapeHtml(receiptData.order_number)}</span></div>
        <div class="meta-row"><span>Trans. No.</span><span>${kioskEscapeHtml(receiptData.order_id || receiptData.order_number)}</span></div>
        <div class="meta-row"><span>Date</span><span>${kioskEscapeHtml(receiptData.date_time)}</span></div>
        <div class="meta-row"><span>Cashier</span><span>${kioskEscapeHtml(receiptData.cashier)}</span></div>
        <div class="meta-row"><span>Payment</span><span>Cash</span></div>

        <div class="line"></div>

        <div class="section-title">WALK-IN COUNTER</div>

        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>${itemsHtml}</tbody>
        </table>

        <div class="line"></div>

        <div class="amount-row"><span>Subtotal</span><span>${peso(totals.subtotal)}</span></div>
        <div class="amount-row"><span>Total Amount Due</span><span class="grand-total">${peso(totals.totalAmount)}</span></div>
        <div class="amount-row"><span>Cash</span><span>${peso(receiptData.cash_received || 0)}</span></div>
        <div class="amount-row"><span>Change</span><span>${peso(receiptData.change_amount || 0)}</span></div>
        <div class="amount-row"><span>VATable Sales</span><span>${peso(totals.vatableSales)}</span></div>
        <div class="amount-row"><span>VAT-Exempt Sales</span><span>${peso(totals.vatExemptSales)}</span></div>
        <div class="amount-row"><span>Zero Rated Sales</span><span>${peso(totals.zeroRatedSales)}</span></div>
        <div class="amount-row"><span>VAT Amount</span><span>${peso(totals.vatAmount)}</span></div>

        <div class="line"></div>

        <div class="section-title">CUSTOMER</div>
        <div class="meta-row"><span>Name</span><span>${kioskEscapeHtml(receiptData.customer_name || 'Walk-in Customer')}</span></div>
        <div class="meta-row"><span>Address</span><span>________________</span></div>
        <div class="meta-row"><span>TIN</span><span>________________</span></div>
        <div class="meta-row"><span>Business Style</span><span>________________</span></div>
        <div class="meta-row"><span>Signature</span><span>________________</span></div>

        <div class="bottom-note">
            ${footerHtml}
        </div>

        <div class="claim">
            CLAIM#<br>${kioskEscapeHtml((receiptData.claim_code || receiptData.order_number || '').replace(/[^A-Za-z0-9]/g, '').slice(-8) || 'N/A')}
        </div>
    </div>
</body>
</html>`;
        }

        function buildOrderSummaryHtml(orderData) {
            const itemsHtml = orderData.items.map((it, index) => `
                <tr>
                    <td>${index + 1}</td>
                    <td>${kioskEscapeHtml(it.name)}</td>
                    <td style="text-align:center;">${it.quantity}</td>
                    <td style="text-align:right;">${peso(it.price)}</td>
                    <td style="text-align:right;">${peso(it.total)}</td>
                </tr>
            `).join('');

            return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Summary ${kioskEscapeHtml(orderData.order_number)}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 24px; color: #111; }
        .sheet { max-width: 900px; margin: 0 auto; }
        .title { display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px; }
        .title h2 { margin: 0; }
        .meta { display: grid; grid-template-columns: repeat(2, minmax(220px, 1fr)); gap: 8px 16px; font-size: 14px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 12px; }
        th, td { border: 1px solid #ddd; padding: 8px; }
        th { background: #f6f6f6; text-align: left; }
        .totals { margin-left: auto; width: 320px; }
        .totals .row { display: flex; justify-content: space-between; padding: 6px 0; }
        .totals .grand { font-weight: bold; border-top: 2px solid #111; margin-top: 8px; padding-top: 8px; }
        .note { margin-top: 20px; color: #444; font-size: 13px; }
        .screen-only { margin-bottom: 16px; display: flex; gap: 8px; }
        .btn { border: 1px solid #ccc; background: #fff; padding: 8px 12px; cursor: pointer; border-radius: 6px; }
        @media print {
            .screen-only { display: none !important; }
            body { padding: 0; }
            @page { size: A4; margin: 12mm; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="screen-only">
            <button class="btn" onclick="window.print()">Print</button>
            <button class="btn" onclick="window.close()">Close</button>
        </div>
        <div class="title">
            <div>
                <h2>${kioskEscapeHtml(kioskMeta.storeName)}</h2>
                <div>Walk-in Order Summary</div>
            </div>
            <div><strong>#${kioskEscapeHtml(orderData.order_number)}</strong></div>
        </div>
        <div class="meta">
            <div><strong>Date:</strong> ${kioskEscapeHtml(orderData.date_time)}</div>
            <div><strong>Cashier:</strong> ${kioskEscapeHtml(orderData.cashier)}</div>
            <div><strong>Customer:</strong> ${kioskEscapeHtml(orderData.customer_name || 'Walk-in Customer')}</div>
            <div><strong>Payment:</strong> Cash</div>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th>Item</th>
                    <th style="width:80px; text-align:center;">Qty</th>
                    <th style="width:130px; text-align:right;">Unit Price</th>
                    <th style="width:140px; text-align:right;">Line Total</th>
                </tr>
            </thead>
            <tbody>${itemsHtml}</tbody>
        </table>
        <div class="totals">
            <div class="row"><span>Subtotal</span><span>${peso(orderData.total_amount)}</span></div>
            <div class="row"><span>Cash Received</span><span>${peso(orderData.cash_received || 0)}</span></div>
            <div class="row grand"><span>Change</span><span>${peso(orderData.change_amount || 0)}</span></div>
        </div>
        <div class="note">Prepared by ${kioskEscapeHtml(orderData.cashier)}.</div>
    </div>
</body>
</html>`;
        }

        function openPrintPreview(html, autoPrint = false, title = 'Print Preview') {
            const printWindow = window.open('', '_blank', 'width=900,height=850');
            if (!printWindow) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Popup Blocked',
                    text: `Unable to open ${title}. Please allow popups and try again.`
                });
                return;
            }

            printWindow.document.open();
            printWindow.document.write(html);
            printWindow.document.close();
            if (autoPrint) {
                printWindow.focus();
                setTimeout(() => {
                    try { printWindow.print(); } catch (e) {}
                }, 300);
            }
        }

        function generateReceipt(receiptData, autoPrint = false) {
            openPrintPreview(buildReceiptHtml(receiptData), autoPrint, 'receipt');
        }

        function generateOrderSummary(orderData, autoPrint = false) {
            openPrintPreview(buildOrderSummaryHtml(orderData), autoPrint, 'order summary');
        }

        function renderOrderSummaryTable(items, totals) {
            const rows = items.map((it) => `
                <tr>
                    <td style="padding:4px 0;">${kioskEscapeHtml(it.name)}</td>
                    <td style="padding:4px 0; text-align:center;">${it.quantity}</td>
                    <td style="padding:4px 0; text-align:right;">${peso(it.total)}</td>
                </tr>
            `).join('');
            return `
                <div style="max-height:220px; overflow:auto; border:1px solid #eee; border-radius:8px; padding:8px;">
                    <table style="width:100%; font-size:0.9rem; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="text-align:left; padding:4px 0;">Item</th>
                                <th style="text-align:center; padding:4px 0; width:60px;">Qty</th>
                                <th style="text-align:right; padding:4px 0; width:120px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                <div style="margin-top:10px; display:grid; gap:4px; font-size:0.92rem;">
                    <div style="display:flex; justify-content:space-between;"><span>Subtotal</span><strong>${peso(totals.subtotal)}</strong></div>
                    <div style="display:flex; justify-content:space-between;"><span>VAT (12%)</span><strong>${peso(totals.vatAmount)}</strong></div>
                    <div style="display:flex; justify-content:space-between; font-size:1rem;"><span>Total Due</span><strong>${peso(totals.totalAmount)}</strong></div>
                </div>
            `;
        }

        async function fetchProducts() {
            const grid = document.getElementById('productGrid');
            try {
                const res = await fetch('../get_products_for_kiosk.php', {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const text = await res.text();
                let data = {};
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('Unexpected server response while loading products.');
                }

                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Failed to load products.');
                }

                products = Array.isArray(data.products) ? data.products : [];
                categories = Array.isArray(data.categories) ? data.categories : [];
                renderCategories();
                renderProducts();
            } catch (error) {
                console.error('Error fetching products:', error);
                if (grid) {
                    grid.innerHTML = `<div class="col-12 text-center py-5 text-danger">${kioskEscapeHtml(error.message || 'Failed to load products.')}</div>`;
                }
                Swal.fire('Error', error.message || 'Failed to load products', 'error');
            }
        }

        function renderCategories(){
            const c = document.getElementById('categories');
            c.innerHTML = '';
            const all = document.createElement('div');
            all.textContent = 'All Items';
            all.className = 'category-pill' + (!activeCategory ? ' active' : '');
            all.onclick = ()=>{ activeCategory=''; renderProducts(); renderCategories(); };
            c.appendChild(all);
            categories.forEach(cat=>{
                const el = document.createElement('div');
                el.textContent = cat;
                el.className = 'category-pill' + (activeCategory===cat?' active':'');
                el.onclick = ()=>{ activeCategory = (activeCategory===cat?'':cat); renderProducts(); renderCategories(); };
                c.appendChild(el);
            });
        }

        function renderProducts(){
            const grid = document.getElementById('productGrid');
            const q = document.getElementById('searchInput').value.trim().toLowerCase();
            grid.innerHTML = '';
            
            const filtered = products
                .filter(p => (!activeCategory || p.category === activeCategory))
                .filter(p => !q || p.name.toLowerCase().includes(q));
                
            if(filtered.length === 0) {
                grid.innerHTML = '<div class="col-12 text-center text-muted py-5">No products found</div>';
                return;
            }

            filtered.forEach(p => {
                const soldOut = !p.is_active || (Number(p.stock) <= 0);
                const wrap = document.createElement('div');
                wrap.className = 'product-card' + (soldOut ? ' sold-out' : '');
                wrap.style.opacity = soldOut ? '0.6' : '1';
                
                let badgeClass = 'bg-success';
                let badgeText = p.stock + ' in stock';
                
                if(soldOut) {
                    badgeClass = 'bg-secondary';
                    badgeText = 'Sold Out';
                } else if (p.stock <= (p.min_stock_level||5)) {
                    badgeClass = 'bg-warning text-dark';
                    badgeText = p.stock + ' left';
                }

                wrap.innerHTML = `
                    <div class="product-image">
                        <img src="${p.image ? '../' + p.image : '../images/placeholder.png'}" 
                             onerror="this.src='../images/placeholder.png'" 
                             alt="${p.name}" 
                             style="width:100%; height:100%; object-fit:cover;">
                    </div>
                    <div class="product-body">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <span class="badge ${badgeClass} rounded-pill" style="font-size:0.7rem;">${badgeText}</span>
                        </div>
                        <div class="product-name" title="${p.name}">${p.name}</div>
                        <div class="d-flex justify-content-between align-items-center mt-auto">
                            <div class="product-price">${peso(p.price)}</div>
                            <button class="btn btn-sm btn-outline-danger rounded-circle" style="width:32px; height:32px; padding:0;" ${soldOut?'disabled':''}>
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>`;
                if (!soldOut) {
                    wrap.style.cursor = 'pointer';
                    wrap.addEventListener('click', () => addToCart(p, wrap));
                }

                const btn = wrap.querySelector('button');
                btn.onclick = (e) => {
                    e.stopPropagation();
                    addToCart(p, wrap);
                };
                
                grid.appendChild(wrap);
            });
        }

        function addToCart(p, sourceElement = null){
            const key = String(p.id);
            const currentQty = cart[key]?.quantity || 0;
            
            // Animation feedback
            const card = sourceElement || null;
            if(card) {
                card.style.transform = 'scale(0.95)';
                setTimeout(() => card.style.transform = '', 100);
            }

            if (currentQty + 1 > Number(p.stock)) {
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false, timer: 2000, timerProgressBar: true
                });
                Toast.fire({ icon: 'warning', title: 'Insufficient Stock' });
                return;
            }
            cart[key] = cart[key] || { id:p.id, product_id:p.product_id, name:p.name, price:Number(p.price), quantity:0, image: p.image };
            cart[key].quantity += 1;
            renderCart();
        }

        function changeQty(key, delta){
            const item = cart[key];
            if (!item) return;
            const product = products.find(p=> String(p.id)===String(key));
            const newQty = item.quantity + delta;
            if (newQty <= 0) { delete cart[key]; renderCart(); return; }
            if (product && newQty > Number(product.stock)) {
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false, timer: 2000
                });
                Toast.fire({ icon: 'warning', title: 'Max stock reached' });
                return;
            }
            item.quantity = newQty;
            renderCart();
        }

        function renderCart(){
            const body = document.getElementById('cartBody');
            body.innerHTML = '';
            let count = 0; let subtotal = 0;
            const items = Object.values(cart);
            
            if(items.length === 0) {
                body.innerHTML = `
                    <div class="text-center text-muted mt-5 fade-in-up">
                        <i class="fas fa-shopping-cart fa-3x mb-3" style="opacity: 0.3;"></i>
                        <p>Cart is empty</p>
                    </div>`;
                document.getElementById('cartCount').textContent = '0';
                document.getElementById('subtotal').textContent = peso(0);
                document.getElementById('vatAmount').textContent = peso(0);
                document.getElementById('grandTotal').textContent = peso(0);
                document.getElementById('checkoutBtn').disabled = true;
                return;
            }

            items.forEach(it=>{
                count += it.quantity;
                subtotal += it.price * it.quantity;
                const row = document.createElement('div');
                row.className = 'cart-item';
                row.innerHTML = `
                    <div class="cart-item-info">
                        <div class="cart-item-title">${it.name}</div>
                        <div class="cart-item-price">${peso(it.price)}</div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div class="qty-ctrl">
                            <button onclick="changeQty('${it.id}', -1)"><i class="fas fa-minus"></i></button>
                            <input type="text" value="${it.quantity}" readonly>
                            <button onclick="changeQty('${it.id}', 1)"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="fw-bold text-end" style="min-width: 60px;">${peso(it.price * it.quantity)}</div>
                        <button class="btn btn-link text-danger p-0" onclick="removeItem('${it.id}')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>`;
                body.appendChild(row);
            });
            const totals = computeKioskTotals(items.map(it => ({
                total: Number(it.price) * Number(it.quantity)
            })));
            document.getElementById('cartCount').textContent = count;
            document.getElementById('subtotal').textContent = peso(subtotal);
            document.getElementById('vatAmount').textContent = peso(totals.vatAmount);
            document.getElementById('grandTotal').textContent = peso(totals.totalAmount);
            document.getElementById('checkoutBtn').disabled = !canCreateOrders;
        }

        function removeItem(key){ 
            delete cart[key]; 
            renderCart(); 
        }

        async function checkout(){
            if (!canCreateOrders) {
                Swal.fire({
                    icon: 'info',
                    title: 'View-Only Access',
                    text: 'Your account can view kiosk items but cannot create orders.'
                });
                return;
            }

            const items = Object.values(cart).map(it=>({ product_id: it.product_id || it.id, id: it.id, quantity: it.quantity }));
            if (items.length === 0) return;
            const receiptItems = Object.values(cart).map(it => ({
                name: it.name,
                quantity: Number(it.quantity),
                price: Number(it.price),
                total: Number(it.price) * Number(it.quantity)
            }));
            const estimatedTotals = computeKioskTotals(receiptItems);
            const estimatedTotal = estimatedTotals.totalAmount;
            
            const paymentPrompt = await Swal.fire({
                title: 'Customer & Payment',
                html: `
                    <input id="swalCustomerName" class="swal2-input" placeholder="Customer name" value="Walk-in Customer">
                    <input id="swalCashReceived" class="swal2-input" placeholder="Cash received" type="number" min="0" step="0.01">
                    ${renderOrderSummaryTable(receiptItems, estimatedTotals)}
                `,
                showCancelButton: true,
                confirmButtonText: 'Place Order',
                confirmButtonColor: '#c62828',
                cancelButtonColor: '#6c757d',
                focusConfirm: false,
                preConfirm: () => {
                    const customerNameInput = document.getElementById('swalCustomerName');
                    const cashReceivedInput = document.getElementById('swalCashReceived');
                    const customerName = (customerNameInput?.value || '').trim() || 'Walk-in Customer';
                    const cashReceived = Number(cashReceivedInput?.value || 0);

                    if (!Number.isFinite(cashReceived) || cashReceived <= 0) {
                        Swal.showValidationMessage('Please enter a valid cash received amount.');
                        return false;
                    }

                    if (cashReceived < estimatedTotal) {
                        Swal.showValidationMessage(`Cash received is not enough. Total due is ${peso(estimatedTotal)}.`);
                        return false;
                    }

                    return {
                        customer_name: customerName,
                        cash_received: cashReceived
                    };
                }
            });

            if (!paymentPrompt.isConfirmed) return;
            const customer_name = paymentPrompt.value.customer_name;
            const cash_received = Number(paymentPrompt.value.cash_received || 0);

            // Show loading
            Swal.fire({
                title: 'Processing...',
                text: 'Creating order',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const res = await fetch('../create_walkin_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': kioskCsrfToken
                    },
                    body: JSON.stringify({ customer_name, items, csrf_token: kioskCsrfToken })
                });

                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error("Server invalid response:", text);
                    // If empty response or HTML, give a generic error but don't crash JSON parser
                    const msg = text ? text.substring(0, 100) : "Empty response from server";
                    throw new Error("Server Error: " + msg);
                }
                
                if (!data.success) throw new Error(data.message || 'Failed to create order');
                const finalTotal = Number(data.total_amount || estimatedTotal);
                const change_amount = Math.max(0, cash_received - finalTotal);
                const completedOrderData = {
                    order_id: Number(data.order_id || 0),
                    order_number: String(data.order_number || ''),
                    subtotal: Number(data.subtotal || estimatedTotals.subtotal),
                    vat_amount: Number(data.vat_amount || estimatedTotals.vatAmount),
                    vatable_sales: Number(data.vatable_sales || estimatedTotals.subtotal),
                    vat_exempt_sales: 0,
                    zero_rated_sales: 0,
                    total_amount: finalTotal,
                    customer_name: customer_name || 'Walk-in Customer',
                    cashier: kioskMeta.cashier,
                    cash_received,
                    change_amount,
                    date_time: new Date().toLocaleString('en-PH', {
                        year: 'numeric',
                        month: 'short',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    }),
                    items: receiptItems
                };

                // Open receipt immediately to ensure cashier can print before next order.
                generateReceipt(completedOrderData, false);
                
                cart = {}; 
                renderCart(); 
                fetchProducts(); // Refresh stock
                
                const actionPrompt = await Swal.fire({ 
                    icon: 'success', 
                    title: 'Order Created!', 
                    html: `Order <b>#${data.order_number}</b> has been saved.<br>Total: <b>${peso(finalTotal)}</b><br>Change: <b>${peso(change_amount)}</b>`,
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'Print Sales Invoice',
                    showCancelButton: true,
                    cancelButtonText: 'Done'
                });

                if (actionPrompt.isConfirmed) {
                    generateReceipt(completedOrderData, true);
                }
            } catch (e){
                Swal.fire({ icon: 'error', title: 'Checkout Failed', text: e.message });
            }
        }

        document.getElementById('checkoutBtn').addEventListener('click', checkout);
        document.getElementById('clearCartBtn').addEventListener('click', ()=>{ 
            if(Object.keys(cart).length > 0) {
                Swal.fire({
                    title: 'Clear Cart?',
                    text: "Remove all items from cart?",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, clear it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        cart={}; renderCart();
                    }
                })
            }
        });
        
        document.getElementById('searchInput').addEventListener('input', renderProducts);
        
        // Initial load
        fetchProducts();
    </script>
</body>
</html>



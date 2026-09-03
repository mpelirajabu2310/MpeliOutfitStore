<?php
// This file generates the main HTML with a timestamp to prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self';");

$timestamp = time();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  <title data-i18n="app.title">mpeli Outfit Store | Clothing Shop Management</title>
  <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png?v=<?php echo $timestamp; ?>" />
  <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon.png?v=<?php echo $timestamp; ?>" />
  <link rel="icon" type="image/png" sizes="48x48" href="assets/images/favicon-48x48.png?v=<?php echo $timestamp; ?>" />
  <link rel="icon" type="image/png" sizes="192x192" href="assets/images/favicon-192.png?v=<?php echo $timestamp; ?>" />
  <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png?v=<?php echo $timestamp; ?>" />
  <meta name="theme-color" content="#12110f" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo $timestamp; ?>&bust=9" />
</head>
<body>
  <div class="splash-screen" id="splashScreen">
    <div class="splash-content">
      <img src="assets/images/logo.png" alt="Mpeli Outfit Store" class="splash-logo">
      <div class="splash-loader"></div>
      <p class="splash-text">Loading...</p>
    </div>
  </div>
  <main class="health-screen hidden" id="healthScreen">
    <div class="health-content">
      <img src="assets/images/logo.png" alt="Mpeli Outfit Store" class="splash-logo" style="margin-bottom:24px">
      <h1 class="health-title" id="healthTitle">System Health Check</h1>
      <p class="health-subtitle" id="healthSubtitle">Verifying database connection, configuration, and system integrity...</p>
      <div class="health-spinner"></div>
      <div class="health-checks" id="healthChecks"></div>
      <div class="health-actions hidden" id="healthActions">
        <button class="gold-button" id="healthRetry" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Retry</button>
      </div>
    </div>
  </main>
  <main class="maintenance-screen hidden" id="maintenanceScreen">
    <div class="health-content">
      <img src="assets/images/logo.png" alt="Mpeli Outfit Store" class="splash-logo" style="margin-bottom:24px">
      <h1 class="health-title">Under Maintenance</h1>
      <p class="health-subtitle" id="maintenanceMessage">The system is currently undergoing scheduled maintenance. Please try again later.</p>
      <div class="maintenance-icon"><i class="bi bi-tools"></i></div>
    </div>
  </main>
  <main class="login-screen" id="loginScreen">
    <section class="login-art" aria-label="Boutique preview" data-i18n-aria-label="aria.boutiquePreview">
      <div class="login-art-body">
        <img src="assets/images/logo.png" alt="logo" class="brand-mark">
        <div class="login-art-text">
          <p class="eyebrow" data-i18n="brand.boutique">MPELI OUTFIT STORE</p>
          <h1 data-i18n="login.heroTitle">Clothing shop management with a luxury retail rhythm.</h1>
          <p data-i18n="login.heroText">Track stock, sales, expenses, and profit from one calm internal dashboard.</p>
        </div>
      </div>
    </section>

    <section class="login-panel" aria-label="Admin login" data-i18n-aria-label="aria.adminLogin">
      <form class="login-card" id="loginForm" autocomplete="off">
        <div class="login-logo-center">
          <img src="assets/images/logo.png" alt="Mpeli Outfit Store" class="login-logo-circle">
        </div>
        <p class="login-shop-name">Mpeli Outfit Store</p>
        <label class="language-field">
          <span data-i18n="settings.language">Language</span>
          <select class="language-switcher" id="loginLanguageSwitcher" aria-label="Language" data-i18n-aria-label="settings.language">
            <option value="en">English</option>
            <option value="sw">Swahili</option>
          </select>
        </label>
        <h2 data-i18n="login.welcome">Welcome backuuuuu</h2>
        <p data-i18n="login.subtitle">Sign in to manage products, sales, inventory, and boutique reports.</p>
        <label>
          <span data-i18n="login.username">Username</span>
          <div class="input-icon-wrap">
            <i class="bi bi-person"></i>
            <input type="text" id="loginUsername" autocomplete="username" />
          </div>
        </label>
        <label>
          <span data-i18n="login.password">Password</span>
          <div class="input-icon-wrap password-wrap">
            <i class="bi bi-lock"></i>
            <input type="password" id="loginPassword" autocomplete="current-password" />
            <button type="button" class="password-toggle" id="loginPasswordToggle" aria-label="Show password"><i class="bi bi-eye"></i></button>
          </div>
        </label>
        <button type="submit" data-i18n="login.signIn"><i class="bi bi-box-arrow-in-right"></i> <span class="btn-text">Sign in</span><span class="btn-loading-text">Signing in...</span></button>
        <p class="login-recovery-hint"><a href="api/recover_owner.php" id="recoveryLink"><i class="bi bi-question-circle"></i> Lost access? Recovery</a></p>
      </form>
      <form class="login-card setup-card hidden" id="ownerSetupForm" autocomplete="off">
        <div class="login-logo-center">
          <img src="assets/images/logo.png" alt="Mpeli Outfit Store" class="login-logo-circle">
        </div>
        <p class="login-shop-name">Mpeli Outfit Store</p>
        <h2 data-i18n="auth.createOwner">Create owner account</h2>
        <p data-i18n="auth.createOwnerText">No owner exists yet. Create the first OWNER account to start using the system.</p>
        <label><span data-i18n="users.name">Name</span><div class="input-icon-wrap"><i class="bi bi-person-badge"></i><input type="text" id="ownerName" autocomplete="name" /></div></label>
        <label><span data-i18n="login.username">Username</span><div class="input-icon-wrap"><i class="bi bi-person"></i><input type="text" id="ownerUsername" autocomplete="username" /></div></label>
        <label><span data-i18n="users.email">Email</span><div class="input-icon-wrap"><i class="bi bi-envelope"></i><input type="email" id="ownerEmail" autocomplete="email" /></div></label>
        <label><span data-i18n="login.password">Password</span><div class="input-icon-wrap password-wrap"><i class="bi bi-lock"></i><input type="password" id="ownerPassword" autocomplete="new-password" /><button type="button" class="password-toggle" id="ownerPasswordToggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div></label>
        <button type="submit" data-i18n="auth.createOwnerButton"><i class="bi bi-person-plus-fill"></i> <span class="btn-text">Create owner</span><span class="btn-loading-text">Creating...</span></button>
      </form>
    </section>
  </main>

  <div class="app-shell hidden" id="appShell">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar">
      <div class="sidebar-brand" id="sidebarBrand" role="button" tabindex="0" aria-label="Go to dashboard">
        <img src="assets/images/logo.png" alt="Mpeli Outfit Store" class="sidebar-logo" />
        <span class="sidebar-title">Mpeli Outfit Store</span>
      </div>
      <nav class="side-nav" aria-label="Main navigation" data-i18n-aria-label="aria.mainNavigation">
        <button class="nav-item active" data-page="dashboard"><i class="bi bi-grid-1x2-fill"></i> <span data-i18n="nav.dashboard">Dashboard</span></button>
        <button class="nav-item" data-page="products"><i class="bi bi-box-seam-fill"></i> <span data-i18n="nav.products">Products</span></button>
        <button class="nav-item owner-only" data-page="promotions"><i class="bi bi-tags-fill"></i> <span data-i18n="nav.promotions">Promotions</span></button>
        <button class="nav-item" data-page="sales"><i class="bi bi-cart-check-fill"></i> <span data-i18n="nav.sales">Sales POS</span></button>
        <button class="nav-item owner-only" data-page="inventory"><i class="bi bi-clipboard-data-fill"></i> <span data-i18n="nav.inventory">Inventory</span></button>
        <button class="nav-item" data-page="reports"><i class="bi bi-bar-chart-line-fill"></i> <span data-i18n="nav.reports">Reports</span></button>
        <button class="nav-item owner-only" data-page="analytics"><i class="bi bi-graph-up-arrow"></i> <span data-i18n="nav.analytics">Analytics</span></button>
        <button class="nav-item" data-page="expenses"><i class="bi bi-wallet2"></i> <span data-i18n="nav.expenses">Expenses</span></button>
        <button class="nav-item owner-only" data-page="users"><i class="bi bi-people-fill"></i> <span data-i18n="nav.users">Users</span></button>
        <button class="nav-item owner-only" data-page="audit"><i class="bi bi-journal-text"></i> <span data-i18n="nav.audit">Audit Logs</span></button>
        <button class="nav-item owner-only" data-page="backup"><i class="bi bi-shield-check"></i> <span data-i18n="nav.backup">Backup</span></button>
        <button class="nav-item owner-only" data-page="settings"><i class="bi bi-gear-fill"></i> <span data-i18n="nav.settings">Settings</span></button>
      </nav>
      <div class="sidebar-feature owner-only">
        <span data-i18n="sidebar.drop">Operations</span>
        <strong data-i18n="sidebar.arrivals">Live inventory</strong>
        <p data-i18n="sidebar.description">Stock levels update automatically after each sale.</p>
      </div>
    </aside>

    <section class="workspace">
      <header class="topbar">
        <button class="menu-button" id="menuButton" aria-label="Toggle menu" data-i18n-aria-label="aria.toggleMenu"><i class="bi bi-list hamburger-icon" aria-hidden="true"></i></button>

        <div class="topbar-center">
          <div class="search-box topbar-search">
            <input type="search" placeholder="Search..." id="globalSearch" data-i18n-placeholder="search.globalPlaceholder" />
            <button type="button" class="search-icon-btn" id="searchIconBtn" aria-label="Search"><i class="bi bi-search"></i></button>
          </div>
          <select class="language-switcher" id="appLanguageSwitcher" aria-label="Language" data-i18n-aria-label="settings.language"></select>
          <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle theme"><i class="bi bi-moon-stars"></i></button>
        </div>

        <div class="topbar-right">
          <div class="topbar-status">
            <span class="status-dot" id="statusDot"></span>
            <span class="status-text" id="statusText" data-i18n="status.online">Online</span>
          </div>

          <div class="topbar-clock">
            <span class="clock-time" id="clockTime">--:--</span>
            <span class="clock-date" id="clockDate">---</span>
          </div>

          <div class="topbar-user" id="topbarUser">
            <span id="profileAvatar" class="profile-avatar">--</span>
            <div class="topbar-user-info">
              <strong id="profileName"></strong>
              <small id="profileRole" class="role-badge"></small>
            </div>
            <i class="bi bi-chevron-down topbar-chevron"></i>

            <div class="user-dropdown hidden" id="userDropdown">
              <button class="dropdown-item" id="dropdownProfile"><i class="bi bi-person"></i> View Profile</button>
              <button class="dropdown-item" id="changePasswordButton"><i class="bi bi-key"></i> Change Password</button>
              <button class="dropdown-item owner-only" id="dropdownSettings"><i class="bi bi-gear"></i> Settings</button>
              <div class="dropdown-divider"></div>
              <button class="dropdown-item dropdown-danger" id="logoutButton"><i class="bi bi-box-arrow-left"></i> Logout</button>
            </div>
          </div>
        </div>
      </header>
      <main class="page active" id="dashboard">
        <div class="page-heading">
          <div>
            <p class="eyebrow" data-i18n="dashboard.eyebrow">Operations Overview</p>
            <h2 data-i18n="nav.dashboard">Dashboard</h2>
          </div>
          <button class="gold-button" id="generateReportButton" data-i18n="reports.generateReport">Generate Report</button>
        </div>
        <section class="stats-grid">
          <article class="stat-card"><span data-i18n="stats.totalProducts">Total Products</span><strong id="totalProducts">0</strong><small data-i18n="stats.activeCatalog">Active catalog items</small></article>
          <article class="stat-card"><span data-i18n="stats.totalSales">Total Sales</span><strong id="totalSales">0</strong><small data-i18n="stats.paidReceipts">Paid receipts</small></article>
          <article class="stat-card"><span data-i18n="stats.dailyRevenue">Daily Revenue</span><strong id="dailyRevenue">TSH 0</strong><small data-i18n="stats.revenueToday">Revenue today</small></article>
          <article class="stat-card owner-only"><span data-i18n="stats.buyingCost">Buying Cost</span><strong id="dailyBuyingCost">TSH 0</strong><small data-i18n="stats.buyingCost">Cost of goods sold today</small></article>
          <article class="stat-card owner-only"><span data-i18n="stats.dailyProfit">Daily Gross Profit</span><strong id="dailyProfit">TSH 0</strong><small data-i18n="stats.profitToday">Profit today</small></article>
          <article class="stat-card owner-only"><span data-i18n="stats.dailyExpenses">Daily Expenses</span><strong id="dailyExpenses">TSH 0</strong><small data-i18n="stats.expensesToday">Expenses today</small></article>
          <article class="stat-card owner-only"><span data-i18n="stats.dailyNetProfit">Daily Net Profit</span><strong id="dailyNetProfit">TSH 0</strong><small data-i18n="stats.netProfitToday">Net profit today</small></article>
          <article class="stat-card owner-only"><span data-i18n="stats.monthlyProfit">Monthly Gross Profit</span><strong id="monthlyProfit">TSH 0</strong><small data-i18n="stats.grossProfitMonth">Gross profit this month</small></article>
          <article class="stat-card owner-only"><span data-i18n="stats.monthlyExpenses">Monthly Expenses</span><strong id="monthlyExpenses">TSH 0</strong><small data-i18n="stats.expensesMonth">Expenses this month</small></article>
          <article class="stat-card owner-only"><span data-i18n="stats.monthlyNetProfit">Monthly Net Profit</span><strong id="monthlyNetProfit">TSH 0</strong><small data-i18n="stats.netProfitMonth">Net profit this month</small></article>
          <article class="stat-card warning"><span data-i18n="stats.lowStockItems">Low Stock Items</span><strong id="lowStockItems">0</strong><small data-i18n="stats.restockReview">Needs restock review</small></article>
        </section>
        <section class="seller-only" id="sellerAnalytics">
          <div class="section-title"><h3 data-i18n="analytics.myPerformance">My Performance</h3><span data-i18n="analytics.ownScope">Sales and expenses recorded by you</span></div>
          <div class="analytics-grid">
            <article class="panel analytics-card">
              <h4 data-i18n="analytics.week">This Week</h4>
              <div class="financial-lines">
                <div class="fin-row"><span data-i18n="analytics.sales">Sales</span><strong id="analWeekSales">TSH 0</strong></div>
                <div class="fin-row fin-divider"><span data-i18n="analytics.expenses">Expenses</span><strong id="analWeekExpenses">TSH 0</strong></div>
              </div>
            </article>
            <article class="panel analytics-card">
              <h4 data-i18n="analytics.month">This Month</h4>
              <div class="financial-lines">
                <div class="fin-row"><span data-i18n="analytics.sales">Sales</span><strong id="analMonthSales">TSH 0</strong></div>
                <div class="fin-row fin-divider"><span data-i18n="analytics.expenses">Expenses</span><strong id="analMonthExpenses">TSH 0</strong></div>
              </div>
            </article>
            <article class="panel analytics-card">
              <h4 data-i18n="analytics.year">This Year</h4>
              <div class="financial-lines">
                <div class="fin-row"><span data-i18n="analytics.sales">Sales</span><strong id="analYearSales">TSH 0</strong></div>
                <div class="fin-row fin-divider"><span data-i18n="analytics.expenses">Expenses</span><strong id="analYearExpenses">TSH 0</strong></div>
              </div>
            </article>
            <article class="panel analytics-card">
              <h4 data-i18n="analytics.allTime">All Time</h4>
              <div class="financial-lines">
                <div class="fin-row"><span data-i18n="analytics.sales">Sales</span><strong id="analTotalSales">TSH 0</strong></div>
                <div class="fin-row fin-divider"><span data-i18n="analytics.expenses">Expenses</span><strong id="analTotalExpenses">TSH 0</strong></div>
              </div>
            </article>
          </div>
        </section>
        <section class="dashboard-grid">
          <article class="panel chart-panel">
            <div class="panel-title">
              <h3 data-i18n="dashboard.salesAnalytics">Sales Analytics</h3>
              <span data-i18n="dashboard.last7Days">Last 7 days</span>
            </div>
            <div class="bar-chart revenue-chart" aria-label="Sales analytics bar chart" data-i18n-aria-label="aria.salesAnalyticsChart">
              <p class="empty-state" data-i18n="dashboard.noChartData">No sales data available yet.</p>
            </div>
          </article>
          <article class="panel owner-only chart-panel">
            <div class="panel-title">
              <h3 data-i18n="dashboard.profitTrend">Profit Trend</h3>
            </div>
            <div class="bar-chart profit-chart">
              <p class="empty-state" data-i18n="dashboard.noChartData">No sales data available yet.</p>
            </div>
          </article>
        </section>
        <article class="panel stock-alerts-panel hidden" id="stockAlertsPanel">
          <div class="panel-title"><h3 data-i18n="dashboard.stockAlerts">Stock Alerts</h3><span data-i18n="dashboard.lowStockLabel">Low Stock</span></div>
          <ul class="inventory-items" id="dashboardStockAlerts"></ul>
        </article>
        <article class="panel">
          <div class="panel-title">
            <h3 data-i18n="dashboard.recentSales">Recent Sales</h3>
            <button class="ghost-button" data-i18n="common.viewAll">View all</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th data-i18n="table.receipt">Receipt</th><th data-i18n="table.product">Product</th><th data-i18n="table.customerType">Customer Type</th><th data-i18n="table.amount">Amount</th><th class="owner-only" data-i18n="table.profit">Profit</th><th data-i18n="table.status">Status</th><th data-i18n="table.actions">Action</th></tr></thead>
              <tbody id="recentSalesBody">
                <tr><td colspan="7" data-i18n="sales.noCompletedSales">No completed sales yet.</td></tr>
              </tbody>
            </table>
          </div>
        </article>

        <!-- Sale Details Modal -->
        <div class="modal-overlay hidden" id="saleDetailsModal">
          <div class="modal-dialog sale-details-dialog">
            <div class="modal-head">
              <h3 data-i18n="saleDetails.title">Sale Details</h3>
              <button type="button" class="reset-close" id="saleDetailsClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div id="saleDetailsContent">
              <div class="sale-details-loading">
                <div class="sale-details-spinner"></div>
                <p data-i18n="saleDetails.loading">Loading sale details...</p>
              </div>
            </div>
          </div>
        </div>
      </main>
      <main class="page" id="products">
        <div class="page-heading">
          <div><p class="eyebrow" data-i18n="products.eyebrow">Catalog Control</p><h2 data-i18n="products.title">Products Management</h2></div>
          <button class="gold-button owner-only" id="toggleProductForm" data-i18n="products.addNew">Add new product</button>
        </div>
        <form class="panel product-form owner-only hidden" id="productForm">
          <input id="productNameInput" placeholder="Product name" data-i18n-placeholder="products.namePlaceholder" />
          <input id="productBuyingInput" type="number" min="0" step="1" placeholder="Buying price (TSH)" data-i18n-placeholder="products.buying" required />
          <input id="productSellingInput" type="number" min="0" step="1" placeholder="Selling price (TSH)" data-i18n-placeholder="products.selling" required />
          <input id="productMinPriceInput" type="number" min="0" step="1" placeholder="Min allowed selling price (TSH)" title="Minimum Allowed Selling Price" required />
          <input id="productStockInput" type="number" min="0" step="1" placeholder="Stock quantity" data-i18n-placeholder="products.stock" />
          <div class="product-image-field">
            <div class="image-input-row">
              <input id="productImageInput" type="file" accept="image/jpeg,image/png,image/jpg" data-i18n-aria-label="products.imageLabel" aria-label="Product image" />
              <button type="button" class="ghost-button" id="productImageClear" data-i18n="products.imageClear">Clear</button>
            </div>
            <div id="productImagePreviewWrap" class="image-preview hidden">
              <img id="productImagePreview" alt="Product image preview" />
            </div>
          </div>
          <button class="gold-button" type="submit" data-i18n="products.saveProduct"><i class="bi bi-check-circle-fill"></i> Save product</button>
        </form>
        <div class="toolbar">
          <input type="search" id="productSearch" placeholder="Search products..." data-i18n-placeholder="products.searchPlaceholder" />
        </div>
        <section class="product-grid" id="productGrid"></section>
      </main>
      <main class="page owner-only" id="promotions">
        <div class="page-heading">
          <div><p class="eyebrow" data-i18n="promotions.eyebrow">Discount Campaigns</p><h2 data-i18n="promotions.title">Promotions Management</h2></div>
          <button class="gold-button" id="newPromotionButton" data-i18n="promotions.addNew"><i class="bi bi-plus-circle-fill"></i> New promotion</button>
        </div>
        <div class="toolbar">
          <p class="bulk-hint promotions-hint" data-i18n="promotions.helpText">Promotions are percentage discounts with a start/end window. Activate one to make it live for sellers.</p>
        </div>
        <section class="promotions-list" id="promotionsList"></section>
      </main>
      <main class="page" id="sales">
        <div class="page-heading">
          <div><p class="eyebrow" data-i18n="sales.eyebrow">Point of Sale</p><h2 data-i18n="sales.title">Sales Management</h2></div>
          <button class="gold-button" id="receiptButton" data-i18n="sales.generateReceipt"><i class="bi bi-receipt"></i> Generate receipt</button>
        </div>
        <section class="pos-layout">
          <article class="panel">
            <div class="panel-title"><h3 data-i18n="sales.productSelection">Product Selection</h3><span data-i18n="sales.tapItems">Tap items to sell</span></div>
            <div class="pos-products" id="posProducts"></div>
          </article>
          <article class="panel receipt-panel">
            <h3 data-i18n="sales.currentSale">Current Sale</h3>
            <div id="cartList" class="cart-list"></div>
            <div class="receipt-row"><span data-i18n="sales.total">Total</span><strong id="saleTotal">TSH 0</strong></div>
            <div class="receipt-row owner-only"><span data-i18n="table.profit">Profit</span><strong id="saleProfit">TSH 0</strong></div>
            <div class="bulk-discount-wrap" id="bulkDiscountWrap">
              <label class="toggle-line">
                <input type="checkbox" id="bulkDiscountToggle" disabled />
                <span data-i18n="sales.bulkDiscount">Bulk customer discount (3+ items)</span>
              </label>
              <div class="bulk-controls">
                <span data-i18n="sales.bulkPercent">Discount %</span>
                <input type="number" id="bulkDiscountPercent" min="1" max="20" step="1" value="15" />
              </div>
              <p class="bulk-hint" id="bulkDiscountHint" data-i18n="sales.bulkNeedsItems">Add 3 or more items to enable bulk discount.</p>
            </div>
            <label><span data-i18n="sales.paymentMethod">Payment method</span>
              <select id="paymentMethod"><option value="cash" data-i18n="payment.cash">Cash</option><option value="card" data-i18n="payment.card">Card</option><option value="mobile_money" data-i18n="payment.mobileMoney">Mobile Money</option></select>
            </label>
            <button class="gold-button full" id="completePaymentButton" data-i18n="sales.paymentCompleted">Payment completed</button>
            <p class="receipt-note" id="receiptNote" data-i18n="sales.readyCheckout">Ready for checkout.</p>
            <p class="receipt-footer" id="receiptStoreRole"></p>
          </article>
        </section>
      </main>
      <main class="page owner-only" id="inventory">
        <div class="page-heading"><div><p class="eyebrow" data-i18n="inventory.eyebrow">Stock Room</p><h2 data-i18n="nav.inventory">Inventory</h2></div></div>
        <section class="stats-grid compact">
          <article class="stat-card"><span data-i18n="inventory.remainingStock">Remaining Stock</span><strong id="inventoryTotalStock">0</strong><small data-i18n="inventory.acrossSizes">Across all sizes</small></article>
          <article class="stat-card warning"><span data-i18n="inventory.lowStock">Low Stock</span><strong id="inventoryLowStock">0</strong><small data-i18n="inventory.below10">Below reorder level</small></article>
          <article class="stat-card danger"><span data-i18n="inventory.outOfStock">Out of Stock</span><strong id="inventoryOutStock">0</strong><small data-i18n="inventory.unavailable">Unavailable items</small></article>
        </section>
        <section class="inventory-list">
          <article class="panel stock-out"><h3 data-i18n="inventory.outStockSection">Out of stock</h3><ul id="outStockList" class="inventory-items"><li data-i18n="inventory.noOutStock">No out of stock items.</li></ul></article>
          <article class="panel stock-warning"><h3 data-i18n="inventory.lowStockWarning">Low stock warning</h3><ul id="lowStockList" class="inventory-items"><li data-i18n="inventory.noLowStock">No low stock items.</li></ul></article>
          <article class="panel"><h3 data-i18n="inventory.allProducts">All Products Stock</h3><ul id="allProductsList" class="inventory-items"></ul></article>
        </section>
      </main>
      <main class="page" id="reports">
        <div class="page-heading">
          <div><p class="eyebrow" data-i18n="reports.eyebrow">Performance Intelligence</p><h2 data-i18n="reports.title">Reports and Analytics</h2></div>
          <button class="gold-button" id="generateReportReportsButton" data-i18n="reports.generateReport"><i class="bi bi-file-earmark-bar-graph"></i> Generate Report</button>
        </div>
        <section class="report-grid">
          <article class="panel"><h3 data-i18n="reports.dailySales">Daily Sales</h3><strong id="reportDailySales">TSH 0</strong><p id="reportDailyNote" class="report-note" data-i18n="dashboard.noChartData">No sales data available yet.</p></article>
          <article class="panel"><h3 data-i18n="reports.weeklyReports">Weekly Reports</h3><strong id="reportWeeklySales">TSH 0</strong><p id="reportWeeklyNote" class="report-note" data-i18n="dashboard.noChartData">No sales data available yet.</p></article>
          <article class="panel"><h3 data-i18n="reports.monthlyReports">Monthly Reports</h3><strong id="reportMonthlySales">TSH 0</strong><p id="reportMonthlyNote" class="report-note" data-i18n="dashboard.noChartData">No sales data available yet.</p></article>
        </section>
        <section class="report-grid owner-only" id="financialReportGrid">
          <article class="panel financial-card profit-card"><h3 data-i18n="reports.dailyFinancial">Daily Financial</h3>
            <div class="financial-lines">
              <div class="fin-row"><span data-i18n="stats.dailyRevenue">Revenue</span><strong id="finDailyRevenue">TSH 0</strong></div>
              <div class="fin-row"><span data-i18n="stats.buyingCost">Buying Cost</span><strong id="finDailyBuyingCost">TSH 0</strong></div>
              <div class="fin-row fin-divider"><span data-i18n="stats.dailyProfit">Gross Profit</span><strong id="finDailyGrossProfit">TSH 0</strong></div>
              <div class="fin-row"><span data-i18n="stats.dailyExpenses">Expenses</span><strong id="finDailyExpensesGross">TSH 0</strong></div>
              <div class="fin-row fin-highlight"><span data-i18n="stats.dailyNetProfit">Net Profit</span><strong id="finDailyNetProfitGross">TSH 0</strong></div>
            </div>
          </article>
          <article class="panel financial-card profit-card"><h3 data-i18n="reports.monthlyFinancial">Monthly Financial</h3>
            <div class="financial-lines">
              <div class="fin-row"><span data-i18n="reports.monthlyReports">Sales</span><strong id="finMonthlySales">TSH 0</strong></div>
              <div class="fin-row"><span data-i18n="stats.buyingCost">Buying Cost</span><strong id="finMonthlyBuyingCost">TSH 0</strong></div>
              <div class="fin-row fin-divider"><span data-i18n="reports.profitAnalytics">Gross Profit</span><strong id="finMonthlyGrossProfit">TSH 0</strong></div>
              <div class="fin-row"><span data-i18n="stats.monthlyExpenses">Expenses</span><strong id="finMonthlyExpensesGross">TSH 0</strong></div>
              <div class="fin-row fin-highlight"><span data-i18n="stats.monthlyNetProfit">Net Profit</span><strong id="finMonthlyNetProfitGross">TSH 0</strong></div>
            </div>
          </article>
          <article class="panel financial-card profit-card"><h3 data-i18n="reports.yearlyFinancial">Yearly Financial</h3>
            <div class="financial-lines">
              <div class="fin-row"><span data-i18n="reports.yearlyRevenue">Revenue</span><strong id="finYearlyRevenue">TSH 0</strong></div>
              <div class="fin-row"><span data-i18n="stats.buyingCost">Buying Cost</span><strong id="finYearlyBuyingCost">TSH 0</strong></div>
              <div class="fin-row fin-divider"><span data-i18n="reports.yearlyGrossProfit">Gross Profit</span><strong id="finYearlyGrossProfit">TSH 0</strong></div>
              <div class="fin-row"><span data-i18n="stats.yearlyExpenses">Expenses</span><strong id="finYearlyExpenses">TSH 0</strong></div>
              <div class="fin-row fin-highlight"><span data-i18n="stats.yearlyNetProfit">Net Profit</span><strong id="finYearlyNetProfit">TSH 0</strong></div>
            </div>
          </article>
          <article class="panel financial-card expense-card"><h3 data-i18n="reports.expenseBreakdown">Expense Breakdown</h3>
            <div class="financial-lines" id="expenseBreakdownContainer">
              <span class="report-note" data-i18n="dashboard.noChartData">No expense data available yet.</span>
            </div>
          </article>
        </section>
        <article class="panel">
          <div class="panel-title"><h3 data-i18n="reports.revenueCharts">Revenue Charts</h3><span data-i18n="reports.monthlyGraph">Monthly graph</span></div>
          <div class="line-chart" id="reportChart"><p class="empty-state" data-i18n="dashboard.noChartData">No sales data available yet.</p></div>
        </article>
        <article class="panel">
          <h3 data-i18n="reports.bestSelling">Best Selling Products</h3>
          <div class="best-sellers" id="bestSellers"><span data-i18n="dashboard.noChartData">No sales data available yet.</span></div>
        </article>
      </main>
      <main class="page owner-only" id="analytics">
        <div class="page-heading">
          <div>
            <p class="eyebrow" data-i18n="analytics.eyebrow">Business Intelligence</p>
            <h2 data-i18n="nav.analytics">Analytics</h2>
          </div>
        </div>

        <div class="bi-date-filter" id="biDateFilter">
          <label data-i18n="analytics.period">Period:</label>
          <button class="bi-period-btn active" data-bi-period="today" data-i18n="common.today">Today</button>
          <button class="bi-period-btn" data-bi-period="yesterday" data-i18n="analytics.yesterday">Yesterday</button>
          <button class="bi-period-btn" data-bi-period="last_7_days" data-i18n="analytics.last7Days">Last 7 Days</button>
          <button class="bi-period-btn" data-bi-period="last_30_days" data-i18n="analytics.last30Days">Last 30 Days</button>
          <button class="bi-period-btn" data-bi-period="this_week" data-i18n="analytics.thisWeek">This Week</button>
          <button class="bi-period-btn" data-bi-period="last_week" data-i18n="analytics.lastWeek">Last Week</button>
          <button class="bi-period-btn" data-bi-period="this_month" data-i18n="analytics.thisMonth">This Month</button>
          <button class="bi-period-btn" data-bi-period="last_month" data-i18n="analytics.lastMonth">Last Month</button>
          <button class="bi-period-btn" data-bi-period="this_year" data-i18n="analytics.thisYear">This Year</button>
          <button class="bi-period-btn" data-bi-period="custom" data-i18n="analytics.custom">Custom</button>
          <div class="bi-custom-range hidden" id="biCustomRange">
            <input type="date" id="biStartDate" />
            <input type="date" id="biEndDate" />
            <button class="ghost-button" id="biApplyCustom" data-i18n="analytics.apply">Apply</button>
          </div>
        </div>

        <!-- View Switcher -->
        <div class="bi-view-switcher" role="tablist" aria-label="Business analysis views">
          <button type="button" class="bi-view-btn active" data-bi-view="overview" role="tab" aria-selected="true" aria-controls="biViewOverview" data-i18n="bi.viewOverview">Performance Overview</button>
          <button type="button" class="bi-view-btn" data-bi-view="breakdown" role="tab" aria-selected="false" aria-controls="biViewBreakdown" data-i18n="bi.viewBreakdown">Performance Breakdown</button>
        </div>

        <!-- ═══════════ VIEW 1 — PERFORMANCE OVERVIEW ═══════════ -->
        <div class="bi-view-panel" id="biViewOverview" role="tabpanel">
          <div class="bi-kpi-grid" id="biKpiGrid">
            <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.revenue">Revenue</span><span class="bi-kpi-value" id="biRevenue">TSH 0</span><span class="bi-kpi-sub" id="biRevenueCompare"></span></div>
            <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.grossProfit">Gross Profit</span><span class="bi-kpi-value gold" id="biGrossProfit">TSH 0</span><span class="bi-kpi-sub" id="biGrossProfitCompare"></span></div>
            <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.expenses">Expenses</span><span class="bi-kpi-value danger" id="biExpenses">TSH 0</span><span class="bi-kpi-sub" id="biExpensesCompare"></span></div>
            <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.netProfit">Net Profit</span><span class="bi-kpi-value success" id="biNetProfit">TSH 0</span><span class="bi-kpi-sub" id="biNetProfitCompare"></span></div>
            <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.numberOfSales">Number of Sales</span><span class="bi-kpi-value" id="biSalesCount">0</span><span class="bi-kpi-sub" id="biSalesCountCompare"></span></div>
            <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.itemsSold">Items Sold</span><span class="bi-kpi-value" id="biItemsSold">0</span><span class="bi-kpi-sub" id="biItemsSoldCompare"></span></div>
            <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.avgOrderValue">Avg Order Value</span><span class="bi-kpi-value" id="biAvgOrder">TSH 0</span></div>
            <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.profitMargin">Profit Margin</span><span class="bi-kpi-value" id="biProfitMargin">0%</span></div>
            <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.productsSold">Products Sold</span><span class="bi-kpi-value" id="biProductsSold">0</span></div>
            <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.activeSellers">Active Sellers</span><span class="bi-kpi-value" id="biActiveSellers">0</span></div>
          </div>

          <div class="bi-insights" id="biInsights"></div>

          <!-- Daily Summary (Owner) -->
          <div class="bi-subsection owner-only" id="biDailySummarySection">
            <h3 class="bi-subsection-title" data-i18n="analytics.dailySummary">Today's Summary</h3>
            <div class="bi-expense-flow">
              <div class="bi-flow-card flow-revenue"><div class="flow-label" data-i18n="analytics.revenue">Revenue</div><div class="flow-value" id="biDailyRevenue">TSH 0</div></div>
              <div class="bi-flow-card flow-gross"><div class="flow-label" data-i18n="analytics.grossProfit">Gross Profit</div><div class="flow-value" id="biDailyGrossProfit">TSH 0</div></div>
              <div class="bi-flow-card flow-expense"><div class="flow-label" data-i18n="analytics.expenses">Expenses</div><div class="flow-value" id="biDailyExpenses">TSH 0</div></div>
              <div class="bi-flow-card flow-net"><div class="flow-label" data-i18n="analytics.netProfit">Net Profit</div><div class="flow-value" id="biDailyNetProfit">TSH 0</div></div>
            </div>
            <div class="bi-kpi-grid" style="grid-template-columns: 1fr 1fr;">
              <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.topProduct">Top Product</span><span class="bi-kpi-value" id="biDailyTopProduct" style="font-size:15px">—</span></div>
              <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.topSeller">Top Seller</span><span class="bi-kpi-value" id="biDailyTopSeller" style="font-size:15px">—</span></div>
            </div>
          </div>

          <!-- Sales & Revenue Trend + Profit & Expense Trend -->
          <div class="bi-trend-grid">
            <div class="bi-chart-panel">
              <div class="panel-title">
                <h3 data-i18n="analytics.salesTrend">Sales & Revenue Trend</h3>
                <span id="biSalesTrendRange" data-i18n="analytics.last7Days">Last 7 Days</span>
              </div>
              <div class="bi-chart-canvas amcharts-chart" id="biSalesTrendChart">
                <div class="bi-empty-state"><i class="bi bi-bar-chart-line"></i><p data-i18n="analytics.noData">No sales data available for this period.</p></div>
              </div>
            </div>

            <div class="bi-chart-panel">
              <div class="panel-title">
                <h3 data-i18n="analytics.profitTrend">Profit & Expense Trend</h3>
                <span id="biProfitTrendRange"></span>
              </div>
              <div class="bi-chart-canvas amcharts-chart" id="biProfitTrendChart">
                <div class="bi-empty-state"><i class="bi bi-graph-up"></i><p data-i18n="analytics.noData">No profit data available for this period.</p></div>
              </div>
            </div>
          </div>

          <!-- Expense Impact (Owner) -->
          <div class="bi-subsection owner-only" id="biExpenseSection" style="margin-top:18px">
            <div class="bi-expense-flow" id="biExpenseFlow">
              <div class="bi-flow-card flow-revenue"><div class="flow-label" data-i18n="analytics.revenue">Revenue</div><div class="flow-value" id="biExpRevenue">TSH 0</div></div>
              <div class="bi-flow-card flow-gross"><div class="flow-label" data-i18n="analytics.grossProfit">Gross Profit</div><div class="flow-value" id="biExpGrossProfit">TSH 0</div></div>
              <div class="bi-flow-card flow-expense"><div class="flow-label" data-i18n="analytics.expenses">Expenses</div><div class="flow-value" id="biExpExpenses">TSH 0</div></div>
              <div class="bi-flow-card flow-net"><div class="flow-label" data-i18n="analytics.netProfit">Net Profit</div><div class="flow-value" id="biExpNetProfit">TSH 0</div></div>
            </div>
            <div class="panel" style="padding:16px">
              <h3 data-i18n="analytics.expenseBreakdown">Expense Breakdown</h3>
              <div class="bi-table-wrap">
                <table class="bi-table" id="biExpenseBreakdownTable">
                  <thead><tr><th data-i18n="table.category">Category</th><th class="align-right" data-i18n="table.amount">Amount</th><th class="align-right">% of Total</th></tr></thead>
                  <tbody id="biExpenseBreakdownBody"></tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Discount Analysis -->
          <div class="bi-subsection" id="biDiscountSection" style="margin-top:18px">
            <div class="bi-kpi-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); margin-bottom:18px;">
              <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.totalDiscount">Total Discount</span><span class="bi-kpi-value danger" id="biDiscountTotal">TSH 0</span></div>
              <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.discountedItems">Discounted Items</span><span class="bi-kpi-value" id="biDiscountedItems">0</span></div>
              <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.discountRate">Discount Rate</span><span class="bi-kpi-value" id="biDiscountRate">0%</span></div>
              <div class="bi-kpi-card"><span class="bi-kpi-label" data-i18n="analytics.discountedSales">Discounted Sales</span><span class="bi-kpi-value" id="biDiscountedSales">0</span></div>
            </div>
            <div class="panel" style="padding:16px">
              <h3 data-i18n="analytics.promotionPerformance">Promotion Performance</h3>
              <div class="bi-table-wrap">
                <table class="bi-table" id="biPromoTable">
                  <thead>
                    <tr>
                      <th data-i18n="promotions.name">Promotion</th>
                      <th class="align-right">%</th>
                      <th class="align-right" data-i18n="analytics.salesCount">Sales</th>
                      <th class="align-right" data-i18n="analytics.itemsSold">Items</th>
                      <th class="align-right" data-i18n="analytics.revenue">Revenue</th>
                      <th class="align-right" data-i18n="analytics.grossProfit">Profit</th>
                      <th class="align-right" data-i18n="analytics.discountGiven">Discount</th>
                    </tr>
                  </thead>
                  <tbody id="biPromoBody"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- ═══════════ VIEW 2 — PERFORMANCE BREAKDOWN ═══════════ -->
        <div class="bi-view-panel hidden" id="biViewBreakdown" role="tabpanel">
          <!-- Seller Performance -->
          <div class="panel" style="padding:16px">
            <div class="panel-title">
              <h3 data-i18n="analytics.sellerRanking">Seller Performance</h3>
            </div>
            <div class="bi-sort-bar" id="biSellerSort">
              <button class="bi-sort-btn active" data-sort="revenue" data-i18n="analytics.byRevenue">By Revenue</button>
              <button class="bi-sort-btn" data-sort="profit" data-i18n="analytics.byProfit">By Profit</button>
              <button class="bi-sort-btn" data-sort="sales" data-i18n="analytics.bySales">By Sales</button>
              <button class="bi-sort-btn" data-sort="items" data-i18n="analytics.byItems">By Items</button>
            </div>
            <div class="bi-chart-canvas amcharts-chart" id="biSellerRankingChart">
              <div class="bi-empty-state"><i class="bi bi-people"></i><p data-i18n="analytics.noData">No seller data available for this period.</p></div>
            </div>
            <div class="bi-table-wrap">
              <table class="bi-table" id="biSellerTable">
                <thead>
                  <tr>
                    <th>#</th>
                    <th data-i18n="users.name">Seller</th>
                    <th class="align-right" data-i18n="analytics.salesCount">Sales</th>
                    <th class="align-right" data-i18n="analytics.itemsSold">Items</th>
                    <th class="align-right" data-i18n="analytics.revenue">Revenue</th>
                    <th class="align-right" data-i18n="analytics.grossProfit">Profit</th>
                    <th class="align-right" data-i18n="analytics.profitMargin">Margin</th>
                    <th class="align-right" data-i18n="analytics.avgOrderValue">Avg Order</th>
                    <th class="align-right" data-i18n="analytics.discountGiven">Discounts</th>
                  </tr>
                </thead>
                <tbody id="biSellerBody"></tbody>
              </table>
            </div>
          </div>

          <div class="bi-subsection" style="margin-top:18px">
            <h3 class="bi-subsection-title" data-i18n="analytics.sellerTrend">Seller Trend</h3>
            <select class="bi-seller-select" id="biSellerSelect"><option value="" data-i18n="analytics.selectSeller">Select a seller</option></select>
            <div class="bi-chart-panel" style="margin-top:12px">
              <div class="bi-chart-canvas amcharts-chart" id="biSellerTrendChart">
                <div class="bi-empty-state"><i class="bi bi-person-line-dotted"></i><p data-i18n="analytics.selectSellerHint">Select a seller to view their trend.</p></div>
              </div>
            </div>
          </div>

          <!-- Product Performance -->
          <div class="panel" style="padding:16px">
            <div class="panel-title">
              <h3 data-i18n="analytics.productRanking">Product Performance</h3>
            </div>
            <div class="bi-sort-bar" id="biProductSort">
              <button class="bi-sort-btn active" data-sort="revenue" data-i18n="analytics.byRevenue">By Revenue</button>
              <button class="bi-sort-btn" data-sort="profit" data-i18n="analytics.byProfit">By Profit</button>
              <button class="bi-sort-btn" data-sort="quantity" data-i18n="analytics.byQuantity">By Quantity</button>
            </div>
            <div class="bi-chart-canvas amcharts-chart" id="biProductRankingChart">
              <div class="bi-empty-state"><i class="bi bi-box-seam"></i><p data-i18n="analytics.noData">No product data available for this period.</p></div>
            </div>
            <div class="bi-table-wrap">
              <table class="bi-table" id="biProductTable">
                <thead>
                  <tr>
                    <th>#</th>
                    <th data-i18n="products.name">Product</th>
                    <th data-i18n="table.category">Category</th>
                    <th class="align-right" data-i18n="analytics.quantitySold">Qty Sold</th>
                    <th class="align-right" data-i18n="analytics.revenue">Revenue</th>
                    <th class="align-right" data-i18n="analytics.grossProfit">Profit</th>
                    <th class="align-right" data-i18n="analytics.profitMargin">Margin</th>
                    <th class="align-right" data-i18n="analytics.avgSellingPrice">Avg Price</th>
                    <th class="align-right" data-i18n="analytics.stock">Stock</th>
                  </tr>
                </thead>
                <tbody id="biProductBody"></tbody>
              </table>
            </div>
          </div>

          <div class="bi-subsection" style="margin-top:18px">
            <h3 class="bi-subsection-title" data-i18n="analytics.productCategories">Product Categories</h3>
            <div class="bi-kpi-grid" id="biProductCategories" style="grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));"></div>
          </div>

          <div class="bi-subsection" style="margin-top:18px">
            <h3 class="bi-subsection-title" data-i18n="analytics.productTrend">Product Trend</h3>
            <select class="bi-seller-select" id="biProductSelect"><option value="" data-i18n="analytics.selectProduct">Select a product</option></select>
            <div class="bi-chart-panel" style="margin-top:12px">
              <div class="bi-chart-canvas amcharts-chart" id="biProductTrendChart">
                <div class="bi-empty-state"><i class="bi bi-box-seam"></i><p data-i18n="analytics.selectProductHint">Select a product to view its trend.</p></div>
              </div>
            </div>
          </div>
        </div>

      </main>
      <main class="page owner-only" id="users">
        <div class="page-heading">
          <div><p class="eyebrow" data-i18n="users.eyebrow">Team Access</p><h2 data-i18n="users.title">User Management</h2></div>
        </div>
        <section class="expense-layout">
          <form class="panel expense-form" id="userForm">
            <h3 data-i18n="users.registerEmployee">Register employee</h3>
            <input id="employeeName" placeholder="Name" data-i18n-placeholder="users.name" />
            <input id="employeeUsername" placeholder="Username" data-i18n-placeholder="login.username" />
            <input id="employeeEmail" type="email" placeholder="Email" data-i18n-placeholder="users.email" />
            <div class="input-icon-wrap password-wrap"><i class="bi bi-lock"></i><input id="employeePassword" type="password" placeholder="Password" data-i18n-placeholder="login.password" autocomplete="new-password" /><button type="button" class="password-toggle" id="employeePasswordToggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
            <input type="hidden" id="employeeRole" value="SELLER" />
            <p class="form-hint" data-i18n="users.sellerOnlyHint">New employees are registered as SELLER.</p>
            <button class="gold-button full" type="submit" data-i18n="users.createUser">Create user</button>
          </form>
          <article class="panel">
            <h3 data-i18n="users.employees">Employees</h3>
            <div class="table-wrap">
              <table>
                <thead><tr><th data-i18n="users.name">Name</th><th data-i18n="login.username">Username</th><th data-i18n="users.role">Role</th><th data-i18n="table.status">Status</th><th data-i18n="users.actions">Actions</th></tr></thead>
                <tbody id="usersBody"><tr><td colspan="5" data-i18n="users.noUsers">No users yet.</td></tr></tbody>
              </table>
            </div>
          </article>
        </section>
      </main>
      <main class="page" id="expenses">
        <div class="page-heading">
          <div><p class="eyebrow" data-i18n="expenses.eyebrow">Cost Tracking</p><h2 data-i18n="nav.expenses">Expenses</h2></div>
          <button class="gold-button" id="toggleExpenseForm" data-i18n="expenses.recordExpense"><i class="bi bi-plus-circle-fill"></i> Record expense</button>
        </div>
        <section class="expense-layout">
          <article class="panel expense-form hidden" id="expenseFormPanel">
            <h3 data-i18n="expenses.recordShopExpenses">Record shop expenses</h3>
            <label class="sr-only" for="expenseCategorySelect" data-i18n="expenses.categoryLabel">Expense category</label>
            <select id="expenseCategorySelect" aria-label="Expense category" data-i18n-aria-label="expenses.categoryLabel">
              <option value="Food" data-i18n="expenseCategory.food">Food</option>
              <option value="Transport" data-i18n="expenseCategory.transport">Transport</option>
              <option value="Rent" data-i18n="expenseCategory.rent">Rent</option>
              <option value="TRA" data-i18n="expenseCategory.tra">TRA</option>
              <option value="Electricity" data-i18n="expenseCategory.electricity">Electricity</option>
              <option value="Water" data-i18n="expenseCategory.water">Water</option>
              <option value="Salary" data-i18n="expenseCategory.salary">Salary</option>
              <option value="Maintenance" data-i18n="expenseCategory.maintenance">Maintenance</option>
              <option value="Other" data-i18n="expenseCategory.other">Other</option>
            </select>
            <label class="sr-only" for="expenseCustomName" data-i18n="expenses.expenseNameLabel">Expense name</label>
            <input placeholder="Expense name (for Other category)" id="expenseCustomName" class="hidden" data-i18n-placeholder="expenses.expenseNamePlaceholder" />
            <label class="sr-only" for="expenseDescription" data-i18n="expenses.descriptionLabel">Description</label>
            <input placeholder="Description (optional)" id="expenseDescription" data-i18n-placeholder="expenses.descriptionPlaceholder" />
            <div class="expense-date-amount">
              <div><label class="sr-only" for="expenseAmountInput" data-i18n="table.amount">Amount</label>
              <input placeholder="Amount" id="expenseAmountInput" type="number" min="0" step="1" data-i18n-placeholder="table.amount" /></div>
              <div class="expense-date-wrap"><label class="sr-only" for="expenseDateInput" data-i18n="expenses.dateLabel">Date</label>
              <input id="expenseDateInput" type="date" /></div>
            </div>
            <p class="form-hint" id="expenseFormError" style="color:var(--danger);display:none"></p>
            <button class="gold-button full" id="saveExpenseButton" data-i18n="expenses.saveExpense">Save expense</button>
          </article>
          <article class="panel">
            <h3 data-i18n="expenses.tracking">Expense summary</h3>
            <div class="expense-row"><span data-i18n="common.today">Today</span><strong id="expenseToday">TSH 0</strong></div>
            <div class="expense-row"><span data-i18n="expenses.thisMonth">This Month</span><strong id="expenseMonth">TSH 0</strong></div>
            <h4 style="margin-top:18px" data-i18n="expenses.todayCategories">Today's Expenses by Category</h4>
            <div id="expenseCategoryBreakdown"></div>
          </article>
        </section>
        <article class="panel" style="margin-top:18px">
          <div class="panel-title">
            <h3 data-i18n="expenses.recentExpenses">Recent Expenses</h3>
          </div>
          <div class="table-wrap">
            <table class="expense-scroll-table">
              <thead><tr><th data-i18n="table.date">Date</th><th data-i18n="table.category">Category</th><th data-i18n="expenses.descriptionLabel">Description</th><th data-i18n="table.amount">Amount</th><th data-i18n="users.name">Created By</th><th class="owner-only" data-i18n="users.actions">Actions</th></tr></thead>
              <tbody id="expensesBody">
                <tr><td colspan="6" data-i18n="expenses.noExpenses">No expenses recorded yet.</td></tr>
              </tbody>
            </table>
          </div>
        </article>
      </main>
      <main class="page owner-only" id="audit">
        <div class="page-heading">
          <div><p class="eyebrow" data-i18n="audit.eyebrow">Security &amp; Compliance</p><h2 data-i18n="nav.audit">Audit Logs</h2></div>
          <button class="gold-button" id="auditRefreshBtn"><i class="bi bi-arrow-clockwise"></i> <span data-i18n="audit.refresh">Refresh</span></button>
        </div>

        <section class="panel audit-filters">
          <div class="audit-filter-grid">
            <div class="audit-filter-field audit-search">
              <label class="sr-only" for="auditSearch" data-i18n="common.search">Search</label>
              <div class="input-icon-wrap">
                <i class="bi bi-search"></i>
                <input id="auditSearch" type="text" placeholder="Search" data-i18n-placeholder="audit.searchPlaceholder" />
              </div>
            </div>
            <div class="audit-filter-field">
              <label class="sr-only" for="auditDateFrom" data-i18n="audit.dateFrom">From date</label>
              <input id="auditDateFrom" type="date" aria-label="From date" data-i18n-aria-label="audit.dateFrom" />
            </div>
            <div class="audit-filter-field">
              <label class="sr-only" for="auditDateTo" data-i18n="audit.dateTo">To date</label>
              <input id="auditDateTo" type="date" aria-label="To date" data-i18n-aria-label="audit.dateTo" />
            </div>
            <div class="audit-filter-field">
              <select id="auditUserFilter" aria-label="User" data-i18n-aria-label="audit.user"><option value="" data-i18n="audit.allUsers">All Users</option></select>
            </div>
            <div class="audit-filter-field">
              <select id="auditRoleFilter" aria-label="Role" data-i18n-aria-label="audit.role"><option value="" data-i18n="audit.allRoles">All Roles</option><option value="OWNER">OWNER</option><option value="SELLER">SELLER</option></select>
            </div>
            <div class="audit-filter-field">
              <select id="auditModuleFilter" aria-label="Module" data-i18n-aria-label="audit.module"><option value="" data-i18n="audit.allModules">All Modules</option></select>
            </div>
            <div class="audit-filter-field">
              <select id="auditActionFilter" aria-label="Action" data-i18n-aria-label="audit.action"><option value="" data-i18n="audit.allActions">All Actions</option></select>
            </div>
            <div class="audit-filter-field">
              <select id="auditEntityFilter" aria-label="Entity type" data-i18n-aria-label="audit.entityType"><option value="" data-i18n="audit.allEntities">All Entity Types</option></select>
            </div>
          </div>
          <div class="audit-filter-actions">
            <button type="button" class="ghost-button" id="auditApplyFilters"><i class="bi bi-funnel-fill"></i> <span data-i18n="audit.applyFilters">Apply Filters</span></button>
            <button type="button" class="ghost-button" id="auditResetFilters"><i class="bi bi-arrow-counterclockwise"></i> <span data-i18n="audit.resetFilters">Reset</span></button>
          </div>
        </section>

        <article class="panel" style="margin-top:18px">
          <div class="panel-title">
            <h3 data-i18n="audit.logEntries">Log Entries</h3>
            <span id="auditResultCount"></span>
          </div>
          <div class="table-wrap">
            <table class="audit-table">
              <thead><tr><th data-i18n="audit.dateTime">Date &amp; Time</th><th data-i18n="audit.user">User</th><th data-i18n="audit.role">Role</th><th data-i18n="audit.action">Action</th><th data-i18n="audit.module">Module</th><th data-i18n="audit.description">Description</th><th data-i18n="audit.ip">IP Address</th><th data-i18n="audit.actions">Actions</th></tr></thead>
              <tbody id="auditBody">
                <tr><td colspan="8" data-i18n="audit.loading">Loading audit logs...</td></tr>
              </tbody>
            </table>
          </div>
          <div class="audit-pagination" id="auditPagination"></div>
        </article>
      </main>
      <main class="page owner-only" id="backup">
        <div class="page-heading">
          <div><p class="eyebrow" data-i18n="backup.eyebrow">Data Protection</p><h2 data-i18n="backup.title">Backup Management</h2></div>
          <div class="backup-heading-actions">
            <button class="ghost-button" id="backupRefreshBtn"><i class="bi bi-arrow-clockwise"></i> <span data-i18n="audit.refresh">Refresh</span></button>
          </div>
        </div>

        <section class="stats-grid backup-status-grid">
          <article class="stat-card backup-stat">
            <span data-i18n="backup.lastDbBackup">Last Database Backup</span>
            <strong id="backupLastDb">—</strong>
            <small id="backupLastDbMeta" data-i18n="backup.never">Never created</small>
          </article>
          <article class="stat-card backup-stat">
            <span data-i18n="backup.lastFilesBackup">Last Files Backup</span>
            <strong id="backupLastFiles">—</strong>
            <small id="backupLastFilesMeta" data-i18n="backup.never">Never created</small>
          </article>
          <article class="stat-card backup-stat">
            <span data-i18n="backup.lastFullBackup">Last Full Backup</span>
            <strong id="backupLastFull">—</strong>
            <small id="backupLastFullMeta" data-i18n="backup.never">Never created</small>
          </article>
          <article class="stat-card backup-stat">
            <span data-i18n="backup.totalBackups">Available Backups</span>
            <strong id="backupCount">0</strong>
            <small id="backupTotalSize">0 B total</small>
          </article>
        </section>

        <article class="panel backup-actions-panel">
          <div class="panel-title">
            <h3 data-i18n="backup.actions">Backup Actions</h3>
            <span data-i18n="backup.actionsHint">Manual operations</span>
          </div>
          <div class="backup-action-row">
            <div class="backup-action-card">
              <i class="bi bi-database-fill backup-action-icon"></i>
              <h4 data-i18n="backup.dbBackup">Database Backup</h4>
              <p data-i18n="backup.dbBackupDesc">Snapshot of all tables, data, indexes and relationships.</p>
              <button class="gold-button" id="createDbBackupBtn" data-i18n="backup.createDbBackup"><i class="bi bi-plus-circle-fill"></i> <span class="btn-text" data-i18n="backup.createDbBackup">Create Database Backup</span><span class="btn-loading-text">Creating Backup...</span></button>
            </div>
            <div class="backup-action-card">
              <i class="bi bi-folder-fill backup-action-icon file"></i>
              <h4 data-i18n="backup.filesBackup">Files Backup</h4>
              <p data-i18n="backup.filesBackupDesc">Archive of product images and other user uploads.</p>
              <button class="gold-button" id="createFilesBackupBtn" data-i18n="backup.createFilesBackup"><i class="bi bi-plus-circle-fill"></i> <span class="btn-text" data-i18n="backup.createFilesBackup">Create Files Backup</span><span class="btn-loading-text">Creating Backup...</span></button>
            </div>
            <div class="backup-action-card">
              <i class="bi bi-box-seam-fill backup-action-icon full"></i>
              <h4 data-i18n="backup.fullBackup">Full Backup</h4>
              <p data-i18n="backup.fullBackupDesc">Database + files in one self-contained archive.</p>
              <button class="gold-button" id="createFullBackupBtn" data-i18n="backup.createFullBackup"><i class="bi bi-plus-circle-fill"></i> <span class="btn-text" data-i18n="backup.createFullBackup">Create Full Backup</span><span class="btn-loading-text">Creating Backup...</span></button>
            </div>
          </div>
        </article>

        <div class="backup-layout">
          <article class="panel backup-retention-panel">
            <div class="panel-title">
              <h3 data-i18n="backup.retention">Retention Policy</h3>
              <span data-i18n="backup.retentionHint">Number of backups to keep</span>
            </div>
            <div class="retention-form">
              <label class="retention-field"><span data-i18n="backup.keepDaily">Daily backups</span><input type="number" id="retentionDaily" min="0" max="365" step="1" /></label>
              <label class="retention-field"><span data-i18n="backup.keepWeekly">Weekly backups</span><input type="number" id="retentionWeekly" min="0" max="156" step="1" /></label>
              <label class="retention-field"><span data-i18n="backup.keepMonthly">Monthly backups</span><input type="number" id="retentionMonthly" min="0" max="120" step="1" /></label>
              <label class="retention-field"><span data-i18n="backup.keepFull">Full backups</span><input type="number" id="retentionFull" min="0" max="30" step="1" /></label>
              <div class="retention-actions">
                <button class="ghost-button" id="saveRetentionBtn"><i class="bi bi-save-fill"></i> <span data-i18n="backup.saveRetention">Save Retention</span></button>
                <button class="ghost-button" id="runCleanupBtn"><i class="bi bi-broom"></i> <span data-i18n="backup.runCleanup">Run Cleanup</span></button>
              </div>
            </div>
            <div class="backup-storage-info">
              <p class="form-hint" id="backupStorageInfo"></p>
            </div>
          </article>

          <article class="panel backup-storage-panel">
            <div class="panel-title">
              <h3 data-i18n="backup.storageTitle">Storage</h3>
              <span data-i18n="backup.storageHint">Where backups live</span>
            </div>
            <div class="storage-detail">
              <div class="storage-row"><span data-i18n="backup.location">Location</span><strong id="backupStorageLocationId" class="storage-value">—</strong></div>
              <div class="storage-row"><span data-i18n="backup.totalSize">Total backup size</span><strong id="backupTotalSizeDetail" class="storage-value">—</strong></div>
              <div class="storage-row"><span data-i18n="backup.database">Database</span><strong id="backupCountDb" class="storage-value">—</strong></div>
              <div class="storage-row"><span data-i18n="backup.files">Files</span><strong id="backupCountFiles" class="storage-value">—</strong></div>
              <div class="storage-row"><span data-i18n="backup.full">Full</span><strong id="backupCountFull" class="storage-value">—</strong></div>
              <div class="storage-row"><span data-i18n="backup.operationStatus">Operation status</span><strong id="backupOperationStatus" class="storage-value">—</strong></div>
            </div>
          </article>
        </div>

        <article class="panel backup-history-panel">
          <div class="panel-title">
            <h3 data-i18n="backup.history">Backup History</h3>
            <span id="backupHistoryCount"></span>
          </div>
          <div class="table-wrap">
            <table class="backup-table">
              <thead><tr>
                <th data-i18n="backup.thName">Backup Name</th>
                <th data-i18n="backup.thType">Type</th>
                <th data-i18n="backup.thDate">Date / Time</th>
                <th data-i18n="backup.thSize">Size</th>
                <th data-i18n="backup.thStatus">Status</th>
                <th data-i18n="backup.thCreator">Created By</th>
                <th data-i18n="backup.thActions">Actions</th>
              </tr></thead>
              <tbody id="backupHistoryBody">
                <tr><td colspan="7" data-i18n="backup.loading">Loading backups...</td></tr>
              </tbody>
            </table>
          </div>
        </article>
      </main>
      <main class="page owner-only" id="settings">
        <div class="page-heading"><div><p class="eyebrow" data-i18n="settings.eyebrow">Workspace Control</p><h2 data-i18n="nav.settings">Settings</h2></div></div>
        <section class="settings-grid">
          <article class="panel settings-card">
            <h3 data-i18n="settings.shopInformation">Shop information</h3>
            <label for="shopName" data-i18n="settings.shopName">Shop name</label>
            <input id="shopName" aria-label="Shop name" data-i18n-aria-label="settings.shopName" />
            <label for="shopAddress" data-i18n="settings.shopAddress">Shop address</label>
            <input id="shopAddress" aria-label="Shop address" data-i18n-aria-label="settings.shopAddress" />
            <label for="shopPhone" data-i18n="settings.shopPhone">Shop phone</label>
            <input id="shopPhone" aria-label="Shop phone" data-i18n-aria-label="settings.shopPhone" />
          </article>
          <article class="panel settings-card">
            <h3 data-i18n="settings.adminProfile">Admin profile settings</h3>
            <label for="adminName" data-i18n="settings.adminName">Admin full name</label>
            <input id="adminName" aria-label="Admin full name" data-i18n-aria-label="settings.adminName" />
            <label for="adminEmail" data-i18n="settings.adminEmail">Admin email</label>
            <input id="adminEmail" aria-label="Admin email" data-i18n-aria-label="settings.adminEmail" />
            <label for="adminPassword" data-i18n="settings.adminPassword">Admin password</label>
            <div class="input-icon-wrap password-wrap"><i class="bi bi-lock"></i><input id="adminPassword" type="password" aria-label="Admin password" data-i18n-aria-label="settings.adminPassword" autocomplete="new-password" /><button type="button" class="password-toggle" id="adminPasswordToggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
          </article>
          <article class="panel settings-card">
            <h3 data-i18n="settings.systemPreferences">System preferences</h3>
            <label class="toggle-line"><span data-i18n="settings.darkMode">Dark mode</span> <input type="checkbox" id="darkModeToggle" /></label>
            <label class="toggle-line"><span data-i18n="settings.lowStockAlerts">Low stock alerts</span> <input type="checkbox" checked /></label>
            <label class="toggle-line"><span data-i18n="settings.receiptPrinting">Receipt printing</span> <input type="checkbox" id="receiptPrintingToggle" checked /></label>
            <label for="lowStockThreshold" data-i18n="settings.lowStockThreshold">Low stock threshold</label>
            <input type="number" id="lowStockThreshold" min="1" step="1" value="5" />
            <label for="receiptFooter" data-i18n="settings.receiptFooter">Receipt footer</label>
            <input type="text" id="receiptFooter" />
          </article>
          <article class="panel settings-card owner-only">
            <h3 data-i18n="settings.maintenance">System Maintenance</h3>
            <label class="toggle-line"><span data-i18n="settings.maintenanceMode">Maintenance Mode</span> <input type="checkbox" id="maintenanceModeToggle" /></label>
            <p class="form-hint" data-i18n="settings.maintenanceHint">When enabled, only owners can log in. All other users will see a maintenance screen.</p>
            <label for="maintenanceMessageInput" data-i18n="settings.maintenanceMessage">Maintenance message</label>
            <input type="text" id="maintenanceMessageInput" value="System is under maintenance. Please try again later." data-i18n-placeholder="settings.maintenanceMessage" />
            <button class="gold-button" type="button" id="saveMaintenanceButton" data-i18n="settings.saveMaintenance"><i class="bi bi-tools"></i> Save maintenance settings</button>
          </article>
        </section>
        <div class="settings-actions">
          <p class="form-hint" id="settingsMessage" role="status"></p>
          <button class="gold-button" type="button" id="saveSettingsButton"><i class="bi bi-save-fill"></i> Save Settings</button>
        </div>
      </main>
    </section>
  </div>

  <div class="modal-overlay hidden" id="reportWizardModal">
    <div class="modal-dialog wizard-dialog">
      <div class="modal-head">
        <h3 data-i18n="wizard.title">Generate Report</h3>
        <button type="button" class="reset-close" id="wizardClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>

      <div class="wizard-steps">
        <span class="wizard-step active" data-step="1"><i class="bi bi-calendar3"></i> <span data-i18n="wizard.stepPeriod">Period</span></span>
        <span class="wizard-step" data-step="2"><i class="bi bi-list-check"></i> <span data-i18n="wizard.stepContent">Content</span></span>
        <span class="wizard-step" data-step="3"><i class="bi bi-file-earmark-arrow-down"></i> <span data-i18n="wizard.stepFormat">Format</span></span>
      </div>

      <div class="wizard-panel" id="wizardStep1">
        <label class="wizard-field"><span data-i18n="wizard.selectPeriod">Select period</span>
          <select id="wizardPeriod">
            <option value="today" data-i18n="wizard.today">Today</option>
            <option value="week" data-i18n="wizard.week">This Week (rolling 7 days)</option>
            <option value="month" data-i18n="wizard.month">This Month</option>
            <option value="year" data-i18n="wizard.year">This Year</option>
            <option value="custom" data-i18n="wizard.custom">Custom Range</option>
          </select>
        </label>
        <div class="wizard-date-row hidden" id="wizardCustomDates">
          <label class="wizard-field"><span data-i18n="wizard.startDate">Start date</span><input type="date" id="wizardStartDate" /></label>
          <label class="wizard-field"><span data-i18n="wizard.endDate">End date</span><input type="date" id="wizardEndDate" /></label>
        </div>
        <p class="form-hint" id="wizardPeriodHint"></p>
        <div class="modal-actions">
          <button type="button" class="ghost-button" id="wizardCancel" data-i18n="common.cancel">Cancel</button>
          <button type="button" class="gold-button" id="wizardNext1"><i class="bi bi-arrow-right"></i> <span data-i18n="wizard.next">Next</span></button>
        </div>
      </div>

      <div class="wizard-panel hidden" id="wizardStep2">
        <div class="wizard-type-row">
          <label class="wizard-radio"><input type="radio" name="wizardType" value="general" checked /> <span data-i18n="wizard.general">General (all sections)</span></label>
          <label class="wizard-radio"><input type="radio" name="wizardType" value="custom" /> <span data-i18n="wizard.customSections">Choose sections</span></label>
        </div>
        <div class="wizard-categories hidden" id="wizardCategories"></div>
        <div class="modal-actions">
          <button type="button" class="ghost-button" id="wizardBack2"><i class="bi bi-arrow-left"></i> <span data-i18n="wizard.back">Back</span></button>
          <button type="button" class="gold-button" id="wizardNext2"><i class="bi bi-arrow-right"></i> <span data-i18n="wizard.next">Next</span></button>
        </div>
      </div>

      <div class="wizard-panel hidden" id="wizardStep3">
        <div class="wizard-format-row">
          <label class="wizard-radio"><input type="radio" name="wizardFormat" value="pdf" checked /> <i class="bi bi-filetype-pdf"></i> <span data-i18n="wizard.pdf">PDF (A4 report)</span></label>
          <label class="wizard-radio"><input type="radio" name="wizardFormat" value="xlsx" /> <i class="bi bi-file-earmark-excel"></i> <span data-i18n="wizard.xlsx">Excel (XLSX)</span></label>
        </div>
        <p class="form-hint" id="wizardGenerateHint"></p>
        <div class="modal-actions">
          <button type="button" class="ghost-button" id="wizardBack3"><i class="bi bi-arrow-left"></i> <span data-i18n="wizard.back">Back</span></button>
          <button type="button" class="gold-button" id="wizardGenerate"><i class="bi bi-download"></i> <span data-i18n="wizard.generateDownload">Generate &amp; Download</span></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Recovery modal — Step 1: Verify Identity -->
  <div class="modal-overlay hidden" id="recoveryModal">
    <div class="reset-dialog">
      <button type="button" class="reset-close" id="recoveryClose"><i class="bi bi-x-lg"></i></button>
      <div class="logo-lockup" style="margin-bottom:20px">
        <img src="assets/images/logo.png" alt="Mpeli Outfit Store" class="login-logo-circle" style="width:48px;height:48px">
        <div>
          <strong>Mpeli Outfit Store</strong>
          <small data-i18n="recovery.title">Account Recovery</small>
        </div>
      </div>

      <!-- Step 1: Verify Identity -->
      <div id="recoveryStep1">
        <h3 data-i18n="recovery.step1Heading">Verify Your Identity</h3>
        <p class="reset-info" data-i18n="recovery.step1Description">Enter your username and email address to verify your account.</p>
        <form id="recoveryVerifyForm">
          <label><span data-i18n="recovery.username">Username</span><div class="input-icon-wrap"><i class="bi bi-person"></i><input type="text" id="recoveryUsername" required autocomplete="username" /></div></label>
          <label><span data-i18n="recovery.email">Email Address</span><div class="input-icon-wrap"><i class="bi bi-envelope"></i><input type="email" id="recoveryEmail" required autocomplete="email" /></div></label>
          <div class="reset-actions">
            <button type="button" class="ghost-button" id="recoveryCancel" data-i18n="common.cancel">Cancel</button>
            <button type="submit" class="gold-button" id="recoveryVerifyBtn"><i class="bi bi-shield-check"></i> <span data-i18n="recovery.verify">Verify Identity</span></button>
          </div>
          <p class="form-hint" id="recoveryStep1Result" style="margin-top:12px;white-space:pre-line"></p>
        </form>
      </div>

      <!-- Step 2: Set New Password (hidden by default) -->
      <div id="recoveryStep2" class="hidden">
        <h3 data-i18n="recovery.step2Heading">Set New Password</h3>
        <p class="reset-info" data-i18n="recovery.step2Description">Identity verified. Choose a new password for your account.</p>
        <form id="recoveryResetForm">
          <label><span data-i18n="recovery.newPassword">New Password</span><div class="input-icon-wrap"><i class="bi bi-lock"></i><input type="password" id="recoveryNewPassword" required autocomplete="new-password" /></div></label>
          <label><span data-i18n="recovery.confirmPassword">Confirm New Password</span><div class="input-icon-wrap"><i class="bi bi-lock"></i><input type="password" id="recoveryConfirmPassword" required autocomplete="new-password" /></div></label>
          <div class="reset-actions">
            <button type="button" class="ghost-button" id="recoveryBackToVerify" data-i18n="common.cancel">Back</button>
            <button type="submit" class="gold-button" id="recoveryResetBtn"><i class="bi bi-arrow-counterclockwise"></i> <span data-i18n="recovery.resetButton">Reset Password</span></button>
          </div>
          <p class="form-hint" id="recoveryStep2Result" style="margin-top:12px;white-space:pre-line"></p>
        </form>
      </div>

      <!-- Success State (hidden by default) -->
      <div id="recoverySuccess" class="hidden">
        <div style="text-align:center;padding:20px 0">
          <i class="bi bi-check-circle" style="font-size:48px;color:var(--accent-green);margin-bottom:12px;display:block"></i>
          <h3 data-i18n="recovery.resetSuccessTitle">Password Reset Successfully</h3>
          <p class="reset-info" id="recoverySuccessMsg" data-i18n="recovery.resetSuccess">You can now log in with your new password.</p>
          <button type="button" class="gold-button" id="recoveryBackToLogin" style="margin-top:16px"><i class="bi bi-box-arrow-in-right"></i> <span data-i18n="recovery.backToLogin">Back to Login</span></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Change password modal -->
  <div class="modal-overlay hidden" id="resetPasswordModal">
    <div class="reset-dialog">
      <button type="button" class="reset-close" id="resetPasswordClose"><i class="bi bi-x-lg"></i></button>
      <div class="logo-lockup" style="margin-bottom:20px">
        <img src="assets/images/logo.png" alt="Mpeli Outfit Store" class="login-logo-circle" style="width:48px;height:48px">
        <div>
          <strong>Mpeli Outfit Store</strong>
          <small data-i18n="auth.passwordRecovery">Change Password</small>
        </div>
      </div>
      <h3 data-i18n="auth.resetPasswordTitle">Change your password</h3>
      <p class="reset-info" data-i18n="auth.changePasswordInfo">Enter your current password and choose a new one.</p>
      <form id="resetPasswordForm">
        <label><span data-i18n="login.currentPassword">Current password</span><div class="input-icon-wrap password-wrap"><i class="bi bi-lock"></i><input type="password" id="resetCurrentPassword" required autocomplete="current-password" /><button type="button" class="password-toggle" id="resetCurrentPasswordToggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div></label>
        <label><span data-i18n="login.newPassword">New password</span><div class="input-icon-wrap password-wrap"><i class="bi bi-lock"></i><input type="password" id="resetNewPassword" minlength="8" required autocomplete="new-password" /><button type="button" class="password-toggle" id="resetNewPasswordToggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div></label>
        <label><span data-i18n="login.confirmNewPassword">Confirm new password</span><div class="input-icon-wrap password-wrap"><i class="bi bi-lock"></i><input type="password" id="resetConfirmPassword" minlength="8" required autocomplete="new-password" /><button type="button" class="password-toggle" id="resetConfirmPasswordToggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div></label>
        <div class="reset-actions">
          <button type="button" class="ghost-button" id="resetPasswordCancel" data-i18n="common.cancel">Cancel</button>
          <button type="submit" class="gold-button" data-i18n="auth.changePassword"><i class="bi bi-key"></i> Change Password</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Profile modal -->
  <div class="modal-overlay hidden" id="profileModal">
    <div class="modal-dialog" style="max-width:420px">
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px">
        <div class="profile-avatar" id="profileModalAvatar" style="width:64px;height:64px;font-size:24px;flex-shrink:0"></div>
        <div>
          <h3 id="profileModalName" style="margin:0;font-size:20px"></h3>
          <span id="profileModalRole" class="role-badge" style="margin-top:4px"></span>
        </div>
      </div>
      <div class="profile-details">
        <div class="profile-row"><span class="profile-label"><i class="bi bi-person"></i> <span data-i18n="profile.username">Username</span></span><span id="profileModalUsername" class="profile-value"></span></div>
        <div class="profile-row"><span class="profile-label"><i class="bi bi-envelope"></i> <span data-i18n="profile.email">Email</span></span><span id="profileModalEmail" class="profile-value"></span></div>
        <div class="profile-row"><span class="profile-label"><i class="bi bi-shield-lock"></i> <span data-i18n="profile.role">Role</span></span><span id="profileModalRoleText" class="profile-value"></span></div>
        <div class="profile-row"><span class="profile-label"><i class="bi bi-circle-fill" style="font-size:8px"></i> <span data-i18n="profile.status">Status</span></span><span id="profileModalStatus" class="profile-value"></span></div>
        <div class="profile-row"><span class="profile-label"><i class="bi bi-hash"></i> <span data-i18n="profile.userId">User ID</span></span><span id="profileModalId" class="profile-value"></span></div>
      </div>
      <div class="modal-actions" style="margin-top:20px">
        <button type="button" class="gold-button" id="profileModalClose"><i class="bi bi-x-lg"></i> <span data-i18n="common.close">Close</span></button>
      </div>
    </div>
  </div>

  <!-- Edit product modal -->
  <div class="modal-overlay hidden" id="editProductModal">
    <div class="modal-dialog" style="max-width:440px">
      <h3 data-i18n="products.edit">Edit product</h3>
      <div class="edit-image-preview">
        <img id="editCurrentImage" class="hidden" alt="Current product image" />
        <div id="editImagePlaceholder" class="image-placeholder"><i class="bi bi-image"></i><span data-i18n="products.noImage">No image</span></div>
      </div>
      <div class="image-input-row">
        <input id="editImageInput" type="file" accept="image/jpeg,image/png,image/jpg" data-i18n-aria-label="products.imageReplace" aria-label="Replace product image" />
      </div>
      <div class="form-hint-row">
        <label class="form-hint"><input type="checkbox" id="editRemoveImage" /> <span data-i18n="products.imageRemove">Remove image</span></label>
      </div>
      <div class="product-edit-fields">
        <input id="editNameInput" placeholder="Product name" data-i18n-placeholder="products.namePlaceholder" required />
        <input id="editBuyingInput" type="number" min="0" step="1" placeholder="Buying price (TSH)" data-i18n-placeholder="products.buying" required />
        <input id="editSellingInput" type="number" min="0" step="1" placeholder="Selling price (TSH)" data-i18n-placeholder="products.selling" required />
        <input id="editMinPriceInput" type="number" min="0" step="1" placeholder="Min allowed selling price (TSH)" title="Minimum Allowed Selling Price" required />
        <input id="editStockInput" type="number" min="0" step="1" placeholder="Stock quantity" data-i18n-placeholder="products.stock" />
      </div>
      <div class="modal-actions" style="margin-top:20px">
        <button type="button" class="ghost-button" id="editProductCancelBtn" data-i18n="common.cancel">Cancel</button>
        <button type="button" class="gold-button" id="editProductSave" data-i18n="products.saveProduct"><i class="bi bi-check-circle-fill"></i> Save product</button>
      </div>
    </div>
  </div>

  <!-- Promotion modal -->
  <div class="modal-overlay hidden" id="promotionModal">
    <div class="modal-dialog" style="max-width:540px">
      <h3 id="promotionModalTitle" data-i18n="promotions.addNew">New promotion</h3>
      <div class="promotion-fields">
        <input id="promoNameInput" placeholder="Promotion name" data-i18n-placeholder="promotions.namePlaceholder" required />
        <input id="promoDescriptionInput" placeholder="Description (optional)" data-i18n-placeholder="promotions.descPlaceholder" />
        <label class="form-field"><span data-i18n="promotions.percentage">Discount %</span>
          <input id="promoPercentageInput" type="number" min="1" max="100" step="1" required />
        </label>
        <div class="promo-date-row">
          <label class="form-field"><span data-i18n="promotions.startDate">Start date</span><input id="promoStartDate" type="date" required /></label>
          <label class="form-field"><span data-i18n="promotions.startTime">Start time (optional)</span><input id="promoStartTime" type="time" /></label>
          <label class="form-field"><span data-i18n="promotions.endDate">End date</span><input id="promoEndDate" type="date" required /></label>
          <label class="form-field"><span data-i18n="promotions.endTime">End time (optional)</span><input id="promoEndTime" type="time" /></label>
        </div>
        <label class="toggle-line"><input type="checkbox" id="promoAllProducts" /> <span data-i18n="promotions.allProducts">Apply to all products</span></label>
        <div class="form-field" id="promoProductsField">
          <span data-i18n="promotions.selectProducts">Select products</span>
          <div class="promo-product-picker" id="promoProductPicker"></div>
        </div>
      </div>
      <div class="modal-actions" style="margin-top:20px">
        <button type="button" class="ghost-button" id="promotionCancelBtn" data-i18n="common.cancel">Cancel</button>
        <button type="button" class="gold-button" id="promotionSaveBtn" data-i18n="promotions.save"><i class="bi bi-check-circle-fill"></i> Save promotion</button>
      </div>
    </div>
  </div>

  <!-- Audit detail modal -->
  <div class="modal-overlay hidden" id="auditDetailModal" role="dialog" aria-modal="true" aria-labelledby="auditDetailModalTitle">
    <div class="modal-dialog audit-detail-dialog">
      <div class="modal-head">
        <h3 id="auditDetailModalTitle" data-i18n="audit.detailTitle">Audit Log Details</h3>
        <button type="button" class="reset-close" id="auditDetailClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="audit-detail-body" id="auditDetailBody"></div>
      <div class="modal-actions">
        <button type="button" class="ghost-button" id="auditDetailCloseBtn" data-i18n="common.close">Close</button>
      </div>
    </div>
  </div>

  <!-- Backup detail modal -->
  <div class="modal-overlay hidden" id="backupDetailModal">
    <div class="modal-dialog backup-detail-dialog">
      <div class="modal-head">
        <h3 data-i18n="backup.detailTitle">Backup Details</h3>
        <button type="button" class="reset-close" id="backupDetailClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="audit-detail-body" id="backupDetailBody"></div>
      <div class="modal-actions">
        <button type="button" class="ghost-button" id="backupDetailCloseBtn" data-i18n="common.close">Close</button>
        <button type="button" class="gold-button backup-detail-validate" id="backupDetailValidateBtn"><i class="bi bi-check-circle"></i> <span data-i18n="backup.validate">Validate</span></button>
      </div>
    </div>
  </div>

  <!-- Restore confirmation modal -->
  <div class="modal-overlay hidden" id="restoreModal">
    <div class="modal-dialog restore-dialog">
      <div class="modal-head">
        <h3 data-i18n="backup.restoreTitle">Restore Backup</h3>
        <button type="button" class="reset-close" id="restoreClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="restore-warning">
        <div class="restore-warning-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div>
          <strong data-i18n="backup.restoreWarning">WARNING: Restoring this backup may replace current production data.</strong>
          <p class="restore-warning-text" data-i18n="backup.restoreWarningText">Before any restore, the current database is automatically backed up first. Then the selected backup will be applied. This action cannot be undone.</p>
        </div>
      </div>
      <div class="restore-details" id="restoreDetails"></div>
      <div class="restore-step hidden" id="restoreStep1">
        <label class="restore-confirm-check">
          <input type="checkbox" id="restoreConfirmCheck1" />
          <span data-i18n="backup.restoreConfirm1">I understand that current production data will be replaced and an automatic safety backup will be created first.</span>
        </label>
      </div>
      <p class="form-hint" id="restoreMessage" role="status"></p>
      <div class="modal-actions">
        <button type="button" class="ghost-button" id="restoreCancel" data-i18n="common.cancel">Cancel</button>
        <button type="button" class="danger-button" id="restoreStep2Btn" disabled data-i18n="backup.restoreButton"><i class="bi bi-arrow-counterclockwise"></i> Confirm Restore</button>
      </div>
    </div>
  </div>

  <!-- Idle session warning modal -->
  <div class="modal-overlay hidden" id="idleWarningModal" role="alertdialog" aria-modal="true" aria-labelledby="idleWarningTitle" aria-describedby="idleWarningText">
    <div class="modal-dialog idle-dialog">
      <h3 class="idle-heading" id="idleWarningTitle" data-i18n="idle.heading">Your session is about to expire.</h3>
      <p class="idle-message" id="idleWarningText" data-i18n="idle.message">Your session will end in 1 minute due to inactivity.</p>
      <div class="idle-timer-wrap" aria-hidden="true">
        <svg class="idle-ring" viewBox="0 0 120 120">
          <circle class="idle-ring-track" cx="60" cy="60" r="52"></circle>
          <circle class="idle-ring-progress" id="idleRingProgress" cx="60" cy="60" r="52"></circle>
        </svg>
        <div class="idle-timer-center">
          <span class="idle-timer-number" id="idleCountdown" aria-live="polite">60</span>
          <span class="idle-timer-label" data-i18n="idle.seconds">seconds</span>
        </div>
      </div>
      <div class="idle-actions">
        <button type="button" class="gold-button" id="idleStayBtn"><i class="bi bi-arrow-counterclockwise"></i> <span data-i18n="idle.continue">Continue Session</span></button>
        <button type="button" class="ghost-button idle-logout-btn" id="idleLogoutBtn"><i class="bi bi-box-arrow-left"></i> <span data-i18n="idle.logout">Log Out</span></button>
      </div>
    </div>
  </div>

  <!-- amCharts 5 (self-hosted, served from 'self' to remain CSP-safe) -->
  <script src="assets/js/amcharts/index.js?v=<?php echo $timestamp; ?>"></script>
  <script src="assets/js/amcharts/xy.js?v=<?php echo $timestamp; ?>"></script>
  <script src="assets/js/amcharts/percent.js?v=<?php echo $timestamp; ?>"></script>
  <script src="assets/js/amcharts/themes/Animated.js?v=<?php echo $timestamp; ?>"></script>
  <!-- Mpeli Outfit Store chart layer -->
  <script src="assets/js/chart-utils.js?v=<?php echo $timestamp; ?>&bust=1"></script>
  <script src="assets/js/dashboard-charts.js?v=<?php echo $timestamp; ?>&bust=1"></script>
  <script src="assets/js/business-analysis-charts.js?v=<?php echo $timestamp; ?>&bust=1"></script>
  <script src="assets/js/script.js?v=<?php echo $timestamp; ?>&bust=9"></script>
</body>
</html>

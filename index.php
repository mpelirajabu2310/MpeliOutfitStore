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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self';");

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
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="styles.css?v=<?php echo $timestamp; ?>&bust=2" />
</head>
<body>
  <div class="splash-screen" id="splashScreen">
    <div class="splash-content">
      <img src="images/logo.png" alt="Mpeli Outfit Store" class="splash-logo">
      <div class="splash-loader"></div>
      <p class="splash-text">Loading...</p>
    </div>
  </div>
  <main class="health-screen hidden" id="healthScreen">
    <div class="health-content">
      <img src="images/logo.png" alt="Mpeli Outfit Store" class="splash-logo" style="margin-bottom:24px">
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
      <img src="images/logo.png" alt="Mpeli Outfit Store" class="splash-logo" style="margin-bottom:24px">
      <h1 class="health-title">Under Maintenance</h1>
      <p class="health-subtitle" id="maintenanceMessage">The system is currently undergoing scheduled maintenance. Please try again later.</p>
      <div class="maintenance-icon"><i class="bi bi-tools"></i></div>
    </div>
  </main>
  <main class="login-screen" id="loginScreen">
    <section class="login-art" aria-label="Boutique preview" data-i18n-aria-label="aria.boutiquePreview">
      <div class="login-art-body">
        <img src="images/logo.png" alt="logo" class="brand-mark">
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
          <img src="images/logo.png" alt="Mpeli Outfit Store" class="login-logo-circle">
        </div>
        <p class="login-shop-name">Mpeli Outfit Store</p>
        <label class="language-field">
          <span data-i18n="settings.language">Language</span>
          <select class="language-switcher" id="loginLanguageSwitcher" aria-label="Language" data-i18n-aria-label="settings.language">
            <option value="en">English</option>
            <option value="sw">Swahili</option>
          </select>
        </label>
        <h2 data-i18n="login.welcome">Welcome back</h2>
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
          <img src="images/logo.png" alt="Mpeli Outfit Store" class="login-logo-circle">
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
      <div class="sidebar-brand">
        <span class="sidebar-title">Mpeli Outfit Store</span>
      </div>
      <nav class="side-nav" aria-label="Main navigation" data-i18n-aria-label="aria.mainNavigation">
        <button class="nav-item active" data-page="dashboard" data-i18n="nav.dashboard"><i class="bi bi-grid-1x2-fill"></i> Dashboard</button>
        <button class="nav-item" data-page="products" data-i18n="nav.products"><i class="bi bi-box-seam-fill"></i> Products</button>
        <button class="nav-item" data-page="sales" data-i18n="nav.sales"><i class="bi bi-cart-check-fill"></i> Sales POS</button>
        <button class="nav-item owner-only" data-page="inventory" data-i18n="nav.inventory"><i class="bi bi-clipboard-data-fill"></i> Inventory</button>
        <button class="nav-item owner-only" data-page="reports" data-i18n="nav.reports"><i class="bi bi-bar-chart-line-fill"></i> Reports</button>
        <button class="nav-item" data-page="expenses" data-i18n="nav.expenses"><i class="bi bi-wallet2"></i> Expenses</button>
        <button class="nav-item owner-only" data-page="users" data-i18n="nav.users"><i class="bi bi-people-fill"></i> Users</button>
        <button class="nav-item owner-only" data-page="settings" data-i18n="nav.settings"><i class="bi bi-gear-fill"></i> Settings</button>
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
        <div class="search-box">
          <span data-i18n="common.search">Search</span>
          <input type="search" placeholder="Products, receipts, expenses..." id="globalSearch" data-i18n-placeholder="search.globalPlaceholder" />
          <button type="button" class="search-icon-btn" id="searchIconBtn" aria-label="Search"><i class="bi bi-search"></i></button>
        </div>
        <select class="language-switcher" id="appLanguageSwitcher" aria-label="Language" data-i18n-aria-label="settings.language"></select>
        <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle theme"><i class="bi bi-moon-stars"></i></button>
        <div class="admin-profile">
          <span id="profileAvatar" class="profile-avatar">--</span>
          <div>
            <strong id="profileName"></strong>
            <small id="profileRole" class="role-badge"></small>
          </div>
        </div>
        <button id="changePasswordButton" class="ghost-button" aria-label="Change password" title="Change password"><i class="bi bi-key"></i></button>
        <button id="logoutButton" class="logout-button" data-i18n="nav.logout"><i class="bi bi-box-arrow-left"></i> Logout</button>
      </header>
      <main class="page active" id="dashboard">
        <div class="page-heading">
          <div>
            <p class="eyebrow" data-i18n="dashboard.eyebrow">Operations Overview</p>
            <h2 data-i18n="nav.dashboard">Dashboard</h2>
          </div>
          <button class="gold-button owner-only" id="generateReportButton" data-i18n="reports.generateReport">Generate Report</button>
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
          <article class="panel owner-only">
            <div class="panel-title">
              <h3 data-i18n="dashboard.storeFloor">Overview</h3>
            </div>
            <p id="dashboardSummaryText" data-i18n="dashboard.storeFloorEmpty">Add products and record sales to see live performance here.</p>
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
              <thead><tr><th data-i18n="table.receipt">Receipt</th><th data-i18n="table.product">Product</th><th data-i18n="table.customerType">Customer Type</th><th data-i18n="table.amount">Amount</th><th class="owner-only" data-i18n="table.profit">Profit</th><th data-i18n="table.status">Status</th></tr></thead>
              <tbody id="recentSalesBody">
                <tr><td colspan="6" data-i18n="sales.noCompletedSales">No completed sales yet.</td></tr>
              </tbody>
            </table>
          </div>
        </article>
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
          <button class="gold-button" type="submit" data-i18n="products.saveProduct"><i class="bi bi-check-circle-fill"></i> Save product</button>
        </form>
        <div class="toolbar">
          <input type="search" id="productSearch" placeholder="Search products..." data-i18n-placeholder="products.searchPlaceholder" />
        </div>
        <section class="product-grid" id="productGrid"></section>
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
            <label><span data-i18n="sales.paymentMethod">Payment method</span>
              <select id="paymentMethod"><option value="cash" data-i18n="payment.cash">Cash</option><option value="card" data-i18n="payment.card">Card</option><option value="mobile_money" data-i18n="payment.mobileMoney">Mobile Money</option></select>
            </label>
            <button class="gold-button full" id="completePaymentButton" data-i18n="sales.paymentCompleted">Payment completed</button>
            <p class="receipt-note" id="receiptNote" data-i18n="sales.readyCheckout">Ready for checkout.</p>
            <p class="receipt-footer">Mpeli Outfit Store - Admin</p>
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
          <article class="panel"><h3 data-i18n="inventory.allProducts">All Products Stock</h3><ul id="allProductsList" class="inventory-items"></ul></article>
          <article class="panel stock-warning"><h3 data-i18n="inventory.lowStockWarning">Low stock warning</h3><ul id="lowStockList" class="inventory-items"><li data-i18n="inventory.noLowStock">No low stock items.</li></ul></article>
          <article class="panel stock-out"><h3 data-i18n="inventory.outStockSection">Out of stock</h3><ul id="outStockList" class="inventory-items"><li data-i18n="inventory.noOutStock">No out of stock items.</li></ul></article>
        </section>
      </main>
      <main class="page owner-only" id="reports">
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
        <article class="panel hidden" id="reportOutputPanel">
          <div class="panel-title"><h3 data-i18n="reports.generatedReport">Generated Report</h3><small id="reportGeneratedAt"></small><small id="reportGeneratedBy"></small></div>
          <div class="report-output table-wrap" id="reportOutput"></div>
          <div class="settings-actions"><button class="ghost-button" onclick="downloadPdfReport()" data-i18n="reports.downloadPdf">Download PDF</button></div>
        </article>
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
            <label class="sr-only" for="expenseAmountInput" data-i18n="table.amount">Amount</label>
            <input placeholder="Amount" id="expenseAmountInput" type="number" min="0" step="1" data-i18n-placeholder="table.amount" />
            <label class="sr-only" for="expenseDateInput" data-i18n="expenses.dateLabel">Date</label>
            <input id="expenseDateInput" type="date" />
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
            <table>
              <thead><tr><th data-i18n="table.date">Date</th><th data-i18n="table.category">Category</th><th data-i18n="expenses.descriptionLabel">Description</th><th data-i18n="table.amount">Amount</th><th data-i18n="users.name">Created By</th><th class="owner-only" data-i18n="users.actions">Actions</th></tr></thead>
              <tbody id="expensesBody">
                <tr><td colspan="6" data-i18n="expenses.noExpenses">No expenses recorded yet.</td></tr>
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
            <label for="maintenanceMessage" data-i18n="settings.maintenanceMessage">Maintenance message</label>
            <input type="text" id="maintenanceMessage" value="System is under maintenance. Please try again later." data-i18n-placeholder="settings.maintenanceMessage" />
            <button class="gold-button" type="button" id="saveMaintenanceButton" data-i18n="settings.saveMaintenance"><i class="bi bi-tools"></i> Save maintenance settings</button>
          </article>
        </section>
        <div class="settings-actions">
          <p class="form-hint" id="settingsMessage" role="status"></p>
          <button class="gold-button" type="button" id="saveSettingsButton" data-i18n="settings.saveSettings"><i class="bi bi-save-fill"></i> Save settings</button>
        </div>
      </main>
    </section>
  </div>

  <div class="modal-overlay hidden" id="reportDateModal">
    <div class="modal-dialog">
      <h3>Report Date Range</h3>
      <div class="modal-quick-buttons">
        <button type="button" class="ghost-button" data-range="today">Today</button>
        <button type="button" class="ghost-button" data-range="week">This Week</button>
        <button type="button" class="ghost-button" data-range="2weeks">Two Weeks</button>
        <button type="button" class="ghost-button" data-range="month">This Month</button>
        <button type="button" class="ghost-button" data-range="custom">Custom</button>
      </div>
      <div class="modal-date-fields hidden" id="customDateFields">
        <label>Start: <input type="date" id="reportStartDate" /></label>
        <label>End: <input type="date" id="reportEndDate" /></label>
      </div>
      <div class="modal-actions">
        <button type="button" class="ghost-button" id="reportDateCancel">Cancel</button>
        <button type="button" class="gold-button" id="reportDateConfirm"><i class="bi bi-check-lg"></i> Generate</button>
      </div>
    </div>
  </div>

  <!-- Recovery modal — Step 1: Verify Identity -->
  <div class="modal-overlay hidden" id="recoveryModal">
    <div class="reset-dialog">
      <button type="button" class="reset-close" id="recoveryClose"><i class="bi bi-x-lg"></i></button>
      <div class="logo-lockup" style="margin-bottom:20px">
        <img src="images/logo.png" alt="Mpeli Outfit Store" class="login-logo-circle" style="width:48px;height:48px">
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
        <img src="images/logo.png" alt="Mpeli Outfit Store" class="login-logo-circle" style="width:48px;height:48px">
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

  <script src="script.js?v=<?php echo $timestamp; ?>&bust=2"></script>
</body>
</html>

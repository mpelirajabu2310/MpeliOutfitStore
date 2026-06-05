let products = [];
let currentUser = null;
let translations = {};
let currentLanguage = localStorage.getItem("preferredLanguage") || "en";
const cart = new Map();

// Force clean container state on load - let init() decide which form to show
(() => {
  try {
    const loginScreen = document.querySelector("#loginScreen");
    const appShell = document.querySelector("#appShell");
    
    if (loginScreen) loginScreen.classList.remove("hidden");
    if (appShell) appShell.classList.add("hidden");
  } catch (e) {
    console.warn("Early state reset error:", e);
  }
})();

// Also force reset after DOM is fully ready
document.addEventListener('DOMContentLoaded', () => {
  const loginScreen = document.querySelector("#loginScreen");
  const appShell = document.querySelector("#appShell");
  
  if (loginScreen) loginScreen.classList.remove("hidden");
  if (appShell) appShell.classList.add("hidden");
}, { once: true });

// Theme system
const STORAGE_THEME_KEY = "preferredTheme";
function getStoredTheme() {
  return localStorage.getItem(STORAGE_THEME_KEY) || "light";
}
function setTheme(theme) {
  document.body.classList.toggle("dark", theme === "dark");
  localStorage.setItem(STORAGE_THEME_KEY, theme);
  const btn = document.querySelector("#themeToggle");
  if (btn) btn.innerHTML = theme === "dark" ? "\u2600\uFE0F" : "\uD83C\uDF19";
}
function toggleTheme() {
  setTheme(document.body.classList.contains("dark") ? "light" : "dark");
}
// Apply theme immediately before any render
setTheme(getStoredTheme());

const money = value => {
  const amount = Number(value || 0);
  return `TSH ${amount.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
};

let lowStockThreshold = 5;

async function apiRequest(url, options = {}) {
  try {
    const response = await fetch(url, {
      headers: { 
        "Content-Type": "application/json",
        ...(options.headers || {})
      },
      credentials: "same-origin",
      cache: "no-store",  // Never cache API responses
      ...options
    });
    
    let payload;
    try {
      payload = await response.json();
    } catch (jsonError) {
      console.error("JSON parse error from", url, ":", jsonError);
      throw new Error("Invalid response from server. Please try again.");
    }
    
    if (!response.ok || payload.success === false) {
      const message = payload.message || "Request failed.";
      throw new Error(message);
    }
    
    return payload;
  } catch (error) {
    console.error("API request error:", error);
    throw error;
  }
}

async function loadTranslations(language) {
  try {
    const response = await fetch(`locales/${language}.json`);
    if (!response.ok) throw new Error("Translation file not found.");
    translations = await response.json();
    currentLanguage = language;
    localStorage.setItem("preferredLanguage", language);
  } catch (error) {
    if (language !== "en") {
      await loadTranslations("en");
      return;
    }
    translations = {};
  }
}

function t(key, replacements = {}) {
  const template = translations[key] || key;
  return Object.entries(replacements).reduce(
    (text, [name, value]) => text.replaceAll(`{${name}}`, value),
    template
  );
}

function applyTranslations() {
  document.documentElement.lang = currentLanguage;
  document.querySelectorAll("[data-i18n]").forEach(element => {
    element.textContent = t(element.dataset.i18n);
  });
  document.querySelectorAll("[data-i18n-placeholder]").forEach(element => {
    element.placeholder = t(element.dataset.i18nPlaceholder);
  });
  document.querySelectorAll("[data-i18n-aria-label]").forEach(element => {
    element.setAttribute("aria-label", t(element.dataset.i18nAriaLabel));
  });
  document.querySelectorAll("[data-i18n-alt]").forEach(element => {
    element.setAttribute("alt", t(element.dataset.i18nAlt));
  });
  document.querySelectorAll(".language-switcher").forEach(select => {
    select.value = currentLanguage;
  });
  document.title = t("app.title");
}

async function setLanguage(language) {
  await loadTranslations(language);
  applyTranslations();
  renderProducts();
  renderCart();
}

function isOwner() {
  return currentUser?.role === "OWNER";
}

function applyRoleUI() {
  document.querySelectorAll(".owner-only").forEach(element => {
    element.classList.toggle("hidden", !isOwner());
  });

  const adminName = document.querySelector(".admin-profile strong");
  const adminRole = document.querySelector(".admin-profile small");
  if (currentUser) {
    adminName.textContent = currentUser.name;
    adminRole.textContent = currentUser.role;
    const avatar = document.querySelector("#profileAvatar");
    if (avatar) {
      avatar.textContent = currentUser.name
        .split(" ")
        .map(part => part[0])
        .join("")
        .slice(0, 2)
        .toUpperCase();
    }
  }
}

function showApp() {
  document.querySelector("#loginScreen").classList.add("hidden");
  document.querySelector("#appShell").classList.remove("hidden");
  setTheme(getStoredTheme());
  applyRoleUI();
}

function showLogin(ownerExists = true) {
  document.querySelector("#loginScreen").classList.remove("hidden");
  document.querySelector("#appShell").classList.add("hidden");
  
  if (ownerExists) {
    // Owner exists, show login form, hide setup form
    document.querySelector("#loginForm").classList.remove("hidden");
    document.querySelector("#ownerSetupForm").classList.add("hidden");
  } else {
    // No owner exists, show setup form, hide login form
    document.querySelector("#loginForm").classList.add("hidden");
    document.querySelector("#ownerSetupForm").classList.remove("hidden");
  }
}

function normalizeProduct(product) {
  const buying = product.buying === null ? null : Number(product.buying);
  const selling = Number(product.selling);
  return {
    ...product,
    id: Number(product.id),
    variant_id: Number(product.variant_id),
    name: product.name,
    buying,
    selling,
    stock: Number(product.stock),
    profit_per_unit: product.profit_per_unit === null ? null : Number(product.profit_per_unit ?? selling - (buying || 0)),
    stock_status: product.stock_status || "in_stock"
  };
}

function stockBadge(product) {
  if (product.stock_status === "out_of_stock") {
    return `<span class="stock-badge danger">${t("inventory.outOfStock")}</span>`;
  }
  if (product.stock_status === "low_stock") {
    return `<span class="stock-badge warning">${t("dashboard.lowStockLabel")}</span>`;
  }
  return "";
}

function translatedCategory(category) {
  const categoryKeys = {
    "T-Shirts": "category.tshirts",
    Hoodies: "category.hoodies",
    Sneakers: "category.sneakers",
    Dresses: "category.dresses",
    Accessories: "category.accessories"
  };
  return t(categoryKeys[category] || category);
}

function translatedCustomerType(type) {
  const normalized = String(type || "walk_in");
  const customerKeys = {
    walk_in: "customer.walkIn",
    vip: "customer.vip",
    staff: "customer.staff",
    other: "customer.other"
  };
  return t(customerKeys[normalized] || normalized.replace("_", " "));
}

async function loadProducts() {
  const search = document.querySelector("#productSearch")?.value || "";
  const params = new URLSearchParams({ search });
  const payload = await apiRequest(`api/products.php?${params.toString()}`);
  lowStockThreshold = payload.low_stock_threshold || 5;
  products = payload.products.map(normalizeProduct);
  console.log(`[loadProducts] Loaded ${products.length} products`, products.map(p => ({ id: p.id, name: p.name, stock: p.stock })));
  cart.clear();
  renderProducts();
  renderCart();
}

function renderProducts() {
  const grid = document.querySelector("#productGrid");
  if (!grid) return;

  grid.innerHTML = products.map(product => {
    const profitHtml = product.buying === null
      ? ""
      : `<span>${t("products.profit")} <strong>${money(product.selling - product.buying)}</strong></span>`;
    const buyingHtml = product.buying === null
      ? ""
      : `<span>${t("products.buying")} <strong>${money(product.buying)}</strong></span>`;
    const actions = isOwner()
      ? `<div class="card-actions">
          <button type="button" data-edit-product="${product.id}">${t("products.edit")}</button>
          <button type="button" data-delete-product="${product.id}">${t("products.delete")}</button>
        </div>`
      : "";

    return `
      <article class="product-card">
        <div class="product-body">
          <h3>${product.name} ${stockBadge(product)}</h3>
          <div class="price-grid">
            ${buyingHtml}
            <span>${t("products.selling")} <strong>${money(product.selling)}</strong></span>
            ${profitHtml}
            <span>${t("products.stock")} <strong>${product.stock}</strong></span>
          </div>
          ${actions}
        </div>
      </article>
    `;
  }).join("") || `<article class="panel"><h3>${t("products.noProducts")}</h3><p>${t("products.startFresh")}</p></article>`;
}

function renderPosProducts() {
  document.querySelector("#posProducts").innerHTML = products.map((product, index) => `
    <article class="pos-item">
      <div>
        <strong>${product.name}</strong> ${stockBadge(product)}
        <small>${money(product.selling)} / ${t("products.stock")} ${product.stock}</small>
      </div>
      <div class="qty-controls">
        <button type="button" data-dec="${index}" aria-label="${t("common.decrease")} ${product.name}">-</button>
        <span>${cart.get(index) || 0}</span>
        <button type="button" data-inc="${index}" aria-label="${t("common.increase")} ${product.name}">+</button>
      </div>
    </article>
  `).join("") || `<p class="empty-state">${t("products.noProducts")}</p>`;
}

function renderCart() {
  const list = document.querySelector("#cartList");
  let total = 0;
  const lines = [...cart.entries()].filter(([, qty]) => qty > 0).map(([index, qty]) => {
    const product = products[index];
    total += product.selling * qty;
    return `<div class="cart-line"><span>${product.name} x ${qty}</span><strong>${money(product.selling * qty)}</strong></div>`;
  });

  list.innerHTML = lines.join("") || `<p class="receipt-note">${t("sales.noProductsSelected")}</p>`;
  document.querySelector("#saleTotal").textContent = money(total);
  let cartProfit = 0;
  if (isOwner()) {
    [...cart.entries()].filter(([, qty]) => qty > 0).forEach(([index, qty]) => {
      const product = products[index];
      cartProfit += (product.selling - (product.buying || 0)) * qty;
    });
    document.querySelector("#saleProfit").textContent = money(cartProfit);
  } else {
    document.querySelector("#saleProfit").textContent = t("role.hidden");
  }
  renderPosProducts();
}

function formatChartDay(day) {
  const date = new Date(`${day}T00:00:00`);
  return Number.isNaN(date.getTime()) ? day : date.toLocaleDateString(undefined, { weekday: "short" });
}

function renderBarChart(container, chart, hasData, valueKey = "value") {
  if (!container) return;
  if (!hasData || !chart?.length) {
    container.innerHTML = `<p class="empty-state">${t("dashboard.noChartData")}</p>`;
    return;
  }

  const max = Math.max(...chart.map(item => Number(item[valueKey] ?? item.revenue ?? item.value ?? 0)), 1);
  const width = 700;
  const height = 220;
  const gap = 18;
  const barWidth = Math.floor((width - gap * (chart.length + 1)) / chart.length);
  const bars = chart.map((item, index) => {
    const amount = Number(item[valueKey] ?? item.revenue ?? item.value ?? 0);
    const barHeight = Math.max(18, Math.round((amount / max) * 180));
    const x = gap + index * (barWidth + gap);
    const y = height - barHeight - 20;
    const label = item.product_name || formatChartDay(item.sale_day || item.report_month);
    return `<rect x="${x}" y="${y}" width="${barWidth}" height="${barHeight}"><title>${label}: ${money(amount)}</title></rect>`;
  }).join("");
  container.innerHTML = `<svg class="sales-chart-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="${t("aria.salesAnalyticsChart")}">${bars}</svg>`;
}

function renderLineChart(container, chart, hasData) {
  if (!container) return;
  if (!hasData || !chart?.length) {
    container.innerHTML = `<p class="empty-state">${t("dashboard.noChartData")}</p>`;
    return;
  }

  const width = 700;
  const height = 220;
  const padding = 24;
  const max = Math.max(...chart.map(item => Number(item.revenue)), 1);
  const step = chart.length > 1 ? (width - padding * 2) / (chart.length - 1) : 0;
  const points = chart.map((item, index) => {
    const x = padding + index * step;
    const y = height - padding - Math.max(12, (Number(item.revenue) / max) * (height - padding * 2));
    return `${x},${y}`;
  }).join(" ");

  container.innerHTML = `<svg class="sales-chart-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="${t("aria.revenueLineChart")}"><polyline points="${points}" /></svg>`;
}

function renderStockAlerts(alerts) {
  const panel = document.querySelector("#stockAlertsPanel");
  const list = document.querySelector("#dashboardStockAlerts");
  if (!panel || !list) return;
  if (!alerts?.length) {
    panel.classList.add("hidden");
    return;
  }
  panel.classList.remove("hidden");
  list.innerHTML = alerts.map(item => {
    const label = item.stock_status === "out_of_stock" ? t("inventory.outOfStock") : t("dashboard.lowStockLabel");
    return `<li><strong>${item.product_name}</strong> — ${item.total_stock} ${t("products.stock").toLowerCase()} <em>(${label})</em></li>`;
  }).join("");
}

async function loadDashboard() {
  const payload = await apiRequest("api/dashboard.php");
  lowStockThreshold = payload.low_stock_threshold || 5;
  document.querySelector("#totalProducts").textContent = payload.stats.total_products;
  document.querySelector("#totalSales").textContent = payload.stats.total_sales;
  document.querySelector("#dailyRevenue").textContent = money(payload.stats.daily_revenue);
  const dailyProfitEl = document.querySelector("#dailyProfit");
  if (dailyProfitEl) {
    dailyProfitEl.textContent = payload.stats.daily_profit === null ? t("role.hidden") : money(payload.stats.daily_profit);
  }
  document.querySelector("#monthlyProfit").textContent = payload.stats.monthly_profit === null ? t("role.hidden") : money(payload.stats.monthly_profit);
  document.querySelector("#lowStockItems").textContent = payload.stats.low_stock_items;

  const summary = document.querySelector("#dashboardSummaryText");
  if (summary) {
    summary.textContent = payload.stats.total_sales > 0
      ? t("dashboard.summaryLive", {
          sales: payload.stats.total_sales,
          revenue: money(payload.stats.daily_revenue)
        })
      : t("dashboard.storeFloorEmpty");
  }

  renderStockAlerts(payload.stock_alerts);

  const rows = payload.recent_sales.map(sale => `
    <tr>
      <td>${sale.receipt_number}</td>
      <td>${t("sales.posSale")}</td>
      <td>${translatedCustomerType(sale.customer_type)}</td>
      <td>${money(sale.total_amount)}</td>
      <td class="owner-only">${sale.total_profit === null ? t("role.hidden") : money(sale.total_profit)}</td>
      <td><span class="status paid">${t(`status.${sale.payment_status}`)}</span></td>
    </tr>
  `);
  document.querySelector("#recentSalesBody").innerHTML = rows.join("") || `
    <tr><td colspan="6">${t("sales.noCompletedSales")}</td></tr>
  `;

  renderBarChart(document.querySelector(".revenue-chart") || document.querySelector(".bar-chart"), payload.revenue_chart, payload.has_revenue_chart, "value");
  renderBarChart(document.querySelector(".profit-chart"), payload.profit_chart, payload.has_profit_chart, "value");
  renderBarChart(document.querySelector(".stock-chart"), payload.stock_chart, payload.has_stock_chart, "value");
}

async function loadReports() {
  if (!isOwner()) return;
  const payload = await apiRequest("api/reports.php");
  document.querySelector("#reportDailySales").textContent = money(payload.stats.daily_sales);
  document.querySelector("#reportWeeklySales").textContent = money(payload.stats.weekly_sales);
  document.querySelector("#reportMonthlySales").textContent = money(payload.stats.monthly_sales);
  document.querySelector("#reportProfit").textContent = payload.stats.monthly_profit === null
    ? t("role.hidden")
    : money(payload.stats.monthly_profit);

  const note = (id, has) => {
    const el = document.querySelector(id);
    if (el) el.textContent = has ? t("reports.liveData") : t("dashboard.noChartData");
  };
  note("#reportDailyNote", payload.stats.daily_sales > 0);
  note("#reportWeeklyNote", payload.stats.weekly_sales > 0);
  note("#reportMonthlyNote", payload.stats.monthly_sales > 0);
  note("#reportProfitNote", (payload.stats.monthly_profit || 0) > 0);

  renderLineChart(document.querySelector("#reportChart"), payload.monthly_chart, payload.has_sales);

  const bestBox = document.querySelector("#bestSellers");
  if (!payload.best_sellers?.length) {
    bestBox.innerHTML = `<span class="empty-state">${t("dashboard.noChartData")}</span>`;
    return;
  }

  bestBox.innerHTML = payload.best_sellers.map(item => `
    <div class="best-seller-row">
      <strong>${item.product_name}</strong>
      <span>${item.category_name || ""}</span>
      <small>${t("reports.unitsSold", { count: item.units_sold })} · ${money(item.revenue)}</small>
    </div>
  `).join("");
}

async function loadInventory() {
  if (!isOwner()) return;
  const payload = await apiRequest("api/inventory.php");
  document.querySelector("#inventoryTotalStock").textContent = payload.stats.total_stock;
  document.querySelector("#inventoryLowStock").textContent = payload.stats.low_stock;
  document.querySelector("#inventoryOutStock").textContent = payload.stats.out_of_stock;

  const allList = document.querySelector("#allProductsList");
  if (allList) {
    allList.innerHTML = payload.all_products.length
      ? payload.all_products.map(item => {
          const statusClass = item.stock_status === "out_of_stock" ? "danger" : item.stock_status === "low_stock" ? "warning" : "";
          return `<li><strong>${item.product_name}</strong> — ${item.total_stock} ${t("products.stock").toLowerCase()}${statusClass ? ` <span class="stock-badge ${statusClass}">${t("inventory." + (item.stock_status === "out_of_stock" ? "outOfStock" : "lowStock"))}</span>` : ""}</li>`;
        }).join("")
      : `<li>${t("products.noProducts")}</li>`;
  }

  const lowList = document.querySelector("#lowStockList");
  lowList.innerHTML = payload.low_stock_items.length
    ? payload.low_stock_items.map(item => `<li>${item.product_name} — ${item.total_stock} ${t("products.stock").toLowerCase()} (${t("dashboard.lowStockLabel")})</li>`).join("")
    : `<li>${t("inventory.noLowStock")}</li>`;

  const outList = document.querySelector("#outStockList");
  outList.innerHTML = payload.out_of_stock_items.length
    ? payload.out_of_stock_items.map(item => `<li>${item.product_name}</li>`).join("")
    : `<li>${t("inventory.noOutStock")}</li>`;
}

async function loadSettings() {
  if (!isOwner()) return;
  const payload = await apiRequest("api/settings.php");
  document.querySelector("#shopName").value = payload.shop.shop_name || "";
  document.querySelector("#shopAddress").value = payload.shop.address || "";
  document.querySelector("#shopPhone").value = payload.shop.phone || "";
  document.querySelector("#adminName").value = payload.admin.name || "";
  document.querySelector("#adminEmail").value = payload.admin.email || "";
  const thresholdInput = document.querySelector("#lowStockThreshold");
  if (thresholdInput) thresholdInput.value = payload.shop.low_stock_threshold || 5;
  const footerInput = document.querySelector("#receiptFooter");
  if (footerInput) footerInput.value = payload.shop.receipt_footer || "";
  document.querySelector("#darkModeToggle").checked = Boolean(payload.shop.dark_mode_enabled);
  lowStockThreshold = payload.shop.low_stock_threshold || 5;
  document.body.classList.toggle("dark", document.querySelector("#darkModeToggle").checked);
}

async function saveSettings() {
  const payload = await apiRequest("api/settings.php", {
    method: "PUT",
    body: JSON.stringify({
      shop_name: document.querySelector("#shopName").value,
      address: document.querySelector("#shopAddress").value,
      phone: document.querySelector("#shopPhone").value,
      shop_email: document.querySelector("#adminEmail").value,
      low_stock_threshold: document.querySelector("#lowStockThreshold")?.value || 5,
      dark_mode_enabled: document.querySelector("#darkModeToggle").checked,
      receipt_footer: document.querySelector("#receiptFooter")?.value || "",
      admin_name: document.querySelector("#adminName").value,
      admin_email: document.querySelector("#adminEmail").value,
      admin_password: document.querySelector("#adminPassword").value
    })
  });
  document.querySelector("#adminPassword").value = "";
  const msg = document.querySelector("#settingsMessage");
  if (msg) {
    msg.textContent = payload.message || t("settings.saved");
    msg.classList.add("success");
  }
  if (currentUser) currentUser.name = document.querySelector("#adminName").value;
  applyRoleUI();
  await refreshAppData();
}

function downloadPdfReport() {
  const output = document.querySelector("#reportOutput");
  if (!output || !output.innerHTML) {
    showToast("No report generated yet. Click 'Generate Report' first.", "error");
    return;
  }
  const win = window.open("", "_blank");
  if (!win) { showToast("Please allow popups for PDF download.", "error"); return; }
  const stamp = document.querySelector("#reportGeneratedAt")?.textContent || "";
  const generatorName = document.querySelector("#reportGeneratedBy")?.textContent || "";
  win.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Shop Report</title><style>
    body { font: 14px/1.5 sans-serif; color: #222; max-width: 900px; margin: 20px auto; padding: 20px; }
    h1 { color: #c9a24e; border-bottom: 2px solid #c9a24e; padding-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
    th { background: #f5f3ee; font-weight: 700; }
    caption { font-weight: 700; margin: 8px 0; text-align: left; font-size: 15px; }
    @media print { body { margin: 0; padding: 10px; } }
  </style></head><body><h1>Shop Report</h1><p>${stamp}</p><p>Generated by: ${generatorName}</p>${output.innerHTML}<p style="color:#888;font-size:12px;text-align:center;margin-top:30px;">Generated on ${new Date().toLocaleString()}</p><script>window.onload=function(){window.print()}<\/script></body></html>`);
  win.document.close();
}

async function loadExpenses() {
  if (!isOwner()) return;
  const payload = await apiRequest("api/expenses.php");
  document.querySelector("#expenseToday").textContent = money(payload.summary.today);
  document.querySelector("#expenseMonth").textContent = money(payload.summary.month);
}

async function loadUsers() {
  if (!isOwner()) return;
  const payload = await apiRequest("api/users.php");
  document.querySelector("#usersBody").innerHTML = payload.users.map(user => `
    <tr data-user-id="${user.id}">
      <td>${user.name}</td>
      <td>${user.username}</td>
      <td>${user.role}</td>
      <td>${user.status}</td>
      <td class="user-actions">
        <button type="button" class="ghost-button" data-edit-user="${user.id}">${t("users.edit")}</button>
        <button type="button" class="ghost-button" data-toggle-user="${user.id}" data-status="${user.status}">${user.status === "active" ? t("users.disable") : t("users.enable")}</button>
      </td>
    </tr>
  `).join("") || `<tr><td colspan="5">${t("users.noUsers")}</td></tr>`;
}

function showToast(message, type) {
  type = type || "success";
  let container = document.querySelector(".toast-container");
  if (!container) {
    container = document.createElement("div");
    container.className = "toast-container";
    document.body.appendChild(container);
  }
  const el = document.createElement("div");
  el.className = "toast " + type;
  el.textContent = message;
  container.appendChild(el);
  setTimeout(() => {
    el.classList.add("removing");
    setTimeout(() => el.remove(), 250);
  }, 3000);
}

async function refreshAppData() {
  const tasks = [loadProducts(), loadDashboard()];
  if (isOwner()) {
    tasks.push(loadSettings(), loadExpenses(), loadUsers(), loadInventory(), loadReports());
  }
  const results = await Promise.allSettled(tasks);
  results.forEach((r, i) => { if (r.status === "rejected") console.warn("refreshAppData: task", i, "failed", r.reason); });
}

async function completePayment() {
  const items = [...cart.entries()]
    .filter(([, quantity]) => quantity > 0)
    .map(([index, quantity]) => ({
      variant_id: products[index].variant_id,
      quantity
    }));

  if (items.length === 0) {
    document.querySelector("#receiptNote").textContent = t("sales.addBeforeCheckout");
    return;
  }

  const payload = await apiRequest("api/sales.php", {
    method: "POST",
    body: JSON.stringify({
      payment_method: document.querySelector("#paymentMethod").value,
      items
    })
  });

  cart.clear();
  document.querySelector("#receiptNote").textContent = t("sales.paymentSaved", { receipt: payload.receipt_number });
  showToast(t("sales.paymentSaved", { receipt: payload.receipt_number }));
  await refreshAppData();
}

document.querySelector("#loginForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const fields = event.currentTarget.querySelectorAll("input");
  try {
    const payload = await apiRequest("api/login.php", {
      method: "POST",
      body: JSON.stringify({ username: fields[0].value, password: fields[1].value })
    });
    currentUser = payload.user;
    showApp();
    showToast(t("login.welcome") + ", " + currentUser.name + "!");
    await refreshAppData();
  } catch (error) {
    showToast(error.message, "error");
  }
});

document.querySelector("#ownerSetupForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  try {
    const username = document.querySelector("#ownerUsername").value;
    const password = document.querySelector("#ownerPassword").value;
    
    await apiRequest("api/register_owner.php", {
      method: "POST",
      body: JSON.stringify({
        name: document.querySelector("#ownerName").value,
        username: username,
        email: document.querySelector("#ownerEmail").value,
        password: password
      })
    });
    
    showToast(t("auth.ownerCreated"));
    
    // Try to auto-login
    try {
      await apiRequest("api/login.php", {
        method: "POST",
        body: JSON.stringify({
          username: username,
          password: password
        })
      });
      
      // Refresh auth state
      const payload = await apiRequest("api/me.php");
      if (payload.authenticated) {
        currentUser = payload.user;
        showApp();
        await refreshAppData();
      } else {
        showLogin(true);
      }
    } catch (loginError) {
      // Auto-login failed, show login form
      showLogin(true);
      document.querySelector("#loginForm input[type='text']").value = username;
    }
  } catch (error) {
    showToast(error.message, "error");
  }
});

let lastNavRefresh = 0;
document.querySelectorAll(".nav-item").forEach(button => {
  button.addEventListener("click", () => {
    if (button.classList.contains("owner-only") && !isOwner()) return;
    document.querySelectorAll(".nav-item").forEach(item => item.classList.remove("active"));
    document.querySelectorAll(".page").forEach(page => page.classList.remove("active"));
    button.classList.add("active");
    document.querySelector(`#${button.dataset.page}`).classList.add("active");
    document.querySelector(".sidebar").classList.remove("open");
    const now = Date.now();
    if (now - lastNavRefresh > 5000) {
      lastNavRefresh = now;
      refreshAppData();
    }
  });
});

document.querySelector("#menuButton")?.addEventListener("click", () => {
  document.querySelector(".sidebar").classList.toggle("open");
});

document.querySelector("#logoutButton")?.addEventListener("click", async () => {
  try {
    await apiRequest("api/logout.php", { method: "POST" });
  } catch (e) {
    console.warn("Logout API call failed, clearing local state anyway:", e);
  }
  currentUser = null;
  products = [];
  cart.clear();
  showLogin(true);
});

document.querySelectorAll(".language-switcher").forEach(select => {
  select.addEventListener("change", event => setLanguage(event.target.value));
});

document.querySelector("#themeToggle")?.addEventListener("click", toggleTheme);

function performSearch(query) {
  const q = query.trim().toLowerCase();
  if (!q) { loadProducts(); return; }
  const filtered = products.filter(p => p.name.toLowerCase().includes(q));
  const grid = document.querySelector("#productGrid");
  if (!grid) return;
  grid.innerHTML = filtered.length
    ? filtered.map(p => {
        const stockLabel = p.stock === 0 ? `<span class="stock-badge danger">${t("inventory.outOfStock")}</span>` : p.stock <= lowStockThreshold ? `<span class="stock-badge warning">${t("dashboard.lowStockLabel")}</span>` : "";
        return `<article class="product-card">
          <div class="product-image-placeholder">${p.name[0]}</div>
          <strong>${p.name}</strong>
          <span>${t("products.selling")}: ${money(p.selling)}</span>
          <span>${t("products.stock")}: ${p.stock} ${stockLabel}</span>
        </article>`;
      }).join("")
    : `<p class="empty-state">${t("products.noProducts")}</p>`;
  const posContainer = document.querySelector("#posProducts");
  if (posContainer) {
    posContainer.innerHTML = filtered.length
      ? filtered.map(p => {
          const idx = products.indexOf(p);
          const qty = cart.get(idx) || 0;
          const atMax = qty >= p.stock;
          return `<div class="pos-item ${qty > 0 ? "active" : ""}">
            <div class="product-image-placeholder">${p.name[0]}</div>
            <strong>${p.name}</strong>
            <span>${money(p.selling)}</span>
            <div class="qty-controls">
              <button type="button" class="ghost-button" data-dec="${idx}">-</button>
              <span>${qty}</span>
              <button type="button" class="ghost-button" data-inc="${idx}" ${atMax ? "disabled" : ""}>+</button>
            </div>
          </div>`;
        }).join("")
      : `<p class="empty-state">${t("products.noProducts")}</p>`;
  }
}

document.querySelector("#globalSearch")?.addEventListener("input", event => performSearch(event.target.value));
document.querySelector("#searchIconBtn")?.addEventListener("click", () => {
  const input = document.querySelector("#globalSearch");
  if (input) performSearch(input.value);
});

document.querySelector("#productSearch")?.addEventListener("input", loadProducts);
document.querySelector("#toggleProductForm")?.addEventListener("click", () => {
  document.querySelector("#productForm")?.classList.toggle("hidden");
});

document.querySelector("#productForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const name = document.querySelector("#productNameInput").value.trim();
  const stockInput = document.querySelector("#productStockInput").value;

  // Frontend duplicate check
  const match = products.find(p => p.product_name?.toLowerCase() === name.toLowerCase());
  if (match) {
    const proceed = confirm(`Product "${name}" already exists with stock ${match.stock_quantity ?? 0}. Click OK to update stock, or Cancel to abort.`);
    if (!proceed) return;
  }

  try {
    const result = await apiRequest("api/products.php", {
      method: "POST",
      body: JSON.stringify({
        name: name,
        buying_price: document.querySelector("#productBuyingInput").value,
        selling_price: document.querySelector("#productSellingInput").value,
        stock_quantity: stockInput
      })
    });
    form.reset();
    showToast(result.updated ? t("products.updatedStock") || "Stock updated successfully." : t("products.added"));
    await refreshAppData();
  } catch (error) {
    showToast(error.message, "error");
  }
});

document.querySelector("#productGrid")?.addEventListener("click", async event => {
  const editId = event.target.dataset.editProduct;
  if (editId) {
    const product = products.find(item => String(item.id) === String(editId));
    if (!product) return;
    const name = prompt(t("products.namePlaceholder"), product.name);
    if (name === null) return;
    const buying = prompt(t("products.buying"), product.buying ?? "0");
    if (buying === null) return;
    const selling = prompt(t("products.selling"), product.selling);
    if (selling === null) return;
    const stock = prompt(t("products.stock"), product.stock);
    if (stock === null) return;
    try {
      await apiRequest("api/products.php", {
        method: "PUT",
        body: JSON.stringify({
          id: product.id,
          name,
          buying_price: buying,
          selling_price: selling,
          stock_quantity: stock
        })
      });
      showToast(t("products.updated"));
      await refreshAppData();
    } catch (error) {
      showToast(error.message, "error");
    }
  }

  const deleteId = event.target.dataset.deleteProduct;
  if (deleteId && confirm(t("products.confirmDelete"))) {
    try {
      await apiRequest("api/products.php", {
        method: "DELETE",
        body: JSON.stringify({ id: deleteId })
      });
      showToast(t("products.deleted"));
      await refreshAppData();
    } catch (error) {
      showToast(error.message, "error");
    }
  }
});

document.querySelector("#posProducts")?.addEventListener("click", event => {
  const inc = event.target.dataset.inc;
  const dec = event.target.dataset.dec;
  if (inc !== undefined) {
    const index = Number(inc);
    const nextQty = (cart.get(index) || 0) + 1;
    if (nextQty <= products[index].stock) cart.set(index, nextQty);
  }
  if (dec !== undefined) {
    const index = Number(dec);
    cart.set(index, Math.max((cart.get(index) || 0) - 1, 0));
  }
  renderCart();
});

document.querySelector("#receiptButton")?.addEventListener("click", () => {
  const hasItems = [...cart.values()].some(qty => qty > 0);
  const note = document.querySelector("#receiptNote");
  if (!hasItems) {
    note.textContent = t("sales.addBeforeReceipt");
    return;
  }
  let total = 0;
  let items = [];
  [...cart.entries()].filter(([, qty]) => qty > 0).forEach(([index, qty]) => {
    const p = products[index];
    total += p.selling * qty;
    items.push(`${p.name} x${qty}  ${money(p.selling * qty)}`);
  });
  note.textContent = items.join(" | ") + " | " + t("sales.total") + " " + money(total) + " | " + t("sales.receiptReady");
});

document.querySelector("#completePaymentButton")?.addEventListener("click", async () => {
  try {
    await completePayment();
  } catch (error) {
    document.querySelector("#receiptNote").textContent = error.message;
  }
});

document.querySelector("#saveExpenseButton")?.addEventListener("click", async () => {
  try {
    await apiRequest("api/expenses.php", {
      method: "POST",
      body: JSON.stringify({
        title: document.querySelector("#expenseTitle").value,
        category: document.querySelector("#expenseCategory").value,
        amount: document.querySelector("#expenseAmount").value,
        expense_date: new Date().toISOString().slice(0, 10)
      })
    });
    document.querySelector("#expenseTitle").value = "";
    document.querySelector("#expenseAmount").value = "";
    await loadExpenses();
    showToast(t("expenses.saved"));
  } catch (error) {
    showToast(error.message, "error");
  }
});

document.querySelector("#userForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const form = event.currentTarget;
  try {
    await apiRequest("api/users.php", {
      method: "POST",
      body: JSON.stringify({
        name: document.querySelector("#employeeName").value,
        username: document.querySelector("#employeeUsername").value,
        email: document.querySelector("#employeeEmail").value,
        password: document.querySelector("#employeePassword").value,
        role: document.querySelector("#employeeRole").value
      })
    });
    form.reset();
    showToast(t("users.created"));
    await loadUsers();
  } catch (error) {
    showToast(error.message, "error");
  }
});

document.querySelector("#usersBody")?.addEventListener("click", async event => {
  const editId = event.target.dataset.editUser;
  if (editId) {
    const row = event.target.closest("tr");
    const name = prompt(t("users.name"), row.children[0].textContent);
    if (name === null) return;
    const email = prompt(t("users.email"), "");
    if (email === null) return;
    const role = prompt(t("users.role"), row.children[2].textContent);
    if (role === null) return;
    const password = prompt(t("users.newPasswordOptional"), "");
    if (password === null) return;
    try {
      await apiRequest("api/users.php", {
        method: "PUT",
        body: JSON.stringify({
          id: editId,
          name,
          email,
          role: role.toUpperCase(),
          status: row.children[3].textContent,
          password
        })
      });
      showToast(t("users.updated"));
      await loadUsers();
    } catch (error) {
      showToast(error.message, "error");
    }
    return;
  }

  const id = event.target.dataset.toggleUser;
  if (!id) return;
  const row = event.target.closest("tr").children;
  try {
    await apiRequest("api/users.php", {
      method: "PUT",
      body: JSON.stringify({
        id,
        name: row[0].textContent,
        email: "",
        role: row[2].textContent,
        status: event.target.dataset.status === "active" ? "inactive" : "active"
      })
    });
    showToast(t("users.updated"));
    await loadUsers();
  } catch (error) {
    showToast(error.message, "error");
  }
});

document.querySelector("#darkModeToggle")?.addEventListener("change", event => {
  document.body.classList.toggle("dark", event.target.checked);
});

document.querySelector("#saveSettingsButton")?.addEventListener("click", async () => {
  try {
    await saveSettings();
    showToast(t("settings.saved"));
  } catch (error) {
    const msg = document.querySelector("#settingsMessage");
    if (msg) msg.textContent = error.message;
    showToast(error.message, "error");
  }
});

let reportStartDate = "";
let reportEndDate = "";
let reportFormat = "json";

function showReportDateDialog(format) {
  reportFormat = format;
  const today = new Date();
  document.querySelector("#reportStartDate").value = today.toISOString().slice(0, 10);
  document.querySelector("#reportEndDate").value = today.toISOString().slice(0, 10);
  document.querySelector("#reportDateModal").classList.remove("hidden");
  document.querySelector("#customDateFields").classList.add("hidden");
}

function closeReportDateDialog() {
  document.querySelector("#reportDateModal").classList.add("hidden");
}

function setReportDateRange(range) {
  const today = new Date();
  const y = today.getFullYear();
  const m = today.getMonth();
  const d = today.getDate();
  let start, end;
  switch (range) {
    case "today":
      start = end = today;
      break;
    case "week": {
      const dayOfWeek = today.getDay();
      start = new Date(y, m, d - (dayOfWeek === 0 ? 6 : dayOfWeek - 1));
      end = today;
      break;
    }
    case "2weeks":
      start = new Date(y, m, d - 13);
      end = today;
      break;
    case "month":
      start = new Date(y, m, 1);
      end = today;
      break;
    case "custom":
      document.querySelector("#customDateFields").classList.remove("hidden");
      return;
    default:
      return;
  }
  document.querySelector("#reportStartDate").value = start.toISOString().slice(0, 10);
  document.querySelector("#reportEndDate").value = end.toISOString().slice(0, 10);
  document.querySelector("#customDateFields").classList.add("hidden");
}

document.querySelector("#reportDateModal")?.addEventListener("click", event => {
  const rangeBtn = event.target.closest("[data-range]");
  if (rangeBtn) setReportDateRange(rangeBtn.dataset.range);
});

document.querySelector("#reportDateCancel")?.addEventListener("click", closeReportDateDialog);
document.querySelector("#reportDateConfirm")?.addEventListener("click", () => {
  reportStartDate = document.querySelector("#reportStartDate").value;
  reportEndDate = document.querySelector("#reportEndDate").value;
  closeReportDateDialog();
  generateReport(reportFormat, reportStartDate, reportEndDate);
});

document.querySelector("#generateReportButton")?.addEventListener("click", () => showReportDateDialog("csv"));
document.querySelector("#generateReportReportsButton")?.addEventListener("click", () => showReportDateDialog("json"));

async function generateReport(format, startDate, endDate) {
  if (format === "csv") {
    const params = new URLSearchParams({ format: "csv" });
    if (startDate) params.set("start_date", startDate);
    if (endDate) params.set("end_date", endDate);
    window.open("api/generate_report.php?" + params.toString(), "_blank");
    return;
  }
  const params = new URLSearchParams({ format: "json" });
  if (startDate) params.set("start_date", startDate);
  if (endDate) params.set("end_date", endDate);
  let payload;
  try {
    payload = await apiRequest("api/generate_report.php?" + params.toString());
  } catch (e) {
    showToast(e.message, "error");
    return;
  }
  const report = payload.report;
  const panel = document.querySelector("#reportOutputPanel");
  const output = document.querySelector("#reportOutput");
  const stamp = document.querySelector("#reportGeneratedAt");
  const generator = document.querySelector("#reportGeneratedBy");
  if (!panel || !output) return;
  panel.classList.remove("hidden");
  if (stamp) stamp.textContent = report.generated_at;
  if (generator) generator.textContent = report.generated_by;
  const periodLabel = report.period_start ? report.period_start + " to " + report.period_end : "All time";
  output.innerHTML = `
    <table class="report-table">
      <caption>${t("reports.summarySection")} (${periodLabel})</caption>
      <tr><td>${t("stats.totalProducts")}</td><td>${report.summary.total_products}</td></tr>
      <tr><td>${t("stats.totalSales")}</td><td>${report.summary.total_sales}</td></tr>
      <tr><td>${t("stats.dailyRevenue")}</td><td>${money(report.summary.period_revenue)}</td></tr>
      <tr><td>${t("stats.dailyProfit")}</td><td>${money(report.summary.period_profit)}</td></tr>
    </table>
    <table class="report-table">
      <caption>${t("reports.productsSection")}</caption>
      <thead><tr><th>${t("products.namePlaceholder")}</th><th>${t("products.stock")}</th><th>${t("products.buying")}</th><th>${t("products.selling")}</th><th>${t("products.profit")}</th><th>${t("table.status")}</th></tr></thead>
      <tbody>${report.products.map(p => `
        <tr><td>${p.product_name}</td><td>${p.total_stock}</td><td>${money(p.buying_price)}</td><td>${money(p.selling_price)}</td><td>${money(p.profit_per_unit)}</td><td><span class="stock-badge ${p.stock_status === 'out_of_stock' ? 'danger' : p.stock_status === 'low_stock' ? 'warning' : ''}">${p.stock_status}</span></td></tr>
      `).join("")}</tbody>
    </table>
    ${report.recent_sales.length ? `
    <table class="report-table">
      <caption>${t("reports.recentSalesSection")}</caption>
      <thead><tr><th>${t("table.receipt")}</th><th>${t("table.amount")}</th><th>${t("table.profit")}</th><th>${t("common.today")}</th><th>${t("users.name")}</th></tr></thead>
      <tbody>${report.recent_sales.map(s => `
        <tr><td>${s.receipt_number}</td><td>${money(s.total_amount)}</td><td>${money(s.total_profit)}</td><td>${s.sale_date}</td><td>${s.seller_name}</td></tr>
      `).join("")}</tbody>
    </table>` : ""}
  `;
  document.querySelector("#reports")?.classList.add("active");
  document.querySelector('[data-page="reports"]')?.classList.add("active");
  showToast(t("reports.generatedReport"));
}

async function init() {
  console.log("[init] Starting app initialization...");
  try {
    // Load translations
    await loadTranslations(currentLanguage);
    applyTranslations();
    
    // Populate app language switcher
    const appSwitcher = document.querySelector("#appLanguageSwitcher");
    if (appSwitcher && !appSwitcher.options.length) {
      appSwitcher.innerHTML = '<option value="en">English</option><option value="sw">Swahili</option>';
      appSwitcher.value = currentLanguage;
    }
    
    // Always do a fresh API call to check authentication and owner status
    console.log("[init] Checking auth state via me.php...");
    const response = await fetch("api/me.php", {
      method: "GET",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      cache: "no-store"  // Force fresh fetch from server
    });
    
    let payload;
    try {
      payload = await response.json();
      console.log("[init] me.php response:", payload);
    } catch (jsonError) {
      console.error("[init] JSON parse error from me.php:", jsonError);
      showLogin(true);
      return;
    }
    
    // If user is authenticated, show app
    if (payload.authenticated && payload.user) {
      currentUser = payload.user;
      console.log("[init] User authenticated as:", currentUser.name, "(" + currentUser.role + ")");
      showApp();
      await refreshAppData();
    } else {
      // Not authenticated - check if owner exists
      const ownerExists = payload.owner_exists === true;
      console.log("[init] Not authenticated, owner_exists:", ownerExists);
      showLogin(ownerExists);
    }
  } catch (error) {
    console.error("[init] Initialization error:", error);
    showLogin(true);
  }
}

// Run init when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

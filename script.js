let products = [];
let currentUser = null;
let translations = {};
let currentLanguage = localStorage.getItem("preferredLanguage") || "en";
const cart = new Map();
const discountPrices = new Map();

function escapeHtml(str) {
  if (str == null) return "";
  const div = document.createElement("div");
  div.textContent = String(str);
  return div.innerHTML;
}

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

  if (currentUser) {
    const profileName = document.querySelector("#profileName");
    const profileRole = document.querySelector("#profileRole");
    const avatar = document.querySelector("#profileAvatar");

    if (profileName) profileName.textContent = currentUser.name;
    if (profileRole) {
      profileRole.textContent = currentUser.role;
      profileRole.className = "role-badge role-" + currentUser.role.toLowerCase();
    }
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
  const minPrice = Number(product.min_price ?? buying ?? 0);
  return {
    ...product,
    id: Number(product.id),
    variant_id: Number(product.variant_id),
    name: product.name,
    buying,
    selling,
    min_price: minPrice,
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
    const minPriceHtml = product.min_price > 0
      ? `<span>Min sell <strong>${money(product.min_price)}</strong></span>`
      : "";
    const actions = isOwner()
      ? `<div class="card-actions">
          <button type="button" data-edit-product="${product.id}">${t("products.edit")}</button>
          <button type="button" data-delete-product="${product.id}">${t("products.delete")}</button>
        </div>`
      : "";

    return `
      <article class="product-card">
        <div class="product-body">
          <h3>${escapeHtml(product.name)} ${stockBadge(product)}</h3>
          <div class="price-grid">
            ${buyingHtml}
            <span>${t("products.selling")} <strong>${money(product.selling)}</strong></span>
            ${minPriceHtml}
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
  document.querySelector("#posProducts").innerHTML = products.map((product, index) => {
    const minPriceInfo = product.min_price > 0 && product.min_price < product.selling
      ? `<br><small>Min: ${money(product.min_price)}</small>`
      : "";
    return `
    <article class="pos-item">
      <div>
        <strong>${escapeHtml(product.name)}</strong> ${stockBadge(product)}
        <small>${money(product.selling)} / ${t("products.stock")} ${product.stock}${minPriceInfo}</small>
      </div>
      <div class="qty-controls">
        <button type="button" data-dec="${index}" aria-label="${t("common.decrease")} ${escapeHtml(product.name)}">-</button>
        <span>${cart.get(index) || 0}</span>
        <button type="button" data-inc="${index}" aria-label="${t("common.increase")} ${escapeHtml(product.name)}">+</button>
      </div>
    </article>
  `}).join("") || `<p class="empty-state">${t("products.noProducts")}</p>`;
}

function getFinalPrice(index) {
  const product = products[index];
  if (!product) return 0;
  return discountPrices.has(index) ? discountPrices.get(index) : product.selling;
}

function renderCart() {
  const list = document.querySelector("#cartList");
  let total = 0;
  const lines = [...cart.entries()].filter(([, qty]) => qty > 0).map(([index, qty]) => {
    const product = products[index];
    const fp = getFinalPrice(index);
    total += fp * qty;
    const hasDiscount = discountPrices.has(index);
    const discountBtn = `<button type="button" class="ghost-button" style="padding:4px 8px;font-size:11px" data-discount="${index}">${hasDiscount ? "Discount: " + money(fp) : "Discount"}</button>`;
    const priceDisplay = hasDiscount
      ? `<span style="text-decoration:line-through;color:var(--text-secondary)">${money(product.selling)}</span> <strong>${money(fp)}</strong>`
      : `<strong>${money(product.selling)}</strong>`;
    const minPriceInfo = hasDiscount ? "" : product.min_price > 0 && product.min_price < product.selling
      ? `<br><small style="color:var(--text-secondary)">Min: ${money(product.min_price)}</small>`
      : "";
    return `<div class="cart-line">
      <span>
        ${escapeHtml(product.name)} x ${qty}
        ${minPriceInfo}
      </span>
      <span>${priceDisplay} ${discountBtn}</span>
    </div>`;
  });

  list.innerHTML = lines.join("") || `<p class="receipt-note">${t("sales.noProductsSelected")}</p>`;
  document.querySelector("#saleTotal").textContent = money(total);
  let cartProfit = 0;
  if (isOwner()) {
    [...cart.entries()].filter(([, qty]) => qty > 0).forEach(([index, qty]) => {
      const product = products[index];
      const fp = getFinalPrice(index);
      cartProfit += (fp - (product.buying || 0)) * qty;
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
    return `<rect x="${x}" y="${y}" width="${barWidth}" height="${barHeight}"><title>${escapeHtml(label)}: ${money(amount)}</title></rect>`;
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
    return `<li><strong>${escapeHtml(item.product_name)}</strong> — ${item.total_stock} ${t("products.stock").toLowerCase()} <em>(${label})</em></li>`;
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

  // Expenses and net profit (owner-only elements)
  const setOwnerStat = (id, value) => {
    const el = document.querySelector(id);
    if (el) el.textContent = value === null ? t("role.hidden") : money(value);
  };
  setOwnerStat("#dailyBuyingCost", payload.stats.daily_buying_cost);
  setOwnerStat("#dailyExpenses", payload.stats.daily_expenses);
  setOwnerStat("#dailyNetProfit", payload.stats.daily_net_profit);
  setOwnerStat("#monthlyExpenses", payload.stats.monthly_expenses);
  setOwnerStat("#monthlyNetProfit", payload.stats.monthly_net_profit);

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
      <td>${escapeHtml(sale.receipt_number)}</td>
      <td>${t("sales.posSale")}</td>
      <td>${escapeHtml(translatedCustomerType(sale.customer_type))}</td>
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

  const note = (id, has) => {
    const el = document.querySelector(id);
    if (el) el.textContent = has ? t("reports.liveData") : t("dashboard.noChartData");
  };
  note("#reportDailyNote", payload.stats.daily_sales > 0);
  note("#reportWeeklyNote", payload.stats.weekly_sales > 0);
  note("#reportMonthlyNote", payload.stats.monthly_sales > 0);

  // Financial cards (owner-only)
  const finGrid = document.querySelector("#financialReportGrid");
  if (finGrid && payload.stats.daily_profit !== null) {
    setStat("#finDailyRevenue", payload.stats.daily_sales);
    setStat("#finDailyBuyingCost", payload.stats.daily_buying_cost);
    setStat("#finDailyGrossProfit", payload.stats.daily_profit);
    setStat("#finDailyExpensesGross", payload.stats.daily_expenses);
    setStat("#finDailyNetProfitGross", payload.stats.daily_net_profit);

    setStat("#finMonthlySales", payload.stats.monthly_sales);
    setStat("#finMonthlyBuyingCost", payload.stats.monthly_buying_cost);
    setStat("#finMonthlyGrossProfit", payload.stats.monthly_profit);
    setStat("#finMonthlyExpensesGross", payload.stats.monthly_expenses);
    setStat("#finMonthlyNetProfitGross", payload.stats.monthly_net_profit);

    setStat("#finYearlyRevenue", payload.stats.yearly_revenue);
    setStat("#finYearlyBuyingCost", payload.stats.yearly_buying_cost);
    setStat("#finYearlyGrossProfit", payload.stats.yearly_profit);
    setStat("#finYearlyExpenses", payload.stats.yearly_expenses);
    setStat("#finYearlyNetProfit", payload.stats.yearly_net_profit);

    // Expense breakdown
    const container = document.querySelector("#expenseBreakdownContainer");
    if (container && payload.expense_categories?.length) {
      let total = 0;
      container.innerHTML = payload.expense_categories.map(c => {
        total += Number(c.total);
        return `<div class="fin-row"><span>${escapeHtml(t("expenseCategory." + c.category) || c.category)}</span><strong>${money(c.total)}</strong></div>`;
      }).join("") + `<div class="fin-row fin-divider"><span>${t("expenses.thisMonth")}</span><strong>${money(total)}</strong></div>`;
    }
  }

  renderLineChart(document.querySelector("#reportChart"), payload.monthly_chart, payload.has_sales);

  const bestBox = document.querySelector("#bestSellers");
  if (!payload.best_sellers?.length) {
    if (bestBox) bestBox.innerHTML = `<span class="empty-state">${t("dashboard.noChartData")}</span>`;
    return;
  }

  if (bestBox) {
    bestBox.innerHTML = payload.best_sellers.map(item => `
      <div class="best-seller-row">
        <strong>${escapeHtml(item.product_name)}</strong>
        <span>${escapeHtml(item.category_name || "")}</span>
        <small>${t("reports.unitsSold", { count: item.units_sold })} · ${money(item.revenue)}</small>
      </div>
    `).join("");
  }
}

function setStat(id, value) {
  const el = document.querySelector(id);
  if (el) el.textContent = value === null ? t("role.hidden") : money(value);
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
          return `<li><strong>${escapeHtml(item.product_name)}</strong> — ${item.total_stock} ${t("products.stock").toLowerCase()}${statusClass ? ` <span class="stock-badge ${statusClass}">${t("inventory." + (item.stock_status === "out_of_stock" ? "outOfStock" : "lowStock"))}</span>` : ""}</li>`;
        }).join("")
      : `<li>${t("products.noProducts")}</li>`;
  }

  const lowList = document.querySelector("#lowStockList");
  lowList.innerHTML = payload.low_stock_items.length
    ? payload.low_stock_items.map(item => `<li>${escapeHtml(item.product_name)} — ${item.total_stock} ${t("products.stock").toLowerCase()} (${t("dashboard.lowStockLabel")})</li>`).join("")
    : `<li>${t("inventory.noLowStock")}</li>`;

  const outList = document.querySelector("#outStockList");
  outList.innerHTML = payload.out_of_stock_items.length
    ? payload.out_of_stock_items.map(item => `<li>${escapeHtml(item.product_name)}</li>`).join("")
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

  shopNameGlobal = payload.shop.shop_name || "Mpeli Outfit Store";
  receiptFooterGlobal = payload.shop.receipt_footer || "";
  const sidebarTitle = document.querySelector(".sidebar-title");
  if (sidebarTitle) sidebarTitle.textContent = shopNameGlobal;
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
  shopNameGlobal = document.querySelector("#shopName").value || "Mpeli Outfit Store";
  receiptFooterGlobal = document.querySelector("#receiptFooter")?.value || "";
  const sidebarTitle = document.querySelector(".sidebar-title");
  if (sidebarTitle) sidebarTitle.textContent = shopNameGlobal;
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
  const footerHtml = receiptFooterGlobal ? `<p style="color:#888;font-size:12px;text-align:center;margin-top:20px;border-top:1px solid #ddd;padding-top:12px;">${escapeHtml(receiptFooterGlobal)}</p>` : "";
  win.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${escapeHtml(shopNameGlobal)} Report</title><style>
    body { font: 14px/1.5 sans-serif; color: #222; max-width: 900px; margin: 20px auto; padding: 20px; }
    h1 { color: #c9a24e; border-bottom: 2px solid #c9a24e; padding-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
    th { background: #f5f3ee; font-weight: 700; }
    caption { font-weight: 700; margin: 8px 0; text-align: left; font-size: 15px; }
    @media print { body { margin: 0; padding: 10px; } }
  </style></head><body><h1>${escapeHtml(shopNameGlobal)}</h1><p>${escapeHtml(stamp)}</p><p>Generated by: ${escapeHtml(generatorName)}</p>${output.innerHTML}${footerHtml}<p style="color:#888;font-size:12px;text-align:center;margin-top:30px;">Generated on ${escapeHtml(new Date().toLocaleString())}</p><script>window.onload=function(){window.print()}<\/script></body></html>`);
  win.document.close();
}

async function loadExpenses() {
  const payload = await apiRequest("api/expenses.php");
  document.querySelector("#expenseToday").textContent = money(payload.summary.today);
  document.querySelector("#expenseMonth").textContent = money(payload.summary.month);

  // Category breakdown for today
  const categoriesEl = document.querySelector("#expenseCategoryBreakdown");
  if (categoriesEl && payload.today_categories) {
    categoriesEl.innerHTML = payload.today_categories.length
      ? payload.today_categories.map(c => `
          <div class="expense-row" style="padding:6px 0">
            <span>${escapeHtml(c.category)}</span>
            <strong>${money(c.total)}</strong>
          </div>
        `).join("")
      : `<p class="receipt-note" style="padding:8px 0">${t("expenses.noExpensesToday")}</p>`;
  }

  // Render expense list
  const body = document.querySelector("#expensesBody");
  if (!body) return;
  if (!payload.expenses?.length) {
    body.innerHTML = `<tr><td colspan="6">${t("expenses.noExpenses")}</td></tr>`;
    return;
  }
  body.innerHTML = payload.expenses.map(e => {
    const actions = isOwner()
      ? `<td class="owner-only">
          <button type="button" class="ghost-button" data-edit-expense="${e.id}" style="padding:4px 8px;font-size:11px">${t("users.edit")}</button>
          <button type="button" class="ghost-button" data-delete-expense="${e.id}" style="padding:4px 8px;font-size:11px;color:var(--danger)">${t("products.delete")}</button>
        </td>`
      : "";
    const displayCategory = e.expense_name || e.category;
    return `<tr>
      <td>${escapeHtml(e.expense_date)}</td>
      <td>${escapeHtml(displayCategory)}</td>
      <td>${escapeHtml(e.description) || "-"}</td>
      <td>${money(e.amount)}</td>
      <td>${escapeHtml(e.created_by_name)}</td>
      ${actions}
    </tr>`;
  }).join("");
}

async function loadUsers() {
  if (!isOwner()) return;
  const payload = await apiRequest("api/users.php");
  document.querySelector("#usersBody").innerHTML = payload.users.map(user => `
    <tr data-user-id="${user.id}">
      <td>${escapeHtml(user.name)}</td>
      <td>${escapeHtml(user.username)}</td>
      <td>${escapeHtml(user.role)}</td>
      <td>${escapeHtml(user.status)}</td>
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
  const tasks = [loadProducts(), loadDashboard(), loadExpenses()];
  if (isOwner()) {
    tasks.push(loadSettings(), loadUsers(), loadInventory(), loadReports());
  }
  const results = await Promise.allSettled(tasks);
  let errors = 0;
  results.forEach((r, i) => {
    if (r.status === "rejected") {
      console.warn("refreshAppData: task", i, "failed", r.reason);
      errors++;
    }
  });
  if (errors > 0) {
    console.warn(`refreshAppData: ${errors}/${tasks.length} tasks failed`);
  }
}

async function refreshFinancialData() {
  const tasks = [loadDashboard()];
  if (isOwner()) {
    tasks.push(loadReports());
  }
  await Promise.allSettled(tasks);
}

async function completePayment() {
  const items = [...cart.entries()]
    .filter(([, quantity]) => quantity > 0)
    .map(([index, quantity]) => ({
      variant_id: products[index].variant_id,
      quantity,
      final_selling_price: getFinalPrice(index),
      original_selling_price: products[index].selling
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
  discountPrices.clear();
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
    startDashboardAutoRefresh();
  } catch (error) {
    showToast(error.message, "error");
  }
});

// Forgot / reset password
document.querySelector("#forgotPasswordLink")?.addEventListener("click", () => {
  document.querySelector("#resetPasswordModal").classList.remove("hidden");
});

function closeResetPasswordModal() {
  document.querySelector("#resetPasswordModal").classList.add("hidden");
  document.querySelector("#resetPasswordForm").reset();
}

document.querySelector("#resetPasswordClose")?.addEventListener("click", closeResetPasswordModal);
document.querySelector("#resetPasswordCancel")?.addEventListener("click", closeResetPasswordModal);
document.querySelector("#resetPasswordModal")?.addEventListener("click", e => {
  if (e.target === e.currentTarget) closeResetPasswordModal();
});

document.querySelector("#resetPasswordForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const username = document.querySelector("#resetUsername").value.trim();
  const email = document.querySelector("#resetEmail").value.trim();
  const password = document.querySelector("#resetNewPassword").value;
  const confirm = document.querySelector("#resetConfirmPassword").value;

  if (password !== confirm) {
    showToast(t("auth.newPasswordsDontMatch"), "error");
    return;
  }

  try {
    await apiRequest("api/reset_password.php", {
      method: "POST",
      body: JSON.stringify({ username, email, password })
    });
    showToast(t("auth.passwordResetSuccess"));
    closeResetPasswordModal();
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
        startDashboardAutoRefresh();
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

document.querySelectorAll(".nav-item").forEach(button => {
  button.addEventListener("click", async () => {
    if (button.classList.contains("owner-only") && !isOwner()) return;
    document.querySelectorAll(".nav-item").forEach(item => item.classList.remove("active"));
    document.querySelectorAll(".page").forEach(page => page.classList.remove("active"));
    button.classList.add("active");
    document.querySelector(`#${button.dataset.page}`).classList.add("active");
    document.querySelector(".sidebar").classList.remove("open");
    // Load page-specific data immediately
    const page = button.dataset.page;
    try {
      if (page === "dashboard") await loadDashboard();
      else if (page === "products") await loadProducts();
      else if (page === "sales") await loadProducts();
      else if (page === "expenses") await loadExpenses();
      else if (page === "inventory" && isOwner()) await loadInventory();
      else if (page === "reports" && isOwner()) await loadReports();
      else if (page === "users" && isOwner()) await loadUsers();
      else if (page === "settings" && isOwner()) await loadSettings();
    } catch (e) {
      console.warn(`[nav] ${page} data load failed:`, e);
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
  discountPrices.clear();
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
          <div class="product-image-placeholder">${escapeHtml(p.name[0])}</div>
          <strong>${escapeHtml(p.name)}</strong>
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
            <div class="product-image-placeholder">${escapeHtml(p.name[0])}</div>
            <strong>${escapeHtml(p.name)}</strong>
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
        minimum_allowed_selling_price: document.querySelector("#productMinPriceInput").value,
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
    const minPrice = prompt("Min allowed selling price", product.min_price ?? buying);
    if (minPrice === null) return;
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
          minimum_allowed_selling_price: minPrice,
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

document.querySelector("#cartList")?.addEventListener("click", event => {
  const discount = event.target.dataset.discount;
  if (discount !== undefined) {
    const index = Number(discount);
    const product = products[index];
    if (!product) return;
    const currentFp = getFinalPrice(index);
    const input = prompt(`Enter final selling price for ${product.name} (Min: ${money(product.min_price)}, Max: ${money(product.selling)})`, currentFp);
    if (input === null) return;
    const fp = Number(input);
    if (isNaN(fp) || fp <= 0) {
      showToast("Invalid price.", "error");
      return;
    }
    if (fp < product.min_price) {
      showToast("The selling price is below the minimum allowed price for this product.", "error");
      return;
    }
    if (fp > product.selling) {
      showToast("Final price cannot exceed the selling price.", "error");
      return;
    }
    if (fp === product.selling) {
      discountPrices.delete(index);
    } else {
      discountPrices.set(index, fp);
    }
    renderCart();
  }
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
    const fp = getFinalPrice(index);
    const lineTotal = fp * qty;
    total += lineTotal;
    if (discountPrices.has(index)) {
      items.push(`${p.name} x${qty}  ${money(p.selling)} → ${money(fp)} (discount)`);
    } else {
      items.push(`${p.name} x${qty}  ${money(fp * qty)}`);
    }
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

// Toggle expense form visibility
document.querySelector("#toggleExpenseForm")?.addEventListener("click", () => {
  const form = document.querySelector("#expenseFormPanel");
  if (form) form.classList.toggle("hidden");
});

// Show/hide custom expense name for "Other" category
document.querySelector("#expenseCategorySelect")?.addEventListener("change", event => {
  const nameInput = document.querySelector("#expenseCustomName");
  if (nameInput) nameInput.classList.toggle("hidden", event.target.value !== "Other");
});

// Save expense
document.querySelector("#saveExpenseButton")?.addEventListener("click", async () => {
  const category = document.querySelector("#expenseCategorySelect").value;
  const expenseName = document.querySelector("#expenseCustomName").value.trim();
  const description = document.querySelector("#expenseDescription").value.trim();
  const amount = Number(document.querySelector("#expenseAmountInput").value);
  const expenseDate = document.querySelector("#expenseDateInput").value || new Date().toISOString().slice(0, 10);
  const errorEl = document.querySelector("#expenseFormError");

  if (errorEl) errorEl.style.display = "none";

  if (category === "Other" && !expenseName) {
    if (errorEl) { errorEl.textContent = t("expenses.otherNameRequired"); errorEl.style.display = "block"; }
    return;
  }

  try {
    await apiRequest("api/expenses.php", {
      method: "POST",
      body: JSON.stringify({
        category,
        expense_name: expenseName,
        description,
        amount,
        expense_date: expenseDate
      })
    });
    document.querySelector("#expenseCategorySelect").value = "Food";
    document.querySelector("#expenseCustomName").value = "";
    document.querySelector("#expenseCustomName").classList.add("hidden");
    document.querySelector("#expenseDescription").value = "";
    document.querySelector("#expenseAmountInput").value = "";
    document.querySelector("#expenseDateInput").value = "";
    document.querySelector("#expenseFormPanel")?.classList.add("hidden");
    await loadExpenses();
    await refreshFinancialData();
    showToast(t("expenses.saved"));
  } catch (error) {
    if (errorEl) { errorEl.textContent = error.message; errorEl.style.display = "block"; }
    showToast(error.message, "error");
  }
});

// Edit and delete expense (via delegation on expenses table)
document.querySelector("#expensesBody")?.addEventListener("click", async event => {
  const editId = event.target.dataset.editExpense;
  const deleteId = event.target.dataset.deleteExpense;

  if (editId) {
    const expense = await apiRequest("api/expenses.php");
    const item = expense.expenses?.find(e => String(e.id) === editId);
    if (!item) return;
    const newCategory = prompt("Category (" + expense.categories.join(", ") + ")", item.expense_name || item.category);
    if (newCategory === null) return;
    const newAmount = prompt("Amount", String(item.amount));
    if (newAmount === null) return;
    const newDesc = prompt("Description", item.description || "");
    if (newDesc === null) return;
    try {
      await apiRequest("api/expenses.php", {
        method: "PUT",
        body: JSON.stringify({
          id: Number(editId),
          category: newCategory,
          expense_name: item.expense_name,
          description: newDesc,
          amount: Number(newAmount)
        })
      });
      showToast("Expense updated.");
      await loadExpenses();
      await refreshFinancialData();
    } catch (error) {
      showToast(error.message, "error");
    }
  }

  if (deleteId) {
    if (!confirm("Delete this expense?")) return;
    try {
      await apiRequest("api/expenses.php", {
        method: "DELETE",
        body: JSON.stringify({ id: Number(deleteId) })
      });
      showToast("Expense deleted.");
      await loadExpenses();
      await refreshFinancialData();
    } catch (error) {
      showToast(error.message, "error");
    }
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
      <caption>${t("reports.summarySection")} (${escapeHtml(periodLabel)})</caption>
      <tr><td>${t("stats.totalProducts")}</td><td>${report.summary.total_products}</td></tr>
      <tr><td>${t("stats.totalSales")}</td><td>${report.summary.total_sales}</td></tr>
      <tr><td>${t("stats.dailyRevenue")}</td><td>${money(report.summary.period_revenue)}</td></tr>
      <tr><td>${t("stats.dailyProfit")}</td><td>${money(report.summary.period_profit)}</td></tr>
    </table>
    <table class="report-table">
      <caption>${t("reports.productsSection")}</caption>
      <thead><tr><th>${t("products.namePlaceholder")}</th><th>${t("products.stock")}</th><th>${t("products.buying")}</th><th>${t("products.selling")}</th><th>${t("products.profit")}</th><th>${t("table.status")}</th></tr></thead>
      <tbody>${report.products.map(p => `
        <tr><td>${escapeHtml(p.product_name)}</td><td>${p.total_stock}</td><td>${money(p.buying_price)}</td><td>${money(p.selling_price)}</td><td>${money(p.profit_per_unit)}</td><td><span class="stock-badge ${p.stock_status === 'out_of_stock' ? 'danger' : p.stock_status === 'low_stock' ? 'warning' : ''}">${escapeHtml(p.stock_status)}</span></td></tr>
      `).join("")}</tbody>
    </table>
    ${report.recent_sales.length ? `
    <table class="report-table">
      <caption>${t("reports.recentSalesSection")}</caption>
      <thead><tr><th>${t("table.receipt")}</th><th>${t("table.amount")}</th><th>${t("table.profit")}</th><th>${t("common.today")}</th><th>${t("users.name")}</th></tr></thead>
      <tbody>${report.recent_sales.map(s => `
        <tr><td>${escapeHtml(s.receipt_number)}</td><td>${money(s.total_amount)}</td><td>${money(s.total_profit)}</td><td>${escapeHtml(s.sale_date)}</td><td>${escapeHtml(s.seller_name)}</td></tr>
      `).join("")}</tbody>
    </table>` : ""}
  `;
  document.querySelector("#reports")?.classList.add("active");
  document.querySelector('[data-page="reports"]')?.classList.add("active");
  showToast(t("reports.generatedReport"));
}

let dashboardRefreshTimer = null;
let shopNameGlobal = "Mpeli Outfit Store";
let receiptFooterGlobal = "";

function startDashboardAutoRefresh() {
  if (dashboardRefreshTimer) return;
  dashboardRefreshTimer = setInterval(async () => {
    if (document.querySelector("#dashboard")?.classList.contains("active")) {
      try {
        await refreshFinancialData();
      } catch (e) {
        console.warn("Auto-refresh failed:", e);
      }
    }
  }, 30000);
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
    let payload;
    try {
      payload = await apiRequest("api/me.php");
    } catch (error) {
      console.error("[init] Auth check failed:", error);
      showLogin(true);
      return;
    }
    
    // If user is authenticated, show app
    if (payload.authenticated && payload.user) {
      currentUser = payload.user;
      console.log("[init] User authenticated as:", currentUser.name, "(" + currentUser.role + ")");
      showApp();
      await refreshAppData();
      startDashboardAutoRefresh();
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

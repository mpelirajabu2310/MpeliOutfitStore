let products = [];
let currentUser = null;
let translations = {};
let currentLanguage;
try { currentLanguage = localStorage.getItem("preferredLanguage"); } catch (_) { currentLanguage = null; }
currentLanguage = currentLanguage || "en";
const cart = new Map();
const discountPrices = new Map();
let promotions = [];
let ownerPromotions = [];
const promoByProduct = new Map();
let bulkDiscountEnabled = false;
let bulkDiscountPercent = 15;
const MAX_BULK_PERCENT = 20;

const CART_STORAGE_KEY = "pos.cart";
const DISCOUNT_STORAGE_KEY = "pos.discounts";

function saveCartState() {
  try {
    sessionStorage.setItem(CART_STORAGE_KEY, JSON.stringify(Object.fromEntries(cart)));
    sessionStorage.setItem(DISCOUNT_STORAGE_KEY, JSON.stringify(Object.fromEntries(discountPrices)));
  } catch (e) {
  }
}

function restoreCartState() {
  try {
    const rawCart = sessionStorage.getItem(CART_STORAGE_KEY);
    if (rawCart) {
      const parsed = JSON.parse(rawCart);
      cart.clear();
      Object.entries(parsed).forEach(([id, qty]) => {
        const n = Number(id);
        const q = Number(qty);
        if (Number.isFinite(n) && Number.isFinite(q) && q > 0) cart.set(n, q);
      });
    }
    const rawDiscounts = sessionStorage.getItem(DISCOUNT_STORAGE_KEY);
    if (rawDiscounts) {
      const parsed = JSON.parse(rawDiscounts);
      discountPrices.clear();
      Object.entries(parsed).forEach(([id, price]) => {
        const n = Number(id);
        const p = Number(price);
        if (Number.isFinite(n) && Number.isFinite(p) && p > 0) discountPrices.set(n, p);
      });
    }
  } catch (e) {
  }
}

function capCartToStock() {
  [...cart.entries()].forEach(([id, qty]) => {
    const product = products.find(p => p.id === id);
    if (!product) return;
    const capped = Math.max(0, Math.min(qty, product.stock));
    if (capped === 0) cart.delete(id);
    else cart.set(id, capped);
  });
  [...discountPrices.keys()].forEach(id => {
    if (!cart.has(id) || !products.some(p => p.id === id)) discountPrices.delete(id);
  });
}

function clearCartState() {
  cart.clear();
  discountPrices.clear();
  try {
    sessionStorage.removeItem(CART_STORAGE_KEY);
    sessionStorage.removeItem(DISCOUNT_STORAGE_KEY);
  } catch (e) {
  }
}

const KNOWN_PAGES = ["dashboard", "products", "sales", "expenses", "inventory", "reports", "analytics", "users", "audit", "backup", "settings"];

function rememberPage(page) {
  if (!KNOWN_PAGES.includes(page)) return;
  try {
    sessionStorage.setItem("app.lastPage", page);
  } catch (e) {
  }
}

function getLastPage() {
  try {
    const page = sessionStorage.getItem("app.lastPage");
    return KNOWN_PAGES.includes(page) ? page : null;
  } catch (e) {
    return null;
  }
}

function forgetLastPage() {
  try {
    sessionStorage.removeItem("app.lastPage");
  } catch (e) {
  }
}

let saleRequestKey = null;
let expenseRequestKey = null;

function generateRequestKey() {
  if (window.crypto && typeof window.crypto.randomUUID === "function") {
    return window.crypto.randomUUID();
  }
  return "req-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 12);
}

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
  }
})();

// Also force reset after DOM is fully ready
document.addEventListener('DOMContentLoaded', () => {
  const loginScreen = document.querySelector("#loginScreen");
  const appShell = document.querySelector("#appShell");
  
  if (loginScreen) loginScreen.classList.remove("hidden");
  if (appShell) appShell.classList.add("hidden");

  const expenseDateInput = document.querySelector("#expenseDateInput");
  if (expenseDateInput) expenseDateInput.max = new Date().toISOString().slice(0, 10);
}, { once: true });

// Theme system
const STORAGE_THEME_KEY = "preferredTheme";
function getStoredTheme() {
  try { return localStorage.getItem(STORAGE_THEME_KEY) || "light"; } catch (_) { return "light"; }
}
function setTheme(theme) {
  document.body.classList.toggle("dark", theme === "dark");
  try { localStorage.setItem(STORAGE_THEME_KEY, theme); } catch (_) {}
  const btn = document.querySelector("#themeToggle");
  if (btn) btn.innerHTML = theme === "dark" ? '<i class="bi bi-sun-fill"></i>' : '<i class="bi bi-moon-stars"></i>';
}
function toggleTheme() {
  setTheme(document.body.classList.contains("dark") ? "light" : "dark");
  refreshChartsAfterTheme();
}

// Rebuild active amCharts so they pick up the re-themed CSS palette.
function refreshChartsAfterTheme() {
  window.MpeliCharts?.clearThemeCache?.();
  setTimeout(() => {
    try {
      if (document.querySelector("#dashboard")?.classList.contains("active")) {
        loadDashboard({ background: true }).catch(() => {});
      }
      if (document.querySelector("#analytics")?.classList.contains("active")) {
        loadBIView().catch(() => {});
      }
    } catch (e) {}
  }, 60);
}
// Apply theme immediately before any render
setTheme(getStoredTheme());

const money = value => {
  const amount = Number(value || 0);
  return `TSH ${amount.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
};

let lowStockThreshold = 5;
let csrfToken;
try { csrfToken = localStorage.getItem("csrf_token"); } catch (_) { csrfToken = null; }
csrfToken = csrfToken || "";

function storeCsrfToken(token) {
  if (token) {
    csrfToken = token;
    try { localStorage.setItem("csrf_token", token); } catch (_) {}
  }
}

function clearCsrfToken() {
  csrfToken = "";
  try { localStorage.removeItem("csrf_token"); } catch (_) {}
}

async function apiRequest(url, options = {}) {
  const method = (options.method || "GET").toUpperCase();
  const needsCsrf = ["POST", "PUT", "DELETE"].includes(method);

  const headers = { ...(options.headers || {}) };
  if (!(options.body instanceof FormData)) {
    headers["Content-Type"] = "application/json";
  }
  if (needsCsrf && csrfToken) {
    headers["X-CSRF-Token"] = csrfToken;
  }
  // Background polling (chart refreshes, dashboard auto-updates) must not reset
  // the server-side idle timer.
  if (options.background) {
    headers["X-Background"] = "1";
  }

  try {
    const response = await fetch(url, {
      ...options,
      method,
      headers,
      credentials: "same-origin",
      cache: "no-store",
    });
    
    let payload;
    try {
      payload = await response.json();
    } catch (jsonError) {
      throw new Error("Invalid response from server. Please try again.");
    }

    // Update CSRF token from any successful response
    if (payload.csrf_token) {
      storeCsrfToken(payload.csrf_token);
    }

    // The server rejected the session (idle timeout, logout in another tab).
    // Log the user out gracefully instead of leaving a dead app shell.
    if (response.status === 401 && currentUser && !/login\.php|register_owner\.php|recover_owner\.php/.test(url)) {
      forceLogout("idle_timeout");
    }

    if (!response.ok || payload.success === false) {
      const message = payload.message || "Request failed.";
      throw new Error(message);
    }
    
    return payload;
  } catch (error) {
    throw error;
  }
}

async function loadTranslations(language) {
  try {
    const response = await fetch(`locales/${language}.json`);
    if (!response.ok) throw new Error("Translation file not found.");
    translations = await response.json();
    currentLanguage = language;
    try { localStorage.setItem("preferredLanguage", language); } catch (_) {}
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
  document.querySelectorAll(".seller-only").forEach(element => {
    element.classList.toggle("hidden", isOwner());
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

    updateTopbarStoreName();
    updateOnlineStatus();
    updateReceiptFooter();
  }
}

function updateReceiptFooter() {
  const el = document.getElementById("receiptStoreRole");
  if (el && currentUser) {
    const storeName = shopNameGlobal || "Mpeli Outfit Store";
    const roleLabel = currentUser.role === "OWNER" ? "Admin" : "Seller";
    el.textContent = storeName + " - " + roleLabel;
  }
}

function showApp() {
  document.querySelector("#healthScreen")?.classList.add("hidden");
  document.querySelector("#maintenanceScreen")?.classList.add("hidden");
  document.querySelector("#loginScreen").classList.add("hidden");
  document.querySelector("#appShell").classList.remove("hidden");
  setTheme(getStoredTheme());
  applyRoleUI();
  startClock();
  setupUserDropdown();
  updateOnlineStatus();
  updateTopbarPageTitle("dashboard");
  startIdleTimer();
  history.pushState({ page: "app" }, "", location.href);
}

function showLogin(ownerExists = true) {
  document.querySelector("#healthScreen")?.classList.add("hidden");
  document.querySelector("#maintenanceScreen")?.classList.add("hidden");
  document.querySelector("#loginScreen").classList.remove("hidden");
  document.querySelector("#appShell").classList.add("hidden");
  stopClock();
  stopIdleTimer();
  
  if (ownerExists) {
    document.querySelector("#loginForm").classList.remove("hidden");
    document.querySelector("#ownerSetupForm").classList.add("hidden");
  } else {
    document.querySelector("#loginForm").classList.add("hidden");
    document.querySelector("#ownerSetupForm").classList.remove("hidden");
  }
}

function showHealthScreen(checks, allPassed) {
  document.querySelector("#loginScreen").classList.add("hidden");
  document.querySelector("#appShell").classList.add("hidden");
  document.querySelector("#maintenanceScreen")?.classList.add("hidden");
  const screen = document.querySelector("#healthScreen");
  screen.classList.remove("hidden");

  const title = document.querySelector("#healthTitle");
  const subtitle = document.querySelector("#healthSubtitle");
  const checksContainer = document.querySelector("#healthChecks");
  const actions = document.querySelector("#healthActions");
  const spinner = screen.querySelector(".health-spinner");

  title.textContent = allPassed ? "System Ready" : "System Health Check";
  subtitle.textContent = allPassed
    ? "All systems operational."
    : "Some checks failed. Please review the details below.";

  spinner.classList.add("hidden");
  actions.classList.remove("hidden");

  checksContainer.innerHTML = checks.map(c => {
    const icon = c.severity === 'critical' ? 'bi-x-circle-fill'
      : c.severity === 'warning' ? 'bi-exclamation-triangle-fill'
      : 'bi-check-circle-fill';
    const cls = c.severity === 'critical' ? 'fail'
      : c.severity === 'warning' ? 'warn'
      : 'pass';
    return `<div class="health-check-item ${cls}">
      <span class="health-check-icon"><i class="bi ${icon}"></i></span>
      <span class="health-check-label">${escapeHtml(c.label)}</span>
      <span class="health-check-detail">${escapeHtml(c.detail)}</span>
    </div>`;
  }).join("");

  if (allPassed) {
    setTimeout(() => {
      screen.classList.add("hidden");
    }, 2000);
  }
}

function showMaintenanceScreen(message) {
  document.querySelector("#loginScreen").classList.add("hidden");
  document.querySelector("#appShell").classList.add("hidden");
  document.querySelector("#healthScreen")?.classList.add("hidden");
  const screen = document.querySelector("#maintenanceScreen");
  screen.classList.remove("hidden");
  document.querySelector("#maintenanceMessage").textContent = message;
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
  restoreCartState();
  capCartToStock();
  saveCartState();
  renderProducts();
  renderCart();
}

function productImageUrl(product) {
  if (!product.image_path) return "";
  return product.updated_at
    ? product.image_path + "?v=" + encodeURIComponent(product.updated_at)
    : product.image_path;
}

let productImgObserver = null;
function getProductImgObserver() {
  if (productImgObserver) return productImgObserver;
  if (!("IntersectionObserver" in window)) return null;
  productImgObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      const el = entry.target;
      if (el.dataset.src) {
        el.style.backgroundImage = `url("${el.dataset.src}")`;
        delete el.dataset.src;
      }
      productImgObserver.unobserve(el);
    });
  }, { rootMargin: "300px" });
  return productImgObserver;
}

function observeProductImages(container) {
  const layers = container.querySelectorAll(".product-img-layer[data-src]");
  const observer = getProductImgObserver();
  if (!observer) {
    layers.forEach((el) => {
      el.style.backgroundImage = `url("${el.dataset.src}")`;
      delete el.dataset.src;
    });
    return;
  }
  layers.forEach((el) => observer.observe(el));
}

function productCardHtml(product) {
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
  const imageUrl = productImageUrl(product);
  const imageLayer = imageUrl
    ? `<div class="product-img-layer" data-src="${escapeHtml(imageUrl)}"></div>`
    : `<div class="product-img-layer placeholder"><i class="bi bi-image"></i></div>`;

  return `
    <article class="product-card ${imageUrl ? "has-image" : "no-image"}">
      ${imageLayer}
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
}

function posItemThumbHtml(product) {
  const imageUrl = productImageUrl(product);
  return imageUrl
    ? `<img class="pos-thumb" src="${escapeHtml(imageUrl)}" alt="" loading="lazy" />`
    : `<span class="pos-thumb placeholder"><i class="bi bi-image"></i></span>`;
}

function renderProducts() {
  const grid = document.querySelector("#productGrid");
  if (!grid) return;

  grid.innerHTML = products.map(productCardHtml).join("") || `<article class="panel"><h3>${t("products.noProducts")}</h3><p>${t("products.startFresh")}</p></article>`;

  observeProductImages(grid);
}

function renderPosProducts() {
  document.querySelector("#posProducts").innerHTML = products.map(product => {
    const minPriceInfo = product.min_price > 0 && product.min_price < product.selling
      ? `<br><small>Min: ${money(product.min_price)}</small>`
      : "";
    const promo = promoByProduct.get(product.id);
    const promoBadge = promo ? `<span class="promo-badge">-${promo.percentage}%</span>` : "";
    const priceHtml = promo
      ? `<small><s>${money(product.selling)}</s> <strong class="promo-price">${money(promo.promo_price)}</strong> ${promoBadge}<br><small>${t("products.stock")} ${product.stock}</small></small>`
      : `<small>${money(product.selling)} / ${t("products.stock")} ${product.stock}${minPriceInfo}</small>`;
    return `
    <article class="pos-item">
      ${posItemThumbHtml(product)}
      <div>
        <strong>${escapeHtml(product.name)}</strong> ${stockBadge(product)}
        ${priceHtml}
      </div>
      <div class="qty-controls">
        <button type="button" data-dec="${product.id}" aria-label="${t("common.decrease")} ${escapeHtml(product.name)}">-</button>
        <span>${cart.get(product.id) || 0}</span>
        <button type="button" data-inc="${product.id}" aria-label="${t("common.increase")} ${escapeHtml(product.name)}">+</button>
      </div>
    </article>
  `}).join("") || `<p class="empty-state">${t("products.noProducts")}</p>`;
}

function round2(value) {
  return Math.round(value * 100) / 100;
}

function getCartTotalQuantity() {
  return [...cart.entries()].reduce((sum, [, qty]) => sum + Math.max(0, qty), 0);
}

function isBulkDiscountActive() {
  return bulkDiscountEnabled && getCartTotalQuantity() >= 3;
}

function bulkPriceForNormal(id) {
  const product = products.find(p => p.id === id);
  if (!product) return 0;
  return Math.max(product.min_price, round2(product.selling * (100 - bulkDiscountPercent) / 100));
}

function getPromoForProduct(id) {
  return promoByProduct.get(id) || null;
}

function getPricingType(id) {
  const product = products.find(p => p.id === id);
  if (!product) return "normal";
  if (discountPrices.has(id)) return "existing_discount";
  if (promoByProduct.has(id)) return "promotion";
  if (isBulkDiscountActive() && bulkPriceForNormal(id) < product.selling) return "bulk_discount";
  return "normal";
}

function getPromotionIdFor(id) {
  const promo = promoByProduct.get(id);
  return promo ? promo.promotion_id : null;
}

function getFinalPrice(id) {
  const product = products.find(p => p.id === id);
  if (!product) return 0;
  if (discountPrices.has(id)) return discountPrices.get(id);
  const promo = promoByProduct.get(id);
  if (promo) return promo.promo_price;
  if (isBulkDiscountActive()) {
    const bp = bulkPriceForNormal(id);
    if (bp < product.selling) return bp;
  }
  return product.selling;
}

function applyPromotionsToProducts() {
  promoByProduct.clear();
  if (!products.length) {
    renderCart();
    renderPosProducts();
    return;
  }
  const productMap = new Map(products.map(p => [p.id, p]));
  promotions.forEach(promo => {
    const pct = Number(promo.percentage);
    const apply = (product) => {
      if (!product) return;
      const price = Math.max(product.min_price, round2(product.selling * (100 - pct) / 100));
      if (price < product.selling) {
        promoByProduct.set(product.id, {
          promotion_id: Number(promo.id),
          name: promo.name,
          percentage: pct,
          promo_price: price
        });
      }
    };
    if (Number(promo.all_products) === 1) {
      productMap.forEach(apply);
    } else {
      (promo.product_ids || []).forEach(pid => apply(productMap.get(Number(pid))));
    }
  });
  renderCart();
  renderPosProducts();
  renderBulkPanel();
}

async function loadPromotions() {
  const payload = await apiRequest("api/promotions.php?active=1");
  promotions = payload.promotions || [];
  applyPromotionsToProducts();
}

function renderBulkPanel() {
  const wrap = document.querySelector("#bulkDiscountWrap");
  if (!wrap) return;
  const toggle = document.querySelector("#bulkDiscountToggle");
  const input = document.querySelector("#bulkDiscountPercent");
  const hint = document.querySelector("#bulkDiscountHint");
  const count = getCartTotalQuantity();
  const qualifies = count >= 3;
  if (!qualifies) {
    if (bulkDiscountEnabled) bulkDiscountEnabled = false;
    if (toggle) {
      toggle.checked = false;
      toggle.disabled = true;
    }
    if (hint) hint.textContent = t("sales.bulkNeedsItems", { remaining: Math.max(0, 3 - count) });
  } else {
    if (toggle) {
      toggle.disabled = false;
      toggle.checked = bulkDiscountEnabled;
    }
    if (hint) hint.textContent = t("sales.bulkHint");
  }
  if (input) {
    input.max = MAX_BULK_PERCENT;
    input.value = bulkDiscountPercent;
  }
}

function renderCart() {
  const list = document.querySelector("#cartList");
  let total = 0;
  const lines = [...cart.entries()].filter(([, qty]) => qty > 0).map(([id, qty]) => {
    const product = products.find(p => p.id === id);
    if (!product) return "";
    const fp = getFinalPrice(id);
    total += fp * qty;
    const pType = getPricingType(id);
    const hasDiscount = fp < product.selling;
    const promo = getPromoForProduct(id);
    const promoBadge = pType === "promotion" && promo ? `<span class="promo-badge">-${promo.percentage}%</span>` : "";
    const bulkBadge = pType === "bulk_discount" ? `<span class="promo-badge bulk">${t("sales.bulkLabel")} -${bulkDiscountPercent}%</span>` : "";
    const discountBtn = `<button type="button" class="ghost-button" style="padding:4px 8px;font-size:11px" data-discount="${id}">${discountPrices.has(id) ? t("sales.discountSet") + ": " + money(fp) : t("sales.discountBtn")}</button>`;
    const priceDisplay = hasDiscount
      ? `<span style="text-decoration:line-through;color:var(--text-secondary)">${money(product.selling)}</span> ${promoBadge}${bulkBadge} <strong>${money(fp)}</strong>`
      : `<strong>${money(product.selling)}</strong> ${promoBadge}${bulkBadge}`;
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
  }).filter(Boolean);

  list.innerHTML = lines.join("") || `<p class="receipt-note">${t("sales.noProductsSelected")}</p>`;
  document.querySelector("#saleTotal").textContent = money(total);
  let cartProfit = 0;
  if (isOwner()) {
    [...cart.entries()].filter(([, qty]) => qty > 0).forEach(([id, qty]) => {
      const product = products.find(p => p.id === id);
      if (!product) return;
      const fp = getFinalPrice(id);
      cartProfit += (fp - (product.buying || 0)) * qty;
    });
    document.querySelector("#saleProfit").textContent = money(cartProfit);
  } else {
    document.querySelector("#saleProfit").textContent = t("role.hidden");
  }
  renderPosProducts();
  renderBulkPanel();
}

function formatChartDay(day) {
  const date = new Date(`${day}T00:00:00Z`);
  return Number.isNaN(date.getTime()) ? day : date.toLocaleDateString(undefined, { weekday: "short" });
}

function renderBarChart(container, chart, hasData, valueKey = "value", showDayLabels = false) {
  if (!container) return;
  if (!hasData || !chart?.length) {
    container.innerHTML = `<p class="empty-state">${t("dashboard.noChartData")}</p>`;
    return;
  }

  const max = Math.max(...chart.map(item => Number(item[valueKey] ?? item.revenue ?? item.value ?? 0)), 1);
  const width = 700;
  const labelSpace = showDayLabels ? 36 : 0;
  const height = 220 + labelSpace;
  const gap = 18;
  const barWidth = Math.floor((width - gap * (chart.length + 1)) / chart.length);
  const bars = chart.map((item, index) => {
    const amount = Number(item[valueKey] ?? item.revenue ?? item.value ?? 0);
    const barHeight = Math.max(18, Math.round((amount / max) * 180));
    const x = gap + index * (barWidth + gap);
    const y = height - barHeight - 20 - labelSpace;
    const label = item.product_name || formatChartDay(item.sale_day || item.report_month);
    const title = `<title>${escapeHtml(label)}: ${money(amount)}</title>`;
    const dayLabel = showDayLabels && item.sale_day
      ? `<text x="${x + barWidth / 2}" y="${height - 4}" text-anchor="middle" class="chart-day-label">${escapeHtml(formatChartDay(item.sale_day))}</text>`
      : "";
    return `<rect x="${x}" y="${y}" width="${barWidth}" height="${barHeight}">${title}</rect>${dayLabel}`;
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

async function loadDashboard(options = {}) {
  const payload = await apiRequest("api/dashboard.php", options);
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

  renderStockAlerts(payload.stock_alerts);

  const rows = payload.recent_sales.map(sale => `
    <tr>
      <td>${escapeHtml(sale.receipt_number)}</td>
      <td>${t("sales.posSale")}</td>
      <td>${escapeHtml(translatedCustomerType(sale.customer_type))}</td>
      <td>${money(sale.total_amount)}</td>
      <td class="owner-only">${sale.total_profit === null ? t("role.hidden") : money(sale.total_profit)}</td>
      <td><span class="status paid">${t(`status.${sale.payment_status}`)}</span></td>
      <td><button class="ghost-button sale-view-btn" data-sale-id="${sale.sale_id}"><i class="bi bi-eye"></i> ${t("saleDetails.view")}</button></td>
    </tr>
  `);
  document.querySelector("#recentSalesBody").innerHTML = rows.join("") || `
    <tr><td colspan="7">${t("sales.noCompletedSales")}</td></tr>
  `;

  if (window.MpeliDashboardCharts && window.MpeliCharts?.am5) {
    window.MpeliDashboardCharts.render(payload);
  } else if (window.MpeliCharts) {
    window.MpeliCharts.onReady(() => {
      if (window.MpeliDashboardCharts?.render) window.MpeliDashboardCharts.render(payload);
    });
  } else {
    renderBarChart(document.querySelector(".revenue-chart") || document.querySelector(".bar-chart"), payload.revenue_chart, payload.has_revenue_chart, "value", true);
    renderBarChart(document.querySelector(".profit-chart"), payload.profit_chart, payload.has_profit_chart, "value", true);
  }

  renderSellerAnalytics(payload.analytics);
}

function renderSellerAnalytics(analytics) {
  const p = analytics?.periods;
  if (!p) return;
  const set = (id, val) => {
    const el = document.querySelector(id);
    if (el) el.textContent = money(val);
  };
  set("#analWeekSales", p.week?.sales);
  set("#analWeekExpenses", p.week?.expenses);
  set("#analMonthSales", p.month?.sales);
  set("#analMonthExpenses", p.month?.expenses);
  set("#analYearSales", p.year?.sales);
  set("#analYearExpenses", p.year?.expenses);
  set("#analTotalSales", p.total?.sales);
  set("#analTotalExpenses", p.total?.expenses);
}

// ── Sale Details Modal ──────────────────────────────────────────────
function closeSaleDetailsModal() {
  document.querySelector("#saleDetailsModal")?.classList.add("hidden");
}

document.querySelector("#saleDetailsModal")?.addEventListener("click", e => {
  if (e.target === e.currentTarget) closeSaleDetailsModal();
});

document.addEventListener("click", e => {
  const btn = e.target.closest(".sale-view-btn");
  if (!btn) return;
  const saleId = btn.getAttribute("data-sale-id");
  if (saleId) openSaleDetails(saleId);
});

async function openSaleDetails(saleId) {
  const modal = document.querySelector("#saleDetailsModal");
  const content = document.querySelector("#saleDetailsContent");
  if (!modal || !content) return;

  content.innerHTML = `<div class="sale-details-loading"><div class="sale-details-spinner"></div><p>${t("saleDetails.loading")}</p></div>`;
  modal.classList.remove("hidden");

  try {
    const payload = await apiRequest(`api/sale_details.php?id=${encodeURIComponent(saleId)}`);
    if (!payload.success || !payload.sale) {
      content.innerHTML = `<div class="sale-details-error"><i class="bi bi-exclamation-triangle"></i><p>${escapeHtml(payload.message || t("saleDetails.error"))}</p></div>`;
      return;
    }
    renderSaleDetailsContent(payload.sale);
  } catch (err) {
    const msg = err && err.message ? err.message : t("saleDetails.networkError");
    content.innerHTML = `<div class="sale-details-error"><i class="bi bi-exclamation-triangle"></i><p>${escapeHtml(msg)}</p></div>`;
  }
}

function renderSaleDetailsContent(sale) {
  const content = document.querySelector("#saleDetailsContent");
  if (!content) return;

  const saleDate = new Date(sale.sale_date);
  const dateStr = saleDate.toLocaleDateString(undefined, { year: "numeric", month: "long", day: "numeric" });
  const timeStr = saleDate.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });

  let totalQuantity = 0;
  let totalDiscount = 0;
  const isOwner = currentUser && currentUser.role === "OWNER";

  const shopInfo = sale.shop || {};
  const shopName = escapeHtml(shopInfo.shop_name || shopNameGlobal || "Mpeli Outfit Store");
  const shopAddress = shopInfo.address ? escapeHtml(shopInfo.address) : "";
  const shopPhone = shopInfo.phone ? escapeHtml(shopInfo.phone) : "";
  const shopEmail = shopInfo.email ? escapeHtml(shopInfo.email) : "";
  const receiptFooterText = shopInfo.receipt_footer || receiptFooterGlobal || "";
  const logoUrl = shopInfo.logo_url || "assets/images/logo.png";

  const customerName = sale.customer_name ? escapeHtml(sale.customer_name) : null;
  const customerPhone = sale.customer_phone ? escapeHtml(sale.customer_phone) : null;

  const paymentMethod = sale.payment_method || "cash";
  const paymentLabels = { cash: t("payment.cash"), card: t("payment.card"), mobile_money: t("payment.mobileMoney") };
  const paymentLabel = paymentLabels[paymentMethod] || paymentMethod;

  const itemsHtml = (sale.items || []).map((item, i) => {
    const qty = parseInt(item.quantity, 10) || 0;
    const unitPrice = parseFloat(item.selling_price) || 0;
    const origPrice = parseFloat(item.original_selling_price) || unitPrice;
    const discountPerUnit = origPrice > unitPrice ? origPrice - unitPrice : 0;
    const lineDiscount = discountPerUnit * qty;
    const lineTotal = parseFloat(item.line_total) || (unitPrice * qty);

    totalQuantity += qty;
    totalDiscount += lineDiscount;

    const productName = escapeHtml(item.product_name);
    const variantParts = [];
    if (item.size_label) variantParts.push(escapeHtml(item.size_label));
    if (item.color_label) variantParts.push(escapeHtml(item.color_label));
    const variantStr = variantParts.length > 0 ? `<br><small class="receipt-item-variant">${variantParts.join(" / ")}</small>` : "";

    return `<tr>
      <td>${productName}${variantStr}</td>
      <td class="receipt-col-center">${qty}</td>
      <td class="receipt-col-right">${money(unitPrice)}</td>
      <td class="receipt-col-right">${lineDiscount > 0 ? money(lineDiscount) : "—"}</td>
      <td class="receipt-col-right receipt-col-total">${money(lineTotal)}</td>
    </tr>`;
  }).join("");

  const headerDiscount = parseFloat(sale.discount_amount) || totalDiscount;
  const totalProductTypes = sale.items ? sale.items.length : 0;

  content.innerHTML = `
    <div class="receipt-print-area" id="receiptPrintArea">
      <div class="receipt-brand">
        <img src="${logoUrl}" alt="${shopName}" class="receipt-logo" onerror="this.style.display='none'" />
        <h2 class="receipt-store-name">${shopName}</h2>
        ${shopAddress ? `<p class="receipt-contact"><i class="bi bi-geo-alt"></i> ${shopAddress}</p>` : ""}
        ${shopPhone ? `<p class="receipt-contact"><i class="bi bi-telephone"></i> ${shopPhone}</p>` : ""}
        ${shopEmail ? `<p class="receipt-contact"><i class="bi bi-envelope"></i> ${shopEmail}</p>` : ""}
      </div>

      <div class="receipt-divider-thick"></div>

      <div class="receipt-header-info">
        <div class="receipt-meta-row">
          <span class="receipt-meta-label">${t("saleDetails.receiptNo") || "Receipt No."}</span>
          <span class="receipt-meta-value">${escapeHtml(sale.receipt_number)}</span>
        </div>
        <div class="receipt-meta-row">
          <span class="receipt-meta-label">${t("saleDetails.date") || "Date"}</span>
          <span class="receipt-meta-value">${dateStr}</span>
        </div>
        <div class="receipt-meta-row">
          <span class="receipt-meta-label">${t("saleDetails.time") || "Time"}</span>
          <span class="receipt-meta-value">${timeStr}</span>
        </div>
        <div class="receipt-meta-row">
          <span class="receipt-meta-label">${t("saleDetails.soldBy") || "Sold by"}</span>
          <span class="receipt-meta-value">${escapeHtml(sale.seller_name)}</span>
        </div>
        ${customerName ? `<div class="receipt-meta-row">
          <span class="receipt-meta-label">${t("saleDetails.customer") || "Customer"}</span>
          <span class="receipt-meta-value">${customerName}</span>
        </div>` : ""}
        ${customerPhone ? `<div class="receipt-meta-row">
          <span class="receipt-meta-label">${t("saleDetails.phone") || "Phone"}</span>
          <span class="receipt-meta-value">${customerPhone}</span>
        </div>` : ""}
      </div>

      <div class="receipt-divider"></div>

      <table class="receipt-items-table">
        <thead>
          <tr>
            <th>${t("saleDetails.product")}</th>
            <th class="receipt-col-center">${t("saleDetails.qty")}</th>
            <th class="receipt-col-right">${t("saleDetails.unitPrice")}</th>
            <th class="receipt-col-right">${t("saleDetails.discount")}</th>
            <th class="receipt-col-right">${t("saleDetails.subtotal")}</th>
          </tr>
        </thead>
        <tbody>
          ${itemsHtml || `<tr><td colspan="5" class="receipt-empty">${t("saleDetails.noItems")}</td></tr>`}
        </tbody>
      </table>

      <div class="receipt-divider"></div>

      <div class="receipt-summary">
        <div class="receipt-summary-row">
          <span>${t("saleDetails.totalItems") || "Total Items"}</span>
          <span>${totalProductTypes}</span>
        </div>
        <div class="receipt-summary-row">
          <span>${t("saleDetails.totalQuantity") || "Total Quantity"}</span>
          <span>${totalQuantity}</span>
        </div>
        ${headerDiscount > 0 ? `<div class="receipt-summary-row receipt-summary-discount">
          <span>${t("saleDetails.totalDiscount") || "Total Discount"}</span>
          <span>- ${money(headerDiscount)}</span>
        </div>` : ""}
        <div class="receipt-summary-row receipt-summary-total">
          <span>${t("saleDetails.totalPaid") || "Total Amount"}</span>
          <span>${money(sale.total_amount)}</span>
        </div>
      </div>

      <div class="receipt-divider"></div>

      <div class="receipt-payment-info">
        <div class="receipt-summary-row">
          <span>${t("saleDetails.paymentMethod") || "Payment Method"}</span>
          <span>${paymentLabel}</span>
        </div>
      </div>

      ${isOwner ? `
      <div class="receipt-divider"></div>
      <div class="receipt-profit-info">
        <div class="receipt-summary-row">
          <span>${t("saleDetails.profit") || "Profit"}</span>
          <span class="receipt-profit-value">${money(sale.total_profit)}</span>
        </div>
      </div>` : ""}

      <div class="receipt-divider-thick"></div>

      <div class="receipt-footer-area">
        ${receiptFooterText ? `<p class="receipt-footer-message">${escapeHtml(receiptFooterText)}</p>` : ""}
        <p class="receipt-thankyou">${t("saleDetails.thankYou") || "Thank you for your purchase!"}</p>
        <p class="receipt-powered">${t("saleDetails.poweredBy") || "Powered by Mpeli Outfit Store"}</p>
      </div>
    </div>

    <div class="sale-details-footer">
      <div class="receipt-actions">
        <button type="button" class="ghost-button receipt-action-btn" id="receiptPrintBtn" title="${t("saleDetails.print") || "Print Receipt"}">
          <i class="bi bi-printer"></i> ${t("saleDetails.print") || "Print"}
        </button>
        <button type="button" class="ghost-button receipt-action-btn" id="receiptDownloadBtn" title="${t("saleDetails.download") || "Download PDF"}">
          <i class="bi bi-file-earmark-pdf"></i> ${t("saleDetails.download") || "Download PDF"}
        </button>
      </div>
      <button type="button" class="ghost-button" id="saleDetailsCloseBtn">${t("common.close")}</button>
    </div>
  `;

  document.querySelector("#saleDetailsCloseBtn")?.addEventListener("click", closeSaleDetailsModal);
  document.querySelector("#saleDetailsClose")?.addEventListener("click", closeSaleDetailsModal);

  document.querySelector("#receiptPrintBtn")?.addEventListener("click", () => {
    printReceipt();
  });

  document.querySelector("#receiptDownloadBtn")?.addEventListener("click", () => {
    printReceipt();
  });
}

function printReceipt() {
  const printArea = document.querySelector("#receiptPrintArea");
  if (!printArea) return;

  const printWindow = window.open("", "_blank", "width=800,height=600");
  if (!printWindow) {
    showToast(t("saleDetails.printBlocked") || "Popup blocked. Please allow popups for printing.", "error");
    return;
  }

  printWindow.document.write("<!DOCTYPE html><html><head><title>Receipt</title>");
  printWindow.document.write("<style>");
  printWindow.document.write("*{margin:0;padding:0;box-sizing:border-box}");
  printWindow.document.write("body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#fff;color:#1a1a1a;-webkit-print-color-adjust:exact;print-color-adjust:exact}");
  printWindow.document.write(".receipt-print-area{max-width:400px;margin:0 auto;padding:20px 16px}");
  printWindow.document.write(".receipt-brand{text-align:center;margin-bottom:12px}");
  printWindow.document.write(".receipt-logo{max-width:80px;max-height:80px;margin-bottom:8px;display:block;margin-left:auto;margin-right:auto}");
  printWindow.document.write(".receipt-store-name{font-size:20px;font-weight:700;letter-spacing:0.5px;margin-bottom:4px}");
  printWindow.document.write(".receipt-contact{font-size:12px;color:#555;margin:2px 0;display:flex;align-items:center;justify-content:center;gap:4px}");
  printWindow.document.write(".receipt-contact i{font-size:11px}");
  printWindow.document.write(".receipt-divider{border-top:1px dashed #ccc;margin:10px 0}");
  printWindow.document.write(".receipt-divider-thick{border-top:2px solid #1a1a1a;margin:12px 0}");
  printWindow.document.write(".receipt-header-info{margin-bottom:8px}");
  printWindow.document.write(".receipt-meta-row{display:flex;justify-content:space-between;padding:3px 0;font-size:13px}");
  printWindow.document.write(".receipt-meta-label{color:#666}");
  printWindow.document.write(".receipt-meta-value{font-weight:600;text-align:right}");
  printWindow.document.write(".receipt-items-table{width:100%;border-collapse:collapse;font-size:13px;margin:8px 0}");
  printWindow.document.write(".receipt-items-table thead th{text-align:left;font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:0.3px;padding:6px 4px;border-bottom:1px solid #ddd}");
  printWindow.document.write(".receipt-items-table thead th.receipt-col-center{text-align:center}");
  printWindow.document.write(".receipt-items-table thead th.receipt-col-right{text-align:right}");
  printWindow.document.write(".receipt-items-table tbody td{padding:6px 4px;border-bottom:1px solid #eee;vertical-align:top}");
  printWindow.document.write(".receipt-items-table td.receipt-col-center{text-align:center}");
  printWindow.document.write(".receipt-items-table td.receipt-col-right{text-align:right}");
  printWindow.document.write(".receipt-items-table td.receipt-col-total{font-weight:600}");
  printWindow.document.write(".receipt-item-variant{color:#777;font-size:11px}");
  printWindow.document.write(".receipt-empty{text-align:center;color:#999;padding:16px 4px}");
  printWindow.document.write(".receipt-summary{margin:8px 0}");
  printWindow.document.write(".receipt-summary-row{display:flex;justify-content:space-between;padding:4px 0;font-size:13px;color:#555}");
  printWindow.document.write(".receipt-summary-row span:last-child{font-weight:500;color:#1a1a1a}");
  printWindow.document.write(".receipt-summary-discount span:last-child{color:#e74c3c}");
  printWindow.document.write(".receipt-summary-total{border-top:1px solid #ddd;margin-top:6px;padding-top:8px}");
  printWindow.document.write(".receipt-summary-total span{font-weight:700;font-size:16px !important;color:#1a1a1a !important}");
  printWindow.document.write(".receipt-payment-info{margin:8px 0;font-size:13px}");
  printWindow.document.write(".receipt-profit-info{margin:8px 0;font-size:13px}");
  printWindow.document.write(".receipt-profit-value{color:#27ae60;font-weight:600}");
  printWindow.document.write(".receipt-footer-area{text-align:center;margin-top:12px}");
  printWindow.document.write(".receipt-footer-message{font-size:12px;color:#666;margin-bottom:6px;font-style:italic}");
  printWindow.document.write(".receipt-thankyou{font-size:14px;font-weight:600;margin-bottom:4px}");
  printWindow.document.write(".receipt-powered{font-size:11px;color:#999}");
  printWindow.document.write("@media print{body{margin:0;padding:0}.receipt-print-area{max-width:100%;padding:10px 8px}}");
  printWindow.document.write("</style></head><body>");
  printWindow.document.write(printArea.innerHTML);
  printWindow.document.write("</body></html>");
  printWindow.document.close();

  printWindow.onload = function() {
    setTimeout(() => {
      printWindow.focus();
      printWindow.print();
    }, 300);
  };
}

async function loadReports(options = {}) {
  const payload = await apiRequest("api/reports.php", options);
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
        return `<div class="fin-row"><span>${escapeHtml(t("expenseCategory." + c.category.toLowerCase()) || c.category)}</span><strong>${money(c.total)}</strong></div>`;
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
  updateTopbarStoreName();
  updateReceiptFooter();
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
  updateTopbarStoreName();
  applyRoleUI();
  await refreshAppData();
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
    body.innerHTML = `<tr><td colspan="6" class="ecl-desktop">${t("expenses.noExpenses")}</td><td class="ecl-mobile-card ecl-empty">${t("expenses.noExpenses")}</td></tr>`;
    return;
  }
  body.innerHTML = payload.expenses.map(e => {
    const displayCategory = e.expense_name || e.category;
    const actionsHtml = isOwner()
      ? `<div class="ecl-actions">
          <button type="button" class="ghost-button" data-edit-expense="${e.id}">${t("users.edit")}</button>
          <button type="button" class="ghost-button" data-delete-expense="${e.id}" style="color:var(--danger)">${t("products.delete")}</button>
        </div>`
      : "";
    return `<tr>
      <td class="ecl-desktop">${escapeHtml(e.expense_date)}</td>
      <td class="ecl-desktop">${escapeHtml(displayCategory)}</td>
      <td class="ecl-desktop">${escapeHtml(e.description) || "-"}</td>
      <td class="ecl-desktop">${money(e.amount)}</td>
      <td class="ecl-desktop">${escapeHtml(e.created_by_name)}</td>
      <td class="ecl-desktop owner-only">
        <button type="button" class="ghost-button" data-edit-expense="${e.id}" style="padding:4px 8px;font-size:11px">${t("users.edit")}</button>
        <button type="button" class="ghost-button" data-delete-expense="${e.id}" style="padding:4px 8px;font-size:11px;color:var(--danger)">${t("products.delete")}</button>
      </td>
      <td class="ecl-mobile-card">
        <span class="ecl-label">${t("table.date")}</span><span class="ecl-value">${escapeHtml(e.expense_date)}</span>
        <span class="ecl-label">${t("table.category")}</span><span class="ecl-value">${escapeHtml(displayCategory)}</span>
        <span class="ecl-label">${t("expenses.descriptionLabel")}</span><span class="ecl-value">${escapeHtml(e.description) || "-"}</span>
        <span class="ecl-label">${t("table.amount")}</span><span class="ecl-amount">${money(e.amount)}</span>
        <span class="ecl-label">${t("users.name")}</span><span class="ecl-value">${escapeHtml(e.created_by_name)}</span>
        ${actionsHtml}
      </td>
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

// ─── Audit Log Management (OWNER only) ─────────────────────────────────────
let auditCurrentPage = 1;
let auditPerPage = 25;
let auditFilterOptions = { users: [], modules: [], actions: [], entity_types: [] };
// Guards the audit detail modal against rapid double-clicks firing multiple
// simultaneous requests, and lets a newer request supersede an older one.
let auditDetailPendingId = null;
let auditDetailRequestToken = null;

async function loadAuditLog() {
  if (!isOwner()) return;
  const params = new URLSearchParams();
  params.set("page", String(auditCurrentPage));
  params.set("per_page", String(auditPerPage));

  const search = document.querySelector("#auditSearch")?.value.trim() || "";
  if (search) params.set("search", search);
  const from = document.querySelector("#auditDateFrom")?.value || "";
  if (from) params.set("date_from", from);
  const to = document.querySelector("#auditDateTo")?.value || "";
  if (to) params.set("date_to", to);
  const user = document.querySelector("#auditUserFilter")?.value || "";
  if (user) params.set("user_id", user);
  const role = document.querySelector("#auditRoleFilter")?.value || "";
  if (role) params.set("role", role);
  const module = document.querySelector("#auditModuleFilter")?.value || "";
  if (module) params.set("module", module);
  const action = document.querySelector("#auditActionFilter")?.value || "";
  if (action) params.set("action", action);
  const entity = document.querySelector("#auditEntityFilter")?.value || "";
  if (entity) params.set("entity_type", entity);

  const body = document.querySelector("#auditBody");
  if (body) body.innerHTML = `<tr><td colspan="8">${t("audit.loading")}</td></tr>`;

  try {
    const payload = await apiRequest(`api/audit.php?${params.toString()}`);
    if (!payload.success) throw new Error(payload.message);

    auditFilterOptions = payload.filter_options || auditFilterOptions;
    populateAuditFilterOptions();
    renderAuditLogs(payload.logs || []);
    renderAuditPagination(payload);
  } catch (e) {
    if (body) body.innerHTML = `<tr><td colspan="8">${t("audit.error")}</td></tr>`;
  }
}

function populateAuditFilterOptions() {
  const userSel = document.querySelector("#auditUserFilter");
  if (userSel && auditFilterOptions.users && auditFilterOptions.users.length) {
    const current = userSel.value;
    userSel.innerHTML = `<option value="">${t("audit.allUsers")}</option>` +
      auditFilterOptions.users.map(u =>
        `<option value="${u.user_id}" ${String(u.user_id) === String(current) ? "selected" : ""}>${escapeHtml(u.user_name)}</option>`
      ).join("");
  }

  const modSel = document.querySelector("#auditModuleFilter");
  if (modSel && auditFilterOptions.modules && auditFilterOptions.modules.length) {
    const current = modSel.value;
    modSel.innerHTML = `<option value="">${t("audit.allModules")}</option>` +
      auditFilterOptions.modules.map(m =>
        `<option value="${escapeHtml(m)}" ${m === current ? "selected" : ""}>${escapeHtml(m)}</option>`
      ).join("");
  }

  const actSel = document.querySelector("#auditActionFilter");
  if (actSel && auditFilterOptions.actions && auditFilterOptions.actions.length) {
    const current = actSel.value;
    actSel.innerHTML = `<option value="">${t("audit.allActions")}</option>` +
      auditFilterOptions.actions.map(a =>
        `<option value="${escapeHtml(a)}" ${a === current ? "selected" : ""}>${escapeHtml(a)}</option>`
      ).join("");
  }

  const entSel = document.querySelector("#auditEntityFilter");
  if (entSel && auditFilterOptions.entity_types && auditFilterOptions.entity_types.length) {
    const current = entSel.value;
    entSel.innerHTML = `<option value="">${t("audit.allEntities")}</option>` +
      auditFilterOptions.entity_types.map(e =>
        `<option value="${escapeHtml(e)}" ${e === current ? "selected" : ""}>${escapeHtml(e)}</option>`
      ).join("");
  }
}

function formatAuditDate(datetime) {
  if (!datetime) return "";
  const d = new Date(datetime.replace(" ", "T"));
  if (isNaN(d.getTime())) return escapeHtml(datetime);
  const opts = { year: "numeric", month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit" };
  return d.toLocaleString(undefined, opts);
}

function renderAuditLogs(logs) {
  const body = document.querySelector("#auditBody");
  const count = document.querySelector("#auditResultCount");
  if (!body) return;

  if (count) count.textContent = logs.length ? `${logs.length} ${t("audit.records")}` : "";

  if (!logs.length) {
    body.innerHTML = `<tr><td colspan="8">${t("audit.noRecords")}</td></tr>`;
    return;
  }

  body.innerHTML = logs.map(log => `
    <tr data-audit-id="${log.id}">
      <td class="nowrap">${formatAuditDate(log.created_at)}</td>
      <td>${escapeHtml(log.user_name || "-")}</td>
      <td>${escapeHtml(log.user_role || "-")}</td>
      <td>${escapeHtml(log.action)}</td>
      <td>${escapeHtml(log.module)}</td>
      <td class="audit-desc-cell" title="${escapeHtml(log.description || "")}">${escapeHtml(truncateText(log.description || "-", 60))}</td>
      <td class="nowrap">${escapeHtml(log.ip_address || "-")}</td>
      <td class="nowrap"><button type="button" class="ghost-button" data-audit-view="${log.id}"><i class="bi bi-eye"></i> ${t("audit.viewDetails")}</button></td>
    </tr>
  `).join("");
}

function renderAuditPagination(data) {
  const el = document.querySelector("#auditPagination");
  if (!el) return;
  const { total_pages, page, total } = data;
  if (total === 0 || total_pages <= 1) {
    el.innerHTML = "";
    return;
  }
  let html = `<div class="pagination-info">${t("audit.pageOf", { page, total: total_pages })} (${total} ${t("audit.records")})</div><div class="pagination-controls">`;
  html += `<button type="button" class="ghost-button" data-audit-page="${page - 1}" ${page <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left"></i></button>`;
  html += `<span class="pagination-page">${page} / ${total_pages}</span>`;
  html += `<button type="button" class="ghost-button" data-audit-page="${page + 1}" ${page >= total_pages ? "disabled" : ""}><i class="bi bi-chevron-right"></i></button>`;
  html += `</div>`;
  el.innerHTML = html;
}

function truncateText(str, n) {
  return str.length > n ? str.slice(0, n - 3) + "..." : str;
}

function auditNotRecorded() {
  return escapeHtml(t("audit.notRecorded"));
}

function auditValue(value) {
  if (value === undefined || value === null || value === "") return `<span class="audit-na">${auditNotRecorded()}</span>`;
  return escapeHtml(String(value));
}

function formatAuditDateTime(datetime) {
  if (!datetime) return { date: "", time: "" };
  const d = new Date(datetime.replace(" ", "T"));
  if (isNaN(d.getTime())) return { date: escapeHtml(datetime), time: "" };
  return {
    date: d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "2-digit" }),
    time: d.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit", second: "2-digit" }),
  };
}

function openAuditDetail(log) {
  const body = document.querySelector("#auditDetailBody");
  const modal = document.querySelector("#auditDetailModal");
  if (!body || !modal) return;

  const dt = formatAuditDateTime(log.created_at);
  const uaInfo = parseUserAgent(log.user_agent);
  const hasEntity = log.entity_type || log.entity_id;
  const oldV = log.old_values && typeof log.old_values === "object" ? log.old_values : {};
  const newV = log.new_values && typeof log.new_values === "object" ? log.new_values : {};
  const hasChanges = Object.keys(oldV).length > 0 || Object.keys(newV).length > 0;

  const gridItem = (label, valueHtml) =>
    `<div class="audit-detail-item"><span class="audit-detail-label">${label}</span><span class="audit-detail-value">${valueHtml}</span></div>`;

  const entityRef = hasEntity && log.entity_reference
    ? escapeHtml(log.entity_reference)
    : auditNotRecorded();

  const descriptionHtml = log.description
    ? `<div class="audit-detail-desc"><span class="audit-detail-label">${t("audit.description")}</span><div class="audit-detail-value">${escapeHtml(log.description)}</div></div>`
    : "";

  const userAgentHtml = log.user_agent
    ? escapeHtml(log.user_agent)
    : auditNotRecorded();

  body.innerHTML = `
    <div class="audit-detail">
      <section class="audit-detail-section">
        <h4 class="audit-section-title">${t("audit.generalInfo")}</h4>
        <div class="audit-detail-grid">
          ${gridItem(t("audit.id"), auditValue(log.id))}
          ${gridItem(t("audit.date"), auditValue(dt.date))}
          ${gridItem(t("audit.exactTime"), auditValue(dt.time))}
          ${gridItem(t("audit.user"), auditValue(log.user_name))}
          ${gridItem(t("audit.userId"), auditValue(log.user_id))}
          ${gridItem(t("audit.role"), auditValue(log.user_role))}
          ${gridItem(t("audit.action"), auditValue(log.action))}
          ${gridItem(t("audit.module"), auditValue(log.module))}
        </div>
        ${descriptionHtml}
      </section>
      <section class="audit-detail-section">
        <h4 class="audit-section-title">${t("audit.affectedEntity")}</h4>
        <div class="audit-detail-grid">
          ${gridItem(t("audit.entityType"), hasEntity ? auditValue(log.entity_type) : auditNotRecorded())}
          ${gridItem(t("audit.entityId"), hasEntity ? auditValue(log.entity_id) : auditNotRecorded())}
          ${gridItem(t("audit.entityReference"), entityRef)}
        </div>
      </section>
      <section class="audit-detail-section">
        <h4 class="audit-section-title">${t("audit.changedFields")}</h4>
        ${hasChanges ? renderAuditChanges(oldV, newV) : `<div class="audit-no-changes">${escapeHtml(t("audit.noChanges"))}</div>`}
      </section>
      <section class="audit-detail-section">
        <h4 class="audit-section-title">${t("audit.deviceInfo")}</h4>
        <div class="audit-detail-grid">
          ${gridItem(t("audit.ip"), auditValue(log.ip_address))}
          ${gridItem(t("audit.browser"), auditValue(uaInfo.browser))}
          ${gridItem(t("audit.os"), auditValue(uaInfo.os))}
          ${gridItem(t("audit.deviceType"), auditValue(uaInfo.device))}
        </div>
        <div class="audit-detail-desc">
          <span class="audit-detail-label">${t("audit.userAgent")}</span>
          <div class="audit-detail-ua">${userAgentHtml}</div>
        </div>
      </section>
    </div>
  `;
  modal.classList.remove("hidden");
  document.body.classList.add("modal-open");
}

async function openAuditDetailFromServer(id) {
  const modal = document.querySelector("#auditDetailModal");
  const body = document.querySelector("#auditDetailBody");
  if (!modal || !body) return;

  // Rapid-click protection: only one detail request in flight at a time.
  if (auditDetailPendingId !== null) return;
  const token = generateRequestKey();
  auditDetailPendingId = String(id);
  auditDetailRequestToken = token;

  body.innerHTML = `<div class="audit-detail-state" role="status"><span class="audit-spinner" aria-hidden="true"></span><span>${escapeHtml(t("audit.loadingDetail"))}</span></div>`;
  modal.classList.remove("hidden");
  document.body.classList.add("modal-open");

  try {
    const params = new URLSearchParams({ id: String(id), detail: "1" });
    const payload = await apiRequest(`api/audit.php?${params.toString()}`);
    if (token !== auditDetailRequestToken) return; // superseded by a newer view
    if (!payload.success || !payload.log) throw new Error("missing log");
    openAuditDetail(payload.log);
  } catch (e) {
    if (token !== auditDetailRequestToken) return;
    body.innerHTML = `<div class="audit-detail-state audit-detail-state-error" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><span>${escapeHtml(t("audit.errorDetail"))}</span></div>`;
  } finally {
    if (token === auditDetailRequestToken) {
      auditDetailPendingId = null;
      auditDetailRequestToken = null;
    }
  }
}

function renderAuditChanges(oldV, newV) {
  const keys = new Set([...Object.keys(oldV || {}), ...Object.keys(newV || {})]);
  if (!keys.size) return `<div class="audit-no-changes">${escapeHtml(t("audit.noChanges"))}</div>`;
  const rows = [...keys].map(key => {
    const oldVal = oldV[key];
    const newVal = newV[key];
    const haveOld = oldVal !== undefined && oldVal !== null && oldVal !== "";
    const haveNew = newVal !== undefined && newVal !== null && newVal !== "";
    const oldHtml = haveOld ? formatChangeValue(oldVal) : `<span class="audit-empty">—</span>`;
    const newHtml = haveNew ? formatChangeValue(newVal) : `<span class="audit-empty">—</span>`;
    return `
      <tr>
        <td class="audit-change-key-cell">${escapeHtml(humanizeFieldName(key))}</td>
        <td class="audit-before">${oldHtml}</td>
        <td class="audit-after">${newHtml}</td>
      </tr>`;
  }).join("");
  return `
    <div class="audit-change-table-wrap">
      <table class="audit-change-table">
        <thead>
          <tr><th>${escapeHtml(t("audit.field"))}</th><th>${escapeHtml(t("audit.before"))}</th><th>${escapeHtml(t("audit.after"))}</th></tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

function formatChangeValue(v) {
  if (v === null || v === undefined) return `<span class="audit-empty">—</span>`;
  if (typeof v === "number") {
    const formatted = Math.abs(v) >= 1000 ? v.toLocaleString(undefined, { maximumFractionDigits: 2 }) : String(v);
    return `<span class="audit-val-num">${escapeHtml(formatted)}</span>`;
  }
  if (typeof v === "boolean") return escapeHtml(v ? "true" : "false");
  if (typeof v === "object") {
    try {
      return `<span class="audit-val-json"><pre>${escapeHtml(JSON.stringify(v, null, 2))}</pre></span>`;
    } catch (_) { return escapeHtml(String(v)); }
  }
  return escapeHtml(String(v));
}

function humanizeFieldName(key) {
  if (!key) return key;
  let out = String(key).replace(/_/g, " ");
  out = out.replace(/([a-z])([A-Z])/g, "$1 $2");
  return out.charAt(0).toUpperCase() + out.slice(1);
}

function parseUserAgent(ua) {
  const unknown = t("audit.unknown");
  if (!ua) return { browser: unknown, os: unknown, device: unknown };
  let browser = unknown;
  let os = unknown;
  let device = "Desktop";
  const u = String(ua);
  if (/Edg\//.test(u)) browser = "Edge";
  else if (/OPR\/|Opera/.test(u)) browser = "Opera";
  else if (/Chrome\//.test(u)) browser = "Chrome";
  else if (/Firefox\//.test(u)) browser = "Firefox";
  else if (/Safari\//.test(u)) browser = "Safari";
  else if (/MSIE|Trident/.test(u)) browser = "Internet Explorer";

  if (/Windows/.test(u)) os = "Windows";
  else if (/Android/.test(u)) os = "Android";
  else if (/iPhone|iPad|iPod/.test(u)) os = "iOS";
  else if (/Mac OS/.test(u)) os = "macOS";
  else if (/Linux/.test(u)) os = "Linux";

  if (/iPad|Tablet|PlayBook|Silk/.test(u)) device = "Tablet";
  else if (/Mobi|Android|iPhone|iPod|Opera Mini|BlackBerry|IEMobile/.test(u)) device = "Mobile";

  return { browser, os, device };
}

function bindAuditEvents() {
  const applyBtn = document.querySelector("#auditApplyFilters");
  if (applyBtn) applyBtn.addEventListener("click", () => { auditCurrentPage = 1; loadAuditLog(); });

  const resetBtn = document.querySelector("#auditResetFilters");
  if (resetBtn) resetBtn.addEventListener("click", () => {
    auditCurrentPage = 1;
    ["#auditSearch", "#auditDateFrom", "#auditDateTo", "#auditUserFilter", "#auditRoleFilter", "#auditModuleFilter", "#auditActionFilter", "#auditEntityFilter"].forEach(sel => {
      const el = document.querySelector(sel);
      if (el) el.value = "";
    });
    loadAuditLog();
  });

  ["#auditDateFrom", "#auditDateTo"].forEach(sel => {
    const el = document.querySelector(sel);
    if (el) el.addEventListener("change", () => { auditCurrentPage = 1; loadAuditLog(); });
  });
  ["#auditUserFilter", "#auditRoleFilter", "#auditModuleFilter", "#auditActionFilter", "#auditEntityFilter"].forEach(sel => {
    const el = document.querySelector(sel);
    if (el) el.addEventListener("change", () => { auditCurrentPage = 1; loadAuditLog(); });
  });

  const refreshBtn = document.querySelector("#auditRefreshBtn");
  if (refreshBtn) refreshBtn.addEventListener("click", () => loadAuditLog());

  const searchInput = document.querySelector("#auditSearch");
  if (searchInput) {
    let debounce;
    searchInput.addEventListener("input", () => {
      clearTimeout(debounce);
      debounce = setTimeout(() => { auditCurrentPage = 1; loadAuditLog(); }, 500);
    });
  }

  // Event delegation: view details button (document-level, bulletproof).
  // Every "View" click always fetches the chosen record fresh from the secure
  // server-side detail endpoint — nothing is shown from a client-side cache.
  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-audit-view]");
    if (!btn || btn.disabled) return;
    openAuditDetailFromServer(String(btn.dataset.auditView));
  });

  document.querySelector("#auditPagination")?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-audit-page]");
    if (btn && !btn.disabled) {
      const pg = parseInt(btn.dataset.auditPage, 10);
      if (pg >= 1) {
        auditCurrentPage = pg;
        loadAuditLog();
      }
    }
  });

  const closeBtn = document.querySelector("#auditDetailClose");
  const closeBtn2 = document.querySelector("#auditDetailCloseBtn");
  if (closeBtn) closeBtn.addEventListener("click", closeAuditDetail);
  if (closeBtn2) closeBtn2.addEventListener("click", closeAuditDetail);

  // Close when clicking the dark overlay area.
  const auditModal = document.querySelector("#auditDetailModal");
  if (auditModal) {
    auditModal.addEventListener("click", (e) => {
      if (e.target === auditModal) closeAuditDetail();
    });
  }

  // Close on Escape.
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    const modal = document.querySelector("#auditDetailModal");
    if (modal && !modal.classList.contains("hidden")) closeAuditDetail();
  });
}

function closeAuditDetail() {
  // Abort any in-flight detail request state so a stale response can't reopen UI.
  auditDetailPendingId = null;
  auditDetailRequestToken = null;
  const modal = document.querySelector("#auditDetailModal");
  if (modal) {
    modal.classList.add("hidden");
    document.body.classList.remove("modal-open");
  }
}

// ─── Backup Management (OWNER only) ────────────────────────────────────────
let backupCache = new Map();
let backupBusy = false;

async function loadBackupDashboard() {
  if (!isOwner()) return;
  const body = document.querySelector("#backupHistoryBody");
  if (body) body.innerHTML = `<tr><td colspan="7">${t("backup.loading")}</td></tr>`;

  try {
    const payload = await apiRequest("api/backup.php");
    if (!payload.success) throw new Error(payload.message);

    backupCache.clear();
    (payload.backups || []).forEach(b => backupCache.set(b.filename, b));

    renderBackupStatus(payload.status || {});
    renderBackupHistory(payload.backups || []);
    renderBackupRetention(payload.retention || {});
  } catch (e) {
    if (body) body.innerHTML = `<tr><td colspan="7">${t("backup.error")}</td></tr>`;
  }
}

function renderBackupStatus(status) {
  setBackupStat("backupLastDb", "backupLastDbMeta", status.last_database);
  setBackupStat("backupLastFiles", "backupLastFilesMeta", status.last_files);
  setBackupStat("backupLastFull", "backupLastFullMeta", status.last_full);

  const count = document.querySelector("#backupCount");
  if (count) count.textContent = String(status.count || 0);

  const size = document.querySelector("#backupTotalSize");
  if (size) size.textContent = status.total_size_human || "0 B total";

  const sizeDetail = document.querySelector("#backupTotalSizeDetail");
  if (sizeDetail) sizeDetail.textContent = status.total_size_human || "0 B";

  const countDb = document.querySelector("#backupCountDb");
  if (countDb) countDb.textContent = String(status.count_database ?? 0);

  const countFiles = document.querySelector("#backupCountFiles");
  if (countFiles) countFiles.textContent = String(status.count_files ?? 0);

  const countFull = document.querySelector("#backupCountFull");
  if (countFull) countFull.textContent = String(status.count_full ?? 0);

  const opStatus = document.querySelector("#backupOperationStatus");
  if (opStatus) {
    opStatus.textContent = status.lock_active ? t("backup.opRunning") : t("backup.opIdle");
    opStatus.style.color = status.lock_active ? "var(--danger)" : "var(--success)";
  }

  const storage = document.querySelector("#backupStorageInfo");
  if (storage) {
    const loc = status.storage_outside_webroot
      ? t("backup.storageOutside")
      : t("backup.storageFallback");
    storage.textContent = `${t("backup.location")}: ${loc}`;
  }

  const storageLoc = document.querySelector("#backupStorageLocationId");
  if (storageLoc) {
    storageLoc.textContent = status.storage_outside_webroot
      ? t("backup.storageOutside")
      : t("backup.storageFallback");
  }
}

function setBackupStat(id, metaId, backup) {
  const el = document.querySelector("#" + id);
  const meta = document.querySelector("#" + metaId);
  if (el) {
    el.textContent = backup ? formatBackupDate(backup.created_at) : t("backup.never");
  }
  if (meta) {
    meta.textContent = backup
      ? `${backup.size_human} · ${t("backup.status." + backup.status)}`
      : t("backup.never");
  }
}

function formatBackupDate(datetime) {
  if (!datetime) return "—";
  const d = new Date(datetime.replace(" ", "T"));
  if (isNaN(d.getTime())) return datetime;
  return d.toLocaleString(undefined, { year: "numeric", month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit" });
}

function renderBackupHistory(backups) {
  const body = document.querySelector("#backupHistoryBody");
  const count = document.querySelector("#backupHistoryCount");
  if (!body) return;
  if (count) count.textContent = backups.length ? `${backups.length} ${t("audit.records")}` : "";

  if (!backups.length) {
    body.innerHTML = `<tr><td colspan="7">${t("backup.noBackups")}</td></tr>`;
    return;
  }

  body.innerHTML = backups.map(b => `
    <tr data-backup-name="${escapeHtml(b.filename)}">
      <td class="backup-filename-cell" title="${escapeHtml(b.filename)}">${escapeHtml(b.filename)}</td>
      <td><span class="backup-type-badge type-${escapeHtml(b.type)}">${t("backup.type." + b.type)}</span></td>
      <td class="nowrap">${formatBackupDate(b.created_at)}</td>
      <td class="nowrap">${escapeHtml(b.size_human || "0 B")}</td>
      <td><span class="backup-status-badge status-${escapeHtml(b.status)}">${t("backup.status." + b.status)}</span></td>
      <td>${t("backup.system")}</td>
      <td class="nowrap backup-actions-cell">
        <button type="button" class="ghost-button backup-view" data-backup-view="${escapeHtml(b.filename)}"><i class="bi bi-eye"></i></button>
        <button type="button" class="ghost-button backup-download" data-backup-download="${escapeHtml(b.filename)}"><i class="bi bi-download"></i></button>
        ${b.type === "database" ? `<button type="button" class="ghost-button backup-restore" data-backup-restore="${escapeHtml(b.filename)}"><i class="bi bi-arrow-counterclockwise"></i></button>` : ""}
        <button type="button" class="ghost-button backup-delete" data-backup-delete="${escapeHtml(b.filename)}"><i class="bi bi-trash"></i></button>
      </td>
    </tr>
  `).join("");
}

function renderBackupRetention(retention) {
  const daily = document.querySelector("#retentionDaily");
  const weekly = document.querySelector("#retentionWeekly");
  const monthly = document.querySelector("#retentionMonthly");
  const full = document.querySelector("#retentionFull");
  if (daily) daily.value = retention.keep_daily ?? 7;
  if (weekly) weekly.value = retention.keep_weekly ?? 4;
  if (monthly) monthly.value = retention.keep_monthly ?? 12;
  if (full) full.value = retention.keep_full ?? 3;
}

async function createBackup(type) {
  if (backupBusy) return;
  if (!isOwner()) return;

  const validTypes = ["database", "files", "full"];
  if (!validTypes.includes(type)) return;

  backupBusy = true;

  const dbBtn = document.querySelector("#createDbBackupBtn");
  const filesBtn = document.querySelector("#createFilesBackupBtn");
  const fullBtn = document.querySelector("#createFullBackupBtn");
  const btns = [dbBtn, filesBtn, fullBtn].filter(Boolean);
  btns.forEach(b => { b.disabled = true; b.classList.add("btn-loading"); });

  const createBtn = type === "database" ? dbBtn : type === "files" ? filesBtn : fullBtn;
  if (createBtn) createBtn.disabled = true;

  try {
    const payload = await apiRequest("api/backup.php", {
      method: "POST",
      body: JSON.stringify({ action: "create", type }),
      headers: { "Content-Type": "application/json" },
    });
    if (payload.backup?.success) {
      showToast(t("backup.createdSuccess"), "success");
    } else {
      showToast(payload.backup?.message || t("backup.createdFailed"), "error");
    }
    await loadBackupDashboard();
  } catch (e) {
    showToast(e.message || t("backup.createdFailed"), "error");
  } finally {
    backupBusy = false;
    btns.forEach(b => { b.disabled = false; b.classList.remove("btn-loading"); });
  }
}

async function downloadBackup(filename) {
  if (!isOwner()) return;
  // Stream the file via fetch and trigger browser download.
  try {
    const response = await fetch("api/backup.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": csrfToken,
      },
      body: JSON.stringify({ action: "download", filename }),
      credentials: "same-origin",
    });

    const contentType = response.headers.get("Content-Type") || "";
    if (!contentType.includes("application/octet-stream")) {
      let payload;
      try { payload = await response.json(); } catch (_) { payload = null; }
      showToast(payload?.message || t("backup.downloadFailed"), "error");
      return;
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast(t("backup.downloaded"), "success");
  } catch (e) {
    showToast(e.message || t("backup.downloadFailed"), "error");
  }
}

async function deleteBackup(filename) {
  if (!isOwner()) return;
  if (!confirm(t("backup.confirmDelete", { file: filename }))) return;
  try {
    const payload = await apiRequest("api/backup.php", {
      method: "POST",
      body: JSON.stringify({ action: "delete", filename }),
    });
    showToast(payload.message || t("backup.deleted"), payload.success ? "success" : "error");
    await loadBackupDashboard();
  } catch (e) {
    showToast(e.message, "error");
  }
}

function openBackupDetail(filename) {
  const backup = backupCache.get(filename);
  const modal = document.querySelector("#backupDetailModal");
  const body = document.querySelector("#backupDetailBody");
  if (!modal || !body || !backup) return;

  body.innerHTML = `
    <div class="audit-detail-grid">
      <div class="audit-detail-item"><span class="audit-detail-label">${t("backup.detailName")}</span><span class="audit-detail-value backup-detail-name">${escapeHtml(backup.filename)}</span></div>
      <div class="audit-detail-item"><span class="audit-detail-label">${t("backup.thType")}</span><span class="audit-detail-value">${t("backup.type." + backup.type)}</span></div>
      <div class="audit-detail-item"><span class="audit-detail-label">${t("backup.thDate")}</span><span class="audit-detail-value">${formatBackupDate(backup.created_at)}</span></div>
      <div class="audit-detail-item"><span class="audit-detail-label">${t("backup.thSize")}</span><span class="audit-detail-value">${escapeHtml(backup.size_human || "0 B")}</span></div>
      <div class="audit-detail-item"><span class="audit-detail-label">${t("backup.thStatus")}</span><span class="audit-detail-value">${t("backup.status." + backup.status)}</span></div>
      <div class="audit-detail-item"><span class="audit-detail-label">${t("backup.thCreator")}</span><span class="audit-detail-value">${t("backup.system")}</span></div>
    </div>
    <div class="backup-detail-note">${t("backup.detailNote")}</div>
  `;

  backupDetailCurrentFile = filename;
  modal.classList.remove("hidden");
  document.body.classList.add("modal-open");
}

let backupDetailCurrentFile = null;

function closeBackupDetail() {
  const modal = document.querySelector("#backupDetailModal");
  if (modal) {
    modal.classList.add("hidden");
    document.body.classList.remove("modal-open");
  }
  backupDetailCurrentFile = null;
}

async function validateBackup(filename) {
  if (!isOwner()) return;
  try {
    const payload = await apiRequest("api/backup.php", {
      method: "POST",
      body: JSON.stringify({ action: "validate", filename }),
    });
    const v = payload.validation || {};
    showToast(v.valid ? `${t("backup.valid")} (${v.size_human})` : `${t("backup.invalid")}: ${v.detail}`, v.valid ? "success" : "error");
  } catch (e) {
    showToast(e.message, "error");
  }
}

async function saveRetention() {
  if (!isOwner()) return;
  const settings = {
    keep_daily: parseInt(document.querySelector("#retentionDaily")?.value || "7", 10) || 0,
    keep_weekly: parseInt(document.querySelector("#retentionWeekly")?.value || "4", 10) || 0,
    keep_monthly: parseInt(document.querySelector("#retentionMonthly")?.value || "12", 10) || 0,
    keep_full: parseInt(document.querySelector("#retentionFull")?.value || "3", 10) || 0,
  };
  try {
    const payload = await apiRequest("api/backup.php", {
      method: "POST",
      body: JSON.stringify({ action: "retention", settings }),
    });
    showToast(payload.message || t("backup.retentionSaved"), payload.success ? "success" : "error");
    if (payload.success) renderBackupRetention(payload.settings || settings);
  } catch (e) {
    showToast(e.message, "error");
  }
}

async function runCleanup() {
  if (!isOwner()) return;
  if (!confirm(t("backup.confirmCleanup"))) return;
  try {
    const payload = await apiRequest("api/backup.php", {
      method: "POST",
      body: JSON.stringify({ action: "cleanup" }),
    });
    const deleted = payload.result?.deleted?.length || 0;
    showToast(deleted ? `${t("backup.cleanupDone")}: ${deleted}` : t("backup.cleanupNothing"), deleted ? "success" : "warning");
    await loadBackupDashboard();
  } catch (e) {
    showToast(e.message, "error");
  }
}

let restoreFilename = null;

function openRestoreModal(filename) {
  const backup = backupCache.get(filename);
  const modal = document.querySelector("#restoreModal");
  const details = document.querySelector("#restoreDetails");
  if (!modal || !backup) return;

  restoreFilename = filename;

  details.innerHTML = `
    <div class="restore-detail-row"><span>${t("backup.currentDb")}</span><strong>Mpeli Outfit Store Production</strong></div>
    <div class="restore-detail-row"><span>${t("backup.restoring")}</span><strong>${escapeHtml(filename)}</strong></div>
    <div class="restore-detail-row"><span>${t("backup.thSize")}</span><strong>${escapeHtml(backup.size_human || "0 B")}</strong></div>
  `;

  document.querySelector("#restoreConfirmCheck1").checked = false;
  document.querySelector("#restoreStep2Btn").disabled = true;
  document.querySelector("#restoreMessage").textContent = "";

  modal.classList.remove("hidden");
  document.body.classList.add("modal-open");
}

function closeRestoreModal() {
  const modal = document.querySelector("#restoreModal");
  if (modal) {
    modal.classList.add("hidden");
    document.body.classList.remove("modal-open");
  }
  restoreFilename = null;
}

async function confirmRestore() {
  const btn = document.querySelector("#restoreStep2Btn");
  if (!restoreFilename || !btn || btn.disabled || backupBusy) return;

  const check1 = document.querySelector("#restoreConfirmCheck1");
  if (!check1.checked) {
    document.querySelector("#restoreMessage").textContent = t("backup.requireConfirm");
    return;
  }

  if (restoreFilename && !isOwner()) return;

  backupBusy = true;
  btn.disabled = true;
  btn.classList.add("btn-loading");

  const msgEl = document.querySelector("#restoreMessage");
  if (msgEl) msgEl.textContent = t("backup.restoringNow");

  try {
    const payload = await apiRequest("api/backup.php", {
      method: "POST",
      body: JSON.stringify({ action: "restore", filename: restoreFilename, confirmed: true }),
    });
    if (payload.success) {
      msgEl.textContent = payload.message || t("backup.restoreSuccess");
      showToast(t("backup.restoreSuccess"), "success");
      setTimeout(() => {
        closeRestoreModal();
        loadBackupDashboard();
      }, 1500);
    } else {
      msgEl.textContent = payload.message || t("backup.restoreFailed");
      showToast(t("backup.restoreFailed"), "error");
    }
  } catch (e) {
    if (msgEl) msgEl.textContent = e.message;
    showToast(e.message || t("backup.restoreFailed"), "error");
  } finally {
    backupBusy = false;
    btn.classList.remove("btn-loading");
  }
}

function bindBackupEvents() {
  document.querySelector("#backupRefreshBtn")?.addEventListener("click", loadBackupDashboard);

  document.querySelector("#createDbBackupBtn")?.addEventListener("click", () => createBackup("database"));
  document.querySelector("#createFilesBackupBtn")?.addEventListener("click", () => createBackup("files"));
  document.querySelector("#createFullBackupBtn")?.addEventListener("click", () => createBackup("full"));

  document.querySelector("#saveRetentionBtn")?.addEventListener("click", saveRetention);
  document.querySelector("#runCleanupBtn")?.addEventListener("click", runCleanup);

  const closeDetail = document.querySelector("#backupDetailClose");
  const closeDetail2 = document.querySelector("#backupDetailCloseBtn");
  if (closeDetail) closeDetail.addEventListener("click", closeBackupDetail);
  if (closeDetail2) closeDetail2.addEventListener("click", closeBackupDetail);

  const validateBtn = document.querySelector("#backupDetailValidateBtn");
  if (validateBtn) validateBtn.addEventListener("click", () => {
    if (backupDetailCurrentFile) validateBackup(backupDetailCurrentFile);
  });

  document.querySelector("#restoreClose")?.addEventListener("click", closeRestoreModal);
  document.querySelector("#restoreCancel")?.addEventListener("click", closeRestoreModal);
  document.querySelector("#restoreConfirmCheck1")?.addEventListener("change", (e) => {
    const btn = document.querySelector("#restoreStep2Btn");
    if (btn) btn.disabled = !e.target.checked;
  });
  document.querySelector("#restoreStep2Btn")?.addEventListener("click", confirmRestore);

  // Event delegation for backup actions
  document.addEventListener("click", (e) => {
    const viewBtn = e.target.closest("[data-backup-view]");
    if (viewBtn) {
      const name = viewBtn.dataset.backupView;
      if (backupCache.has(name)) {
        openBackupDetail(name);
      } else {
        showToast(t("backup.notFound"), "error");
      }
      return;
    }

    const dlBtn = e.target.closest("[data-backup-download]");
    if (dlBtn) {
      downloadBackup(dlBtn.dataset.backupDownload);
      return;
    }

    const delBtn = e.target.closest("[data-backup-delete]");
    if (delBtn) {
      deleteBackup(delBtn.dataset.backupDelete);
      return;
    }

    const restBtn = e.target.closest("[data-backup-restore]");
    if (restBtn) {
      openRestoreModal(restBtn.dataset.backupRestore);
    }
  });
}

async function refreshAppData() {
  const tasks = [loadProducts(), loadPromotions(), loadDashboard(), loadExpenses(), loadReports()];
  if (isOwner()) {
    tasks.push(loadSettings(), loadUsers(), loadInventory(), loadMaintenanceStatus(), loadPromotionsOwner());
  }
  const results = await Promise.allSettled(tasks);
  let errors = 0;
  results.forEach((r, i) => {
    if (r.status === "rejected") {
      errors++;
    }
  });
  if (errors > 0) {
  }
}

async function refreshFinancialData(options = {}) {
  const tasks = [loadDashboard(options), loadReports(options)];
  await Promise.allSettled(tasks);
}

async function completePayment() {
  const items = [...cart.entries()]
    .filter(([, quantity]) => quantity > 0)
    .map(([id, quantity]) => {
      const product = products.find(p => p.id === id);
      if (!product) return null;
      const item = {
        variant_id: product.variant_id,
        quantity,
        pricing_type: getPricingType(id)
      };
      if (item.pricing_type === "existing_discount") {
        item.final_selling_price = getFinalPrice(id);
        item.original_selling_price = product.selling;
      } else if (item.pricing_type === "promotion") {
        item.promotion_id = getPromotionIdFor(id);
      }
      return item;
    })
    .filter(Boolean);

  if (items.length === 0) {
    document.querySelector("#receiptNote").textContent = t("sales.addBeforeCheckout");
    return;
  }

  const btn = document.querySelector("#completePaymentButton");
  const originalLabel = btn ? btn.textContent : "";
  if (btn) {
    btn.disabled = true;
    btn.textContent = t("common.processing");
  }

  try {
    if (!saleRequestKey) saleRequestKey = generateRequestKey();
    const payload = await apiRequest("api/sales.php", {
      method: "POST",
      body: JSON.stringify({
        payment_method: document.querySelector("#paymentMethod").value,
        items,
        bulk_discount_percent: isBulkDiscountActive() ? bulkDiscountPercent : null,
        request_id: saleRequestKey
      })
    });

    saleRequestKey = null;
    clearCartState();
    document.querySelector("#receiptNote").textContent = t("sales.paymentSaved", { receipt: payload.receipt_number });
    showToast(t("sales.paymentSaved", { receipt: payload.receipt_number }));
    await refreshAppData();
  } catch (error) {
    throw error;
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = originalLabel;
    }
  }
}

document.querySelector("#loginForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const username = document.querySelector("#loginUsername").value.trim();
  const password = document.querySelector("#loginPassword").value;
  const btn = document.querySelector("#loginForm button[type='submit']");
  if (btn.classList.contains("btn-loading")) return;
  btn.classList.add("btn-loading");
  try {
    const payload = await apiRequest("api/login.php", {
      method: "POST",
      body: JSON.stringify({ username, password })
    });
    if (payload.csrf_token) storeCsrfToken(payload.csrf_token);
    currentUser = payload.user;
    document.querySelector("#loginForm").reset();
    showApp();
    showToast(t("login.welcome") + ", " + currentUser.name + "!");
    await refreshAppData();
    startDashboardAutoRefresh();
  } catch (error) {
    showToast(error.message, "error");
  } finally {
    btn.classList.remove("btn-loading");
  }
});

// Change password modal (authenticated users)
document.querySelector("#changePasswordButton")?.addEventListener("click", () => {
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
  const currentPassword = document.querySelector("#resetCurrentPassword").value;
  const password = document.querySelector("#resetNewPassword").value;
  const confirm = document.querySelector("#resetConfirmPassword").value;

  if (password !== confirm) {
    showToast(t("auth.newPasswordsDontMatch"), "error");
    return;
  }

  if (password === currentPassword) {
    showToast(t("auth.samePassword"), "error");
    return;
  }

  try {
    await apiRequest("api/reset_password.php", {
      method: "POST",
      body: JSON.stringify({ current_password: currentPassword, new_password: password })
    });
    showToast(t("auth.passwordChanged"));
    closeResetPasswordModal();
  } catch (error) {
    showToast(error.message, "error");
  }
});

let recoveryToken = null;

function openRecoveryModal() {
  const modal = document.querySelector("#recoveryModal");
  if (!modal) return;
  modal.classList.remove("hidden");
  document.querySelector("#recoveryStep1")?.classList.remove("hidden");
  document.querySelector("#recoveryStep2")?.classList.add("hidden");
  document.querySelector("#recoverySuccess")?.classList.add("hidden");
  document.querySelector("#recoveryVerifyForm")?.reset();
  document.querySelector("#recoveryResetForm")?.reset();
  const r1 = document.querySelector("#recoveryStep1Result");
  const r2 = document.querySelector("#recoveryStep2Result");
  if (r1) r1.textContent = "";
  if (r2) r2.textContent = "";
  recoveryToken = null;
  document.querySelector("#recoveryUsername")?.focus();
}

function closeRecoveryModal() {
  document.querySelector("#recoveryModal")?.classList.add("hidden");
  recoveryToken = null;
}

document.querySelector("#recoveryLink")?.addEventListener("click", e => {
  e.preventDefault();
  openRecoveryModal();
});
document.querySelector("#recoveryClose")?.addEventListener("click", closeRecoveryModal);
document.querySelector("#recoveryCancel")?.addEventListener("click", closeRecoveryModal);
document.querySelector("#recoveryBackToVerify")?.addEventListener("click", () => {
  document.querySelector("#recoveryStep1")?.classList.remove("hidden");
  document.querySelector("#recoveryStep2")?.classList.add("hidden");
  const r2 = document.querySelector("#recoveryStep2Result");
  if (r2) r2.textContent = "";
});
document.querySelector("#recoveryModal")?.addEventListener("click", e => {
  if (e.target === e.currentTarget) closeRecoveryModal();
});

// Step 1: Verify Identity
document.querySelector("#recoveryVerifyForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const username = document.querySelector("#recoveryUsername")?.value?.trim();
  const email = document.querySelector("#recoveryEmail")?.value?.trim();
  const result = document.querySelector("#recoveryStep1Result");
  if (!username || !email || !result) return;

  try {
    const response = await fetch("api/recover_owner.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "verify", username, email }),
    });
    const data = await response.json();
    if (data.success) {
      result.style.color = "var(--success)";
      result.textContent = data.message || t("recovery.identityVerified");
      recoveryToken = data.token || null;
      setTimeout(() => {
        document.querySelector("#recoveryStep1")?.classList.add("hidden");
        document.querySelector("#recoveryStep2")?.classList.remove("hidden");
        document.querySelector("#recoveryNewPassword")?.focus();
      }, 600);
    } else {
      result.style.color = "var(--danger)";
      result.textContent = data.message || t("recovery.accountNotFound");
    }
  } catch (error) {
    result.style.color = "var(--danger)";
    result.textContent = t("recovery.failed");
  }
});

// Step 2: Reset Password
document.querySelector("#recoveryResetForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const newPassword = document.querySelector("#recoveryNewPassword")?.value;
  const confirmPassword = document.querySelector("#recoveryConfirmPassword")?.value;
  const result = document.querySelector("#recoveryStep2Result");
  if (!newPassword || !confirmPassword || !result) return;

  if (newPassword !== confirmPassword) {
    result.style.color = "var(--danger)";
    result.textContent = t("recovery.passwordsDontMatch");
    return;
  }

  if (newPassword.length < 8) {
    result.style.color = "var(--danger)";
    result.textContent = t("recovery.passwordTooShort");
    return;
  }

  if (!/[A-Za-z]/.test(newPassword) || !/[0-9]/.test(newPassword)) {
    result.style.color = "var(--danger)";
    result.textContent = t("recovery.passwordRequirements");
    return;
  }

  try {
    const response = await fetch("api/recover_owner.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "reset", token: recoveryToken, new_password: newPassword, confirm_password: confirmPassword }),
    });
    const data = await response.json();
    if (data.success) {
      document.querySelector("#recoveryStep2")?.classList.add("hidden");
      document.querySelector("#recoverySuccess")?.classList.remove("hidden");
      recoveryToken = null;
    } else {
      result.style.color = "var(--danger)";
      result.textContent = data.message || t("recovery.failed");
    }
  } catch (error) {
    result.style.color = "var(--danger)";
    result.textContent = t("recovery.failed");
  }
});

// Back to Login from success screen
document.querySelector("#recoveryBackToLogin")?.addEventListener("click", () => {
  closeRecoveryModal();
  document.querySelector("#loginUsername")?.focus();
});

// Password toggle functionality
document.querySelectorAll(".password-toggle").forEach(btn => {
  btn.addEventListener("click", () => {
    const input = btn.parentElement.querySelector("input");
    if (!input) return;
    const isPassword = input.type === "password";
    input.type = isPassword ? "text" : "password";
    const icon = btn.querySelector("i");
    if (icon) {
      icon.className = isPassword ? "bi bi-eye-slash" : "bi bi-eye";
    }
    btn.setAttribute("aria-label", isPassword ? "Hide password" : "Show password");
  });
});

document.querySelector("#ownerSetupForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const btn = document.querySelector("#ownerSetupForm button[type='submit']");
  if (btn.classList.contains("btn-loading")) return;
  btn.classList.add("btn-loading");
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
      const loginPayload = await apiRequest("api/login.php", {
        method: "POST",
        body: JSON.stringify({
          username: username,
          password: password
        })
      });
      if (loginPayload.csrf_token) storeCsrfToken(loginPayload.csrf_token);
      
      // Refresh auth state
      const payload = await apiRequest("api/me.php");
      if (payload.authenticated) {
        currentUser = payload.user;
        document.querySelector("#ownerSetupForm").reset();
        showApp();
        await refreshAppData();
        startDashboardAutoRefresh();
      } else {
        showLogin(true);
      }
    } catch (loginError) {
      showLogin(true);
      document.querySelector("#loginUsername").value = username;
    }
  } catch (error) {
    showToast(error.message, "error");
  } finally {
    btn.classList.remove("btn-loading");
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
    closeSidebar();
    // Load page-specific data immediately
    const page = button.dataset.page;
    rememberPage(page);
    // Dispose Analytics chart roots when leaving the Analytics page so hidden
    // charts don't keep animating / leak memory.
    if (page !== "analytics" && window.MpeliCharts) {
      ["biSalesTrendChart", "biProfitTrendChart", "biSellerRankingChart", "biProductRankingChart", "biSellerTrendChart", "biProductTrendChart"].forEach(id => {
        window.MpeliCharts.disposeRoot(document.querySelector("#" + id));
      });
    }
    try {
      if (page === "dashboard") await loadDashboard();
      else if (page === "products") { await loadProducts(); await loadPromotions(); }
      else if (page === "sales") { await loadProducts(); await loadPromotions(); }
      else if (page === "promotions" && isOwner()) await loadPromotionsOwner();
      else if (page === "expenses") await loadExpenses();
      else if (page === "inventory" && isOwner()) await loadInventory();
      else if (page === "reports") await loadReports();
      else if (page === "analytics") await initBI();
      else if (page === "users" && isOwner()) await loadUsers();
      else if (page === "audit" && isOwner()) await loadAuditLog();
      else if (page === "backup" && isOwner()) await loadBackupDashboard();
      else if (page === "settings" && isOwner()) await loadSettings();
    } catch (e) {
    }
  });
});

document.querySelector("#menuButton")?.addEventListener("click", () => {
  toggleSidebar();
});

document.querySelector("#sidebarOverlay")?.addEventListener("click", () => {
  closeSidebar();
});

document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") closeSidebar();
  if ((e.key === "Enter" || e.key === " ") && e.target?.id === "sidebarBrand") {
    e.preventDefault();
    e.target.click();
  }
});

function toggleSidebar() {
  const sidebar = document.querySelector(".sidebar");
  const overlay = document.querySelector("#sidebarOverlay");
  const isOpen = sidebar.classList.contains("open");
  if (isOpen) {
    closeSidebar();
  } else {
    sidebar.classList.add("open");
    overlay.classList.add("active");
    document.body.classList.add("sidebar-open");
  }
}

function closeSidebar() {
  const sidebar = document.querySelector(".sidebar");
  const overlay = document.querySelector("#sidebarOverlay");
  sidebar.classList.remove("open");
  overlay.classList.remove("active");
  document.body.classList.remove("sidebar-open");
}

// Sidebar brand / logo click → navigate to dashboard
let _sidebarBrandBusy = false;
document.querySelector("#sidebarBrand")?.addEventListener("click", async () => {
  if (_sidebarBrandBusy) return;
  _sidebarBrandBusy = true;
  try {
    document.querySelectorAll(".nav-item").forEach(i => i.classList.remove("active"));
    document.querySelectorAll(".page").forEach(p => p.classList.remove("active"));
    const dashBtn = document.querySelector('.nav-item[data-page="dashboard"]');
    if (dashBtn) dashBtn.classList.add("active");
    const dashPage = document.querySelector("#dashboard");
    if (dashPage) dashPage.classList.add("active");
    closeSidebar();
    rememberPage("dashboard");
    await loadDashboard();
  } catch (_) { /* silent */ } finally {
    _sidebarBrandBusy = false;
  }
});

// Auto-close sidebar when viewport crosses the 900px breakpoint
let _lastViewportWidth = window.innerWidth;
window.addEventListener("resize", () => {
  const w = window.innerWidth;
  if ((_lastViewportWidth > 900 && w <= 900) || (_lastViewportWidth <= 900 && w > 900)) {
    closeSidebar();
  }
  _lastViewportWidth = w;
}, { passive: true });

// Prevent background scroll when a modal is open
const _bodyScrollLockObserver = new MutationObserver(() => {
  const anyModalOpen = document.querySelectorAll(".modal-overlay:not(.hidden), .dialog:not(.hidden)").length > 0;
  document.body.style.overflow = anyModalOpen ? "hidden" : "";
});
_bodyScrollLockObserver.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ["class"] });

document.querySelector("#logoutButton")?.addEventListener("click", async () => {
  try {
    await apiRequest("api/logout.php", { method: "POST" });
  } catch (e) {
  }
  // Tell other open tabs the session has ended.
  idleStorageSet(SESSION_LOGGED_OUT_KEY, String(Date.now()));
  currentUser = null;
  products = [];
  clearCartState();
  forgetLastPage();
  clearCsrfToken();
  if (window.MpeliCharts) window.MpeliCharts.disposeAll();
  document.querySelector("#loginForm")?.reset();
  document.querySelector("#ownerSetupForm")?.reset();
  document.querySelectorAll("#loginScreen input").forEach(input => { input.value = ""; });
  showLogin(true);
  history.pushState({ page: "login" }, "", location.href);
});

document.querySelectorAll(".language-switcher").forEach(select => {
  select.addEventListener("change", event => setLanguage(event.target.value));
});

document.querySelector("#themeToggle")?.addEventListener("click", toggleTheme);

// Back-button protection: redirect to login if user is not authenticated
window.addEventListener("popstate", (e) => {
  if (!currentUser) {
    history.replaceState({ page: "login" }, "", location.href);
    showLogin(true);
  }
}, { passive: true });

function performSearch(query) {
  const q = query.trim().toLowerCase();
  if (!q) { loadProducts(); return; }
  const filtered = products.filter(p => p.name.toLowerCase().includes(q));
  const grid = document.querySelector("#productGrid");
  if (!grid) return;
  grid.innerHTML = filtered.length
    ? filtered.map(productCardHtml).join("")
    : `<p class="empty-state">${t("products.noProducts")}</p>`;
  observeProductImages(grid);
  const posContainer = document.querySelector("#posProducts");
  if (posContainer) {
    posContainer.innerHTML = filtered.length
      ? filtered.map(p => {
          const qty = cart.get(p.id) || 0;
          const atMax = qty >= p.stock;
          return `<article class="pos-item ${qty > 0 ? "active" : ""}">
            ${posItemThumbHtml(p)}
            <div>
              <strong>${escapeHtml(p.name)}</strong>
              <small>${money(p.selling)}</small>
            </div>
            <div class="qty-controls">
              <button type="button" class="ghost-button" data-dec="${p.id}">-</button>
              <span>${qty}</span>
              <button type="button" class="ghost-button" data-inc="${p.id}" ${atMax ? "disabled" : ""}>+</button>
            </div>
          </article>`;
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
  const match = products.find(p => (p.name || p.product_name || "").toLowerCase() === name.toLowerCase());
  if (match) {
    const proceed = confirm(`Product "${name}" already exists with stock ${match.stock_quantity ?? 0}. Click OK to update stock, or Cancel to abort.`);
    if (!proceed) return;
  }

  const submitBtn = form.querySelector('button[type="submit"]');
  const originalBtnHTML = submitBtn ? submitBtn.innerHTML : "";
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + (t("common.processing") || "Saving...");
  }

  try {
    const formData = new FormData();
    formData.append("name", name);
    formData.append("buying_price", document.querySelector("#productBuyingInput").value);
    formData.append("selling_price", document.querySelector("#productSellingInput").value);
    formData.append("minimum_allowed_selling_price", document.querySelector("#productMinPriceInput").value);
    formData.append("stock_quantity", stockInput);
    formData.append("idempotency_key", crypto.randomUUID());
    const imageInput = document.querySelector("#productImageInput");
    if (imageInput && imageInput.files && imageInput.files[0]) {
      formData.append("product_image", imageInput.files[0]);
    }

    const result = await apiRequest("api/products.php", {
      method: "POST",
      body: formData
    });
    form.reset();
    clearProductImagePreview();
    showToast(result.updated ? t("products.updatedStock") || "Stock updated successfully." : t("products.added"));
    if (result.image_error) {
      showToast(result.image_error, "error");
    }
    await refreshAppData();
  } catch (error) {
    showToast(error.message, "error");
  } finally {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalBtnHTML;
    }
  }
});

function clearProductImagePreview() {
  const input = document.querySelector("#productImageInput");
  const wrap = document.querySelector("#productImagePreviewWrap");
  const preview = document.querySelector("#productImagePreview");
  if (input) input.value = "";
  if (wrap) wrap.classList.add("hidden");
  if (preview) preview.removeAttribute("src");
}

document.querySelector("#productImageInput")?.addEventListener("change", () => {
  const input = document.querySelector("#productImageInput");
  const wrap = document.querySelector("#productImagePreviewWrap");
  const preview = document.querySelector("#productImagePreview");
  const file = input.files && input.files[0];
  if (!file) {
    clearProductImagePreview();
    return;
  }
  if (!["image/jpeg", "image/png"].includes(file.type)) {
    showToast(t("products.imageUnsupported"), "error");
    clearProductImagePreview();
    return;
  }
  if (file.size > 2 * 1024 * 1024) {
    showToast(t("products.imageTooLarge"), "error");
    clearProductImagePreview();
    return;
  }
  if (preview && wrap) {
    preview.src = URL.createObjectURL(file);
    wrap.classList.remove("hidden");
  }
});

document.querySelector("#productImageClear")?.addEventListener("click", clearProductImagePreview);

let editingProductId = null;

function openEditProductModal(product) {
  editingProductId = Number(product.id);
  document.querySelector("#editNameInput").value = product.name || "";
  document.querySelector("#editBuyingInput").value = product.buying ?? "";
  document.querySelector("#editSellingInput").value = product.selling ?? "";
  document.querySelector("#editMinPriceInput").value = product.min_price ?? "";
  document.querySelector("#editStockInput").value = product.stock ?? "";
  const errorEl = document.querySelector("#editFormError");
  if (errorEl) errorEl.style.display = "none";

  const img = document.querySelector("#editCurrentImage");
  const placeholder = document.querySelector("#editImagePlaceholder");
  const imageUrl = productImageUrl(product);
  if (img) {
    if (imageUrl) {
      img.src = imageUrl;
      img.classList.remove("hidden");
      if (placeholder) placeholder.classList.add("hidden");
    } else {
      img.classList.add("hidden");
      img.removeAttribute("src");
      if (placeholder) placeholder.classList.remove("hidden");
    }
  }

  const fileInput = document.querySelector("#editImageInput");
  const removeCheck = document.querySelector("#editRemoveImage");
  if (fileInput) fileInput.value = "";
  if (removeCheck) removeCheck.checked = false;

  document.querySelector("#editProductModal")?.classList.remove("hidden");
}

function closeEditProductModal() {
  document.querySelector("#editProductModal")?.classList.add("hidden");
  editingProductId = null;
}

document.querySelector("#editProductCancelBtn")?.addEventListener("click", closeEditProductModal);
document.querySelector("#editProductModal")?.addEventListener("click", (e) => {
  if (e.target === e.currentTarget) closeEditProductModal();
});

document.querySelector("#editImageInput")?.addEventListener("change", () => {
  const fileInput = document.querySelector("#editImageInput");
  const file = fileInput.files && fileInput.files[0];
  if (!file) return;
  if (!["image/jpeg", "image/png"].includes(file.type)) {
    showToast(t("products.imageUnsupported"), "error");
    fileInput.value = "";
    return;
  }
  if (file.size > 2 * 1024 * 1024) {
    showToast(t("products.imageTooLarge"), "error");
    fileInput.value = "";
    return;
  }
  const img = document.querySelector("#editCurrentImage");
  if (img) {
    img.src = URL.createObjectURL(file);
    img.classList.remove("hidden");
    const placeholder = document.querySelector("#editImagePlaceholder");
    if (placeholder) placeholder.classList.add("hidden");
  }
  const removeCheck = document.querySelector("#editRemoveImage");
  if (removeCheck) removeCheck.checked = false;
});

document.querySelector("#editProductSave")?.addEventListener("click", async () => {
  if (!editingProductId) return;
  const btn = document.querySelector("#editProductSave");
  const originalBtnHTML = btn ? btn.innerHTML : "";
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + (t("common.processing") || "Saving...");
  }

  try {
    await apiRequest("api/products.php", {
      method: "PUT",
      body: JSON.stringify({
        id: editingProductId,
        name: document.querySelector("#editNameInput").value.trim(),
        buying_price: document.querySelector("#editBuyingInput").value,
        selling_price: document.querySelector("#editSellingInput").value,
        minimum_allowed_selling_price: document.querySelector("#editMinPriceInput").value,
        stock_quantity: document.querySelector("#editStockInput").value,
        idempotency_key: crypto.randomUUID()
      })
    });

    const fileInput = document.querySelector("#editImageInput");
    const removeCheck = document.querySelector("#editRemoveImage");
    const hasNewFile = fileInput && fileInput.files && fileInput.files[0];

    if (hasNewFile) {
      const fd = new FormData();
      fd.append("product_id", editingProductId);
      fd.append("product_image", fileInput.files[0]);
      await apiRequest("api/product_image.php", { method: "POST", body: fd });
      showToast(t("products.imageUpdated"));
    } else if (removeCheck && removeCheck.checked) {
      await apiRequest("api/product_image.php", {
        method: "POST",
        body: JSON.stringify({ product_id: editingProductId, remove_image: true })
      });
      showToast(t("products.imageRemoved"));
    }

    showToast(t("products.updated"));
    closeEditProductModal();
    await refreshAppData();
  } catch (error) {
    showToast(error.message, "error");
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = originalBtnHTML;
    }
  }
});

document.querySelector("#productGrid")?.addEventListener("click", async event => {
  const editId = event.target.dataset.editProduct;
  if (editId) {
    const product = products.find(item => String(item.id) === String(editId));
    if (product) openEditProductModal(product);
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
    const id = Number(inc);
    const product = products.find(p => p.id === id);
    if (!product) return;
    const nextQty = (cart.get(id) || 0) + 1;
    if (nextQty <= product.stock) cart.set(id, nextQty);
  }
  if (dec !== undefined) {
    const id = Number(dec);
    const nextQty = Math.max((cart.get(id) || 0) - 1, 0);
    if (nextQty === 0) cart.delete(id);
    else cart.set(id, nextQty);
  }
  saveCartState();
  renderCart();
});

document.querySelector("#cartList")?.addEventListener("click", event => {
  const discount = event.target.dataset.discount;
  if (discount !== undefined) {
    const id = Number(discount);
    const product = products.find(p => p.id === id);
    if (!product) return;
    const currentFp = getFinalPrice(id);
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
      discountPrices.delete(id);
    } else {
      discountPrices.set(id, fp);
    }
    saveCartState();
    renderCart();
  }
});

document.querySelector("#bulkDiscountToggle")?.addEventListener("change", event => {
  bulkDiscountEnabled = event.target.checked;
  if (bulkDiscountEnabled) {
    const v = parseFloat(document.querySelector("#bulkDiscountPercent")?.value);
    if (!isNaN(v) && v > 0 && v <= MAX_BULK_PERCENT) bulkDiscountPercent = v;
  }
  renderCart();
});

document.querySelector("#bulkDiscountPercent")?.addEventListener("input", event => {
  const v = parseFloat(event.target.value);
  if (!isNaN(v) && v > 0 && v <= MAX_BULK_PERCENT) {
    bulkDiscountPercent = v;
  } else {
    event.target.value = bulkDiscountPercent;
  }
  renderCart();
});

// ── Owner promotions management ──────────────────────────────────────────────
let editingPromotionId = null;

async function loadPromotionsOwner() {
  const payload = await apiRequest("api/promotions.php");
  ownerPromotions = payload.promotions || [];
  renderPromotionsList();
}

function promoEffectiveLabel(state) {
  const keys = {
    active: "promotions.stateActive",
    scheduled: "promotions.stateScheduled",
    expired: "promotions.stateExpired",
    draft: "promotions.stateDraft",
    inactive: "promotions.stateInactive"
  };
  return t(keys[state] || state);
}

function promoScopeText(promo) {
  if (Number(promo.all_products) === 1) return t("promotions.allProducts");
  const names = promo.product_names || [];
  if (!names.length) return "—";
  if (names.length <= 3) return names.join(", ");
  return names.slice(0, 3).join(", ") + ` +${names.length - 3}`;
}

function renderPromotionsList() {
  const listEl = document.querySelector("#promotionsList");
  if (!listEl) return;
  if (!ownerPromotions.length) {
    listEl.innerHTML = `<p class="empty-state">${t("promotions.empty")}</p>`;
    return;
  }
  listEl.innerHTML = ownerPromotions.map(promo => {
    const state = promo.effective_state || "draft";
    const statusBtn = state === "active"
      ? `<button type="button" class="ghost-button" data-promo-deactivate="${promo.id}">${t("promotions.deactivate")}</button>`
      : `<button type="button" class="gold-button" data-promo-activate="${promo.id}" ${state === "expired" ? "disabled" : ""}>${t("promotions.activate")}</button>`;
    const actions = `${statusBtn}
      <button type="button" class="ghost-button" data-promo-edit="${promo.id}" ${state === "active" ? "disabled" : ""}>${t("common.edit")}</button>
      <button type="button" class="ghost-button danger" data-promo-delete="${promo.id}">${t("common.delete")}</button>`;
    const dateRange = `${promo.start_date}${promo.start_time ? " " + promo.start_time : ""} → ${promo.end_date}${promo.end_time ? " " + promo.end_time : ""}`;
    return `<article class="promo-card">
      <div class="promo-card-head">
        <strong>${escapeHtml(promo.name)}</strong>
        <span class="promo-state ${state}">${promoEffectiveLabel(state)}</span>
      </div>
      ${promo.description ? `<p class="promo-desc">${escapeHtml(promo.description)}</p>` : ""}
      <div class="promo-meta">
        <span><i class="bi bi-tag-fill"></i> ${promo.percentage}%</span>
        <span><i class="bi bi-calendar-range"></i> ${escapeHtml(dateRange)}</span>
        <span><i class="bi bi-box-seam"></i> ${escapeHtml(promoScopeText(promo))}</span>
      </div>
      <div class="promo-actions">${actions}</div>
    </article>`;
  }).join("");
}

function openPromotionModal(promo) {
  editingPromotionId = promo ? Number(promo.id) : null;
  document.querySelector("#promoNameInput").value = promo ? promo.name : "";
  document.querySelector("#promoDescriptionInput").value = promo ? (promo.description || "") : "";
  document.querySelector("#promoPercentageInput").value = promo ? promo.percentage : "";
  document.querySelector("#promoStartDate").value = promo ? promo.start_date : new Date().toISOString().slice(0, 10);
  document.querySelector("#promoStartTime").value = promo ? (promo.start_time || "") : "";
  document.querySelector("#promoEndDate").value = promo ? promo.end_date : "";
  document.querySelector("#promoEndTime").value = promo ? (promo.end_time || "") : "";
  const allProducts = promo ? Number(promo.all_products) === 1 : false;
  document.querySelector("#promoAllProducts").checked = allProducts;
  document.querySelector("#promoProductsField").classList.toggle("hidden", allProducts);
  document.querySelector("#promotionModalTitle").textContent = promo ? t("promotions.edit") : t("promotions.addNew");
  loadPromotionProductOptions(promo ? (promo.product_ids || []) : []);
  document.querySelector("#promotionModal").classList.remove("hidden");
}

async function loadPromotionProductOptions(selectedIds) {
  const picker = document.querySelector("#promoProductPicker");
  if (!picker) return;
  picker.innerHTML = `<p class="bulk-hint">${t("common.loading")}</p>`;
  try {
    const payload = await apiRequest("api/products.php");
    const opts = payload.products || [];
    picker.innerHTML = opts.map(p => {
      const checked = selectedIds.includes(Number(p.id)) ? "checked" : "";
      return `<label class="promo-product-option"><input type="checkbox" value="${p.id}" ${checked}/> ${escapeHtml(p.name)}</label>`;
    }).join("") || `<p class="bulk-hint">${t("products.noProducts")}</p>`;
  } catch (e) {
    picker.innerHTML = `<p class="bulk-hint">${t("promotions.productLoadError")}</p>`;
  }
}

function closePromotionModal() {
  document.querySelector("#promotionModal")?.classList.add("hidden");
  editingPromotionId = null;
}

async function savePromotion() {
  const name = document.querySelector("#promoNameInput").value.trim();
  const percentage = parseFloat(document.querySelector("#promoPercentageInput").value);
  const startDate = document.querySelector("#promoStartDate").value;
  const endDate = document.querySelector("#promoEndDate").value;
  if (!name) { showToast(t("promotions.nameRequired"), "error"); return; }
  if (isNaN(percentage) || percentage <= 0 || percentage > 100) { showToast(t("promotions.percentageInvalid"), "error"); return; }
  if (!startDate || !endDate) { showToast(t("promotions.datesRequired"), "error"); return; }
  if (endDate < startDate) { showToast(t("promotions.dateOrder"), "error"); return; }
  const allProducts = document.querySelector("#promoAllProducts").checked;
  const productIds = allProducts
    ? []
    : [...document.querySelectorAll("#promoProductPicker input[type=checkbox]:checked")].map(cb => Number(cb.value));
  if (!allProducts && productIds.length === 0) { showToast(t("promotions.productsRequired"), "error"); return; }
  const body = {
    name,
    description: document.querySelector("#promoDescriptionInput").value.trim(),
    percentage,
    start_date: startDate,
    start_time: document.querySelector("#promoStartTime").value || "",
    end_date: endDate,
    end_time: document.querySelector("#promoEndTime").value || "",
    all_products: allProducts,
    product_ids: productIds
  };
  try {
    if (editingPromotionId) {
      await apiRequest("api/promotions.php", { method: "PUT", body: JSON.stringify({ id: editingPromotionId, ...body }) });
      showToast(t("promotions.updated"));
    } else {
      await apiRequest("api/promotions.php", { method: "POST", body: JSON.stringify(body) });
      showToast(t("promotions.created"));
    }
    closePromotionModal();
    await loadPromotionsOwner();
    await loadPromotions();
  } catch (e) {
    showToast(e.message, "error");
  }
}

document.querySelector("#newPromotionButton")?.addEventListener("click", () => openPromotionModal(null));
document.querySelector("#promotionCancelBtn")?.addEventListener("click", closePromotionModal);
document.querySelector("#promotionSaveBtn")?.addEventListener("click", savePromotion);
document.querySelector("#promotionModal")?.addEventListener("click", event => {
  if (event.target.id === "promotionModal") closePromotionModal();
});
document.querySelector("#promoAllProducts")?.addEventListener("change", event => {
  const field = document.querySelector("#promoProductsField");
  if (field) field.classList.toggle("hidden", event.target.checked);
});
document.querySelector("#promotionsList")?.addEventListener("click", async event => {
  const activateId = event.target.dataset.promoActivate;
  const deactivateId = event.target.dataset.promoDeactivate;
  const editId = event.target.dataset.promoEdit;
  const deleteId = event.target.dataset.promoDelete;
  try {
    if (activateId !== undefined) {
      await apiRequest("api/promotions.php", { method: "PUT", body: JSON.stringify({ id: activateId, action: "set_status", status: "active" }) });
      showToast(t("promotions.activated"));
      await loadPromotionsOwner();
      await loadPromotions();
    } else if (deactivateId !== undefined) {
      await apiRequest("api/promotions.php", { method: "PUT", body: JSON.stringify({ id: deactivateId, action: "set_status", status: "inactive" }) });
      showToast(t("promotions.deactivated"));
      await loadPromotionsOwner();
      await loadPromotions();
    } else if (editId !== undefined) {
      const promo = ownerPromotions.find(p => Number(p.id) === Number(editId));
      if (promo) openPromotionModal(promo);
    } else if (deleteId !== undefined) {
      if (!confirm(t("promotions.confirmDelete"))) return;
      await apiRequest("api/promotions.php", { method: "DELETE", body: JSON.stringify({ id: deleteId }) });
      showToast(t("promotions.deleted"));
      await loadPromotionsOwner();
      await loadPromotions();
    }
  } catch (e) {
    showToast(e.message, "error");
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
  [...cart.entries()].filter(([, qty]) => qty > 0).forEach(([id, qty]) => {
    const p = products.find(prod => prod.id === id);
    if (!p) return;
    const fp = getFinalPrice(id);
    const pType = getPricingType(id);
    const lineTotal = fp * qty;
    total += lineTotal;
    if (pType === "promotion") {
      const promo = getPromoForProduct(id);
      items.push(`${p.name} x${qty}  ${money(p.selling)} → ${money(fp)} (${t("promotions.title")} -${promo ? promo.percentage : ""}%)`);
    } else if (pType === "bulk_discount") {
      items.push(`${p.name} x${qty}  ${money(p.selling)} → ${money(fp)} (${t("sales.bulkLabel")} -${bulkDiscountPercent}%)`);
    } else if (pType === "existing_discount") {
      items.push(`${p.name} x${qty}  ${money(p.selling)} → ${money(fp)} (${t("sales.discountLabel")})`);
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

  if (expenseDate > new Date().toISOString().slice(0, 10)) {
    if (errorEl) { errorEl.textContent = t("expenses.futureDate"); errorEl.style.display = "block"; }
    return;
  }

  const btn = document.querySelector("#saveExpenseButton");
  const originalLabel = btn ? btn.textContent : "";
  if (btn) {
    btn.disabled = true;
    btn.textContent = t("common.processing");
  }

  try {
    if (!expenseRequestKey) expenseRequestKey = generateRequestKey();
    await apiRequest("api/expenses.php", {
      method: "POST",
      body: JSON.stringify({
        category,
        expense_name: expenseName,
        description,
        amount,
        expense_date: expenseDate,
        request_id: expenseRequestKey
      })
    });
    expenseRequestKey = null;
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
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = originalLabel;
    }
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

async function loadMaintenanceStatus() {
  if (!isOwner()) return;
  try {
    const payload = await apiRequest("api/maintenance.php");
    const toggle = document.querySelector("#maintenanceModeToggle");
    const msgInput = document.querySelector("#maintenanceMessageInput");
    if (toggle && payload.maintenance) {
      toggle.checked = payload.maintenance.active === true;
      if (msgInput && payload.maintenance.message) {
        msgInput.value = payload.maintenance.message;
      }
    }
  } catch (e) {
  }
}

document.querySelector("#saveMaintenanceButton")?.addEventListener("click", async () => {
  const toggle = document.querySelector("#maintenanceModeToggle");
  const msgInput = document.querySelector("#maintenanceMessageInput");
  if (!toggle) return;
  try {
    const payload = await apiRequest("api/maintenance.php", {
      method: "POST",
      body: JSON.stringify({
        enable: toggle.checked,
        message: msgInput?.value || "System is under maintenance.",
      }),
    });
    showToast(t("settings.maintenanceSaved"));
  } catch (error) {
    showToast(error.message, "error");
  }
});

// ── Report Generation Wizard ────────────────────────────────────────────
const REPORT_CATEGORIES = {
  OWNER: ["sales", "revenue", "expenses", "profit", "purchases", "inventory", "stock_movement", "seller_performance", "customers", "transactions", "products"],
  SELLER: ["sales", "revenue", "expenses", "transactions"],
};
const REPORT_CATEGORY_LABEL = {
  sales: "wizard.category.sales",
  revenue: "wizard.category.revenue",
  expenses: "wizard.category.expenses",
  profit: "wizard.category.profit",
  purchases: "wizard.category.purchases",
  inventory: "wizard.category.inventory",
  stock_movement: "wizard.category.stockMovement",
  seller_performance: "wizard.category.sellerPerformance",
  customers: "wizard.category.customers",
  transactions: "wizard.category.transactions",
  products: "wizard.category.products",
};

function showReportWizard() {
  const modal = document.querySelector("#reportWizardModal");
  if (!modal) return;
  modal.classList.remove("hidden");
  const period = document.querySelector("#wizardPeriod");
  if (period) period.value = "today";
  const customDates = document.querySelector("#wizardCustomDates");
  if (customDates) customDates.classList.add("hidden");
  const start = document.querySelector("#wizardStartDate");
  const end = document.querySelector("#wizardEndDate");
  if (start) start.value = "";
  if (end) end.value = "";
  const hint = document.querySelector("#wizardPeriodHint");
  if (hint) hint.textContent = "";
  const general = document.querySelector('input[name="wizardType"][value="general"]');
  if (general) general.checked = true;
  const cats = document.querySelector("#wizardCategories");
  if (cats) cats.classList.add("hidden");
  const pdf = document.querySelector('input[name="wizardFormat"][value="pdf"]');
  if (pdf) pdf.checked = true;
  renderWizardCategories();
  goToWizardStep(1);
  refreshWizardSelections();
}

function closeReportWizard() {
  document.querySelector("#reportWizardModal")?.classList.add("hidden");
}

function goToWizardStep(step) {
  [1, 2, 3].forEach(n => {
    document.querySelector(`#wizardStep${n}`)?.classList.toggle("hidden", n !== step);
  });
  document.querySelectorAll(".wizard-step").forEach(el => {
    el.classList.toggle("active", Number(el.dataset.step) === step);
  });
}

function renderWizardCategories() {
  const box = document.querySelector("#wizardCategories");
  if (!box) return;
  const role = isOwner() ? "OWNER" : "SELLER";
  const cats = REPORT_CATEGORIES[role] || [];
  box.innerHTML = cats.map(c => `
    <label class="wizard-cat"><input type="checkbox" value="${c}" /> ${escapeHtml(t(REPORT_CATEGORY_LABEL[c] || c))}</label>
  `).join("");
  refreshWizardSelections();
}

function refreshWizardSelections() {
  document.querySelectorAll(".wizard-radio").forEach(label => {
    label.classList.toggle("selected", !!label.querySelector("input:checked"));
  });
  document.querySelectorAll(".wizard-cat").forEach(label => {
    label.classList.toggle("selected", !!label.querySelector("input:checked"));
  });
}

function collectWizardOptions() {
  const period = document.querySelector("#wizardPeriod")?.value || "today";
  const options = { period };
  if (period === "custom") {
    options.start_date = document.querySelector("#wizardStartDate")?.value || "";
    options.end_date = document.querySelector("#wizardEndDate")?.value || "";
  }
  const type = document.querySelector('input[name="wizardType"]:checked')?.value || "general";
  options.type = type;
  if (type === "custom") {
    options.categories = [...document.querySelectorAll("#wizardCategories input:checked")].map(cb => cb.value);
  }
  options.format = document.querySelector('input[name="wizardFormat"]:checked')?.value || "pdf";
  return options;
}

function safeFilename(name) {
  return String(name).replace(/[^A-Za-z0-9 _\-]/g, "").replace(/\s+/g, " ").trim() || "Report";
}

async function generateAndDownloadReport() {
  const options = collectWizardOptions();
  if (options.period === "custom" && (!options.start_date || !options.end_date)) {
    showToast(t("wizard.customDatesRequired"), "error");
    goToWizardStep(1);
    return;
  }
  if (options.type === "custom" && (!options.categories || options.categories.length === 0)) {
    showToast(t("wizard.noCategories"), "error");
    goToWizardStep(2);
    return;
  }
  const btn = document.querySelector("#wizardGenerate");
  if (btn) {
    btn.disabled = true;
    btn.classList.add("btn-loading");
  }
  try {
    const response = await fetch("api/generate_report.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": csrfToken || "",
      },
      credentials: "same-origin",
      cache: "no-store",
      body: JSON.stringify(options),
    });
    if (!response.ok) {
      let message = t("wizard.failed");
      try {
        const err = await response.json();
        if (err?.message) message = err.message;
      } catch (_) { /* ignore */ }
      throw new Error(message);
    }
    const blob = await response.blob();
    const ext = options.format === "pdf" ? "pdf" : "xlsx";
    const title = options.type === "general"
      ? t("wizard.generalTitle")
      : options.categories.map(c => t(REPORT_CATEGORY_LABEL[c] || c)).join(", ");
    const filename = `MpeliOutFitStore Report - ${safeFilename(title)}.${ext}`;
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 4000);
    closeReportWizard();
    showToast(t("wizard.downloaded"));
  } catch (e) {
    showToast(e.message || t("wizard.failed"), "error");
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.classList.remove("btn-loading");
    }
  }
}

document.querySelector("#generateReportButton")?.addEventListener("click", showReportWizard);
document.querySelector("#generateReportReportsButton")?.addEventListener("click", showReportWizard);
document.querySelector("#wizardClose")?.addEventListener("click", closeReportWizard);
document.querySelector("#wizardCancel")?.addEventListener("click", closeReportWizard);
document.querySelector("#wizardPeriod")?.addEventListener("change", () => {
  const isCustom = document.querySelector("#wizardPeriod")?.value === "custom";
  document.querySelector("#wizardCustomDates")?.classList.toggle("hidden", !isCustom);
  const hint = document.querySelector("#wizardPeriodHint");
  if (hint) hint.textContent = isCustom ? t("wizard.customDatesHint") : "";
});
document.querySelector("#wizardNext1")?.addEventListener("click", () => goToWizardStep(2));
document.querySelector("#wizardBack2")?.addEventListener("click", () => goToWizardStep(1));
document.querySelectorAll('input[name="wizardType"]').forEach(input => {
  input.addEventListener("change", () => {
    const isCustom = document.querySelector('input[name="wizardType"]:checked')?.value === "custom";
    document.querySelector("#wizardCategories")?.classList.toggle("hidden", !isCustom);
    refreshWizardSelections();
  });
});
document.querySelector("#wizardCategories")?.addEventListener("change", refreshWizardSelections);
document.querySelector("#wizardNext2")?.addEventListener("click", () => goToWizardStep(3));
document.querySelector("#wizardBack3")?.addEventListener("click", () => goToWizardStep(2));
document.querySelector("#wizardGenerate")?.addEventListener("click", generateAndDownloadReport);

// ── Live Clock + Date + Online Status + Dropdown ───────────
let clockInterval = null;

function updateClock() {
  const now = new Date();
  const timeEl = document.querySelector("#clockTime");
  const dateEl = document.querySelector("#clockDate");
  if (timeEl) {
    timeEl.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }
  if (dateEl) {
    dateEl.textContent = now.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
  }
}

function startClock() {
  if (clockInterval) return;
  updateClock();
  clockInterval = setInterval(updateClock, 1000);
}

function stopClock() {
  if (clockInterval) { clearInterval(clockInterval); clockInterval = null; }
}

function updateOnlineStatus() {
  const dot = document.querySelector("#statusDot");
  const text = document.querySelector("#statusText");
  if (!dot || !text) return;
  if (navigator.onLine) {
    dot.classList.remove("offline");
    text.textContent = t("status.online") || "Online";
  } else {
    dot.classList.add("offline");
    text.textContent = t("status.offline") || "Offline";
  }
}

window.addEventListener("online", updateOnlineStatus);
window.addEventListener("offline", updateOnlineStatus);

function updateTopbarStoreName() {
  const el = document.querySelector("#topbarStoreName");
  if (el && shopNameGlobal) el.textContent = shopNameGlobal;
}

function updateTopbarPageTitle(pageName) {
  const el = document.querySelector("#topbarPageTitle");
  if (!el) return;
  const titleMap = {
    dashboard: "nav.dashboard",
    products: "nav.products",
    sales: "nav.sales",
    expenses: "nav.expenses",
    reports: "nav.reports",
    analytics: "nav.analytics",
    inventory: "nav.inventory",
    promotions: "nav.promotions",
    users: "nav.users",
    settings: "nav.settings"
  };
  const key = titleMap[pageName];
  if (key) {
    const translated = t(key);
    el.textContent = translated !== key ? translated : pageName.charAt(0).toUpperCase() + pageName.slice(1);
  } else {
    el.textContent = pageName;
  }
}

// Dropdown toggle
function setupUserDropdown() {
  const trigger = document.querySelector("#topbarUser");
  const dropdown = document.querySelector("#userDropdown");
  if (!trigger || !dropdown) return;
  if (trigger.dataset.dropdownReady === "1") return;
  trigger.dataset.dropdownReady = "1";

  function openDropdown() {
    dropdown.classList.remove("hidden");
    trigger.classList.add("open");
  }

  function closeDropdown() {
    dropdown.classList.add("hidden");
    trigger.classList.remove("open");
  }

  trigger.addEventListener("click", (e) => {
    e.stopPropagation();
    const isOpen = !dropdown.classList.contains("hidden");
    if (isOpen) {
      closeDropdown();
    } else {
      openDropdown();
    }
  });

  document.addEventListener("click", (e) => {
    if (!trigger.contains(e.target) && !dropdown.contains(e.target)) {
      closeDropdown();
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeDropdown();
  });

  document.querySelector("#dropdownProfile")?.addEventListener("click", () => closeDropdown());
  document.querySelector("#changePasswordButton")?.addEventListener("click", () => closeDropdown());
  document.querySelector("#dropdownSettings")?.addEventListener("click", () => {
    closeDropdown();
    document.querySelector(".nav-item[data-page='settings']")?.click();
  });
  document.querySelector("#logoutButton")?.addEventListener("click", () => closeDropdown());
}

// Hook into nav clicks to update page title
const _origNavClick = document.querySelectorAll(".nav-item");
_origNavClick.forEach(btn => {
  btn.addEventListener("click", () => {
    const page = btn.dataset.page;
    if (page) updateTopbarPageTitle(page);
  });
});

// ── Profile Modal ───────────────────────────────────────────
function openProfileModal() {
  if (!currentUser) return;
  const modal = document.querySelector("#profileModal");
  if (!modal) return;

  const initials = currentUser.name.split(" ").map(p => p[0]).join("").slice(0, 2).toUpperCase();

  document.querySelector("#profileModalAvatar").textContent = initials;
  document.querySelector("#profileModalName").textContent = currentUser.name;
  document.querySelector("#profileModalUsername").textContent = currentUser.username || "—";
  document.querySelector("#profileModalEmail").textContent = currentUser.email || "—";

  const roleEl = document.querySelector("#profileModalRole");
  roleEl.textContent = currentUser.role;
  roleEl.className = "role-badge role-" + currentUser.role.toLowerCase();

  document.querySelector("#profileModalRoleText").textContent = currentUser.role;

  const statusEl = document.querySelector("#profileModalStatus");
  statusEl.textContent = currentUser.status || "active";
  statusEl.style.color = currentUser.status === "active" ? "#22c55e" : "#ef4444";

  document.querySelector("#profileModalId").textContent = "#" + currentUser.id;

  modal.classList.remove("hidden");
}

function closeProfileModal() {
  document.querySelector("#profileModal")?.classList.add("hidden");
}

document.querySelector("#profileModalClose")?.addEventListener("click", closeProfileModal);
document.querySelector("#profileModal")?.addEventListener("click", (e) => {
  if (e.target === e.currentTarget) closeProfileModal();
});

document.querySelector("#dropdownProfile")?.addEventListener("click", () => {
  document.querySelector("#userDropdown")?.classList.add("hidden");
  document.querySelector("#topbarUser")?.classList.remove("open");
  openProfileModal();
});

// ── Idle Session Timeout: warning modal + server-enforced logout ────────────
const IDLE_TIMEOUT = 3 * 60 * 1000;          // 3 minutes idle before forced logout
const WARNING_DURATION = 60 * 1000;          // 1 minute warning countdown
const HEARTBEAT_DEBOUNCE = 30 * 1000;        // at most one server heartbeat per 30s of activity
const ACTIVITY_BROADCAST_DEBOUNCE = 5 * 1000;// cross-tab activity broadcast per 5s

const SESSION_ACTIVITY_KEY = "mpos.session.activity";
const SESSION_LOGGED_OUT_KEY = "mpos.session.loggedOut";
const IDLE_RING_RADIUS = 52;
const IDLE_RING_CIRCUMFERENCE = 2 * Math.PI * IDLE_RING_RADIUS;

let idleTimer = null;
let warningTimer = null;
let countdownInterval = null;
let idleWarningShowing = false;
let idleListenersAttached = false;
let loggingOut = false;
let countdownStartTime = 0;
let lastHeartbeatSent = 0;
let lastActivityBroadcast = 0;

function idleStorageSet(key, value) {
  try {
    localStorage.setItem(key, value);
  } catch (e) {
    /* storage may be unavailable (private mode); ignore */
  }
}

// Tell other open tabs the user is active (throttled).
function broadcastActivity() {
  const now = Date.now();
  if (now - lastActivityBroadcast >= ACTIVITY_BROADCAST_DEBOUNCE) {
    lastActivityBroadcast = now;
    idleStorageSet(SESSION_ACTIVITY_KEY, String(now));
  }
}

// Debounced server heartbeat so genuine activity keeps the server-side session
// alive without spamming requests on every mouse move.
function sendHeartbeat(force) {
  if (!currentUser) return;
  const now = Date.now();
  if (!force && now - lastHeartbeatSent < HEARTBEAT_DEBOUNCE) return;
  lastHeartbeatSent = now;
  apiRequest("api/heartbeat.php")
    .then(payload => {
      if (payload.authenticated === false) {
        forceLogout("idle_timeout");
      }
    })
    .catch(() => {
      // Transient network errors are ignored; the server-side timer still expires.
    });
}

function updateIdleRing(remainingSeconds, totalSeconds) {
  const ring = document.querySelector("#idleRingProgress");
  const number = document.querySelector("#idleCountdown");
  if (!ring || !number) return;
  const fraction = Math.max(0, Math.min(1, remainingSeconds / totalSeconds));
  ring.style.strokeDashoffset = String(IDLE_RING_CIRCUMFERENCE * (1 - fraction));
  ring.classList.toggle("danger", remainingSeconds <= 15);
  number.textContent = String(Math.max(0, Math.ceil(remainingSeconds)));
}

function resetIdleTimer() {
  if (!currentUser) return;
  clearTimeout(idleTimer);
  clearTimeout(warningTimer);
  clearInterval(countdownInterval);
  idleWarningShowing = false;

  document.querySelector("#idleWarningModal")?.classList.add("hidden");

  broadcastActivity();
  sendHeartbeat(false);

  idleTimer = setTimeout(() => {
    showIdleWarning();
  }, IDLE_TIMEOUT - WARNING_DURATION);
}

function showIdleWarning() {
  if (!currentUser) return;
  idleWarningShowing = true;

  const modal = document.querySelector("#idleWarningModal");
  const stayBtn = document.querySelector("#idleStayBtn");
  if (!modal || !stayBtn) return;

  // Confirm the server still considers the session valid before starting the clock.
  sendHeartbeat(true);

  modal.classList.remove("hidden");
  countdownStartTime = Date.now();
  const total = WARNING_DURATION / 1000;
  updateIdleRing(total, total);
  stayBtn.focus();

  countdownInterval = setInterval(() => {
    const remaining = total - Math.floor((Date.now() - countdownStartTime) / 1000);
    updateIdleRing(remaining, total);
    if (remaining <= 0) {
      clearInterval(countdownInterval);
      forceLogout("idle_timeout");
    }
  }, 1000);
}

function forceLogout(reason) {
  if (loggingOut) return;
  loggingOut = true;

  clearInterval(countdownInterval);
  clearTimeout(idleTimer);
  clearTimeout(warningTimer);
  idleWarningShowing = false;

  document.querySelector("#idleWarningModal")?.classList.add("hidden");

  // Let other tabs know this session is over (multi-tab consistency).
  idleStorageSet(SESSION_LOGGED_OUT_KEY, String(Date.now()));

  apiRequest("api/logout.php", { method: "POST" }).catch(() => {});

  currentUser = null;
  products = [];
  clearCartState();
  forgetLastPage();
  clearCsrfToken();
  // Dispose all amCharts roots so chart memory is released on logout.
  if (window.MpeliCharts) window.MpeliCharts.disposeAll();
  document.querySelector("#loginForm")?.reset();
  document.querySelector("#ownerSetupForm")?.reset();
  document.querySelectorAll("#loginScreen input").forEach(input => { input.value = ""; });
  showLogin(true);

  showToast(reason === "idle_timeout"
    ? (t("idle.loggedOut") || "Your session expired due to inactivity. Please log in again.")
    : "Session expired.");
}

function startIdleTimer() {
  loggingOut = false;

  if (idleListenersAttached) {
    resetIdleTimer();
    return;
  }
  idleListenersAttached = true;

  // Only genuine user interaction resets the idle timer. Background polling,
  // chart refreshes and AJAX updates never fire these events.
  const activityEvents = ["mousedown", "keydown", "touchstart", "scroll", "mousemove"];
  activityEvents.forEach(event => {
    document.addEventListener(event, resetIdleTimer, { passive: true });
  });

  // Multi-tab consistency: activity in any tab keeps every tab alive, and a
  // logout in one tab logs out the others.
  window.addEventListener("storage", (e) => {
    if (!currentUser) return;
    if (e.key === SESSION_ACTIVITY_KEY) {
      resetIdleTimer();
    } else if (e.key === SESSION_LOGGED_OUT_KEY) {
      forceLogout("session_expired");
    }
  });

  // Re-validate the server-side session whenever the tab regains focus.
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && currentUser) {
      sendHeartbeat(true);
    }
  });

  resetIdleTimer();
}

function stopIdleTimer() {
  clearTimeout(idleTimer);
  clearTimeout(warningTimer);
  clearInterval(countdownInterval);
  idleWarningShowing = false;
  document.querySelector("#idleWarningModal")?.classList.add("hidden");
}

// Continue Session: cancel the countdown and reset the timer everywhere.
document.querySelector("#idleStayBtn")?.addEventListener("click", () => {
  sendHeartbeat(true);
  resetIdleTimer();
});

// Log Out: end the session immediately.
document.querySelector("#idleLogoutBtn")?.addEventListener("click", () => {
  forceLogout("logout");
});

let dashboardRefreshTimer = null;
let shopNameGlobal = "Mpeli Outfit Store";
let receiptFooterGlobal = "";

function startDashboardAutoRefresh() {
  if (dashboardRefreshTimer) return;
  dashboardRefreshTimer = setInterval(async () => {
    if (document.querySelector("#dashboard")?.classList.contains("active")) {
      try {
        // Background poll: must not count as user activity or reset the idle timer.
        await refreshFinancialData({ background: true });
      } catch (e) {
      }
    }
  }, 30000);
}

// ── BI Analytics ───────────────────────────────────────────────────────────

let biCurrentPeriod = "last_7_days";
let biStartDate = "";
let biEndDate = "";
let biCurrentView = "overview";
let biSellerSort = "revenue";
let biProductSort = "revenue";
let biCurrentSellerId = null;
let biCurrentProductId = null;
let biInitialized = false;

async function initBI() {
  if (!biInitialized) {
    biInitialized = true;
    setupBIEvents();
  }
  biCurrentPeriod = "last_7_days";
  setBIPeriodActive("last_7_days");
  await loadBIView();
}

function setupBIEvents() {
  // Period buttons
  document.querySelectorAll(".bi-period-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      const period = btn.dataset.biPeriod;
      if (period === "custom") {
        document.querySelector("#biCustomRange")?.classList.toggle("hidden");
        return;
      }
      document.querySelector("#biCustomRange")?.classList.add("hidden");
      setBIPeriodActive(period);
      biCurrentPeriod = period;
      loadBIView();
    });
  });

  // Custom range apply
  document.querySelector("#biApplyCustom")?.addEventListener("click", () => {
    const s = document.querySelector("#biStartDate")?.value;
    const e = document.querySelector("#biEndDate")?.value;
    if (s && e) {
      biStartDate = s;
      biEndDate = e;
      biCurrentPeriod = "custom";
      setBIPeriodActive("custom");
      loadBIView();
    }
  });

  // View switching (Performance Overview | Performance Breakdown)
  document.querySelectorAll(".bi-view-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      const view = btn.dataset.biView;
      setBIViewActive(view);
      biCurrentView = view;
      loadBIView();
    });
  });

  // Seller sort
  document.querySelectorAll("#biSellerSort .bi-sort-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      document.querySelectorAll("#biSellerSort .bi-sort-btn").forEach(b => b.classList.remove("active"));
      btn.classList.add("active");
      biSellerSort = btn.dataset.sort;
      loadBISellers();
    });
  });

  // Product sort
  document.querySelectorAll("#biProductSort .bi-sort-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      document.querySelectorAll("#biProductSort .bi-sort-btn").forEach(b => b.classList.remove("active"));
      btn.classList.add("active");
      biProductSort = btn.dataset.sort;
      loadBIProducts();
    });
  });

  // Seller select for trend
  document.querySelector("#biSellerSelect")?.addEventListener("change", async (e) => {
    biCurrentSellerId = e.target.value || null;
    if (biCurrentSellerId) await loadBISellerTrend(biCurrentSellerId);
  });

  // Product select for trend
  document.querySelector("#biProductSelect")?.addEventListener("change", async (e) => {
    biCurrentProductId = e.target.value || null;
    if (biCurrentProductId) await loadBIProductTrend(biCurrentProductId);
  });
}

function setBIPeriodActive(period) {
  document.querySelectorAll(".bi-period-btn").forEach(b => b.classList.toggle("active", b.dataset.biPeriod === period));
}

function setBIViewActive(view) {
  document.querySelectorAll(".bi-view-btn").forEach(b => b.classList.toggle("active", b.dataset.biView === view));
  document.querySelectorAll(".bi-view-panel").forEach(p => p.classList.add("hidden"));
  const panelId = view === "overview" ? "biViewOverview" : "biViewBreakdown";
  const panel = document.querySelector("#" + panelId);
  if (panel) panel.classList.remove("hidden");
}

function biUrl(action, extra = {}) {
  let url = `api/analytics.php?action=${action}&period=${biCurrentPeriod}`;
  if (biCurrentPeriod === "custom") {
    url += `&start_date=${biStartDate}&end_date=${biEndDate}`;
  }
  Object.entries(extra).forEach(([k, v]) => { if (v != null) url += `&${k}=${v}`; });
  return url;
}

// Safely run a chart renderer into `container`. Guarantees the container is
// never left on the "Loading…" state: success -> chart, no-data/failure ->
// a safe empty/error message. Never throws to the caller.
function renderChartSafe(container, renderFn, opts = {}) {
  if (!container) return null;
  if (window.MpeliCharts) window.MpeliCharts.showChartLoading(container, opts.loading || "Loading…");
  try {
    const result = renderFn();
    if (result === null && window.MpeliCharts) {
      window.MpeliCharts.showChartEmpty(container, opts.empty || "No data available for the selected period.");
    }
    return result;
  } catch (e) {
    if (window.MpeliCharts) window.MpeliCharts.showChartError(container, opts.error || "Unable to render chart.");
    return null;
  }
}

// Fetch a BI payload, guaranteeing a chart container is never stuck on
// "Loading…" if the request fails. Returns the payload or null on error.
async function biRequestSafe(container, url, opts = {}) {
  if (container && window.MpeliCharts) window.MpeliCharts.showChartLoading(container, opts.loading || "Loading…");
  try {
    return await apiRequest(url);
  } catch (e) {
    if (container && window.MpeliCharts) window.MpeliCharts.showChartError(container, opts.error || "Unable to load chart data.");
    return null;
  }
}

async function loadBIView() {
  try {
    if (biCurrentView === "overview") {
      await loadBIOverview();
      await loadBISalesTrend();
      await loadBIProfitTrend();
      if (isOwner()) await loadBIExpenses();
      await loadBIDiscounts();
    } else {
      await loadBISellers();
      await loadBIProducts();
    }
  } catch (e) {
    showToast(e.message, "error");
  }
}

// ── Overview ───────────────────────────────────────────────────────────────

async function loadBIOverview() {
  const payload = await apiRequest(biUrl("dashboard"));
  const kpis = payload.kpis;
  const comp = payload.comparison?.comparison || {};

  setText("#biRevenue", money(kpis.revenue));
  setText("#biGrossProfit", money(kpis.gross_profit));
  setText("#biExpenses", money(kpis.expenses));
  setText("#biNetProfit", money(kpis.net_profit));
  setText("#biSalesCount", kpis.sales_count);
  setText("#biItemsSold", kpis.items_sold);
  setText("#biAvgOrder", money(kpis.avg_order_value));
  setText("#biProfitMargin", kpis.profit_margin + "%");
  setText("#biProductsSold", kpis.distinct_products_sold);
  setText("#biActiveSellers", kpis.active_sellers);

  setComparison("#biRevenueCompare", comp.revenue);
  setComparison("#biGrossProfitCompare", comp.gross_profit);
  setComparison("#biExpensesCompare", comp.expenses);
  setComparison("#biNetProfitCompare", comp.net_profit);
  setComparison("#biSalesCountCompare", comp.sales_count);
  setComparison("#biItemsSoldCompare", comp.items_sold);

  // Daily summary
  if (payload.daily_summary) {
    const ds = payload.daily_summary;
    const dsKpis = ds.kpis;
    setText("#biDailyRevenue", money(dsKpis.revenue));
    setText("#biDailyGrossProfit", money(dsKpis.gross_profit));
    setText("#biDailyExpenses", money(dsKpis.expenses));
    setText("#biDailyNetProfit", money(dsKpis.net_profit));
    setText("#biDailyTopProduct", ds.top_product?.product_name || "—");
    setText("#biDailyTopSeller", ds.top_seller?.seller_name || "—");
  }

  // Insights
  const insightsEl = document.querySelector("#biInsights");
  if (insightsEl && payload.insights) {
    if (payload.insights.length === 0) {
      insightsEl.innerHTML = "";
    } else {
      const iconMap = { positive: "bi-arrow-up-right", negative: "bi-arrow-down-right", warning: "bi-exclamation-triangle", info: "bi-info-circle" };
      insightsEl.innerHTML = payload.insights.map(i =>
        `<div class="bi-insight ${i.type}"><i class="bi ${iconMap[i.type] || 'bi-info-circle'} bi-insight-icon"></i> <span>${escapeHtml(i.text)}</span></div>`
      ).join("");
    }
  }
}

// ── Sales Trend ────────────────────────────────────────────────────────────

async function loadBISalesTrend() {
  const container = document.querySelector("#biSalesTrendChart");
  const payload = await biRequestSafe(container, biUrl("sales_trend"));
  if (!payload) return;
  const trend = payload.trend || [];
  const rangeLabel = biPeriodLabel(payload.period, payload.start_date, payload.end_date);
  setText("#biSalesTrendRange", rangeLabel);

  if (!trend.length) {
    if (window.MpeliCharts) window.MpeliCharts.disposeRoot(container);
    if (container) container.innerHTML = `<div class="bi-empty-state"><i class="bi bi-bar-chart-line"></i><p>${t("analytics.noData")}</p></div>`;
    return;
  }
  renderChartSafe(container, () => {
    if (window.MpeliBusinessCharts) return window.MpeliBusinessCharts.renderSalesTrend(container, trend);
    let out = null;
    window.MpeliCharts?.onReady(() => { if (window.MpeliBusinessCharts) out = window.MpeliBusinessCharts.renderSalesTrend(container, trend); });
    return out;
  }, { error: "Unable to render sales trend chart." });
}

// ── Profit Trend ───────────────────────────────────────────────────────────

async function loadBIProfitTrend() {
  const container = document.querySelector("#biProfitTrendChart");
  const payload = await biRequestSafe(container, biUrl("profit_trend"));
  if (!payload) return;
  const trend = payload.trend || [];
  const rangeLabel = biPeriodLabel(payload.period, payload.start_date, payload.end_date);
  setText("#biProfitTrendRange", rangeLabel);

  if (!trend.length) {
    if (window.MpeliCharts) window.MpeliCharts.disposeRoot(container);
    if (container) container.innerHTML = `<div class="bi-empty-state"><i class="bi bi-graph-up"></i><p>${t("analytics.noData")}</p></div>`;
    return;
  }
  renderChartSafe(container, () => {
    if (window.MpeliBusinessCharts) return window.MpeliBusinessCharts.renderProfitTrend(container, trend);
    let out = null;
    window.MpeliCharts?.onReady(() => { if (window.MpeliBusinessCharts) out = window.MpeliBusinessCharts.renderProfitTrend(container, trend); });
    return out;
  }, { error: "Unable to render profit trend chart." });
}

// ── Seller Performance ─────────────────────────────────────────────────────

async function loadBISellers() {
  const rankingContainer = document.querySelector("#biSellerRankingChart");
  const payload = await biRequestSafe(rankingContainer, biUrl("seller_performance"));
  if (!payload) return;
  let sellers = payload.sellers || [];

  // Sort
  if (biSellerSort === "profit") sellers.sort((a, b) => Number(b.gross_profit) - Number(a.gross_profit));
  else if (biSellerSort === "sales") sellers.sort((a, b) => Number(b.transactions) - Number(a.transactions));
  else if (biSellerSort === "items") sellers.sort((a, b) => Number(b.items_sold) - Number(a.items_sold));
  else sellers.sort((a, b) => Number(b.revenue) - Number(a.revenue));

  // Populate select
  const select = document.querySelector("#biSellerSelect");
  if (select) {
    const currentVal = select.value;
    select.innerHTML = `<option value="">${t("analytics.selectSeller")}</option>` +
      sellers.map(s => `<option value="${s.seller_id}">${escapeHtml(s.seller_name)}</option>`).join("");
    if (currentVal) select.value = currentVal;
  }

  const tbody = document.querySelector("#biSellerBody");
  if (!tbody) return;

  if (!sellers.length) {
    tbody.innerHTML = `<tr><td colspan="9" class="align-right">${t("analytics.noData")}</td></tr>`;
    const rankingContainer = document.querySelector("#biSellerRankingChart");
    if (window.MpeliCharts) window.MpeliCharts.disposeRoot(rankingContainer);
    if (rankingContainer) rankingContainer.innerHTML = `<div class="bi-empty-state"><i class="bi bi-people"></i><p>${t("analytics.noData")}</p></div>`;
    return;
  }

  // Render seller ranking bar chart (metric depends on the active sort).
  const sortingMetric = biSellerSort === "profit" ? "gross_profit"
    : biSellerSort === "sales" ? "transactions"
    : biSellerSort === "items" ? "items_sold"
    : "revenue";
  renderChartSafe(rankingContainer, () => {
    if (window.MpeliBusinessCharts) {
      return window.MpeliBusinessCharts.renderSellerRanking(rankingContainer, sellers, {
        nameField: "seller_name",
        valueField: sortingMetric,
        name: biSellerSort === "revenue" ? "Revenue" : (biSellerSort === "profit" ? "Profit" : (biSellerSort === "sales" ? "Sales" : "Items Sold")),
        color: sortingMetric === "gross_profit" ? window.MpeliCharts?.cssVar("--success", "#2d7c59") : window.MpeliCharts?.cssVar("--gold", "#c9a24e"),
        limit: 10,
        emptyMessage: t("analytics.noData"),
      });
    }
    return null;
  }, { error: "Unable to render seller ranking." });

  const rankIcons = ["", "🥇", "🥈", "🥉"];
  tbody.innerHTML = sellers.map((s, i) => {
    const marginClass = s.profit_margin >= 30 ? "margin-good" : (s.profit_margin < 15 ? "margin-bad" : "margin-neutral");
    return `<tr>
      <td class="rank-cell ${i < 3 ? 'rank-' + (i + 1) : ''}">${rankIcons[i] || (i + 1)}</td>
      <td class="seller-name-cell">${escapeHtml(s.seller_name)}</td>
      <td class="align-right">${s.transactions}</td>
      <td class="align-right">${s.items_sold}</td>
      <td class="align-right">${money(s.revenue)}</td>
      <td class="align-right">${money(s.gross_profit)}</td>
      <td class="align-right"><span class="${marginClass}">${s.profit_margin}%</span></td>
      <td class="align-right">${money(s.avg_order_value)}</td>
      <td class="align-right">${money(s.discount_amount)}</td>
    </tr>`;
  }).join("");
}

async function loadBISellerTrend(sellerId) {
  const container = document.querySelector("#biSellerTrendChart");
  const payload = await biRequestSafe(container, biUrl("seller_trend", { seller_id: sellerId }));
  if (!payload) return;
  const trend = payload.trend || [];

  if (!trend.length) {
    if (window.MpeliCharts) window.MpeliCharts.disposeRoot(container);
    if (container) container.innerHTML = `<div class="bi-empty-state"><i class="bi bi-person-line-dotted"></i><p>${t("analytics.noData")}</p></div>`;
    return;
  }

  renderChartSafe(container, () => {
    if (window.MpeliBusinessCharts) return window.MpeliBusinessCharts.renderSellerTrend(container, trend);
    let out = null;
    window.MpeliCharts?.onReady(() => { if (window.MpeliBusinessCharts) out = window.MpeliBusinessCharts.renderSellerTrend(container, trend); });
    return out;
  }, { error: "Unable to render seller trend chart." });
}

// ── Product Performance ────────────────────────────────────────────────────

async function loadBIProducts() {
  const productRankingContainer = document.querySelector("#biProductRankingChart");
  if (productRankingContainer && window.MpeliCharts) window.MpeliCharts.showChartLoading(productRankingContainer, "Loading…");
  let rankingsPayload = null;
  let categoriesPayload = null;
  try {
    [rankingsPayload, categoriesPayload] = await Promise.all([
      apiRequest(biUrl("product_rankings", { sort: biProductSort, limit: 15 })),
      apiRequest(biUrl("product_categories")),
    ]);
  } catch (e) {
    if (productRankingContainer && window.MpeliCharts) window.MpeliCharts.showChartError(productRankingContainer, "Unable to load product data.");
    return;
  }

  let products = (rankingsPayload && rankingsPayload.products) || [];

  const tbody = document.querySelector("#biProductBody");
  if (tbody) {
    if (!products.length) {
      tbody.innerHTML = `<tr><td colspan="9" class="align-right">${t("analytics.noData")}</td></tr>`;
    } else {
      tbody.innerHTML = products.map((p, i) => {
        const marginClass = p.profit_margin >= 30 ? "margin-good" : (p.profit_margin < 15 ? "margin-bad" : "margin-neutral");
        const stockBadge = p.stock_status === "out_of_stock" ? "out" : (p.stock_status === "low_stock" ? "low" : "in");
        return `<tr>
          <td class="rank-cell ${i < 3 ? 'rank-' + (i + 1) : ''}">${i + 1}</td>
          <td class="product-name-cell">${escapeHtml(p.product_name)}</td>
          <td>${escapeHtml(p.category_name || "")}</td>
          <td class="align-right">${p.quantity_sold}</td>
          <td class="align-right">${money(p.revenue)}</td>
          <td class="align-right">${money(p.gross_profit)}</td>
          <td class="align-right"><span class="${marginClass}">${p.profit_margin}%</span></td>
          <td class="align-right">${money(p.avg_selling_price)}</td>
          <td class="align-right"><span class="stock-badge-sm ${stockBadge}">${p.current_stock}</span></td>
        </tr>`;
      }).join("");
    }
  }

  // Populate product select
  const allProductsPayload = await apiRequest(biUrl("product_performance"));
  const select = document.querySelector("#biProductSelect");
  if (select) {
    const currentVal = select.value;
    select.innerHTML = `<option value="">${t("analytics.selectProduct")}</option>` +
      (allProductsPayload.products || []).map(p => `<option value="${p.product_id}">${escapeHtml(p.product_name)}</option>`).join("");
    if (currentVal) select.value = currentVal;
  }

  // Render product ranking bar chart.
  if (window.MpeliCharts) window.MpeliCharts.disposeRoot(productRankingContainer);
  if (!products.length) {
    if (productRankingContainer) productRankingContainer.innerHTML = `<div class="bi-empty-state"><i class="bi bi-box-seam"></i><p>${t("analytics.noData")}</p></div>`;
  } else if (window.MpeliBusinessCharts) {
    const productMetric = biProductSort === "profit" ? "gross_profit"
      : biProductSort === "quantity" ? "quantity_sold"
      : "revenue";
    window.MpeliBusinessCharts.renderProductRanking(productRankingContainer, products, {
      nameField: "product_name",
      valueField: productMetric,
      name: biProductSort === "revenue" ? "Revenue" : (biProductSort === "profit" ? "Profit" : "Quantity Sold"),
      color: productMetric === "gross_profit" ? window.MpeliCharts?.cssVar("--success", "#2d7c59") : window.MpeliCharts?.cssVar("--gold", "#c9a24e"),
      limit: 12,
      emptyMessage: t("analytics.noData"),
    });
  }

  // Product categories
  renderProductCategories(categoriesPayload.categories || {});
}

function renderProductCategories(categories) {
  const container = document.querySelector("#biProductCategories");
  if (!container) return;

  const cards = [];
  if (categories.best_sellers?.length) {
    cards.push(`<div class="bi-kpi-card"><span class="bi-kpi-label">${t("analytics.bestSellers")}</span><span class="bi-kpi-value" style="font-size:14px; text-align:left; line-height:1.6">${categories.best_sellers.map(p => escapeHtml(p.product_name)).join(", ")}</span></div>`);
  }
  if (categories.slow_moving?.length) {
    cards.push(`<div class="bi-kpi-card"><span class="bi-kpi-label">${t("analytics.slowMoving")}</span><span class="bi-kpi-value" style="font-size:14px; text-align:left; line-height:1.6">${categories.slow_moving.map(p => escapeHtml(p.product_name)).join(", ")}</span></div>`);
  }
  if (categories.out_of_stock?.length) {
    cards.push(`<div class="bi-kpi-card"><span class="bi-kpi-label">${t("analytics.outOfStock")}</span><span class="bi-kpi-value danger" style="font-size:14px; text-align:left; line-height:1.6">${categories.out_of_stock.map(p => escapeHtml(p.product_name)).join(", ")}</span></div>`);
  }
  if (categories.low_stock?.length) {
    cards.push(`<div class="bi-kpi-card"><span class="bi-kpi-label">${t("analytics.lowStock")}</span><span class="bi-kpi-value" style="font-size:14px; text-align:left; line-height:1.6; color:var(--text-secondary)">${categories.low_stock.map(p => escapeHtml(p.product_name)).join(", ")}</span></div>`);
  }
  container.innerHTML = cards.join("") || `<div class="bi-kpi-card"><span class="bi-kpi-label">${t("analytics.noData")}</span></div>`;
}

async function loadBIProductTrend(productId) {
  const container = document.querySelector("#biProductTrendChart");
  const payload = await biRequestSafe(container, biUrl("product_trend", { product_id: productId }));
  if (!payload) return;
  const trend = payload.trend || [];

  if (!trend.length) {
    if (window.MpeliCharts) window.MpeliCharts.disposeRoot(container);
    if (container) container.innerHTML = `<div class="bi-empty-state"><i class="bi bi-box-seam"></i><p>${t("analytics.noData")}</p></div>`;
    return;
  }

  renderChartSafe(container, () => {
    if (window.MpeliBusinessCharts) return window.MpeliBusinessCharts.renderProductTrend(container, trend);
    let out = null;
    window.MpeliCharts?.onReady(() => { if (window.MpeliBusinessCharts) out = window.MpeliBusinessCharts.renderProductTrend(container, trend); });
    return out;
  }, { error: "Unable to render product trend chart." });
}

// ── Expense Impact ─────────────────────────────────────────────────────────

async function loadBIExpenses() {
  const payload = await apiRequest(biUrl("expense_impact"));
  const impact = payload.impact || {};

  setText("#biExpRevenue", money(impact.revenue));
  setText("#biExpGrossProfit", money(impact.gross_profit));
  setText("#biExpExpenses", money(impact.expenses));
  setText("#biExpNetProfit", money(impact.net_profit));

  const tbody = document.querySelector("#biExpenseBreakdownBody");
  if (!tbody) return;

  const breakdown = impact.expense_breakdown || [];
  if (!breakdown.length) {
    tbody.innerHTML = `<tr><td colspan="3" class="align-right">${t("analytics.noData")}</td></tr>`;
    return;
  }

  const totalExp = Number(impact.expenses) || 1;
  tbody.innerHTML = breakdown.map(b => {
    const pct = totalExp > 0 ? ((Number(b.total) / totalExp) * 100).toFixed(1) : "0";
    return `<tr>
      <td>${escapeHtml(t("expenseCategory." + b.category.toLowerCase()) || b.category)}</td>
      <td class="align-right">${money(b.total)}</td>
      <td class="align-right">${pct}%</td>
    </tr>`;
  }).join("");
}

// ── Discount Analysis ──────────────────────────────────────────────────────

async function loadBIDiscounts() {
  const [discountsPayload, promosPayload] = await Promise.all([
    apiRequest(biUrl("discount_analysis")),
    apiRequest(biUrl("promotion_performance")),
  ]);

  const d = discountsPayload.discounts || {};
  setText("#biDiscountTotal", money(d.total_discount_amount));
  setText("#biDiscountedItems", d.discounted_items);
  setText("#biDiscountRate", d.discount_percentage + "%");
  setText("#biDiscountedSales", d.discounted_sales);

  const tbody = document.querySelector("#biPromoBody");
  if (!tbody) return;

  const promos = promosPayload.promotions || [];
  if (!promos.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="align-right">${t("analytics.noData")}</td></tr>`;
    return;
  }

  tbody.innerHTML = promos.map(p => `<tr>
    <td>${escapeHtml(p.promotion_name)}</td>
    <td class="align-right">${p.percentage}%</td>
    <td class="align-right">${p.sales_count}</td>
    <td class="align-right">${p.items_sold}</td>
    <td class="align-right">${money(p.revenue)}</td>
    <td class="align-right">${money(p.gross_profit)}</td>
    <td class="align-right">${money(p.discount_amount)}</td>
  </tr>`).join("");
}

// ── BI Helpers ─────────────────────────────────────────────────────────────

function setText(selector, value) {
  const el = document.querySelector(selector);
  if (el) el.textContent = value;
}

function setComparison(selector, comp) {
  const el = document.querySelector(selector);
  if (!el || !comp) { if (el) el.textContent = ""; return; }
  if (!comp.has_data || comp.change === 0) {
    el.textContent = "";
    el.className = "bi-kpi-sub";
    return;
  }
  const arrow = comp.direction === "up" ? "▲" : "▼";
  const cls = comp.direction === "up" ? "positive" : "negative";
  el.textContent = `${arrow} ${Math.abs(comp.change)}%`;
  el.className = "bi-kpi-sub " + cls;
}

function shortDateLabel(dateStr) {
  if (!dateStr) return "";
  const parts = String(dateStr).split("-");
  if (parts.length < 2) return dateStr;
  const months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
  const m = parseInt(parts[1], 10);
  if (parts.length === 3) return `${months[m - 1]} ${parseInt(parts[2], 10)}`;
  if (parts.length === 2) return `${months[m - 1]} ${parts[0].slice(2)}`;
  return dateStr;
}

function biPeriodLabel(period, startDate, endDate) {
  const labels = {
    today: t("common.today"),
    yesterday: t("analytics.yesterday"),
    last_7_days: t("analytics.last7Days"),
    last_30_days: t("analytics.last30Days"),
    this_week: t("analytics.thisWeek"),
    last_week: t("analytics.lastWeek"),
    this_month: t("analytics.thisMonth"),
    last_month: t("analytics.lastMonth"),
    this_year: t("analytics.thisYear"),
    custom: `${startDate} – ${endDate}`,
  };
  return labels[period] || period;
}

async function init() {
  try {
    await loadTranslations(currentLanguage);
    applyTranslations();

    const appSwitcher = document.querySelector("#appLanguageSwitcher");
    if (appSwitcher && !appSwitcher.options.length) {
      appSwitcher.innerHTML = '<option value="en">English</option><option value="sw">Swahili</option>';
      appSwitcher.value = currentLanguage;
    }

    let mePayload;
    try {
      mePayload = await apiRequest("api/me.php");
    } catch (error) {
      const healthChecks = [{ id: 'connection', label: 'Server Connection', severity: 'critical', detail: error.message || 'Could not reach the server.' }];
      showHealthScreen(healthChecks, false);
      return;
    }

    if (mePayload.healthy === false || mePayload.success === false) {
      showHealthScreen(mePayload.checks || [{ id: 'unknown', label: 'Unknown Error', severity: 'critical', detail: 'System check failed.' }], false);
      return;
    }

    if (mePayload.maintenance && mePayload.maintenance.active) {
      const maintRole = mePayload.user?.role;
      const allowed = mePayload.maintenance.allowed_roles || ['OWNER'];
      if (maintRole && allowed.includes(maintRole)) {
      } else {
        showMaintenanceScreen(mePayload.maintenance.message || 'System is under maintenance.');
        return;
      }
    }

    if (mePayload.authenticated && mePayload.user) {
      currentUser = mePayload.user;
      showApp();
      bindAuditEvents();
      bindBackupEvents();
      await refreshAppData();
      const lastPage = getLastPage();
      if (lastPage && lastPage !== "dashboard") {
        const target = document.querySelector(`.nav-item[data-page="${lastPage}"]`);
        if (target) target.click();
      }
      startDashboardAutoRefresh();
    } else {
      const ownerExists = mePayload.owner_exists === true;
      showLogin(ownerExists);
    }
  } catch (error) {
    showLogin(true);
  } finally {
    const splash = document.querySelector("#splashScreen");
    if (splash) {
      splash.classList.add("fade-out");
      setTimeout(() => splash.remove(), 500);
    }
  }
}

// Run init when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

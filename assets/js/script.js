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

const KNOWN_PAGES = ["dashboard", "products", "sales", "expenses", "inventory", "reports", "users", "settings"];

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

  renderBarChart(document.querySelector(".revenue-chart") || document.querySelector(".bar-chart"), payload.revenue_chart, payload.has_revenue_chart, "value", true);
  renderBarChart(document.querySelector(".profit-chart"), payload.profit_chart, payload.has_profit_chart, "value", true);

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
    const variantStr = variantParts.length > 0 ? `<br><small class="sale-details-variant">${variantParts.join(" / ")}</small>` : "";

    return `<tr>
      <td>${i + 1}</td>
      <td>${productName}${variantStr}</td>
      <td>${qty}</td>
      <td>${money(unitPrice)}</td>
      <td>${lineDiscount > 0 ? money(lineDiscount) : "—"}</td>
      <td>${money(lineTotal)}</td>
    </tr>`;
  }).join("");

  const headerDiscount = parseFloat(sale.discount_amount) || totalDiscount;
  const totalItems = sale.items ? sale.items.length : 0;

  content.innerHTML = `
    <div class="sale-details-header">
      <div class="sale-details-receipt">${escapeHtml(sale.receipt_number)}</div>
      <div class="sale-details-meta">
        <span><i class="bi bi-calendar3"></i> ${dateStr}</span>
        <span><i class="bi bi-clock"></i> ${timeStr}</span>
        <span><i class="bi bi-person"></i> ${escapeHtml(sale.seller_name)}</span>
      </div>
    </div>
    <div class="sale-details-table-wrap">
      <table class="sale-details-table">
        <thead><tr>
          <th>#</th>
          <th>${t("saleDetails.product")}</th>
          <th>${t("saleDetails.qty")}</th>
          <th>${t("saleDetails.unitPrice")}</th>
          <th>${t("saleDetails.discount")}</th>
          <th>${t("saleDetails.subtotal")}</th>
        </tr></thead>
        <tbody>${itemsHtml || `<tr><td colspan="6" class="sale-details-empty">${t("saleDetails.noItems")}</td></tr>`}</tbody>
      </table>
    </div>
    <div class="sale-details-summary">
      <div class="sale-details-summary-row">
        <span>${t("saleDetails.productTypes")}</span><span>${totalItems}</span>
      </div>
      <div class="sale-details-summary-row">
        <span>${t("saleDetails.totalQuantity")}</span><span>${totalQuantity}</span>
      </div>
      ${headerDiscount > 0 ? `<div class="sale-details-summary-row">
        <span>${t("saleDetails.totalDiscount")}</span><span>${money(headerDiscount)}</span>
      </div>` : ""}
      <div class="sale-details-summary-row sale-details-total">
        <span>${t("saleDetails.totalPaid")}</span><span>${money(sale.total_amount)}</span>
      </div>
      ${isOwner ? `<div class="sale-details-summary-row sale-details-profit">
        <span>${t("saleDetails.profit")}</span><span>${money(sale.total_profit)}</span>
      </div>` : ""}
    </div>
    <div class="sale-details-footer">
      <button type="button" class="ghost-button" id="saleDetailsCloseBtn">${t("common.close")}</button>
    </div>
  `;

  document.querySelector("#saleDetailsCloseBtn")?.addEventListener("click", closeSaleDetailsModal);
  document.querySelector("#saleDetailsClose")?.addEventListener("click", closeSaleDetailsModal);
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
    try {
      if (page === "dashboard") await loadDashboard();
      else if (page === "products") { await loadProducts(); await loadPromotions(); }
      else if (page === "sales") { await loadProducts(); await loadPromotions(); }
      else if (page === "promotions" && isOwner()) await loadPromotionsOwner();
      else if (page === "expenses") await loadExpenses();
      else if (page === "inventory" && isOwner()) await loadInventory();
      else if (page === "reports") await loadReports();
      else if (page === "users" && isOwner()) await loadUsers();
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

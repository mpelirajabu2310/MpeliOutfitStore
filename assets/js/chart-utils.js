/* ═══════════════════════════════════════════════════════════════════════════
 * Mpeli Outfit Store — amCharts 5 Shared Utilities
 *
 * Centralizes the reusable pieces of the amCharts 5 visualization layer:
 *   • Currency & date formatting (consistent with the app's TSh / TSH)
 *   • Chart theming (reads the app's CSS custom properties → light/dark aware)
 *   • Root creation / single-root-per-container disposal (prevents
 *     "You cannot have multiple Roots in the same DOM node")
 *   • Loading, empty and error states
 *   • Common tooltip & label configuration
 * ═══════════════════════════════════════════════════════════════════════════ */
(function (global) {
  "use strict";

  const MpeliCharts = {
    am5: null, // core namespace, populated after index.js loads
    am5xy: null, // XY chart namespace, populated after xy.js loads (global am5xy)
    am5percent: null, // percent chart namespace, populated after percent.js (am5percent)
    ready: false,
    awaiting: [],
  };

  // ── CDN/self-hosted readiness ──────────────────────────────────────────────
  // amCharts 5 script-tag builds expose the chart namespaces as SEPARATE globals
  // (`am5` core, `am5xy` for XY, `am5percent` for percent) — NOT as properties
  // of the `am5` core object. We therefore gate on those globals so renderers
  // never run before the namespaces have actually merged.
  function onAmReady() {
    if (!global.am5 || !global.am5xy) return false;
    MpeliCharts.am5 = global.am5;
    MpeliCharts.am5xy = global.am5xy;
    MpeliCharts.am5percent = global.am5percent || null;
    MpeliCharts.ready = true;
    MpeliCharts.awaiting.forEach(function (fn) { try { fn(); } catch (e) {} });
    MpeliCharts.awaiting = [];
    return true;
  }

  MpeliCharts.onReady = function (fn) {
    if (MpeliCharts.ready && MpeliCharts.am5) { try { fn(); } catch (e) {} return; }
    MpeliCharts.awaiting.push(fn);
  };

  // ── CSS variable → color ───────────────────────────────────────────────────
  // Charts must not hard-code the palette so dark/light mode keeps working.
  const themeCache = {};
  function cssVar(name, fallback) {
    const key = name;
    if (themeCache[key] !== undefined) return themeCache[key];
    try {
      const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
      themeCache[key] = v || fallback;
    } catch (e) {
      themeCache[key] = fallback;
    }
    return themeCache[key];
  }

  function palette() {
    return {
      gold: cssVar("--gold", "#c9a24e"),
      success: cssVar("--success", "#2d7c59"),
      danger: cssVar("--danger", "#b84c43"),
      text: cssVar("--text", "#1d1a16"),
      textSecondary: cssVar("--text-secondary", "#77716a"),
      border: cssVar("--border", "#e9e4da"),
      bg: cssVar("--surface", "#ffffff"),
      muted: cssVar("--text-secondary", "#77716a"),
    };
  }

  // Invalidate the cache whenever the theme changes so re-created charts pick
  // up the new palette. Existing roots are re-themed in applyChartTheme().
  function clearThemeCache() {
    for (const k in themeCache) delete themeCache[k];
  }

  // ── Currency / number formatting (TSh standard, app-consistent) ──────────
  function money(value) {
    const amount = Number(value || 0);
    return "TSH " + amount.toLocaleString(undefined, {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    });
  }

  // Compact label for axes / legends: TSH 1.2M, TSH 45K …
  function moneyCompact(value) {
    const n = Number(value || 0);
    const abs = Math.abs(n);
    const sign = n < 0 ? "-" : "";
    if (abs >= 1e9) return `${sign}${trimNum(abs / 1e9)}B`;
    if (abs >= 1e6) return `${sign}${trimNum(abs / 1e6)}M`;
    if (abs >= 1e3) return `${sign}${trimNum(abs / 1e3)}K`;
    return String(Math.round(n));
  }
  function trimNum(x) {
    const r = Math.round(x * 10) / 10;
    return Number.isInteger(r) ? String(r) : String(r);
  }

  // Full label for tooltips (precise value)
  function tooltipMoney(value) {
    return "TSh " + Number(value || 0).toLocaleString(undefined, {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    });
  }

  // ── A readable dependant-axis label formatter ─────────────────────────────
  function axisMoneyLabel(value) {
    return moneyCompact(value);
  }

  // ── Chart root creation with duplicate-root protection & animation ────────
  // Before creating a new Root on `element`, any existing amCharts root on the
  // same DOM node is disposed. This prevents the "You cannot have multiple
  // Roots in the same DOM node" error when switching views / filters.
  const rootRegistry = new Map();

  function getRootForElement(element) {
    if (!element) return null;
    return rootRegistry.get(element) || null;
  }

  function safeRoot(element, allowedModules) {
    if (!element) return { root: null, disposed: false };

    // Dispose an existing root on this node (and any nested children).
    const existing = rootRegistry.get(element);
    if (existing) {
      try { existing.dispose(); } catch (e) {}
      rootRegistry.delete(element);
    }
    // Defensive: if amCharts 5 already recorded a root on this node internally,
    // clear it so we can re-initialize cleanly.
    element.__am5root = undefined;

    if (!global.am5) {
      showChartLoading(element, "Chart library loading…");
      return { root: null, disposed: false };
    }

    const root = global.am5.Root.new(element);
    element.__am5root = root;
    rootRegistry.set(element, root);

    // Animated theme — professional initial + transition animations.
    try {
      if (global.am5themes_Animated) {
        root.setThemes([global.am5themes_Animated.new(root)]);
      }
    } catch (e) {}

    return { root, disposed: false };
  }

  // Apply/refresh the palette on a root (used after creation + on theme toggle).
  function applyChartTheme(root) {
    if (!root) return;
    const p = palette();
    try {
      root.getInterfaceContainer().set("containerBackground", global.am5.color(p.bg));
    } catch (e) {}
  }

  function disposeRoot(element) {
    if (!element) return;
    const root = rootRegistry.get(element);
    if (root) {
      try { root.dispose(); } catch (e) {}
      rootRegistry.delete(element);
      element.__am5root = undefined;
    }
  }

  // Dispose every tracked root (used on logout / full teardown).
  function disposeAll() {
    rootRegistry.forEach(function (root, element) {
      try { root.dispose(); } catch (e) {}
    });
    rootRegistry.clear();
  }

  // ── Common tooltip configuration ───────────────────────────────────────────
  function tooltipSettings() {
    const p = palette();
    return {
      labelText: "{valueY}",
      backgroundFill: global.am5.color("#11100e"),
      backgroundFillOpacity: 0.92,
      fill: global.am5.color("#ffffff"),
      labelFill: global.am5.color("#ffffff"),
    };
  }

  function themeTip(root) {
    const p = palette();
    return {
      fill: global.am5.color("#11100e"),
      fillOpacity: 0.92,
      labelFill: global.am5.color("#ffffff"),
    };
  }

  // ── Loading / empty / error states (kept out of amCharts so they don't
  //    create misleading empty charts). ──────────────────────────────────────
  function showChartLoading(element, message) {
    if (!element) return;
    element.innerHTML = `<div class="am-chart-state am-chart-loading"><span class="am-chart-spinner"></span><p>${escapeState(message || "Loading…")}</p></div>`;
  }

  function showChartEmpty(element, message) {
    if (!element) return;
    element.innerHTML = `<div class="am-chart-state am-chart-empty"><i class="bi bi-inbox"></i><p>${escapeState(message || "No sales data available for the selected period.")}</p></div>`;
  }

  function showChartError(element, message) {
    if (!element) return;
    element.innerHTML = `<div class="am-chart-state am-chart-error"><i class="bi bi-exclamation-triangle"></i><p>${escapeState(message || "Unable to load chart data.")}</p></div>`;
  }

  function escapeState(str) {
    return String(str == null ? "" : str).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  // ── Resize helper: amCharts auto-resizes; we refresh on window resize via
  //    browser resize events so container-based roots stay in sync. Real
  //    per-root responsiveness is handled by amCharts itself.
  const debounced = {};
  function debounce(fn, wait) {
    return function () {
      const ctx = this, args = arguments;
      clearTimeout(debounced[fn]);
      debounced[fn] = setTimeout(function () { fn.apply(ctx, args); }, wait);
    };
  }

  // Re-theme all live roots when the app theme toggles. The app toggles the
  // `dark` class on <body>; we listen once at module scope here.
  function wireThemeListener() {
    const toggle = document.querySelector("#themeToggle");
    if (toggle && !toggle.__mpeCharts) {
      toggle.__mpeCharts = true;
      toggle.addEventListener("click", function () {
        clearThemeCache();
        setTimeout(function () {
          rootRegistry.forEach(function (root, element) {
            applyChartTheme(root);
          });
        }, 0);
      });
    }
  }
  document.addEventListener("DOMContentLoaded", wireThemeListener);
  if (document.readyState !== "loading") wireThemeListener();

  // ── Public API ─────────────────────────────────────────────────────────────
  MpeliCharts.cssVar = cssVar;
  MpeliCharts.palette = palette;
  MpeliCharts.clearThemeCache = clearThemeCache;
  MpeliCharts.money = money;
  MpeliCharts.moneyCompact = moneyCompact;
  MpeliCharts.tooltipMoney = tooltipMoney;
  MpeliCharts.axisMoneyLabel = axisMoneyLabel;
  MpeliCharts.safeRoot = safeRoot;
  MpeliCharts.applyChartTheme = applyChartTheme;
  MpeliCharts.disposeRoot = disposeRoot;
  MpeliCharts.disposeAll = disposeAll;
  MpeliCharts.getRootForElement = getRootForElement;
  MpeliCharts.tooltipSettings = tooltipSettings;
  MpeliCharts.showChartLoading = showChartLoading;
  MpeliCharts.showChartEmpty = showChartEmpty;
  MpeliCharts.showChartError = showChartError;
  MpeliCharts.onAmReady = onAmReady;

  global.MpeliCharts = MpeliCharts;

  // If amCharts is already loaded, mark ready.
  onAmReady();
})(window);

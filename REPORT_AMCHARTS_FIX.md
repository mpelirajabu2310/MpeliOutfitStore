# Mpeli Out Fit Store — amCharts 5 BI Upgrade: Diagnosis & Fix Report

**Date:** 2026-09-02
**Environment:** Local XAMPP (Apache :80, MySQL, PHP 8.2.12) at `http://127.0.0.1/MpeliOutFitStore/`
**Status:** Fix verified locally. **Not deployed, not pushed.**

---

## 1. Symptoms (before fix)

1. **Dashboard** kept rendering the OLD hand-built SVG charts instead of the new amCharts.
2. **Business Analysis** (both views) stuck on **"Loading…"** indefinitely.

Backend was already confirmed healthy: `api/analytics.php` returns valid data for every action
(`sales_trend`, `profit_trend`, `seller_performance`, `seller_trend`, `product_trend`,
`product_rankings`, `product_categories`, `expense_impact`, `discount_analysis`, `dashboard`),
with field names matching the JS expectations. Auth (Phase 1 OWNER/SELLER) was fine. The chart
JS files loaded 200 with no 404s, no CSP violations, and all four `amcharts/*` files matched the
official CDN byte-for-byte.

So the backend, the network, and the library files were all correct — the bug was **in how the
JavaScript referenced the amCharts 5 API**.

---

## 2. Root cause

**amCharts 5 script-tag builds expose the chart namespaces as SEPARATE global objects
(`am5` core, `am5xy` for XY charts, `am5percent` for percent charts) — NOT as properties of the
`am5` core object.**

The chart code referenced:
- `am5.xy.XYChart` / `M.am5.xy`  → **undefined** (the correct global is `am5xy`)
- `am5.percent.PieChart` / `M.am5.percent` → **undefined** (correct global is `am5percent`)

Consequences:
- `chart-utils.js` `onAmReady()` tested `!global.am5.xy || !global.am5.percent`. Both were
  undefined, so `onAmReady()` always returned `false`, `MpeliCharts.ready` stayed `false`, and
  `MpeliCharts.am5` was never set.
- → **Dashboard:** `loadDashboard` saw `MpeliCharts?.am5` as falsy and fell back to the old
  `renderBarChart` SVG path.
- → **BI:** every `render*()` function started with `if (!M.am5.xy) return null;`, so they returned
  `null` after the loading spinner was shown → **stuck on "Loading…"**.

This also confirms the earlier "module-graph mismatch" theory was a red herring: the installed
`amcharts/*` files are the correct, consistent, official UMD build. The failure was purely in
our JS calling convention.

---

## 3. Fixes (smallest safe changes; no data/library changes)

### `assets/js/chart-utils.js`
- `MpeliCharts` now carries `am5`, `am5xy`, `am5percent` plus the `ready` flag.
- `onAmReady()` now gates on `global.am5 && global.am5xy` and populates
  `MpeliCharts.am5xy = global.am5xy` and `MpeliCharts.am5percent = global.am5percent`.

### `assets/js/dashboard-charts.js`
- `renderColumnChart` guard: `!M.am5.xy` → `!M.am5xy`; namespace source `xy = M.am5xy`.
- Latent (previously masked) bugs that surfaced once amCharts actually loaded:
  - `yAxis.get("baseAxis").get(...)` → `yAxis.get("baseAxis")?.get(...)` (undefined `baseAxis`).
  - `am5.Media.new(...)` guarded — the Media module isn't loaded; it was dead/no-op code anyway.

### `assets/js/business-analysis-charts.js`
- All `M.am5.xy` guards → `M.am5xy`; `xy = M.am5xy` everywhere.
- Latent bugs surfaced:
  - `xy.Scrollbar.new(...)` guarded — the Scrollbar add-on isn't loaded (optional).
  - `am5.color(p.surface)` → `am5.color(p.border || …)` — `surface` is not in the palette.

### `assets/js/script.js`
- Dashboard render now prefers amCharts via `onReady()` fallback instead of an immediate
  synchronous SVG fallback, so the old SVG path is only used if amCharts truly never loads.

No `index.php` script order change was needed (already correct:
`index.js → xy.js → percent.js → Animated.js → chart-utils.js → dashboard-charts.js →
business-analysis-charts.js → script.js`), and `?v=` is a per-request `time()` so cache is
always busted. No CSP changes, no library swaps, no DB/data/schema changes.

---

## 4. Local verification (all passed)

- `node --check` passes on all four edited JS files.
- No remaining stale `am5.xy` / `M.am5.xy` references.
- Headless Chrome (same script order as `index.php`), with real data shapes:
  - `MpeliCharts.ready = true`, `am5xy`/`am5percent` populated.
  - **Dashboard** column chart: `RESULT SIMULATION SUCCESS`.
  - **BI** `sales_trend`, `profit_trend`, `seller_ranking`: all returned a built chart (`OK`).
- Real `index.php` loads with no JavaScript errors (only benign DOM warnings on the login page).

---

## 5. Production readiness verdict

**READY to deploy after review.** The change is confined to 4 client-side JS files and is
backwards-safe:
- It only corrects the API call convention to match amCharts 5's script-tag globals.
- No business calculations, DB schema, data, auth, or CSP were modified.
- Any residual risk (e.g., the optional Scrollbar/Media add-ons not being loaded) is now guarded
  and degrades gracefully rather than crashing.

**Deployment was intentionally NOT performed.** A human should review, run the app's own local
tests, and deploy to production on their own timeline. Pushing to the live store is deliberately
out of scope for this diagnosis.

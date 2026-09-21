/* ═══════════════════════════════════════════════════════════════════════════
 * Mpeli Outfit Store — Dashboard amCharts 5
 *
 * Converts the existing SVG Dashboard charts (Sales Analytics + Profit Trend)
 * to interactive, animated amCharts 5 charts. Real data flows from
 * api/dashboard.php (revenue_chart / profit_chart).
 * ═══════════════════════════════════════════════════════════════════════════ */
(function (global) {
  "use strict";

  const M = global.MpeliCharts;

  function dayName(dateStr) {
    const d = new Date(dateStr + "T00:00:00Z");
    return Number.isNaN(d.getTime()) ? dateStr : d.toLocaleDateString(undefined, { weekday: "short" });
  }

  function shortDate(dateStr) {
    const d = new Date(dateStr + "T00:00:00Z");
    if (Number.isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
  }

  // ── Column/Area chart for the Dashboard revenue + profit ──────────────────
  // Uses a category X-axis with day names under the bars (preserving the
  // existing "day names under bars" requirement) and a value axis formatted
  // in TSh. Column chart keeps financial values scannable.
  function renderColumnChart(container, data, opts) {
    if (!container || !M.am5 || !M.am5xy || !data || !data.length) return null;
    const { root } = M.safeRoot(container, ["xy"]);
    if (!root) return null;

    const am5 = M.am5, xy = M.am5xy;
    const p = M.palette();

    // Prepare display values (day name + full date for the tooltip).
    const rows = data.map(function (d) {
      const day = d.sale_day || d.date;
      return {
        day: dayName(day),
        date: shortDate(day),
        value: Number(d.value) || Number(d[opts.valueKey] || 0) || 0,
      };
    });

    const chart = root.container.children.push(xy.XYChart.new(root, {
      layout: root.verticalLayout,
      paddingTop: 12,
      paddingRight: 10,
      paddingBottom: 16,
      paddingLeft: 2,
      maxTooltipDistance: 0,
    }));

    const xAxis = chart.xAxes.push(xy.CategoryAxis.new(root, {
      categoryField: "day",
      renderer: xy.AxisRendererX.new(root, {
        minGridDistance: 20,
        cellStartLocation: 0.2,
        cellEndLocation: 0.8,
      }),
      tooltip: am5.Tooltip.new(root, {}),
    }));
    xAxis.get("renderer").labels.template.setAll({
      fontSize: 11,
      fill: am5.color(p.textSecondary),
      fontFamily: "Inter, sans-serif",
    });
    xAxis.get("renderer").grid.template.set("visible", false);
    xAxis.data.setAll(rows);

    const yAxis = chart.yAxes.push(xy.ValueAxis.new(root, {
      renderer: xy.AxisRendererY.new(root, {}),
      numberFormat: "#,##a",
      min: 0,
      extraTooltipPrecision: 0,
    }));
    yAxis.get("renderer").labels.template.setAll({
      fontSize: 11,
      fill: am5.color(p.textSecondary),
      fontFamily: "Inter, sans-serif",
    });
    yAxis.get("baseAxis")?.get("renderer")?.grid?.template.setAll({ strokeOpacity: 0.15 });
    yAxis.get("renderer").minGridDistance = 30;
    yAxis.get("renderer").grid.template.setAll({ stroke: am5.color(p.border), strokeOpacity: 0.5 });

    const color = opts.color || p.gold;

    const series = chart.series.push(xy.ColumnSeries.new(root, {
      name: opts.name || "Value",
      xAxis: xAxis,
      yAxis: yAxis,
      valueYField: "value",
      categoryXField: "day",
      tooltip: am5.Tooltip.new(root, {
        labelText: "{date}\nRevenue: [bold]{valueY}[/]",
        backgroundFill: am5.color("#11100e"),
        backgroundFillOpacity: 0.94,
        labelFill: am5.color("#ffffff"),
      }),
      sequencedInterpolation: true,
      pullDuration: 300,
      animateOnChange: true,
    }));

    series.columns.template.setAll({
      fill: am5.color(color),
      stroke: am5.color(color),
      cornerRadius: 5,
      maxWidth: 46,
      fillOpacity: 0.9,
    });
    series.columns.template.states.create("hover", { fill: am5.color(color), fillOpacity: 1 });
    series.columns.template.events.on("pointerover", function (ev) {
      ev.target.set("brightness", 0.08);
    });
    series.columns.template.events.on("pointerout", function (ev) {
      ev.target.set("brightness", 0);
    });
    series.data.setAll(rows);

    // Value labels just above each column (mobile-friendly, compact).
    const labelBullet = series.bullets.push(function () {
      return am5.Bullet.new(root, {
        sprite: am5.Label.new(root, {
          text: "{valueY.formatNumber('#,##a')}",
          fill: am5.color(p.textSecondary),
          fontSize: 10,
          centerX: am5.percent(50),
          centerY: am5.percent(100),
          dy: -7,
          populateText: true,
        }),
      });
    });

    // Hover cursor.
    chart.set("cursor", xy.XYCursor.new(root, {
      behavior: "none",
      xAxis: xAxis,
      yAxis: yAxis,
    }));

    // Responsive: hide inline value labels on smaller screens to declutter.
    // am5.Media is only present when the separate media.js module is loaded;
    // guard it so charts still render without that optional module.
    try {
      if (am5.Media) am5.Media.new(root);
    } catch (e) {}

    return chart;
  }

  // ── Public renderer used by script.js ─────────────────────────────────────
  function renderDashboardCharts(payload) {
    const revenueContainer = document.querySelector(".revenue-chart");
    const profitContainer = document.querySelector(".profit-chart");

    if (revenueContainer) {
      const has = payload.has_revenue_chart && (payload.revenue_chart || []).length;
      if (!has) {
        M.disposeRoot(revenueContainer);
        M.showChartEmpty(revenueContainer, "No sales data available yet.");
      } else {
        renderColumnChart(revenueContainer, payload.revenue_chart, {
          name: "Revenue",
          valueKey: "value",
          color: M.cssVar("--gold", "#c9a24e"),
        });
      }
    }

    if (profitContainer) {
      const has = payload.has_profit_chart && (payload.profit_chart || []).length;
      if (!has) {
        M.disposeRoot(profitContainer);
        M.showChartEmpty(profitContainer, "No sales data available yet.");
      } else {
        renderColumnChart(profitContainer, payload.profit_chart, {
          name: "Profit",
          valueKey: "value",
          color: M.cssVar("--success", "#2d7c59"),
        });
      }
    }
  }

  // ── Minimal showcase so amCharts always initializes on the page even if the
  //    chart JS files load independently of script.js. ───────────────────────
  global.MpeliDashboardCharts = {
    render: renderDashboardCharts,
    renderColumn: renderColumnChart,
  };
})(window);

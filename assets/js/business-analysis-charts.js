/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
 * Mpeli Outfit Store â€” Business Analysis amCharts 5
 *
 * Builds the amCharts 5 visualizations for Business Analytics:
 *   VIEW 1 (Performance Overview): Sales/Revenue Trend, Profit vs Expenses Trend
 *   VIEW 2 (Performance Breakdown): Seller ranking, Product ranking
 * Plus the per-seller and per-product trend charts.
 *
 * All data is real, returned by api/analytics.php.
 * â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
(function (global) {
  "use strict";

  const M = global.MpeliCharts;

  // â”€â”€ Date parsing helpers (handles "YYYY-MM-DD" and "YYYY-MM") â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function parseDay(value) {
    if (!value) return null;
    const s = String(value);
    const parts = s.split("-");
    if (parts.length >= 3) return new Date(s + "T00:00:00Z");
    if (parts.length === 2) return new Date(s + "-01T00:00:00Z");
    const d = new Date(s + "T00:00:00Z");
    return Number.isNaN(d.getTime()) ? null : d;
  }

  function fmtTooltipDate(date) {
    if (!date) return "";
    return date.toLocaleDateString(undefined, { month: "short", day: "numeric", year: date.getFullYear() !== new Date().getFullYear() ? "numeric" : undefined });
  }

  function buildDateRows(trend, dateKey) {
    if (!trend || !trend.length) return [];
    return trend
      .map(function (d) {
        const dt = parseDay(d[dateKey] || d.date || d.sale_day);
        if (!dt) return null;
        return {
          date: dt.getTime(),
          revenue: Number(d.revenue) || 0,
          gross_profit: Number(d.gross_profit) || 0,
          net_profit: Number(d.net_profit) || 0,
          expenses: Number(d.expenses) || 0,
          transactions: Number(d.transactions) || 0,
          items_sold: Number(d.items_sold) || 0,
        };
      })
      .filter(Boolean);
  }

  // â”€â”€ Shared XY line chart builder â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function makeXY(root, container) {
    const am5 = M.am5, xy = M.am5xy;
    const p = M.palette();

    const chart = root.container.children.push(xy.XYChart.new(root, {
      layout: root.verticalLayout,
      paddingTop: 12,
      paddingRight: 18,
      paddingBottom: 14,
      paddingLeft: 2,
      maxTooltipDistance: 8,
    }));

    const xAxis = chart.xAxes.push(xy.DateAxis.new(root, {
      baseInterval: baseIntervalFor(container),
      renderer: xy.AxisRendererX.new(root, {
        minGridDistance: 45,
        strokeOpacity: 0.2,
      }),
      tooltip: am5.Tooltip.new(root, {
        dateFormats: { day: "MMM d", week: "MMM d", month: "MMM yyyy" },
      }),
    }));
    xAxis.get("renderer").labels.template.setAll({
      fontSize: 11,
      fill: am5.color(p.textSecondary),
      fontFamily: "Inter, sans-serif",
    });
    xAxis.get("renderer").grid.template.set("visible", false);
    xAxis.get("renderer").ticks.template.set("visible", false);

    const yAxis = chart.yAxes.push(xy.ValueAxis.new(root, {
      renderer: xy.AxisRendererY.new(root, {}),
      min: 0,
      numberFormat: "#,##a",
      extraTooltipPrecision: 0,
    }));
    yAxis.get("renderer").labels.template.setAll({
      fontSize: 11,
      fill: am5.color(p.textSecondary),
      fontFamily: "Inter, sans-serif",
    });
    yAxis.get("renderer").grid.template.setAll({ stroke: am5.color(p.border), strokeOpacity: 0.5 });
    yAxis.get("renderer").minGridDistance = 30;

    return { chart, xAxis, yAxis };
  }

  function baseIntervalFor(container) {
    // Rows may be daily, weekly or monthly depending on range length. Let
    // amCharts decide from the data span; an explicit day base works for all
    // since DateAxis aggregates by display granularity automatically.
    return { timeUnit: "day", count: 1 };
  }

  function addLineSeries({ root, chart, xAxis, yAxis, field, name, color, width, dash, area }) {
    const am5 = M.am5, xy = M.am5xy;
    const p = M.palette();
    const series = chart.series.push(xy.LineSeries.new(root, {
      name: name,
      xAxis: xAxis,
      yAxis: yAxis,
      valueYField: field,
      connect: true,
      stroke: am5.color(color),
      tooltip: am5.Tooltip.new(root, {
        labelText: "{name}: TSh [bold]{valueY}[/]",
        backgroundFill: am5.color("#11100e"),
        backgroundFillOpacity: 0.94,
        labelFill: am5.color("#ffffff"),
      }),
      tensionX: 0.75,
      tensionY: 0.75,
      animateOnChange: true,
    }));
    series.strokes.template.setAll({
      strokeWidth: width || 2.5,
      strokeDasharray: dash || undefined,
      strokeLinecap: "round",
    });
    if (area) {
      series.fills.template.setAll({
        fill: am5.color(color),
        fillOpacity: 0.12,
        visible: true,
      });
    }
    series.set("sequencedInterpolation", true);
    series.bullets.push(function () {
      return am5.Bullet.new(root, {
        sprite: am5.Circle.new(root, {
          radius: 3.5,
          fill: am5.color(color),
          stroke: am5.color(p.border || "#e9e4da"),
          strokeWidth: 1,
          visible: false,
        }),
      });
    });
    return series;
  }

  // â”€â”€ Sales & Revenue Trend (line: revenue, dashed: sales count) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function renderSalesTrend(container, trend) {
    if (!container || !M.am5 || !M.am5xy) return null;
    const rows = buildDateRows(trend, "sale_day");
    const { root } = M.safeRoot(container, ["xy"]);
    if (!root) return null;

    const am5 = M.am5, xy = M.am5xy;
    const p = M.palette();
    const { chart, xAxis, yAxis } = makeXY(root, container);
    xAxis.data.setAll(rows);
    yAxis.data.setAll(rows);

    const revenue = addLineSeries({ root, chart, xAxis, yAxis, field: "revenue", name: "Revenue", color: M.cssVar("--gold", "#c9a24e"), width: 3, area: false });
    // Sales count on a secondary axis (0..max transactions) so both are readable.
    const y2 = chart.yAxes.push(xy.ValueAxis.new(root, {
      renderer: xy.AxisRendererY.new(root, { opposite: true }),
      min: 0,
      numberFormat: "#",
      extraTooltipPrecision: 0,
    }));
    y2.get("renderer").labels.template.setAll({ fontSize: 11, fill: am5.color(p.textSecondary), fontFamily: "Inter, sans-serif" });
    y2.get("renderer").grid.template.set("visible", false);
    const salesCount = chart.series.push(xy.LineSeries.new(root, {
      name: "Sales Count",
      xAxis: xAxis,
      yAxis: y2,
      valueYField: "transactions",
      connect: true,
      stroke: am5.color(M.cssVar("--success", "#2d7c59")),
      strokeDasharray: [5, 3],
      tensionX: 0.75,
      tensionY: 0.75,
      tooltip: am5.Tooltip.new(root, {
        labelText: "{name}: {valueY}",
        backgroundFill: am5.color("#11100e"),
        backgroundFillOpacity: 0.94,
        labelFill: am5.color("#ffffff"),
      }),
      animateOnChange: true,
    }));
    salesCount.strokes.template.setAll({ strokeWidth: 2 });
    salesCount.set("sequencedInterpolation", true);

    revenue.data.setAll(rows);
    salesCount.data.setAll(rows);

    // Cursor
    const cursor = xy.XYCursor.new(root, { behavior: "none", xAxis: xAxis, yAxis: yAxis });
    chart.set("cursor", cursor);

    // Legend
    chart.children.push(am5.Legend.new(root, {
      x: am5.percent(100),
      dx: -8,
      centerX: am5.percent(100),
      y: am5.percent(100),
      dy: -6,
      layout: root.horizontalLayout,
    }));

    // Scrollbar requires the scrollbar.js add-on; guard so charts still render without it.
    if (xy.Scrollbar) {
      try {
        chart.set("scrollbarX", xy.Scrollbar.new(root, { orientation: "horizontal", height: 8 }));
        chart.get("scrollbarX")?.get("startGrip")?.setAll({ visible: false });
        chart.get("scrollbarX")?.get("endGrip")?.setAll({ visible: false });
        chart.children.push(chart.get("scrollbarX"));
      } catch (e) {}
    }

    return chart;
  }

  // â”€â”€ Profit vs Expenses Trend (revenue, gross profit, net profit, expenses) â”€
  function renderProfitTrend(container, trend) {
    if (!container || !M.am5 || !M.am5xy) return null;
    const rows = buildDateRows(trend, "date");
    const { root } = M.safeRoot(container, ["xy"]);
    if (!root) return null;

    const am5 = M.am5, xy = M.am5xy;
    const p = M.palette();
    const { chart, xAxis, yAxis } = makeXY(root, container);
    xAxis.data.setAll(rows);
    yAxis.data.setAll(rows);

    const seriesConfig = [
      { field: "revenue", name: "Revenue", color: M.cssVar("--gold", "#c9a24e"), width: 3, dashed: null },
      { field: "gross_profit", name: "Gross Profit", color: M.cssVar("--success", "#2d7c59"), width: 2.5, dashed: null },
      { field: "expenses", name: "Expenses", color: "#b07b3c", width: 2, dashed: null },
      { field: "net_profit", name: "Net Profit", color: M.cssVar("--danger", "#b84c43"), width: 2.5, dashed: [6, 4] },
    ];
    seriesConfig.forEach(function (c) {
      addLineSeries({ root, chart, xAxis, yAxis, field: c.field, name: c.name, color: c.color, width: c.width, dash: c.dashed, area: false })
        .data.setAll(rows);
    });

    const cursor = xy.XYCursor.new(root, { behavior: "none", xAxis: xAxis, yAxis: yAxis });
    chart.set("cursor", cursor);

    const legend = am5.Legend.new(root, {
      x: am5.percent(100),
      dx: -8,
      centerX: am5.percent(100),
      y: am5.percent(100),
      dy: -6,
      layout: root.horizontalLayout,
    });
    legend.data.setAll(chart.series.values);
    chart.children.push(legend);

    // Scrollbar requires the scrollbar.js add-on; guard so charts still render without it.
    if (xy.Scrollbar) {
      try {
        chart.set("scrollbarX", xy.Scrollbar.new(root, { orientation: "horizontal", height: 8 }));
        chart.get("scrollbarX")?.get("startGrip")?.setAll({ visible: false });
        chart.get("scrollbarX")?.get("endGrip")?.setAll({ visible: false });
        chart.children.push(chart.get("scrollbarX"));
      } catch (e) {}
    }

    return chart;
  }

  // â”€â”€ Horizontal bar chart (ranking: sellers / products) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function renderRanking(container, items, opts) {
    if (!container || !M.am5 || !M.am5xy) return null;
    if (!items || !items.length) {
      M.disposeRoot(container);
      M.showChartEmpty(container, opts.emptyMessage || "No data available for the selected period.");
      return null;
    }
    const { root } = M.safeRoot(container, ["xy"]);
    if (!root) return null;

    const am5 = M.am5, xy = M.am5xy;
    const p = M.palette();
    const limit = opts.limit || 10;
    const rows = items.slice(0, limit).map(function (it) {
      return {
        label: it[opts.nameField] || "",
        value: Number(it[opts.valueField]) || 0,
        meta: it,
      };
    });

    const chart = root.container.children.push(xy.XYChart.new(root, {
      layout: root.verticalLayout,
      paddingTop: 8,
      paddingRight: 18,
      paddingBottom: 12,
      paddingLeft: 2,
    }));

    // Horizontal: value on X, category (name) on Y.
    const yAxis = chart.yAxes.push(xy.CategoryAxis.new(root, {
      categoryField: "label",
      renderer: xy.AxisRendererY.new(root, {
        minGridDistance: 6,
        cellStartLocation: 0.15,
        cellEndLocation: 0.85,
        inversed: true,
      }),
      tooltip: am5.Tooltip.new(root, {}),
    }));
    yAxis.get("renderer").labels.template.setAll({
      fontSize: 11,
      fill: am5.color(p.text),
      fontFamily: "Inter, sans-serif",
      maxWidth: 130,
      oversizedBehavior: "truncate",
      centerX: am5.p100,
      dx: -8,
    });
    yAxis.get("renderer").grid.template.set("visible", false);
    yAxis.data.setAll(rows);

    const xAxis = chart.xAxes.push(xy.ValueAxis.new(root, {
      renderer: xy.AxisRendererX.new(root, {}),
      min: 0,
      numberFormat: "#,##a",
    }));
    xAxis.get("renderer").labels.template.setAll({
      fontSize: 11,
      fill: am5.color(p.textSecondary),
      fontFamily: "Inter, sans-serif",
    });
    xAxis.get("renderer").grid.template.setAll({ stroke: am5.color(p.border), strokeOpacity: 0.5 });

    const color = opts.color || M.cssVar("--gold", "#c9a24e");
    const series = chart.series.push(xy.ColumnSeries.new(root, {
      name: opts.name || "Value",
      xAxis: xAxis,
      yAxis: yAxis,
      valueXField: "value",
      categoryYField: "label",
      tooltip: am5.Tooltip.new(root, {
        labelText: "{categoryY}: {valueX}",
        backgroundFill: am5.color("#11100e"),
        backgroundFillOpacity: 0.94,
        labelFill: am5.color("#ffffff"),
      }),
      sequencedInterpolation: true,
      animateOnChange: true,
    }));
    series.columns.template.setAll({
      fill: am5.color(color),
      stroke: am5.color(color),
      cornerRadius: 5,
      maxHeight: 26,
      fillOpacity: 0.92,
    });
    series.columns.template.states.create("hover", { fill: am5.color(color), fillOpacity: 1 });
    series.data.setAll(rows);

    // Value labels at the ends of each bar.
    series.bullets.push(function () {
      return am5.Bullet.new(root, {
        sprite: am5.Label.new(root, {
          text: "{valueX.formatNumber('#,##a')}",
          fill: am5.color(p.textSecondary),
          fontSize: 10,
          centerY: am5.percent(50),
          dx: 6,
          populateText: true,
        }),
      });
    });

    // Hover cursor (polar for value axis).
    chart.set("cursor", xy.XYCursor.new(root, { behavior: "none", xAxis: xAxis, yAxis: yAxis }));

    return chart;
  }

  // â”€â”€ Seller trend (revenue + net profit) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function renderSellerTrend(container, trend) {
    if (!container || !M.am5 || !M.am5xy) return null;
    const rows = buildDateRows(trend, "date");
    const { root } = M.safeRoot(container, ["xy"]);
    if (!root) return null;
    const am5 = M.am5, xy = M.am5xy;
    const p = M.palette();
    const { chart, xAxis, yAxis } = makeXY(root, container);
    xAxis.data.setAll(rows); yAxis.data.setAll(rows);
    addLineSeries({ root, chart, xAxis, yAxis, field: "revenue", name: "Revenue", color: M.cssVar("--gold", "#c9a24e"), width: 3 }).data.setAll(rows);
    addLineSeries({ root, chart, xAxis, yAxis, field: "net_profit", name: "Net Profit", color: M.cssVar("--success", "#2d7c59"), width: 2.5 }).data.setAll(rows);
    chart.set("cursor", xy.XYCursor.new(root, { behavior: "none", xAxis: xAxis, yAxis: yAxis }));
    return chart;
  }

  // â”€â”€ Product trend (revenue + gross profit) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function renderProductTrend(container, trend) {
    if (!container || !M.am5 || !M.am5xy) return null;
    const rows = buildDateRows(trend, "sale_day");
    const { root } = M.safeRoot(container, ["xy"]);
    if (!root) return null;
    const am5 = M.am5, xy = M.am5xy;
    const p = M.palette();
    const { chart, xAxis, yAxis } = makeXY(root, container);
    xAxis.data.setAll(rows); yAxis.data.setAll(rows);
    addLineSeries({ root, chart, xAxis, yAxis, field: "revenue", name: "Revenue", color: M.cssVar("--gold", "#c9a24e"), width: 3 }).data.setAll(rows);
    addLineSeries({ root, chart, xAxis, yAxis, field: "gross_profit", name: "Gross Profit", color: M.cssVar("--success", "#2d7c59"), width: 2.5 }).data.setAll(rows);
    chart.set("cursor", xy.XYCursor.new(root, { behavior: "none", xAxis: xAxis, yAxis: yAxis }));
    return chart;
  }

  // â”€â”€ Ranking charts (reuse the shared horizontal-bar renderer) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function renderSellerRanking(container, sellers, opts) {
    return renderRanking(container, sellers, opts);
  }

  function renderProductRanking(container, products, opts) {
    return renderRanking(container, products, opts);
  }

  // â”€â”€ Reports: Monthly Revenue chart â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  // Renders the monthly sales chart on the Reports page using amCharts 5.
  // Input rows: [{ report_month: "YYYY-MM", revenue: number }].
  function renderReportsChart(container, rows, hasData) {
    if (!container || !M.am5 || !M.am5xy) return null;
    if (!hasData || !rows || !rows.length) {
      M.disposeRoot(container);
      M.showChartEmpty(container, "No sales data available yet.");
      return null;
    }
    const { root } = M.safeRoot(container, ["xy"]);
    if (!root) return null;

    const am5 = M.am5, xy = M.am5xy;
    const p = M.palette();

    // Normalize month keys into display-able rows.
    const data = rows.map(function (d) {
      const raw = d.report_month || d.date || "";
      const label = String(raw);
      return {
        month: label,
        value: Number(d.revenue) || Number(d.value) || 0,
      };
    });

    const chart = root.container.children.push(xy.XYChart.new(root, {
      layout: root.verticalLayout,
      paddingTop: 10,
      paddingRight: 18,
      paddingBottom: 14,
      paddingLeft: 2,
      maxTooltipDistance: 8,
    }));

    const xAxis = chart.xAxes.push(xy.CategoryAxis.new(root, {
      categoryField: "month",
      renderer: xy.AxisRendererX.new(root, {
        minGridDistance: 32,
        cellStartLocation: 0.22,
        cellEndLocation: 0.78,
      }),
      tooltip: am5.Tooltip.new(root, {}),
    }));
    xAxis.get("renderer").labels.template.setAll({ fontSize: 11, fill: am5.color(p.textSecondary), fontFamily: "Inter, sans-serif" });
    xAxis.get("renderer").grid.template.set("visible", false);
    xAxis.data.setAll(data);

    const yAxis = chart.yAxes.push(xy.ValueAxis.new(root, {
      renderer: xy.AxisRendererY.new(root, {}),
      min: 0,
      numberFormat: "#,##a",
      extraTooltipPrecision: 0,
    }));
    yAxis.get("renderer").labels.template.setAll({ fontSize: 11, fill: am5.color(p.textSecondary), fontFamily: "Inter, sans-serif" });
    yAxis.get("renderer").grid.template.setAll({ stroke: am5.color(p.border), strokeOpacity: 0.5 });
    yAxis.get("renderer").minGridDistance = 30;

    const color = M.cssVar("--gold", "#c9a24e");
    const series = chart.series.push(xy.ColumnSeries.new(root, {
      name: "Revenue",
      xAxis: xAxis,
      yAxis: yAxis,
      valueYField: "value",
      categoryXField: "month",
      tooltip: am5.Tooltip.new(root, {
        labelText: "{month}\nRevenue: [bold]{valueY}[/]",
        backgroundFill: am5.color("#11100e"),
        backgroundFillOpacity: 0.94,
        labelFill: am5.color("#ffffff"),
      }),
      sequencedInterpolation: true,
      animateOnChange: true,
    }));
    series.columns.template.setAll({ fill: am5.color(color), stroke: am5.color(color), cornerRadius: 5, maxWidth: 46, fillOpacity: 0.9 });
    series.columns.template.states.create("hover", { fill: am5.color(color), fillOpacity: 1 });
    series.data.setAll(data);

    series.bullets.push(function () {
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

    chart.set("cursor", xy.XYCursor.new(root, { behavior: "none", xAxis: xAxis, yAxis: yAxis }));

    return chart;
  }

  // â”€â”€ Public API â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  global.MpeliBusinessCharts = {
    renderSalesTrend: renderSalesTrend,
    renderProfitTrend: renderProfitTrend,
    renderSellerRanking: renderSellerRanking,
    renderProductRanking: renderProductRanking,
    renderSellerTrend: renderSellerTrend,
    renderProductTrend: renderProductTrend,
    renderReportsChart: renderReportsChart,
  };
})(window);

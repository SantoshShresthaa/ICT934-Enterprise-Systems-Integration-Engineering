<!DOCTYPE html>
<!--
  index.php
  ---------------------------------------------------------------------
  Web interface for the CRM Data Warehouse query engine.
  Calls queryEngine.php via fetch() for each report/analysis and renders
  the JSON response as summary cards and a sortable, searchable table.
  ---------------------------------------------------------------------
-->
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CRM Sales Insights</title>
<style>
  :root {
    --bg: #f4f6fb;
    --surface: #ffffff;
    --border: #e3e8f0;
    --border-soft: #f1f5f9;
    --text: #0f172a;
    --muted: #64748b;
    --primary: #4f46e5;
    --primary-dark: #4338ca;
    --primary-soft: #eef2ff;
    --success: #15803d;
    --success-soft: #dcfce7;
    --danger: #b91c1c;
    --danger-soft: #fee2e2;
    --radius: 12px;
    --shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 4px 12px rgba(15, 23, 42, .05);
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
  }

  /* Layout */
  .app { display: grid; grid-template-columns: 248px 1fr; min-height: 100vh; }
  .sidebar { background: #0f172a; color: #cbd5e1; padding: 24px 16px; position: sticky; top: 0; height: 100vh; }
  .brand { display: flex; gap: 12px; align-items: center; color: #fff; font-weight: 700; font-size: 16px; padding: 0 8px 28px; }
  .brand-mark { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #6366f1, #22d3ee); display: grid; place-items: center; }
  .brand-mark svg { width: 20px; height: 20px; }
  .brand small { display: block; font-weight: 500; color: #94a3b8; font-size: 12px; }
  .nav-label { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #64748b; padding: 0 12px 8px; }
  .nav button {
    display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 10px 12px; margin-bottom: 4px; border: 0; border-radius: 8px;
    background: transparent; color: #cbd5e1; font: inherit; text-align: left; cursor: pointer;
  }
  .nav button:hover { background: rgba(255, 255, 255, .06); color: #fff; }
  .nav button[aria-current="page"] { background: rgba(99, 102, 241, .22); color: #fff; }
  .nav svg { width: 18px; height: 18px; flex: none; }
  .main { padding: 28px 32px 48px; min-width: 0; }

  /* Page header */
  .page-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
  .page-header h1 { margin: 0; font-size: 22px; letter-spacing: -.01em; }
  .page-header p { margin: 4px 0 0; color: var(--muted); }
  .meta { color: var(--muted); font-size: 12px; }

  /* Cards */
  .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); }

  /* Filters toolbar */
  .toolbar { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; padding: 16px; margin-bottom: 20px; }
  .toolbar .spacer { flex: 1; }
  .toolbar .hint { color: var(--muted); align-self: center; }
  .field { display: flex; flex-direction: column; gap: 4px; }
  .field label { font-size: 12px; font-weight: 600; color: var(--muted); }
  .field select, .field input, .search input {
    height: 36px; padding: 0 10px; border: 1px solid var(--border); border-radius: 8px;
    font: inherit; background: #fff; color: var(--text); min-width: 150px;
  }
  .field select:focus, .field input:focus, .search input:focus, .btn:focus-visible, .nav button:focus-visible, th button:focus-visible {
    outline: 2px solid var(--primary); outline-offset: 1px;
  }
  .btn {
    height: 36px; padding: 0 14px; border-radius: 8px; border: 1px solid var(--border);
    background: #fff; color: var(--text); font: inherit; font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .btn:hover { background: #f8fafc; }
  .btn svg { width: 16px; height: 16px; }
  .btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
  .btn-primary:hover { background: var(--primary-dark); }
  .btn:disabled { opacity: .5; cursor: not-allowed; }

  /* KPI cards */
  .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 20px; }
  .kpi { padding: 16px 18px; }
  .kpi-label { font-size: 12px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
  .kpi-value { font-size: 24px; font-weight: 700; margin-top: 6px; letter-spacing: -.02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .kpi-sub { font-size: 12px; color: var(--muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

  /* Results table */
  .table-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
  .table-header h2 { margin: 0; font-size: 15px; }
  .table-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .search { position: relative; }
  .search svg { position: absolute; left: 10px; top: 10px; width: 16px; height: 16px; color: var(--muted); pointer-events: none; }
  .search input { padding-left: 32px; min-width: 240px; }
  .table-wrap { overflow: auto; max-height: 560px; }
  table { width: 100%; border-collapse: separate; border-spacing: 0; }
  th {
    position: sticky; top: 0; z-index: 1; background: #f8fafc; padding: 0;
    font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted);
    text-align: left; border-bottom: 1px solid var(--border); white-space: nowrap;
  }
  th button { all: unset; box-sizing: border-box; display: flex; align-items: center; gap: 4px; width: 100%; padding: 10px 14px; cursor: pointer; }
  th button:hover { color: var(--text); }
  th.num button { justify-content: flex-end; }
  th .arrow { opacity: .35; font-size: 10px; }
  th[aria-sort] .arrow { opacity: 1; color: var(--primary); }
  td { padding: 10px 14px; border-bottom: 1px solid var(--border-soft); white-space: nowrap; }
  tbody tr:hover td { background: #f8fafc; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; color: #334155; }
  .strong { font-weight: 600; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .badge-won { background: var(--success-soft); color: var(--success); }
  .badge-lost { background: var(--danger-soft); color: var(--danger); }
  .chip { display: inline-block; padding: 2px 8px; border-radius: 6px; background: var(--primary-soft); color: var(--primary-dark); font-size: 12px; font-weight: 600; }
  .bar-cell { display: flex; align-items: center; justify-content: flex-end; gap: 12px; }
  .bar { width: 120px; height: 6px; border-radius: 3px; background: #e0e7ff; overflow: hidden; flex: none; }
  .bar span { display: block; height: 100%; background: var(--primary); border-radius: 3px; }
  .table-footer { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; padding: 12px 16px; color: var(--muted); font-size: 13px; }
  .pager { display: flex; align-items: center; gap: 8px; }

  /* States */
  .state { padding: 56px 16px; text-align: center; color: var(--muted); }
  .state strong { display: block; color: var(--text); font-size: 15px; margin-bottom: 4px; }
  .state.error strong { color: var(--danger); }
  .skeleton { height: 14px; border-radius: 6px; background: linear-gradient(90deg, #eef2f7 25%, #f8fafc 50%, #eef2f7 75%); background-size: 200% 100%; animation: shimmer 1.2s infinite; }
  .kpi .skeleton { height: 28px; margin-top: 8px; width: 70%; }
  @keyframes shimmer { from { background-position: 200% 0; } to { background-position: -200% 0; } }

  /* Responsive */
  @media (max-width: 1100px) { .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (max-width: 860px) {
    .app { grid-template-columns: 1fr; }
    .sidebar { position: static; height: auto; padding: 16px; }
    .brand { padding-bottom: 12px; }
    .nav-label { display: none; }
    .nav { display: flex; gap: 6px; overflow-x: auto; }
    .nav button { width: auto; white-space: nowrap; margin: 0; }
    .main { padding: 20px 16px 40px; }
    .search input { min-width: 0; width: 100%; }
  }
  @media (max-width: 520px) { .kpis { grid-template-columns: 1fr; } }

  /* Overview dashboard */
  .dash-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 16px; }
  .span-4 { grid-column: span 4; }
  .span-6 { grid-column: span 6; }
  .span-8 { grid-column: span 8; }
  .panel { padding: 16px 18px 18px; min-width: 0; }
  .panel-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
  .panel-head h2 { margin: 0; font-size: 15px; }
  .panel-head p { margin: 2px 0 0; color: var(--muted); font-size: 12.5px; }
  .panel-link { color: var(--primary); font-size: 12.5px; font-weight: 600; text-decoration: none; white-space: nowrap; }
  .panel-link:hover { text-decoration: underline; }
  .chart-box { position: relative; height: 280px; }
  .chart-box.tall { height: 320px; }
  .chart-box.short { height: 200px; }
  .is-loading .chart-box canvas { visibility: hidden; }
  .is-loading .chart-box::after {
    content: ""; position: absolute; inset: 0; border-radius: 8px;
    background: linear-gradient(90deg, #eef2f7 25%, #f8fafc 50%, #eef2f7 75%); background-size: 200% 100%; animation: shimmer 1.2s infinite;
  }
  .chart-box.no-chart canvas { display: none; }
  .chart-box.no-chart::before {
    content: "Chart unavailable: the Chart.js library could not be loaded (internet connection required).";
    position: absolute; inset: 0; display: grid; place-items: center; padding: 16px; text-align: center; color: var(--muted);
    border: 1px dashed var(--border); border-radius: 8px;
  }
  .panel-body .skeleton + .skeleton { margin-top: 12px; }

  .funnel-step + .funnel-step { margin-top: 14px; }
  .funnel-top { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; }
  .funnel-top strong { font-variant-numeric: tabular-nums; }
  .funnel-bar { height: 10px; border-radius: 5px; background: var(--border-soft); margin: 6px 0 4px; overflow: hidden; }
  .funnel-bar span { display: block; height: 100%; border-radius: 5px; background: linear-gradient(90deg, #6366f1, #22d3ee); }
  .funnel-step.won .funnel-bar span { background: linear-gradient(90deg, #16a34a, #4ade80); }
  .funnel-sub { font-size: 12px; color: var(--muted); }
  .panel-note { margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--border-soft); font-size: 12.5px; color: var(--muted); }

  .mini-table td, .mini-table th { padding: 8px 10px; }
  .mini-table th { position: static; padding: 8px 10px; }
  .mini-table .sub { display: block; font-size: 12px; color: var(--muted); font-weight: 400; }
  .rank { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 6px; background: var(--border-soft); font-size: 12px; font-weight: 700; color: var(--muted); }
  .rank.top { background: var(--primary-soft); color: var(--primary-dark); }

  .region-stats { margin-top: 12px; }
  .region-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 13px; }
  .region-row + .region-row { border-top: 1px solid var(--border-soft); }
  .region-row .dot { width: 10px; height: 10px; border-radius: 3px; flex: none; }
  .region-row .name { font-weight: 600; flex: 1; }
  .region-row .muted { color: var(--muted); font-size: 12px; }

  .panel .table-wrap { max-height: none; }
  .dq-status { display: inline-block; white-space: nowrap; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .dq-status.ok { background: var(--success-soft); color: var(--success); }
  .dq-status.warn { background: #fef3c7; color: #b45309; }
  .dq-list { list-style: none; margin: 0; padding: 0; }
  .dq-list li { display: flex; align-items: center; gap: 10px; padding: 8px 0; font-size: 13px; }
  .dq-list li + li { border-top: 1px solid var(--border-soft); }
  .dq-list .icon { width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; flex: none; font-size: 11px; font-weight: 700; }
  .dq-list .icon.ok { background: var(--success-soft); color: var(--success); }
  .dq-list .icon.info { background: var(--primary-soft); color: var(--primary-dark); }
  .dq-list .icon.warn { background: #fef3c7; color: #b45309; }
  .dq-list .label { flex: 1; }
  .dq-list .value { font-weight: 700; font-variant-numeric: tabular-nums; }

  @media (max-width: 1100px) { .span-4, .span-6, .span-8 { grid-column: span 12; } }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
        integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body>

<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/></svg>
      </div>
      <div>CRM Insights<small>Sales Data Warehouse</small></div>
    </div>
    <nav class="nav" id="nav" aria-label="Pages"></nav>
  </aside>

  <main class="main">
    <header class="page-header">
      <div>
        <h1 id="title"></h1>
        <p id="description"></p>
      </div>
      <div class="meta" id="generated"></div>
    </header>

    <section class="card toolbar" id="toolbar" aria-label="Filters"></section>

    <section class="kpis" id="kpis" aria-label="Summary"></section>

    <section id="dashboard" aria-label="Overview dashboard" hidden>
      <div class="card state error" id="dash-error" hidden></div>
      <div class="dash-grid" id="dash-grid">
        <article class="card panel span-8">
          <div class="panel-head">
            <div><h2>Monthly Won Revenue</h2><p>Revenue from won deals by close month, with the monthly win rate</p></div>
            <a class="panel-link" href="#opportunities">View report →</a>
          </div>
          <div class="chart-box"><canvas id="chart-monthly" role="img" aria-label="Bar chart of won revenue per month with a win rate line"></canvas></div>
        </article>

        <article class="card panel span-4">
          <div class="panel-head">
            <div><h2>Sales Funnel</h2><p>How far opportunities progress through the pipeline</p></div>
          </div>
          <div class="panel-body" id="funnel"></div>
        </article>

        <article class="card panel span-6">
          <div class="panel-head">
            <div><h2>Revenue by Product</h2><p>Total won value per product, coloured by series</p></div>
            <a class="panel-link" href="#analysis">View analysis →</a>
          </div>
          <div class="chart-box"><canvas id="chart-products" role="img" aria-label="Horizontal bar chart of won revenue by product"></canvas></div>
        </article>

        <article class="card panel span-6">
          <div class="panel-head">
            <div><h2>Won vs Lost by Product</h2><p>Closed deals per product; hover for the win rate</p></div>
            <a class="panel-link" href="#analysis">View analysis →</a>
          </div>
          <div class="chart-box"><canvas id="chart-winloss" role="img" aria-label="Stacked bar chart of won and lost deals by product"></canvas></div>
        </article>

        <article class="card panel span-8">
          <div class="panel-head">
            <div><h2>Revenue by Establishment Year</h2><p>Total annual revenue of client accounts and the number of accounts, by year founded</p></div>
            <a class="panel-link" href="#revenue">View report →</a>
          </div>
          <div class="chart-box tall"><canvas id="chart-years" role="img" aria-label="Bar chart of account revenue by establishment year"></canvas></div>
        </article>

        <article class="card panel span-4">
          <div class="panel-head">
            <div><h2>Regional Performance</h2><p>Share of won revenue by regional office</p></div>
          </div>
          <div class="chart-box short"><canvas id="chart-regions" role="img" aria-label="Doughnut chart of won revenue share by regional office"></canvas></div>
          <div class="region-stats" id="region-stats"></div>
        </article>

        <article class="card panel span-8">
          <div class="panel-head">
            <div><h2>Top Sales Agents</h2><p>Ranked by won revenue across all closed deals</p></div>
          </div>
          <div class="panel-body" id="top-agents"></div>
        </article>

        <article class="card panel span-4">
          <div class="panel-head">
            <div><h2>ETL Data Quality</h2><p>Raw staging data compared with the cleaned warehouse</p></div>
            <span id="dq-status"></span>
          </div>
          <div class="panel-body" id="data-quality"></div>
        </article>
      </div>
    </section>

    <section class="card" id="results" aria-labelledby="table-title">
      <div class="table-header">
        <div>
          <h2 id="table-title">Results</h2>
          <span class="meta" id="result-count"></span>
        </div>
        <div class="table-actions">
          <div class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="search" placeholder="Search results…" aria-label="Search results">
          </div>
          <button class="btn" id="export" type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
            Export CSV
          </button>
        </div>
      </div>
      <div class="table-wrap" id="table-wrap"></div>
      <div class="table-footer" id="footer"></div>
    </section>
  </main>
</div>

<script>
const PAGE_SIZE = 25;

const ICONS = {
  overview: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
  products: '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M3 11h18M9 7V3h6v4"/>',
  opportunities: '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
  revenue: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
  analysis: '<path d="M12 20V10M18 20V4M6 20v-4"/>',
};

const STAGE_FILTER = {
  key: 'deal_stage', label: 'Deal stage', type: 'select',
  options: [['', 'All (Won + Lost)'], ['Won', 'Won'], ['Lost', 'Lost']],
};

const REPORTS = {
  products: {
    action: 'products_report',
    title: 'Products Report',
    description: 'Complete product catalogue with series and list price.',
    filters: [],
    columns: [
      { key: 'product', label: 'Product', type: 'strong' },
      { key: 'series', label: 'Series', type: 'chip' },
      { key: 'sales_price', label: 'Sales Price', type: 'currency' },
    ],
    kpis(rows) {
      const prices = rows.map(r => Number(r.sales_price));
      const top = maxBy(rows, r => Number(r.sales_price));
      return [
        { label: 'Products', value: fmt.int(rows.length) },
        { label: 'Product Series', value: fmt.int(new Set(rows.map(r => r.series)).size) },
        { label: 'Average Price', value: fmt.currency(avg(prices)) },
        { label: 'Highest Price', value: top ? fmt.currency(top.sales_price) : '—', sub: top ? top.product : '' },
      ];
    },
  },

  opportunities: {
    action: 'sales_opportunities_report',
    title: 'Sales Opportunities',
    description: 'Won and lost opportunities with their value, product, account and sales agent.',
    filters: [STAGE_FILTER],
    columns: [
      { key: 'opportunity_id', label: 'Opportunity', type: 'mono' },
      { key: 'account', label: 'Account', type: 'strong' },
      { key: 'product', label: 'Product', type: 'text' },
      { key: 'series', label: 'Series', type: 'chip' },
      { key: 'sales_agent', label: 'Sales Agent', type: 'text' },
      { key: 'deal_stage', label: 'Stage', type: 'stage' },
      { key: 'engage_date', label: 'Engaged', type: 'date' },
      { key: 'close_date', label: 'Closed', type: 'date' },
      { key: 'close_value', label: 'Close Value', type: 'currency' },
    ],
    kpis(rows) {
      const won = rows.filter(r => r.deal_stage === 'Won');
      const lost = rows.length - won.length;
      return [
        { label: 'Opportunities', value: fmt.int(rows.length) },
        { label: 'Won', value: fmt.int(won.length), sub: `${fmt.int(lost)} lost` },
        { label: 'Win Rate', value: rows.length ? fmt.percent(won.length / rows.length) : '—' },
        { label: 'Won Revenue', value: fmt.currency(sum(won, r => r.close_value)) },
      ];
    },
  },

  revenue: {
    action: 'establishment_year_revenue',
    title: 'Establishment Year Revenue',
    description: 'Annual revenue of client accounts grouped by the year each company was established.',
    filters: [
      { key: 'year_from', label: 'Year from', type: 'number', placeholder: 'e.g. 1990' },
      { key: 'year_to', label: 'Year to', type: 'number', placeholder: 'e.g. 2015' },
    ],
    columns: [
      { key: 'year_established', label: 'Year Established', type: 'strong' },
      { key: 'number_of_accounts', label: 'Accounts', type: 'int' },
      { key: 'total_revenue', label: 'Total Revenue', type: 'bar', format: 'millions' },
      { key: 'average_revenue', label: 'Average Revenue', type: 'millions' },
    ],
    kpis(rows) {
      const peak = maxBy(rows, r => Number(r.total_revenue));
      return [
        { label: 'Years Covered', value: fmt.int(rows.length),
          sub: rows.length ? `${rows[0].year_established} – ${rows[rows.length - 1].year_established}` : '' },
        { label: 'Accounts', value: fmt.int(sum(rows, r => r.number_of_accounts)) },
        { label: 'Total Revenue', value: fmt.millions(sum(rows, r => r.total_revenue)), sub: 'Annual, USD' },
        { label: 'Peak Year', value: peak ? String(peak.year_established) : '—',
          sub: peak ? fmt.millions(peak.total_revenue) : '' },
      ];
    },
  },

  analysis: {
    action: 'sales_opportunity_analysis',
    title: 'Sales Opportunity Analysis',
    description: 'Total and average opportunity value segmented by product.',
    filters: [{ ...STAGE_FILTER, options: [['', 'All opportunities'], ['Won', 'Won'], ['Lost', 'Lost']] }],
    columns: [
      { key: 'product', label: 'Product', type: 'strong' },
      { key: 'series', label: 'Series', type: 'chip' },
      { key: 'number_of_opportunities', label: 'Opportunities', type: 'int' },
      { key: 'won_count', label: 'Won', type: 'int' },
      { key: 'lost_count', label: 'Lost', type: 'int' },
      { key: 'total_value', label: 'Total Value', type: 'bar', format: 'currency' },
      { key: 'average_value', label: 'Average Value', type: 'currency' },
    ],
    kpis(rows) {
      const opportunities = sum(rows, r => r.number_of_opportunities);
      const won = sum(rows, r => r.won_count);
      const top = maxBy(rows, r => Number(r.total_value));
      return [
        { label: 'Total Value', value: fmt.currency(sum(rows, r => r.total_value)) },
        { label: 'Opportunities', value: fmt.int(opportunities) },
        { label: 'Top Product', value: top && Number(top.total_value) > 0 ? top.product : '—',
          sub: top ? fmt.currency(top.total_value) : '' },
        { label: 'Win Rate', value: opportunities ? fmt.percent(won / opportunities) : '—',
          sub: `${fmt.int(won)} won` },
      ];
    },
  },
};

const OVERVIEW = {
  title: 'Overview',
  description: 'Headline sales performance, pipeline and data quality across the whole warehouse.',
};

const COLORS = {
  primary: '#4f46e5', primarySoft: 'rgba(79, 70, 229, .12)',
  cyan: '#06b6d4', amber: '#f59e0b', won: '#22c55e', lost: '#f87171',
};
const SERIES_COLORS = { GTX: COLORS.primary, MG: COLORS.cyan, GTK: COLORS.amber };
const REGION_COLORS = [COLORS.primary, COLORS.cyan, COLORS.amber, '#a855f7', '#64748b'];

const NUMERIC_TYPES = new Set(['currency', 'millions', 'int', 'bar']);

// ---------------------------------------------------------------------
// Formatting helpers
// ---------------------------------------------------------------------
const usd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 });
const dateFormat = new Intl.DateTimeFormat('en-AU', { day: '2-digit', month: 'short', year: 'numeric' });
const usdWhole = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
const compactNumber = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });
const monthFormat = new Intl.DateTimeFormat('en-AU', { month: 'short', year: 'numeric' });

const fmt = {
  currency: v => usd.format(Number(v) || 0),
  money: v => usdWhole.format(Number(v) || 0),
  compact: v => '$' + compactNumber.format(Number(v) || 0),
  month: v => monthFormat.format(new Date(v + '-01T00:00:00')),
  millions: v => '$' + (Number(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + 'M',
  int: v => (Number(v) || 0).toLocaleString('en-US'),
  percent: v => (v * 100).toFixed(1) + '%',
  date: v => v ? dateFormat.format(new Date(v + 'T00:00:00')) : '—',
};

const sum = (rows, pick) => rows.reduce((total, r) => total + (Number(pick(r)) || 0), 0);
const avg = values => values.length ? values.reduce((a, b) => a + b, 0) / values.length : 0;
const maxBy = (rows, pick) => rows.reduce((best, r) => (best === null || pick(r) > pick(best) ? r : best), null);

// Always escape before inserting into the DOM (defence in depth alongside
// the server-side JSON encoding in queryEngine.php).
function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

// ---------------------------------------------------------------------
// State
// ---------------------------------------------------------------------
const state = {
  report: 'overview',
  rows: [],
  filters: {},
  sort: null,
  search: '',
  page: 1,
  requestId: 0,
};

const $ = id => document.getElementById(id);

// ---------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------
function renderNav() {
  const button = (key, title) => `
    <button type="button" data-report="${key}" ${key === state.report ? 'aria-current="page"' : ''}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ICONS[key]}</svg>
      ${escapeHtml(title)}
    </button>`;
  $('nav').innerHTML = '<div class="nav-label">Dashboard</div>'
    + button('overview', OVERVIEW.title)
    + '<div class="nav-label" style="margin-top:16px">Reports &amp; Analysis</div>'
    + Object.entries(REPORTS).map(([key, report]) => button(key, report.title)).join('');
}

function renderToolbar() {
  if (state.report === 'overview') {
    $('toolbar').innerHTML = `<span class="hint">Live summary of the data warehouse. Open a report for the full detail.</span>
       <div class="spacer"></div>
       <button class="btn" type="button" id="apply">Refresh</button>`;
    return;
  }
  const report = REPORTS[state.report];
  const values = state.filters[state.report] || {};

  const fields = report.filters.map(f => {
    const value = values[f.key] ?? '';
    const control = f.type === 'select'
      ? `<select id="filter-${f.key}">${f.options.map(([v, label]) =>
          `<option value="${escapeHtml(v)}" ${v === value ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}</select>`
      : `<input id="filter-${f.key}" type="number" min="1800" max="${new Date().getFullYear()}"
           placeholder="${escapeHtml(f.placeholder || '')}" value="${escapeHtml(value)}">`;
    return `<div class="field"><label for="filter-${f.key}">${escapeHtml(f.label)}</label>${control}</div>`;
  }).join('');

  $('toolbar').innerHTML = report.filters.length
    ? `${fields}
       <button class="btn btn-primary" type="button" id="apply">Apply filters</button>
       <button class="btn" type="button" id="reset">Reset</button>
       <div class="spacer"></div>`
    : `<span class="hint">This report has no filters.</span>
       <div class="spacer"></div>
       <button class="btn" type="button" id="apply">Refresh</button>`;
}

function renderKpis(loading = false) {
  if (loading) {
    $('kpis').innerHTML = Array.from({ length: 4 }, () =>
      '<div class="card kpi"><div class="skeleton" style="width:40%;height:12px"></div><div class="skeleton"></div></div>').join('');
    return;
  }
  renderKpiCards(REPORTS[state.report].kpis(state.rows));
}

function renderKpiCards(kpis) {
  $('kpis').innerHTML = kpis.map(k => `
    <div class="card kpi">
      <div class="kpi-label">${escapeHtml(k.label)}</div>
      <div class="kpi-value" title="${escapeHtml(k.value)}">${escapeHtml(k.value)}</div>
      ${k.sub ? `<div class="kpi-sub">${escapeHtml(k.sub)}</div>` : ''}
    </div>`).join('');
}

function visibleRows() {
  const report = REPORTS[state.report];
  let rows = state.rows;

  if (state.search) {
    const needle = state.search.toLowerCase();
    rows = rows.filter(r => report.columns.some(c => String(r[c.key] ?? '').toLowerCase().includes(needle)));
  }

  if (state.sort) {
    const column = report.columns.find(c => c.key === state.sort.key);
    const numeric = NUMERIC_TYPES.has(column.type);
    const dir = state.sort.dir === 'asc' ? 1 : -1;
    rows = [...rows].sort((a, b) => {
      const x = a[column.key], y = b[column.key];
      const result = numeric
        ? (Number(x) || 0) - (Number(y) || 0)
        : String(x ?? '').localeCompare(String(y ?? ''), undefined, { numeric: true });
      return result * dir;
    });
  }
  return rows;
}

function renderCell(column, row, context) {
  const value = row[column.key];
  if (value === null || value === undefined || value === '') {
    return `<td${NUMERIC_TYPES.has(column.type) ? ' class="num"' : ''}>—</td>`;
  }
  switch (column.type) {
    case 'strong':   return `<td class="strong">${escapeHtml(value)}</td>`;
    case 'mono':     return `<td><span class="mono">${escapeHtml(value)}</span></td>`;
    case 'chip':     return `<td><span class="chip">${escapeHtml(value)}</span></td>`;
    case 'stage':    return `<td><span class="badge ${value === 'Won' ? 'badge-won' : 'badge-lost'}">${escapeHtml(value)}</span></td>`;
    case 'date':     return `<td>${escapeHtml(fmt.date(value))}</td>`;
    case 'currency': return `<td class="num">${escapeHtml(fmt.currency(value))}</td>`;
    case 'millions': return `<td class="num">${escapeHtml(fmt.millions(value))}</td>`;
    case 'int':      return `<td class="num">${escapeHtml(fmt.int(value))}</td>`;
    case 'bar': {
      const max = context.max[column.key] || 1;
      const width = Math.max(0, Math.min(100, (Number(value) / max) * 100));
      return `<td class="num"><div class="bar-cell">${escapeHtml(fmt[column.format](value))}
                <div class="bar"><span style="width:${width.toFixed(1)}%"></span></div></div></td>`;
    }
    default:         return `<td>${escapeHtml(value)}</td>`;
  }
}

function renderTable() {
  const report = REPORTS[state.report];
  const rows = visibleRows();
  const pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  state.page = Math.min(state.page, pages);
  const start = (state.page - 1) * PAGE_SIZE;
  const pageRows = rows.slice(start, start + PAGE_SIZE);

  $('result-count').textContent = state.search
    ? `${fmt.int(rows.length)} of ${fmt.int(state.rows.length)} rows match`
    : `${fmt.int(state.rows.length)} rows`;
  $('export').disabled = rows.length === 0;

  if (!state.rows.length) {
    showState('No results', 'The query returned no rows. Try widening the filters.');
    return;
  }
  if (!rows.length) {
    showState('No matches', `Nothing matches “${state.search}”.`);
    return;
  }

  const context = { max: {} };
  report.columns.filter(c => c.type === 'bar').forEach(c => {
    context.max[c.key] = Math.max(...state.rows.map(r => Number(r[c.key]) || 0));
  });

  const head = report.columns.map(c => {
    const sorted = state.sort && state.sort.key === c.key;
    const arrow = sorted ? (state.sort.dir === 'asc' ? '▲' : '▼') : '▲▼';
    return `<th class="${NUMERIC_TYPES.has(c.type) ? 'num' : ''}"
               ${sorted ? `aria-sort="${state.sort.dir === 'asc' ? 'ascending' : 'descending'}"` : ''}>
              <button type="button" data-sort="${c.key}">${escapeHtml(c.label)} <span class="arrow">${arrow}</span></button>
            </th>`;
  }).join('');

  const body = pageRows.map(r => `<tr>${report.columns.map(c => renderCell(c, r, context)).join('')}</tr>`).join('');

  $('table-wrap').innerHTML = `<table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
  $('footer').innerHTML = `
    <span>Showing ${fmt.int(start + 1)}–${fmt.int(start + pageRows.length)} of ${fmt.int(rows.length)}</span>
    <div class="pager">
      <button class="btn" type="button" data-page="prev" ${state.page === 1 ? 'disabled' : ''}>Previous</button>
      <span>Page ${state.page} of ${pages}</span>
      <button class="btn" type="button" data-page="next" ${state.page === pages ? 'disabled' : ''}>Next</button>
    </div>`;
}

function showState(title, message, isError = false) {
  $('table-wrap').innerHTML = `<div class="state${isError ? ' error' : ''}"><strong>${escapeHtml(title)}</strong>${escapeHtml(message)}</div>`;
  $('footer').innerHTML = '';
}

function showLoading() {
  const cols = REPORTS[state.report].columns.length;
  const row = `<tr>${'<td><div class="skeleton"></div></td>'.repeat(cols)}</tr>`;
  $('table-wrap').innerHTML = `<table><tbody>${row.repeat(8)}</tbody></table>`;
  $('footer').innerHTML = '';
  $('result-count').textContent = 'Loading…';
  $('generated').textContent = '';
  renderKpis(true);
}

// ---------------------------------------------------------------------
// Data loading
// ---------------------------------------------------------------------
async function loadReport() {
  const report = REPORTS[state.report];
  const requestId = ++state.requestId;
  const params = new URLSearchParams({ action: report.action });
  for (const [key, value] of Object.entries(state.filters[state.report] || {})) {
    if (value !== '') params.set(key, value);
  }

  showLoading();

  try {
    const res = await fetch('queryEngine.php?' + params.toString());
    const json = await res.json();
    if (requestId !== state.requestId) return;

    if (!json.success) {
      state.rows = [];
      renderKpis();
      $('result-count').textContent = '';
      showState('Unable to load report', json.error || 'Unknown error.', true);
      return;
    }

    state.rows = json.data.rows;
    $('generated').textContent = 'Updated ' + new Date(json.data.generatedAt).toLocaleString('en-AU');
    renderKpis();
    renderTable();
  } catch (err) {
    if (requestId !== state.requestId) return;
    state.rows = [];
    renderKpis();
    $('result-count').textContent = '';
    showState('Request failed', 'Could not reach the query engine. Please try again.', true);
  }
}

// ---------------------------------------------------------------------
// Overview dashboard
// ---------------------------------------------------------------------
const charts = {};

if (window.Chart) {
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  Chart.defaults.font.size = 12;
  Chart.defaults.color = '#64748b';
  Chart.defaults.borderColor = '#eef2f7';
  Chart.defaults.maintainAspectRatio = false;
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  Chart.defaults.plugins.legend.labels.boxHeight = 8;
  Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 8;
}

function drawChart(id, config) {
  const canvas = $(id);
  if (!window.Chart) {
    canvas.parentElement.classList.add('no-chart');
    return;
  }
  if (charts[id]) charts[id].destroy();
  charts[id] = new Chart(canvas, config);
}

const winRate = (won, lost) => (Number(won) + Number(lost)) ? Number(won) / (Number(won) + Number(lost)) : 0;

async function loadDashboard() {
  const requestId = ++state.requestId;
  const grid = $('dash-grid');
  $('dash-error').hidden = true;
  grid.hidden = false;
  grid.classList.add('is-loading');
  $('generated').textContent = '';
  renderKpis(true);
  const skeleton = '<div class="skeleton"></div>'.repeat(5);
  ['funnel', 'top-agents', 'data-quality', 'region-stats'].forEach(id => { $(id).innerHTML = skeleton; });
  $('dq-status').innerHTML = '';

  try {
    const res = await fetch('queryEngine.php?action=dashboard_summary');
    const json = await res.json();
    if (requestId !== state.requestId) return;
    if (!json.success) throw new Error(json.error || 'Unknown error.');

    const s = json.data.sections;
    grid.classList.remove('is-loading');
    $('generated').textContent = 'Updated ' + new Date(json.data.generatedAt).toLocaleString('en-AU');
    renderOverviewKpis(s.kpis);
    renderMonthlyChart(s.monthly_revenue);
    renderFunnel(s.funnel);
    renderProductCharts(s.products);
    renderYearChart(s.establishment_years);
    renderRegions(s.regions);
    renderTopAgents(s.top_agents);
    renderDataQuality(s.data_quality);
  } catch (err) {
    if (requestId !== state.requestId) return;
    grid.hidden = true;
    $('kpis').innerHTML = '';
    const message = err instanceof SyntaxError || err instanceof TypeError
      ? 'Could not reach the query engine. Please try again.' : err.message;
    $('dash-error').innerHTML = `<strong>Unable to load the dashboard</strong>${escapeHtml(message)}`;
    $('dash-error').hidden = false;
  }
}

function renderOverviewKpis(k) {
  renderKpiCards([
    { label: 'Won Revenue', value: fmt.money(k.won_revenue), sub: `${fmt.int(k.won_count)} deals won` },
    { label: 'Win Rate', value: fmt.percent(winRate(k.won_count, k.lost_count)),
      sub: `${fmt.int(k.won_count)} won · ${fmt.int(k.lost_count)} lost` },
    { label: 'Average Won Deal', value: fmt.money(k.average_won_value),
      sub: k.average_sales_cycle_days !== null ? `Average sales cycle ${Number(k.average_sales_cycle_days).toFixed(0)} days` : '' },
    { label: 'Open Pipeline', value: `${fmt.int(k.open_count)} deals`,
      sub: `≈ ${fmt.compact(k.open_pipeline_value)} at list price` },
  ]);
}

function renderMonthlyChart(rows) {
  drawChart('chart-monthly', {
    data: {
      labels: rows.map(r => fmt.month(r.month)),
      datasets: [
        { type: 'bar', label: 'Won revenue', data: rows.map(r => Number(r.won_revenue)),
          backgroundColor: COLORS.primary, borderRadius: 6, maxBarThickness: 36, yAxisID: 'y', order: 2 },
        { type: 'line', label: 'Win rate', data: rows.map(r => +(winRate(r.won_count, r.lost_count) * 100).toFixed(1)),
          borderColor: COLORS.cyan, backgroundColor: COLORS.cyan, tension: .35, pointRadius: 3, yAxisID: 'y1', order: 1 },
      ],
    },
    options: {
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top', align: 'end' },
        tooltip: { callbacks: {
          label: c => c.dataset.yAxisID === 'y1' ? ` Win rate: ${c.parsed.y}%` : ` Won revenue: ${fmt.money(c.parsed.y)}`,
          afterBody: items => {
            const r = rows[items[0].dataIndex];
            return `${fmt.int(r.won_count)} won · ${fmt.int(r.lost_count)} lost`;
          },
        } },
      },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, ticks: { callback: v => fmt.compact(v) } },
        y1: { position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false }, ticks: { callback: v => v + '%' } },
      },
    },
  });
}

function renderFunnel(f) {
  const total = Number(f.total) || 0;
  const steps = [
    { label: 'All opportunities', value: Number(f.total) },
    { label: 'Engaged', value: Number(f.engaged) },
    { label: 'Closed (won or lost)', value: Number(f.closed) },
    { label: 'Won', value: Number(f.won), won: true },
  ];
  $('funnel').innerHTML = steps.map((step, i) => {
    const share = total ? step.value / total : 0;
    const previous = i ? steps[i - 1].value : 0;
    const conversion = i && previous ? ` · ${fmt.percent(step.value / previous)} of previous step` : '';
    return `<div class="funnel-step${step.won ? ' won' : ''}">
        <div class="funnel-top"><span>${escapeHtml(step.label)}</span><strong>${escapeHtml(fmt.int(step.value))}</strong></div>
        <div class="funnel-bar"><span style="width:${(share * 100).toFixed(1)}%"></span></div>
        <div class="funnel-sub">${escapeHtml(fmt.percent(share))} of pipeline${escapeHtml(conversion)}</div>
      </div>`;
  }).join('') + `<div class="panel-note">Still open: ${escapeHtml(fmt.int(total - Number(f.engaged)))} prospecting
      and ${escapeHtml(fmt.int(Number(f.engaged) - Number(f.closed)))} engaging deals.</div>`;
}

function renderProductCharts(rows) {
  const products = [...rows].sort((a, b) => Number(b.total_value) - Number(a.total_value));
  const labels = products.map(p => p.product);
  const series = [...new Set(products.map(p => p.series))];

  drawChart('chart-products', {
    type: 'bar',
    data: {
      labels,
      datasets: series.map(name => ({
        label: `${name} series`,
        data: products.map(p => p.series === name ? Number(p.total_value) : null),
        backgroundColor: SERIES_COLORS[name] || COLORS.primary, borderRadius: 5, maxBarThickness: 22,
      })),
    },
    options: {
      indexAxis: 'y',
      skipNull: true,
      plugins: {
        legend: { position: 'top', align: 'end' },
        tooltip: { filter: item => item.raw !== null, callbacks: {
          label: c => ` ${fmt.money(c.parsed.x)}`,
          afterLabel: c => `Average deal ${fmt.money(products[c.dataIndex].average_value)}`,
        } },
      },
      scales: {
        x: { stacked: true, beginAtZero: true, ticks: { callback: v => fmt.compact(v) } },
        y: { stacked: true, grid: { display: false } },
      },
    },
  });

  drawChart('chart-winloss', {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Won', data: products.map(p => Number(p.won_count)), backgroundColor: COLORS.won, borderRadius: 4, maxBarThickness: 22 },
        { label: 'Lost', data: products.map(p => Number(p.lost_count)), backgroundColor: COLORS.lost, borderRadius: 4, maxBarThickness: 22 },
      ],
    },
    options: {
      indexAxis: 'y',
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top', align: 'end' },
        tooltip: { callbacks: {
          label: c => ` ${c.dataset.label}: ${fmt.int(c.parsed.x)}`,
          footer: items => {
            const p = products[items[0].dataIndex];
            return `Win rate ${fmt.percent(winRate(p.won_count, p.lost_count))}`;
          },
        } },
      },
      scales: {
        x: { stacked: true, beginAtZero: true },
        y: { stacked: true, grid: { display: false } },
      },
    },
  });
}

function renderYearChart(rows) {
  drawChart('chart-years', {
    data: {
      labels: rows.map(r => String(r.year_established)),
      datasets: [
        { type: 'bar', label: 'Total revenue', data: rows.map(r => Number(r.total_revenue)),
          backgroundColor: COLORS.primary, borderRadius: 4, yAxisID: 'y', order: 2 },
        { type: 'line', label: 'Accounts', data: rows.map(r => Number(r.number_of_accounts)),
          borderColor: COLORS.amber, backgroundColor: COLORS.amber, pointRadius: 2.5, borderWidth: 2, yAxisID: 'y1', order: 1 },
      ],
    },
    options: {
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top', align: 'end' },
        tooltip: { callbacks: {
          label: c => c.dataset.yAxisID === 'y1'
            ? ` Accounts: ${fmt.int(c.parsed.y)}`
            : ` Total revenue: ${fmt.millions(c.parsed.y)} (${fmt.compact(c.parsed.y * 1e6)})`,
        } },
      },
      scales: {
        x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0 } },
        y: { beginAtZero: true, ticks: { callback: v => fmt.compact(v * 1e6) } },
        y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { precision: 0 } },
      },
    },
  });
}

function renderRegions(rows) {
  const totalRevenue = sum(rows, r => r.won_revenue);
  drawChart('chart-regions', {
    type: 'doughnut',
    data: {
      labels: rows.map(r => r.regional_office),
      datasets: [{ data: rows.map(r => Number(r.won_revenue)), backgroundColor: REGION_COLORS, borderWidth: 2, borderColor: '#fff' }],
    },
    options: {
      cutout: '68%',
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: {
          label: c => ` ${fmt.money(c.parsed)} (${fmt.percent(totalRevenue ? c.parsed / totalRevenue : 0)})`,
        } },
      },
    },
  });

  $('region-stats').innerHTML = rows.map((r, i) => `
    <div class="region-row">
      <span class="dot" style="background:${REGION_COLORS[i % REGION_COLORS.length]}"></span>
      <span class="name">${escapeHtml(r.regional_office)} <span class="muted">· ${escapeHtml(fmt.int(r.agents))} agents</span></span>
      <span class="muted">${escapeHtml(fmt.percent(winRate(r.won_count, r.lost_count)))} win</span>
      <strong>${escapeHtml(fmt.compact(r.won_revenue))}</strong>
    </div>`).join('');
}

function renderTopAgents(rows) {
  if (!rows.length) {
    $('top-agents').innerHTML = '<div class="state"><strong>No closed deals</strong>No agent has closed a deal yet.</div>';
    return;
  }
  const max = Math.max(...rows.map(r => Number(r.won_revenue))) || 1;
  const body = rows.map((r, i) => `
    <tr>
      <td><span class="rank${i < 3 ? ' top' : ''}">${i + 1}</span></td>
      <td class="strong">${escapeHtml(r.sales_agent)}<span class="sub">Manager: ${escapeHtml(r.manager ?? '—')}</span></td>
      <td><span class="chip">${escapeHtml(r.regional_office ?? '—')}</span></td>
      <td class="num">${escapeHtml(fmt.int(r.won_count))}</td>
      <td class="num">${escapeHtml(fmt.percent(winRate(r.won_count, r.lost_count)))}</td>
      <td class="num"><div class="bar-cell">${escapeHtml(fmt.money(r.won_revenue))}
        <div class="bar"><span style="width:${(Number(r.won_revenue) / max * 100).toFixed(1)}%"></span></div></div></td>
    </tr>`).join('');
  $('top-agents').innerHTML = `<div class="table-wrap"><table class="mini-table">
      <thead><tr><th>#</th><th>Sales agent</th><th>Region</th><th class="num">Won</th><th class="num">Win rate</th><th class="num">Won revenue</th></tr></thead>
      <tbody>${body}</tbody></table></div>`;
}

function renderDataQuality(dq) {
  if (!dq) {
    $('dq-status').innerHTML = '';
    $('data-quality').innerHTML = '<div class="state"><strong>No staging data</strong>Run the ETL (etl/run_etl.sh) to populate the staging table.</div>';
    return;
  }
  const rejected = Math.max(0, Number(dq.staged_rows) - Number(dq.loaded_rows));
  const items = [
    { label: 'Raw rows staged', value: dq.staged_rows, kind: 'info' },
    { label: 'Rows loaded into the warehouse', value: dq.loaded_rows, kind: 'info' },
    { label: 'Rows rejected by validation', value: rejected, kind: rejected === 0 ? 'ok' : 'warn' },
    { label: 'Product names standardised', value: dq.products_standardised, kind: 'info' },
    { label: 'Empty values converted to NULL', value: dq.empty_values_to_null, kind: 'info' },
    { label: 'Orphan references', value: dq.orphan_rows, kind: Number(dq.orphan_rows) === 0 ? 'ok' : 'warn' },
    { label: 'Business-rule violations', value: dq.rule_violations, kind: Number(dq.rule_violations) === 0 ? 'ok' : 'warn' },
  ];
  const passed = !items.some(item => item.kind === 'warn');
  const symbol = { ok: '✓', info: 'i', warn: '!' };
  $('dq-status').innerHTML = `<span class="dq-status ${passed ? 'ok' : 'warn'}">${passed ? 'All checks passed' : 'Needs review'}</span>`;
  $('data-quality').innerHTML = `<ul class="dq-list">${items.map(item => `
      <li><span class="icon ${item.kind}">${symbol[item.kind]}</span>
          <span class="label">${escapeHtml(item.label)}</span>
          <span class="value">${escapeHtml(fmt.int(item.value))}</span></li>`).join('')}</ul>`;
}

function selectReport(key) {
  if (key !== 'overview' && !REPORTS[key]) key = 'overview';
  const page = key === 'overview' ? OVERVIEW : REPORTS[key];
  state.report = key;
  state.rows = [];
  state.sort = null;
  state.search = '';
  state.page = 1;
  $('search').value = '';
  $('title').textContent = page.title;
  $('description').textContent = page.description;
  document.title = `${page.title} · CRM Sales Insights`;
  $('dashboard').hidden = key !== 'overview';
  $('results').hidden = key === 'overview';
  renderNav();
  renderToolbar();
  refresh();
}

function refresh() {
  if (state.report === 'overview') {
    loadDashboard();
  } else {
    loadReport();
  }
}

function readFilters() {
  const values = {};
  for (const f of REPORTS[state.report].filters) {
    values[f.key] = $(`filter-${f.key}`).value.trim();
  }
  state.filters[state.report] = values;
}

function exportCsv() {
  const report = REPORTS[state.report];
  const rows = visibleRows();
  const cell = value => {
    let text = String(value ?? '');
    if (/^[=+\-@]/.test(text)) text = "'" + text; // prevent spreadsheet formula injection
    return `"${text.replaceAll('"', '""')}"`;
  };
  const lines = [
    report.columns.map(c => cell(c.label)).join(','),
    ...rows.map(r => report.columns.map(c => cell(r[c.key])).join(',')),
  ];
  const blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `${state.report}-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
  URL.revokeObjectURL(link.href);
}

// ---------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------
$('nav').addEventListener('click', e => {
  const button = e.target.closest('button[data-report]');
  if (button) location.hash = button.dataset.report;
});

window.addEventListener('hashchange', () => selectReport(location.hash.slice(1)));

$('toolbar').addEventListener('click', e => {
  if (e.target.closest('#apply') && state.report === 'overview') {
    loadDashboard();
  } else if (e.target.closest('#apply')) {
    readFilters();
    state.page = 1;
    loadReport();
  } else if (e.target.closest('#reset')) {
    state.filters[state.report] = {};
    renderToolbar();
    state.page = 1;
    loadReport();
  }
});

$('toolbar').addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target.matches('input, select')) {
    readFilters();
    state.page = 1;
    loadReport();
  }
});

$('table-wrap').addEventListener('click', e => {
  const button = e.target.closest('button[data-sort]');
  if (!button) return;
  const key = button.dataset.sort;
  const column = REPORTS[state.report].columns.find(c => c.key === key);
  const firstDir = NUMERIC_TYPES.has(column.type) ? 'desc' : 'asc';
  if (!state.sort || state.sort.key !== key) {
    state.sort = { key, dir: firstDir };
  } else if (state.sort.dir === firstDir) {
    state.sort.dir = firstDir === 'asc' ? 'desc' : 'asc';
  } else {
    state.sort = null;
  }
  state.page = 1;
  renderTable();
});

$('footer').addEventListener('click', e => {
  const button = e.target.closest('button[data-page]');
  if (!button || button.disabled) return;
  state.page += button.dataset.page === 'next' ? 1 : -1;
  renderTable();
  $('table-wrap').scrollTop = 0;
});

let searchTimer;
$('search').addEventListener('input', e => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    state.search = e.target.value.trim();
    state.page = 1;
    if (state.rows.length) renderTable();
  }, 150);
});

$('export').addEventListener('click', exportCsv);

selectReport(location.hash.slice(1));
</script>

</body>
</html>

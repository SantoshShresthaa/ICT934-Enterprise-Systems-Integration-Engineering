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
</style>
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
    <div class="nav-label">Reports &amp; Analysis</div>
    <nav class="nav" id="nav" aria-label="Reports"></nav>
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

    <section class="card" aria-labelledby="table-title">
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

const NUMERIC_TYPES = new Set(['currency', 'millions', 'int', 'bar']);

// ---------------------------------------------------------------------
// Formatting helpers
// ---------------------------------------------------------------------
const usd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 });
const dateFormat = new Intl.DateTimeFormat('en-AU', { day: '2-digit', month: 'short', year: 'numeric' });

const fmt = {
  currency: v => usd.format(Number(v) || 0),
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
  report: 'products',
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
  $('nav').innerHTML = Object.entries(REPORTS).map(([key, report]) => `
    <button type="button" data-report="${key}" ${key === state.report ? 'aria-current="page"' : ''}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ICONS[key]}</svg>
      ${escapeHtml(report.title)}
    </button>`).join('');
}

function renderToolbar() {
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
  const report = REPORTS[state.report];
  if (loading) {
    $('kpis').innerHTML = Array.from({ length: 4 }, () =>
      '<div class="card kpi"><div class="skeleton" style="width:40%;height:12px"></div><div class="skeleton"></div></div>').join('');
    return;
  }
  const kpis = report.kpis(state.rows);
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

function selectReport(key) {
  if (!REPORTS[key]) key = 'products';
  state.report = key;
  state.rows = [];
  state.sort = null;
  state.search = '';
  state.page = 1;
  $('search').value = '';
  $('title').textContent = REPORTS[key].title;
  $('description').textContent = REPORTS[key].description;
  document.title = `${REPORTS[key].title} · CRM Sales Insights`;
  renderNav();
  renderToolbar();
  loadReport();
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
  if (e.target.closest('#apply')) {
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

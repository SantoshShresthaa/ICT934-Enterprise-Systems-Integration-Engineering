<!DOCTYPE html>
<!--
  index.php
  ---------------------------------------------------------------------
  Web interface for the CRM Data Warehouse query engine.
  Calls queryEngine.php via fetch() for each report/analysis and renders
  the JSON response as a table. Also includes quick security/robustness
  checks used as evidence for the secure query handling tests.
  ---------------------------------------------------------------------
-->
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CRM Data Warehouse - Reports &amp; Analysis</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; margin: 2rem; background:#f7f8fa; color:#222; }
  h1 { font-size: 1.4rem; }
  .panel { background:#fff; border:1px solid #ddd; border-radius:8px; padding:1rem 1.25rem; margin-bottom:1.5rem; }
  button { background:#2557a7; color:#fff; border:0; padding:.5rem 1rem; border-radius:5px; cursor:pointer; margin-right:.5rem; }
  button:hover { background:#1c4482; }
  select, input { padding:.35rem; margin-right:.5rem; }
  .table-wrap { max-height: 480px; overflow:auto; margin-top:1rem; }
  table { border-collapse: collapse; width:100%; font-size:.9rem; }
  th, td { border:1px solid #e2e2e2; padding:.4rem .6rem; text-align:left; }
  th { background:#eef1f6; position: sticky; top: 0; }
  td.num { text-align:right; }
  .meta { color:#666; font-size:.85rem; margin-top:.5rem;}
  .error { color:#b00020; font-weight:bold; }
</style>
</head>
<body>

<h1>CRM Data Warehouse - Reports &amp; Analysis</h1>
<p>Each report calls <code>queryEngine.php</code> and renders the JSON response below.</p>

<div class="panel">
  <h2>1. Products Report</h2>
  <p>All products with their series and sales price.</p>
  <button onclick="runQuery('products_report', {}, 'out-products')">Run</button>
  <div id="out-products"></div>
</div>

<div class="panel">
  <h2>2. Sales Opportunities Report (Won / Lost)</h2>
  <p>Closed opportunities with their value, associated product, account and sales agent.</p>
  <label>Deal stage:
    <select id="stage-filter">
      <option value="">Both (Won + Lost)</option>
      <option value="Won">Won</option>
      <option value="Lost">Lost</option>
    </select>
  </label>
  <button onclick="runQuery('sales_opportunities_report', {deal_stage: document.getElementById('stage-filter').value}, 'out-opps')">Run</button>
  <div id="out-opps"></div>
</div>

<div class="panel">
  <h2>3. Establishment Year Revenue Analysis</h2>
  <p>Total and average annual revenue of accounts, grouped by year of establishment.</p>
  <label>Year from: <input type="number" id="year-from" placeholder="e.g. 1990" style="width:100px"></label>
  <label>Year to: <input type="number" id="year-to" placeholder="e.g. 2015" style="width:100px"></label>
  <button onclick="runQuery('establishment_year_revenue', {year_from: document.getElementById('year-from').value, year_to: document.getElementById('year-to').value}, 'out-year')">Run</button>
  <div id="out-year"></div>
</div>

<div class="panel">
  <h2>4. Sales Opportunity Analysis (by product)</h2>
  <p>Total and average opportunity value per product, with won/lost counts.</p>
  <label>Deal stage:
    <select id="stage-filter-2">
      <option value="">All opportunities</option>
      <option value="Won">Won</option>
      <option value="Lost">Lost</option>
    </select>
  </label>
  <button onclick="runQuery('sales_opportunity_analysis', {deal_stage: document.getElementById('stage-filter-2').value}, 'out-analysis')">Run</button>
  <div id="out-analysis"></div>
</div>

<div class="panel">
  <h2>Security / Robustness Checks</h2>
  <p>Quick manual checks — see docs/security_test_plan.md for the full test plan.</p>
  <button onclick="runQuery('drop_table_accounts', {}, 'out-sec')">Invalid action</button>
  <button onclick="runQuery('sales_opportunities_report', {deal_stage: &quot;Won' OR '1'='1&quot;}, 'out-sec')">SQL injection attempt (deal_stage)</button>
  <button onclick="runQuery('establishment_year_revenue', {year_from: 'abc'}, 'out-sec')">Invalid type (year_from)</button>
  <button onclick="runQuery('establishment_year_revenue', {year_from: '2010', year_to: '1990'}, 'out-sec')">Reversed year range</button>
  <div id="out-sec"></div>
</div>

<script>
async function runQuery(action, params, targetId) {
  const target = document.getElementById(targetId);
  target.innerHTML = '<p><em>Loading…</em></p>';

  const query = new URLSearchParams({ action, ...params });
  // Strip empty params so we don't send blank filters.
  for (const key of [...query.keys()]) {
    if (query.get(key) === '' || query.get(key) === null || query.get(key) === undefined) {
      query.delete(key);
    }
  }

  try {
    const res = await fetch('queryEngine.php?' + query.toString());
    const json = await res.json();

    if (!json.success) {
      target.innerHTML = `<p class="error">Error (HTTP ${res.status}): ${escapeHtml(json.error)}</p>`;
      return;
    }

    const rows = json.data.rows;
    if (!rows.length) {
      target.innerHTML = '<p>No rows returned.</p>';
      return;
    }

    const columns = Object.keys(rows[0]);
    let html = '<div class="table-wrap"><table><thead><tr>' +
      columns.map(c => `<th>${escapeHtml(formatHeader(c))}</th>`).join('') +
      '</tr></thead><tbody>';
    for (const row of rows) {
      html += '<tr>' + columns.map(c => {
        const value = row[c] ?? '';
        const isNumeric = value !== '' && !isNaN(value) && !/date/.test(c);
        return `<td${isNumeric ? ' class="num"' : ''}>${escapeHtml(value)}</td>`;
      }).join('') + '</tr>';
    }
    html += '</tbody></table></div>';
    html += `<div class="meta">${json.data.row_count} row(s) • generated ${escapeHtml(json.data.generatedAt)}</div>`;
    target.innerHTML = html;
  } catch (err) {
    target.innerHTML = `<p class="error">Request failed: ${escapeHtml(err.message)}</p>`;
  }
}

function formatHeader(column) {
  return column.replaceAll('_', ' ').replace(/\b\w/g, ch => ch.toUpperCase());
}

// Always escape before inserting into the DOM (defence in depth alongside
// the server-side JSON encoding in queryEngine.php).
function escapeHtml(str) {
  return String(str)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
</script>

</body>
</html>

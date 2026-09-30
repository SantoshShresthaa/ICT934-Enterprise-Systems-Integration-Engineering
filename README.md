# CRM Sales Data Warehouse and Query Engine

ICT934 Assessment 3. A data warehouse built from the Maven "CRM Sales Opportunities" dataset, with:

- a **Java ETL** that extracts the XML/CSV source files, cleans and validates them, and loads MySQL (Parts 1 and 2);
- a **PHP query engine** that serves the reports as a secure JSON API (Part 3);
- a **web interface** with an Overview dashboard and the four required reports (Part 4).

## Features

**Reports** (web interface and API)

| Report | Action | Filters |
|---|---|---|
| Products Report | `products_report` | none |
| Sales Opportunities Report (won and lost deals) | `sales_opportunities_report` | `deal_stage` = `Won` or `Lost` |
| Establishment Year Revenue Analysis | `establishment_year_revenue` | `year_from`, `year_to` |
| Sales Opportunity Analysis (total and average value by product) | `sales_opportunity_analysis` | `deal_stage` = `Won` or `Lost` |
| Overview dashboard | `dashboard_summary` | none |

**Overview dashboard:** headline KPIs, monthly won revenue with win rate, sales funnel, revenue and win/loss by product, revenue by establishment year, regional performance, top sales agents, and an ETL data-quality panel.

**Report pages:** summary cards, sortable and searchable tables, pagination and CSV export.

**Secure query handling**

- Whitelisted actions; anything else returns HTTP 400 before the database is touched.
- Prepared statements with real server-side binding for every user-supplied value.
- Type, enum and range validation of every filter.
- Read-only: the engine only runs `SELECT` statements.
- Generic error messages to the client; the real database error is logged server-side only.
- Only GET and POST are accepted (anything else returns 405).
- HTML escaping in the browser, and a formula-injection guard on CSV export.

## Repository structure

```text
queryEngine/
├── index.php                        # Web interface (Overview dashboard + four reports)
├── queryEngine.php                  # Query engine / JSON API
├── config.php                       # .env loader, DB settings, PDO connection, TLS
├── sql/
│   └── warehouse.sql                # Warehouse export (schema + cleaned data + raw staging)
├── Dataset_CRM_Sales_Opportunities/ # Source data (accounts.xml, sales_teams.xml,
│                                    #   products.csv, sales_pipeline.csv, data_dictionary.csv)
├── etl/
│   ├── src/                         # XMLParser, CSVParser, DataTransformer, MySQLLoader, ETL_Main
│   ├── reports/                     # etl_report.txt and rejected_records.csv from the last run
│   └── run_etl.sh                   # Builds and runs the ETL
├── tests/
│   └── security_tests.php           # Automated security test suite (8 tests)
├── Dockerfile.vercel                # Container image (PHP 8.3 + Apache)
├── .env.example                     # Database settings template
└── .gitignore / .dockerignore / .gitattributes
```

## Requirements

- PHP 8.1+ with the `pdo_mysql` extension
- MySQL 8 (local or Docker), or TiDB Cloud
- For the ETL: a JDK 17+, **or** Docker (the script falls back to a Docker JDK automatically)

## Getting started

### 1. Configure the database

```bash
cp .env.example .env
```

Edit `.env` with your database settings. Real environment variables take priority over `.env`.

| Variable | Default | Notes |
|---|---|---|
| `CRM_DB_HOST` | `127.0.0.1` | |
| `CRM_DB_PORT` | `3306` | TiDB Cloud uses `4000` |
| `CRM_DB_NAME` | `crm_sales` | |
| `CRM_DB_USER` | `root` | |
| `CRM_DB_PASS` | *(empty)* | |
| `CRM_DB_SSL` | `false` | Set to `true` for hosted databases that require TLS (e.g. TiDB Cloud) |

### 2. Load the warehouse

Use **one** of these options.

**Option A: run the ETL** (recommended; rebuilds the warehouse from the source files)

```bash
./etl/run_etl.sh             # extract, stage, profile, transform, quality gate, load, reconcile
./etl/run_etl.sh --dry-run   # everything up to the quality gate, without touching the database
```

**Option B: import the export**

```bash
mysql -u root -p crm_sales < sql/warehouse.sql
```

### 3. Run the web interface

```bash
php -S 127.0.0.1:8000
```

Open <http://127.0.0.1:8000/index.php>. The dashboard charts load Chart.js from a CDN, so they need an internet connection. Without one, the rest of the page still works.

## ETL process

`ETL_Main` runs seven steps, and prints a report of each one:

1. **Extract:** parse `accounts.xml`, `sales_teams.xml`, `products.csv` and `sales_pipeline.csv`.
2. **Stage:** land the raw pipeline rows, unchanged, in `sales_pipeline_staging`.
3. **Profile:** assess the raw data before changing it: missing values, whitespace, inconsistent casing, invalid numbers or dates, duplicate keys and unmatched references.
4. **Transform:** clean and validate.
   - Cleaning trims whitespace, fixes spellings (`technolgy`, `Philipines`), title-cases sectors, maps `GTXPro` to `GTX Pro`, and converts empty values to NULL.
   - Validation rejects records that break the type, domain, business or referential-integrity rules, with a reason for each.
5. **Quality gate:** stop before loading if more than 5% of any source is rejected. Change the limit with `ETL_MAX_REJECT_PERCENT`.
6. **Load:** replace the warehouse data in a single transaction; on any failure, everything is rolled back.
7. **Reconcile:** confirm that source = loaded + rejected for each table, that won-value totals match the staged data, and that there are no orphan rows or rule violations.

Each run writes `etl/reports/etl_report.txt` and `etl/reports/rejected_records.csv`.

**Result on the provided dataset:**

| Table | Rows loaded | Rejected |
|---|---|---|
| accounts | 85 | 0 |
| products | 7 | 0 |
| sales_teams | 35 | 0 |
| sales_pipeline | 8,800 | 0 |

The 8,800 opportunities are 4,238 Won, 2,473 Lost, 1,589 Engaging and 500 Prospecting. Won revenue totals $10,005,534.

## API

```text
GET queryEngine.php?action=<action>[&filters]
```

Examples:

```text
queryEngine.php?action=products_report
queryEngine.php?action=sales_opportunities_report&deal_stage=Won
queryEngine.php?action=establishment_year_revenue&year_from=1990&year_to=2010
queryEngine.php?action=sales_opportunity_analysis&deal_stage=Lost
queryEngine.php?action=dashboard_summary
```

A successful report response looks like this:

```json
{
  "success": true,
  "data": { "action": "products_report", "row_count": 7, "generatedAt": "2026-09-30T21:00:00+10:00", "rows": [] }
}
```

`dashboard_summary` returns `sections` instead of `row_count` and `rows`. Errors return `{"success": false, "error": "..."}` with HTTP 400, 405 or 500.

## Security tests

```bash
php tests/security_tests.php              # run all 8 tests
php tests/security_tests.php 2 3          # run selected tests
php tests/security_tests.php --list       # list the tests
php tests/security_tests.php --markdown   # also print a Markdown results table
```

| # | Test | Expected result |
|---|---|---|
| 1 | Unknown action | 400, rejected before any database access |
| 2 | SQL injection via `deal_stage` | 400; tables unchanged |
| 3 | SQL injection via `year_from` | 400; tables unchanged |
| 4 | Invalid type (`year_from=abc`) | 400 |
| 5 | Wrong HTTP method (PUT) | 405 |
| 6 | Missing action | 400 |
| 7 | Forced database error | 500 with a generic message; the real error is only logged |
| 8 | Injected parameters on the dashboard | 200 and identical to a clean request; tables unchanged |

The suite starts its own PHP servers and uses the database configured in `.env`.

## Deployment (Docker / Vercel)

`Dockerfile.vercel` builds a PHP 8.3 + Apache image that listens on `$PORT`.

```bash
docker build -f Dockerfile.vercel -t crm-query-engine .
docker run -p 8080:80 \
  -e CRM_DB_HOST=host.docker.internal -e CRM_DB_NAME=crm_sales \
  -e CRM_DB_USER=root -e CRM_DB_PASS=your_password \
  crm-query-engine
```

Open <http://localhost:8080/index.php>. Inside a container, `127.0.0.1` means the container itself, so use `host.docker.internal` to reach a database on your machine.

On Vercel, set the `CRM_DB_*` variables in the project's environment settings. For TiDB Cloud, also set `CRM_DB_SSL=true`. `.env` is excluded from the image by `.dockerignore`.

<?php
/**
 * queryEngine.php
 * -----------------------------------------------------------------------
 * PHP Query Engine (middleware) - ICT934 Assessment 3, Part 3.
 *
 * Role in the architecture:
 *   Web Interface (index.php)  --HTTP GET/POST-->  queryEngine.php
 *   queryEngine.php            --PDO/prepared SQL-->  MySQL Data Warehouse
 *   queryEngine.php            --JSON-->  Web Interface (rendered as tables)
 *
 * Warehouse schema (see sql/warehouse.sql):
 *   accounts        (account PK, sector, year_established, revenue,
 *                    employees, office_location, subsidiary_of -> accounts)
 *   products        (product PK, series, sales_price)
 *   sales_teams     (sales_agent PK, manager, regional_office)
 *   sales_pipeline  (opportunity_id PK, sales_agent -> sales_teams,
 *                    product -> products, account -> accounts,
 *                    deal_stage, engage_date, close_date, close_value)
 *   All relationships use natural (name) keys, so joins are on names.
 *
 * Supported actions (?action=...):
 *   products_report                 - Products Report
 *   sales_opportunities_report      - Won/Lost Sales Opportunities Report
 *   establishment_year_revenue      - Establishment Year Revenue Analysis
 *   sales_opportunity_analysis      - Sales Opportunity Analysis by product
 *
 * SECURE QUERY HANDLING (design decisions, documented for the report):
 *   1. Whitelisted actions   - the "action" parameter is matched against a
 *      fixed list (ALLOWED_ACTIONS). Anything else -> 400 error. This means
 *      the client can never select or inject arbitrary SQL / table names.
 *   2. Prepared statements   - every query uses PDO bound parameters
 *      (":placeholder") for any user-supplied value. No string
 *      concatenation of user input into SQL is performed anywhere.
 *   3. Input validation      - optional filters (deal_stage, year range)
 *      are validated by type/enum/range BEFORE being bound, so even a
 *      bound parameter can't carry unexpected values through to business
 *      logic.
 *   4. Least-privilege output - only SELECT statements are executed; the
 *      engine has no code path that performs INSERT/UPDATE/DELETE/DDL.
 *   5. Error handling         - internal DB errors are logged server-side
 *      (error_log) and a generic JSON error is returned to the client;
 *      raw PDOException messages/stack traces are never echoed.
 *   6. Output encoding        - all string values are passed through
 *      json_encode(), which safely escapes special characters for JSON
 *      consumption by the front end (front end should also escape when
 *      inserting into the DOM, see index.php).
 * -----------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Restrict to the methods the engine actually needs.
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Merge GET/POST so the engine works with either (front end uses GET for
// simple report fetches, POST could be used for larger filter payloads).
$request = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : $_POST;

const ALLOWED_ACTIONS = [
    'products_report',
    'sales_opportunities_report',
    'establishment_year_revenue',
    'sales_opportunity_analysis',
];

// The ETL only loads closed opportunities into sales_pipeline, so these are
// the only deal_stage values present in the warehouse.
const ALLOWED_DEAL_STAGES = ['Won', 'Lost'];

/**
 * Sends a JSON response and terminates the script.
 */
function respond(bool $success, $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(array_merge(
        ['success' => $success],
        $success ? ['data' => $payload] : ['error' => $payload]
    ), JSON_PRETTY_PRINT);
    exit;
}

/**
 * Validates and returns a clean action string, or null if invalid.
 */
function getValidatedAction(array $request): ?string
{
    $action = $request['action'] ?? '';
    $action = is_string($action) ? trim($action) : '';
    return in_array($action, ALLOWED_ACTIONS, true) ? $action : null;
}

/**
 * Returns the validated deal_stage filter, or null when no filter was sent.
 *
 * @throws InvalidArgumentException if the value is not a whitelisted stage
 */
function getValidatedDealStage(array $request): ?string
{
    $stage = $request['deal_stage'] ?? null;

    if ($stage === null || $stage === '') {
        return null;
    }

    if (!is_string($stage) || !in_array($stage, ALLOWED_DEAL_STAGES, true)) {
        throw new InvalidArgumentException(
            'Invalid deal_stage filter. Allowed values: ' . implode(', ', ALLOWED_DEAL_STAGES) . '.'
        );
    }

    return $stage;
}

// -----------------------------------------------------------------------
// Query implementations
// Each function takes the PDO connection + the raw request array,
// validates whatever filters it needs, and returns an associative array.
// -----------------------------------------------------------------------

/**
 * Products Report: all product information.
 */
function getProductsReport(PDO $pdo, array $request): array
{
    $sql = "SELECT
                product,
                series,
                sales_price
            FROM products
            ORDER BY series, product";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Sales Opportunities Report: won and lost opportunities with opportunity
 * values and the associated product. Optional ?deal_stage=Won|Lost filter
 * (defaults to both).
 */
function getSalesOpportunitiesReport(PDO $pdo, array $request): array
{
    $stage = getValidatedDealStage($request);

    $params = [];
    $stageCondition = "sp.deal_stage IN ('Won', 'Lost')";

    if ($stage !== null) {
        $stageCondition = 'sp.deal_stage = :deal_stage';
        $params[':deal_stage'] = $stage;
    }

    $sql = "SELECT
                sp.opportunity_id,
                sp.account,
                sp.product,
                p.series,
                p.sales_price,
                sp.sales_agent,
                sp.deal_stage,
                sp.engage_date,
                sp.close_date,
                sp.close_value
            FROM sales_pipeline sp
            LEFT JOIN products p ON p.product = sp.product
            WHERE {$stageCondition}
            ORDER BY sp.close_date DESC, sp.opportunity_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Establishment Year Revenue Analysis: total annual revenue per year of
 * establishment, from the accounts data. Optional ?year_from / ?year_to
 * range filters.
 */
function getEstablishmentYearRevenueAnalysis(PDO $pdo, array $request): array
{
    $params = [];
    $conditions = ['year_established IS NOT NULL'];
    $bounds = [];

    foreach (['year_from' => '>=', 'year_to' => '<='] as $key => $operator) {
        if (isset($request[$key]) && $request[$key] !== '') {
            if (!is_string($request[$key]) ||
                !ctype_digit($request[$key]) ||
                (int) $request[$key] < 1800 ||
                (int) $request[$key] > (int) date('Y')
            ) {
                throw new InvalidArgumentException("Invalid {$key} value.");
            }
            $bounds[$key]      = (int) $request[$key];
            $conditions[]      = "year_established {$operator} :{$key}";
            $params[":{$key}"] = $bounds[$key];
        }
    }

    if (isset($bounds['year_from'], $bounds['year_to']) && $bounds['year_from'] > $bounds['year_to']) {
        throw new InvalidArgumentException('year_from must be less than or equal to year_to.');
    }

    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $sql = "SELECT
                year_established,
                COUNT(*)               AS number_of_accounts,
                SUM(revenue)           AS total_revenue,
                ROUND(AVG(revenue), 2) AS average_revenue
            FROM accounts
            {$whereClause}
            GROUP BY year_established
            ORDER BY year_established ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Sales Opportunity Analysis: total and average sales opportunity values
 * segmented by product. Optional ?deal_stage=Won|Lost filter; defaults to
 * all opportunities. Products with no matching opportunities are still
 * listed (with zero totals) so the full product range is always shown.
 */
function getSalesOpportunityAnalysis(PDO $pdo, array $request): array
{
    $stage = getValidatedDealStage($request);

    $params = [];
    $stageJoin = '';

    if ($stage !== null) {
        $stageJoin = 'AND sp.deal_stage = :deal_stage';
        $params[':deal_stage'] = $stage;
    }

    $sql = "SELECT
                p.product,
                p.series,
                COUNT(sp.opportunity_id)                     AS number_of_opportunities,
                SUM(CASE WHEN sp.deal_stage = 'Won'  THEN 1 ELSE 0 END) AS won_count,
                SUM(CASE WHEN sp.deal_stage = 'Lost' THEN 1 ELSE 0 END) AS lost_count,
                COALESCE(SUM(sp.close_value), 0)             AS total_value,
                COALESCE(ROUND(AVG(sp.close_value), 2), 0)   AS average_value
            FROM products p
            LEFT JOIN sales_pipeline sp
                   ON sp.product = p.product
                  {$stageJoin}
            GROUP BY p.product, p.series
            ORDER BY total_value DESC, p.product";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// -----------------------------------------------------------------------
// Request dispatch
// -----------------------------------------------------------------------

$action = getValidatedAction($request);

if ($action === null) {
    respond(false, 'Missing or invalid "action" parameter. Allowed actions: '
        . implode(', ', ALLOWED_ACTIONS), 400);
}

try {
    $pdo = getDbConnection();

    switch ($action) {
        case 'products_report':
            $data = getProductsReport($pdo, $request);
            break;
        case 'sales_opportunities_report':
            $data = getSalesOpportunitiesReport($pdo, $request);
            break;
        case 'establishment_year_revenue':
            $data = getEstablishmentYearRevenueAnalysis($pdo, $request);
            break;
        case 'sales_opportunity_analysis':
            $data = getSalesOpportunityAnalysis($pdo, $request);
            break;
        default:
            // Unreachable due to whitelist check above, kept for safety.
            respond(false, 'Unsupported action.', 400);
    }

    respond(true, [
        'action'      => $action,
        'row_count'   => count($data),
        'generatedAt' => date(DATE_ATOM),
        'rows'        => $data,
    ]);
} catch (InvalidArgumentException $e) {
    // Validation failures -> safe to expose the message (no internal detail leaked).
    respond(false, $e->getMessage(), 400);
} catch (PDOException $e) {
    // Never leak raw DB errors (could reveal schema/credentials) - log and
    // return a generic message instead.
    error_log('[queryEngine] Database error: ' . $e->getMessage());
    respond(false, 'A database error occurred. Please try again later.', 500);
} catch (Throwable $e) {
    error_log('[queryEngine] Unexpected error: ' . $e->getMessage());
    respond(false, 'An unexpected error occurred.', 500);
}

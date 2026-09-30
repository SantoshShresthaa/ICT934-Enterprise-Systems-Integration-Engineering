<?php
/**
 * security_tests.php
 * -----------------------------------------------------------------------
 * Automated security / robustness tests for queryEngine.php.
 *
 * Usage (from the project folder):
 *   php tests/security_tests.php              # run every test
 *   php tests/security_tests.php 3            # run only test 3 (or: 2 5 7)
 *   php tests/security_tests.php --list       # list the tests
 *   php tests/security_tests.php --markdown   # also print a Markdown table
 *   php tests/security_tests.php --no-color   # plain output (also NO_COLOR=1)
 *   php tests/security_tests.php --fast       # skip the progress animation
 *
 * The script starts its own PHP built-in servers, so nothing needs to be
 * running beforehand. It needs a reachable database configured through
 * .env (or environment variables), exactly like the application.
 * Exit code is 0 when every test passes, 1 otherwise.
 * -----------------------------------------------------------------------
 */

declare(strict_types=1);

const PROJECT_ROOT = __DIR__ . '/..';
const GENERIC_DB_ERROR = 'A database error occurred. Please try again later.';

require PROJECT_ROOT . '/config.php';

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

function findFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    return $port;
}

/**
 * Starts `php -S` serving the project. $env overrides the inherited
 * environment (real environment variables take priority over .env).
 */
function startServer(array $env = []): array
{
    $port = findFreePort();
    $logFile = tempnam(sys_get_temp_dir(), 'qe-test-');
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', PROJECT_ROOT],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $logFile, 'w']],
        $pipes,
        PROJECT_ROOT,
        $env + getenv()
    );

    for ($i = 0; $i < 50; $i++) {
        $connection = @fsockopen('127.0.0.1', $port);
        if ($connection) {
            fclose($connection);
            return ['url' => "http://127.0.0.1:{$port}/queryEngine.php", 'process' => $process, 'log' => $logFile];
        }
        usleep(100_000);
    }

    throw new RuntimeException("Test server on port {$port} did not start.");
}

function stopServer(array $server): void
{
    proc_terminate($server['process']);
    proc_close($server['process']);
    @unlink($server['log']);
}

/**
 * Sends an HTTP request and returns [status code, decoded JSON, raw body].
 */
function request(string $method, string $url, array $query = []): array
{
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 30]]);
    $body = (string) @file_get_contents($url, false, $context);

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
            $status = (int) $match[1];
        }
    }

    return [$status, json_decode($body, true), $body];
}

function describe(int $status, ?array $json): string
{
    $message = $json['error'] ?? (($json['success'] ?? false) ? 'success' : 'no JSON body');
    return "HTTP {$status} - {$message}";
}

/**
 * Row counts of every warehouse table, used to prove injection attempts
 * did not modify the database.
 */
function tableRowCounts(): array
{
    $pdo = getDbConnection();
    $counts = [];
    foreach (['accounts', 'products', 'sales_pipeline', 'sales_teams'] as $table) {
        $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    return $counts;
}

function formatCounts(array $counts): string
{
    return implode(', ', array_map(fn ($t, $n) => "{$t}={$n}", array_keys($counts), $counts));
}

// -----------------------------------------------------------------------
// Test cases
// -----------------------------------------------------------------------

function buildTests(array $app, array $brokenDb, array &$countsBefore): array
{
    $unmodified = function () use (&$countsBefore): array {
        $after = tableRowCounts();
        return [$after === $countsBefore, formatCounts($after)];
    };

    return [
        [
            'name'     => 'Unknown action',
            'input'    => 'action=drop_table_students',
            'expected' => 'HTTP 400, request rejected before DB access',
            // Sent to a server whose database is unreachable: a 400 (not 500)
            // proves the request was rejected before any database access.
            'run'      => function () use ($brokenDb): array {
                [$status, $json] = request('GET', $brokenDb['url'], ['action' => 'drop_table_students']);
                $pass = $status === 400 && ($json['success'] ?? true) === false
                    && str_contains($json['error'] ?? '', 'Allowed actions');
                return [$pass, describe($status, $json) . ' (database unreachable, so no DB access occurred)'];
            },
        ],
        [
            'name'     => 'SQL injection via filter',
            'input'    => "deal_stage=Won' OR '1'='1",
            'expected' => 'HTTP 400, rejected by enum whitelist; tables confirmed unmodified afterwards',
            'run'      => function () use ($app, $unmodified): array {
                [$status, $json] = request('GET', $app['url'], [
                    'action' => 'sales_opportunities_report', 'deal_stage' => "Won' OR '1'='1",
                ]);
                [$intact, $counts] = $unmodified();
                $pass = $status === 400 && str_contains($json['error'] ?? '', 'Invalid deal_stage') && $intact;
                return [$pass, describe($status, $json) . "; tables unchanged: {$counts}"];
            },
        ],
        [
            'name'     => 'SQL injection via numeric filter',
            'input'    => 'year_from=1990; DROP TABLE accounts;--',
            'expected' => 'HTTP 400, rejected by numeric validation; tables confirmed unmodified afterwards',
            'run'      => function () use ($app, $unmodified): array {
                [$status, $json] = request('GET', $app['url'], [
                    'action' => 'establishment_year_revenue', 'year_from' => '1990; DROP TABLE accounts;--',
                ]);
                [$intact, $counts] = $unmodified();
                $pass = $status === 400 && ($json['error'] ?? '') === 'Invalid year_from value.' && $intact;
                return [$pass, describe($status, $json) . "; tables unchanged: {$counts}"];
            },
        ],
        [
            'name'     => 'Invalid type',
            'input'    => 'year_from=abc',
            'expected' => 'HTTP 400, rejected by ctype_digit() check',
            'run'      => function () use ($app): array {
                [$status, $json] = request('GET', $app['url'], ['action' => 'establishment_year_revenue', 'year_from' => 'abc']);
                return [$status === 400 && ($json['error'] ?? '') === 'Invalid year_from value.', describe($status, $json)];
            },
        ],
        [
            'name'     => 'Wrong HTTP method',
            'input'    => 'PUT /queryEngine.php',
            'expected' => 'HTTP 405, Method not allowed',
            'run'      => function () use ($app): array {
                [$status, $json] = request('PUT', $app['url'], ['action' => 'products_report']);
                return [$status === 405 && ($json['error'] ?? '') === 'Method not allowed', describe($status, $json)];
            },
        ],
        [
            'name'     => 'Missing action',
            'input'    => '(no parameters)',
            'expected' => "HTTP 400, generic 'invalid action' message",
            'run'      => function () use ($app): array {
                [$status, $json] = request('GET', $app['url']);
                $pass = $status === 400 && str_contains($json['error'] ?? '', 'Missing or invalid "action" parameter');
                return [$pass, describe($status, $json)];
            },
        ],
        [
            'name'     => 'Forced DB error',
            'input'    => 'DB unreachable (engine pointed at a non-existent database)',
            'expected' => 'HTTP 500, generic message only; raw error logged server-side, never returned to client',
            'run'      => function () use ($brokenDb): array {
                [$status, $json, $body] = request('GET', $brokenDb['url'], ['action' => 'products_report']);
                usleep(200_000);
                $log = (string) file_get_contents($brokenDb['log']);
                $loggedServerSide = str_contains($log, '[queryEngine] Database error');
                $leaked = str_contains($body, 'SQLSTATE') || str_contains($body, 'qe_test_missing_db');
                $pass = $status === 500 && ($json['error'] ?? '') === GENERIC_DB_ERROR && $loggedServerSide && !$leaked;
                return [$pass, describe($status, $json)
                    . '; raw error in server log: ' . ($loggedServerSide ? 'yes' : 'no')
                    . '; raw error in response: ' . ($leaked ? 'yes' : 'no')];
            },
        ],
        [
            'name'     => 'Dashboard ignores injected parameters',
            'input'    => "action=dashboard_summary&deal_stage=' OR 1=1;--&year_from=0 UNION SELECT",
            'expected' => 'HTTP 200, same aggregates as a clean request; tables confirmed unmodified afterwards',
            'run'      => function () use ($app, $unmodified): array {
                [$cleanStatus, $clean] = request('GET', $app['url'], ['action' => 'dashboard_summary']);
                [$status, $json] = request('GET', $app['url'], [
                    'action' => 'dashboard_summary', 'deal_stage' => "' OR 1=1;--", 'year_from' => '0 UNION SELECT',
                ]);
                [$intact, $counts] = $unmodified();
                $same = $cleanStatus === 200
                    && ($json['data']['sections'] ?? null) === ($clean['data']['sections'] ?? false);
                $pass = $status === 200 && ($json['success'] ?? false) === true && $same && $intact;
                return [$pass, describe($status, $json) . '; identical to clean request: ' . ($same ? 'yes' : 'no')
                    . "; tables unchanged: {$counts}"];
            },
        ],
    ];
}

// -----------------------------------------------------------------------
// Runner
// -----------------------------------------------------------------------

$args     = array_slice($argv, 1);
$markdown = in_array('--markdown', $args, true);
$listOnly = in_array('--list', $args, true);
$selected = array_map('intval', array_values(array_filter($args, 'ctype_digit')));
$fast     = in_array('--fast', $args, true);

if (!ini_get('date.timezone') && preg_match('#zoneinfo/(.+)$#', (string) @readlink('/etc/localtime'), $tz)) {
    @date_default_timezone_set($tz[1]);
}

define('IS_TTY', function_exists('stream_isatty') && stream_isatty(STDOUT));
define('USE_COLOR', IS_TTY && getenv('NO_COLOR') === false && !in_array('--no-color', $args, true));
define('TERM_WIDTH', max(70, min(120, (int) (getenv('COLUMNS') ?: (IS_TTY ? exec('tput cols 2>/dev/null') : 0)) ?: 100)));
define('NAME_WIDTH', 40);

function style(string $text, string ...$styles): string
{
    static $codes = [
        'bold' => '1', 'dim' => '2', 'red' => '31', 'green' => '32', 'yellow' => '33',
        'cyan' => '36', 'gray' => '90', 'passBadge' => '1;30;42', 'failBadge' => '1;97;41',
    ];
    if (!USE_COLOR || !$styles) {
        return $text;
    }
    return "\033[" . implode(';', array_map(fn ($s) => $codes[$s], $styles)) . "m{$text}\033[0m";
}

function detailLine(string $label, string $text, string ...$styles): void
{
    $indent = str_repeat(' ', 9);
    $lines = explode("\n", wordwrap($text, TERM_WIDTH - 20, "\n", true));
    foreach ($lines as $i => $line) {
        $prefix = $i === 0 ? style(str_pad($label, 10), 'gray') : str_repeat(' ', 10);
        echo "{$indent}{$prefix}" . style($line, ...$styles) . "\n";
    }
}

function testTitle(int $id, int $total, string $name): string
{
    $counter = str_pad("{$id}/{$total}", 5);
    $dots = str_repeat('·', max(2, NAME_WIDTH - mb_strlen($name)));
    return style($counter, 'gray') . ' ' . style($name, 'bold') . ' ' . style($dots, 'gray');
}

function banner(string $title): void
{
    $width = mb_strlen($title) + 4;
    echo "\n  " . style('╭' . str_repeat('─', $width) . '╮', 'cyan') . "\n";
    echo '  ' . style('│', 'cyan') . '  ' . style($title, 'bold') . '  ' . style('│', 'cyan') . "\n";
    echo '  ' . style('╰' . str_repeat('─', $width) . '╯', 'cyan') . "\n\n";
}

$placeholder = ['url' => '', 'log' => ''];
$noCounts = [];
$allTests = buildTests($placeholder, $placeholder, $noCounts);

if ($listOnly) {
    banner('Query Engine · Security Test Suite');
    foreach ($allTests as $i => $test) {
        printf("  %s  %s\n", style(str_pad((string) ($i + 1), 2, ' ', STR_PAD_LEFT), 'cyan'), $test['name']);
    }
    echo "\n  Run one test:  " . style('php tests/security_tests.php 3', 'bold') . "\n\n";
    exit(0);
}

foreach ($selected as $id) {
    if ($id < 1 || $id > count($allTests)) {
        fwrite(STDERR, "Unknown test number {$id}. Use --list to see the " . count($allTests) . " available tests.\n");
        exit(1);
    }
}

banner('Query Engine · Security Test Suite');

try {
    $countsBefore = tableRowCounts();
} catch (Throwable $e) {
    echo '  ' . style(' ERROR ', 'failBadge') . ' Cannot reach the database configured in .env: '
        . style($e->getMessage(), 'red') . "\n\n";
    exit(1);
}

detailLine('Database', DB_HOST . ':' . DB_PORT . '/' . DB_NAME);
detailLine('Baseline', formatCounts($countsBefore));
detailLine('Started', date('Y-m-d H:i:s T'));
echo "\n";

$app = startServer();
$brokenDb = startServer(['CRM_DB_NAME' => 'qe_test_missing_db']);

$tests = buildTests($app, $brokenDb, $countsBefore);
$toRun = $selected ?: range(1, count($tests));
$total = count($tests);
$results = [];
$suiteStart = microtime(true);

try {
    foreach ($toRun as $id) {
        $test = $tests[$id - 1];
        $title = testTitle($id, $total, $test['name']);

        if (IS_TTY) {
            echo '  ' . style('◌', 'yellow') . " {$title} " . style('running…', 'yellow');
        }

        $start = microtime(true);
        try {
            [$pass, $actual] = $test['run']();
        } catch (Throwable $e) {
            [$pass, $actual] = [false, 'Test error: ' . $e->getMessage()];
        }
        $elapsed = sprintf('%.2fs', microtime(true) - $start);

        if (IS_TTY) {
            // Keep the spinner visible briefly so progress is readable;
            // the reported time is the real test duration.
            $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
            for ($f = 0; !$fast && microtime(true) - $start < 0.4; $f++) {
                echo "\r  " . style($frames[$f % count($frames)], 'yellow') . " {$title} " . style('running…', 'yellow');
                usleep(60_000);
            }
            echo "\r\033[2K";
        }

        $icon = $pass ? style('✔', 'green', 'bold') : style('✘', 'red', 'bold');
        $badge = $pass ? style(' PASS ', 'passBadge') : style(' FAIL ', 'failBadge');
        echo "  {$icon} {$title} {$badge} " . style($elapsed, 'gray') . "\n";
        detailLine('Input', $test['input']);
        detailLine('Expected', $test['expected']);
        detailLine('Actual', $actual, $pass ? 'green' : 'red');
        echo "\n";

        $results[] = ['id' => $id, 'pass' => $pass, 'actual' => $actual] + $test;
    }
} finally {
    stopServer($app);
    stopServer($brokenDb);
}

$passed = count(array_filter($results, fn ($r) => $r['pass']));
$failed = count($results) - $passed;
$duration = sprintf('%.2fs', microtime(true) - $suiteStart);

echo '  ' . style(str_repeat('─', 60), 'gray') . "\n";
echo '  ' . style("✔ {$passed} passed", 'green', 'bold')
    . style('   ·   ', 'gray')
    . style("✘ {$failed} failed", $failed ? 'red' : 'gray', 'bold')
    . style('   ·   ', 'gray')
    . count($results) . ' run'
    . style("   ·   {$duration}", 'gray') . "\n";
echo '  ' . ($failed === 0
        ? style(' ALL TESTS PASSED ', 'passBadge')
        : style(" {$failed} TEST" . ($failed === 1 ? '' : 'S') . ' FAILED ', 'failBadge')) . "\n\n";

if ($markdown) {
    $cell = fn (string $text) => str_replace('|', '\|', $text);
    echo "\n| # | Test case | Input | Expected result | Actual result | Status |\n";
    echo "|---|---|---|---|---|---|\n";
    foreach ($results as $r) {
        printf("| %d | %s | `%s` | %s | %s | %s |\n",
            $r['id'], $cell($r['name']), $cell($r['input']), $cell($r['expected']),
            $cell($r['actual']), $r['pass'] ? 'PASS' : 'FAIL');
    }
}

exit($passed === count($results) ? 0 : 1);

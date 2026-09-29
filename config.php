<?php
/**
 * config.php
 * -----------------------------------------------------------------------
 * Database configuration and connection factory for the CRM Data Warehouse
 * Query Engine (Part 3 - ICT934 Assessment 3).
 *
 * SECURITY NOTES:
 *  - Uses PDO with the MySQL native driver and prepared statements
 *    (see queryEngine.php) to prevent SQL injection.
 *  - Credentials are read from a local .env file (or real environment
 *    variables, which take priority) so this file can be committed
 *    without leaking the real password. Keep .env out of version control.
 *  - PDO is configured to throw exceptions on error (caught centrally in
 *    queryEngine.php) rather than leaking MySQL errors to the client.
 *  - Emulated prepares are disabled so placeholders are sent to MySQL as
 *    real bind parameters, not string-substituted by the PHP driver.
 * -----------------------------------------------------------------------
 */

/**
 * Loads KEY=VALUE pairs from a .env file into the process environment.
 * Variables already set in the real environment are not overwritten.
 */
function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}

loadEnvFile(__DIR__ . '/.env');

// Fallbacks are only used when a value is missing from both .env and the
// real environment; they match the local Docker MySQL (docker-compose.yml).
define('DB_HOST', getenv('CRM_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('CRM_DB_PORT') ?: '3306');
define('DB_NAME', getenv('CRM_DB_NAME') ?: 'crm_saless');
define('DB_USER', getenv('CRM_DB_USER') ?: 'root');
define('DB_PASS', getenv('CRM_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Hosted MySQL (e.g. TiDB Cloud) rejects unencrypted connections; set
// CRM_DB_SSL=true there. CRM_DB_SSL_CA overrides the CA bundle path.
define('DB_SSL', filter_var(getenv('CRM_DB_SSL'), FILTER_VALIDATE_BOOLEAN));
define('DB_SSL_CA', getenv('CRM_DB_SSL_CA') ?: '');

/**
 * Returns the CA bundle used to verify the database server's certificate.
 *
 * @throws RuntimeException if no CA bundle can be found
 */
function getSslCaPath(): string
{
    $candidates = DB_SSL_CA !== '' ? [DB_SSL_CA] : [
        '/etc/ssl/certs/ca-certificates.crt', // Debian/Ubuntu (Docker image)
        '/etc/ssl/cert.pem',                  // macOS, Alpine
        '/etc/pki/tls/certs/ca-bundle.crt',   // RHEL/Fedora
    ];

    foreach ($candidates as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }

    throw new RuntimeException('CRM_DB_SSL is enabled but no readable CA bundle was found.');
}

/**
 * Returns a shared PDO connection (singleton) to the CRM data warehouse.
 *
 * @return PDO
 * @throws PDOException if the connection cannot be established
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // fail loudly, caught centrally
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements -> stronger injection protection
        PDO::ATTR_PERSISTENT         => false,
    ];

    if (DB_SSL) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = getSslCaPath();
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    return $pdo;
}

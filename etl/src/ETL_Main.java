import java.io.IOException;
import java.math.BigDecimal;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Objects;

/**
 * Entry point for the CRM Data Warehouse ETL.
 *
 * Usage: java -cp <classes>:<mysql-connector-j.jar> ETL_Main [datasetDir] [--dry-run]
 * (datasetDir defaults to Dataset_CRM_Sales_Opportunities)
 *
 * Process:
 *   1. EXTRACT       parse the XML and CSV source files
 *   2. STAGE         land the raw pipeline rows in sales_pipeline_staging
 *   3. PROFILE       assess the quality of the raw data (nothing is changed)
 *   4. TRANSFORM     clean, standardise and validate every record
 *   5. QUALITY GATE  stop before loading if too many records were rejected
 *   6. LOAD          replace the warehouse data in one transaction
 *   7. RECONCILE     check the warehouse against the source and business rules
 *
 * --dry-run runs steps 1, 3, 4 and 5 only and never connects to the database.
 * A full report is written to etl/reports/etl_report.txt and rejected records
 * to etl/reports/rejected_records.csv.
 *
 * Database settings are read from the same CRM_DB_* variables the query
 * engine uses: real environment variables first, then the project's .env.
 *   CRM_DB_HOST, CRM_DB_PORT, CRM_DB_NAME, CRM_DB_USER, CRM_DB_PASS,
 *   CRM_DB_SSL=true  (encrypted, certificate-verified connection, e.g. TiDB Cloud)
 * ETL_MAX_REJECT_PERCENT (default 5) sets the quality-gate threshold per source.
 *
 * Exit codes: 0 success, 1 quality gate or reconciliation failed, 2 error.
 */
public class ETL_Main {

    private static final Path REPORT_DIR = Path.of("etl", "reports");
    private static final StringBuilder report = new StringBuilder();

    public static void main(String[] args) {
        boolean dryRun = List.of(args).contains("--dry-run");
        String datasetDir = List.of(args).stream().filter(a -> !a.startsWith("--")).findFirst()
                .orElse("Dataset_CRM_Sales_Opportunities");
        int exitCode;
        try {
            exitCode = run(Path.of(datasetDir), dryRun) ? 0 : 1;
        } catch (Exception e) {
            out("\nETL FAILED: " + e.getMessage());
            exitCode = 2;
        }
        writeReport();
        System.exit(exitCode);
    }

    private static boolean run(Path dataset, boolean dryRun) throws Exception {
        long started = System.currentTimeMillis();
        Map<String, String> config = loadConfig(Path.of(".env"));
        out("CRM Data Warehouse ETL - " + LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
                + (dryRun ? "  (DRY RUN - database not touched)" : ""));

        // ---------------- 1. EXTRACT ----------------
        heading("1. EXTRACT");
        XMLParser xml = new XMLParser();
        CSVParser csv = new CSVParser();
        Map<String, List<Map<String, String>>> raw = new LinkedHashMap<>();
        raw.put("accounts.xml", xml.parse(dataset.resolve("accounts.xml").toString()));
        raw.put("sales_teams.xml", xml.parse(dataset.resolve("sales_teams.xml").toString()));
        raw.put("products.csv", csv.parse(dataset.resolve("products.csv").toString()));
        raw.put("sales_pipeline.csv", csv.parse(dataset.resolve("sales_pipeline.csv").toString()));
        raw.forEach((file, rows) -> row(file, rows.size() + " records"));

        MySQLLoader loader = null;
        try {
            // ---------------- 2. STAGE ----------------
            heading("2. STAGE");
            if (dryRun) {
                out("  skipped (dry run)");
            } else {
                String target = config.get("CRM_DB_HOST") + ":" + config.get("CRM_DB_PORT") + "/" + config.get("CRM_DB_NAME");
                row("Target database", target + (isTrue(config.get("CRM_DB_SSL")) ? " (TLS)" : ""));
                loader = new MySQLLoader(jdbcUrl(config), config.get("CRM_DB_USER"), config.get("CRM_DB_PASS"));
                loader.ensureSchema();
                row("sales_pipeline_staging", loader.stageRawPipeline(raw.get("sales_pipeline.csv"))
                        + " raw rows landed (unmodified)");
            }

            // ---------------- 3. PROFILE ----------------
            heading("3. PROFILE (raw data, before cleaning)");
            DataTransformer transformer = new DataTransformer();
            raw.forEach((file, rows) -> printProfile(file, transformer.profile(rows)));
            printKeyChecks(transformer, raw);

            // ---------------- 4. TRANSFORM ----------------
            heading("4. TRANSFORM (clean + validate)");
            List<DataTransformer.Product> products = transformer.transformProducts(raw.get("products.csv"));
            List<DataTransformer.SalesAgent> agents = transformer.transformSalesTeams(raw.get("sales_teams.xml"));
            List<DataTransformer.Account> accounts = transformer.transformAccounts(raw.get("accounts.xml"));
            List<DataTransformer.Opportunity> opportunities =
                    transformer.transformPipeline(raw.get("sales_pipeline.csv"), products, agents, accounts);

            out("  Cleaning applied:");
            if (transformer.getCorrections().isEmpty()) {
                out("    (none)");
            }
            transformer.getCorrections().forEach((change, count) -> out(String.format("    %6d x  %s", count, change)));

            out("  Validation:");
            row("  accepted", products.size() + " products, " + agents.size() + " sales agents, "
                    + accounts.size() + " accounts, " + opportunities.size() + " opportunities");
            row("  rejected", transformer.getRejections().size() + " records");
            transformer.getRejections().forEach(r -> out("    - " + r));
            row("  warnings", transformer.getWarnings().size() + " records (loaded, flagged for review)");
            transformer.getWarnings().forEach(w -> out("    - " + w));
            writeRejections(transformer.getRejections());

            // ---------------- 5. QUALITY GATE ----------------
            heading("5. QUALITY GATE");
            Map<String, Integer> accepted = new LinkedHashMap<>();
            accepted.put("accounts.xml", accounts.size());
            accepted.put("sales_teams.xml", agents.size());
            accepted.put("products.csv", products.size());
            accepted.put("sales_pipeline.csv", opportunities.size());
            if (!qualityGate(raw, accepted)) {
                heading("ETL STOPPED - data did not pass the quality gate; warehouse not changed");
                return false;
            }
            if (dryRun) {
                heading("DRY RUN COMPLETE - data is ready to load");
                return true;
            }

            // ---------------- 6. LOAD ----------------
            heading("6. LOAD");
            Map<String, Integer> loaded = loader.replaceWarehouse(products, agents, accounts, opportunities);
            loaded.forEach((table, n) -> row(table, n + " rows loaded"));
            row("Transaction", "committed");

            // ---------------- 7. RECONCILE ----------------
            heading("7. RECONCILE");
            boolean ok = reconcile(loader, raw, accepted, opportunities);

            heading(ok ? "ETL COMPLETED SUCCESSFULLY" : "ETL COMPLETED - RECONCILIATION FAILED");
            out(String.format("  Finished in %.1fs", (System.currentTimeMillis() - started) / 1000.0));
            return ok;
        } finally {
            if (loader != null) {
                loader.close();
            }
        }
    }

    // -------------------------------------------------------------------
    // Profiling output
    // -------------------------------------------------------------------

    private static void printProfile(String file, List<DataTransformer.ColumnProfile> profiles) {
        out("\n  " + file);
        out(String.format("    %-18s %7s %7s %8s %6s %7s  %s",
                "column", "missing", "spaces", "distinct", "case", "invalid", "values"));
        for (DataTransformer.ColumnProfile p : profiles) {
            out(String.format("    %-18s %7d %7d %8d %6d %7d  %s",
                    p.column(), p.missing(), p.whitespace(), p.distinct(), p.caseVariants(), p.invalid(), p.values()));
        }
    }

    private static void printKeyChecks(DataTransformer t, Map<String, List<Map<String, String>>> raw) {
        out("\n  Key and reference checks");
        row("  duplicate keys", "accounts=" + t.duplicateKeys(raw.get("accounts.xml"), "account")
                + ", sales_teams=" + t.duplicateKeys(raw.get("sales_teams.xml"), "sales_agent")
                + ", products=" + t.duplicateKeys(raw.get("products.csv"), "product")
                + ", sales_pipeline=" + t.duplicateKeys(raw.get("sales_pipeline.csv"), "opportunity_id"));
        String[][] references = {
            {"sales_pipeline.csv", "sales_agent", "sales_teams.xml", "sales_agent"},
            {"sales_pipeline.csv", "product", "products.csv", "product"},
            {"sales_pipeline.csv", "account", "accounts.xml", "account"},
            {"accounts.xml", "subsidiary_of", "accounts.xml", "account"},
        };
        for (String[] ref : references) {
            Map<String, Integer> unmatched = t.unmatchedReferences(raw.get(ref[0]), ref[1], raw.get(ref[2]), ref[3]);
            int total = unmatched.values().stream().mapToInt(Integer::intValue).sum();
            out(String.format("    %-44s %s", ref[0] + "." + ref[1] + " -> " + ref[2],
                    total == 0 ? "all match" : total + " unmatched " + unmatched));
        }
    }

    // -------------------------------------------------------------------
    // Quality gate and reconciliation
    // -------------------------------------------------------------------

    private static boolean qualityGate(Map<String, List<Map<String, String>>> raw, Map<String, Integer> accepted) {
        double maxPercent = Double.parseDouble(Objects.requireNonNullElse(System.getenv("ETL_MAX_REJECT_PERCENT"), "5"));
        boolean pass = true;
        for (Map.Entry<String, List<Map<String, String>>> source : raw.entrySet()) {
            int total = source.getValue().size();
            int rejected = total - accepted.get(source.getKey());
            double percent = total == 0 ? 0 : 100.0 * rejected / total;
            boolean ok = total > 0 && percent <= maxPercent;
            pass &= ok;
            row(source.getKey(), String.format("%d/%d rejected (%.1f%%, limit %.1f%%) %s",
                    rejected, total, percent, maxPercent, ok ? "[PASS]" : "[FAIL]"));
        }
        return pass;
    }

    private static boolean reconcile(MySQLLoader db, Map<String, List<Map<String, String>>> raw,
                                     Map<String, Integer> accepted,
                                     List<DataTransformer.Opportunity> opportunities) throws Exception {
        List<Boolean> results = new ArrayList<>();

        out("  Row counts (source = loaded + rejected)");
        String[][] tables = {
            {"accounts", "accounts.xml"}, {"sales_teams", "sales_teams.xml"},
            {"products", "products.csv"}, {"sales_pipeline", "sales_pipeline.csv"},
        };
        for (String[] t : tables) {
            int inDb = Integer.parseInt(db.queryValue("SELECT COUNT(*) FROM " + t[0]));
            int source = raw.get(t[1]).size();
            int rejected = source - accepted.get(t[1]);
            results.add(check(t[0], inDb == accepted.get(t[1]),
                    source + " source = " + inDb + " loaded + " + rejected + " rejected"));
        }
        int staged = Integer.parseInt(db.queryValue("SELECT COUNT(*) FROM sales_pipeline_staging"));
        results.add(check("staging", staged == raw.get("sales_pipeline.csv").size(), staged + " raw rows"));

        out("  Value totals");
        BigDecimal expectedWon = opportunities.stream().filter(o -> o.dealStage().equals("Won"))
                .map(DataTransformer.Opportunity::closeValue).reduce(BigDecimal.ZERO, BigDecimal::add);
        BigDecimal stagedWon = decimal(db.queryValue(
                "SELECT SUM(CAST(close_value AS DECIMAL(15,2))) FROM sales_pipeline_staging "
                        + "WHERE deal_stage = 'Won' AND close_value <> ''"));
        BigDecimal loadedWon = decimal(db.queryValue(
                "SELECT SUM(close_value) FROM sales_pipeline WHERE deal_stage = 'Won'"));
        results.add(check("Won value", loadedWon.compareTo(expectedWon) == 0,
                "warehouse " + loadedWon + " / staging (raw) " + stagedWon));
        row("  Deal stages", db.queryValue(
                "SELECT GROUP_CONCAT(CONCAT(deal_stage, '=', n) ORDER BY deal_stage SEPARATOR ', ') "
                        + "FROM (SELECT deal_stage, COUNT(*) n FROM sales_pipeline GROUP BY deal_stage) s"));

        out("  Referential integrity (orphan rows)");
        results.add(zero(db, "pipeline -> sales_teams",
                "SELECT COUNT(*) FROM sales_pipeline sp LEFT JOIN sales_teams t ON t.sales_agent = sp.sales_agent "
                        + "WHERE t.sales_agent IS NULL"));
        results.add(zero(db, "pipeline -> products",
                "SELECT COUNT(*) FROM sales_pipeline sp LEFT JOIN products p ON p.product = sp.product "
                        + "WHERE p.product IS NULL"));
        results.add(zero(db, "pipeline -> accounts",
                "SELECT COUNT(*) FROM sales_pipeline sp LEFT JOIN accounts a ON a.account = sp.account "
                        + "WHERE sp.account IS NOT NULL AND a.account IS NULL"));
        results.add(zero(db, "accounts -> parent",
                "SELECT COUNT(*) FROM accounts a LEFT JOIN accounts p ON p.account = a.subsidiary_of "
                        + "WHERE a.subsidiary_of IS NOT NULL AND p.account IS NULL"));

        out("  Business rules");
        results.add(zero(db, "closed deal incomplete",
                "SELECT COUNT(*) FROM sales_pipeline WHERE deal_stage IN ('Won', 'Lost') AND (account IS NULL "
                        + "OR engage_date IS NULL OR close_date IS NULL OR close_value IS NULL)"));
        results.add(zero(db, "close before engage",
                "SELECT COUNT(*) FROM sales_pipeline WHERE close_date < engage_date"));
        results.add(zero(db, "open deal with close",
                "SELECT COUNT(*) FROM sales_pipeline WHERE deal_stage IN ('Prospecting', 'Engaging') "
                        + "AND (close_date IS NOT NULL OR close_value IS NOT NULL)"));
        results.add(zero(db, "invalid deal stage",
                "SELECT COUNT(*) FROM sales_pipeline "
                        + "WHERE deal_stage NOT IN ('Prospecting', 'Engaging', 'Won', 'Lost') OR deal_stage IS NULL"));
        results.add(zero(db, "negative amounts",
                "SELECT (SELECT COUNT(*) FROM sales_pipeline WHERE close_value < 0) "
                        + "+ (SELECT COUNT(*) FROM products WHERE sales_price < 0) "
                        + "+ (SELECT COUNT(*) FROM accounts WHERE revenue < 0 OR employees < 0)"));

        return !results.contains(false);
    }

    private static boolean zero(MySQLLoader db, String label, String sql) throws Exception {
        String count = db.queryValue(sql);
        return check(label, "0".equals(count), count + " rows");
    }

    private static boolean check(String label, boolean ok, String detail) {
        out(String.format("    %-24s %-45s %s", label, detail, ok ? "[OK]" : "[FAIL]"));
        return ok;
    }

    private static BigDecimal decimal(String value) {
        return value == null ? BigDecimal.ZERO : new BigDecimal(value);
    }

    // -------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------

    private static Map<String, String> loadConfig(Path envFile) throws IOException {
        Map<String, String> config = new HashMap<>();
        if (Files.isReadable(envFile)) {
            for (String line : Files.readAllLines(envFile)) {
                line = line.trim();
                int eq = line.indexOf('=');
                if (line.isEmpty() || line.startsWith("#") || eq < 0) {
                    continue;
                }
                String value = line.substring(eq + 1).trim().replaceAll("^[\"']|[\"']$", "");
                config.put(line.substring(0, eq).trim(), value);
            }
        }
        for (String key : List.of("CRM_DB_HOST", "CRM_DB_PORT", "CRM_DB_NAME", "CRM_DB_USER", "CRM_DB_PASS", "CRM_DB_SSL")) {
            String fromEnvironment = System.getenv(key);
            if (fromEnvironment != null) {
                config.put(key, fromEnvironment);
            }
        }
        config.putIfAbsent("CRM_DB_HOST", "127.0.0.1");
        config.putIfAbsent("CRM_DB_PORT", "3306");
        config.putIfAbsent("CRM_DB_NAME", "crm_sales");
        config.putIfAbsent("CRM_DB_USER", "root");
        config.putIfAbsent("CRM_DB_PASS", "");

        // When the ETL runs inside Docker, "localhost" is the container itself.
        String hostOverride = System.getenv("CRM_DB_HOST_OVERRIDE");
        String host = config.get("CRM_DB_HOST");
        if (hostOverride != null && (host.equals("127.0.0.1") || host.equals("localhost"))) {
            config.put("CRM_DB_HOST", hostOverride);
        }
        return config;
    }

    private static String jdbcUrl(Map<String, String> config) {
        String security = isTrue(config.get("CRM_DB_SSL"))
                ? "sslMode=VERIFY_IDENTITY"
                : "sslMode=PREFERRED&allowPublicKeyRetrieval=true";
        return "jdbc:mysql://" + config.get("CRM_DB_HOST") + ":" + config.get("CRM_DB_PORT") + "/"
                + config.get("CRM_DB_NAME") + "?" + security
                + "&characterEncoding=UTF-8&rewriteBatchedStatements=true";
    }

    private static boolean isTrue(String value) {
        return value != null && List.of("true", "1", "yes", "on").contains(value.trim().toLowerCase());
    }

    // -------------------------------------------------------------------
    // Output and reports
    // -------------------------------------------------------------------

    private static void out(String line) {
        System.out.println(line);
        report.append(line).append(System.lineSeparator());
    }

    private static void heading(String title) {
        out("\n== " + title + " " + "=".repeat(Math.max(3, 70 - title.length())));
    }

    private static void row(String label, String value) {
        out(String.format("  %-24s %s", label, value));
    }

    private static void writeReport() {
        try {
            Files.createDirectories(REPORT_DIR);
            Files.writeString(REPORT_DIR.resolve("etl_report.txt"), report);
        } catch (IOException e) {
            System.err.println("Could not write ETL report: " + e.getMessage());
        }
    }

    private static void writeRejections(List<String> rejections) throws IOException {
        Files.createDirectories(REPORT_DIR);
        StringBuilder csv = new StringBuilder("source,record,reason").append(System.lineSeparator());
        for (String r : rejections) {
            String[] parts = r.split(" record |: ", 3);
            csv.append(parts[0]).append(',').append(parts[1]).append(",\"")
                    .append(parts[2].replace("\"", "\"\"")).append('"').append(System.lineSeparator());
        }
        Files.writeString(REPORT_DIR.resolve("rejected_records.csv"), csv);
    }
}

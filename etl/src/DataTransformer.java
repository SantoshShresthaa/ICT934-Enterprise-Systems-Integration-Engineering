import java.math.BigDecimal;
import java.time.LocalDate;
import java.time.Year;
import java.time.format.DateTimeParseException;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Objects;
import java.util.Set;
import java.util.TreeMap;
import java.util.TreeSet;
import java.util.stream.Collectors;

/**
 * Transform step, run in three stages before anything is loaded:
 *
 *  1. PROFILE  - {@link #profile}, {@link #duplicateKeys} and
 *                {@link #unmatchedReferences} assess the RAW extracted data
 *                (missing values, stray whitespace, inconsistent casing,
 *                invalid numbers/dates, duplicate keys, orphan references)
 *                without changing it.
 *  2. CLEAN    - trim, standardise, correct and convert every field.
 *  3. VALIDATE - enforce types, domains, business rules and referential
 *                integrity; records that fail are rejected with a reason.
 *
 * Every change is counted in {@link #getCorrections()}, every rejected record
 * is listed with its reason in {@link #getRejections()}, and suspicious but
 * loadable values are listed in {@link #getWarnings()}, so each ETL run
 * produces an auditable data-quality report.
 *
 * Cleaning and validation rules:
 *  - Trim whitespace; empty values become NULL.
 *  - accounts.sector: fix known misspellings, title-case ("technolgy" -> "Technology").
 *  - accounts.office_location: fix known misspellings ("Philipines" -> "Philippines").
 *  - accounts.subsidiary_of: must name an existing account, otherwise NULL.
 *  - sales_pipeline.product: matched to the product catalogue ignoring case,
 *    spaces and punctuation ("GTXPro" -> "GTX Pro").
 *  - sales_pipeline.deal_stage: must be Prospecting, Engaging, Won or Lost.
 *  - sales_pipeline: sales agent and account must exist; dates must be valid
 *    ISO dates, not in the future; closed deals (Won/Lost) need an account,
 *    engage date, close date >= engage date and a non-negative close value;
 *    open deals must not have a close date or value.
 *  - Warning only: a Won deal whose value is below half or above double the
 *    product's list price.
 */
public class DataTransformer {

    public record Product(String product, String series, BigDecimal salesPrice) {}

    public record SalesAgent(String salesAgent, String manager, String regionalOffice) {}

    public record Account(String account, String sector, Integer yearEstablished, BigDecimal revenue,
                          Integer employees, String officeLocation, String subsidiaryOf) {}

    public record Opportunity(String opportunityId, String salesAgent, String product, String account,
                              String dealStage, LocalDate engageDate, LocalDate closeDate, BigDecimal closeValue) {}

    private static final List<String> DEAL_STAGES = List.of("Prospecting", "Engaging", "Won", "Lost");
    private static final Set<String> CLOSED_STAGES = Set.of("Won", "Lost");
    private static final Map<String, String> SPELLING_CORRECTIONS = Map.of(
            "technolgy", "technology",
            "philipines", "philippines");

    private static final Set<String> NUMERIC_COLUMNS =
            Set.of("sales_price", "year_established", "revenue", "employees", "close_value");
    private static final Set<String> DATE_COLUMNS = Set.of("engage_date", "close_date");
    private static final int MAX_LISTED_VALUES = 15;

    /** Data-quality profile of one raw column. */
    public record ColumnProfile(String column, int missing, int whitespace, int distinct,
                                int caseVariants, int invalid, String values) {}

    private final Map<String, Integer> corrections = new TreeMap<>();
    private final List<String> rejections = new ArrayList<>();
    private final List<String> warnings = new ArrayList<>();

    public Map<String, Integer> getCorrections() {
        return corrections;
    }

    public List<String> getRejections() {
        return rejections;
    }

    public List<String> getWarnings() {
        return warnings;
    }

    // -------------------------------------------------------------------
    // Profiling (raw data, read-only)
    // -------------------------------------------------------------------

    /**
     * Profiles every column of a raw source. A column missing from some
     * records (e.g. an absent XML element) counts as missing for them.
     */
    public List<ColumnProfile> profile(List<Map<String, String>> rows) {
        Set<String> columns = new LinkedHashSet<>();
        rows.forEach(r -> columns.addAll(r.keySet()));

        List<ColumnProfile> profiles = new ArrayList<>();
        for (String column : columns) {
            int missing = 0, whitespace = 0, invalid = 0;
            Set<String> distinct = new TreeSet<>();
            for (Map<String, String> row : rows) {
                String value = row.get(column);
                if (value == null || value.trim().isEmpty()) {
                    missing++;
                    continue;
                }
                if (!value.equals(value.trim())) {
                    whitespace++;
                }
                String v = value.trim();
                distinct.add(v);
                if (NUMERIC_COLUMNS.contains(column) && !isNumber(v)) {
                    invalid++;
                } else if (DATE_COLUMNS.contains(column) && !isDate(v)) {
                    invalid++;
                }
            }
            int caseVariants = distinct.size()
                    - (int) distinct.stream().map(String::toLowerCase).distinct().count();
            boolean categorical = !NUMERIC_COLUMNS.contains(column) && !DATE_COLUMNS.contains(column)
                    && distinct.size() <= MAX_LISTED_VALUES;
            profiles.add(new ColumnProfile(column, missing, whitespace, distinct.size(), caseVariants, invalid,
                    categorical ? String.join(", ", distinct) : ""));
        }
        return profiles;
    }

    /** Number of records whose key value repeats an earlier record's. */
    public int duplicateKeys(List<Map<String, String>> rows, String key) {
        Set<String> seen = new HashSet<>();
        int duplicates = 0;
        for (Map<String, String> row : rows) {
            String value = clean(row.get(key));
            if (value != null && !seen.add(value)) {
                duplicates++;
            }
        }
        return duplicates;
    }

    /**
     * Child values that do not exactly match any parent key, with their
     * frequency. Empty child values are not references and are ignored.
     */
    public Map<String, Integer> unmatchedReferences(List<Map<String, String>> childRows, String childColumn,
                                                    List<Map<String, String>> parentRows, String parentColumn) {
        Set<String> parentKeys = parentRows.stream().map(r -> clean(r.get(parentColumn)))
                .filter(Objects::nonNull).collect(Collectors.toSet());
        Map<String, Integer> unmatched = new TreeMap<>();
        for (Map<String, String> row : childRows) {
            String value = clean(row.get(childColumn));
            if (value != null && !parentKeys.contains(value)) {
                unmatched.merge(value, 1, Integer::sum);
            }
        }
        return unmatched;
    }

    private static boolean isNumber(String value) {
        try {
            new BigDecimal(value);
            return true;
        } catch (NumberFormatException e) {
            return false;
        }
    }

    private static boolean isDate(String value) {
        try {
            LocalDate.parse(value);
            return true;
        } catch (DateTimeParseException e) {
            return false;
        }
    }

    // -------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------

    public List<Product> transformProducts(List<Map<String, String>> rawRows) {
        List<Map<String, String>> rows = trimAll(rawRows, "products");
        Map<String, Product> products = new LinkedHashMap<>();
        int recordNo = 0;
        for (Map<String, String> row : rows) {
            recordNo++;
            try {
                String name = required(row, "product");
                if (products.containsKey(name)) {
                    throw new IllegalArgumentException("duplicate product '" + name + "'");
                }
                products.put(name, new Product(name, clean(row.get("series")),
                        decimal(row.get("sales_price"), "sales_price", true)));
            } catch (IllegalArgumentException e) {
                reject("products.csv", recordNo, e.getMessage());
            }
        }
        return new ArrayList<>(products.values());
    }

    // -------------------------------------------------------------------
    // Sales teams
    // -------------------------------------------------------------------

    public List<SalesAgent> transformSalesTeams(List<Map<String, String>> rawRows) {
        List<Map<String, String>> rows = trimAll(rawRows, "sales_teams");
        Map<String, SalesAgent> agents = new LinkedHashMap<>();
        int recordNo = 0;
        for (Map<String, String> row : rows) {
            recordNo++;
            try {
                String name = required(row, "sales_agent");
                if (agents.containsKey(name)) {
                    throw new IllegalArgumentException("duplicate sales agent '" + name + "'");
                }
                agents.put(name, new SalesAgent(name, clean(row.get("manager")),
                        standardise(row.get("regional_office"), "sales_teams.regional_office")));
            } catch (IllegalArgumentException e) {
                reject("sales_teams.xml", recordNo, e.getMessage());
            }
        }
        return new ArrayList<>(agents.values());
    }

    // -------------------------------------------------------------------
    // Accounts
    // -------------------------------------------------------------------

    public List<Account> transformAccounts(List<Map<String, String>> rawRows) {
        List<Map<String, String>> rows = trimAll(rawRows, "accounts");
        Map<String, Account> accounts = new LinkedHashMap<>();
        int maxYear = Year.now().getValue();
        int recordNo = 0;
        for (Map<String, String> row : rows) {
            recordNo++;
            try {
                String name = required(row, "account");
                if (accounts.containsKey(name)) {
                    throw new IllegalArgumentException("duplicate account '" + name + "'");
                }
                accounts.put(name, new Account(
                        name,
                        standardise(row.get("sector"), "accounts.sector"),
                        integer(row.get("year_established"), "year_established", 1800, maxYear),
                        decimal(row.get("revenue"), "revenue", false),
                        integer(row.get("employees"), "employees", 0, Integer.MAX_VALUE),
                        standardise(row.get("office_location"), "accounts.office_location"),
                        emptyToNull(row.get("subsidiary_of"), "accounts.subsidiary_of")));
            } catch (IllegalArgumentException e) {
                reject("accounts.xml", recordNo, e.getMessage());
            }
        }

        // A parent company must itself be a known account.
        List<Account> result = new ArrayList<>();
        for (Account a : accounts.values()) {
            if (a.subsidiaryOf() != null && !accounts.containsKey(a.subsidiaryOf())) {
                count("accounts.subsidiary_of: unknown parent '" + a.subsidiaryOf() + "' -> NULL");
                a = new Account(a.account(), a.sector(), a.yearEstablished(), a.revenue(),
                        a.employees(), a.officeLocation(), null);
            }
            result.add(a);
        }
        return result;
    }

    // -------------------------------------------------------------------
    // Sales pipeline
    // -------------------------------------------------------------------

    public List<Opportunity> transformPipeline(List<Map<String, String>> rawRows, List<Product> products,
                                               List<SalesAgent> agents, List<Account> accounts) {
        List<Map<String, String>> rows = trimAll(rawRows, "sales_pipeline");
        Map<String, String> productIndex = new HashMap<>();
        Map<String, BigDecimal> listPrices = new HashMap<>();
        for (Product p : products) {
            productIndex.put(matchKey(p.product()), p.product());
            listPrices.put(p.product(), p.salesPrice());
        }
        LocalDate today = LocalDate.now();
        Set<String> agentNames = agents.stream().map(SalesAgent::salesAgent).collect(Collectors.toSet());
        Set<String> accountNames = accounts.stream().map(Account::account).collect(Collectors.toSet());
        Set<String> seenIds = new HashSet<>();

        List<Opportunity> opportunities = new ArrayList<>();
        int recordNo = 0;
        for (Map<String, String> row : rows) {
            recordNo++;
            try {
                String id = required(row, "opportunity_id");
                if (!seenIds.add(id)) {
                    throw new IllegalArgumentException("duplicate opportunity_id '" + id + "'");
                }

                String agent = required(row, "sales_agent");
                if (!agentNames.contains(agent)) {
                    throw new IllegalArgumentException("unknown sales agent '" + agent + "'");
                }

                String product = resolveProduct(required(row, "product"), productIndex);
                String stage = resolveDealStage(required(row, "deal_stage"));
                boolean closed = CLOSED_STAGES.contains(stage);

                String account = emptyToNull(row.get("account"), "sales_pipeline.account");
                if (account != null && !accountNames.contains(account)) {
                    throw new IllegalArgumentException("unknown account '" + account + "'");
                }

                LocalDate engageDate = date(emptyToNull(row.get("engage_date"), "sales_pipeline.engage_date"), "engage_date");
                LocalDate closeDate = date(emptyToNull(row.get("close_date"), "sales_pipeline.close_date"), "close_date");
                BigDecimal closeValue = decimal(emptyToNull(row.get("close_value"), "sales_pipeline.close_value"),
                        "close_value", false);

                for (LocalDate d : new LocalDate[] {engageDate, closeDate}) {
                    if (d != null && d.isAfter(today)) {
                        throw new IllegalArgumentException("date " + d + " is in the future");
                    }
                }

                if (closed) {
                    if (account == null) {
                        throw new IllegalArgumentException(stage + " deal has no account");
                    }
                    if (engageDate == null || closeDate == null || closeValue == null) {
                        throw new IllegalArgumentException(stage + " deal is missing engage_date, close_date or close_value");
                    }
                    if (closeDate.isBefore(engageDate)) {
                        throw new IllegalArgumentException("close_date " + closeDate + " is before engage_date " + engageDate);
                    }
                    BigDecimal listPrice = listPrices.get(product);
                    if (stage.equals("Won") && listPrice != null && listPrice.signum() > 0) {
                        double ratio = closeValue.doubleValue() / listPrice.doubleValue();
                        if (ratio < 0.5 || ratio > 2.0) {
                            warnings.add("sales_pipeline.csv record " + recordNo + " (" + id + "): Won value "
                                    + closeValue + " vs list price " + listPrice + " for " + product);
                        }
                    }
                } else {
                    if (closeDate != null || closeValue != null) {
                        throw new IllegalArgumentException(stage + " deal must not have a close_date or close_value");
                    }
                    if (stage.equals("Engaging") && engageDate == null) {
                        throw new IllegalArgumentException("Engaging deal has no engage_date");
                    }
                }

                opportunities.add(new Opportunity(id, agent, product, account, stage, engageDate, closeDate, closeValue));
            } catch (IllegalArgumentException e) {
                reject("sales_pipeline.csv", recordNo, e.getMessage());
            }
        }
        return opportunities;
    }

    private String resolveProduct(String raw, Map<String, String> productIndex) {
        String canonical = productIndex.get(matchKey(raw));
        if (canonical == null) {
            throw new IllegalArgumentException("unknown product '" + raw + "'");
        }
        if (!canonical.equals(raw)) {
            count("sales_pipeline.product: '" + raw + "' -> '" + canonical + "'");
        }
        return canonical;
    }

    private String resolveDealStage(String raw) {
        for (String stage : DEAL_STAGES) {
            if (stage.equalsIgnoreCase(raw)) {
                if (!stage.equals(raw)) {
                    count("sales_pipeline.deal_stage: '" + raw + "' -> '" + stage + "'");
                }
                return stage;
            }
        }
        throw new IllegalArgumentException("unknown deal_stage '" + raw + "'");
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /** Lower-case, letters and digits only: "GTXPro", "GTX Pro" and "gtx-pro" all match. */
    private static String matchKey(String value) {
        return value.toLowerCase().replaceAll("[^a-z0-9]", "");
    }

    /** Copies the rows with leading/trailing whitespace removed, recording each trimmed field. */
    private List<Map<String, String>> trimAll(List<Map<String, String>> rows, String table) {
        List<Map<String, String>> trimmed = new ArrayList<>(rows.size());
        for (Map<String, String> row : rows) {
            Map<String, String> copy = new LinkedHashMap<>();
            row.forEach((field, value) -> {
                if (value != null && !value.equals(value.trim())) {
                    count(table + "." + field + ": whitespace trimmed");
                    value = value.trim();
                }
                copy.put(field, value);
            });
            trimmed.add(copy);
        }
        return trimmed;
    }

    private static String clean(String value) {
        if (value == null) {
            return null;
        }
        String trimmed = value.trim();
        return trimmed.isEmpty() ? null : trimmed;
    }

    private static String required(Map<String, String> row, String field) {
        String value = clean(row.get(field));
        if (value == null) {
            throw new IllegalArgumentException("missing " + field);
        }
        return value;
    }

    private String emptyToNull(String value, String field) {
        String cleaned = clean(value);
        if (cleaned == null) {
            count(field + ": empty -> NULL");
        }
        return cleaned;
    }

    /** Fixes known misspellings and applies Title Case, recording any change. */
    private String standardise(String value, String field) {
        String original = clean(value);
        if (original == null) {
            return null;
        }
        String corrected = SPELLING_CORRECTIONS.getOrDefault(original.toLowerCase(), original);
        String result = toTitleCase(corrected);
        if (!result.equals(original)) {
            count(field + ": '" + original + "' -> '" + result + "'");
        }
        return result;
    }

    private static String toTitleCase(String value) {
        StringBuilder out = new StringBuilder(value.length());
        boolean startOfWord = true;
        for (char c : value.toCharArray()) {
            out.append(startOfWord ? Character.toUpperCase(c) : Character.toLowerCase(c));
            startOfWord = Character.isWhitespace(c) || c == '-';
        }
        return out.toString();
    }

    private static BigDecimal decimal(String value, String field, boolean required) {
        String cleaned = clean(value);
        if (cleaned == null) {
            if (required) {
                throw new IllegalArgumentException("missing " + field);
            }
            return null;
        }
        try {
            BigDecimal number = new BigDecimal(cleaned);
            if (number.signum() < 0) {
                throw new IllegalArgumentException(field + " is negative: " + cleaned);
            }
            return number;
        } catch (NumberFormatException e) {
            throw new IllegalArgumentException(field + " is not a number: '" + cleaned + "'");
        }
    }

    private static Integer integer(String value, String field, int min, int max) {
        String cleaned = clean(value);
        if (cleaned == null) {
            return null;
        }
        try {
            int number = Integer.parseInt(cleaned);
            if (number < min || number > max) {
                throw new IllegalArgumentException(field + " out of range: " + number);
            }
            return number;
        } catch (NumberFormatException e) {
            throw new IllegalArgumentException(field + " is not a whole number: '" + cleaned + "'");
        }
    }

    private static LocalDate date(String value, String field) {
        if (value == null) {
            return null;
        }
        try {
            return LocalDate.parse(value);
        } catch (DateTimeParseException e) {
            throw new IllegalArgumentException(field + " is not a valid date (YYYY-MM-DD): '" + value + "'");
        }
    }

    private void count(String correction) {
        corrections.merge(correction, 1, Integer::sum);
    }

    private void reject(String source, int recordNo, String reason) {
        rejections.add(source + " record " + recordNo + ": " + reason);
    }
}

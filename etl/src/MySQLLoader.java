import java.math.BigDecimal;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;
import java.sql.Types;
import java.time.LocalDate;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Load step.
 *
 *  1. {@link #ensureSchema()} creates any missing warehouse tables (same
 *     design as sql/warehouse.sql). Existing tables and data are untouched.
 *  2. {@link #stageRawPipeline(List)} lands the raw, untransformed pipeline
 *     rows in sales_pipeline_staging (all text columns) so the source can be
 *     audited and reconciled against the warehouse after the load.
 *  3. {@link #replaceWarehouse} swaps the warehouse contents for the cleaned
 *     data inside ONE transaction: if any insert fails, everything is rolled
 *     back and the previous warehouse data remains in place.
 *
 * Tables are filled parent-first so every foreign key is satisfied:
 * products, sales_teams, accounts, then sales_pipeline. accounts references
 * itself (subsidiary_of), so accounts are inserted first and their parent
 * links are set in a second pass.
 */
public class MySQLLoader implements AutoCloseable {

    private static final int BATCH_SIZE = 1000;

    private static final String[] SCHEMA = {
        """
        CREATE TABLE IF NOT EXISTS products (
          product     varchar(100) NOT NULL,
          series      varchar(100) DEFAULT NULL,
          sales_price decimal(10,2) DEFAULT NULL,
          PRIMARY KEY (product)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4""",
        """
        CREATE TABLE IF NOT EXISTS sales_teams (
          sales_agent     varchar(100) NOT NULL,
          manager         varchar(100) DEFAULT NULL,
          regional_office varchar(100) DEFAULT NULL,
          PRIMARY KEY (sales_agent)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4""",
        """
        CREATE TABLE IF NOT EXISTS accounts (
          account          varchar(100) NOT NULL,
          sector           varchar(100) DEFAULT NULL,
          year_established int DEFAULT NULL,
          revenue          decimal(15,2) DEFAULT NULL,
          employees        int DEFAULT NULL,
          office_location  varchar(100) DEFAULT NULL,
          subsidiary_of    varchar(100) DEFAULT NULL,
          PRIMARY KEY (account),
          KEY subsidiary_of (subsidiary_of),
          CONSTRAINT accounts_ibfk_1 FOREIGN KEY (subsidiary_of) REFERENCES accounts (account)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4""",
        """
        CREATE TABLE IF NOT EXISTS sales_pipeline (
          opportunity_id varchar(50) NOT NULL,
          sales_agent    varchar(100) DEFAULT NULL,
          product        varchar(100) DEFAULT NULL,
          account        varchar(100) DEFAULT NULL,
          deal_stage     varchar(50) DEFAULT NULL,
          engage_date    date DEFAULT NULL,
          close_date     date DEFAULT NULL,
          close_value    decimal(15,2) DEFAULT NULL,
          PRIMARY KEY (opportunity_id),
          KEY sales_agent (sales_agent),
          KEY product (product),
          KEY account (account),
          CONSTRAINT sales_pipeline_ibfk_1 FOREIGN KEY (sales_agent) REFERENCES sales_teams (sales_agent),
          CONSTRAINT sales_pipeline_ibfk_2 FOREIGN KEY (product) REFERENCES products (product),
          CONSTRAINT sales_pipeline_ibfk_3 FOREIGN KEY (account) REFERENCES accounts (account)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4""",
        """
        CREATE TABLE IF NOT EXISTS sales_pipeline_staging (
          opportunity_id varchar(50) DEFAULT NULL,
          sales_agent    varchar(100) DEFAULT NULL,
          product        varchar(100) DEFAULT NULL,
          account        varchar(100) DEFAULT NULL,
          deal_stage     varchar(50) DEFAULT NULL,
          engage_date    varchar(50) DEFAULT NULL,
          close_date     varchar(50) DEFAULT NULL,
          close_value    varchar(50) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4""",
    };

    private static final String[] PIPELINE_COLUMNS = {
        "opportunity_id", "sales_agent", "product", "account",
        "deal_stage", "engage_date", "close_date", "close_value",
    };

    private final Connection connection;

    public MySQLLoader(String jdbcUrl, String user, String password) throws SQLException {
        connection = DriverManager.getConnection(jdbcUrl, user, password);
        connection.setAutoCommit(false);
    }

    public void ensureSchema() throws SQLException {
        try (Statement statement = connection.createStatement()) {
            for (String ddl : SCHEMA) {
                statement.execute(ddl);
            }
        }
        connection.commit();
    }

    /** Replaces the staging contents with the raw source rows, exactly as extracted. */
    public int stageRawPipeline(List<Map<String, String>> rawRows) throws SQLException {
        return inTransaction(() -> {
            try (Statement clear = connection.createStatement();
                 PreparedStatement insert = connection.prepareStatement(
                    "INSERT INTO sales_pipeline_staging (" + String.join(", ", PIPELINE_COLUMNS)
                            + ") VALUES (?, ?, ?, ?, ?, ?, ?, ?)")) {
                clear.executeUpdate("DELETE FROM sales_pipeline_staging");
                int pending = 0;
                for (Map<String, String> row : rawRows) {
                    for (int i = 0; i < PIPELINE_COLUMNS.length; i++) {
                        setString(insert, i + 1, row.get(PIPELINE_COLUMNS[i]));
                    }
                    insert.addBatch();
                    if (++pending == BATCH_SIZE) {
                        insert.executeBatch();
                        pending = 0;
                    }
                }
                insert.executeBatch();
            }
            return rawRows.size();
        });
    }

    /**
     * Replaces all warehouse data with the transformed records in a single
     * transaction. Returns the number of rows inserted per table.
     */
    public Map<String, Integer> replaceWarehouse(List<DataTransformer.Product> products,
                                                 List<DataTransformer.SalesAgent> agents,
                                                 List<DataTransformer.Account> accounts,
                                                 List<DataTransformer.Opportunity> opportunities) throws SQLException {
        Map<String, Integer> loaded = new LinkedHashMap<>();
        try {
            try (Statement clear = connection.createStatement()) {
                clear.executeUpdate("DELETE FROM sales_pipeline");
                clear.executeUpdate("UPDATE accounts SET subsidiary_of = NULL");
                clear.executeUpdate("DELETE FROM accounts");
                clear.executeUpdate("DELETE FROM products");
                clear.executeUpdate("DELETE FROM sales_teams");
            }
            loaded.put("products", insertProducts(products));
            loaded.put("sales_teams", insertSalesTeams(agents));
            loaded.put("accounts", insertAccounts(accounts));
            loaded.put("sales_pipeline", insertOpportunities(opportunities));
            connection.commit();
            return loaded;
        } catch (SQLException e) {
            connection.rollback();
            throw new SQLException("load rolled back, warehouse left unchanged: " + e.getMessage(), e);
        }
    }

    /** Runs a read-only query that returns a single value, for post-load verification. */
    public String queryValue(String sql) throws SQLException {
        try (Statement statement = connection.createStatement(); ResultSet rs = statement.executeQuery(sql)) {
            return rs.next() ? rs.getString(1) : null;
        }
    }

    @Override
    public void close() throws SQLException {
        connection.close();
    }

    // -------------------------------------------------------------------
    // Inserts (run inside replaceWarehouse's transaction)
    // -------------------------------------------------------------------

    private int insertProducts(List<DataTransformer.Product> products) throws SQLException {
        try (PreparedStatement insert = connection.prepareStatement(
                "INSERT INTO products (product, series, sales_price) VALUES (?, ?, ?)")) {
            for (DataTransformer.Product p : products) {
                insert.setString(1, p.product());
                setString(insert, 2, p.series());
                setDecimal(insert, 3, p.salesPrice());
                insert.addBatch();
            }
            insert.executeBatch();
        }
        return products.size();
    }

    private int insertSalesTeams(List<DataTransformer.SalesAgent> agents) throws SQLException {
        try (PreparedStatement insert = connection.prepareStatement(
                "INSERT INTO sales_teams (sales_agent, manager, regional_office) VALUES (?, ?, ?)")) {
            for (DataTransformer.SalesAgent a : agents) {
                insert.setString(1, a.salesAgent());
                setString(insert, 2, a.manager());
                setString(insert, 3, a.regionalOffice());
                insert.addBatch();
            }
            insert.executeBatch();
        }
        return agents.size();
    }

    private int insertAccounts(List<DataTransformer.Account> accounts) throws SQLException {
        try (PreparedStatement insert = connection.prepareStatement(
                "INSERT INTO accounts (account, sector, year_established, revenue, employees, office_location) "
                        + "VALUES (?, ?, ?, ?, ?, ?)");
             PreparedStatement linkParent = connection.prepareStatement(
                "UPDATE accounts SET subsidiary_of = ? WHERE account = ?")) {
            for (DataTransformer.Account a : accounts) {
                insert.setString(1, a.account());
                setString(insert, 2, a.sector());
                setInteger(insert, 3, a.yearEstablished());
                setDecimal(insert, 4, a.revenue());
                setInteger(insert, 5, a.employees());
                setString(insert, 6, a.officeLocation());
                insert.addBatch();
            }
            insert.executeBatch();

            for (DataTransformer.Account a : accounts) {
                if (a.subsidiaryOf() != null) {
                    linkParent.setString(1, a.subsidiaryOf());
                    linkParent.setString(2, a.account());
                    linkParent.addBatch();
                }
            }
            linkParent.executeBatch();
        }
        return accounts.size();
    }

    private int insertOpportunities(List<DataTransformer.Opportunity> opportunities) throws SQLException {
        try (PreparedStatement insert = connection.prepareStatement(
                "INSERT INTO sales_pipeline (opportunity_id, sales_agent, product, account, deal_stage, "
                        + "engage_date, close_date, close_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")) {
            int pending = 0;
            for (DataTransformer.Opportunity o : opportunities) {
                insert.setString(1, o.opportunityId());
                insert.setString(2, o.salesAgent());
                insert.setString(3, o.product());
                setString(insert, 4, o.account());
                insert.setString(5, o.dealStage());
                setDate(insert, 6, o.engageDate());
                setDate(insert, 7, o.closeDate());
                setDecimal(insert, 8, o.closeValue());
                insert.addBatch();
                if (++pending == BATCH_SIZE) {
                    insert.executeBatch();
                    pending = 0;
                }
            }
            insert.executeBatch();
        }
        return opportunities.size();
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    @FunctionalInterface
    private interface SqlWork {
        int run() throws SQLException;
    }

    private int inTransaction(SqlWork work) throws SQLException {
        try {
            int rows = work.run();
            connection.commit();
            return rows;
        } catch (SQLException e) {
            connection.rollback();
            throw e;
        }
    }

    private static void setString(PreparedStatement statement, int index, String value) throws SQLException {
        if (value == null) {
            statement.setNull(index, Types.VARCHAR);
        } else {
            statement.setString(index, value);
        }
    }

    private static void setInteger(PreparedStatement statement, int index, Integer value) throws SQLException {
        if (value == null) {
            statement.setNull(index, Types.INTEGER);
        } else {
            statement.setInt(index, value);
        }
    }

    private static void setDecimal(PreparedStatement statement, int index, BigDecimal value) throws SQLException {
        if (value == null) {
            statement.setNull(index, Types.DECIMAL);
        } else {
            statement.setBigDecimal(index, value);
        }
    }

    private static void setDate(PreparedStatement statement, int index, LocalDate value) throws SQLException {
        if (value == null) {
            statement.setNull(index, Types.DATE);
        } else {
            statement.setObject(index, value);
        }
    }
}

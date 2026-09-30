import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Extract step for CSV sources (products.csv, sales_pipeline.csv).
 *
 * Implements RFC 4180 parsing: quoted fields, escaped quotes ("") and commas
 * or line breaks inside quotes. The first row is the header; each following
 * row becomes a map of header name -> raw value. Short rows are padded with
 * empty values so the Transform step can reject them with a clear reason.
 */
public class CSVParser {

    public List<Map<String, String>> parse(String path) throws IOException {
        List<List<String>> rows = readRows(Files.readString(Path.of(path), StandardCharsets.UTF_8));
        List<Map<String, String>> records = new ArrayList<>();
        if (rows.isEmpty()) {
            return records;
        }

        List<String> header = rows.get(0);
        header.set(0, header.get(0).replace("\uFEFF", "")); // strip UTF-8 byte order mark

        for (List<String> row : rows.subList(1, rows.size())) {
            if (row.size() == 1 && row.get(0).isBlank()) {
                continue; // blank line
            }
            Map<String, String> record = new LinkedHashMap<>();
            for (int i = 0; i < header.size(); i++) {
                record.put(header.get(i).trim(), i < row.size() ? row.get(i) : "");
            }
            records.add(record);
        }
        return records;
    }

    private List<List<String>> readRows(String text) {
        List<List<String>> rows = new ArrayList<>();
        List<String> row = new ArrayList<>();
        StringBuilder field = new StringBuilder();
        boolean inQuotes = false;

        for (int i = 0; i < text.length(); i++) {
            char c = text.charAt(i);
            if (inQuotes) {
                if (c == '"' && i + 1 < text.length() && text.charAt(i + 1) == '"') {
                    field.append('"');
                    i++;
                } else if (c == '"') {
                    inQuotes = false;
                } else {
                    field.append(c);
                }
            } else if (c == '"') {
                inQuotes = true;
            } else if (c == ',') {
                row.add(field.toString());
                field.setLength(0);
            } else if (c == '\n') {
                row.add(field.toString());
                field.setLength(0);
                rows.add(row);
                row = new ArrayList<>();
            } else if (c != '\r') {
                field.append(c);
            }
        }

        if (field.length() > 0 || !row.isEmpty()) {
            row.add(field.toString());
            rows.add(row);
        }
        return rows;
    }
}

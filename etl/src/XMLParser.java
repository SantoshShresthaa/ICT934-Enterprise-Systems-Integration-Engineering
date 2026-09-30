import java.io.File;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import javax.xml.XMLConstants;
import javax.xml.parsers.DocumentBuilderFactory;
import org.w3c.dom.Document;
import org.w3c.dom.Element;
import org.w3c.dom.Node;

/**
 * Extract step for XML sources (accounts.xml, sales_teams.xml).
 *
 * Both files are "flat": every child element of the root is one record, and
 * every child element of a record is one field, e.g.
 *   <accounts><account><account>Acme</account><sector>...</sector></account></accounts>
 * Fields missing from a record (e.g. subsidiary_of) are simply absent from
 * the returned map.
 */
public class XMLParser {

    public List<Map<String, String>> parse(String path) throws Exception {
        DocumentBuilderFactory factory = DocumentBuilderFactory.newInstance();
        // Harden against XML External Entity (XXE) attacks.
        factory.setFeature(XMLConstants.FEATURE_SECURE_PROCESSING, true);
        factory.setFeature("http://apache.org/xml/features/disallow-doctype-decl", true);
        factory.setExpandEntityReferences(false);

        Document document = factory.newDocumentBuilder().parse(new File(path));
        Element root = document.getDocumentElement();

        List<Map<String, String>> records = new ArrayList<>();
        for (Node record = root.getFirstChild(); record != null; record = record.getNextSibling()) {
            if (record.getNodeType() != Node.ELEMENT_NODE) {
                continue;
            }
            Map<String, String> fields = new LinkedHashMap<>();
            for (Node field = record.getFirstChild(); field != null; field = field.getNextSibling()) {
                if (field.getNodeType() == Node.ELEMENT_NODE) {
                    fields.put(field.getNodeName(), field.getTextContent());
                }
            }
            records.add(fields);
        }
        return records;
    }
}

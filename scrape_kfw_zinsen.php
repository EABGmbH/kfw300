<?php
/**
 * KfW 300 und Interhyp Zinsen Scraper (PHP Version)
 * Scrapet aktuelle Zinssätze und speichert sie in zinsen-kfw300.json
 */

// Fehlerausgabe für Debugging (bei Problemen)
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

/**
 * HTML von einer URL laden
 */
function fetch_html($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "cURL Fehler: $error\n";
        return false;
    }
    
    return $html;
}

/**
 * KfW-Zinssätze von der offiziellen API abrufen
 */
function scrape_kfw_zinsen_api() {
    echo "Rufe KfW-API ab...\n";
    
    $api_url = 'https://www.kfw.de/zinsen/soll.rates';
    
    // API aufrufen
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $json_response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "cURL Fehler: $error\n";
        return null;
    }
    
    if (!$json_response) {
        echo "Keine Antwort von der API\n";
        return null;
    }
    
    // JSON dekodieren
    $data = json_decode($json_response, true);
    
    if ($data === null) {
        echo "JSON-Dekodierung fehlgeschlagen\n";
        echo "Response (erste 500 Zeichen): " . substr($json_response, 0, 500) . "\n";
        return null;
    }
    
    // Die API könnte verschiedene Strukturen zurückgeben:
    // Variante 1: {"kfw": [...]}
    // Variante 2: Direct array [...]
    // Variante 3: Verschachtelt z.B. {"data": {"kfw": [...]}}
    
    $kfw_programs = null;
    
    if (isset($data['kfw']) && is_array($data['kfw'])) {
        $kfw_programs = $data['kfw'];
        echo "✓ API-Struktur: kfw-Array gefunden\n";
    } elseif (is_array($data) && isset($data[0]) && isset($data[0]['programm'])) {
        // Direktes Array von Programmen
        $kfw_programs = $data;
        echo "✓ API-Struktur: direktes Programm-Array\n";
    } elseif (isset($data['data']) && isset($data['data']['kfw'])) {
        $kfw_programs = $data['data']['kfw'];
        echo "✓ API-Struktur: verschachteltes kfw-Array\n";
    } else {
        echo "⚠ API-Response hat unerwartetes Format\n";
        echo "Verfügbare Keys: " . implode(', ', array_keys($data)) . "\n";
        echo "Response (erste 300 Zeichen): " . substr($json_response, 0, 300) . "\n";
        return null;
    }
    
    // Filtere KfW 300 Daten
    $kfw300_rates = [];
    
    echo "Anzahl Programme in API: " . count($kfw_programs) . "\n";
    
    // Debug: Zeige erstes Entry
    if (count($kfw_programs) > 0) {
        echo "Beispiel-Entry (erstes Element):\n";
        echo "  Keys: " . implode(', ', array_keys($kfw_programs[0])) . "\n";
        if (isset($kfw_programs[0]['programm'])) {
            echo "  programm: " . $kfw_programs[0]['programm'] . " (Typ: " . gettype($kfw_programs[0]['programm']) . ")\n";
        }
    }
    
    // Zähle KfW 300 Einträge
    $kfw300_count = 0;
    foreach ($kfw_programs as $entry) {
        if (isset($entry['programm'])) {
            // Vergleiche als String UND als Integer
            if ($entry['programm'] == 300 || $entry['programm'] == '300') {
                $kfw300_count++;
                echo "KfW 300 Entry #$kfw300_count gefunden:\n";
                echo json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }
    echo "Gesamt KfW 300 Einträge gefunden: $kfw300_count\n";
    
    foreach ($kfw_programs as $entry) {
        if (isset($entry['programm']) && ($entry['programm'] == 300 || $entry['programm'] == '300')) {
            $laufzeit = isset($entry['laufzeit']) ? $entry['laufzeit'] : null;
            $sollzins = isset($entry['sollzins']) ? $entry['sollzins'] : null;
            
            if ($laufzeit === null || $sollzins === null) {
                echo "⚠ Entry übersprungen (fehlende Felder): laufzeit=$laufzeit, sollzins=$sollzins\n";
                continue;
            }
            
            // Mapping: laufzeit 1 = 4-10 Jahre, laufzeit 2 = 11-25 Jahre, laufzeit 3 = 26-35 Jahre
            if ($laufzeit == 1) {
                $kfw300_rates['4-10_jahre'] = ['sollzins' => $sollzins];
                echo "✓ API: 4-10 Jahre = $sollzins%\n";
            } elseif ($laufzeit == 2) {
                $kfw300_rates['11-25_jahre'] = ['sollzins' => $sollzins];
                echo "✓ API: 11-25 Jahre = $sollzins%\n";
            } elseif ($laufzeit == 3) {
                $kfw300_rates['26-35_jahre'] = ['sollzins' => $sollzins];
                echo "✓ API: 26-35 Jahre = $sollzins%\n";
            }
        }
    }
    
    // Validierung: Alle drei Laufzeiten gefunden?
    if (count($kfw300_rates) == 3) {
        echo "✓ KfW 300 Zinsen erfolgreich von API abgerufen\n";
        return $kfw300_rates;
    }
    
    echo "⚠ Nicht alle KfW 300 Laufzeiten in API gefunden (" . count($kfw300_rates) . "/3)\n";
    return null;
}

/**
 * KfW-Zinssätze scrapen (HTML Fallback)
 */
function scrape_kfw_zinsen() {
    echo "Scrape KfW-Zinsen (HTML Fallback)...\n";
    
    $url = 'https://www.kfw.de/inlandsfoerderung/Privatpersonen/Neubau/F%C3%B6rderprodukte/Wohneigentum-f%C3%BCr-Familien-(300)/';
    $html = fetch_html($url);
    
    if (!$html) {
        echo "Fehler beim Laden der KfW-Seite\n";
        return null;
    }
    
    $rates = [];
    
    // DOM-Parsing - Suche nach span-Elementen mit data-Attributen
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    // Suche nach span-Elementen mit KfW 300 Zinsdaten
    // Die Struktur ist: <span data-program-numbers="300" data-laufzeit="XX" data-interest-rate-type="sollzins">
    $spans = $xpath->query("//span[@data-program-numbers='300'][@data-interest-rate-type='sollzins']");
    
    echo "Gefundene KfW 300 Sollzins-Elemente: " . $spans->length . "\n";
    
    foreach ($spans as $span) {
        $laufzeit = $span->getAttribute('data-laufzeit');
        
        // Debug: Zeige alle Attribute
        echo "Debug: data-laufzeit='$laufzeit'";
        
        // Finde den inneren span mit class="link-labeling" der den Zinssatz enthält
        $label_spans = $xpath->query(".//span[@class='link-labeling']", $span);
        
        echo ", label_spans gefunden: " . $label_spans->length;
        
        if ($label_spans->length > 0) {
            $zins_text = trim($label_spans->item(0)->textContent);
            echo ", Text: '$zins_text'";
            
            // Parse "0,01 %" oder "1,09 %"
            if (preg_match('/(\d+[,\.]\d+)\s*%/', $zins_text, $match)) {
                $sollzins = floatval(str_replace(',', '.', $match[1]));
                echo ", Parsed: $sollzins%";
                
                // Mapping basierend auf data-laufzeit Attribut
                if ($laufzeit == '10') {
                    $rates['4-10_jahre'] = ['sollzins' => $sollzins];
                    echo " → ✓ 4-10 Jahre\n";
                }
                elseif ($laufzeit == '25') {
                    $rates['11-25_jahre'] = ['sollzins' => $sollzins];
                    echo " → ✓ 11-25 Jahre\n";
                }
                elseif ($laufzeit == '35') {
                    $rates['26-35_jahre'] = ['sollzins' => $sollzins];
                    echo " → ✓ 26-35 Jahre\n";
                }
                else {
                    echo " → ⚠ Laufzeit '$laufzeit' nicht erkannt\n";
                }
            } else {
                echo " → ⚠ Regex match fehlgeschlagen\n";
            }
        } else {
            echo " → ⚠ Keine link-labeling spans gefunden\n";
        }
    }
    
    if (count($rates) >= 3) {
        // Prüfe ob alle Werte gleich sind (dann stimmt was nicht)
        $values = array_column($rates, 'sollzins');
        $unique_values = array_unique($values);
        
        if (count($unique_values) === 1) {
            echo "⚠ Alle Zinssätze sind gleich ($values[0]%) - das ist unrealistisch!\n";
            echo "Verwerfe Ergebnis und nutze Fallback.\n";
            return null;
        }
        
        echo "✓ KfW 300 Zinsen erfolgreich gescraped: " . count($rates) . " Laufzeiten\n";
        return $rates;
    }
    
    echo "⚠ Nicht alle KfW-Zinsen gefunden (" . count($rates) . "/3)\n";
    return null;
}

/**
 * Bauzinsen von Interhyp scrapen
 */
function scrape_interhyp_zinsen() {
    echo "Scrape Interhyp-Zinsen...\n";
    
    $url = 'https://www.interhyp.de/ratgeber/was-muss-ich-wissen/zinsen-konditionen/interhyp-bauzinsen-charts.html';
    $html = fetch_html($url);
    
    if ($html) {
        $result = parse_bauzinsen($html, 'Interhyp');
        if ($result) return $result;
    }
    
    echo "⚠ Interhyp fehlgeschlagen\n";
    return null;
}

/**
 * Bauzinsen von Check24 scrapen
 */
function scrape_check24_zinsen() {
    echo "Scrape Check24-Zinsen...\n";
    
    $url = 'https://www.check24.de/baufinanzierung/zinsen/';
    $html = fetch_html($url);
    
    if ($html) {
        $result = parse_bauzinsen($html, 'Check24');
        if ($result) return $result;
    }
    
    echo "⚠ Check24 fehlgeschlagen\n";
    return null;
}

/**
 * Bauzinsen von Dr. Klein scrapen
 */
function scrape_drklein_zinsen() {
    echo "Scrape Dr. Klein-Zinsen...\n";
    
    $url = 'https://www.drklein.de/baufinanzierung-zinsen.html';
    $html = fetch_html($url);
    
    if ($html) {
        $result = parse_bauzinsen($html, 'Dr. Klein');
        if ($result) return $result;
    }
    
    echo "⚠ Dr. Klein fehlgeschlagen\n";
    return null;
}

/**
 * Intelligente Bauzinsen-Erkennung für alle Quellen
 */
function parse_bauzinsen($html, $source) {
    // Methode 1: Regex-Suche nach realistischen Bauzinsen (2% - 6%)
    preg_match_all('/(\d+[,.]?\d*)\s*%/u', $html, $all_matches);
    
    $found_rates = [];
    foreach ($all_matches[1] as $match) {
        $rate = floatval(str_replace(',', '.', $match));
        if ($rate >= 2.0 && $rate <= 6.0) {
            $found_rates[] = $rate;
        }
    }
    
    // Filtere unrealistische Werte (z.B. 100%, Jahreszahlen)
    $found_rates = array_filter($found_rates, function($rate) {
        return $rate < 10; // Nur Zinssätze unter 10%
    });
    
    if (count($found_rates) >= 3) {
        // Nehme den Median der gefundenen Zinssätze
        sort($found_rates);
        $median_index = floor(count($found_rates) / 2);
        $zins = $found_rates[$median_index];
        
        echo "Gefundene Zinssätze ($source): " . implode(', ', array_unique($found_rates)) . "%\n";
        echo "✓ Verwende Median: $zins%\n";
        
        return [
            'zinsbindung_10' => ['zins' => $zins]
        ];
    }
    
    // Methode 2: DOM-Parsing nach Tabellen
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    $rows = $xpath->query("//table//tr | //tbody//tr");
    
    foreach ($rows as $row) {
        $cells = $xpath->query(".//td | .//th", $row);
        if ($cells->length < 2) continue;
        
        $row_text = '';
        foreach ($cells as $cell) {
            $row_text .= ' ' . trim($cell->textContent);
        }
        
        // Suche nach "10 Jahre" Zeile
        if (preg_match('/10.*Jahre|Jahre.*10/iu', $row_text)) {
            foreach ($cells as $cell) {
                $text = trim($cell->textContent);
                if (preg_match('/(\d+[,.]?\d*)\s*%/', $text, $matches)) {
                    $zins = floatval(str_replace(',', '.', $matches[1]));
                    if ($zins >= 2 && $zins <= 6) {
                        echo "✓ $source-Zins 10 Jahre gefunden: $zins%\n";
                        return [
                            'zinsbindung_10' => ['zins' => $zins]
                        ];
                    }
                }
            }
        }
    }
    
    return null;
}

/**
 * Markt-Bauzinsen von mehreren Quellen scrapen
 */
function scrape_markt_zinsen() {
    echo "\n=== Markt-Bauzinsen (10 Jahre Zinsbindung) ===\n";
    
    // Versuche Interhyp
    $result = scrape_interhyp_zinsen();
    if ($result) return $result;
    
    // Fallback: Check24
    $result = scrape_check24_zinsen();
    if ($result) return $result;
    
    // Fallback: Dr. Klein
    $result = scrape_drklein_zinsen();
    if ($result) return $result;
    
    echo "⚠ Alle Markt-Zinsquellen fehlgeschlagen\n";
    return null;
}

/**
 * Alle Daten scrapen und JSON speichern
 */
function scrape_all() {
    echo "=== Start Scraping ===\n";
    echo date('Y-m-d H:i:s') . "\n\n";
    
    $json_file = __DIR__ . '/zinsen-kfw300.json';
    
    // Lade vorhandene Daten als Fallback
    $existing_data = null;
    if (file_exists($json_file)) {
        $existing_json = file_get_contents($json_file);
        $existing_data = json_decode($existing_json, true);
        echo "✓ Vorhandene JSON gefunden (Fallback)\n\n";
    }
    
    // KfW-Daten: Versuche zuerst die API
    echo "=== KfW 300 Zinsen ===\n";
    $kfw_rates = scrape_kfw_zinsen_api();
    $kfw_quelle = 'API';
    
    // Fallback 1: HTML-Scraping
    if (!$kfw_rates) {
        echo "→ API fehlgeschlagen, versuche HTML-Scraping...\n";
        $kfw_rates = scrape_kfw_zinsen();
        $kfw_quelle = 'HTML-Scraping';
    }
    
    // Fallback 2: Vorhandene JSON-Daten
    if (!$kfw_rates && $existing_data && isset($existing_data['kfw']['rates'])) {
        echo "→ HTML-Scraping fehlgeschlagen, verwende vorhandene JSON-Daten\n";
        $kfw_rates = $existing_data['kfw']['rates'];
        $kfw_quelle = 'Vorhandene JSON';
    }
    
    // Fallback 3: Hart-codierte aktuelle Werte
    if (!$kfw_rates) {
        echo "→ Verwende hart-codierte Fallback KfW 300 Daten (aktuelle Zinsen)\n";
        $kfw_rates = [
            '4-10_jahre' => ['sollzins' => 0.01],
            '11-25_jahre' => ['sollzins' => 1.09],
            '26-35_jahre' => ['sollzins' => 1.32]
        ];
        $kfw_quelle = 'Hart-codiert';
    }
    
    echo "KfW-Quelle: $kfw_quelle\n\n";
    
    // Markt-Bauzinsen
    $interhyp_rates = scrape_markt_zinsen();
    
    if (!$interhyp_rates) {
        if ($existing_data && isset($existing_data['interhyp']['rates'])) {
            echo "→ Verwende vorhandene Interhyp-Daten aus JSON\n";
            $interhyp_rates = $existing_data['interhyp']['rates'];
        } else {
            echo "→ Verwende hart-codierte Fallback Interhyp-Daten\n";
            $interhyp_rates = [
                'zinsbindung_10' => ['zins' => 3.96]
            ];
        }
    }
    
    // JSON-Struktur erstellen
    $data = [
        'kfw' => [
            'rates' => $kfw_rates,
            'quelle' => $kfw_quelle
        ],
        'interhyp' => [
            'rates' => $interhyp_rates
        ],
        'updated' => date('c') // ISO 8601 Format
    ];
    
    // JSON speichern
    $json_content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if (file_put_contents($json_file, $json_content)) {
        echo "\n✓ JSON erfolgreich gespeichert: $json_file\n";
        echo "Größe: " . filesize($json_file) . " Bytes\n";
        echo "\nInhalt:\n";
        echo "KfW-Quelle: $kfw_quelle\n";
        echo "KfW 4-10 Jahre: " . $kfw_rates['4-10_jahre']['sollzins'] . "%\n";
        echo "KfW 11-25 Jahre: " . $kfw_rates['11-25_jahre']['sollzins'] . "%\n";
        echo "KfW 26-35 Jahre: " . $kfw_rates['26-35_jahre']['sollzins'] . "%\n";
        echo "Interhyp 10 Jahre: " . $interhyp_rates['zinsbindung_10']['zins'] . "%\n";
        return true;
    } else {
        echo "\n✗ Fehler beim Speichern der JSON-Datei\n";
        return false;
    }
}

// Script ausführen
scrape_all();

echo "\n=== Scraping abgeschlossen ===\n";
?>

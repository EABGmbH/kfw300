<?php
/**
 * KfW 300 Zinsen Updater
 * Lädt die fertige JSON von GitHub (die durch Playwright erstellt wurde)
 * und speichert sie lokal auf IONOS
 * 
 * Einrichtung Cronjob: Täglich um 3:30 AM (nach GitHub Actions um 3:00 AM)
 */

// Konfiguration
$github_url = 'https://raw.githubusercontent.com/EABGmbH/kfw300/main/zinsen-kfw300.json';
$local_file = __DIR__ . '/zinsen-kfw300.json';

echo "=== KfW 300 Zinsen Update von GitHub ===\n";
echo "Zeit: " . date('Y-m-d H:i:s') . "\n\n";

// JSON von GitHub laden
echo "Lade JSON von GitHub...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $github_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
// Cache-Buster: Füge Timestamp hinzu um GitHub's Cache zu umgehen
curl_setopt($ch, CURLOPT_URL, $github_url . '?t=' . time());

$json_content = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Fehlerbehandlung
if ($http_code !== 200) {
    echo "❌ HTTP Fehler: Code $http_code\n";
    if ($error) {
        echo "cURL Fehler: $error\n";
    }
    exit(1);
}

if (empty($json_content)) {
    echo "❌ Leere Antwort von GitHub\n";
    exit(1);
}

// JSON validieren
$data = json_decode($json_content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ JSON ungültig: " . json_last_error_msg() . "\n";
    exit(1);
}

// Prüfe ob notwendige Felder vorhanden sind
if (!isset($data['kfw']) || !isset($data['kfw']['rates'])) {
    echo "⚠️ JSON-Struktur unvollständig (kein kfw.rates gefunden)\n";
    echo "Inhalt:\n";
    print_r($data);
    exit(1);
}

if (!isset($data['interhyp']) || !isset($data['interhyp']['rates'])) {
    echo "⚠️ Warnung: Keine Interhyp-Daten gefunden\n";
}

// Alte Daten laden für Vergleich (falls vorhanden)
$old_data = null;
if (file_exists($local_file)) {
    $old_content = file_get_contents($local_file);
    $old_data = json_decode($old_content, true);
}

// Speichere neue Daten
$bytes_written = file_put_contents($local_file, $json_content);

if ($bytes_written === false) {
    echo "❌ Fehler beim Speichern der Datei\n";
    exit(1);
}

echo "✅ JSON erfolgreich aktualisiert ($bytes_written Bytes)\n";
echo "Stand: " . ($data['updated'] ?? 'unbekannt') . "\n\n";

// Zeige Änderungen
if ($old_data !== null) {
    echo "Änderungen:\n";
    
    // Vergleiche KfW-Zinsen
    $kfw_4_10_alt = $old_data['kfw']['rates']['4-10_jahre']['sollzins'] ?? null;
    $kfw_4_10_neu = $data['kfw']['rates']['4-10_jahre']['sollzins'] ?? null;
    
    if ($kfw_4_10_alt !== $kfw_4_10_neu) {
        echo "  KfW 4-10 Jahre: $kfw_4_10_alt% → $kfw_4_10_neu%\n";
    }
    
    // Vergleiche Interhyp-Zins
    $interhyp_alt = $old_data['interhyp']['rates']['zinsbindung_10']['zins'] ?? null;
    $interhyp_neu = $data['interhyp']['rates']['zinsbindung_10']['zins'] ?? null;
    
    if ($interhyp_alt !== $interhyp_neu) {
        echo "  Interhyp 10J: $interhyp_alt% → $interhyp_neu%\n";
    }
    
    if ($kfw_4_10_alt === $kfw_4_10_neu && $interhyp_alt === $interhyp_neu) {
        echo "  Keine Änderungen\n";
    }
} else {
    echo "Erste Synchronisation - keine Vergleichsdaten vorhanden\n";
}

echo "\n✅ Update abgeschlossen\n";
?>

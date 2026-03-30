<?php
/**
 * cron-zinsen.php — Täglich per IONOS Cronjob aufrufen
 *
 * IONOS Control Panel → Hosting → Cronjobs → Neue Aufgabe:
 *   URL:      https://www.energy-advice-bavaria.de/api/cron-zinsen.php?secret=DEIN_SECRET_HIER_ERSETZEN
 *   Zeitplan: Täglich, 07:00 Uhr
 *
 * Beim ersten Aufruf wird data/kfw/ automatisch angelegt.
 */

declare(strict_types=1);

// ── Konfiguration ─────────────────────────────────────────────────────────────

const CRON_SECRET       = 'DEIN_SECRET_HIER_ERSETZEN'; // ← In IONOS-URL eintragen
const KFW_SOURCE_URL    = 'https://www.kfw-formularsammlung.de/KonditionenanzeigerINet/KonditionenAnzeiger';
const INTERHYP_URL      = 'https://www.interhyp.de/zinsen/';
const CHECK24_URL       = 'https://www.check24.de/baufinanzierung/zinsen/';
const OUTPUT_FILE       = __DIR__ . '/../data/kfw/300.json';
const NOTIFICATION_MAIL = 'anfrage@energy-advice-bavaria.de';
const CURL_TIMEOUT      = 30;
const CURL_UA           = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';

const KFW_TARGETS = [
    ['laufzeit' => 10, 'tilgfrei' => 2, 'zinsbindung' => 10],
    ['laufzeit' => 25, 'tilgfrei' => 3, 'zinsbindung' => 10],
    ['laufzeit' => 35, 'tilgfrei' => 5, 'zinsbindung' => 10],
];

// ── Secret-Prüfung ────────────────────────────────────────────────────────────

$secret = (string)($_GET['secret'] ?? '');
if (!hash_equals(CRON_SECRET, $secret)) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

// ── Hilfsfunktionen ───────────────────────────────────────────────────────────

function fetch_url(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_USERAGENT      => CURL_UA,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de-DE,de;q=0.9,en;q=0.5',
        ],
    ]);
    $html     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $html === false || $html === '') {
        return null;
    }
    return (string)$html;
}

function decode_html(string $s): string
{
    return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function normalize_ws(string $s): string
{
    return trim((string)preg_replace('/[\x{00A0}\s]+/u', ' ', $s));
}

function cell_text(string $html): string
{
    $html = (string)preg_replace('/<!--[\s\S]*?-->/u', ' ', $html);
    return normalize_ws(decode_html(strip_tags($html)));
}

function german_float(string $raw): ?float
{
    $n = str_replace(['.', ','], ['', '.'], trim($raw));
    if (!is_numeric($n)) return null;
    $v = (float)$n;
    return is_finite($v) ? $v : null;
}

// ── Interhyp-Parsing (identisch mit TypeScript-Scraper) ──────────────────────

function parse_interhyp(string $html): ?float
{
    // Heading "Zinstabelle: Effektiver Jahreszins" suchen
    if (!preg_match(
        '/<h[1-6][^>]*>[\s\S]*?Zinstabelle:\s*Effektiver\s*Jahreszins[\s\S]*?<\/h[1-6]>/i',
        $html, $hm, PREG_OFFSET_CAPTURE
    )) {
        return null;
    }

    $afterHeading = substr($html, $hm[0][1] + strlen($hm[0][0]));

    // Erste <table> nach der Überschrift
    if (!preg_match('/<table\b[^>]*>[\s\S]*?<\/table>/i', $afterHeading, $tm)) {
        return null;
    }

    // Alle <tr> extrahieren, Header überspringen
    if (!preg_match_all('/<tr\b[^>]*>[\s\S]*?<\/tr>/i', $tm[0], $rows) || count($rows[0]) < 2) {
        return null;
    }

    foreach (array_slice($rows[0], 1) as $rowHtml) {
        // Zellen extrahieren
        if (!preg_match_all('/<(td|th)\b[^>]*>([\s\S]*?)<\/\1>/i', $rowHtml, $cm)) {
            continue;
        }
        $cells = array_map('cell_text', $cm[2]);
        if (count($cells) < 2) continue;

        // Erste Zelle muss "10" sein (10-jährige Zinsbindung)
        if (normalize_ws($cells[0]) !== '10') continue;

        // Alle %-Werte aus den restlichen Zellen sammeln
        $rates = [];
        foreach (array_slice($cells, 1) as $cell) {
            if (preg_match('/(\d+,\d+)\s*%/', $cell, $m)) {
                $v = german_float($m[1]);
                if ($v !== null && $v > 0 && $v < 15) {
                    $rates[] = $v;
                }
            }
        }
        if (empty($rates)) continue;

        // Letzten Wert nehmen — identisch mit TypeScript: parsedRates[parsedRates.length - 1]
        return end($rates);
    }

    return null;
}

// ── Check24-Fallback-Parsing ──────────────────────────────────────────────────

function parse_check24(string $html): ?float
{
    $html = (string)preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', ' ', $html);
    $html = (string)preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', ' ', $html);

    if (preg_match_all('/<tr\b[^>]*>[\s\S]*?<\/tr>/i', $html, $rows)) {
        foreach ($rows[0] as $row) {
            $text = cell_text($row);
            if (!preg_match('/10\s*Jahre/i', $text)) continue;
            preg_match_all('/(\d{1,2},\d{1,2})\s*%/', $text, $pct);
            foreach ($pct[1] as $raw) {
                $v = german_float($raw);
                if ($v !== null && $v >= 2 && $v <= 10) return $v;
            }
        }
    }

    // Breitere Suche
    $full = normalize_ws(decode_html(strip_tags($html)));
    preg_match_all('/10\s*Jahre.{0,80}?(\d{1,2},\d{1,2})\s*%/i', $full, $broad);
    foreach ($broad[1] as $raw) {
        $v = german_float($raw);
        if ($v !== null && $v >= 2 && $v <= 10) return $v;
    }

    return null;
}

// ── KfW-Parsing (identisch mit TypeScript-Scraper) ───────────────────────────

function extract_stand(string $html): ?string
{
    $decoded = decode_html($html);
    return preg_match('/Stand\s*:\s*(\d{2}\.\d{2}\.\d{4})/i', $decoded, $m) ? $m[1] : null;
}

function extract_kfw_rates(string $html): array
{
    $html = (string)preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', ' ', $html);
    $html = (string)preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', ' ', $html);

    $targetKeys = array_map(
        fn($t) => "{$t['laufzeit']}/{$t['tilgfrei']}/{$t['zinsbindung']}",
        KFW_TARGETS
    );

    $found = [];
    preg_match_all('/<tr\b[^>]*>[\s\S]*?<\/tr>/i', $html, $rowMatches);
    foreach ($rowMatches[0] as $row) {
        $row  = (string)preg_replace('/<br\s*\/?\s*>/i', ' ', $row);
        $text = normalize_ws(decode_html(strip_tags($row)));
        if ($text === '') continue;

        if (!preg_match('/Wohneigentum\s+f(?:ü|u)r\s+Familien(?!\s*-)/iu', $text)) continue;
        if (!preg_match('/\b300\b/', $text)) continue;

        $pat = '/(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(\d{1,2})\s+300\s+'
             . '([0-9]+,[0-9]+)\s*\(\s*([0-9]+,[0-9]+)\s*\)\s+'
             . '([0-9]+(?:,[0-9]+)?)\s+([0-9]+(?:,[0-9]+)?)\s+'
             . '(\d{2}\.\d{2}\.\d{4})/i';
        if (!preg_match($pat, $text, $m)) continue;

        $lz  = (int)$m[1];
        $tf  = (int)$m[2];
        $zb  = (int)$m[3];
        $key = "$lz/$tf/$zb";
        if (!in_array($key, $targetKeys, true) || isset($found[$key])) continue;

        $soll   = german_float($m[4]);
        $eff    = german_float($m[5]);
        $ausz   = german_float($m[6]);
        $bereit = german_float($m[7]);
        if ($soll === null || $eff === null) continue;

        $found[$key] = [
            'laufzeitJahre'                      => $lz,
            'tilgfreiJahre'                      => $tf,
            'zinsbindungJahre'                   => $zb,
            'sollzinsProzent'                    => $soll,
            'effektivzinsProzent'                => $eff,
            'auszahlungProzent'                  => $ausz,
            'bereitstellungsprovProzentProMonat' => $bereit,
            'gueltigAb'                          => $m[8],
        ];
    }

    return $found;
}

function validate_kfw_rates(array $found): bool
{
    if (count($found) !== 3) return false;
    foreach (KFW_TARGETS as $t) {
        $key = "{$t['laufzeit']}/{$t['tilgfrei']}/{$t['zinsbindung']}";
        if (!isset($found[$key])) return false;
        foreach (['sollzinsProzent', 'effektivzinsProzent'] as $f) {
            $v = $found[$key][$f];
            if (!is_float($v) || $v < 0 || $v > 15) return false;
        }
    }
    return true;
}

// ── Bestehende Datei laden ────────────────────────────────────────────────────

function load_existing(): ?array
{
    if (!file_exists(OUTPUT_FILE)) return null;
    $raw = file_get_contents(OUTPUT_FILE);
    if ($raw === false) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

// ── Hauptlogik ────────────────────────────────────────────────────────────────

$errors   = [];
$log      = [];
$existing = load_existing();

// 1. KfW scrapen
$log[] = '[' . date('d.m.Y H:i:s') . '] Starte KfW-Scraping...';
$kfwHtml    = fetch_url(KFW_SOURCE_URL);
$stand      = null;
$foundRates = [];

if ($kfwHtml === null) {
    $errors[] = 'KfW nicht erreichbar (HTTP-Fehler)';
    $log[]    = 'FEHLER: KfW nicht erreichbar';
} else {
    $stand      = extract_stand($kfwHtml);
    $foundRates = extract_kfw_rates($kfwHtml);
    if (!validate_kfw_rates($foundRates)) {
        $errors[] = 'KfW-Parsing fehlgeschlagen (' . count($foundRates) . '/3 Varianten gefunden)';
        $log[]    = 'FEHLER: KfW-Parsing fehlgeschlagen, gefunden: ' . count($foundRates) . '/3';
    } else {
        $log[] = 'KfW OK: 3 Varianten, Stand ' . ($stand ?? 'unbekannt');
    }
}

// 2. Interhyp scrapen
$log[]        = 'Lade Interhyp-Zinsen...';
$marketRate   = null;
$marketSource = 'Interhyp';
$marketUrl    = INTERHYP_URL;

$interhypHtml = fetch_url(INTERHYP_URL);
if ($interhypHtml !== null) {
    $marketRate = parse_interhyp($interhypHtml);
}

if ($marketRate !== null) {
    $log[] = "Interhyp OK: {$marketRate}% (10 Jahre)";
} else {
    $log[] = 'Interhyp fehlgeschlagen – versuche Check24...';
    $check24Html = fetch_url(CHECK24_URL);
    if ($check24Html !== null) {
        $marketRate = parse_check24($check24Html);
        if ($marketRate !== null) {
            $marketSource = 'CHECK24';
            $marketUrl    = CHECK24_URL;
            $log[]        = "Check24 OK: {$marketRate}% (10 Jahre)";
        }
    }
}

// 3. Wenn Marktrate nicht ermittelbar → aus bestehender Datei übernehmen (KEIN Hardcode-Fallback)
if ($marketRate === null) {
    $oldRate = isset($existing['interhyp']['rates']['zinsbindung_10']['zins'])
        ? (float)$existing['interhyp']['rates']['zinsbindung_10']['zins']
        : null;

    if ($oldRate !== null && is_finite($oldRate) && $oldRate > 0) {
        $marketRate   = $oldRate;
        $oldName      = $existing['interhyp']['source_name'] ?? 'Interhyp';
        $marketSource = $oldName . ' (letzter bekannter Wert)';
        $marketUrl    = $existing['interhyp']['source_url'] ?? INTERHYP_URL;
        $log[]        = "Marktrate aus bestehender Datei: {$marketRate}%";
    } else {
        $errors[] = 'Marktrate nicht ermittelbar und keine bestehende Datei vorhanden';
        $log[]    = 'FEHLER: Marktrate nicht ermittelbar';
    }
}

// 4. Bei KfW-Fehler abbrechen — Datei NICHT überschreiben
if (!empty($errors)) {
    $subject = '[KfW300-IONOS] Cronjob Fehler ' . date('d.m.Y');
    $body    = implode("\n", $log);
    mail(NOTIFICATION_MAIL, $subject, $body, 'From: ' . NOTIFICATION_MAIL);

    http_response_code(500);
    echo implode("\n", $log) . "\n";
    echo "\nFEHLER – Datei wurde NICHT überschrieben:\n";
    echo implode("\n", array_map(fn($e) => "• $e", $errors)) . "\n";
    exit;
}

// 5. Ausgabe zusammenbauen
$rates = [];
foreach (KFW_TARGETS as $t) {
    $key = "{$t['laufzeit']}/{$t['tilgfrei']}/{$t['zinsbindung']}";
    $rates[] = $foundRates[$key];
}

$output = [
    'source'    => KFW_SOURCE_URL,
    'stand'     => $stand,
    'program'   => 300,
    'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'rates'     => $rates,
    'interhyp'  => [
        'source_name' => $marketSource,
        'source_url'  => $marketUrl,
        'rates'       => [
            'zinsbindung_10' => [
                'zinsbindung' => '10 Jahre',
                'beleihung'   => '< 90%',
                'zins'        => round((float)$marketRate, 2),
            ],
        ],
    ],
];

// 6. Verzeichnis anlegen (beim ersten Aufruf) und Datei schreiben
$dir = dirname(OUTPUT_FILE);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if (file_put_contents(OUTPUT_FILE, $json, LOCK_EX) === false) {
    http_response_code(500);
    echo implode("\n", $log) . "\nFEHLER: Datei konnte nicht geschrieben werden\n";
    exit;
}

$log[] = 'OK: data/kfw/300.json aktualisiert';
$log[] = 'Marktrate: ' . $marketRate . '% (' . $marketSource . ')';

echo implode("\n", $log) . "\n";

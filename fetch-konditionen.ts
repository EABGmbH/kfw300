import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const SOURCE_URL =
  'https://www.kfw-formularsammlung.de/KonditionenanzeigerINet/KonditionenAnzeiger';
const OUTPUT_FILE = path.resolve(process.cwd(), 'data/kfw/300.json');

type Variant = {
  laufzeitJahre: number;
  tilgfreiJahre: number;
  zinsbindungJahre: number;
};

type Rate = Variant & {
  sollzinsProzent: number;
  effektivzinsProzent: number;
  auszahlungProzent: number;
  bereitstellungsprovProzentProMonat: number;
  gueltigAb: string;
};

type Output = {
  source: string;
  stand: string | null;
  program: number;
  updatedAt: string;
  rates: Rate[];
};

const TARGETS: Variant[] = [
  { laufzeitJahre: 10, tilgfreiJahre: 2, zinsbindungJahre: 10 },
  { laufzeitJahre: 25, tilgfreiJahre: 3, zinsbindungJahre: 10 },
  { laufzeitJahre: 35, tilgfreiJahre: 5, zinsbindungJahre: 10 }
];

const TARGET_KEYS = new Set(TARGETS.map((v) => `${v.laufzeitJahre}/${v.tilgfreiJahre}/${v.zinsbindungJahre}`));

function decodeHtmlEntities(value: string): string {
  const named = value
    .replace(/&nbsp;|&#160;|&#xA0;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;|&apos;/gi, "'")
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>');

  return named.replace(/&#(\d+);/g, (_, code) => {
    const parsed = Number.parseInt(code, 10);
    return Number.isFinite(parsed) ? String.fromCharCode(parsed) : _;
  });
}

function normalizeWhitespace(value: string): string {
  return value.replace(/[\u00A0\s]+/g, ' ').trim();
}

function germanToNumber(raw: string): number {
  const normalized = raw.replace(/\./g, '').replace(',', '.').trim();
  const result = Number.parseFloat(normalized);
  if (!Number.isFinite(result)) {
    throw new Error(`Ungültige Zahl: ${raw}`);
  }
  return result;
}

function extractStandDate(html: string): string | null {
  const decoded = decodeHtmlEntities(html);
  const match = decoded.match(/Stand\s*:\s*(\d{2}\.\d{2}\.\d{4})/i);
  return match ? match[1] : null;
}

function extractRowTexts(html: string): string[] {
  const withoutScripts = html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, ' ');

  const rows = withoutScripts.match(/<tr\b[^>]*>[\s\S]*?<\/tr>/gi) ?? [];
  return rows
    .map((rowHtml) => rowHtml.replace(/<br\s*\/?\s*>/gi, ' '))
    .map((rowHtml) => decodeHtmlEntities(rowHtml.replace(/<[^>]+>/g, ' ')))
    .map(normalizeWhitespace)
    .filter((rowText) => rowText.length > 0);
}

function parseRateLine(line: string): Rate | null {
  if (!/Wohneigentum\s+f(?:ü|u)r\s+Familien(?!\s*-)/i.test(line) || !/\b300\b/.test(line)) {
    return null;
  }

  const pattern =
    /(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(\d{1,2})\s+300\s+([0-9]+,[0-9]+)\s*\(\s*([0-9]+,[0-9]+)\s*\)\s+([0-9]+(?:,[0-9]+)?)\s+([0-9]+(?:,[0-9]+)?)\s+(\d{2}\.\d{2}\.\d{4})/i;

  const match = line.match(pattern);
  if (!match) {
    return null;
  }

  const laufzeitJahre = Number.parseInt(match[1], 10);
  const tilgfreiJahre = Number.parseInt(match[2], 10);
  const zinsbindungJahre = Number.parseInt(match[3], 10);
  const key = `${laufzeitJahre}/${tilgfreiJahre}/${zinsbindungJahre}`;

  if (!TARGET_KEYS.has(key)) {
    return null;
  }

  return {
    laufzeitJahre,
    tilgfreiJahre,
    zinsbindungJahre,
    sollzinsProzent: germanToNumber(match[4]),
    effektivzinsProzent: germanToNumber(match[5]),
    auszahlungProzent: germanToNumber(match[6]),
    bereitstellungsprovProzentProMonat: germanToNumber(match[7]),
    gueltigAb: match[8]
  };
}

function validateRates(rates: Rate[]): void {
  if (rates.length !== 3) {
    throw new Error(`Validierung fehlgeschlagen: exakt 3 Treffer erwartet, gefunden: ${rates.length}`);
  }

  const seen = new Set<string>();
  for (const rate of rates) {
    const key = `${rate.laufzeitJahre}/${rate.tilgfreiJahre}/${rate.zinsbindungJahre}`;
    if (!TARGET_KEYS.has(key)) {
      throw new Error(`Validierung fehlgeschlagen: unerwartete Variante ${key}`);
    }
    if (seen.has(key)) {
      throw new Error(`Validierung fehlgeschlagen: doppelte Variante ${key}`);
    }
    seen.add(key);

    const toCheck = [rate.sollzinsProzent, rate.effektivzinsProzent];
    for (const value of toCheck) {
      if (!Number.isFinite(value) || value === 0 || value < 0 || value > 15) {
        throw new Error(`Validierung fehlgeschlagen: ungültiger Zinssatz ${value} bei ${key}`);
      }
    }
  }

  for (const target of TARGETS) {
    const key = `${target.laufzeitJahre}/${target.tilgfreiJahre}/${target.zinsbindungJahre}`;
    if (!seen.has(key)) {
      throw new Error(`Validierung fehlgeschlagen: Variante fehlt ${key}`);
    }
  }
}

async function loadLastKnownGood(): Promise<Output | null> {
  try {
    const raw = await readFile(OUTPUT_FILE, 'utf8');
    return JSON.parse(raw) as Output;
  } catch {
    return null;
  }
}

async function main(): Promise<void> {
  const lkg = await loadLastKnownGood();

  const response = await fetch(SOURCE_URL, {
    method: 'GET',
    signal: AbortSignal.timeout(30_000),
    headers: {
      'user-agent':
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36'
    }
  });

  if (!response.ok) {
    throw new Error(`HTTP Fehler beim Laden der Quelle: ${response.status} ${response.statusText}`);
  }

  const html = await response.text();
  const stand = extractStandDate(html);
  const lines = extractRowTexts(html);

  const found = new Map<string, Rate>();
  for (const line of lines) {
    const parsed = parseRateLine(line);
    if (!parsed) continue;
    const key = `${parsed.laufzeitJahre}/${parsed.tilgfreiJahre}/${parsed.zinsbindungJahre}`;
    found.set(key, parsed);
  }

  const rates = TARGETS.map((target) => {
    const key = `${target.laufzeitJahre}/${target.tilgfreiJahre}/${target.zinsbindungJahre}`;
    return found.get(key);
  }).filter((rate): rate is Rate => Boolean(rate));

  validateRates(rates);

  const output: Output = {
    source: SOURCE_URL,
    stand,
    program: 300,
    updatedAt: new Date().toISOString(),
    rates
  };

  await mkdir(path.dirname(OUTPUT_FILE), { recursive: true });
  await writeFile(OUTPUT_FILE, `${JSON.stringify(output, null, 2)}\n`, 'utf8');

  console.log(`OK: ${OUTPUT_FILE} aktualisiert (${rates.length} Zeilen)`);
  for (const rate of rates) {
    console.log(
      `${rate.laufzeitJahre}/${rate.tilgfreiJahre}/${rate.zinsbindungJahre} -> Soll ${rate.sollzinsProzent}% (eff. ${rate.effektivzinsProzent}%), gültig ab ${rate.gueltigAb}`
    );
  }
  if (stand) {
    console.log(`Stand: ${stand}`);
  }
  if (lkg) {
    console.log('Last-known-good war vorhanden und wurde bei erfolgreicher Validierung ersetzt.');
  }
}

main().catch((error: unknown) => {
  const message = error instanceof Error ? error.message : String(error);
  console.error(`FEHLER: ${message}`);
  console.error('Last-known-good bleibt unverändert, da keine Datei bei Fehler überschrieben wird.');
  process.exitCode = 1;
});

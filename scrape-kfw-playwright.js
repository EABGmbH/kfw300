#!/usr/bin/env node
/**
 * KfW 300 Zinssätze Scraper mit Playwright
 * Scraped die gerenderten Zinswerte von der KfW-Website
 */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

// Konfiguration
const KFW_URL = 'https://www.kfw.de/inlandsfoerderung/Privatpersonen/Neubau/F%C3%B6rderprodukte/Wohneigentum-f%C3%BCr-Familien-(300)/';
const CHECK24_URL = 'https://www.check24.de/baufinanzierung/zinsen/';
const OUTPUT_FILE = 'zinsen-kfw300.json';
const PREVIOUS_FILE = 'zinsen-kfw300.json';

/**
 * Scraped KfW 300 Zinssätze mit Playwright
 */
async function scrapeKfwZinsen() {
    console.log('🚀 Starte KfW 300 Zins-Scraping mit Playwright...');
    
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    
    try {
        const page = await browser.newPage();
        await page.setViewportSize({ width: 1920, height: 1080 });
        
        console.log(`📡 Lade KfW-Seite: ${KFW_URL}`);
        await page.goto(KFW_URL, { waitUntil: 'networkidle', timeout: 30000 });
        
        // Warte auf "Konditionen" Section
        console.log('⏳ Warte auf Konditionen-Section...');
        await page.waitForSelector('h2:has-text("Konditionen")', { timeout: 10000 });
        
        // Öffne "Konditionen" Akkordeon falls geschlossen
        const konditionenButton = page.locator('button:has-text("Konditionen")').first();
        const isExpanded = await konditionenButton.getAttribute('aria-expanded');
        
        if (isExpanded === 'false') {
            console.log('📂 Öffne Konditionen-Akkordeon...');
            await konditionenButton.click();
            await page.waitForTimeout(1000); // Warte auf Animation
        }
        
        // Warte auf Tabelle mit Zinssätzen (nicht mehr Platzhalter)
        console.log('⏳ Warte auf gerenderte Zinssätze...');
        await page.waitForSelector('table tbody tr td:has-text("%")', { timeout: 15000 });
        
        // Extrahiere Zinssätze aus der Tabelle
        console.log('📊 Extrahiere Zinssätze...');
        const rates = await page.evaluate(() => {
            const result = {};
            const rows = document.querySelectorAll('table tbody tr');
            
            rows.forEach((row) => {
                const cells = row.querySelectorAll('td');
                if (cells.length < 4) return;
                
                const laufzeit = cells[0].textContent.trim();
                const sollzinsCell = cells[3];
                
                // Extrahiere Sollzins (erster Prozentsatz in der Zelle)
                const sollzinsSpan = sollzinsCell.querySelector('span[data-interest-rate-type="sollzins"] .link-labeling');
                if (!sollzinsSpan) return;
                
                const sollzinsText = sollzinsSpan.textContent.trim();
                
                // Skip Platzhalter
                if (sollzinsText.includes('-,--')) return;
                
                // Parse "0,01 %" → 0.01
                const match = sollzinsText.match(/(\d+[,\.]\d+)\s*%/);
                if (!match) return;
                
                const sollzins = parseFloat(match[1].replace(',', '.'));
                
                // Mapping
                if (laufzeit.includes('4 bis 10')) {
                    result['4-10_jahre'] = sollzins;
                } else if (laufzeit.includes('11 bis 25')) {
                    result['11-25_jahre'] = sollzins;
                } else if (laufzeit.includes('26 bis 35')) {
                    result['26-35_jahre'] = sollzins;
                }
            });
            
            return result;
        });
        
        // Validierung
        const requiredKeys = ['4-10_jahre', '11-25_jahre', '26-35_jahre'];
        const hasAllRates = requiredKeys.every(key => rates[key] !== undefined);
        
        if (!hasAllRates) {
            throw new Error(`❌ Nicht alle Zinssätze gefunden. Extrahiert: ${Object.keys(rates).join(', ')}`);
        }
        
        // Plausibilitäts-Check: Alle Zinsen im Bereich 0-10%
        const values = Object.values(rates);
        const allPlausible = values.every(v => v >= 0 && v <= 10);
        
        if (!allPlausible) {
            throw new Error(`❌ Unplausible Zinssätze: ${JSON.stringify(rates)}`);
        }
        
        // Check ob alle gleich sind (unrealistisch)
        const uniqueValues = [...new Set(values)];
        if (uniqueValues.length === 1) {
            throw new Error(`⚠️ Alle Zinssätze sind gleich (${uniqueValues[0]}%) - unrealistisch!`);
        }
        
        console.log('✅ KfW-Zinsen erfolgreich extrahiert:');
        console.log(`   4-10 Jahre:   ${rates['4-10_jahre']}%`);
        console.log(`   11-25 Jahre:  ${rates['11-25_jahre']}%`);
        console.log(`   26-35 Jahre:  ${rates['26-35_jahre']}%`);
        
        return rates;
        
    } finally {
        await browser.close();
    }
}

/**
 * Scraped Markt-Bauzinsen von Check24
 */
async function scrapeMarktZinsen() {
    console.log('📊 Scrape Check24-Zinsen...');
    
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    
    try {
        const page = await browser.newPage();
        await page.goto(CHECK24_URL, { waitUntil: 'networkidle', timeout: 30000 });
        
        // Warte auf Tabelle
        await page.waitForSelector('table', { timeout: 10000 });
        
        // Extrahiere 10-Jahre Zins
        const zins = await page.evaluate(() => {
            const rows = document.querySelectorAll('table tr');
            
            for (const row of rows) {
                const cells = row.querySelectorAll('td, th');
                const rowText = Array.from(cells).map(c => c.textContent.trim()).join(' ');
                
                // Suche nach "10 Jahre" Zeile
                if (/10.*Jahre|Jahre.*10/i.test(rowText)) {
                    for (const cell of cells) {
                        const match = cell.textContent.match(/(\d+[,\.]\d+)\s*%/);
                        if (match) {
                            const value = parseFloat(match[1].replace(',', '.'));
                            if (value >= 2 && value <= 6) {
                                return value;
                            }
                        }
                    }
                }
            }
            return null;
        });
        
        if (zins) {
            console.log(`✅ Check24-Zins (10 Jahre): ${zins}%`);
            return zins;
        } else {
            console.log('⚠️ Check24-Zins nicht gefunden');
            return null;
        }
        
    } catch (error) {
        console.error('❌ Check24 Scraping fehlgeschlagen:', error.message);
        return null;
    } finally {
        await browser.close();
    }
}

/**
 * Hauptfunktion
 */
async function main() {
    console.log('=== KfW 300 Zinssätze Scraper ===');
    console.log(`Zeitstempel: ${new Date().toISOString()}\n`);
    
    // Lade vorhandene Daten als Fallback
    let previousData = null;
    if (fs.existsSync(PREVIOUS_FILE)) {
        try {
            previousData = JSON.parse(fs.readFileSync(PREVIOUS_FILE, 'utf8'));
            console.log('📂 Vorhandene JSON gefunden (Fallback)\n');
        } catch (e) {
            console.error('⚠️ Fehler beim Laden der vorhandenen JSON:', e.message);
        }
    }
    
    try {
        // Scrape KfW-Zinsen
        const kfwRates = await scrapeKfwZinsen();
        
        // Scrape Markt-Zinsen (Check24)
        const marktZins = await scrapeMarktZinsen();
        
        // Fallback für Markt-Zins
        let interhypRate = marktZins;
        if (!interhypRate && previousData && previousData.interhyp && previousData.interhyp.rates) {
            interhypRate = previousData.interhyp.rates.zinsbindung_10;
            console.log(`→ Verwende vorherigen Markt-Zins: ${interhypRate}%`);
        }
        if (!interhypRate) {
            interhypRate = 3.96; // Hart-codierter Fallback
            console.log(`→ Verwende hart-codierten Fallback: ${interhypRate}%`);
        }
        
        // JSON-Struktur erstellen
        const data = {
            kfw: {
                rates: {
                    '4-10_jahre': kfwRates['4-10_jahre'].toFixed(2),
                    '11-25_jahre': kfwRates['11-25_jahre'].toFixed(2),
                    '26-35_jahre': kfwRates['26-35_jahre'].toFixed(2)
                },
                quelle: 'Playwright (gerendert)'
            },
            interhyp: {
                rates: {
                    'zinsbindung_10': marktZins ? marktZins.toFixed(2) : interhypRate.toFixed(2)
                }
            },
            updated: new Date().toISOString()
        };
        
        // JSON speichern
        fs.writeFileSync(OUTPUT_FILE, JSON.stringify(data, null, 2), 'utf8');
        console.log(`\n✅ JSON erfolgreich gespeichert: ${OUTPUT_FILE}`);
        console.log(`Größe: ${fs.statSync(OUTPUT_FILE).size} Bytes`);
        
        console.log('\n📄 Inhalt:');
        console.log(`KfW 4-10 Jahre:   ${data.kfw.rates['4-10_jahre']}%`);
        console.log(`KfW 11-25 Jahre:  ${data.kfw.rates['11-25_jahre']}%`);
        console.log(`KfW 26-35 Jahre:  ${data.kfw.rates['26-35_jahre']}%`);
        console.log(`Markt 10 Jahre:   ${data.interhyp.rates.zinsbindung_10}%`);
        
        // Change-Detection (optional für Alerts)
        if (previousData && previousData.kfw && previousData.kfw.rates) {
            const oldRates = previousData.kfw.rates;
            const newRates = data.kfw.rates;
            
            let changed = false;
            for (const key of Object.keys(newRates)) {
                if (oldRates[key] !== newRates[key]) {
                    console.log(`\n🔔 ÄNDERUNG: ${key}: ${oldRates[key]}% → ${newRates[key]}%`);
                    changed = true;
                }
            }
            
            if (!changed) {
                console.log('\n✓ Keine Zinsänderungen seit letztem Update');
            }
        }
        
        console.log('\n=== Scraping erfolgreich abgeschlossen ===');
        process.exit(0);
        
    } catch (error) {
        console.error('\n❌ Scraping fehlgeschlagen:', error.message);
        console.error('Stack:', error.stack);
        
        // Fallback: Vorhandene JSON beibehalten
        if (previousData) {
            console.log('\n→ Behalte vorhandene JSON bei (kein Update)');
            process.exit(0);
        } else {
            console.log('\n→ Keine Fallback-Daten verfügbar');
            process.exit(1);
        }
    }
}

// Script ausführen
if (require.main === module) {
    main();
}

#!/usr/bin/env python3
"""
KfW 300 Zinssätze Scraper mit Interhyp-Vergleich
Lädt täglich die aktuellen Zinssätze von der KfW-Website und Interhyp und speichert sie als JSON
"""

import requests
from bs4 import BeautifulSoup
import json
from datetime import datetime
import re

def scrape_kfw_zinsen():
    """
    Scrapt die KfW-Website für Programm 300 Zinssätze
    """
    # KfW 300 Konditionsseite
    url = "https://www.kfw.de/inlandsfoerderung/Privatpersonen/Neubau/F%C3%B6rderprodukte/Wohneigentum-f%C3%BCr-Familien-(300)/"
    
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    }
    
    try:
        response = requests.get(url, headers=headers, timeout=10)
        response.raise_for_status()
        
        soup = BeautifulSoup(response.content, 'html.parser')
        
        # Zinssätze extrahieren (anpassen an tatsächliche HTML-Struktur)
        # Dies ist ein Template - muss an die tatsächliche Struktur angepasst werden
        zinsen = {
            "updated": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "program": "KfW 300 - Wohneigentum für Familien",
            "rates": {
                "4-10_jahre": {
                    "laufzeit": "4 bis 10 Jahre",
                    "zinsbindung": "10 Jahre",
                    "tilgungsfrei": "1 bis 2 Jahre",
                    "sollzins": 0.0,
                    "effektivzins": 0.0
                },
                "11-25_jahre": {
                    "laufzeit": "11 bis 25 Jahre",
                    "zinsbindung": "10 Jahre",
                    "tilgungsfrei": "1 bis 3 Jahre",
                    "sollzins": 0.0,
                    "effektivzins": 0.0
                },
                "26-35_jahre": {
                    "laufzeit": "26 bis 35 Jahre",
                    "zinsbindung": "10 Jahre",
                    "tilgungsfrei": "1 bis 5 Jahre",
                    "sollzins": 0.0,
                    "effektivzins": 0.0
                }
            },
            "source_url": url
        }
        
        # Versuche Zinssätze zu finden (diese Selektoren müssen angepasst werden!)
        # Beispiel für mögliche Struktur:
        zins_tabelle = soup.find('table', {'class': lambda x: x and 'konditionen' in x.lower() if x else False})
        
        if zins_tabelle:
            rows = zins_tabelle.find_all('tr')
            for row in rows:
                cols = row.find_all('td')
                if len(cols) >= 2:
                    text = ' '.join([col.get_text(strip=True) for col in cols])
                    
                    # Suche nach Zinssätzen (Format: X,XX %)
                    zins_matches = re.findall(r'(\d+[,\.]\d+)\s*%', text)
                    
                    if '4' in text and '10' in text and zins_matches:
                        zinsen['rates']['4-10_jahre']['sollzins'] = float(zins_matches[0].replace(',', '.'))
                        if len(zins_matches) > 1:
                            zinsen['rates']['4-10_jahre']['effektivzins'] = float(zins_matches[1].replace(',', '.'))
                    
                    elif '11' in text and '25' in text and zins_matches:
                        zinsen['rates']['11-25_jahre']['sollzins'] = float(zins_matches[0].replace(',', '.'))
                        if len(zins_matches) > 1:
                            zinsen['rates']['11-25_jahre']['effektivzins'] = float(zins_matches[1].replace(',', '.'))
                    
                    elif '26' in text and '35' in text and zins_matches:
                        zinsen['rates']['26-35_jahre']['sollzins'] = float(zins_matches[0].replace(',', '.'))
                        if len(zins_matches) > 1:
                            zinsen['rates']['26-35_jahre']['effektivzins'] = float(zins_matches[1].replace(',', '.'))
        
        # Fallback: Wenn Scraping fehlschlägt, verwende Platzhalter
        if all(zinsen['rates'][key]['sollzins'] == 0.0 for key in zinsen['rates']):
            print("⚠️ Warnung: Keine Zinssätze gefunden. Bitte HTML-Struktur prüfen!")
            print("Verwende Platzhalter-Werte...")
            
            # Platzhalter (aktualisiert: 11.02.2026)
            zinsen['rates']['4-10_jahre']['sollzins'] = 0.01
            zinsen['rates']['4-10_jahre']['effektivzins'] = 0.01
            zinsen['rates']['11-25_jahre']['sollzins'] = 1.09
            zinsen['rates']['11-25_jahre']['effektivzins'] = 1.10
            zinsen['rates']['26-35_jahre']['sollzins'] = 1.32
            zinsen['rates']['26-35_jahre']['effektivzins'] = 1.33
            zinsen['manual_update_required'] = True
        
        print("✅ KfW Zinssätze extrahiert")
        
        return zinsen
        
    except Exception as e:
        print(f"❌ Fehler beim KfW-Scraping: {e}")
        
        # Erstelle minimale Daten mit Fehlermeldung
        error_data = {
            "updated": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "error": str(e),
            "message": "Scraping fehlgeschlagen. Bitte manuell prüfen.",
            "program": "KfW 300 - Wohneigentum für Familien",
            "rates": {
                "4-10_jahre": {
                    "laufzeit": "4 bis 10 Jahre",
                    "zinsbindung": "10 Jahre",
                    "tilgungsfrei": "1 bis 2 Jahre",
                    "sollzins": None,
                    "effektivzins": None
                },
                "11-25_jahre": {
                    "laufzeit": "11 bis 25 Jahre",
                    "zinsbindung": "10 Jahre",
                    "tilgungsfrei": "1 bis 3 Jahre",
                    "sollzins": None,
                    "effektivzins": None
                },
                "26-35_jahre": {
                    "laufzeit": "26 bis 35 Jahre",
                    "zinsbindung": "10 Jahre",
                    "tilgungsfrei": "1 bis 5 Jahre",
                    "sollzins": None,
                    "effektivzins": None
                }
            },
            "source_url": "https://www.kfw.de/inlandsfoerderung/Privatpersonen/Neubau/F%C3%B6rderprodukte/Wohneigentum-f%C3%BCr-Familien-(300)/"
        }
        
        return error_data

def scrape_interhyp_zinsen():
    """
    Scrapt Interhyp-Zinssätze für Zinsbindung 10 Jahre, Beleihungsauslauf <90%
    """
    url = "https://www.interhyp.de/zinsen/"
    
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    }
    
    try:
        response = requests.get(url, headers=headers, timeout=10)
        response.raise_for_status()
        
        soup = BeautifulSoup(response.content, 'html.parser')
        
        interhyp_data = {
            "zinsbindung_10": {
                "zinsbindung": "10 Jahre",
                "beleihung": "< 90%",
                "zins": None
            }
        }
        
        # Suche nach der Zinssatz-Tabelle
        # Interhyp hat oft eine Tabelle mit Zinsbindung als Zeilen und Beleihung als Spalten
        
        # Strategie 1: Suche nach Tabelle mit "Zinsbindung" und "Beleihungsauslauf"
        tables = soup.find_all('table')
        
        for table in tables:
            # Suche nach Header mit "Beleihungsauslauf >90" oder ähnlich
            headers = table.find_all(['th', 'td'])
            header_texts = [h.get_text(strip=True) for h in headers]
            
            # Prüfe ob es eine Zinsbindungs-Tabelle ist
            if any('Beleihung' in text or '90' in text for text in header_texts):
                print("📊 Gefundene Tabelle mit Beleihung-Spalten")
                
                # Finde alle Zeilen
                rows = table.find_all('tr')
                
                for row in rows:
                    cells = row.find_all(['td', 'th'])
                    if len(cells) >= 4:  # Mindestens 4 Spalten erwartet
                        row_text = cells[0].get_text(strip=True)
                        
                        # Suche nach Zeile mit "10" für 10 Jahre Zinsbindung
                        if row_text == '10' or '10 Jahre' in row_text:
                            print(f"✓ Gefundene Zeile: 10 Jahre Zinsbindung")
                            
                            # Die Spalten sind typisch: [Zinsbindung, <70, =80, >90]
                            # Für <90% nehmen wir die letzte Spalte (>90 ist außerhalb)
                            # oder die vorletzte Spalte (wenn =80 gemeint ist)
                            
                            # Versuche den Zins aus der letzten Spalte zu extrahieren
                            zins_text = cells[-1].get_text(strip=True)
                            
                            # Extrahiere Zahl im Format X,XX % oder X.XX %
                            zins_match = re.search(r'(\d+[,\.]\d{1,2})\s*%?', zins_text)
                            
                            if zins_match:
                                zins_str = zins_match.group(1).replace(',', '.')
                                interhyp_data['zinsbindung_10']['zins'] = float(zins_str)
                                print(f"✅ Interhyp Zins (10 Jahre, >90%): {zins_str}%")
                                break
                
                if interhyp_data['zinsbindung_10']['zins'] is not None:
                    break
        
        # Strategie 2: Fallback - Suche nach spezifischen Mustern im Text
        if interhyp_data['zinsbindung_10']['zins'] is None:
            print("⚠️ Tabelle nicht gefunden, versuche Textsuche...")
            
            # Suche nach Muster wie "10" gefolgt von Zinssätzen
            text_content = soup.get_text()
            lines = text_content.split('\n')
            
            for i, line in enumerate(lines):
                if line.strip() == '10' or 'Zinsbindung Tranche' in line:
                    # Schaue in den nächsten Zeilen nach Zinssätzen
                    for j in range(i, min(i+5, len(lines))):
                        zins_matches = re.findall(r'(\d+[,\.]\d{2})\s*%', lines[j])
                        if zins_matches:
                            # Nehme den letzten gefundenen Wert (oft >90%)
                            zins_str = zins_matches[-1].replace(',', '.')
                            interhyp_data['zinsbindung_10']['zins'] = float(zins_str)
                            print(f"✅ Interhyp Zins aus Text: {zins_str}%")
                            break
                    
                    if interhyp_data['zinsbindung_10']['zins'] is not None:
                        break
        
        # Wenn nichts gefunden wurde, Platzhalter verwenden
        if interhyp_data['zinsbindung_10']['zins'] is None:
            print("⚠️ Warnung: Keine Interhyp-Zinssätze gefunden. Verwende Platzhalter...")
            interhyp_data['zinsbindung_10']['zins'] = 3.96  # Platzhalter aktualisiert
        
        return interhyp_data
        
    except Exception as e:
        print(f"⚠️ Fehler beim Interhyp-Scraping: {e}")
        print("Verwende Platzhalter-Wert...")
        return {
            "zinsbindung_10": {
                "zinsbindung": "10 Jahre",
                "beleihung": "< 90%",
                "zins": 3.96  # Platzhalter aktualisiert
            }
        }

def scrape_all():
    """
    Scrapt KfW und Interhyp Zinssätze und kombiniert sie
    """
    print("🔄 Starte Scraping...")
    print("\n--- KfW 300 ---")
    kfw_data = scrape_kfw_zinsen()
    
    print("\n--- Interhyp ---")
    interhyp_data = scrape_interhyp_zinsen()
    
    # Kombiniere beide Datenquellen
    combined_data = {
        "updated": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "kfw": {
            "program": kfw_data.get('program', 'KfW 300'),
            "rates": kfw_data.get('rates', {}),
            "source_url": kfw_data.get('source_url', '')
        },
        "interhyp": {
            "rates": interhyp_data,
            "source_url": "https://www.interhyp.de/zinsen/"
        }
    }
    
    # Speichere kombinierte Daten
    with open('zinsen-kfw300.json', 'w', encoding='utf-8') as f:
        json.dump(combined_data, f, indent=2, ensure_ascii=False)
    
    print("\n✅ Alle Daten erfolgreich gespeichert!")
    print(json.dumps(combined_data, indent=2, ensure_ascii=False))
    
    return combined_data

if __name__ == "__main__":
    scrape_all()

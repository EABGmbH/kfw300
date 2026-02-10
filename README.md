# KfW-300 Förderrechner

Interaktiver Online-Rechner für das KfW-Programm 300 "Wohneigentum für Familien" mit automatischer täglicher Aktualisierung der Zinssätze.

## 🚀 Features

- ✅ Interaktive Schritt-für-Schritt-Berechnung
- ✅ Live-Validierung der Eingaben
- ✅ Automatische Aktualisierung der KfW-Zinssätze (täglich)
- ✅ Responsive Design (Desktop & Mobile)
- ✅ Detaillierte Konditionsübersicht

## 📁 Projektstruktur

```
kfw300/
├── kfw300-rechner.html          # Haupt-HTML-Datei
├── scrape_kfw_zinsen.py         # Python-Script zum Scraping
├── zinsen-kfw300.json           # JSON mit aktuellen Zinssätzen
├── requirements.txt             # Python Dependencies
├── .github/
│   └── workflows/
│       └── scrape-daily.yml     # GitHub Actions Workflow
└── README.md                    # Diese Datei
```

## 🔧 Setup

### 1. GitHub Repository erstellen

1. Erstelle ein neues GitHub Repository (z.B. `kfw300`)
2. Pushe alle Dateien in das Repository

```bash
git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin https://github.com/DEIN-USERNAME/kfw300.git
git push -u origin main
```

### 2. HTML-Datei anpassen

In `kfw300-rechner.html` Zeile ~888:

```javascript
const GITHUB_JSON_URL = 'https://raw.githubusercontent.com/DEIN-USERNAME/kfw300/main/zinsen-kfw300.json';
```

Ersetze `DEIN-USERNAME` mit deinem GitHub-Benutzernamen!

### 3. GitHub Actions aktivieren

Die GitHub Action läuft automatisch täglich um 6:00 Uhr (UTC) und:
- Scrapt die KfW-Website
- Aktualisiert `zinsen-kfw300.json`
- Committed die Änderungen

**Manuelles Triggern:**
- Gehe zu `Actions` → `Scrape KfW 300 Zinssätze täglich` → `Run workflow`

### 4. Auf deinen Server hochladen

Lade `kfw300-rechner.html` auf deinen Server hoch. Die HTML-Datei lädt automatisch die aktuellen Zinssätze von GitHub.

## 🔍 Wie funktioniert es?

```
┌─────────────────┐
│  GitHub Actions │  ← Läuft täglich um 6 Uhr
│   (Cron Job)    │
└────────┬────────┘
         │
         ▼
┌─────────────────────┐
│ scrape_kfw_zinsen.py│  ← Scrapt KfW-Website
└────────┬────────────┘
         │
         ▼
┌─────────────────────┐
│ zinsen-kfw300.json  │  ← Wird ins Repo committed
└────────┬────────────┘
         │
         ▼
┌─────────────────────┐
│  Dein Server (HTML) │  ← Lädt JSON von GitHub
└─────────────────────┘
         │
         ▼
┌─────────────────────┐
│   User Browser      │  ← Sieht aktuelle Zinsen
└─────────────────────┘
```

## 🛠️ Scraper anpassen

Falls die KfW ihre Website-Struktur ändert, musst du das Scraping-Script anpassen:

1. Öffne `scrape_kfw_zinsen.py`
2. Passe die CSS-Selektoren in Zeile ~35 an
3. Teste lokal: `python scrape_kfw_zinsen.py`
4. Committe die Änderungen

## 🧪 Lokales Testen

```bash
# Python Dependencies installieren
pip install -r requirements.txt

# Scraper testen
python scrape_kfw_zinsen.py

# Prüfen ob JSON erstellt wurde
cat zinsen-kfw300.json

# HTML im Browser öffnen (ändere vorher die GITHUB_JSON_URL zu einer lokalen Datei)
# Oder starte einen lokalen Server:
python -m http.server 8000
# Dann öffne: http://localhost:8000/kfw300-rechner.html
```

## 📝 Manuelle Aktualisierung

Falls das Scraping nicht funktioniert, kannst du die Zinssätze manuell aktualisieren:

1. Besuche: https://www.kfw.de/300
2. Öffne `zinsen-kfw300.json`
3. Ändere die Werte in `rates`
4. Commit & Push

```json
{
  "rates": {
    "4-10_jahre": {
      "sollzins": 2.15,
      "effektivzins": 2.17
    },
    ...
  }
}
```

## ⚠️ Wichtige Hinweise

- **Disclaimer**: Dies ist ein inoffizielles Tool zur Orientierung
- **Keine Garantie**: Die Zinssätze werden automatisch gescrapt und können fehlerhaft sein
- **Aktualität prüfen**: Bei wichtigen Entscheidungen immer die offizielle KfW-Website konsultieren
- **Website-Änderungen**: Bei Änderungen der KfW-Website muss der Scraper angepasst werden

## 📧 Support

Bei Problemen:
1. Prüfe die GitHub Actions Logs
2. Teste das Scraping-Script lokal
3. Prüfe die Browser-Konsole auf Fehler beim Laden der JSON

## 📄 Lizenz

Dieses Projekt ist für private und kommerzielle Nutzung frei verfügbar.

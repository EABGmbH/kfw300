# KfW 300 Konditionen-Update (Node.js 20 + TypeScript)

Dieses Repo enthält einen produktionsreifen CLI-Job, der täglich die Konditionen für KfW Programm 300 aus der offiziellen Konditionenanzeiger-Seite zieht und in `data/kfw/300.json` schreibt.

## Quelle

- `https://www.kfw-formularsammlung.de/KonditionenanzeigerINet/KonditionenAnzeiger`

## Lokal testen

Voraussetzung: Node.js 20

```bash
npm ci
npm run kfw:300
```

Bei erfolgreichem Lauf wird `data/kfw/300.json` aktualisiert.
Bei Validierungsfehlern wird die Datei **nicht** überschrieben und der Prozess endet mit Exit-Code 1.

## GitHub Actions

Workflow: `.github/workflows/kfw300.yml`

- Geplante Ausführung täglich um 06:00 Europe/Berlin
- Läuft `npm ci` und `npm run kfw:300`
- Commit + Push nur bei Änderungen in `data/kfw/300.json`
- Bei Fehlern schlägt der Workflow fehl (Notifications möglich)

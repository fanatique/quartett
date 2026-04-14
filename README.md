# Quartett 🚗 🛻

Browserbasiertes Quartettspiel mit mehreren Themen-Decks, lokaler Deck-Verwaltung per JSON und einem einfachen Zwei-Spieler-Modus über PHP-Sessiondateien.

Die Oberfläche liegt als Single-Page-App in `index.html`. Die Daten kommen aus JSON-Dateien in `decks/`. PHP-Endpunkte unter `api/` liefern Deck-Metadaten, proxien Bilder und verwalten Spielsessions.

## Features

- Deck-Auswahl direkt aus `decks/index.json`
- Karten- und Coverbilder über einen serverseitigen Bild-Proxy mit Cache
- Zwei-Spieler-Modus per Session-Link
- Kein Build-Prozess, kein Node-Setup
- Decks lassen sich durch neue JSON-Dateien erweitern

## Tech-Stack

- Frontend: HTML, CSS, Vue 3, Bootstrap 5
- Backend: PHP
- Datenspeicherung: JSON-Dateien und Dateisystem-basierte Sessions
- Deployment: GitHub Actions per FTP

## Lokale Entwicklung

Voraussetzungen:

- PHP 8.x
- PHP-Erweiterungen `curl` und `gd`
- Schreibrechte für `cache/` und `api/sessions/`

Lokalen Server starten:

```bash
php -S localhost:8000
```

Dann im Browser öffnen:

```text
http://localhost:8000
```

Hinweise:

- Die App lädt Vue und Bootstrap per CDN.
- Der Bild-Proxy in `api/image.php` lädt entfernte Bilder nach und speichert WebP-Dateien unter `cache/`.
- Mehrspieler-Sessions werden als JSON-Dateien unter `api/sessions/` abgelegt.

## Projektstruktur

```text
.
├── index.html          # SPA mit Menü, Spielansicht und Session-Logik
├── api/
│   ├── common.php      # gemeinsame Hilfsfunktionen und Validierung
│   ├── deck.php        # Deck-Index und einzelne Decks als API
│   ├── image.php       # Bild-Proxy, Resize, WebP-Cache
│   ├── session.php     # Zwei-Spieler-Sessions und Spielablauf
│   └── sessions/       # persistierte Sessions
├── decks/
│   ├── index.json      # Liste aller verfügbaren Decks
│   └── *.json          # einzelne Deck-Definitionen
├── cache/              # erzeugte Bild-Caches
└── .github/workflows/
    └── deploy.yml      # FTP-Deployment
```

## APIs

### Deck-API

- `GET api/deck.php?action=index`
  Liefert alle Decks aus `decks/index.json`. Coverbilder werden als Proxy-URL zurückgegeben.

- `GET api/deck.php?action=deck&id=<deckId>`
  Liefert Deck-Metadaten, Kategorien und Karten eines einzelnen Decks. `imageUrl`-Felder der Karten werden dabei nicht an den Client weitergegeben.

### Bild-API

- `GET api/image.php?deck=<deckId>&type=cover`
- `GET api/image.php?deck=<deckId>&card=<cardId>`

Der Endpoint lädt erlaubte externe Bilder, schneidet sie passend zu und cached sie als WebP.

Aktuell erlaubte Bildquellen sind in `api/common.php` definiert:

- `commons.wikimedia.org`
- `upload.wikimedia.org`
- `static.necy.eu`

### Session-API

- `POST api/session.php?action=create`
- `POST api/session.php?action=join`
- `GET api/session.php?action=state`
- `POST api/session.php?action=tap`
- `POST api/session.php?action=select`
- `POST api/session.php?action=next`

Die Session-Logik ist dateibasiert und für einfache Zwei-Spieler-Partien ausgelegt.

## Neues Deck anlegen

1. Neue Deck-Datei in `decks/` anlegen.
2. Deck in `decks/index.json` registrieren.
3. Darauf achten, dass externe Bilder von einer erlaubten Domain kommen.

Minimales Format:

```json
{
  "deck": {
    "id": "beispiel-deck",
    "title": "Beispiel-Deck",
    "coverImageUrl": "https://example.org/cover.jpg",
    "titleImageUrl": "https://example.org/title.jpg",
    "locale": "de-DE"
  },
  "categories": [
    {
      "key": "leistung_ps",
      "label": "Leistung",
      "unit": "PS",
      "better": "higher"
    }
  ],
  "cards": [
    {
      "id": "CAR01",
      "title": "Beispielkarte",
      "imageUrl": "https://example.org/card.jpg",
      "status": "complete",
      "stats": {
        "leistung_ps": 150
      }
    }
  ]
}
```

Wichtig:

- `id`-Werte sollten nur Buchstaben, Zahlen, `_` und `-` enthalten.
- Für spielbare Karten wird aktuell `status: "complete"` erwartet.
- Die `stats`-Schlüssel müssen zu den `categories[].key`-Werten passen.

## Deployment

Bei Pushes auf `main` läuft `.github/workflows/deploy.yml` und deployt per FTPS in das Zielverzeichnis `./quartet/`.

Benötigte Secrets:

- `FTP_SERVER`
- `FTP_USERNAME_PRODUCTION`
- `FTP_PASSWORD_PRODUCTION`

## Mögliche nächste Schritte

- README um Screenshots ergänzen
- Deck-Schema formalisieren
- Sessions robuster machen, z. B. mit besserem Error-Handling und Cleanup-Monitoring

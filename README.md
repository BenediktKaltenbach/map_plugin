# My Map Plugin

Interaktive Leaflet-Karte für WordPress mit Marker-Kategorien, farbcodierten Markern und Nutzervorschlägen.

## Installation

1. Diesen Ordner nach `wp-content/plugins/my-map-plugin/` kopieren
2. Leaflet.js herunterladen (siehe `assets/leaflet/INSTALL.txt`)
3. In WordPress: **Plugins → Aktivieren**

## Verwendung

Shortcode in beliebigen Seiten/Posts einfügen:
```
[my_map]
```

Optional mit Parametern:
```
[my_map height="600" zoom="12" lat="48.775" lng="9.182"]
```

## Admin-Bereich

Nach Aktivierung erscheint im WordPress-Menü:

- **Marker** – Marker auf der Karte verwalten, neue hinzufügen
- **Vorschläge** – Nutzervorschläge prüfen, freigeben oder ablehnen
- **Kategorien** – Kategorien mit Farben und Icons anlegen
- **Einstellungen** – Standardzoom, Kartenmittelpunkt, Kartenhöhe

## Sicherheit

- Alle Eingaben werden serverseitig mit `sanitize_text_field()`, `wp_kses()`, `sanitize_hex_color()` etc. bereinigt
- CSRF-Schutz via WordPress Nonces
- Nutzervorschläge landen als `pending` und sind bis zur Freigabe unsichtbar
- Honeypot-Feld gegen einfache Spam-Bots
- Rate-Limiting: max. 5 Vorschläge pro IP pro Stunde

## Dateistruktur

```
my-map-plugin/
├── my-map-plugin.php          # Plugin-Hauptdatei
├── includes/
│   ├── class-cpt.php          # Custom Post Types
│   ├── class-sanitizer.php    # Eingaben bereinigen
│   └── class-rest-api.php     # REST-Endpoints
├── admin/
│   ├── class-admin.php        # Admin-Seiten
│   ├── admin.css
│   └── admin.js
├── public/
│   ├── class-map-shortcode.php
│   ├── css/map.css
│   └── js/map.js
└── assets/
    └── leaflet/               # Leaflet hier ablegen
```

# AHX WP Mail

IMAP-Postfach-Viewer im WordPress-Frontend mit konto-spezifischen Zugangsdaten, Regeln und optimiertem Ladeverhalten.

## Funktionsumfang

- Frontend-Postfachansicht mit Ordnern, Listenansicht und Detailansicht.
- Mehrere Konten pro Benutzerprofil.
- Regeln fuer automatisierte Aktionen (z. B. markieren, verschieben, archivieren, loeschen).
- Move-Empfehlungen basierend auf Absender-Historie.
- Bulk-Aktionen (gelesen/ungelesen, verschieben, archivieren, loeschen).
- Optionales Leeren des Papierkorbs.

## Installation

1. Plugin-Ordner nach `wp-content/plugins/ahx_wp_mail` kopieren.
2. Plugin in WordPress aktivieren.
3. IMAP-Einstellungen hinterlegen:
   - global ueber Plugin-/Admin-Einstellungen
   - oder konto-spezifisch im Benutzerprofil

## Nutzung im Frontend

Das Plugin wird ueber den vorgesehenen Shortcode/Frontend-Container der Plugin-Integration gerendert.

Wichtige UI-Bereiche:

- Ordnerleiste (links)
- Nachrichtenliste (Mitte)
- Detailansicht mit Aktionen (rechts/Panel)

## Konfiguration

### IMAP

- Host
- Port
- Verschluesselung (`ssl`, `tls`, `none`)
- Benutzername und Passwort pro Konto

### Anzeige

- Nachrichten pro Seite
- Verhalten fuer "als gelesen markieren" (z. B. beim Oeffnen)

### Regeln

Regeln koennen pro Konto konfiguriert werden:

- Filter: Von, An, Betreff, Ordner
- Aktion: markieren, verschieben, archivieren, loeschen

## Performance-Optimierungen (aktueller Stand)

### 1) Fast-Path fuer Listen

Die Listenansicht wird ohne teure Attachment-Strukturpruefung geladen und anschliessend ergaenzt.

### 2) Serverseitige Caches

- Versionierte Cache-Keys pro Benutzer/Konto
- Listen-Cache mit dynamischer TTL
- Ordnerliste gecacht
- Ordner-Statistiken pro Ordner einzeln gecacht
- Detailansicht (Mail-Inhalt) gecacht

### 3) Cache-Invalidierung

Mutierende Aktionen invalidieren die Cache-Version:

- markieren
- loeschen
- verschieben
- archivieren
- papierkorb leeren

### 4) Attachment-Flags

- Seite 1: Flags werden serverseitig vorab mitgeliefert
- Weitere Seiten/fehlende Flags: lazy Nachladen per Batch-Endpoint
- Frontend-Cache mit Obergrenze und Eviction

### 5) Folder-Stats: priorisiert + deferred

- Wichtige Ordner werden zuerst berechnet
- Restliche Ordner-Stats werden im Hintergrund nachgeladen

### 6) Stale-while-revalidate im Frontend

- Optionales sofortiges Rendern aus sessionStorage
- Hintergrund-Refresh aktualisiert Daten
- Sichtbarer Hinweis bei gecachter Darstellung

## Sicherheit

- WordPress-Nonce-Pruefung fuer AJAX-Endpunkte
- Login- und Berechtigungspruefungen
- Sanitizing/Validierung von Eingaben

## Troubleshooting

### "Verbindungsfehler. Bitte Seite neu laden."

- IMAP-Zugangsdaten pruefen.
- Erreichbarkeit des IMAP-Hosts pruefen.
- SSL/TLS/Port-Kombination pruefen.
- Falls noetig in den Plugin-Logs bzw. Browser-Devtools (Network) den fehlgeschlagenen AJAX-Call identifizieren.

### Keine Ordner oder leere Liste

- Konto-Daten im Benutzerprofil kontrollieren.
- Berechtigungen und aktives Konto pruefen.
- IMAP-Verbindungstest ausfuehren.

### Empfehlungen/Flags fehlen kurzfristig

- Bei erstem Laden kann Nachladen asynchron passieren.
- Nach kurzer Zeit oder Refresh sollte der Zustand vollstaendig sein.

## Entwicklungshinweise

Relevante Dateien:

- `ahx_wp_mail.php`: AJAX-Endpunkte, Caching, Runner, Hauptlogik
- `includes/class-imap.php`: IMAP-Zugriff und Mail-Operationen
- `assets/mail.js`: Frontend-State, Rendering, AJAX-Flow
- `assets/mail.css`: UI-Styles
- `frontend/shortcode.php`: Frontend-Markup

## Lizenz

GPL2
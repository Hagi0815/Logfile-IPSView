# Log Analyzer IPSView

**Version:** 1.1 · **Autor:** Christian Hagedorn · **IPS-Kompatibilität:** 8.1+

Ein vollständiger Log-Viewer für IP-Symcon, der direkt in einer HTML-Box oder im IPSView/WebFront angezeigt wird. Alle Interaktionen (Filter, Navigation, Export) laufen über einen WebHook – ohne Seitenneuladen der gesamten Oberfläche.

---

## Installation

1. Modul über Modulcontrol installieren https://github.com/Hagi0815/Logfile-IPSView
2. Den WebHook in IPS registrieren: **Skripte → WebHook Control → Neuer Hook**
   - Hook-Pfad: `/hook/LogAnalyzerIPSView_{InstanceID}`
   - Skript: Das automatisch erstellte Kind-Skript `WebHook Handler` der Instanz auswählen
3. Eine HTML-Box anlegen und als Inhalt einen `<iframe>` mit dem Hook-Pfad eintragen:
   ```html
   <iframe src="/hook/LogAnalyzerIPSView_12345" style="width:100%;height:100%;border:none"></iframe>
   ```

---

## Konfiguration

| Eigenschaft | Beschreibung |
|---|---|
| **Logdatei** | Standard-Logdatei beim ersten Start (wird danach per Dropdown überschrieben) |
| **Betriebsmodus** | `Standard` (bis 6 MB, PHP), `System` (grep, für große Dateien) |
| **Auto-Refresh** | Intervall für automatische Aktualisierung (0 = aus) |

---

## Oberfläche

### Toolbar (obere Leiste)

| Element | Funktion |
|---|---|
| **Logdatei** | Dropdown zur Auswahl aller verfügbaren `logfile*.log`-Dateien im IPS-Log-Verzeichnis |
| **Zeilen** | Anzahl der Zeilen pro Seite (20 / 50 / 100 / 200 / 500 / 1000) |
| **Modus** | Betriebsmodus umschalten (Standard / System) |
| **Schrift** | Schriftgröße für die gesamte Oberfläche (8–20 px) |
| **⇤** | Zur neuesten Seite springen (Tastaturkürzel: `Pos1`) |
| **‹ Neuere** | Eine Seite Richtung neuere Einträge (Tastaturkürzel: `←` oder `N`) |
| **[Seitenfeld]** | Direkte Seiteneingabe – Zahl eingeben und Enter drücken |
| **/ 143** | Gesamtseitenanzahl |
| **Ältere ›** | Eine Seite Richtung ältere Einträge (Tastaturkürzel: `→` oder `A`) |
| **⇥** | Zur ältesten Seite springen (Tastaturkürzel: `Ende`) |
| **↺** | Aktualisieren (Tastaturkürzel: `R`) |
| **⚌ Kompakt** | Kompaktansicht ein-/ausschalten (reduziert Zeilenabstand) |
| **Live** | Auto-Refresh-Intervall (Aus / 5s / 10s / 30s / 60s / 2min) |

### Export-Buttons

| Button | Funktion |
|---|---|
| **📄 PDF** | Aktuelle Seite als druckbares PDF im Browser öffnen |
| **📄 PDF Alle** | Alle gefilterten Treffer als PDF (max. 10.000 Zeilen) |
| **💾 CSV** | Aktuelle Seite als CSV-Datei herunterladen |
| **💾 CSV Alle** | Alle gefilterten Treffer als CSV (max. 10.000 Zeilen) |

PDF-Exporte öffnen in einem neuen Tab mit Metainfos (Dateiname, Filter, Datum, Zeilenanzahl) und einem Drucken-Button. CSV-Dateien sind semikolon-getrennt, UTF-8-kodiert.

### Filter-Leiste

| Element | Funktion |
|---|---|
| **Meldungstyp** | Multi-Select: Filtern nach DEBUG, ERROR, WARNING, MESSAGE, CUSTOM usw. |
| **Sender** | Multi-Select: Filtern nach Modul-/Sender-Namen |
| **Text-Filter** | Freitextsuche (Groß-/Kleinschreibung ignoriert), Treffer werden farbig hervorgehoben |
| **ObjektID** | Filtern nach einer oder mehreren Objekt-IDs (kommagetrennt) |
| **Von / Bis** | Zeitraum-Filter. Format: `YYYY-MM-DD HH:MM` oder nur `YYYY-MM-DD` |
| **Filter anwenden** | Filter aktivieren und Ergebnisse neu laden |
| **✕ Reset** | Alle Filter zurücksetzen |
| **Spalten** | Checkboxen zum Ein-/Ausblenden einzelner Spalten (wird im Browser gespeichert) |

### Tabelle

Jede Zeile enthält:

| Spalte | Beschreibung |
|---|---|
| **#** | Laufende Zeilennummer (seitenweise) |
| **Zeit** | Zeitstempel des Log-Eintrags |
| **ObjektID** | IPS-Objekt-ID – **Hover** zeigt Name und Typ des Objekts als Tooltip |
| **Typ** | Meldungstyp (farbig: ERROR=rot, WARNING=orange, DEBUG=blau usw.) – **Klick** filtert sofort nach diesem Typ |
| **Sender** | Modulname – **Klick** filtert sofort nach diesem Sender |
| **Meldung** | Log-Text. Einträge über 80 Zeichen werden gekürzt – **Klick** klappt den vollen Text auf |

### Meta-Leiste

Zwischen Toolbar und Tabelle wird angezeigt:

- Dateiname und Dateigröße
- **Fehler-Zusammenfassung für heute**: Anzahl Errors ⚠, Warnings △ und Messages (nur wenn vorhanden)
- Treffer-Anzahl und Ladezeit
- Aktive Filter als farbige Badges
- Zeitstempel des letzten Ladevorgangs

---

## Betriebsmodi

| Modus | Beschreibung | Geeignet für |
|---|---|---|
| **Standard (bis 6 MB)** | Liest die Logdatei mit PHP direkt ein | Kleinere Logdateien, Windows und Linux |
| **System (grep)** | Nutzt den System-`grep`-Befehl | Große Logdateien auf Linux-Systemen |

---

## Tastaturkürzel

Kürzel funktionieren nur wenn kein Eingabefeld aktiv ist.

| Taste | Funktion |
|---|---|
| `R` | Aktualisieren |
| `←` oder `N` | Neuere Einträge (Seite zurück) |
| `→` oder `A` | Ältere Einträge (Seite vor) |
| `Pos1` | Zur neuesten Seite |
| `Ende` | Zur ältesten Seite |

---

## Technische Details

### Architektur

Das Modul besteht aus mehreren Komponenten:

- **`module.php`** – Hauptklasse `LogAnalyzerIPSView extends IPSModuleStrict`
- **`libs/hook_handler.php`** – Separates IPS-Script das als WebHook registriert wird
- **`libs/LogAnalyzerStandardTrait.php`** – Log-Parser für den Standard-Modus
- **`libs/LogAnalyzerSystemTrait.php`** – Log-Parser für den System-Modus (grep)
- **`libs/LogAnalyzerUltraTrait.php`** – Erweiterte Analysefunktionen

### Warum ein separates Hook-Script?

`ProcessHookData()` funktioniert nicht korrekt mit `IPSModuleStrict` und `declare(strict_types=1)` in IPS 8. Stattdessen wird ein Kind-Script der Instanz automatisch erstellt und als WebHook registriert. Das Script wird bei jedem Modul-Update automatisch aktualisiert – die Script-ID (und damit der WebHook-Eintrag) bleibt stabil.

### IPS-Attribut-Cache-Problem

IPS cached Attributwerte im selben PHP-Request. Beim Logdatei-Wechsel, Schriftgrößen-Änderung und anderen sofortigen Aktionen wird der neue Wert daher direkt als Parameter durch die Render-Kette gereicht – am Cache vorbei – um sofortige Anzeige ohne zweiten Request zu gewährleisten.

### Öffentliche PHP-Funktionen

| Funktion | Beschreibung |
|---|---|
| `LOGANALYZER_VerarbeiteHookAktion($id, $aktion, $wert)` | Führt eine Aktion aus und gibt direkt HTML zurück |
| `LOGANALYZER_ErstelleHtmlDirekt($id)` | Rendert den aktuellen Stand als HTML-String |
| `LOGANALYZER_AktualisierenVisualisierung($id)` | Aktualisiert die Visualisierung (HTMLBOX) |
| `LOGANALYZER_ObjektIdAufloesen($id, $oid)` | Gibt Name und Typ einer ObjektID als JSON zurück |
| `LOGANALYZER_ExportierePdf($id, $scope)` | Gibt HTML für PDF-Export zurück (`scope`: `seite`\|`alle`) |
| `LOGANALYZER_ExportiereCsv($id, $scope)` | Gibt CSV-Inhalt zurück und setzt Download-Header |

---

## Bekannte Einschränkungen

- Der **System-Modus** (grep) funktioniert nur auf Linux-Systemen
- **PDF-Export** nutzt den Browser-Druckdialog – kein serverseitiges PDF
- **Export „Alle"** ist auf 10.000 Zeilen begrenzt (RAM/Timeout-Schutz)
- Der **Zeitraum-Filter** nutzt String-Vergleich auf dem Zeitstempel – das Format muss `YYYY-MM-DD` entsprechen

---

## Changelog

### 1.0
- Schnellfilter per Klick auf Sender/Typ
- ObjektID Hover-Tooltip (Name + Typ)
- Direkte Seiten-Eingabe + Letzte-Seite-Button
- Fehler-Zusammenfassung heute in Meta-Leiste
- Suchbegriff-Highlighting in Meldung und Sender
- Zeilennummern (#-Spalte)
- Tastaturkürzel (R, ←/→, Pos1, Ende)
- Schriftgröße-Dropdown (skaliert gesamte Oberfläche)
- Kompakt-Modus
- Auto-Refresh / Live-Modus
- Zeitraum-Filter (Von/Bis)
- Meldungen aufklappbar
- Spalten ein-/ausblenden
- PDF- und CSV-Export
- Logdatei-Auswahl per Dropdown
- Mehrseitiger Filter mit Badges
- Paginierung mit Seite vor/zurück

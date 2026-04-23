# Log Analyzer IPSView

**Version:** 1.2 · **Autor:** Christian Hagedorn · **IPS-Kompatibilität:** 8.1+

Ein vollständiger Log-Viewer für IP-Symcon mit Statistik-Dashboard, der direkt in einer HTML-Box oder im WebFront angezeigt wird. Alle Interaktionen laufen über einen WebHook – kein Seitenneulade der gesamten Oberfläche nötig.

---

## Inhaltsverzeichnis

- [Installation](#installation)
- [Konfiguration](#konfiguration)
- [Oberfläche](#oberfläche)
  - [Toolbar](#toolbar)
  - [Filter-Leiste](#filter-leiste)
  - [Tabelle](#tabelle)
  - [Meta-Leiste](#meta-leiste)
- [Statistik-Dashboard](#statistik-dashboard)
- [Betriebsmodi](#betriebsmodi)
- [Tastaturkürzel](#tastaturkürzel)
- [Technische Details](#technische-details)
- [Bekannte Einschränkungen](#bekannte-einschränkungen)
- [Changelog](#changelog)

---

## Installation

1. Modul über den IPS Modulcontrol installieren:
   ```
   https://github.com/Hagi0815/Logfile-IPSView
   ```
2. Den WebHook in IPS registrieren: **Skripte → WebHook Control → Neuer Hook**
   - Hook-Pfad: `/hook/LogAnalyzerIPSView_{InstanceID}`
   - Skript: Das automatisch erstellte Kind-Skript `WebHook Handler` der Instanz auswählen
3. Eine HTML-Box anlegen und als Inhalt einen `<iframe>` eintragen:
   ```html
   <iframe src="/hook/LogAnalyzerIPSView_12345" style="width:100%;height:100%;border:none"></iframe>
   ```

---

## Konfiguration

| Eigenschaft | Beschreibung |
|---|---|
| **Logdatei** | Standard-Logdatei beim ersten Start |
| **Betriebsmodus** | `Standard` (PHP, bis 6 MB) oder `System` (grep, für große Dateien) |
| **Auto-Refresh** | Intervall für automatische Aktualisierung (0 = deaktiviert) |

---

## Oberfläche

### Toolbar

Die oberste Leiste enthält alle Steuerelemente für Anzeige und Navigation.

| Element | Funktion |
|---|---|
| **Logdatei** | Dropdown zur Auswahl aller verfügbaren `logfile*.log`-Dateien |
| **Zeilen** | Anzahl der Einträge pro Seite (20 / 50 / 100 / 200 / 500 / 1000) |
| **Modus** | Betriebsmodus umschalten (Standard / System) |
| **Schrift** | Schriftgröße der Oberfläche (8–20 px), wird persistent gespeichert |
| **Live** | Auto-Refresh-Intervall (Aus / 5s / 10s / 30s / 60s / 2min) |
| **📄 PDF** | Aktuelle Seite als druckbares PDF öffnen |
| **📄 PDF Alle** | Alle gefilterten Treffer als PDF (max. 10.000 Zeilen) |
| **💾 CSV** | Aktuelle Seite als CSV-Datei herunterladen |
| **💾 CSV Alle** | Alle gefilterten Treffer als CSV (max. 10.000 Zeilen) |
| **⇤ / ‹ / › / ⇥** | Navigation: Neueste / Zurück / Vor / Älteste |
| **[Seitenfeld]** | Direkte Seiteneingabe – Zahl eingeben und Enter |
| **↺** | Aktualisieren (Tastaturkürzel: `R`) |
| **📊** | Statistik-Dashboard öffnen |

### Filter-Leiste

Die Filter-Leiste besteht aus zwei Zeilen unterhalb der Toolbar.

**Zeile 1 – Eingabefelder:**

| Element | Funktion |
|---|---|
| **Sender** | Checkbox-Leiste: Sofort-Filter per Klick auf einzelne Sender |
| **Text** | Freitextsuche (Groß-/Kleinschreibung ignoriert), Treffer werden hervorgehoben |
| **ObjID** | Filtern nach einer oder mehreren Objekt-IDs (kommagetrennt) |
| **Von / Bis** | Zeitraum-Filter – Format: `YYYY-MM-DD` oder `YYYY-MM-DD HH:MM` |
| **✓ Filter** | Filter anwenden und Ergebnisse neu laden |
| **✕ Reset** | Alle Filter zurücksetzen |

**Zeile 2 – Typ-Auswahl:**

Farbige Checkboxen für jeden Meldungstyp (CUSTOM, DEBUG, ERROR, MESSAGE, NOTIFY, SUCCESS, WARNING). Ein Klick filtert sofort ohne den Filter-Button zu drücken.

**Aktive Filter** werden unterhalb als farbige Badges angezeigt – ein Klick auf einen Badge entfernt genau diesen Filter einzeln.

### Tabelle

| Spalte | Beschreibung |
|---|---|
| **#** | Laufende Zeilennummer |
| **Zeit** | Zeitstempel des Log-Eintrags |
| **ObjektID** | IPS-Objekt-ID mit Name darunter – **Hover** zeigt Typ des Objekts |
| **Typ** | Meldungstyp (farbig) – **Klick** filtert sofort nach diesem Typ |
| **Sender** | Modulname – **Klick** filtert sofort nach diesem Sender |
| **Meldung** | Log-Text – bei über 80 Zeichen gekürzt, **Klick** klappt vollen Text auf |

Ein **Klick auf eine Tabellenzeile** öffnet eine Detailansicht mit allen Feldern aufgeklappt.

### Meta-Leiste

Zwischen Filter und Tabelle wird angezeigt:

- Dateiname und Dateigröße
- **Fehler-Zusammenfassung für heute**: Anzahl Errors ⚠, Warnings △, Messages
- Treffer-Anzahl, Ladezeit und Zeitstempel des letzten Ladevorgangs
- Aktive Filter als klickbare Badges

---

## Statistik-Dashboard

Das Dashboard ist über den **📊-Button** in der Toolbar erreichbar und lädt direkt in der HTML-Box.

### Übersicht (Meta-Leiste)

Zeigt auf einen Blick: Gesamtzeilenzahl, Errors/Warnings gesamt, Fehler heute, Fehler gestern.

### Diagramm 1 – Errors + Warnings nach Uhrzeit

Balkendiagramm mit 24 Stunden. Zwei überlagerte Balken pro Stunde:
- **Farbiger Balken** = Heute (grün/orange/rot je nach Häufigkeit)
- **Grauer Balken** = Gestern (Vergleich)

Y-Achse mit Gitterlinien. **Hover** zeigt Uhrzeit, Heute/Gestern-Werte und häufigste Meldung. **Klick** öffnet Detailliste aller Einträge dieser Stunde.

### Diagramm 2 – Verlauf letzte 30 Tage

Balkendiagramm mit logarithmischer Y-Achse (verhindert dass ein einzelner großer Tag alles dominiert). Heute wird rot hervorgehoben, gestern orange.

**Hover** zeigt Datum und Gesamtanzahl. **Klick** öffnet Detailliste aller Einträge des jeweiligen Tages.

### Diagramm 3 – Heatmap Wochentag × Uhrzeit

7×24 Gitter. Farbe von dunkelrot bis hellrot zeigt die Häufigkeit. Zahlen in den Zellen bei ausreichender Breite.

**Hover** zeigt Wochentag, Uhrzeit und Anzahl. **Klick** öffnet Detailliste aller Einträge für diesen Wochentag und diese Stunde (alle Vorkommen im gesamten Logfile).

### Diagramm 4 – Verteilung nach Wochentag

Balkendiagramm Mo–So mit Y-Achse. Wochenende leicht anders eingefärbt.

**Hover** zeigt Wochentag und Anzahl. **Klick** öffnet Detailliste aller Einträge dieses Wochentags.

### Detail-Overlay

Beim Klick auf einen Balken oder eine Heatmap-Zelle öffnet sich ein modales Panel mit:
- Titel mit Zeitangabe und Anzahl der Einträge
- Tabelle mit Zeit, Typ (farbig), Sender und Meldung
- Bis zu 300 Einträge, neueste zuerst
- Schließen per Button, Klick auf Hintergrund

### Tabellen

| Tabelle | Inhalt |
|---|---|
| **Häufigste Fehler** | Top 25 Fehlermeldungen mit Anzahl, Erstauftreten und Balken |
| **Fehler-Gruppen** | Ähnliche Fehlermeldungen zusammengefasst (Varianten-Anzahl) |
| **Aktivste Sender** | Top 15 Sender mit Gesamtanzahl, Error-Hervorhebung und Typ-Aufschlüsselung |
| **Typ-Verteilung** | Alle Typen mit Anzahl, Prozentsatz und Balken |

---

## Betriebsmodi

| Modus | Beschreibung | Geeignet für |
|---|---|---|
| **Standard (bis 6 MB)** | Liest die Logdatei mit PHP direkt ein | Kleinere Logdateien, Windows + Linux |
| **System (grep)** | Nutzt `grep` auf Systemebene | Große Logdateien auf Linux |

---

## Tastaturkürzel

Funktionieren nur wenn kein Eingabefeld aktiv ist.

| Taste | Funktion |
|---|---|
| `R` | Aktualisieren |
| `←` oder `N` | Neuere Einträge |
| `→` oder `A` | Ältere Einträge |
| `Pos1` | Zur neuesten Seite |
| `Ende` | Zur ältesten Seite |

---

## Technische Details

### Architektur

| Datei | Beschreibung |
|---|---|
| `module.php` | Hauptklasse `LogAnalyzerIPSView extends IPSModuleStrict` |
| `libs/hook_handler.php` | WebHook-Script – wird automatisch als Kind-Skript erstellt |
| `libs/LogAnalyzerStandardTrait.php` | Log-Parser für den Standard-Modus (PHP) |
| `libs/LogAnalyzerSystemTrait.php` | Log-Parser für den System-Modus (grep) |
| `libs/LogAnalyzerUltraTrait.php` | Erweiterte Analysefunktionen |

### WebHook-API

Alle Aktionen laufen als GET-Requests gegen den Hook. Wichtige Endpunkte:

| Endpunkt | Beschreibung |
|---|---|
| `?a=FilterAnwenden&ft[]=ERROR&sf[]=DataServer&tf=Text&zv=2026-01-01` | Filter anwenden |
| `?a=FilterReset` | Alle Filter zurücksetzen |
| `?a=Statistik` | Statistik-Dashboard laden |
| `?a=HeatmapDetail&dow=1&h=10` | Detail-Daten JSON: Dienstag 10 Uhr |
| `?a=TrendDetail&datum=2026-04-14` | Detail-Daten JSON: bestimmter Tag |
| `?a=StundenDetail&datum=heute&h=10` | Detail-Daten JSON: heutige Stunde 10 |
| `?a=WochentagDetail&dow=1` | Detail-Daten JSON: alle Dienstage |
| `?a=ObjektIdAufloesen&oid=12345` | ObjektID-Info als JSON |
| `?a=ExportPdf&scope=seite` | PDF der aktuellen Seite |
| `?a=ExportCsv&scope=alle` | CSV aller Treffer |

### Öffentliche PHP-Funktionen

| Funktion | Beschreibung |
|---|---|
| `LOGANALYZER_VerarbeiteHookAktion($id, $aktion, $wert)` | Aktion ausführen, HTML zurückgeben |
| `LOGANALYZER_ErstelleHtmlDirekt($id)` | Aktuellen Stand als HTML rendern |
| `LOGANALYZER_AktualisierenVisualisierung($id)` | HTMLBOX aktualisieren |
| `LOGANALYZER_ErstelleStatistik($id)` | Statistik-Dashboard als HTML |
| `LOGANALYZER_HeatmapDetail($id, $dow, $stunde)` | Heatmap-Detail als JSON |
| `LOGANALYZER_TrendDetail($id, $datum)` | Tages-Detail als JSON |
| `LOGANALYZER_StundenDetail($id, $datum, $stunde)` | Stunden-Detail als JSON |
| `LOGANALYZER_WochentagDetail($id, $dow)` | Wochentag-Detail als JSON |
| `LOGANALYZER_ObjektIdAufloesen($id, $oid)` | ObjektID-Info als JSON |
| `LOGANALYZER_ExportierePdf($id, $scope)` | PDF-HTML zurückgeben |
| `LOGANALYZER_ExportiereCsv($id, $scope)` | CSV-Inhalt zurückgeben |

### IPS-Attribut-Cache

IPS cached Attributwerte im selben PHP-Request. Bei sofortigen Aktionen (Logdatei-Wechsel, Schriftgröße, Auto-Refresh) wird der neue Wert daher direkt als Parameter durch die Render-Kette gereicht – am Cache vorbei.

---

## Bekannte Einschränkungen

- Der **System-Modus** (grep) funktioniert nur auf Linux-Systemen
- **PDF-Export** nutzt den Browser-Druckdialog – kein serverseitiges PDF
- **Export „Alle"** ist auf 10.000 Zeilen begrenzt (RAM/Timeout-Schutz)
- **Statistik-Dashboard** liest die gesamte Logdatei ein – bei sehr großen Dateien kann dies einige Sekunden dauern
- **Detail-Panels** zeigen maximal 300 Einträge (neueste zuerst)

---

## Changelog

### v1.2
- **Statistik-Dashboard** mit 4 interaktiven Diagrammen
- **Heute vs. Gestern** Vergleichs-Balkendiagramm (stündlich)
- **30-Tage Trend** mit logarithmischer Skalierung
- **Heatmap** Wochentag × Uhrzeit mit Farbintensität
- **Wochentag-Verteilung** Balkendiagramm
- **Detail-Overlay** für alle Charts: Klick öffnet gefilterte Einträge
- **Hover-Tooltips** auf allen Diagrammen
- **Filter-Badges** – aktive Filter als klickbare Badges mit Einzelentfernung
- **Zeilendetail** – Klick auf Tabellenzeile klappt Detailansicht auf
- **ObjektID-Name** direkt in der Spalte angezeigt

### v1.1
- Sender-Auswahl als Checkbox-Leiste (wie Typ-Filter)
- Schriftgröße persistent gespeichert
- Auto-Refresh persistent gespeichert
- Letzte/Erste Seite korrekt berechnet
- Verschiedene Bugfixes bei Filter und Navigation

### v1.0
- Initiale Veröffentlichung
- Log-Viewer mit Filter, Navigation, Export
- Standard- und System-Modus
- ObjektID-Hover-Tooltip

# LogAnalyzer – Architektur & Performance-Design

## Ziel der Architektur

Der LogAnalyzer ist für sehr große Logdateien optimiert.  
Die Architektur trennt strikt zwischen:

- UI / Statusverwaltung
- Cache-Schicht
- Datenzugriffsschicht
- Betriebssystem-spezifischer Verarbeitung

Ziel ist es:
- Vollscans zu vermeiden
- Parsing zu minimieren
- OS-Tools zu nutzen
- UI reaktiv zu halten

---

# Gesamtarchitektur

```
UI (Visualization)
        ↓
Status / RequestAction
        ↓
Cache-Schicht (Attribute)
        ↓
Moduswahl
        ↓
Standard-Modus        System-Modus
(PHP)               (OS optimiert)
                        ↓
                Windows        Linux/Unix
```

---

# Cache-Schicht

Persistente Symcon-Attribute:

## 1. VisualisierungsStatus
Speichert:
- Seite
- maxZeilen
- aktive Filter
- TrefferGesamt
- Ladezustände
- Theme
- Datei-Signatur

Zweck:
- UI Zustand erhalten
- unnötige Reloads vermeiden

---

## 2. SeitenCache

Speichert:
- aktuelle Tabellenzeilen
- hatWeitere
- TrefferGesamt
- Datei-Größe
- Datei-MTime
- Filter-Signaturen

Zweck:
- gleiche Seite nicht erneut laden

---

## 3. FilterMetadaten

Speichert:
- verfügbare Typen
- verfügbare Sender
- Gesamtzeilen
- Datei-Signatur
- Ladezeit

Zweck:
- Filteroptionen nur einmal berechnen

---

# Betriebsmodi

## Standard-Modus

### Eigenschaften
- komplette Datei wird gelesen
- alles in PHP
- exakt
- langsam bei großen Dateien

### Ablauf

```
fopen()
  ↓
fgets()
  ↓
parseLogZeile()
  ↓
Filterprüfung
  ↓
Array sammeln
  ↓
array_reverse()
  ↓
Pagination
```

### Performance
- O(n)
- kompletter RAM / CPU Scan
- geeignet für kleine Logs

---

# System-Modus

Der System-Modus verwendet unterschiedliche Strategien pro OS.

```
System
 ├─ Windows → optimiertes PHP
 └─ Linux/Unix → Shell Pipeline
```

---

# System-Modus – Windows

## Ohne Filter

Blockweises Rückwärtslesen:

```
Dateiende
   ↑
fseek()
   ↑
fread()
   ↑
Buffer
   ↑
Zeilen extrahieren
```

Nur benötigte Zeilen werden gelesen.

### Vorteil
- kein Vollscan
- konstante Laufzeit
- ideal für große Dateien

---

## Mit Filter

```
foreach fgets()
    ↓
extrahiereLogFelder()
    ↓
Filter prüfen
    ↓
Queue (begrenzte Größe)
```

Queue verhindert RAM-Wachstum.

---

## Treffer zählen (Windows)

```
foreach Zeile
    ↓
Filter prüfen
    ↓
Counter++
```

Immer Vollscan.

---

## Metadaten (Windows)

```
foreach Zeile
    ↓
Typ sammeln
Sender sammeln
Zeilen zählen
```

---

# System-Modus – Linux / Unix

Hier wird alles an Systemtools delegiert.

## Verwendete Tools

- tail
- head
- grep
- awk
- wc
- sift (optional)
- tac (optional)

---

## Ohne Filter

Pipeline:

```
tail -n TAKE
    ↓
head -n HEAD
    ↓
reverse
```

Nur Dateiende wird gelesen.

---

## Mit Filter

Pipeline:

```
grep / sift
    ↓
grep Objekt-ID
    ↓
grep Sender
    ↓
grep Text
    ↓
awk Feldfilter
    ↓
tail
    ↓
head
    ↓
reverse
```

Filter laufen im Kernel / OS.

---

## Treffer zählen (Linux)

Ohne Filter:
```
awk zählt Zeilen
```

Mit Filter:
```
Pipeline → wc -l
```

---

## Filtermetadaten (Linux)

```
awk
 ├─ Typen sammeln
 ├─ Sender sammeln
 └─ Gesamt zählen
```

Nur ein Durchlauf.

---

# Reverse-Logik (tac)

Der Code prüft:

```
command -v tac
```

## Wenn vorhanden

```
reverse = tac
```

## Wenn nicht vorhanden

AWK Fallback:

```
lines[NR]=$0
END reverse print
```

---

# Plattformverhalten

| Plattform | tac |
|-----------|-----|
Linux | meist vorhanden |
macOS | meist nicht vorhanden |
Docker | abhängig vom Image |
BusyBox | meist nicht vorhanden |

Es wird **nicht OS-basiert**, sondern **tool-basiert** entschieden.

---

# Performance-Vergleich

| Modus | Lesen | Filter | Zählen | Performance |
|------|------|-------|--------|-------------|
Standard | PHP Vollscan | PHP | PHP | langsam |
System Windows | Rückwärts | PHP | PHP | mittel |
System Linux | tail | grep/awk | wc | sehr schnell |

---

# Wann was geladen wird

## Logdatei wechseln
- Status reset
- Filtermetadaten neu
- SeitenCache leer
- Tabelle neu laden

## Filter ändern
- Status ändern
- SeitenCache leer
- Tabelle neu laden
- Filtermetadaten bleiben (System-Modus)

## Seite wechseln
- nur Tabellenladung

## maxZeilen ändern
- nur Tabellenladung

## Aktualisieren
- kompletter Reset
- Filtermetadaten neu
- Tabelle neu

---

# Wichtigste Performance-Prinzipien

1. Nur Dateiende lesen wenn möglich
2. OS Tools statt PHP verwenden
3. Parsing minimieren
4. Cache konsequent nutzen
5. Queue statt vollständiger Trefferliste
6. getrennte Berechnung von:
   - Tabelle
   - Trefferanzahl
   - Filtermetadaten
7. tac nur wenn verfügbar
8. Linux nutzt Kernel-Pipeline
9. Windows nutzt optimiertes fread
10. Standard nur für kleine Dateien

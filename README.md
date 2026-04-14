<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>LogAnalyzer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="/icons.js"></script>
    <style>
        /* Zentrale Designvariablen */
        :root {
            --accent: #D37800;
            --accent-soft: rgba(77, 163, 255, 0.18);
            --radius: 12px;
            --radius-sm: 8px;
        }

        /* Erzwingt Dark-Theme für native Eingabeelemente */
        html.theme-dark,
        body.theme-dark,
        body.theme-dark select,
        body.theme-dark input {
            color-scheme: dark;
        }

        /* Erzwingt Light-Theme für native Eingabeelemente */
        html.theme-light,
        body.theme-light,
        body.theme-light select,
        body.theme-light input {
            color-scheme: light;
        }

        /* Farbwerte für Dark-Theme */
        body.theme-dark {
            --bg: rgba(20, 20, 20, 0.35);
            --bg-soft: rgba(255, 255, 255, 0.05);
            --bg-input: rgba(0, 0, 0, 0.25);
            --bg-dropdown: rgba(30, 30, 30, 0.96);
            --border: rgba(255, 255, 255, 0.12);
            --border-soft: rgba(255, 255, 255, 0.10);
            --text: #f5f5f5;
            --muted: #b8b8b8;
            --row-even: rgba(255, 255, 255, 0.03);
            --row-border: rgba(255, 255, 255, 0.08);
            --table-line: rgba(255, 255, 255, 0.10);
            --table-line-strong: rgba(255, 255, 255, 0.14);
            --message-text: #ffd2d2;
            --activity-track: rgba(255, 255, 255, 0.08);
            --activity-bar: rgba(77, 163, 255, 0.85);
        }

        /* Farbwerte für Light-Theme */
        body.theme-light {
            --bg: rgba(255, 255, 255, 0.82);
            --bg-soft: rgba(0, 0, 0, 0.03);
            --bg-input: rgba(255, 255, 255, 0.92);
            --bg-dropdown: rgba(255, 255, 255, 0.98);
            --border: rgba(0, 0, 0, 0.12);
            --border-soft: rgba(0, 0, 0, 0.10);
            --text: #1f2937;
            --muted: #6b7280;
            --row-even: rgba(0, 0, 0, 0.025);
            --row-border: rgba(0, 0, 0, 0.08);
            --table-line: rgba(0, 0, 0, 0.10);
            --table-line-strong: rgba(0, 0, 0, 0.14);
            --message-text: #9f1239;
            --activity-track: rgba(0, 0, 0, 0.08);
            --activity-bar: rgba(37, 99, 235, 0.85);
        }

        /* Einheitliches Box-Modell */
        * {
            box-sizing: border-box;
        }

        /* Grundlayout der Seite */
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background: transparent;
            transition: color 0.18s ease, background 0.18s ease;
        }

        /* Äußerer Inhaltsbereich */
        .wrapper {
            display: grid;
            gap: 12px;
            padding: 12px;
        }

        /* Kartencontainer für die Hauptansicht */
        .card {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: visible;
            backdrop-filter: blur(8px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        /* Kopfbereich mit Filtern und Aktionen */
        .toolbar {
            display: grid;
            gap: 10px;
            padding: 12px;
            background: var(--bg-soft);
            border-bottom: 1px solid var(--border);
            overflow: visible;
        }

        /* Responsives Raster für Filterfelder */
        .toolbar-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            overflow: visible;
        }

        /* Beschriftungen für Eingabefelder */
        label {
            display: grid;
            gap: 6px;
            font-size: 12px;
            color: var(--muted);
        }

        /* Einheitliche Darstellung für Eingabefelder */
        input, select {
            width: 100%;
            min-height: 34px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--bg-input);
            color: var(--text);
            padding: 8px 10px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        /* Darstellung nativer Auswahloptionen */
        select,
        option,
        optgroup {
            background: var(--bg-input);
            color: var(--text);
        }

        /* Fokuszustand für Eingabeelemente */
        input:focus, select:focus, button:focus {
            outline: none;
            border-color: rgba(77, 163, 255, 0.5);
            box-shadow: 0 0 0 3px rgba(77, 163, 255, 0.15);
        }

        /* Zeile für Aktionsbuttons und Inline-Auswahlfelder */
        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        /* Inline-Steuerelemente in der Button-Zeile */
        .button-row .inline-control {
            min-width: 130px;
            max-width: 170px;
        }

        /* Beschriftung vor Einstellungen */
		.button-row .inline-control {
			min-width: 130px;
			max-width: 170px;
			display: inline-flex;
			align-items: center;
			gap: 6px;
			white-space: nowrap;
		}

		.button-row .inline-control span {
			display: inline-block;
			color: var(--muted);
			font-size: 12px;
		}

		.button-row .inline-control select {
			width: auto;
			flex: 1 1 auto;
		}

        /* Kompaktere Selects in der Button-Zeile */
        .button-row .inline-control select {
            min-height: 30px;
            padding: 5px 8px;
            font-size: 12px;
        }

        /* Grundstil für Schaltflächen */
        button {
            width: auto;
            min-width: 0;
            min-height: 30px;
            padding: 6px 10px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--accent-soft);
            color: var(--text);
            cursor: pointer;
            white-space: nowrap;
            flex: 0 0 auto;
            transition: transform 0.12s ease, opacity 0.12s ease, background 0.15s ease;
        }

        /* Hover-Effekt für Buttons */
        button:hover {
            transform: translateY(-1px);
        }

        /* Alternative Button-Darstellung */
        button.secondary {
            background: var(--bg-soft);
        }

        /* Deaktivierte Buttons */
        button:disabled {
            opacity: 0.45;
            cursor: default;
            transform: none;
        }

        /* Metadatenzeile unterhalb der Toolbar */
        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding: 0 12px 12px;
            margin-top: 6px;
            font-size: 12px;
            color: var(--muted);
        }

        /* Bereich für Ladeindikator */
        .status-activity {
            padding: 6px 12px 6px;
            display: none;
        }

        /* Sichtbarer Ladeindikator */
        .status-activity.active {
            display: block;
        }

        /* Hintergrundspur des Ladebalkens */
        .status-activity-track {
            position: relative;
            height: 6px;
            border-radius: 999px;
            overflow: hidden;
            background: var(--activity-track);
            border: 1px solid var(--border-soft);
        }

        /* Bewegter Balken für laufende Aktionen */
        .status-activity-bar {
            position: absolute;
            top: 0;
            left: -30%;
            width: 30%;
            height: 100%;
            border-radius: 999px;
            background: var(--activity-bar);
            animation: status-running 1.4s ease-in-out infinite alternate;
        }

        /* Text unterhalb des Ladebalkens */
        .status-activity-text {
            margin-top: 8px;
            margin-bottom: 8px;
            font-size: 11px;
            color: var(--muted);
            text-align: center;
        }

        /* Animation für den Ladebalken */
        @keyframes status-running {
            from {
                left: -30%;
            }
            to {
                left: 100%;
            }
        }

        /* Bereich für Fehlermeldungen und Hinweise */
        .message {
            padding: 12px;
            color: var(--message-text);
            border-top: 1px solid var(--border);
            display: none;
        }

        /* Scrollbarer Tabellenbereich */
        .table-wrap {
            overflow: auto;
            max-height: 65vh;
        }

        /* Grundlayout der Tabelle */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        /* Tabellenkopf mit Sticky-Verhalten */
        thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            text-align: left;
            padding: 10px;
            background: var(--accent);
            color: #fff;
            border-bottom: 1px solid var(--table-line-strong);
            border-right: 1px solid var(--table-line);
			padding: 6px 8px;
        }

        /* Letzte Tabellenkopfzelle ohne rechte Linie */
        thead th:last-child {
            border-right: none;
        }

        /* Tabellenzellen im Datenbereich */
        tbody td {
            padding: 9px 10px;
            border-bottom: 1px solid var(--table-line);
            border-right: 1px solid var(--table-line);
            vertical-align: top;
        }

        /* Letzte Spalte ohne rechte Linie */
        tbody td:last-child {
            border-right: none;
        }

        /* Wechselnde Zeilenhintergründe */
        tbody tr:nth-child(even) {
            background: var(--row-even);
        }

        /* Feste Breite für Zeitspalte */
        .col-zeit {
            width: 170px;
            white-space: nowrap;
        }

        /* Feste Breite für Objekt-ID-Spalte */
        .col-objektid {
            width: 90px;
            white-space: nowrap;
        }

        /* Feste Breite für Typ-Spalte */
        .col-typ {
            width: 110px;
            white-space: nowrap;
        }

        /* Feste Breite für Sender-Spalte */
        .col-sender {
            width: 220px;
        }

        /* Anzeige für leere Zustände */
        .empty {
            padding: 16px;
            color: var(--muted);
        }

        /* Wrapper für Mehrfachauswahl */
        .multi-select {
            position: relative;
        }

        /* Button für Mehrfachauswahl */
        .multi-select-button {
            width: 100%;
            min-height: 34px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--bg-input);
            color: var(--text);
            padding: 8px 10px;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Pfeilsymbol am Mehrfachauswahl-Button */
        .multi-select-button::after {
            content: "▾";
            opacity: 0.8;
            margin-left: 10px;
            flex: 0 0 auto;
        }

        /* Dropdown für Mehrfachauswahl */
        .multi-select-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            z-index: 50;
            display: none;
            background: var(--bg-dropdown);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 8px;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.18);
        }

        /* Sichtbarer Zustand des Mehrfachauswahl-Dropdowns */
        .multi-select.open .multi-select-dropdown {
            display: block;
        }

        /* Optionsliste im Dropdown */
        .multi-select-options {
            display: grid;
            gap: 6px;
            max-height: 220px;
            overflow-y: auto;
        }

        /* Einzelne Option im Dropdown */
        .multi-select-option {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
            font-size: 13px;
        }

        /* Checkbox-Stil im Mehrfachauswahlfeld */
        .multi-select-option input[type="checkbox"] {
            width: auto;
            min-height: 0;
            margin: 0;
            padding: 0;
        }

        /* Kompakter Modus für Toolbar */
        body.compact .toolbar {
            padding: 9px;
            gap: 8px;
        }

        /* Kompakter Modus für Toolbar-Raster */
        body.compact .toolbar-grid {
            gap: 8px;
        }

        /* Kompakter Modus für Labels */
        body.compact label {
            gap: 4px;
            font-size: 11px;
        }

        /* Kompakter Modus für Eingabefelder */
        body.compact input,
        body.compact select,
        body.compact .multi-select-button {
            min-height: 30px;
            padding: 6px 8px;
            font-size: 12px;
        }

        /* Kompakter Modus für Buttons */
        body.compact button {
            min-height: 28px;
            padding: 4px 8px;
            font-size: 12px;
        }

        /* Kompakter Modus für Metadaten */
        body.compact .meta {
            font-size: 11px;
            gap: 10px;
            padding: 0 10px 10px;
        }

        /* Kompakter Modus für Tabellen */
        body.compact table {
            font-size: 12px;
        }

        /* Kompakter Modus für Tabellenkopf */
        body.compact thead th {
            padding: 8px;
        }

        /* Kompakter Modus für Tabellenzellen */
        body.compact tbody td {
            padding: 6px 8px;
        }

        /* Kompakter Modus für Mehrfachauswahl-Optionen */
        body.compact .multi-select-option {
            font-size: 12px;
        }
    </style>
</head>
<body class="theme-dark">
<br><br><br>

<!-- Gesamter sichtbarer Inhaltsbereich -->
<div class="wrapper">
    <div class="card">

        <!-- Filter- und Steuerbereich -->
        <div class="toolbar">
            <div class="toolbar-grid">

                <!-- Auswahl der Logdatei -->
                <label>
                    Logdatei
                    <select id="logDateiSelect"></select>
                </label>

                <!-- Auswahl der Seitengröße -->
                <label>
                    Zeilen pro Seite
                    <select id="maxZeilenSelect">
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                        <option value="1000">1000</option>
                        <option value="2000">2000</option>
                        <option value="3000">3000</option>
                    </select>
                </label>

                <!-- Filter nach Objekt-ID -->
                <label>
                    ObjektID
                    <input id="objektIdFilter" type="text" placeholder="z. B. 12345, 67890">
                </label>

                <!-- Mehrfachauswahl für Meldungstypen -->
                <label>
                    Meldungstyp
                    <div class="multi-select" id="filterTypContainer">
                        <button type="button" id="filterTypButton" class="multi-select-button">Alle</button>
                        <div class="multi-select-dropdown" id="filterTypDropdown">
                            <div class="multi-select-options" id="filterTypOptions"></div>
                        </div>
                    </div>
                </label>

                <!-- Mehrfachauswahl für Sender -->
                <label>
                    Sender
                    <div class="multi-select" id="senderContainer">
                        <button type="button" id="senderButton" class="multi-select-button">Alle</button>
                        <div class="multi-select-dropdown" id="senderDropdown">
                            <div class="multi-select-options" id="senderOptions"></div>
                        </div>
                    </div>
                </label>

                <!-- Freitextfilter für Meldungsinhalt -->
                <label>
                    Meldung enthält
                    <input id="textFilter" type="text" placeholder="Freitext">
                </label>
            </div>

            <!-- Aktions- und Darstellungssteuerung -->
            <div class="button-row">

                <!-- Theme-Auswahl -->
                <label class="inline-control">
					<span>Theme:</span>
                    <select id="themeSelect">
                        <option value="dark">Dark</option>
                        <option value="light">Light</option>
                    </select>
                </label>

                <!-- Umschaltung Standard/Kompakt -->

                <label class="inline-control">
					<span>UI:</span>
                    <select id="kompaktSelect">
                        <option value="0">Standard</option>
                        <option value="1">Kompakt</option>
                    </select>
                </label>
				
				<!-- Auswahl des Betriebsmodus -->
				<label class="inline-control">
					<span>Mode:</span>
					<select id="betriebsmodusSelect">
						<option value="standard">Standard</option>
						<option value="system">System</option>
						<option value="ultra">Ultra</option>
					</select>
				</label>
				
				<!-- Filterungen anwenden und Aktualisieren sowie Blättern -->
                <button id="btnAnwenden">Filter anwenden</button>
                <button id="btnReload" class="secondary">Aktualisieren</button>
                <button id="btnZurueck" class="secondary">Neuere</button>
                <button id="btnVor" class="secondary">Ältere</button>

            </div>
        </div>

            <!-- Metadaten zur aktuellen Anzeige -->
			<div class="meta">
				<div id="metaDatei">Datei: -</div>
				<div id="metaGroesse">Größe: -</div>
				<div id="metaSeite">Seite: 1</div>
				<div id="metaTreffer">Treffer: -</div>
				<div id="metaLadezeit">Ladezeit Tabelle: -</div>
				<div id="metaFilterLadezeit">Ladezeit Filter: -</div>
				<div id="metaStand">Stand: -</div>
			</div>

        <!-- Ladebalken für Tabellen- und Statusvorgänge -->
        <div id="statusActivity" class="status-activity">
            <div class="status-activity-track">
                <div class="status-activity-bar"></div>
            </div>
            <div id="statusActivityText" class="status-activity-text">Metadaten werden geladen …</div>
        </div>

        <!-- Bereich für Fehlermeldungen -->
        <div id="message" class="message"></div>

        <!-- Tabellenanzeige der Logeinträge -->
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="col-zeit">Zeit</th>
                    <th class="col-objektid">ObjektID</th>
                    <th class="col-typ">Meldungstyp</th>
                    <th class="col-sender">Sender</th>
                    <th>Meldung</th>
                </tr>
                </thead>
                <tbody id="tbody">
                <tr><td colspan="5" class="empty">Lade Daten …</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // Initiale Daten aus dem PHP-Modul
    const INITIAL_DATA = %%INITIAL_DATA%%;
    let currentData = INITIAL_DATA;
    let countRequestRunning = false;
    let lastCountSignature = '';
    let filterMetaRequestRunning = false;
    let lastFilterMetaSignature = '';

    // Escaped HTML-Sonderzeichen für sichere Ausgabe
    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    // Formatiert Zahlen für die Anzeige
    function formatInteger(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) {
            return String(value ?? '');
        }
        return Math.round(num).toLocaleString('de-DE');
    }

    // Entfernt leere und doppelte Werte
    function uniqueValues(values) {
        return Array.from(new Set((Array.isArray(values) ? values : []).map(v => String(v).trim()).filter(Boolean)));
    }

    // Liest die gesetzten Werte eines Multi-Selects aus
    function getSelectedValues(optionsId) {
        return Array.from(document.querySelectorAll('#' + optionsId + ' input[type="checkbox"]:checked'))
            .map((input) => input.value);
    }

    // Befüllt ein Multi-Select mit Optionen und Auswahl
    function populateMultiSelect(optionsId, values, selectedValues) {
        const container = document.getElementById(optionsId);
        const allValues = uniqueValues([...(Array.isArray(values) ? values : []), ...(Array.isArray(selectedValues) ? selectedValues : [])]);

        container.innerHTML = '';

        allValues.forEach((value) => {
            const label = document.createElement('label');
            label.className = 'multi-select-option';

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = value;
            input.checked = Array.isArray(selectedValues) && selectedValues.includes(value);

            input.addEventListener('change', () => {
                updateMultiSelectButtonText(optionsId);
            });

            label.appendChild(input);
            label.appendChild(document.createTextNode(' ' + value));
            container.appendChild(label);
        });
    }

    // Aktualisiert den Button-Text eines Multi-Selects
    function updateMultiSelectButtonText(optionsId) {
        const mapping = {
            filterTypOptions: 'filterTypButton',
            senderOptions: 'senderButton'
        };

        const button = document.getElementById(mapping[optionsId]);
        const selected = getSelectedValues(optionsId);

        if (selected.length === 0) {
            button.textContent = 'Alle';
            return;
        }

        if (selected.length <= 2) {
            button.textContent = selected.join(', ');
            return;
        }

        button.textContent = selected.length + ' ausgewählt';
    }

    // Sammelt alle aktuellen Filterwerte aus der Oberfläche
    function collectFilterData() {
        return {
            filterTypen: getSelectedValues('filterTypOptions'),
            objektIdFilter: document.getElementById('objektIdFilter').value.trim(),
            senderFilter: getSelectedValues('senderOptions'),
            textFilter: document.getElementById('textFilter').value.trim()
        };
    }

    // Erzwingt ein visuelles Refresh nativer Selects
    function refreshNativeSelects() {
        document.querySelectorAll('select').forEach((el) => {
            const originalDisplay = el.style.display;
            el.style.display = 'none';
            void el.offsetHeight;
            el.style.display = originalDisplay || '';
        });
    }

    // Wendet Theme- und Kompakt-Einstellungen auf die Ansicht an
    function applyViewSettings(status) {
        const theme = (status.theme || 'dark') === 'light' ? 'light' : 'dark';
        const kompakt = !!status.kompakt;

        document.documentElement.classList.remove('theme-dark', 'theme-light');
        document.documentElement.classList.add('theme-' + theme);

        document.body.classList.remove('theme-dark', 'theme-light');
        document.body.classList.add('theme-' + theme);

        document.body.classList.toggle('compact', kompakt);

        document.getElementById('themeSelect').value = theme;
        document.getElementById('kompaktSelect').value = kompakt ? '1' : '0';

        requestAnimationFrame(() => {
            refreshNativeSelects();
        });
    }

    // Nimmt neue Daten entgegen und startet das Rendering
    function handleMessage(data) {
        try {
            let parsed = data;
            if (typeof data === 'string') {
                parsed = JSON.parse(data);
            }
            render(parsed);
        } catch (e) {
            console.error('Fehler in handleMessage', e, data);
            const msg = document.getElementById('message');
            if (msg) {
                msg.style.display = 'block';
                msg.textContent = 'Fehler in handleMessage: ' + e.message;
            }
        }
    }

	// Um per Doppelklick IDs in den Filter zu nehmen
    function uebernehmeObjektIdInFilter(objektId) {
        const input = document.getElementById('objektIdFilter');
        if (!input) {
            return;
        }

        const neuerWert = String(objektId || '').trim();
        if (neuerWert === '') {
            return;
        }

        const vorhandene = input.value
            .split(/[\s,;]+/)
            .map(v => v.trim())
            .filter(Boolean);

        if (!vorhandene.includes(neuerWert)) {
            vorhandene.push(neuerWert);
        }

        input.value = vorhandene.join(', ');
    }


    // Rendert die vollständige Oberfläche neu
    function render(data) {
        currentData = data || {};

        const status = currentData.status || {
            seite: 0,
            maxZeilen: 50,
            theme: 'dark',
            kompakt: false,
            filterTypen: [],
            objektIdFilter: '',
            senderFilter: [],
            textFilter: '',
            tabellenLadungLaeuft: false,
            tabellenLadungText: ''
        };

        applyViewSettings(status);

        const logDateiSelect = document.getElementById('logDateiSelect');
        const verfuegbareLogdateien = Array.isArray(currentData.verfuegbareLogdateien) ? currentData.verfuegbareLogdateien : [];
        const aktuelleLogDatei = currentData.aktuelleLogDatei || currentData.logDatei || '';

        logDateiSelect.innerHTML = '';
        verfuegbareLogdateien.forEach((eintrag) => {
            const option = document.createElement('option');
            option.value = eintrag.pfad || '';
            option.textContent = eintrag.anzeige || eintrag.dateiname || eintrag.pfad || '';
            option.selected = option.value === aktuelleLogDatei;
            logDateiSelect.appendChild(option);
        });

        const maxZeilenSelect = document.getElementById('maxZeilenSelect');
        const aktuelleMaxZeilen = Number(currentData.maxZeilen || status.maxZeilen || 50);
        maxZeilenSelect.value = String(aktuelleMaxZeilen);

        const verfuegbareTypen = uniqueValues(currentData.verfuegbareFilterTypen || []);
        const verfuegbareSender = uniqueValues(currentData.verfuegbareSender || []);
        const aktiveTypen = uniqueValues(status.filterTypen || []);
        const aktiveSender = uniqueValues(status.senderFilter || []);

        populateMultiSelect('filterTypOptions', verfuegbareTypen, aktiveTypen);
        populateMultiSelect('senderOptions', verfuegbareSender, aktiveSender);
        updateMultiSelectButtonText('filterTypOptions');
        updateMultiSelectButtonText('senderOptions');

        document.getElementById('textFilter').value = status.textFilter ?? '';
        document.getElementById('objektIdFilter').value = status.objektIdFilter ?? '';

        document.getElementById('metaDatei').textContent = 'Datei: ' + (currentData.logDatei || '-');
        document.getElementById('metaGroesse').textContent = 'Größe: ' + (currentData.dateiGroesse || '-');
        document.getElementById('metaSeite').textContent = 'Seite: ' + ((Number(status.seite || 0)) + 1);
        document.getElementById('metaStand').textContent = 'Stand: ' + (currentData.zeitstempel || '-');
        document.getElementById('metaLadezeit').textContent = 'Ladezeit Tabelle: ' + ((currentData.ladezeitMs ?? 0)) + ' ms';
        document.getElementById('metaFilterLadezeit').textContent = 'Ladezeit Filter: ' + ((currentData.filterLadezeitMs ?? 0) > 0 ? (currentData.filterLadezeitMs + ' ms') : '-');
		
		document.getElementById('betriebsmodusSelect').value = currentData.betriebsmodus || 'standard';

        const metaTreffer = document.getElementById('metaTreffer');
        if (currentData.zaehlungLaeuft) {
            metaTreffer.textContent = 'Treffer: wird ermittelt …';
        } else if (typeof currentData.trefferGesamt === 'number' && currentData.trefferGesamt >= 0) {
            metaTreffer.textContent =
                'Bereich: ' + formatInteger(currentData.bereichVon || 0) + '–' + formatInteger(currentData.bereichBis || 0) +
                ' von ' + formatInteger(currentData.trefferGesamt);
        } else {
            metaTreffer.textContent = 'Treffer: -';
        }

        const statusActivity = document.getElementById('statusActivity');
        const statusActivityText = document.getElementById('statusActivityText');

        const laedtTabelle = Boolean(currentData.tabellenLadungLaeuft || status.tabellenLadungLaeuft);
        const tabellenText = String(currentData.tabellenLadungText || status.tabellenLadungText || '').trim();
        const laedtTreffer = Boolean(currentData.zaehlungLaeuft);
        const laedtFilter = Boolean(currentData.filterMetadatenLaeuft);

        if (laedtTabelle || laedtTreffer || laedtFilter) {
            statusActivity.classList.add('active');

            if (laedtTabelle && laedtTreffer && laedtFilter) {
                statusActivityText.textContent = tabellenText || 'Tabelle, Treffer und Filteroptionen werden geladen …';
            } else if (laedtTabelle && laedtTreffer) {
                statusActivityText.textContent = tabellenText || 'Tabelle und Treffer werden geladen …';
            } else if (laedtTabelle && laedtFilter) {
                statusActivityText.textContent = tabellenText || 'Tabelle und Filteroptionen werden geladen …';
            } else if (laedtTabelle) {
                statusActivityText.textContent = tabellenText || 'Tabelle wird geladen …';
            } else if (laedtTreffer && laedtFilter) {
                statusActivityText.textContent = 'Treffer und Filteroptionen werden ermittelt …';
            } else if (laedtTreffer) {
                statusActivityText.textContent = 'Treffer werden ermittelt …';
            } else {
                statusActivityText.textContent = 'Filteroptionen werden geladen …';
            }
        } else {
            statusActivity.classList.remove('active');
            statusActivityText.textContent = '';
        }

        const msg = document.getElementById('message');
        if (currentData.ok === false && currentData.fehlermeldung) {
            msg.style.display = 'block';
            msg.textContent = currentData.fehlermeldung;
        } else {
            msg.style.display = 'none';
            msg.textContent = '';
        }

        const tbody = document.getElementById('tbody');
        const rows = Array.isArray(currentData.zeilen) ? currentData.zeilen : [];

        console.debug('[LogAnalyzer] render-table-state', {
            laedtTabelle,
            laedtTreffer,
            laedtFilter,
            rowsLength: rows.length,
            tabellenText,
            trefferGesamt: currentData.trefferGesamt
        });

        // Tabellen-Ladezustand hat Vorrang vor Status- und Leerdarstellung
        if (laedtTabelle && rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="empty">Lade Daten …</td></tr>';
            triggerCountIfNeeded(currentData);
            triggerFilterMetaIfNeeded(currentData);
            return;
        }

        // Reiner Statuslauf ohne Tabelleninhalt
        if (!laedtTabelle && rows.length === 0 && (laedtTreffer || laedtFilter)) {
            tbody.innerHTML = '<tr><td colspan="5" class="empty">Status wird aktualisiert …</td></tr>';
            triggerCountIfNeeded(currentData);
            triggerFilterMetaIfNeeded(currentData);
            return;
        }

        tbody.innerHTML = '';

        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="empty">Keine passenden Logzeilen gefunden.</td></tr>';
        } else {
			
			// Um per Doppelklick IDs in den Filter zu nehmen
			rows.forEach((row) => {
				const tr = document.createElement('tr');
				tr.innerHTML =
					'<td class="col-zeit">' + escapeHtml(row.zeitstempel || '') + '</td>' +
					'<td class="col-objektid">' + escapeHtml(row.objektId || '') + '</td>' +
					'<td class="col-typ">' + escapeHtml(row.typ || '') + '</td>' +
					'<td class="col-sender">' + escapeHtml(row.sender || '') + '</td>' +
					'<td>' + escapeHtml(row.meldung || '') + '</td>';

				const objektIdCell = tr.querySelector('.col-objektid');
				if (objektIdCell) {
					objektIdCell.style.cursor = 'pointer';
					objektIdCell.title = 'Doppelklick übernimmt die ObjektID in den Filter';

					objektIdCell.addEventListener('dblclick', () => {
						uebernehmeObjektIdInFilter(row.objektId || '');
					});
				}

				tbody.appendChild(tr);
			});
        }

        const aktuelleSeite = Number(status.seite || 0);
        const trefferGesamt = Number(currentData.trefferGesamt ?? -1);
        const maxZeilen = Number(currentData.maxZeilen || 0);

        document.getElementById('btnZurueck').disabled = aktuelleSeite <= 0;

        if (trefferGesamt >= 0 && maxZeilen > 0) {
            const maxSeite = Math.max(0, Math.ceil(trefferGesamt / maxZeilen) - 1);
            document.getElementById('btnVor').disabled = aktuelleSeite >= maxSeite;
        } else {
            document.getElementById('btnVor').disabled = !currentData.hatWeitere;
        }

        triggerCountIfNeeded(currentData);
        triggerFilterMetaIfNeeded(currentData);
    }
	
	// Startet die Trefferzählung bei Bedarf
	function triggerCountIfNeeded(data) {
		const status = data?.status || {};
		const signature = JSON.stringify({
			filterTypen: Array.isArray(status.filterTypen) ? status.filterTypen : [],
			objektIdFilter: status.objektIdFilter ?? '',
			senderFilter: Array.isArray(status.senderFilter) ? status.senderFilter : [],
			textFilter: status.textFilter ?? '',
			logDatei: data?.logDatei ?? ''
		});

		const blockedByError = data?.ok === false;

		const needsCount =
			!blockedByError &&
			!data?.zaehlungLaeuft &&
			(typeof data?.trefferGesamt !== 'number' || data.trefferGesamt < 0);

		console.debug('[LogAnalyzer] triggerCountIfNeeded', {
			logDatei: data?.logDatei ?? '',
			blockedByError,
			needsCount,
			zaehlungLaeuft: !!data?.zaehlungLaeuft,
			trefferGesamt: data?.trefferGesamt,
			signature,
			countRequestRunning,
			lastCountSignature
		});

		if (!needsCount) {
			lastCountSignature = signature;
			countRequestRunning = false;
			return;
		}

		if (countRequestRunning && lastCountSignature === signature) {
			console.debug('[LogAnalyzer] ZaehleTreffer wird nicht erneut gestartet (bereits laufend/signaturgleich)', {
				signature
			});
			return;
		}

		countRequestRunning = true;
		lastCountSignature = signature;

		console.debug('[LogAnalyzer] Starte ZaehleTreffer', {
			signature
		});

		window.setTimeout(() => {
			try {
				requestAction('ZaehleTreffer', '');
			} catch (e) {
				console.error('[LogAnalyzer] Fehler beim Starten der Trefferzählung', e);
				countRequestRunning = false;
			}
		}, 0);
	}

	// Startet das Laden der Filtermetadaten bei Bedarf
	function triggerFilterMetaIfNeeded(data) {
		const signature = JSON.stringify({
			logDatei: data?.logDatei ?? '',
			dateiGroesse: data?.dateiGroesse ?? ''
		});

		const blockedByError = data?.ok === false;
		const needsLoad = !blockedByError && !data?.filterMetadatenGeladen && !data?.filterMetadatenLaeuft;

		console.debug('[LogAnalyzer] triggerFilterMetaIfNeeded', {
			logDatei: data?.logDatei ?? '',
			blockedByError,
			needsLoad,
			filterMetadatenGeladen: !!data?.filterMetadatenGeladen,
			filterMetadatenLaeuft: !!data?.filterMetadatenLaeuft,
			signature,
			filterMetaRequestRunning,
			lastFilterMetaSignature
		});

		if (!needsLoad) {
			lastFilterMetaSignature = signature;
			filterMetaRequestRunning = false;
			return;
		}

		if (filterMetaRequestRunning && lastFilterMetaSignature === signature) {
			console.debug('[LogAnalyzer] LadeFilterOptionen wird nicht erneut gestartet (bereits laufend/signaturgleich)', {
				signature
			});
			return;
		}

		filterMetaRequestRunning = true;
		lastFilterMetaSignature = signature;

		console.debug('[LogAnalyzer] Starte LadeFilterOptionen', {
			signature
		});

		window.setTimeout(() => {
			try {
				requestAction('LadeFilterOptionen', '');
			} catch (e) {
				console.error('[LogAnalyzer] Fehler beim Laden der Filteroptionen', e);
				filterMetaRequestRunning = false;
			}
		}, 0);
	}

    // Öffnet oder schließt die Typ-Auswahl
    document.getElementById('filterTypButton').addEventListener('click', (event) => {
        event.stopPropagation();
        document.getElementById('filterTypContainer').classList.toggle('open');
    });

    // Öffnet oder schließt die Sender-Auswahl
    document.getElementById('senderButton').addEventListener('click', (event) => {
        event.stopPropagation();
        document.getElementById('senderContainer').classList.toggle('open');
    });

    // Schließt offene Dropdowns bei Klick außerhalb
    document.addEventListener('click', (event) => {
        const typContainer = document.getElementById('filterTypContainer');
        const senderContainer = document.getElementById('senderContainer');

        if (!typContainer.contains(event.target)) {
            typContainer.classList.remove('open');
        }

        if (!senderContainer.contains(event.target)) {
            senderContainer.classList.remove('open');
        }
    });

    // Wendet die aktuellen Filter an
    document.getElementById('btnAnwenden').addEventListener('click', () => {
        requestAction('FilterAnwenden', JSON.stringify(collectFilterData()));
    });

    // Lädt die aktuelle Ansicht neu
    document.getElementById('btnReload').addEventListener('click', () => {
        requestAction('Aktualisieren', '');
    });

    // Wechselt zur älteren Seite
    document.getElementById('btnVor').addEventListener('click', () => {
        requestAction('SeiteVor', '');
    });

    // Wechselt zur neueren Seite
    document.getElementById('btnZurueck').addEventListener('click', () => {
        requestAction('SeiteZurueck', '');
    });

    // Reagiert auf eine neue Logdatei-Auswahl
    document.getElementById('logDateiSelect').addEventListener('change', (event) => {
        requestAction('LogDateiAuswaehlen', event.target.value);
    });

    // Reagiert auf eine neue Seitengröße
    document.getElementById('maxZeilenSelect').addEventListener('change', (event) => {
        requestAction('SetzeMaxZeilen', Number(event.target.value));
    });

    // Reagiert auf einen Theme-Wechsel
    document.getElementById('themeSelect').addEventListener('change', (event) => {
        requestAction('SetzeTheme', event.target.value);
    });

    // Reagiert auf Umschaltung Standard/Kompakt
    document.getElementById('kompaktSelect').addEventListener('change', (event) => {
        requestAction('SetzeKompakt', event.target.value === '1');
    });

	// Reagiert auf einen Wechsel des Betriebsmodus
	document.getElementById('betriebsmodusSelect').addEventListener('change', (event) => {
		requestAction('SetzeBetriebsmodus', event.target.value);
	});

    // Initiales Rendering beim Laden der Seite
    render(INITIAL_DATA);
</script>
</body>
</html>
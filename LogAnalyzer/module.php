<?php
/**
 * NEUSTART des Moduls: MC_ReloadModule(59139, "LogAnalyzerIPSView");
 * sudo /etc/init.d/symcon start
 * sudo /etc/init.d/symcon stop
 * sudo /etc/init.d/symcon restart
 *
 * ToDo:
 * - Ultra Version einbauen
 * - CSV Export neu implementieren
 * - DarkLight Abruf vom Tile ausgehend
 * - Ladezeit Filter 0ms, wenn nicht notwendig zum Laden, bei Modi System
*/

declare(strict_types=1);
require_once __DIR__ . '/libs/LogAnalyzerStandardTrait.php';
require_once __DIR__ . '/libs/LogAnalyzerSystemTrait.php';

class LogAnalyzerIPSView extends IPSModuleStrict
{
	use LogAnalyzerStandardTrait;
	use LogAnalyzerSystemTrait;

	private const ATTR_STATUS = 'VisualisierungsStatus';
	private const ATTR_FILTERMETA = 'FilterMetadaten';
	private const ATTR_SEITENCACHE = 'SeitenCache';

    /**
     * Create
     *
     * Wird beim Erstellen der Modulinstanz aufgerufen.
     * - Registriert Eigenschaften, Attribute und Timer
     * - Initialisiert Standardwerte für Status und Cache
     *
     * Parameter: keine
     * Rückgabewert: void
     */
	public function Create(): void
	{
		// nicht löschen
		parent::Create();

		$this->RegisterPropertyString('LogDatei', IPS_GetLogDir() . 'logfile.log');		// nur zu Initialisierung, wird später überschrieben
		$this->RegisterPropertyInteger('MaxZeilen', 50);
		$this->RegisterPropertyBoolean('VerwendeSift', false);
		$this->RegisterPropertyInteger('AutoRefreshSekunden', 0);
		$this->RegisterPropertyString('Betriebsmodus', 'standard');
		$this->RegisterPropertyString('IpsUsername', '');
		$this->RegisterPropertyString('IpsPassword', '');

		$this->RegisterAttributeString(self::ATTR_STATUS, json_encode([
			'seite'                    => 0,
			'maxZeilen'                => 50,
			'theme'                    => 'dark',
			'kompakt'                  => false,
			'filterTypen'              => [],
			'objektIdFilter'           => '',
			'senderFilter'             => [],
			'textFilter'               => '',
			'trefferGesamt'            => -1,
			'zaehlungLaeuft'           => false,
			'dateiGroesseCache'        => 0,
			'dateiMTimeCache'          => 0,
			'zaehlSignatur'            => '',
			'tabellenLadungLaeuft'     => false,
			'tabellenLadungText'       => '',
			'letzteTabellenLadezeitMs' => 0
		], JSON_THROW_ON_ERROR));

		$this->RegisterAttributeString(self::ATTR_FILTERMETA, json_encode([
			'verfuegbareFilterTypen' => [],
			'verfuegbareSender'      => [],
			'dateiGroesseCache'      => 0,
			'dateiMTimeCache'        => 0,
			'ladezeitMs'             => 0,
			'laedt'                  => false,
			'signatur'               => ''
		], JSON_THROW_ON_ERROR));

		$this->RegisterAttributeString(self::ATTR_SEITENCACHE, json_encode([
			'listenSignatur'    => '',
			'zaehlSignatur'     => '',
			'dateiGroesseCache' => 0,
			'dateiMTimeCache'   => 0,
			'trefferGesamt'     => -1,
			'hatWeitere'        => false,
			'zeilen'            => []
		], JSON_THROW_ON_ERROR));

		// Visu Aktulisieren
		$this->RegisterTimer('VisualisierungAktualisieren',0,'LOGANALYZER_AktualisierenVisualisierung($_IPS["TARGET"]);');
		// HTML-Box Variable für IPSView
		$this->RegisterVariableString('HTMLBOX', 'Log Anzeige', '~HTMLBox');
	}

    public function Destroy(): void
    {
		// nicht löschen
        parent::Destroy();
    }

    /**
     * ApplyChanges
     *
     * Wird bei Änderungen der Modulkonfiguration aufgerufen.
     * - Aktualisiert Timer, Visualisierung und Zusammenfassung
     * - Prüft und korrigiert den Logdateipfad bei Bedarf
     *
     * Parameter: keine
     * Rückgabewert: void
     */
    public function ApplyChanges(): void
    {
		// nicht löschen
        parent::ApplyChanges();

		// Tile Visu nutzen
        // Tile-Visualisierung deaktiviert (IPSView HTML-Box wird verwendet)
        // $this->SetVisualizationType(1);

        $intervall = max(0, $this->ReadPropertyInteger('AutoRefreshSekunden')) * 1000;
        $this->SetTimerInterval('VisualisierungAktualisieren', $intervall);

        $logDatei = $this->ReadPropertyString('LogDatei');

		if (!is_file($logDatei)) {
			$verfuegbareDateien = $this->ermittleVerfuegbareLogdateien();
			if (count($verfuegbareDateien) > 0) {
				$logDatei = (string) $verfuegbareDateien[0]['pfad'];
				IPS_SetProperty($this->InstanceID, 'LogDatei', $logDatei);
			}
		}
		
        $summary = basename($logDatei);
        if (is_file($logDatei)) {
            $summary .= ' · ' . $this->formatiereDateigroesse((int) filesize($logDatei));
        }
		
		if (IPS_GetProperty($this->InstanceID, 'LogDatei') !== $logDatei) {
			IPS_ApplyChanges($this->InstanceID);
			return;
		}
        $this->SetSummary($summary);
    }

    /**
     * GetConfigurationForm
     *
     * Liefert das Konfigurationsformular des Moduls.
     * - Erstellt die angezeigten Hinweise und Aktionen
     * - Gibt das Formular als JSON zurück
     *
     * Parameter: keine
     * Rückgabewert: string
     */
    public function GetConfigurationForm(): string
    {
        $elements = [
            [
                'type'    => 'Label',
                'bold'    => true,
                'caption' => 'Log Analyzer IPSView'
            ],
            [
                'type'    => 'Label',
                'caption' => 'Alle Einstellungen (Logdatei, Filter, Zeilen, Modus) werden direkt in der HTML-Box gesteuert.'
            ],
            [
                'type'    => 'Label',
                'caption' => 'Die Variable "HTMLBOX" in IPSView als HTML-Box einbinden.'
            ],
            [
                'type'    => 'Label',
                'bold'    => true,
                'caption' => 'IPS-Zugangsdaten für JSON-RPC (nur nötig wenn IPS Authentifizierung aktiviert ist)'
            ],
            [
                'type'    => 'ValidationTextBox',
                'name'    => 'IpsUsername',
                'caption' => 'Benutzername'
            ],
            [
                'type'    => 'PasswordTextBox',
                'name'    => 'IpsPassword',
                'caption' => 'Passwort'
            ],
        ];

        $actions = [
            [
                'type'    => 'Button',
                'caption' => 'HTML-Box jetzt aktualisieren',
                'onClick' => 'LOGANALYZER_AktualisierenVisualisierung($id);'
            ],
        ];

        return json_encode([
            'elements' => $elements,
            'actions'  => $actions
        ], JSON_THROW_ON_ERROR);
    }


    /**
     * RequestAction
     *
     * Verarbeitet Aktionen aus der Visualisierung.
     * - Reagiert auf Navigation, Filter, Einstellungen und Dateiauswahl
     * - Aktualisiert Status, Cache und Anzeige
     *
     * Parameter: string $Ident, mixed $Value
     * Rückgabewert: void
     */
	public function RequestAction(string $Ident, mixed $Value): void
	{
		$this->SendDebug('RequestAction', 'Ident=' . $Ident . ' Value=' . print_r($Value, true), 0);
		try {
			$status = $this->leseStatus();
			switch ($Ident) {
				case 'Laden':
				case 'Aktualisieren':
					$status['trefferGesamt'] = -1;
					$status['zaehlungLaeuft'] = false;
					$status['dateiGroesseCache'] = 0;
					$status['dateiMTimeCache'] = 0;
					$status['zaehlSignatur'] = '';
					$this->schreibeStatus($status);
					$this->leereSeitenCache();

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/Aktualisieren-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}

					$this->setzeTabellenLadezustand(true, 'Tabelle wird aktualisiert …', 'RequestAction/Aktualisieren');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-aktualisieren');
					$this->aktualisiereVisualisierung();
					return;
				case 'SeiteVor':
					$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
					$aktuelleSeite = max(0, (int) ($status['seite'] ?? 0));
					$trefferGesamt = (int) ($status['trefferGesamt'] ?? -1);

					if ($trefferGesamt >= 0) {
						$maxSeite = max(0, (int) ceil($trefferGesamt / $maxZeilen) - 1);
						$status['seite'] = min($aktuelleSeite + 1, $maxSeite);
					} else {
						$status['seite'] = $aktuelleSeite + 1;
					}

					$this->schreibeStatus($status);

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/SeiteVor-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}

					$this->setzeTabellenLadezustand(true, 'Ältere Einträge werden geladen …', 'RequestAction/SeiteVor');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-seitevor');
					$this->aktualisiereVisualisierung();
					return;
				case 'SeiteZurueck':
					$status['seite'] = max(0, (int) $status['seite'] - 1);
					$this->schreibeStatus($status);

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/SeiteZurueck-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}
					$this->setzeTabellenLadezustand(true, 'Neuere Einträge werden geladen …', 'RequestAction/SeiteZurueck');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-seitezurueck');
					$this->aktualisiereVisualisierung();
					return;
				case 'FilterAnwenden':
					$daten = $this->dekodiereJsonArray((string) $Value);

					$status['filterTypen'] = $this->normalisiereFilterTypen($daten['filterTypen'] ?? []);
					$status['objektIdFilter'] = $this->normalisiereObjektIdFilterString((string) ($daten['objektIdFilter'] ?? ''));
					$status['senderFilter'] = $this->normalisiereSenderFilter($daten['senderFilter'] ?? []);
					$status['textFilter'] = trim((string) ($daten['textFilter'] ?? ''));
					$status['seite'] = 0;

					$status['trefferGesamt'] = -1;
					$status['zaehlungLaeuft'] = false;
					$status['dateiGroesseCache'] = 0;
					$status['dateiMTimeCache'] = 0;
					$status['zaehlSignatur'] = '';

					$this->schreibeStatus($status);
					$this->leereSeitenCache();

					if ($this->ermittleAktivenModus() === 'standard') {
						$this->schreibeFilterMetadaten([
							'verfuegbareFilterTypen' => [],
							'verfuegbareSender'      => [],
							'gesamtZeilenCache'      => -1,
							'dateiGroesseCache'      => 0,
							'dateiMTimeCache'        => 0,
							'ladezeitMs'             => 0,
							'laedt'                  => false,
							'signatur'               => ''
						]);
					}

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/FilterAnwenden-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}

					$this->setzeTabellenLadezustand(true, 'Gefilterte Tabelle wird geladen …', 'RequestAction/FilterAnwenden');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-filteranwenden');
					$this->aktualisiereVisualisierung();
					return;
				case 'ZaehleTreffer':
					$this->zaehleTrefferAsynchron();
					return;
				case 'LadeFilterOptionen':
					$this->ladeFilterMetadatenAsynchron();
					return;
				case 'LogDateiAuswaehlen':
					$datei = trim((string) $Value);
					$verfuegbareDateien = $this->ermittleVerfuegbareLogdateien();
					$gueltigePfade = array_column($verfuegbareDateien, 'pfad');

					if (!in_array($datei, $gueltigePfade, true)) {
						throw new Exception('Ungültige Logdatei ausgewählt: ' . $datei);
					}

					// IPSView: IPS_ApplyChanges würde den Thread abbrechen bevor SetValue('HTMLBOX') erreicht wird.
					// Property direkt schreiben und selbst aktualisieren statt ApplyChanges zu nutzen.
					IPS_SetProperty($this->InstanceID, 'LogDatei', $datei);
					// Timer-Intervall neu setzen (wie ApplyChanges es täte)
					$intervall = max(0, $this->ReadPropertyInteger('AutoRefreshSekunden')) * 1000;
					$this->SetTimerInterval('VisualisierungAktualisieren', $intervall);

					$status = [
						'seite'                    => 0,
						'maxZeilen'                => $this->normalisiereMaxZeilen(
							max((int) ($status['maxZeilen'] ?? 0), $this->ReadPropertyInteger('MaxZeilen'))
						),
						'theme'                    => $this->normalisiereTheme((string) ($status['theme'] ?? 'dark')),
						'kompakt'                  => $this->normalisiereKompakt($status['kompakt'] ?? false),
						'filterTypen'              => [],
						'objektIdFilter'           => '',
						'senderFilter'             => [],
						'textFilter'               => '',
						'trefferGesamt'            => -1,
						'zaehlungLaeuft'           => false,
						'dateiGroesseCache'        => 0,
						'dateiMTimeCache'          => 0,
						'zaehlSignatur'            => '',
						'tabellenLadungLaeuft'     => false,
						'tabellenLadungText'       => '',
						'letzteTabellenLadezeitMs' => 0
					];
					$this->schreibeStatus($status);

					$this->schreibeFilterMetadaten([
						'verfuegbareFilterTypen' => [],
						'verfuegbareSender'      => [],
						'gesamtZeilenCache'      => -1,
						'dateiGroesseCache'      => 0,
						'dateiMTimeCache'        => 0,
						'ladezeitMs'             => 0,
						'laedt'                  => false,
						'signatur' => ''
					]);

					$this->leereSeitenCache();

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/LogDateiAuswaehlen-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}

					$this->setzeTabellenLadezustand(true, 'Logdatei wird geladen …', 'RequestAction/LogDateiAuswaehlen');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-logdateiwechsel');
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeMaxZeilen':
					$status['maxZeilen'] = $this->normalisiereMaxZeilen((int) $Value);
					$status['seite'] = 0;
					$this->schreibeStatus($status);

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/SetzeMaxZeilen-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}

					$this->setzeTabellenLadezustand(true, 'Tabellenbereich wird neu geladen …', 'RequestAction/SetzeMaxZeilen');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-maxzeilen');
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeTheme':
					$status['theme'] = $this->normalisiereTheme((string) $Value);
					$this->schreibeStatus($status);
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeKompakt':
					$status['kompakt'] = $this->normalisiereKompakt($Value);
					$this->schreibeStatus($status);
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeBetriebsmodus':
					$modus = strtolower(trim((string) $Value));
					if (!in_array($modus, ['standard', 'system', 'ultra'], true)) {
						$modus = 'standard';
					}

					// IPSView: Property direkt setzen, kein IPS_ApplyChanges (würde Thread abbrechen)
					IPS_SetProperty($this->InstanceID, 'Betriebsmodus', $modus);

					$status['trefferGesamt'] = -1;
					$status['zaehlungLaeuft'] = false;
					$status['dateiGroesseCache'] = 0;
					$status['dateiMTimeCache'] = 0;
					$status['zaehlSignatur'] = '';
					$status['tabellenLadungLaeuft'] = false;
					$status['tabellenLadungText'] = '';
					$this->schreibeStatus($status);

					$this->schreibeFilterMetadaten([
						'verfuegbareFilterTypen' => [],
						'verfuegbareSender'      => [],
						'gesamtZeilenCache'      => -1,
						'dateiGroesseCache'      => 0,
						'dateiMTimeCache'        => 0,
						'ladezeitMs'             => 0,
						'laedt'                  => false,
						'signatur' => ''
					]);

					$this->leereSeitenCache();
					$this->aktualisiereVisualisierung();
					return;
			}

			throw new Exception('Unbekannte Aktion: ' . $Ident);
		} catch (\Throwable $e) {
			$this->SendDebug('RequestAction FEHLER', $e->getMessage(), 0);
			throw $e;}
	}

    /**
     * AktualisierenVisualisierung
     *
     * Öffentliche Methode zum Aktualisieren der Visualisierung.
     * - Ruft die interne Aktualisierung der Anzeige auf
     * - Kann durch Timer oder Aktion verwendet werden
     *
     * Parameter: keine
     * Rückgabewert: void
     */
	public function AktualisierenVisualisierung(): void
	{
		$this->aktualisiereVisualisierung();
	}

    /**
     * aktualisiereVisualisierungNurStatus
     *
     * Aktualisiert nur den Statusbereich der Visualisierung.
     * - Erstellt kompakte Statusdaten für die Oberfläche
     * - Überträgt die Daten ohne vollständigen Tabellenneuaufbau
     *
     * Parameter: string $quelle
     * Rückgabewert: void
     */
	private function aktualisiereVisualisierungNurStatus(string $quelle = ''): void
	{
		$daten = $this->erstelleVisualisierungsStatusDaten();
		$this->SendDebug('VisualisierungStatus',
			sprintf(
				'quelle=%s zeilen=%d treffer=%d zaehlung=%s filterLaeuft=%s tabellenLadung=%s ladezeitMs=%d',
				$quelle !== '' ? $quelle : '-',
				is_array($daten['zeilen'] ?? null) ? count($daten['zeilen']) : 0,
				(int) ($daten['trefferGesamt'] ?? -1),
				($daten['zaehlungLaeuft'] ?? false) ? 'true' : 'false',
				($daten['filterMetadatenLaeuft'] ?? false) ? 'true' : 'false',
				($daten['tabellenLadungLaeuft'] ?? false) ? 'true' : 'false',
				(int) ($daten['ladezeitMs'] ?? 0)
			),
			0
		);
		$erfolg = $this->UpdateVisualizationValue(json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
		);
		$this->SendDebug('UpdateVisualizationValue Status', $erfolg ? 'true' : 'false', 0);
	}

    /**
     * aktualisiereVisualisierung
     *
     * Aktualisiert die vollständige Visualisierung des Moduls.
     * - Erstellt neue Anzeigedaten und übergibt sie an die Oberfläche
     * - Setzt Ladezustände nach erfolgreichem Aufbau zurück
     *
     * Parameter: keine
     * Rückgabewert: void
     */


	/**
	 * erstelleHtmlFuerIPSView
	 *
	 * Erzeugt eine vollständig interaktive HTML-Oberfläche für die IPSView HTML-Box.
	 * - Enthält alle Filter- und Steuerelemente (wie die original Tile-Ansicht)
	 * - Kommuniziert via IPS JSON-RPC mit dem Modul (RequestAction)
	 *
	 * Parameter: array $daten
	 * Rückgabewert: string
	 */
	private function erstelleHtmlFuerIPSView(array $daten): string
	{
		$instanzId  = $this->InstanceID;
		$status     = is_array($daten['status'] ?? null) ? $daten['status'] : [];
		$zeilen     = is_array($daten['zeilen'] ?? null) ? $daten['zeilen'] : [];
		$logDatei   = (string) ($daten['logDatei'] ?? '');
		$dateiGroesse = htmlspecialchars((string) ($daten['dateiGroesse'] ?? ''));
		$treffer    = (int) ($daten['trefferGesamt'] ?? -1);
		$von        = (int) ($daten['bereichVon'] ?? 0);
		$bis        = (int) ($daten['bereichBis'] ?? 0);
		$ts         = htmlspecialchars((string) ($daten['zeitstempel'] ?? ''));
		$seite      = (int) ($status['seite'] ?? 0);
		$maxZeilen  = (int) ($daten['maxZeilen'] ?? 50);
		$modus      = htmlspecialchars((string) ($daten['betriebsmodus'] ?? 'standard'));
		$hatWeitere = (bool) ($daten['hatWeitere'] ?? false);
		$laedtTab   = (bool) ($daten['tabellenLadungLaeuft'] ?? false);
		$laedtZaehl = (bool) ($daten['zaehlungLaeuft'] ?? false);
		$laedtFilter= (bool) ($daten['filterMetadatenLaeuft'] ?? false);
		$laedt      = ($laedtTab || $laedtZaehl || $laedtFilter) ? ' active' : '';
		$laedtText  = htmlspecialchars((string) ($daten['tabellenLadungText'] ?? ''));
		$aktiveTypen   = json_encode(array_values((array) ($status['filterTypen'] ?? [])));
		$aktiveSender  = json_encode(array_values((array) ($status['senderFilter'] ?? [])));
		$textFilter    = htmlspecialchars((string) ($status['textFilter'] ?? ''));
		$objektFilter  = htmlspecialchars((string) ($status['objektIdFilter'] ?? ''));
		$verfTypen     = json_encode(array_values((array) ($daten['verfuegbareFilterTypen'] ?? [])));
		$verfSender    = json_encode(array_values((array) ($daten['verfuegbareSender'] ?? [])));
		$ladezeitTab   = (int) ($daten['ladezeitMs'] ?? 0);
		$ladezeitFilt  = (int) ($daten['filterLadezeitMs'] ?? 0);

		// Fehlerfall
		$fehlerHtml = '';
		if (!(bool) ($daten['ok'] ?? false)) {
			$msg = htmlspecialchars((string) ($daten['fehlermeldung'] ?? 'Unbekannter Fehler'));
			$fehlerHtml = "<div class=\"la-message\">&#9888; {$msg}</div>";
		}

		// Logdatei-Optionen
		$logOptionen = '';
		foreach ((array) ($daten['verfuegbareLogdateien'] ?? []) as $ld) {
			$pfad    = htmlspecialchars((string) ($ld['pfad']    ?? ''));
			$anzeige = htmlspecialchars((string) ($ld['anzeige'] ?? $pfad));
			$sel     = ($pfad === $logDatei) ? ' selected' : '';
			$logOptionen .= "<option value=\"{$pfad}\"{$sel}>{$anzeige}</option>";
		}

		// MaxZeilen-Optionen
		$zeilenOptionen = '';
		foreach ([20, 50, 100, 200, 500, 1000, 2000, 3000] as $z) {
			$sel = ($z === $maxZeilen) ? ' selected' : '';
			$zeilenOptionen .= "<option value=\"{$z}\"{$sel}>{$z}</option>";
		}

		// Betriebsmodus-Optionen
		$modusOptionen = '';
		foreach (['standard' => 'Standard', 'system' => 'System'] as $val => $lbl) {
			$sel = ($val === $modus) ? ' selected' : '';
			$modusOptionen .= "<option value=\"{$val}\"{$sel}>{$lbl}</option>";
		}

		// Ladebalken
		$ladebarVisible = $laedt ? 'block' : 'none';
		if ($laedtText === '') {
			if ($laedtTab)   $laedtText = 'Tabelle wird geladen …';
			elseif ($laedtZaehl) $laedtText = 'Treffer werden ermittelt …';
			else              $laedtText = 'Filteroptionen werden geladen …';
		}

		// Metazeile
		$metaTreffer = $laedtZaehl
			? 'Treffer: wird ermittelt …'
			: ($treffer >= 0 ? "Bereich: {$von}–{$bis} / {$treffer}" : 'Treffer: -');

		// Tabellenbody
		$typFarben = [
			'DEBUG' => '#7ecfff', 'INFO' => '#aaffaa', 'WARNING' => '#ffd080',
			'ERROR' => '#ff7070', 'FATAL' => '#ff4444', 'NOTIFY' => '#d0aaff',
			'SUCCESS' => '#88ffcc', 'MESSAGE' => '#cccccc',
		];
		$tbodyHtml = '';
		if ($laedtTab && empty($zeilen)) {
			$tbodyHtml = '<tr><td colspan="5" class="la-empty">Lade Daten …</td></tr>';
		} elseif (empty($zeilen)) {
			$tbodyHtml = '<tr><td colspan="5" class="la-empty">Keine passenden Logzeilen gefunden.</td></tr>';
		} else {
			foreach ($zeilen as $z) {
				$typ    = htmlspecialchars((string) ($z['typ']         ?? ''));
				$sender = htmlspecialchars((string) ($z['sender']      ?? ''));
				$msg    = htmlspecialchars((string) ($z['meldung']     ?? ''));
				$zeit   = htmlspecialchars((string) ($z['zeitstempel'] ?? ''));
				$oid    = htmlspecialchars((string) ($z['objektId']    ?? ''));
				$farbe  = $typFarben[strtoupper($typ)] ?? '#cccccc';
				$tbodyHtml .= "<tr>
					<td class=\"la-col-zeit\">{$zeit}</td>
					<td class=\"la-col-oid\" ondblclick=\"oidInFilter('{$oid}')\" title=\"Doppelklick → ObjektID in Filter\">{$oid}</td>
					<td class=\"la-col-typ\" style=\"color:{$farbe}\">{$typ}</td>
					<td class=\"la-col-sender\">{$sender}</td>
					<td class=\"la-col-msg\">{$msg}</td>
				</tr>";
			}
		}

		// Buttons
		$disZurueck = ($seite <= 0) ? ' disabled' : '';
		if ($treffer >= 0 && $maxZeilen > 0) {
			$maxSeite = max(0, (int) ceil($treffer / $maxZeilen) - 1);
			$disVor   = ($seite >= $maxSeite) ? ' disabled' : '';
		} else {
			$disVor = $hatWeitere ? '' : ' disabled';
		}

		// JSON-RPC URL
		// Relativer Pfad – funktioniert da die HTML-Box über den IPS-Webserver ausgeliefert wird
		$rpcUrl = '/api/';
		$ipsUser = (string) $this->ReadPropertyString('IpsUsername');
		$ipsPass = (string) $this->ReadPropertyString('IpsPassword');
		$hasAuth    = ($ipsUser !== '') ? 'true' : 'false';
		$ipsUserJson = json_encode($ipsUser);
		$ipsPassJson = json_encode($ipsPass);

		return <<<HTML
<!doctype html><html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;background:#111;color:#ddd}
.la-wrap{padding:10px;display:grid;gap:10px}
.la-card{background:rgba(30,30,30,.95);border:1px solid rgba(255,255,255,.1);border-radius:10px;overflow:visible}
.la-toolbar{padding:10px;background:rgba(255,255,255,.04);border-bottom:1px solid rgba(255,255,255,.08);display:grid;gap:10px}
.la-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
label.la-lbl{display:grid;gap:4px;font-size:11px;color:#999}
input,select{width:100%;min-height:32px;border-radius:6px;border:1px solid rgba(255,255,255,.15);background:rgba(0,0,0,.3);color:#ddd;padding:6px 8px;font-size:12px;color-scheme:dark}
input:focus,select:focus,button:focus{outline:none;border-color:rgba(77,163,255,.5);box-shadow:0 0 0 3px rgba(77,163,255,.12)}
.la-btnrow{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.la-inline{display:inline-flex;align-items:center;gap:5px;white-space:nowrap;min-width:120px}
.la-inline span{font-size:11px;color:#999}
.la-inline select{width:auto;flex:1 1 auto;min-height:28px;padding:4px 6px;font-size:11px}
button{min-height:28px;padding:5px 10px;border-radius:6px;border:1px solid rgba(255,255,255,.15);background:rgba(77,163,255,.18);color:#ddd;cursor:pointer;white-space:nowrap;transition:transform .1s,opacity .1s;font-size:12px}
button:hover{transform:translateY(-1px)}
button.sec{background:rgba(255,255,255,.07)}
button:disabled{opacity:.4;cursor:default;transform:none}
.la-meta{display:flex;flex-wrap:wrap;gap:10px;padding:8px 12px;font-size:11px;color:#777;border-bottom:1px solid rgba(255,255,255,.06)}
.la-loader{padding:6px 12px;display:none}
.la-loader.active{display:block}
.la-loader-track{height:5px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden;position:relative}
.la-loader-bar{position:absolute;top:0;left:-30%;width:30%;height:100%;border-radius:999px;background:rgba(77,163,255,.85);animation:la-run 1.4s ease-in-out infinite alternate}
@keyframes la-run{from{left:-30%}to{left:100%}}
.la-loader-txt{margin-top:6px;font-size:11px;color:#666;text-align:center}
.la-message{padding:10px 12px;color:#f99;border-top:1px solid rgba(255,255,255,.08)}
.la-table-wrap{overflow:auto;max-height:65vh}
table{width:100%;border-collapse:collapse;font-size:12px}
thead th{position:sticky;top:0;z-index:2;text-align:left;padding:7px 8px;background:#7a4400;color:#fff;border-bottom:1px solid rgba(255,255,255,.15);border-right:1px solid rgba(255,255,255,.08)}
thead th:last-child{border-right:none}
tbody td{padding:7px 8px;border-bottom:1px solid rgba(255,255,255,.07);border-right:1px solid rgba(255,255,255,.06);vertical-align:top}
tbody td:last-child{border-right:none}
tbody tr:nth-child(even){background:rgba(255,255,255,.03)}
.la-col-zeit{width:155px;white-space:nowrap;color:#666;font-size:11px}
.la-col-oid{width:80px;white-space:nowrap;cursor:pointer}
.la-col-typ{width:100px;white-space:nowrap;font-weight:bold;font-size:11px}
.la-col-sender{width:200px;color:#aaa;font-size:11px}
.la-col-msg{word-break:break-word}
.la-empty{padding:14px;color:#555}
/* Multi-Select Dropdown */
.ms-wrap{position:relative}
.ms-btn{width:100%;min-height:32px;border-radius:6px;border:1px solid rgba(255,255,255,.15);background:rgba(0,0,0,.3);color:#ddd;padding:6px 8px;text-align:left;display:flex;align-items:center;justify-content:space-between;font-size:12px}
.ms-btn::after{content:"▾";opacity:.7;margin-left:8px}
.ms-drop{position:absolute;top:calc(100% + 3px);left:0;right:0;z-index:99;display:none;background:rgba(30,30,30,.98);border:1px solid rgba(255,255,255,.15);border-radius:6px;padding:8px;box-shadow:0 8px 20px rgba(0,0,0,.4)}
.ms-wrap.open .ms-drop{display:block}
.ms-opts{display:grid;gap:5px;max-height:200px;overflow-y:auto}
.ms-opt{display:flex;align-items:center;gap:7px;font-size:12px;color:#ddd}
.ms-opt input[type=checkbox]{width:auto;min-height:0;margin:0;padding:0}
</style></head>
<body>
<div class="la-wrap"><div class="la-card">

  <div class="la-toolbar">
    <div class="la-grid">
      <label class="la-lbl">Logdatei
        <select id="laLogDatei" onchange="la('LogDateiAuswaehlen',this.value)">{$logOptionen}</select>
      </label>
      <label class="la-lbl">Zeilen pro Seite
        <select id="laMaxZeilen" onchange="la('SetzeMaxZeilen',parseInt(this.value))">{$zeilenOptionen}</select>
      </label>
      <label class="la-lbl">ObjektID
        <input id="laObjektId" type="text" placeholder="z. B. 12345, 67890" value="{$objektFilter}">
      </label>
      <label class="la-lbl">Meldungstyp
        <div class="ms-wrap" id="msTypWrap">
          <button type="button" class="ms-btn" id="msTypBtn" onclick="msToggle('msTypWrap',event)">Alle</button>
          <div class="ms-drop"><div class="ms-opts" id="msTypOpts"></div></div>
        </div>
      </label>
      <label class="la-lbl">Sender
        <div class="ms-wrap" id="msSndWrap">
          <button type="button" class="ms-btn" id="msSndBtn" onclick="msToggle('msSndWrap',event)">Alle</button>
          <div class="ms-drop"><div class="ms-opts" id="msSndOpts"></div></div>
        </div>
      </label>
      <label class="la-lbl">Meldung enthält
        <input id="laText" type="text" placeholder="Freitext" value="{$textFilter}">
      </label>
    </div>
    <div class="la-btnrow">
      <label class="la-inline"><span>Mode:</span>
        <select id="laModus" onchange="la('SetzeBetriebsmodus',this.value)">{$modusOptionen}</select>
      </label>
      <button onclick="laFilter()">Filter anwenden</button>
      <button class="sec" onclick="la('Aktualisieren','')">Aktualisieren</button>
      <button class="sec" id="btnNeu" {$disZurueck} onclick="la('SeiteZurueck','')">Neuere</button>
      <button class="sec" id="btnAlt" {$disVor} onclick="la('SeiteVor','')">Ältere</button>
    </div>
  </div>

  <div class="la-meta">
    <span>Datei: {$logDatei}</span>
    <span>Größe: {$dateiGroesse}</span>
    <span>Seite: {$seite}</span>
    <span>{$metaTreffer}</span>
    <span>Tab: {$ladezeitTab} ms</span>
    <span>Filter: {$ladezeitFilt} ms</span>
    <span>Stand: {$ts}</span>
  </div>

  <div class="la-loader{$laedt}" id="laLoader" style="display:{$ladebarVisible}">
    <div class="la-loader-track"><div class="la-loader-bar"></div></div>
    <div class="la-loader-txt" id="laLoaderTxt">{$laedtText}</div>
  </div>

  {$fehlerHtml}

  <div class="la-table-wrap">
    <table>
      <thead><tr>
        <th class="la-col-zeit">Zeit</th>
        <th class="la-col-oid">ObjektID</th>
        <th class="la-col-typ">Typ</th>
        <th class="la-col-sender">Sender</th>
        <th>Meldung</th>
      </tr></thead>
      <tbody>{$tbodyHtml}</tbody>
    </table>
  </div>
</div></div>

<script>
const IPS_ID   = {$instanzId};
const RPC_URL  = '{$rpcUrl}';
const HAS_AUTH = {$hasAuth};
const IPS_USER = {$ipsUserJson};
const IPS_PASS = {$ipsPassJson};
const VERF_TYPEN  = {$verfTypen};
const VERF_SENDER = {$verfSender};
const AKT_TYPEN   = {$aktiveTypen};
const AKT_SENDER  = {$aktiveSender};

// Ladebalken und Buttons sperren
function laSetLoading(text) {
  document.querySelectorAll('button').forEach(b => b.disabled = true);
  const loader = document.getElementById('laLoader');
  const loaderTxt = document.getElementById('laLoaderTxt');
  if (loader) loader.style.display = 'block';
  if (loaderTxt) loaderTxt.textContent = text || 'Wird verarbeitet …';
}

// IPS JSON-RPC Aufruf – sendet Aktion und lädt danach die Seite neu
function la(ident, value, reloadDelay) {
  laSetLoading('Wird verarbeitet …');
  const delay = reloadDelay || 600;

  const headers = {'Content-Type': 'application/json'};
  if (HAS_AUTH) {
    headers['Authorization'] = 'Basic ' + btoa(IPS_USER + ':' + IPS_PASS);
  }

  fetch(RPC_URL, {
    method: 'POST',
    credentials: 'include',
    headers: headers,
    body: JSON.stringify({
      jsonrpc: '2.0', method: 'IPS_RequestAction', id: 1,
      params: {InstanceID: IPS_ID, Ident: ident, Value: value}
    })
  })
  .then(r => r.json())
  .then(resp => {
    if (resp && resp.error) {
      console.warn('IPS RPC Antwort-Fehler', resp.error);
    }
    // Seite neu laden damit neue HTMLBOX-Daten sichtbar werden
    setTimeout(() => location.reload(), delay);
  })
  .catch(e => {
    console.error('IPS RPC Fehler', e);
    setTimeout(() => location.reload(), delay);
  });
}

// Filter sammeln und senden
function laFilter() {
  la('FilterAnwenden', JSON.stringify({
    filterTypen:    msGetSelected('msTypOpts'),
    objektIdFilter: document.getElementById('laObjektId').value.trim(),
    senderFilter:   msGetSelected('msSndOpts'),
    textFilter:     document.getElementById('laText').value.trim()
  }));
}

// ObjektID in Filterfeld übernehmen (Doppelklick)
function oidInFilter(id) {
  const inp = document.getElementById('laObjektId');
  const existing = inp.value.split(/[\s,;]+/).map(v => v.trim()).filter(Boolean);
  if (!existing.includes(id)) existing.push(id);
  inp.value = existing.join(', ');
}

// Multi-Select: Toggle
function msToggle(wrapId, event) {
  event.stopPropagation();
  document.querySelectorAll('.ms-wrap').forEach(w => {
    if (w.id !== wrapId) w.classList.remove('open');
  });
  document.getElementById(wrapId).classList.toggle('open');
}

// Multi-Select: Optionen befüllen
function msFill(optsId, verfuegbar, aktiv) {
  const all = [...new Set([...verfuegbar, ...aktiv])].filter(Boolean);
  const c = document.getElementById(optsId);
  c.innerHTML = '';
  all.forEach(v => {
    const lbl = document.createElement('label');
    lbl.className = 'ms-opt';
    const cb = document.createElement('input');
    cb.type = 'checkbox'; cb.value = v; cb.checked = aktiv.includes(v);
    cb.addEventListener('change', () => msUpdateBtn(optsId));
    lbl.appendChild(cb);
    lbl.appendChild(document.createTextNode(' ' + v));
    c.appendChild(lbl);
  });
  msUpdateBtn(optsId);
}

// Multi-Select: Button-Text aktualisieren
function msUpdateBtn(optsId) {
  const btnMap = {msTypOpts: 'msTypBtn', msSndOpts: 'msSndBtn'};
  const sel = msGetSelected(optsId);
  const btn = document.getElementById(btnMap[optsId]);
  btn.textContent = sel.length === 0 ? 'Alle' : (sel.length <= 2 ? sel.join(', ') : sel.length + ' ausgewählt');
}

// Multi-Select: Selektierte Werte lesen
function msGetSelected(optsId) {
  return Array.from(document.querySelectorAll('#' + optsId + ' input[type=checkbox]:checked')).map(i => i.value);
}

// Dropdowns schließen bei Klick außerhalb
document.addEventListener('click', () => {
  document.querySelectorAll('.ms-wrap').forEach(w => w.classList.remove('open'));
});

// Init
msFill('msTypOpts', VERF_TYPEN, AKT_TYPEN);
msFill('msSndOpts', VERF_SENDER, AKT_SENDER);
</script>
</body></html>
HTML;
	}


	private function aktualisiereVisualisierung(): void
	{
		try {
			$start = microtime(true);
			$daten = $this->erstelleVisualisierungsDaten();
			$dauerMs = (int) round((microtime(true) - $start) * 1000);

			$status = $this->leseStatus();
			$status['letzteTabellenLadezeitMs'] = (int) ($daten['ladezeitMs'] ?? 0);

			if ((bool) ($status['tabellenLadungLaeuft'] ?? false)) {
				$status['tabellenLadungLaeuft'] = false;
				$status['tabellenLadungText'] = '';
				$this->SendDebug('Ladebalken', 'quelle=aktualisiereVisualisierung sichtbar=false text=-', 0);
			}

			$this->schreibeStatus($status);

			$daten['status'] = $status;
			$daten['tabellenLadungLaeuft'] = false;
			$daten['tabellenLadungText'] = '';
			$daten['ladezeitMs'] = (int) ($status['letzteTabellenLadezeitMs'] ?? 0);

			$this->SendDebug(
				'Visualisierung',
				sprintf(
					'ok=%s datei=%s seite=%d maxZeilen=%d zeilen=%d hatWeitere=%s treffer=%d zaehlung=%s filterGeladen=%s tabellenLadung=%s dauerMs=%d',
					($daten['ok'] ?? false) ? 'true' : 'false',
					basename((string) ($daten['logDatei'] ?? '')),
					(int) ($status['seite'] ?? 0),
					(int) ($daten['maxZeilen'] ?? 0),
					is_array($daten['zeilen'] ?? null) ? count($daten['zeilen']) : 0,
					($daten['hatWeitere'] ?? false) ? 'true' : 'false',
					(int) ($daten['trefferGesamt'] ?? -1),
					($daten['zaehlungLaeuft'] ?? false) ? 'true' : 'false',
					($daten['filterMetadatenGeladen'] ?? false) ? 'true' : 'false',
					($daten['tabellenLadungLaeuft'] ?? false) ? 'true' : 'false',
					$dauerMs
				),
				0
			);

			$erfolg = $this->UpdateVisualizationValue(
				json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
			);

			$this->SendDebug('UpdateVisualizationValue', $erfolg ? 'true' : 'false', 0);

			// HTML-Box für IPSView befüllen
			$this->SetValue('HTMLBOX', $this->erstelleHtmlFuerIPSView($daten));
		} catch (\Throwable $e) {
			$status = $this->leseStatus();
			if ((bool) ($status['tabellenLadungLaeuft'] ?? false)) {
				$status['tabellenLadungLaeuft'] = false;
				$status['tabellenLadungText'] = '';
				$this->schreibeStatus($status);
				$this->SendDebug('Ladebalken', 'quelle=aktualisiereVisualisierung-fehler sichtbar=false text=-', 0);
			}

			$this->SendDebug('aktualisiereVisualisierung FEHLER', $e->getMessage(), 0);
			throw new Exception('Fehler beim Aktualisieren der Visualisierung: ' . $e->getMessage());
		}
	}

    /**
     * erstelleVisualisierungsDaten
     *
     * Erstellt die vollständigen Daten für die Visualisierung.
     * - Lädt Status, Metadaten und Logzeilen
     * - Bereitet alle Werte für die Anzeige auf
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function erstelleVisualisierungsDaten(): array
	{
		$status = $this->leseStatus();

		// IPSView: Filtermetadaten synchron laden wenn Cache leer oder ungültig
		$filterMetadaten = $this->leseFilterMetadatenFuerAnzeige();
		if (!(bool) ($filterMetadaten['geladen'] ?? false)) {
			$logDateiCheck = $this->ReadPropertyString('LogDatei');
			if (is_file($logDateiCheck)) {
				$dateiGroesseCheck = (int) filesize($logDateiCheck);
				$dateiMTimeCheck   = (int) filemtime($logDateiCheck);
				$ermittelt = $this->ermittleFilterMetadaten();
				$metaRoh = $this->leseFilterMetadatenRoh();
				$metaRoh['verfuegbareFilterTypen'] = $ermittelt['verfuegbareFilterTypen'];
				$metaRoh['verfuegbareSender']      = $ermittelt['verfuegbareSender'];
				$metaRoh['gesamtZeilenCache']      = (int) ($ermittelt['gesamtZeilen'] ?? -1);
				$metaRoh['dateiGroesseCache']      = $dateiGroesseCheck;
				$metaRoh['dateiMTimeCache']        = $dateiMTimeCheck;
				$metaRoh['ladezeitMs']             = 0;
				$metaRoh['laedt']                  = false;
				$metaRoh['signatur']               = $this->ermittleFilterMetadatenSignatur($this->leseStatus());
				$this->schreibeFilterMetadaten($metaRoh);
				$filterMetadaten = $this->leseFilterMetadatenFuerAnzeige();
			}
		}

		$logDatei = $this->ReadPropertyString('LogDatei');
		$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
		$betriebsmodus = $this->ermittleAktivenModus();

		$ergebnis = [
			'ok'                     => true,
			'fehlermeldung'          => '',
			'status'                 => $status,
			'maxZeilen'              => $maxZeilen,
			'logDatei'               => $logDatei,
			'dateiGroesse'           => '',
			'zeilen'                 => [],
			'hatWeitere'             => false,
			'zeitstempel'            => date('Y-m-d H:i:s'),
			'trefferGesamt'          => (int) ($status['trefferGesamt'] ?? -1),
			'zaehlungLaeuft'         => (bool) ($status['zaehlungLaeuft'] ?? false),
			'bereichVon'             => 0,
			'bereichBis'             => 0,
			'ladezeitMs'             => (int) ($status['letzteTabellenLadezeitMs'] ?? 0),
			'filterLadezeitMs'       => (int) ($filterMetadaten['ladezeitMs'] ?? 0),
			'verfuegbareFilterTypen' => $filterMetadaten['verfuegbareFilterTypen'],
			'verfuegbareSender'      => $filterMetadaten['verfuegbareSender'],
			'filterMetadatenGeladen' => $filterMetadaten['geladen'],
			'filterMetadatenLaeuft'  => $filterMetadaten['laedt'],
			'verfuegbareLogdateien'  => $this->ermittleVerfuegbareLogdateien(),
			'aktuelleLogDatei'       => $logDatei,
			'tabellenLadungLaeuft'   => (bool) ($status['tabellenLadungLaeuft'] ?? false),
			'tabellenLadungText'     => (string) ($status['tabellenLadungText'] ?? ''),
			'betriebsmodus'          => $betriebsmodus
		];

		$modusPruefung = $this->pruefeModusVerwendbarkeit();
		if (!(bool) ($modusPruefung['ok'] ?? false)) {
			$ergebnis['ok'] = false;
			$ergebnis['fehlermeldung'] = (string) ($modusPruefung['fehlermeldung'] ?? 'Modus nicht verfügbar.');
			return $ergebnis;
		}

		if (!is_file($logDatei)) {
			$ergebnis['ok'] = false;
			$ergebnis['fehlermeldung'] = 'Logdatei nicht gefunden: ' . $logDatei;
			return $ergebnis;
		}

		$ergebnis['dateiGroesse'] = $this->formatiereDateigroesse((int) filesize($logDatei));

		$start = microtime(true);
		$leseErgebnis = $this->ladeLogZeilen($status);
		$ergebnis['ladezeitMs'] = (int) round((microtime(true) - $start) * 1000);

		$ergebnis['ok'] = $leseErgebnis['ok'];
		$ergebnis['fehlermeldung'] = $leseErgebnis['fehlermeldung'];
		$ergebnis['zeilen'] = $leseErgebnis['zeilen'];
		$ergebnis['hatWeitere'] = $leseErgebnis['hatWeitere'];

		if (array_key_exists('trefferGesamt', $leseErgebnis) && (int) $leseErgebnis['trefferGesamt'] >= 0) {
			$ergebnis['trefferGesamt'] = (int) $leseErgebnis['trefferGesamt'];
		}

		$seite = max(0, (int) ($status['seite'] ?? 0));
		$anzahlAktuelleSeite = count($ergebnis['zeilen']);

		if ($anzahlAktuelleSeite > 0) {
			$ergebnis['bereichVon'] = ($seite * $maxZeilen) + 1;
			$ergebnis['bereichBis'] = ($seite * $maxZeilen) + $anzahlAktuelleSeite;
		}
		return $ergebnis;
	}

    /**
     * erstelleVisualisierungsStatusDaten
     *
     * Erstellt die Statusdaten für die Visualisierung.
     * - Lädt Status, Filtermetadaten und Cacheinformationen
     * - Gibt nur die aktuell nötigen Anzeigewerte zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function erstelleVisualisierungsStatusDaten(): array
	{
		$status = $this->leseStatus();
		$filterMetadaten = $this->leseFilterMetadatenFuerAnzeige();
		$logDatei = $this->ReadPropertyString('LogDatei');
		$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
		$betriebsmodus = $this->ermittleAktivenModus();

		$ergebnis = [
			'ok'                     => true,
			'fehlermeldung'          => '',
			'status'                 => $status,
			'maxZeilen'              => $maxZeilen,
			'logDatei'               => $logDatei,
			'dateiGroesse'           => '',
			'zeilen'                 => [],
			'hatWeitere'             => false,
			'zeitstempel'            => date('Y-m-d H:i:s'),
			'trefferGesamt'          => (int) ($status['trefferGesamt'] ?? -1),
			'zaehlungLaeuft'         => (bool) ($status['zaehlungLaeuft'] ?? false),
			'bereichVon'             => 0,
			'bereichBis'             => 0,
			'ladezeitMs'             => (int) ($status['letzteTabellenLadezeitMs'] ?? 0),
			'filterLadezeitMs'       => (int) ($filterMetadaten['ladezeitMs'] ?? 0),
			'verfuegbareFilterTypen' => $filterMetadaten['verfuegbareFilterTypen'],
			'verfuegbareSender'      => $filterMetadaten['verfuegbareSender'],
			'filterMetadatenGeladen' => $filterMetadaten['geladen'],
			'filterMetadatenLaeuft'  => $filterMetadaten['laedt'],
			'verfuegbareLogdateien'  => $this->ermittleVerfuegbareLogdateien(),
			'aktuelleLogDatei'       => $logDatei,
			'tabellenLadungLaeuft'   => (bool) ($status['tabellenLadungLaeuft'] ?? false),
			'tabellenLadungText'     => (string) ($status['tabellenLadungText'] ?? ''),
			'betriebsmodus'          => $betriebsmodus
		];

		$modusPruefung = $this->pruefeModusVerwendbarkeit();
		if (!(bool) ($modusPruefung['ok'] ?? false)) {
			$ergebnis['ok'] = false;
			$ergebnis['fehlermeldung'] = (string) ($modusPruefung['fehlermeldung'] ?? 'Modus nicht verfügbar.');
			return $ergebnis;
		}

		if (!is_file($logDatei)) {
			$ergebnis['ok'] = false;
			$ergebnis['fehlermeldung'] = 'Logdatei nicht gefunden: ' . $logDatei;
			return $ergebnis;
		}

		$dateiGroesse = (int) filesize($logDatei);
		$dateiMTime = (int) filemtime($logDatei);
		$ergebnis['dateiGroesse'] = $this->formatiereDateigroesse($dateiGroesse);

		$cache = $this->leseSeitenCache();
		$listenSignatur = $this->ermittleListenCacheSignatur($status);
		$zaehlSignatur = $this->ermittleZaehlsignatur($status);

		$cacheGueltig =
			((string) ($cache['listenSignatur'] ?? '') === $listenSignatur) &&
			((string) ($cache['zaehlSignatur'] ?? '') === $zaehlSignatur) &&
			((int) ($cache['dateiGroesseCache'] ?? 0) === $dateiGroesse) &&
			((int) ($cache['dateiMTimeCache'] ?? 0) === $dateiMTime);

		if ($cacheGueltig) {
			$ergebnis['zeilen'] = is_array($cache['zeilen'] ?? null) ? $cache['zeilen'] : [];
			$ergebnis['hatWeitere'] = (bool) ($cache['hatWeitere'] ?? false);

			if ((int) ($cache['trefferGesamt'] ?? -1) >= 0) {
				$ergebnis['trefferGesamt'] = (int) $cache['trefferGesamt'];
			}

			$seite = max(0, (int) ($status['seite'] ?? 0));
			$anzahlAktuelleSeite = count($ergebnis['zeilen']);

			if ($anzahlAktuelleSeite > 0) {
				$ergebnis['bereichVon'] = ($seite * $maxZeilen) + 1;
				$ergebnis['bereichBis'] = ($seite * $maxZeilen) + $anzahlAktuelleSeite;
			}
		}

		$this->SendDebug('StatusCache',
			sprintf(
				'datei=%s modus=%s zeilen=%d hatWeitere=%s treffer=%d tabellenLadung=%s zaehlung=%s filterLaeuft=%s',
				basename($logDatei),
				$betriebsmodus,
				is_array($ergebnis['zeilen'] ?? null) ? count($ergebnis['zeilen']) : 0,
				($ergebnis['hatWeitere'] ?? false) ? 'true' : 'false',
				(int) ($ergebnis['trefferGesamt'] ?? -1),
				($ergebnis['tabellenLadungLaeuft'] ?? false) ? 'true' : 'false',
				($ergebnis['zaehlungLaeuft'] ?? false) ? 'true' : 'false',
				($ergebnis['filterMetadatenLaeuft'] ?? false) ? 'true' : 'false'
			),
			0
		);
		return $ergebnis;
	}

    /**
     * ladeFilterMetadatenAsynchron
     *
     * Lädt verfügbare Filteroptionen und Metadaten zur Logdatei.
     * - Prüft Cache und Dateistand
     * - Aktualisiert Filtertypen, Sender und Gesamtanzahl
     *
     * Parameter: keine
     * Rückgabewert: void
     */
	private function ladeFilterMetadatenAsynchron(): void
	{
		$modusPruefung = $this->pruefeModusVerwendbarkeit();
		if (!(bool) ($modusPruefung['ok'] ?? false)) {
			$this->SendDebug('LadeFilterOptionen',
				sprintf(
					'modus-blockiert datei=%s meldung=%s',
					basename($this->ReadPropertyString('LogDatei')),
					(string) ($modusPruefung['fehlermeldung'] ?? '')
				),
				0
			);

			$meta = $this->leseFilterMetadatenRoh();
			$meta['laedt'] = false;
			$this->schreibeFilterMetadaten($meta);
			$this->aktualisiereVisualisierung();
			return;
		}

		$logDatei = $this->ReadPropertyString('LogDatei');
		$status = $this->leseStatus();
		$meta = $this->leseFilterMetadatenRoh();

		if (!is_file($logDatei)) {
			$meta['verfuegbareFilterTypen'] = [];
			$meta['verfuegbareSender'] = [];
			$meta['gesamtZeilenCache'] = -1;
			$meta['dateiGroesseCache'] = 0;
			$meta['dateiMTimeCache'] = 0;
			$meta['ladezeitMs'] = 0;
			$meta['laedt'] = false;
			$meta['signatur'] = '';
			$this->schreibeFilterMetadaten($meta);
			$this->aktualisiereVisualisierungNurStatus('ladeFilter-datei-fehlt');
			return;
		}

		$dateiGroesse = (int) filesize($logDatei);
		$dateiMTime = (int) filemtime($logDatei);
		$signatur = $this->ermittleFilterMetadatenSignatur($status);

		$cacheGueltig =
			((int) ($meta['dateiGroesseCache'] ?? 0) === $dateiGroesse) &&
			((int) ($meta['dateiMTimeCache'] ?? 0) === $dateiMTime) &&
			((string) ($meta['signatur'] ?? '') === $signatur) &&
			is_array($meta['verfuegbareFilterTypen'] ?? null) &&
			is_array($meta['verfuegbareSender'] ?? null);

		if ($cacheGueltig) {
			$this->SendDebug(
				'LadeFilterOptionen',
				sprintf(
					'plattform=%s datei=%s cache=gueltig',
					(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
					basename($logDatei)
				),
				0
			);
			$this->aktualisiereVisualisierungNurStatus('ladeFilter-cache');
			return;
		}

		if ((bool) ($meta['laedt'] ?? false)) {
			$this->SendDebug(
				'LadeFilterOptionen',
				sprintf(
					'plattform=%s datei=%s status=laeuft-bereits',
					(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
					basename($logDatei)
				),
				0
			);
			return;
		}

		$meta['laedt'] = true;
		$this->schreibeFilterMetadaten($meta);
		$this->aktualisiereVisualisierungNurStatus('ladeFilter-start');

		$start = microtime(true);
		$ermittelt = $this->ermittleFilterMetadaten();
		$ladezeitMs = (int) round((microtime(true) - $start) * 1000);

		$meta = $this->leseFilterMetadatenRoh();
		$meta['verfuegbareFilterTypen'] = $ermittelt['verfuegbareFilterTypen'];
		$meta['verfuegbareSender'] = $ermittelt['verfuegbareSender'];
		$meta['gesamtZeilenCache'] = (int) ($ermittelt['gesamtZeilen'] ?? -1);
		$meta['dateiGroesseCache'] = $dateiGroesse;
		$meta['dateiMTimeCache'] = $dateiMTime;
		$meta['ladezeitMs'] = $ladezeitMs;
		$meta['laedt'] = false;
		$meta['signatur'] = $signatur;

		$this->schreibeFilterMetadaten($meta);

		$status = $this->leseStatus();
		if (
			$this->ermittleAktivenModus() !== 'standard' &&
			!$this->hatAktiveFilter($status) &&
			(int) ($ermittelt['gesamtZeilen'] ?? -1) >= 0
		) {
			if ((int) ($status['trefferGesamt'] ?? -1) < 0) {
				$status['trefferGesamt'] = (int) $ermittelt['gesamtZeilen'];
				$status['dateiGroesseCache'] = $dateiGroesse;
				$status['dateiMTimeCache'] = $dateiMTime;
				$status['zaehlSignatur'] = $this->ermittleZaehlsignatur($status);
				$this->schreibeStatus($status);
			}
		}

		$this->SendDebug('FilterMetadaten',
			sprintf(
				'plattform=%s datei=%s gesamt=%d typen=%d sender=%d dauerMs=%d',
				(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
				basename($logDatei),
				(int) ($ermittelt['gesamtZeilen'] ?? -1),
				count($ermittelt['verfuegbareFilterTypen'] ?? []),
				count($ermittelt['verfuegbareSender'] ?? []),
				$ladezeitMs
			),
			0
		);
		$this->aktualisiereVisualisierungNurStatus('ladeFilter-ende');
	}

    /**
     * zaehleTrefferAsynchron
     *
     * Ermittelt die Anzahl gefilterter Treffer.
     * - Nutzt vorhandene Cachewerte wenn möglich
     * - Schreibt das Ergebnis in den Modulstatus zurück
     *
     * Parameter: keine
     * Rückgabewert: void
     */
	private function zaehleTrefferAsynchron(): void
	{
		$modusPruefung = $this->pruefeModusVerwendbarkeit();
		if (!(bool) ($modusPruefung['ok'] ?? false)) {
			$this->SendDebug('ZaehleTreffer',
				sprintf(
					'modus-blockiert datei=%s meldung=%s',
					basename($this->ReadPropertyString('LogDatei')),
					(string) ($modusPruefung['fehlermeldung'] ?? '')
				),
				0
			);

			$status = $this->leseStatus();
			$status['zaehlungLaeuft'] = false;
			$this->schreibeStatus($status);
			$this->aktualisiereVisualisierung();
			return;
		}

		$status = $this->leseStatus();
		$logDatei = $this->ReadPropertyString('LogDatei');

		if (!is_file($logDatei)) {
			$aktuellerStatus = $this->leseStatus();
			$aktuellerStatus['trefferGesamt'] = 0;
			$aktuellerStatus['zaehlungLaeuft'] = false;
			$this->schreibeStatus($aktuellerStatus);
			$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-datei-fehlt');
			return;
		}

		$dateiGroesse = (int) filesize($logDatei);
		$dateiMTime = (int) filemtime($logDatei);
		$signatur = $this->ermittleZaehlsignatur($status);

		$cacheGueltig =
			((int) ($status['trefferGesamt'] ?? -1) >= 0) &&
			((int) ($status['dateiGroesseCache'] ?? 0) === $dateiGroesse) &&
			((int) ($status['dateiMTimeCache'] ?? 0) === $dateiMTime) &&
			((string) ($status['zaehlSignatur'] ?? '') === $signatur);

		if ($cacheGueltig) {
			$this->SendDebug(
				'ZaehleTreffer',
				sprintf(
					'plattform=%s datei=%s cache=status',
					(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
					basename($logDatei)
				),
				0
			);
			$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-cache-status');
			return;
		}

		if (!(bool) $this->hatAktiveFilter($status)) {
			$metaAnzeige = $this->leseFilterMetadatenFuerAnzeige();
			$gesamtMeta = (int) ($metaAnzeige['gesamtZeilen'] ?? -1);

			if ((bool) ($metaAnzeige['geladen'] ?? false) && $gesamtMeta >= 0) {
				$aktuellerStatus = $this->leseStatus();
				$aktuellerStatus['trefferGesamt'] = $gesamtMeta;
				$aktuellerStatus['zaehlungLaeuft'] = false;
				$aktuellerStatus['dateiGroesseCache'] = $dateiGroesse;
				$aktuellerStatus['dateiMTimeCache'] = $dateiMTime;
				$aktuellerStatus['zaehlSignatur'] = $signatur;
				$this->schreibeStatus($aktuellerStatus);

				$this->SendDebug(
					'ZaehleTreffer',
					sprintf(
						'plattform=%s datei=%s cache=filtermeta anzahl=%d',
						(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
						basename($logDatei),
						$gesamtMeta
					),
					0
				);

				$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-cache-filtermeta');
				return;
			}
		}

		if ($this->hatAktiveFilter($status)) {
			$seitenCache = $this->leseSeitenCache();
			$seitenCacheGueltig =
				((string) ($seitenCache['zaehlSignatur'] ?? '') === $signatur) &&
				((int) ($seitenCache['dateiGroesseCache'] ?? 0) === $dateiGroesse) &&
				((int) ($seitenCache['dateiMTimeCache'] ?? 0) === $dateiMTime) &&
				((int) ($seitenCache['trefferGesamt'] ?? -1) >= 0);

			if ($seitenCacheGueltig) {
				$anzahl = (int) $seitenCache['trefferGesamt'];

				$aktuellerStatus = $this->leseStatus();
				$aktuellerStatus['trefferGesamt'] = $anzahl;
				$aktuellerStatus['zaehlungLaeuft'] = false;
				$aktuellerStatus['dateiGroesseCache'] = $dateiGroesse;
				$aktuellerStatus['dateiMTimeCache'] = $dateiMTime;
				$aktuellerStatus['zaehlSignatur'] = $signatur;
				$this->schreibeStatus($aktuellerStatus);

				$this->SendDebug(
					'ZaehleTreffer',
					sprintf(
						'plattform=%s datei=%s cache=seiten anzahl=%d',
						(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
						basename($logDatei),
						$anzahl
					),
					0
				);

				$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-cache-seiten');
				return;
			}
		}

		if ((bool) ($status['zaehlungLaeuft'] ?? false)) {
			$this->SendDebug(
				'ZaehleTreffer',
				sprintf(
					'plattform=%s datei=%s status=laeuft-bereits',
					(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
					basename($logDatei)
				),
				0
			);
			return;
		}

		$aktuellerStatus = $this->leseStatus();
		$aktuellerStatus['zaehlungLaeuft'] = true;
		$this->schreibeStatus($aktuellerStatus);
		$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-start');

		$start = microtime(true);
		$anzahl = $this->zaehleGefilterteZeilen($status);
		$dauerMs = (int) round((microtime(true) - $start) * 1000);

		$status = $this->leseStatus();
		$status['trefferGesamt'] = $anzahl;
		$status['zaehlungLaeuft'] = false;
		$status['dateiGroesseCache'] = $dateiGroesse;
		$status['dateiMTimeCache'] = $dateiMTime;
		$status['zaehlSignatur'] = $signatur;

		$this->schreibeStatus($status);

		$this->SendDebug('ZaehleTreffer',
			sprintf(
				'plattform=%s datei=%s anzahl=%d dauerMs=%d',
				(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
				basename($logDatei),
				$anzahl,
				$dauerMs
			),
			0
		);
		$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-ende');
	}

    /**
     * ladeLogZeilen
     *
     * Lädt Logzeilen abhängig vom aktiven Betriebsmodus.
     * - Leitet an Standard- oder Systempfad weiter
     * - Liefert Zeilen, Treffer und Seitendaten zurück
     *
     * Parameter: array $status
     * Rückgabewert: array
     */
	private function ladeLogZeilen(array $status): array
	{
		$modus = $this->ermittleAktivenModus();

		if ($modus === 'standard') {
			return $this->ladeLogZeilenStandard($status);
		}

		if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
			return $this->ladeLogZeilenWindows($status);
		}

		$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
		$seite = max(0, (int) $status['seite']);

		$take = (($seite + 1) * $maxZeilen) + 1;
		$head = $maxZeilen + 1;

		$befehl = $this->baueShellBefehl($status, $take, $head);

		$start = microtime(true);
		$ausgabe = [];
		$rueckgabeCode = 0;
		exec($befehl, $ausgabe, $rueckgabeCode);
		$dauerMs = (int) round((microtime(true) - $start) * 1000);

		if ($rueckgabeCode > 1) {
			$this->SendDebug(
				'ladeLogZeilen FEHLER',
				'plattform=linux rc=' . $rueckgabeCode . ' dauerMs=' . $dauerMs,
				0
			);

			return [
				'ok'            => false,
				'fehlermeldung' => 'Fehler beim Ausführen des Shell-Befehls. Rückgabecode: ' . $rueckgabeCode,
				'zeilen'        => [],
				'hatWeitere'    => false
			];
		}

		$zeilen = [];
		$verworfen = 0;
		$verworfenBeispiele = [];

		foreach ($ausgabe as $zeile) {
			$parsed = $this->parseLogZeile($zeile);
			if ($parsed === null) {
				$verworfen++;

				if (count($verworfenBeispiele) < 5) {
					$beispiel = trim($zeile);
					if (mb_strlen($beispiel, 'UTF-8') > 140) {
						$beispiel = mb_substr($beispiel, 0, 140, 'UTF-8') . '…';
					}
					$verworfenBeispiele[] = $beispiel;
				}
				continue;
			}

			$zeilen[] = $parsed;
		}

		$hatWeitere = count($zeilen) > $maxZeilen;
		if ($hatWeitere) {
			array_pop($zeilen);
		}

		$logDatei = $this->ReadPropertyString('LogDatei');
		$dateiGroesse = is_file($logDatei) ? (int) filesize($logDatei) : 0;
		$dateiMTime = is_file($logDatei) ? (int) filemtime($logDatei) : 0;
		$listenSignatur = $this->ermittleListenCacheSignatur($status);
		$zaehlSignatur = $this->ermittleZaehlsignatur($status);

		$this->schreibeSeitenCache([
			'listenSignatur'    => $listenSignatur,
			'zaehlSignatur'     => $zaehlSignatur,
			'dateiGroesseCache' => $dateiGroesse,
			'dateiMTimeCache'   => $dateiMTime,
			'trefferGesamt'     => (int) ($status['trefferGesamt'] ?? -1),
			'hatWeitere'        => $hatWeitere,
			'zeilen'            => $zeilen
		]);

		$this->SendDebug(
			'ladeLogZeilen',
			sprintf(
				'plattform=linux datei=%s seite=%d maxZeilen=%d raw=%d parsed=%d verworfen=%d hatFilter=%s hatWeitere=%s dauerMs=%d cache=geschrieben',
				basename($logDatei),
				$seite,
				$maxZeilen,
				count($ausgabe),
				count($zeilen),
				$verworfen,
				$this->hatAktiveFilter($status) ? 'true' : 'false',
				$hatWeitere ? 'true' : 'false',
				$dauerMs
			),
			0
		);

		if ($verworfen > 0) {
			$this->SendDebug(
				'ladeLogZeilen verworfen',
				json_encode($verworfenBeispiele, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
				0
			);
		}

		return [
			'ok'            => true,
			'fehlermeldung' => '',
			'zeilen'        => $zeilen,
			'hatWeitere'    => $hatWeitere
		];
	}

    /**
     * zaehleGefilterteZeilen
     *
     * Zählt gefilterte Logzeilen abhängig vom aktiven Betriebsmodus.
     * - Leitet an Standard- oder Systempfad weiter
     * - Liefert die Anzahl passender Einträge zurück
     *
     * Parameter: array $status
     * Rückgabewert: int
     */
	private function zaehleGefilterteZeilen(array $status): int
	{
		$modus = $this->ermittleAktivenModus();

		if ($modus === 'standard') {
			return $this->zaehleGefilterteZeilenStandard($status);
		}

		if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
			return $this->zaehleGefilterteZeilenWindows($status);
		}

		$befehl = $this->baueZaehlBefehl($status);

		$start = microtime(true);
		$ausgabe = [];
		$rc = 0;
		exec($befehl, $ausgabe, $rc);
		$dauerMs = (int) round((microtime(true) - $start) * 1000);

		if ($rc > 1 || !isset($ausgabe[0])) {
			$this->SendDebug('ZaehleTrefferFehler', 'plattform=linux rc=' . $rc . ' dauerMs=' . $dauerMs, 0);
			return 0;
		}
		return (int) trim($ausgabe[0]);
	}

    /**
     * ermittleFilterMetadaten
     *
     * Ermittelt Filtertypen, Sender und Gesamtmenge zur Logdatei.
     * - Leitet an Standard- oder Systempfad weiter
     * - Liefert Metadaten für die Filteranzeige zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function ermittleFilterMetadaten(): array
	{
		$modus = $this->ermittleAktivenModus();

		if ($modus === 'standard') {
			return $this->ermittleFilterMetadatenStandard();
		}
		
		// Prüfen welches Betriebssystem im EInsatz. Es wird nur windows
		// unterschieden, alles andere wie Linux, bis auf Tac wird nur auf echtem Linux und sogar nicht in Docker einegsetzt
		if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
			return $this->ermittleWindowsMetadatenUndGesamtmenge();
		}

		$befehl = $this->baueFilterMetadatenBefehl();

		$start = microtime(true);
		$ausgabe = [];
		$rc = 0;
		exec($befehl, $ausgabe, $rc);
		$dauerMs = (int) round((microtime(true) - $start) * 1000);

		if ($rc > 1) {
			$this->SendDebug('FilterMetadatenFehler', 'plattform=linux rc=' . $rc . ' dauerMs=' . $dauerMs, 0);
			return [
				'verfuegbareFilterTypen' => [],
				'verfuegbareSender'      => [],
				'gesamtZeilen'           => -1
			];
		}

		$typen = [];
		$sender = [];
		$gesamtZeilen = -1;

		foreach ($ausgabe as $zeile) {
			$teile = explode("\t", $zeile, 2);
			if (count($teile) < 2) {
				continue;
			}

			$prefix = $teile[0];
			$wert = trim($teile[1]);

			if ($prefix === 'G') {
				$gesamtZeilen = (int) $wert;
				continue;
			}

			if ($wert === '') {
				continue;
			}

			if ($prefix === 'T') {
				$typen[] = $wert;
			} elseif ($prefix === 'S') {
				$sender[] = $wert;
			}
		}

		$typen = array_values(array_unique($typen));
		$sender = array_values(array_unique($sender));

		sort($typen, SORT_NATURAL | SORT_FLAG_CASE);
		sort($sender, SORT_NATURAL | SORT_FLAG_CASE);

		return [
			'verfuegbareFilterTypen' => $typen,
			'verfuegbareSender'      => $sender,
			'gesamtZeilen'           => $gesamtZeilen
		];
	}

    /**
     * ermittleListenCacheSignatur
     *
     * Erzeugt eine Signatur für den Seiten- und Listen-Cache.
     * - Berücksichtigt Datei, Seite und aktive Filter
     * - Dient zur Prüfung auf gültige Cacheeinträge
     *
     * Parameter: array $status
     * Rückgabewert: string
     */
	private function ermittleListenCacheSignatur(array $status): string
	{
		return md5(json_encode([
			'logDatei'        => $this->ReadPropertyString('LogDatei'),
			'seite'           => max(0, (int) ($status['seite'] ?? 0)),
			'maxZeilen'       => $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50)),
			'filterTypen'     => $this->normalisiereFilterTypen($status['filterTypen'] ?? []),
			'objektIdFilter'  => $this->normalisiereObjektIdFilterString((string) ($status['objektIdFilter'] ?? '')),
			'senderFilter'    => $this->normalisiereSenderFilter($status['senderFilter'] ?? []),
			'textFilter'      => trim((string) ($status['textFilter'] ?? ''))
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}


    /**
     * leseSeitenCache
     *
     * Liest den gespeicherten Seiten-Cache aus dem Attribut.
     * - Dekodiert die Cachedaten aus JSON
     * - Gibt eine normalisierte Cache-Struktur zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function leseSeitenCache(): array
	{
		$json = $this->ReadAttributeString(self::ATTR_SEITENCACHE);
		$daten = $this->dekodiereJsonArray($json);

		return [
			'listenSignatur'    => (string) ($daten['listenSignatur'] ?? ''),
			'zaehlSignatur'     => (string) ($daten['zaehlSignatur'] ?? ''),
			'dateiGroesseCache' => (int) ($daten['dateiGroesseCache'] ?? 0),
			'dateiMTimeCache'   => (int) ($daten['dateiMTimeCache'] ?? 0),
			'trefferGesamt'     => (int) ($daten['trefferGesamt'] ?? -1),
			'hatWeitere'        => (bool) ($daten['hatWeitere'] ?? false),
			'zeilen'            => is_array($daten['zeilen'] ?? null) ? array_values($daten['zeilen']) : []
		];
	}

    /**
     * schreibeSeitenCache
     *
     * Schreibt den Seiten-Cache in das Modulattribut.
     * - Speichert Signatur, Dateistand und geladene Zeilen
     * - Normalisiert die Struktur vor dem Speichern
     *
     * Parameter: array $cache
     * Rückgabewert: void
     */
	private function schreibeSeitenCache(array $cache): void
	{
		$this->WriteAttributeString(
			self::ATTR_SEITENCACHE,
			json_encode([
				'listenSignatur'    => (string) ($cache['listenSignatur'] ?? ''),
				'zaehlSignatur'     => (string) ($cache['zaehlSignatur'] ?? ''),
				'dateiGroesseCache' => (int) ($cache['dateiGroesseCache'] ?? 0),
				'dateiMTimeCache'   => (int) ($cache['dateiMTimeCache'] ?? 0),
				'trefferGesamt'     => (int) ($cache['trefferGesamt'] ?? -1),
				'hatWeitere'        => (bool) ($cache['hatWeitere'] ?? false),
				'zeilen'            => is_array($cache['zeilen'] ?? null) ? array_values($cache['zeilen']) : []
			], JSON_THROW_ON_ERROR)
		);
	}

    /**
     * leereSeitenCache
     *
     * Setzt den Seiten-Cache auf einen leeren Zustand zurück.
     * - Entfernt gespeicherte Zeilen und Trefferwerte
     * - Initialisiert die Cachefelder neu
     *
     * Parameter: keine
     * Rückgabewert: void
     */
	private function leereSeitenCache(): void
	{
		$this->schreibeSeitenCache([
			'listenSignatur'    => '',
			'zaehlSignatur'     => '',
			'dateiGroesseCache' => 0,
			'dateiMTimeCache'   => 0,
			'trefferGesamt'     => -1,
			'hatWeitere'        => false,
			'zeilen'            => []
		]);
	}

    /**
     * leseStatus
     *
     * Liest den aktuellen Visualisierungsstatus aus dem Attribut.
     * - Dekodiert gespeicherte Zustandsdaten aus JSON
     * - Gibt eine normalisierte Statusstruktur zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function leseStatus(): array
	{
		$json = $this->ReadAttributeString(self::ATTR_STATUS);
		$daten = $this->dekodiereJsonArray($json);

		return [
			'seite'                    => max(0, (int) ($daten['seite'] ?? 0)),
			'maxZeilen'                => $this->normalisiereMaxZeilen((int) ($daten['maxZeilen'] ?? $this->ReadPropertyInteger('MaxZeilen'))),
			'theme'                    => $this->normalisiereTheme((string) ($daten['theme'] ?? 'dark')),
			'kompakt'                  => $this->normalisiereKompakt($daten['kompakt'] ?? false),
			'filterTypen'              => $this->normalisiereFilterTypen($daten['filterTypen'] ?? []),
			'objektIdFilter'           => $this->normalisiereObjektIdFilterString((string) ($daten['objektIdFilter'] ?? '')),
			'senderFilter'             => $this->normalisiereSenderFilter($daten['senderFilter'] ?? []),
			'textFilter'               => trim((string) ($daten['textFilter'] ?? '')),
			'trefferGesamt'            => (int) ($daten['trefferGesamt'] ?? -1),
			'zaehlungLaeuft'           => (bool) ($daten['zaehlungLaeuft'] ?? false),
			'dateiGroesseCache'        => (int) ($daten['dateiGroesseCache'] ?? 0),
			'dateiMTimeCache'          => (int) ($daten['dateiMTimeCache'] ?? 0),
			'zaehlSignatur'            => (string) ($daten['zaehlSignatur'] ?? ''),
			'tabellenLadungLaeuft'     => (bool) ($daten['tabellenLadungLaeuft'] ?? false),
			'tabellenLadungText'       => trim((string) ($daten['tabellenLadungText'] ?? '')),
			'letzteTabellenLadezeitMs' => max(0, (int) ($daten['letzteTabellenLadezeitMs'] ?? 0))
		];
	}

    /**
     * schreibeStatus
     *
     * Schreibt den aktuellen Visualisierungsstatus in das Attribut.
     * - Normalisiert die Werte vor dem Speichern
     * - Persistiert den Status als JSON
     *
     * Parameter: array $status
     * Rückgabewert: void
     */
	private function schreibeStatus(array $status): void
	{
		$this->WriteAttributeString(
			self::ATTR_STATUS,
			json_encode([
				'seite'                    => max(0, (int) ($status['seite'] ?? 0)),
				'maxZeilen'                => $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50)),
				'theme'                    => $this->normalisiereTheme((string) ($status['theme'] ?? 'dark')),
				'kompakt'                  => $this->normalisiereKompakt($status['kompakt'] ?? false),
				'filterTypen'              => $this->normalisiereFilterTypen($status['filterTypen'] ?? []),
				'objektIdFilter'           => $this->normalisiereObjektIdFilterString((string) ($status['objektIdFilter'] ?? '')),
				'senderFilter'             => $this->normalisiereSenderFilter($status['senderFilter'] ?? []),
				'textFilter'               => trim((string) ($status['textFilter'] ?? '')),
				'trefferGesamt'            => (int) ($status['trefferGesamt'] ?? -1),
				'zaehlungLaeuft'           => (bool) ($status['zaehlungLaeuft'] ?? false),
				'dateiGroesseCache'        => (int) ($status['dateiGroesseCache'] ?? 0),
				'dateiMTimeCache'          => (int) ($status['dateiMTimeCache'] ?? 0),
				'zaehlSignatur'            => (string) ($status['zaehlSignatur'] ?? ''),
				'tabellenLadungLaeuft'     => (bool) ($status['tabellenLadungLaeuft'] ?? false),
				'tabellenLadungText'       => trim((string) ($status['tabellenLadungText'] ?? '')),
				'letzteTabellenLadezeitMs' => max(0, (int) ($status['letzteTabellenLadezeitMs'] ?? 0))
			], JSON_THROW_ON_ERROR)
		);
	}


    /**
     * setzeTabellenLadezustand
     *
     * Setzt den Ladezustand der Tabellenanzeige.
     * - Aktualisiert Sichtbarkeit und Text des Ladehinweises
     * - Speichert den Zustand im Modulstatus
     *
     * Parameter: bool $laeuft, string $text, string $quelle
     * Rückgabewert: void
     */
	private function setzeTabellenLadezustand(bool $laeuft, string $text = '', string $quelle = ''): void
	{
		$status = $this->leseStatus();
		$status['tabellenLadungLaeuft'] = $laeuft;
		$status['tabellenLadungText'] = $laeuft ? trim($text) : '';
		$this->schreibeStatus($status);

		$this->SendDebug('Ladebalken',
			sprintf(
				'quelle=%s sichtbar=%s text=%s',
				$quelle !== '' ? $quelle : '-',
				$laeuft ? 'true' : 'false',
				$laeuft ? trim($text) : '-'
			),
			0
		);
	}

    /**
     * leseFilterMetadatenRoh
     *
     * Liest die gespeicherten Filtermetadaten unverändert aus.
     * - Dekodiert das Attribut für Filterinformationen
     * - Gibt die Rohwerte in normalisierter Form zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function leseFilterMetadatenRoh(): array
	{
		$json = $this->ReadAttributeString(self::ATTR_FILTERMETA);
		$daten = $this->dekodiereJsonArray($json);

		return [
			'verfuegbareFilterTypen' => is_array($daten['verfuegbareFilterTypen'] ?? null) ? array_values($daten['verfuegbareFilterTypen']) : [],
			'verfuegbareSender'      => is_array($daten['verfuegbareSender'] ?? null) ? array_values($daten['verfuegbareSender']) : [],
			'gesamtZeilenCache'      => (int) ($daten['gesamtZeilenCache'] ?? -1),
			'dateiGroesseCache'      => (int) ($daten['dateiGroesseCache'] ?? 0),
			'dateiMTimeCache'        => (int) ($daten['dateiMTimeCache'] ?? 0),
			'ladezeitMs'             => (int) ($daten['ladezeitMs'] ?? 0),
			'laedt'                  => (bool) ($daten['laedt'] ?? false),
			'signatur'               => (string) ($daten['signatur'] ?? '')
		];
	}


    /**
     * leseFilterMetadatenFuerAnzeige
     *
     * Bereitet die Filtermetadaten für die Anzeige auf.
     * - Prüft Dateistand und Metadaten-Signatur
     * - Liefert nur gültige Werte für die Oberfläche zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function leseFilterMetadatenFuerAnzeige(): array
	{
		$roh = $this->leseFilterMetadatenRoh();
		$logDatei = $this->ReadPropertyString('LogDatei');
		$status = $this->leseStatus();

		if (!is_file($logDatei)) {
			return [
				'verfuegbareFilterTypen' => [],
				'verfuegbareSender'      => [],
				'gesamtZeilen'           => -1,
				'geladen'                => false,
				'laedt'                  => (bool) $roh['laedt'],
				'ladezeitMs'             => (int) $roh['ladezeitMs']
			];
		}

		$dateiGroesse = (int) filesize($logDatei);
		$dateiMTime = (int) filemtime($logDatei);
		$signatur = $this->ermittleFilterMetadatenSignatur($status);

		$geladen =
			((int) $roh['dateiGroesseCache'] === $dateiGroesse) &&
			((int) $roh['dateiMTimeCache'] === $dateiMTime) &&
			((string) $roh['signatur'] === $signatur);

		return [
			'verfuegbareFilterTypen' => $geladen ? array_values($roh['verfuegbareFilterTypen']) : [],
			'verfuegbareSender'      => $geladen ? array_values($roh['verfuegbareSender']) : [],
			'gesamtZeilen'           => $geladen ? (int) $roh['gesamtZeilenCache'] : -1,
			'geladen'                => $geladen,
			'laedt'                  => (bool) $roh['laedt'],
			'ladezeitMs'             => (int) $roh['ladezeitMs']
		];
	}

    /**
     * schreibeFilterMetadaten
     *
     * Schreibt Filtermetadaten in das Modulattribut.
     * - Speichert verfügbare Filterwerte und Cacheinformationen
     * - Normalisiert die Daten vor dem Schreiben
     *
     * Parameter: array $meta
     * Rückgabewert: void
     */
	private function schreibeFilterMetadaten(array $meta): void
	{
		$this->WriteAttributeString(
			self::ATTR_FILTERMETA,
			json_encode([
				'verfuegbareFilterTypen' => is_array($meta['verfuegbareFilterTypen'] ?? null) ? array_values($meta['verfuegbareFilterTypen']) : [],
				'verfuegbareSender'      => is_array($meta['verfuegbareSender'] ?? null) ? array_values($meta['verfuegbareSender']) : [],
				'gesamtZeilenCache'      => (int) ($meta['gesamtZeilenCache'] ?? -1),
				'dateiGroesseCache'      => (int) ($meta['dateiGroesseCache'] ?? 0),
				'dateiMTimeCache'        => (int) ($meta['dateiMTimeCache'] ?? 0),
				'ladezeitMs'             => (int) ($meta['ladezeitMs'] ?? 0),
				'laedt'                  => (bool) ($meta['laedt'] ?? false),
				'signatur'               => (string) ($meta['signatur'] ?? '')
			], JSON_THROW_ON_ERROR)
		);
	}

    /**
     * hatAktiveFilter
     *
     * Prüft, ob im Status aktive Filter gesetzt sind.
     * - Berücksichtigt Typ, Objekt-ID, Sender und Text
     * - Liefert true bei mindestens einem aktiven Filter
     *
     * Parameter: array $status
     * Rückgabewert: bool
     */
	private function hatAktiveFilter(array $status): bool
	{
		return
			count($this->normalisiereFilterTypen($status['filterTypen'] ?? [])) > 0 ||
			count($this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''))) > 0 ||
			count($this->normalisiereSenderFilter($status['senderFilter'] ?? [])) > 0 ||
			trim((string) ($status['textFilter'] ?? '')) !== '';
	}

    /**
     * ermittleAktivenModus
     *
     * Ermittelt den aktuell konfigurierten Betriebsmodus.
     * - Liest die Moduleigenschaft Betriebsmodus aus
     * - Gibt nur gültige Moduswerte zurück
     *
     * Parameter: keine
     * Rückgabewert: string
     */
	private function ermittleAktivenModus(): string
	{
		$modus = strtolower(trim($this->ReadPropertyString('Betriebsmodus')));

		return in_array($modus, ['standard', 'system', 'ultra'], true)
			? $modus
			: 'standard';
	}

    /**
     * pruefeModusVerwendbarkeit
     *
     * Prüft, ob der aktuelle Betriebsmodus verwendet werden kann.
     * - Validiert Logdatei, Modusstatus und Größenbeschränkungen
     * - Liefert Status und Fehlermeldung zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function pruefeModusVerwendbarkeit(): array
	{
		$modus = $this->ermittleAktivenModus();
		$logDatei = $this->ReadPropertyString('LogDatei');

		if (!is_file($logDatei)) {
			return [
				'ok' => false,
				'fehlermeldung' => 'Logdatei nicht gefunden: ' . $logDatei
			];
		}

		if ($modus === 'ultra') {
			return [
				'ok' => false,
				'fehlermeldung' => 'Der Modus Ultra ist noch in Bearbeitung.'
			];
		}

		if ($modus === 'standard') {
			$dateiGroesse = (int) filesize($logDatei);
			$grenze = 6 * 1024 * 1024;

			if ($dateiGroesse > $grenze) {
				return [
					'ok' => false,
					'fehlermeldung' => 'Die ausgewählte Logdatei ist größer als 6 MB. Bitte verwenden Sie den Modus System.'
				];
			}
		}

		return [
			'ok' => true,
			'fehlermeldung' => ''
		];
	}

    /**
     * ermittleZaehlsignatur
     *
     * Erzeugt eine Signatur für Trefferzählungen.
     * - Berücksichtigt die aktiven Filterwerte
     * - Dient zur Prüfung auf gültige Zählergebnisse
     *
     * Parameter: array $status
     * Rückgabewert: string
     */
    private function ermittleZaehlsignatur(array $status): string
    {
        return md5(json_encode([
            'filterTypen'    => $this->normalisiereFilterTypen($status['filterTypen'] ?? []),
            'objektIdFilter' => $this->normalisiereObjektIdFilterString((string) ($status['objektIdFilter'] ?? '')),
            'senderFilter'   => $this->normalisiereSenderFilter($status['senderFilter'] ?? []),
            'textFilter'     => trim((string) ($status['textFilter'] ?? ''))
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * ermittleFilterMetadatenSignatur
     *
     * Erzeugt eine Signatur für Filtermetadaten.
     * - Berücksichtigt Modus, Datei und relevante Filter
     * - Dient zur Prüfung auf gültige Metadaten
     *
     * Parameter: array $status
     * Rückgabewert: string
     */
	private function ermittleFilterMetadatenSignatur(array $status): string
	{
		$modus = $this->ermittleAktivenModus();

		if ($modus !== 'standard') {
			return md5(json_encode([
				'modus'   => $modus,
				'logDatei'=> $this->ReadPropertyString('LogDatei')
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		}

		return md5(json_encode([
			'modus'          => $modus,
			'logDatei'       => $this->ReadPropertyString('LogDatei'),
			'objektIdFilter' => $this->normalisiereObjektIdFilterString((string) ($status['objektIdFilter'] ?? '')),
			'textFilter'     => trim((string) ($status['textFilter'] ?? '')),
			'filterTypen'    => $this->normalisiereFilterTypen($status['filterTypen'] ?? []),
			'senderFilter'   => $this->normalisiereSenderFilter($status['senderFilter'] ?? [])
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

    /**
     * parseLogZeile
     *
     * Parst eine Logzeile in die Anzeigestruktur des Moduls.
     * - Extrahiert Felder und Uhrzeit aus der Zeile
     * - Gibt null bei ungültigem Format zurück
     *
     * Parameter: string $zeile
     * Rückgabewert: ?array
     */
	private function parseLogZeile(string $zeile): ?array
	{
		$teile = $this->extrahiereLogFelder($zeile);
		if ($teile === null) {
			return null;
		}

		$zeitstempel = $teile['zeitstempel'];
		$objektId = $teile['objektId'];
		$typ = $teile['typ'];
		$sender = $teile['sender'];
		$meldung = $teile['meldung'];

		preg_match('/(\d{2}:\d{2}:\d{2})/', $zeitstempel, $treffer);
		$uhrzeit = $treffer[1] ?? '';

		return [
			'zeitstempel' => $zeitstempel,
			'zeit'        => $uhrzeit,
			'objektId'    => $objektId,
			'typ'         => $typ,
			'sender'      => $sender,
			'meldung'     => $meldung
		];
	}

    /**
     * extrahiereLogFelder
     *
     * Zerlegt eine Logzeile in ihre einzelnen Felder.
     * - Erwartet das Pipe-getrennte Logformat
     * - Gibt null bei ungültigen oder leeren Zeilen zurück
     *
     * Parameter: string $zeile
     * Rückgabewert: ?array
     */
	private function extrahiereLogFelder(string $zeile): ?array
	{
		$zeile = trim($this->normalisiereUtf8String($zeile));
		if ($zeile === '') {
			return null;
		}

		$teile = explode('|', $zeile, 5);
		if (count($teile) < 5) {
			return null;
		}

		return [
			'zeitstempel' => trim($this->normalisiereUtf8String($teile[0])),
			'objektId'    => trim($this->normalisiereUtf8String($teile[1])),
			'typ'         => trim($this->normalisiereUtf8String($teile[2])),
			'sender'      => trim($this->normalisiereUtf8String($teile[3])),
			'meldung'     => trim($this->normalisiereUtf8String($teile[4]))
		];
	}

    /**
     * logZeileErfuelltFilter
     *
     * Prüft, ob eine Logzeile die aktiven Filterbedingungen erfüllt.
     * - Vergleicht Typ, Objekt-ID, Sender und Textfilter
     * - Liefert true bei vollständiger Übereinstimmung
     *
     * Parameter: array $felder, array $status
     * Rückgabewert: bool
     */
	private function logZeileErfuelltFilter(array $felder, array $status): bool
	{
		$filterTypen = $this->normalisiereFilterTypen($status['filterTypen'] ?? []);
		$objektIds = $this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''));
		$senderFilter = $this->normalisiereSenderFilter($status['senderFilter'] ?? []);
		$textFilter = trim((string) ($status['textFilter'] ?? ''));

		if (count($objektIds) > 0 && !in_array($felder['objektId'], $objektIds, true)) {
			return false;
		}

		if (count($filterTypen) > 0 && !in_array($felder['typ'], $filterTypen, true)) {
			return false;
		}

		if (count($senderFilter) > 0 && !in_array($felder['sender'], $senderFilter, true)) {
			return false;
		}

		if ($textFilter !== '' && mb_stripos($felder['meldung'], $textFilter, 0, 'UTF-8') === false) {
			return false;
		}
		return true;
	}


    /**
     * baueAnzeigeZeileAusFeldern
     *
     * Baut aus extrahierten Feldern eine Anzeigezeile auf.
     * - Ergänzt die gekürzte Uhrzeit für die Oberfläche
     * - Gibt die normalisierte Zeilenstruktur zurück
     *
     * Parameter: array $felder
     * Rückgabewert: array
     */
	private function baueAnzeigeZeileAusFeldern(array $felder): array
	{
		preg_match('/(\d{2}:\d{2}:\d{2})/', $felder['zeitstempel'], $treffer);
		$uhrzeit = $treffer[1] ?? '';

		return [
			'zeitstempel' => $felder['zeitstempel'],
			'zeit'        => $uhrzeit,
			'objektId'    => $felder['objektId'],
			'typ'         => $felder['typ'],
			'sender'      => $felder['sender'],
			'meldung'     => $felder['meldung']
		];
	}

    /**
     * normalisiereUtf8String
     *
     * Normalisiert einen String auf gültiges UTF-8.
     * - Prüft vorhandene Kodierung und konvertiert bei Bedarf
     * - Gibt den bereinigten String zurück
     *
     * Parameter: string $wert
     * Rückgabewert: string
     */
	private function normalisiereUtf8String(string $wert): string
	{
		if ($wert === '') {
			return '';
		}

		if (preg_match('//u', $wert) === 1) {
			return $wert;
		}

		if (function_exists('mb_convert_encoding')) {
			$konvertiert = @mb_convert_encoding($wert, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
			if (is_string($konvertiert) && $konvertiert !== '' && preg_match('//u', $konvertiert) === 1) {
				return $konvertiert;
			}
		}

		if (function_exists('iconv')) {
			$ersetzt = @iconv('UTF-8', 'UTF-8//IGNORE', $wert);
			if (is_string($ersetzt)) {
				return $ersetzt;
			}
		}

		return $wert;
	}

    /**
     * normalisiereMaxZeilen
     *
     * Prüft und begrenzt die zulässige Anzahl an Tabellenzeilen.
     * - Akzeptiert nur definierte Werte aus der Auswahlliste
     * - Verwendet einen Standardwert bei ungültiger Eingabe
     *
     * Parameter: int $wert
     * Rückgabewert: int
     */
	private function normalisiereMaxZeilen(int $wert): int
	{
		$erlaubt = [20, 50, 100, 200, 500, 1000, 2000, 3000];
		return in_array($wert, $erlaubt, true) ? $wert : 50;
	}

    /**
     * normalisiereFilterTypen
     *
     * Normalisiert die Liste ausgewählter Filtertypen.
     * - Entfernt leere und doppelte Einträge
     * - Gibt eine bereinigte Werteliste zurück
     *
     * Parameter: mixed $filterTypen
     * Rückgabewert: array
     */
    private function normalisiereFilterTypen(mixed $filterTypen): array
    {
        if (!is_array($filterTypen)) {
            return [];
        }
        $ergebnis = [];
        foreach ($filterTypen as $typ) {
            $typ = trim((string) $typ);
            if ($typ === '') {
                continue;
            }
            $ergebnis[] = $typ;
        }
        return array_values(array_unique($ergebnis));
    }

    /**
     * normalisiereSenderFilter
     *
     * Normalisiert die Liste ausgewählter Senderfilter.
     * - Akzeptiert String oder Array als Eingabe
     * - Entfernt leere und doppelte Einträge
     *
     * Parameter: mixed $senderFilter
     * Rückgabewert: array
     */
    private function normalisiereSenderFilter(mixed $senderFilter): array
    {
        if (is_string($senderFilter)) {
            $senderFilter = [$senderFilter];
        }
        if (!is_array($senderFilter)) {
            return [];
        }
        $ergebnis = [];
        foreach ($senderFilter as $sender) {
            $sender = trim((string) $sender);
            if ($sender === '') {
                continue;
            }
            $ergebnis[] = $sender;
        }
        return array_values(array_unique($ergebnis));
    }

    /**
     * normalisiereObjektIdFilterListe
     *
     * Zerlegt den Objekt-ID-Filter in eine bereinigte Liste.
     * - Trennt Eingaben nach Leerzeichen, Komma oder Semikolon
     * - Begrenzt die Anzahl der übernommenen Werte
     *
     * Parameter: string $wert
     * Rückgabewert: array
     */
    private function normalisiereObjektIdFilterListe(string $wert): array
    {
        $teile = preg_split('/[\s,;]+/', trim($wert)) ?: [];
        $ergebnis = [];
        foreach ($teile as $teil) {
            $teil = trim($teil);
            if ($teil === '') {
                continue;
            }
            $ergebnis[] = $teil;
            if (count($ergebnis) >= 10) {
                break;
            }
        }
        return array_values(array_unique($ergebnis));
    }

    /**
     * normalisiereObjektIdFilterString
     *
     * Normalisiert den Objekt-ID-Filter als Zeichenkette.
     * - Bereitet die Liste intern auf
     * - Gibt die Werte als kommagetrennten String zurück
     *
     * Parameter: string $wert
     * Rückgabewert: string
     */
    private function normalisiereObjektIdFilterString(string $wert): string
    {
        return implode(', ', $this->normalisiereObjektIdFilterListe($wert));
    }

    /**
     * normalisiereTheme
     *
     * Normalisiert das gewählte Farbschema der Anzeige.
     * - Akzeptiert nur gültige Theme-Werte
     * - Verwendet dark als Standardwert
     *
     * Parameter: string $theme
     * Rückgabewert: string
     */
	private function normalisiereTheme(string $theme): string
	{
		$theme = strtolower(trim($theme));
		return in_array($theme, ['dark', 'light'], true) ? $theme : 'dark';
	}

    /**
     * normalisiereKompakt
     *
     * Normalisiert den Kompaktmodus der Anzeige.
     * - Wandelt den übergebenen Wert in bool um
     * - Liefert den bereinigten Zustand zurück
     *
     * Parameter: mixed $wert
     * Rückgabewert: bool
     */
	private function normalisiereKompakt(mixed $wert): bool
	{
		return (bool) $wert;
	}

    /**
     * dekodiereJsonArray
     *
     * Dekodiert einen JSON-String in ein Array.
     * - Gibt bei Fehlern ein leeres Array zurück
     * - Protokolliert JSON-Fehler als Debugmeldung
     *
     * Parameter: string $json
     * Rückgabewert: array
     */
    private function dekodiereJsonArray(string $json): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $daten = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($daten) ? $daten : [];
        } catch (\Throwable $e) {
            $this->SendDebug('JSON-Fehler', $e->getMessage(), 0);
            return [];
        }
    }

    /**
     * formatiereDateigroesse
     *
     * Formatiert eine Dateigröße in eine lesbare Darstellung.
     * - Wandelt Bytes in passende Einheiten um
     * - Gibt den formatierten Text zurück
     *
     * Parameter: int $bytes
     * Rückgabewert: string
     */
    private function formatiereDateigroesse(int $bytes): string
    {
        $einheiten = ['B', 'KB', 'MB', 'GB', 'TB'];
        $wert = (float) $bytes;
        $index = 0;
        while ($wert >= 1024 && $index < count($einheiten) - 1) {
            $wert /= 1024;
            $index++;
        }
        return number_format($wert, 2, ',', '.') . ' ' . $einheiten[$index];
    }

    /**
     * ermittleVerfuegbareLogdateien
     *
     * Ermittelt verfügbare Logdateien im Logverzeichnis.
     * - Sammelt Dateiinformationen und Anzeigetexte
     * - Sortiert die Dateien nach Zeitstempel absteigend
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function ermittleVerfuegbareLogdateien(): array
	{
		$logDir = rtrim(IPS_GetLogDir(), DIRECTORY_SEPARATOR);
		$muster = $logDir . DIRECTORY_SEPARATOR . 'logfile*.log';
		$dateien = glob($muster);

		if ($dateien === false) {
			return [];
		}

		$ergebnis = [];

		foreach ($dateien as $datei) {
			if (!is_file($datei)) {
				continue;
			}

			$basename = basename($datei);
			$unixzeit = $this->extrahiereUnixzeitAusDateiname($basename);
			$mtime = (int) filemtime($datei);
			$groesseBytes = (int) filesize($datei);
			$groesseFormatiert = $this->formatiereDateigroesse($groesseBytes);

			$ergebnis[] = [
				'pfad'      => $datei,
				'dateiname' => $basename,
				'anzeige'   => $this->formatiereLogdateiAnzeige($basename, $unixzeit, $groesseFormatiert),
				'unixzeit'  => $unixzeit,
				'mtime'     => $mtime,
				'groesse'   => $groesseFormatiert
			];
		}

		usort($ergebnis, static function (array $a, array $b): int {
			$zeitA = (int) ($a['unixzeit'] ?: $a['mtime']);
			$zeitB = (int) ($b['unixzeit'] ?: $b['mtime']);

			return $zeitB <=> $zeitA;
		});

		return $ergebnis;
	}

    /**
     * extrahiereUnixzeitAusDateiname
     *
     * Liest einen Unix-Zeitstempel aus dem Logdateinamen aus.
     * - Prüft das erwartete Namensmuster der Datei
     * - Gibt 0 zurück, wenn kein Zeitstempel gefunden wurde
     *
     * Parameter: string $dateiname
     * Rückgabewert: int
     */
	private function extrahiereUnixzeitAusDateiname(string $dateiname): int
	{
		if (preg_match('/^logfile(\d{9,})\.log$/', $dateiname, $treffer) === 1) {
			return (int) $treffer[1];
		}
		return 0;
	}

    /**
     * formatiereLogdateiAnzeige
     *
     * Erzeugt den Anzeigetext für eine Logdatei.
     * - Formatiert Zeitstempel und Dateigröße lesbar
     * - Gibt den fertigen Auswahltext zurück
     *
     * Parameter: string $dateiname, int $unixzeit, string $groesseFormatiert
     * Rückgabewert: string
     */
	private function formatiereLogdateiAnzeige(string $dateiname, int $unixzeit, string $groesseFormatiert = ''): string
	{
		if ($unixzeit > 0) {
			$anzeige = 'log-' . date('Y.m.d-H:i:s', $unixzeit);
		} else {
			$anzeige = $dateiname;
		}
		if ($groesseFormatiert !== '') {
			$anzeige .= ' · ' . $groesseFormatiert;
		}
		return $anzeige;
	}

}
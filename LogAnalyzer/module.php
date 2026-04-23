<?php
/**
 * NEUSTART des Moduls: MC_ReloadModule(59139, "LogAnalyzer");
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
require_once __DIR__ . '/libs/LogAnalyzerUltraTrait.php';

class LogAnalyzerIPSView extends IPSModuleStrict
{
	use LogAnalyzerStandardTrait;
	use LogAnalyzerSystemTrait;
	use LogAnalyzerUltraTrait;

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

		$this->RegisterAttributeString('AktuelleLogDatei', '');
		$this->RegisterPropertyString('LogDatei', IPS_GetLogDir() . 'logfile.log');		// nur zu Initialisierung, wird später überschrieben
		$this->RegisterPropertyInteger('MaxZeilen', 50);
		$this->RegisterPropertyBoolean('VerwendeSift', false);
		$this->RegisterPropertyInteger('AutoRefreshSekunden', 0);
		$this->RegisterPropertyString('Betriebsmodus', 'standard');

		$this->RegisterAttributeString(self::ATTR_STATUS, json_encode([
			'seite'                    => 0,
			'maxZeilen'                => 50,
			'theme'                    => 'dark',
			'kompakt'                  => false,
			'schriftgroesse'           => 12,
			'kompakt'                  => false,
			'autoRefreshSek'           => 0,
			'zeitVon'                  => '',
			'zeitBis'                  => '',
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

		// HTML-Box Variable für IPSView
		$this->RegisterVariableString('HTMLBOX', 'Log Anzeige', '~HTMLBox');

		// Visu Aktulisieren
		$this->RegisterTimer('VisualisierungAktualisieren',0,'LOGANALYZER_AktualisierenVisualisierung($_IPS["TARGET"]);');
	}

    public function Destroy(): void
    {
		// nicht löschen
        parent::Destroy();
		// Script wird von IPS automatisch mit der Instanz gelöscht (Kind-Objekt)
		// Kein manuelles Löschen nötig - würde bei Updates zu ID-Wechsel führen
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

		// Tile Visu deaktiviert (IPSView HTML-Box)
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

		// Hook-Script anlegen/aktualisieren
		$this->erstelleOderAktualisierHookScript();

		// iframe in HTMLBOX setzen
		$hookUrl = '/hook/LogAnalyzerIPSView_' . $this->InstanceID;
		$this->SetValue('HTMLBOX', "<iframe src='{$hookUrl}' style='width:100%;height:800px;border:none;background:#1a1a1a'></iframe>");
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
	/**
	 * VerarbeiteHookAktion
	 *
	 * Verarbeitet eine Hook-Aktion SYNCHRON und gibt das HTML direkt zurück.
	 * Wird vom hook_handler.php aufgerufen damit nach Aktionen (z.B. Logdatei
	 * wechseln) sofort das aktualisierte HTML gerendert wird – ohne das
	 * Timing-Problem von IPS_RequestAction (asynchron).
	 */
	public function VerarbeiteHookAktion(string $aktion, string $wert): string
	{
		$logDateiOverride = null;
		$schriftgroesseOverride = null;
		$autoRefreshOverride = null;
		try {
			if ($aktion === 'LogDateiAuswaehlen') {
				$datei = trim($wert);
				$logDir = rtrim(IPS_GetLogDir(), DIRECTORY_SEPARATOR);
				$dateiReal = realpath($datei);
				$logDirReal = realpath($logDir);
				$gueltig = $dateiReal !== false
					&& $logDirReal !== false
					&& stripos($dateiReal, $logDirReal) === 0
					&& is_file($dateiReal)
					&& preg_match('/logfile.*\.log$/i', basename($dateiReal));
				$this->SendDebug('LogDateiAuswaehlen', "wert={$wert} dateiReal={$dateiReal} logDirReal={$logDirReal} gueltig=" . ($gueltig?'ja':'nein'), 0);
				if ($gueltig) {
					$this->WriteAttributeString('AktuelleLogDatei', $dateiReal);
					$logDateiOverride = $dateiReal; // direkt weitergeben, IPS-Attribut-Cache umgehen
					$status = $this->leseStatus();
					$status['seite'] = 0;
					$status['filterTypen'] = [];
					$status['objektIdFilter'] = '';
					$status['senderFilter'] = [];
					$status['textFilter'] = '';
					$status['trefferGesamt'] = -1;
					$status['zaehlungLaeuft'] = false;
					$status['dateiGroesseCache'] = 0;
					$status['dateiMTimeCache'] = 0;
					$status['tabellenLadungLaeuft'] = false;
					$status['tabellenLadungText'] = '';
					$this->schreibeStatus($status);
					$this->leereSeitenCache();
				}
			} elseif ($aktion === 'SetzeSchriftgroesse') {
				$groesse = max(8, min(20, (int) $wert));
				$schriftgroesseOverride = $groesse;
				$status = $this->leseStatus();
				$status['schriftgroesse'] = $groesse;
				$this->schreibeStatus($status);
			} elseif ($aktion === 'ErsteSeite') {
				$status = $this->leseStatus();
				$status['seite'] = 0;
				$this->schreibeStatus($status);
			} elseif ($aktion === 'LetzteSeite') {
				$status = $this->leseStatus();
				$treffer = $this->ermittleTrefferFuerLetzteSeitenBerechnung($status);
				$mz = $this->normalisiereMaxZeilen((int)($status['maxZeilen'] ?? 50));
				$status['seite'] = ($treffer > 0 && $mz > 0) ? max(0, (int)ceil($treffer/$mz)-1) : 0;
				$this->schreibeStatus($status);
			} elseif ($aktion === 'SprungSeite') {
				$status = $this->leseStatus();
				$ziel = max(0, (int)$wert - 1);
				$treffer = (int)($status['trefferGesamt'] ?? -1);
				$mz = $this->normalisiereMaxZeilen((int)($status['maxZeilen'] ?? 50));
				if ($treffer > 0 && $mz > 0) $ziel = min($ziel, (int)ceil($treffer/$mz)-1);
				$status['seite'] = $ziel;
				$this->schreibeStatus($status);

			} elseif ($aktion === 'Schnellfilter') {
				$sf = json_decode($wert, true) ?? [];
				$status = $this->leseStatus();
				if (isset($sf['ft'])) $status['filterTypen'] = [$sf['ft']];
				if (isset($sf['sf'])) $status['senderFilter'] = [$sf['sf']];
				$status['seite'] = 0;
				$status['trefferGesamt'] = -1;
				$this->schreibeStatus($status);
				$this->leereSeitenCache();
			} elseif ($aktion === 'SetzeAutoRefresh') {
				$sek = max(0, (int) $wert);
				$status = $this->leseStatus();
				$status['autoRefreshSek'] = $sek;
				$this->schreibeStatus($status);
				$autoRefreshOverride = $sek;
			} elseif ($aktion !== '') {
				$this->RequestAction($aktion, $wert);
			}
		} catch (\Throwable $e) {
			$this->SendDebug('VerarbeiteHookAktion', 'Fehler: ' . $e->getMessage(), 0);
		}
		return $this->erstelleHtmlMitLogDatei($logDateiOverride, $schriftgroesseOverride, $autoRefreshOverride);
	}

	public function ErstelleHtmlDirekt(): string
	{
		return $this->erstelleHtmlMitLogDatei(null);
	}

	public function ErstelleStatistik(): string
	{
		$logDatei = $this->leseAktuelleLogDatei();
		$h = '/hook/LogAnalyzerIPSView_' . $this->InstanceID;
		if (!is_file($logDatei)) {
			return '<html><body style="background:#1a1a1a;color:#f88;padding:20px">Logdatei nicht gefunden.</body></html>';
		}

		// ── Daten sammeln ─────────────────────────────────────────
		$fehlerCount    = [];
		$fehlerErstmals = [];
		$senderCount    = [];
		$senderTypCount = [];
		$stundenCount   = array_fill(0, 24, 0);  // alle Tage (für Chart)
		$stundenCountH  = array_fill(0, 24, 0);  // nur heute (für Meta)
		$stundenCountG  = array_fill(0, 24, 0);  // nur gestern (für Meta)
		$stundenMsgs    = array_fill(0, 24, []);  // alle Tage
		$wochentagCount = array_fill(0, 7, 0);
		$tageCount      = [];
		$typCount       = [];
		$heatmap        = [];
		$fehlerGruppen  = [];
		$gesamt = 0;
		$heute   = date('Y-m-d');
		$gestern = date('Y-m-d', strtotime('-1 day'));
		$vor30   = date('Y-m-d', strtotime('-30 days'));
		$tage    = ['Mo','Di','Mi','Do','Fr','Sa','So'];

		$handle = @fopen($logDatei, 'rb');
		if ($handle) {
			while (($zeile = fgets($handle)) !== false) {
				$p = $this->parseLogZeile($zeile);
				if ($p === null) continue;
				$gesamt++;
				$typ    = strtoupper((string)($p['typ'] ?? ''));
				$sender = (string)($p['sender'] ?? '');
				$msg    = trim((string)($p['meldung'] ?? ''));
				$zstamp = (string)($p['zeitstempel'] ?? '');

				$typCount[$typ] = ($typCount[$typ] ?? 0) + 1;
				$senderCount[$sender] = ($senderCount[$sender] ?? 0) + 1;
				$senderTypCount[$sender][$typ] = ($senderTypCount[$sender][$typ] ?? 0) + 1;

				// Datum: DD.MM.YYYY -> YYYY-MM-DD
				$datum = '';
				if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $zstamp, $dm)) {
					$datum = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
				}

				// Uhrzeit
				preg_match('/(\d{2}):(\d{2}):(\d{2})/', $zstamp, $tm);
				$stunde = isset($tm[1]) ? (int)$tm[1] : -1;

				if ($datum) {
					$dow = ((int)date('N', strtotime($datum)) - 1);
					$wochentagCount[$dow]++;
					if ($stunde >= 0) {
						$heatmap[$dow][$stunde] = ($heatmap[$dow][$stunde] ?? 0) + 1;
					}
					$tageCount[$datum] = ($tageCount[$datum] ?? 0) + 1;
				}

				if (in_array($typ, ['ERROR','WARNING'], true)) {
					$fehlerCount[$msg] = ($fehlerCount[$msg] ?? 0) + 1;
					if (!isset($fehlerErstmals[$msg])) $fehlerErstmals[$msg] = $zstamp;
					if ($stunde >= 0) {
						$stundenCount[$stunde]++;  // alle Tage
						$stundenMsgs[$stunde][$msg] = ($stundenMsgs[$stunde][$msg] ?? 0) + 1;
						if ($datum === $heute) $stundenCountH[$stunde]++;
						if ($datum === $gestern) $stundenCountG[$stunde]++;
					}
					$grpKey = preg_replace('/[0-9]+/', 'N', substr($msg, 0, 50));
					$fehlerGruppen[$grpKey]['count'] = ($fehlerGruppen[$grpKey]['count'] ?? 0) + 1;
					$fehlerGruppen[$grpKey]['msgs'][$msg] = ($fehlerGruppen[$grpKey]['msgs'][$msg] ?? 0) + 1;
				}
			}
			fclose($handle);
		}

		arsort($fehlerCount);
		$topFehler = array_slice($fehlerCount, 0, 25, true);
		arsort($senderCount);
		$topSender = array_slice($senderCount, 0, 15, true);
		uasort($fehlerGruppen, fn($a,$b) => $b['count'] <=> $a['count']);
		$topGruppen  = array_slice($fehlerGruppen, 0, 10, true);
		$maxGruppen  = $topGruppen ? max(array_column($topGruppen, 'count')) : 1;
		ksort($tageCount);

		$maxStunden   = max(max($stundenCount) ?: 1, max($stundenCountG) ?: 1);
		$maxFehler    = $topFehler ? max($topFehler) : 1;
		$maxSender    = $topSender ? max($topSender) : 1;
		$maxWochentag = max($wochentagCount) ?: 1;
		$gesamtFehler = ($typCount['ERROR'] ?? 0) + ($typCount['WARNING'] ?? 0);
		$typFarben    = ['ERROR'=>'#f66','WARNING'=>'#fa0','DEBUG'=>'#7ecfff','MESSAGE'=>'#888','CUSTOM'=>'#ffcc88','NOTIFY'=>'#d0aaff','SUCCESS'=>'#88ffcc'];

		// ── Chart 1: Alle Tage aggregiert ────────────────────────────
		$svgW = 700; $svgH = 150; $barW = (int)($svgW / 24);
		$chartH = $svgH - 30; // Platz für X-Achse + Y-Achse Labels
		$svgBars = '';
		// Y-Achse Linien
		for ($yi = 1; $yi <= 4; $yi++) {
			$yv = (int)round($maxStunden * $yi / 4);
			$yy = $svgH - 22 - (int)round($chartH * $yi / 4);
			$svgBars .= '<line x1="22" y1="' . $yy . '" x2="' . $svgW . '" y2="' . $yy . '" stroke="#2a2a2a" stroke-width="1"/>';
			$svgBars .= '<text x="20" y="' . ($yy+3) . '" font-size="7" fill="#444" text-anchor="end">' . $yv . '</text>';
		}
		for ($i = 0; $i < 24; $i++) {
			$vH = $stundenCount[$i];
			$vG = $stundenCountG[$i];
			$bhH = $vH > 0 ? max(2, (int)round($vH / $maxStunden * $chartH)) : 0;
			$bhG = $vG > 0 ? max(2, (int)round($vG / $maxStunden * $chartH)) : 0;
			$x  = 22 + $i * (int)(($svgW - 22) / 24);
			$bw = (int)(($svgW - 22) / 24);
			$lx = $x + (int)($bw/2) - 4;
			// Gestern
			if ($bhG > 0) $svgBars .= '<rect x="' . ($x+1) . '" y="' . ($svgH-$bhG-22) . '" width="' . ($bw-2) . '" height="' . $bhG . '" fill="#3a3a3a" rx="2" pointer-events="none"/>';
			// Heute
			$fc = $vH > $maxStunden * 0.7 ? '#f66' : ($vH > $maxStunden * 0.3 ? '#fa0' : '#5a9');
			if ($bhH > 0) $svgBars .= '<rect x="' . ($x+3) . '" y="' . ($svgH-$bhH-22) . '" width="' . ($bw-6) . '" height="' . $bhH . '" fill="' . $fc . '" rx="2" pointer-events="none"/>';
			// Hover
			$topMsg = '';
			if (!empty($stundenMsgs[$i])) { arsort($stundenMsgs[$i]); $topMsg = array_key_first($stundenMsgs[$i]); if (strlen($topMsg)>60) $topMsg=substr($topMsg,0,60).'…'; }
			$svgBars .= '<rect x="' . $x . '" y="0" width="' . $bw . '" height="' . ($svgH-18) . '" fill="transparent" data-h="' . sprintf('%02d:00',$i) . '" data-c="' . $stundenCountH[$i] . '" data-g="' . $vG . '" data-tot="' . $vH . '" data-m="' . htmlspecialchars($topMsg, ENT_QUOTES) . '" class="sh" cursor="pointer"/>';
			$svgBars .= '<text x="' . $lx . '" y="' . ($svgH-5) . '" font-size="8" fill="#444">' . sprintf('%02d',$i) . '</text>';
			if ($vH > 0) $svgBars .= '<text x="' . $lx . '" y="' . ($svgH-$bhH-25) . '" font-size="7" fill="#aaa">' . $vH . '</text>';
		}
		$svgBars .= '<g id="stunden-tip" visibility="hidden" pointer-events="none">'
			. '<rect id="stunden-tip-bg" rx="4" fill="#1e1e2a" stroke="#555" stroke-width="1"/>'
			. '<text id="stunden-tip-h" font-size="10" font-weight="bold" fill="#ffd080"></text>'
			. '<text id="stunden-tip-c" font-size="9" fill="#f88"></text>'
			. '<text id="stunden-tip-g" font-size="9" fill="#555"></text>'
			. '<text id="stunden-tip-m" font-size="8" fill="#999"></text>'
			. '</g>';

		// ── Chart 2: Trend über alle verfügbaren Tage ───────────
		$trendW = 700; $trendH = 120;
		$tChartH = $trendH - 30;
		// Datumsbereich aus vorhandenen Daten ermitteln
		$alleTageDaten = array_keys($tageCount);
		if ($alleTageDaten) {
			$erstTag = min($alleTageDaten);
			$letzTag = $heute;
			$tageSpanne = (int)round((strtotime($letzTag) - strtotime($erstTag)) / 86400) + 1;
		} else {
			$erstTag    = date('Y-m-d', strtotime('-29 days'));
			$tageSpanne = 30;
		}
		$tageSpanne = max(7, min(365, $tageSpanne)); // 7–365 Tage
		$tageKeys   = [];
		for ($i = $tageSpanne - 1; $i >= 0; $i--) {
			$tageKeys[] = date('Y-m-d', strtotime($letzTag . " -{$i} days"));
		}
		$trendBars = '';
		$trendBarW = max(1, (int)(($trendW - 35) / count($tageKeys)));
		$maxTagLog = $tageCount ? log(max($tageCount) + 1, 10) : 1;
		if ($maxTagLog < 0.01) $maxTagLog = 1;
		// Y-Achse (log)
		$maxTagVal = $tageCount ? max($tageCount) : 0;
		foreach ([1, 10, 100, 1000, 10000] as $yl) {
			if ($yl > $maxTagVal * 1.2) break;
			$yy = $trendH - 20 - (int)round(log($yl+1,10) / $maxTagLog * $tChartH);
			$trendBars .= '<line x1="33" y1="' . $yy . '" x2="' . $trendW . '" y2="' . $yy . '" stroke="#2a2a2a" stroke-width="1"/>';
			$trendBars .= '<text x="31" y="' . ($yy+3) . '" font-size="6" fill="#444" text-anchor="end">' . $yl . '</text>';
		}
		foreach ($tageKeys as $idx => $tag) {
			$v = $tageCount[$tag] ?? 0;
			$bh = $v > 0 ? max(2, (int)round(log($v+1,10) / $maxTagLog * $tChartH)) : 0;
			$x  = 34 + $idx * $trendBarW;
			$fc = ($tag === $heute) ? '#f88' : ($tag === $gestern ? '#fa8' : '#4a6a8a');
			$tagLabel = date('d.m.Y', strtotime($tag));
			$tagWt = $tage[(int)date('N', strtotime($tag)) - 1];
			if ($bh > 0) $trendBars .= '<rect x="' . ($x+1) . '" y="' . ($trendH-$bh-20) . '" width="' . ($trendBarW-2) . '" height="' . $bh . '" fill="' . $fc . '" rx="1"'
				. ' data-d="' . htmlspecialchars($tagWt . ', ' . $tagLabel, ENT_QUOTES) . '" data-v="' . $v . '" data-datum="' . $tag . '" class="th" cursor="pointer"/>';
			$labelIntervall = $tageSpanne <= 14 ? 1 : ($tageSpanne <= 60 ? 7 : 30);
			if ($idx % $labelIntervall === 0 || $tag === $heute) {
				$lbl = date('d.m', strtotime($tag));
				$trendBars .= '<text x="' . ($x+1) . '" y="' . ($trendH-4) . '" font-size="7" fill="#444">' . $lbl . '</text>';
			}
			if ($v > 0 && $bh > 12) $trendBars .= '<text x="' . ($x+2) . '" y="' . ($trendH-$bh-22) . '" font-size="6" fill="#666">' . $v . '</text>';
		}

		// ── Chart 3: Heatmap ──────────────────────────────────────
		$hmW = 700; $hmCellW = (int)(($hmW - 24) / 24); $hmCellH = 24;
		$hmH = 7 * $hmCellH + 24;
		$hmMax = 1;
		foreach ($heatmap as $row) foreach ($row as $v) if ($v > $hmMax) $hmMax = $v;
		$hmSvg = '';
		// Stunden-Labels oben
		for ($h2 = 0; $h2 < 24; $h2 += 3) {
			$hmSvg .= '<text x="' . (24 + $h2*$hmCellW + 2) . '" y="10" font-size="7" fill="#444">' . sprintf('%02d',$h2) . '</text>';
		}
		for ($d = 0; $d < 7; $d++) {
			$y = $d * $hmCellH + 14;
			$hmSvg .= '<text x="20" y="' . ($y+15) . '" font-size="8" fill="#666" text-anchor="end">' . $tage[$d] . '</text>';
			for ($h2 = 0; $h2 < 24; $h2++) {
				$v = $heatmap[$d][$h2] ?? 0;
				$cx = 24 + $h2 * $hmCellW;
				$hmLabel2 = htmlspecialchars($tage[$d] . ', ' . sprintf('%02d:00',$h2), ENT_QUOTES);
				if ($v > 0) {
					$intensity = min(1.0, $v / $hmMax);
					$r = (int)(40 + $intensity * 190);
					$gg = (int)(10 + $intensity * 10);
					$b = (int)(10 + $intensity * 10);
					$fill = sprintf('rgb(%d,%d,%d)', $r, $gg, $b);
					$hmSvg .= '<rect x="' . $cx . '" y="' . $y . '" width="' . ($hmCellW-1) . '" height="' . ($hmCellH-2) . '" fill="' . $fill . '" rx="1"'
						. ' data-d="' . $hmLabel2 . '" data-v="' . $v . '" data-dow="' . $d . '" data-hr="' . $h2 . '" class="hm" cursor="pointer"/>';
					if ($hmCellW >= 20) {
						$textCol = $intensity > 0.6 ? '#fff' : '#aaa';
						$hmSvg .= '<text x="' . ($cx + (int)($hmCellW/2)) . '" y="' . ($y+15) . '" font-size="7" fill="' . $textCol . '" text-anchor="middle" pointer-events="none">' . $v . '</text>';
					}
				} else {
					$hmSvg .= '<rect x="' . $cx . '" y="' . $y . '" width="' . ($hmCellW-1) . '" height="' . ($hmCellH-2) . '" fill="#1e1e1e" rx="1"/>';
				}
			}
		}

		// ── Chart 4: Wochentag ────────────────────────────────────
		$wtW = 300; $wtH = 120;
		$wtChartH = $wtH - 30;
		$wtBarW = (int)(($wtW - 20) / 7);
		$wtSvg = '';
		// Y-Achse
		for ($yi = 1; $yi <= 3; $yi++) {
			$yv = (int)round($maxWochentag * $yi / 3);
			$yy = $wtH - 20 - (int)round($wtChartH * $yi / 3);
			$wtSvg .= '<line x1="18" y1="' . $yy . '" x2="' . $wtW . '" y2="' . $yy . '" stroke="#2a2a2a" stroke-width="1"/>';
			$wtSvg .= '<text x="16" y="' . ($yy+3) . '" font-size="6" fill="#444" text-anchor="end">' . $yv . '</text>';
		}
		for ($d = 0; $d < 7; $d++) {
			$v = $wochentagCount[$d];
			$bh = $v > 0 ? max(2, (int)round($v / $maxWochentag * $wtChartH)) : 0;
			$x  = 20 + $d * $wtBarW;
			$fc = $d >= 5 ? '#7a5a2a' : '#3a6a5a';
			if ($bh > 0) {
				$wtSvg .= '<rect x="' . ($x+2) . '" y="' . ($wtH-$bh-20) . '" width="' . ($wtBarW-4) . '" height="' . $bh . '" fill="' . $fc . '" rx="2"'
					. ' data-d="' . htmlspecialchars($tage[$d], ENT_QUOTES) . '" data-v="' . $v . '" data-dow="' . $d . '" class="wt" cursor="pointer"/>';
				if ($bh > 14) $wtSvg .= '<text x="' . ($x + (int)($wtBarW/2)) . '" y="' . ($wtH-$bh-22) . '" font-size="7" fill="#888" text-anchor="middle" pointer-events="none">' . $v . '</text>';
			}
			$wtSvg .= '<text x="' . ($x + (int)($wtBarW/2)) . '" y="' . ($wtH-5) . '" font-size="8" fill="#555" text-anchor="middle">' . $tage[$d] . '</text>';
		}

		// ── Tabelle: Top-Fehler ───────────────────────────────────
		$fehlerRows = '';
		$rank = 1;
		foreach ($topFehler as $msg => $cnt) {
			$pct    = (int)round($cnt / $maxFehler * 100);
			$msgH   = htmlspecialchars(strlen($msg)>120 ? substr($msg,0,120).'…' : $msg);
			$erst   = htmlspecialchars(substr($fehlerErstmals[$msg] ?? '', 0, 16));
			$fc2    = $rank <= 3 ? '#f66' : ($rank <= 8 ? '#fa0' : '#888');
			$bgRank = $rank <= 3 ? 'background:#1a0a0a' : '';
			$fehlerRows .= '<tr style="' . $bgRank . '">'
				. '<td style="color:#444;width:24px;text-align:right;padding-right:6px">' . $rank . '</td>'
				. '<td style="color:' . $fc2 . ';font-weight:bold;width:45px;text-align:right">' . $cnt . '×</td>'
				. '<td style="color:#555;width:115px;font-size:11px;padding:0 6px">' . $erst . '</td>'
				. '<td><div style="background:' . $fc2 . ';opacity:0.7;height:3px;width:' . $pct . '%;border-radius:2px;margin-bottom:3px"></div>'
				. '<span style="font-size:11px;color:#bbb">' . $msgH . '</span></td>'
				. '</tr>';
			$rank++;
		}

		// ── Tabelle: Fehler-Gruppen ───────────────────────────────
		$gruppenRows = '';
		foreach ($topGruppen as $grpKey => $grp) {
			$cnt      = $grp['count'];
			$varCount = count($grp['msgs']);
			arsort($grp['msgs']);
			$topMsg = htmlspecialchars(substr(array_key_first($grp['msgs']), 0, 90));
			$pct    = (int)round($cnt / $maxGruppen * 100);
			$gruppenRows .= '<tr>'
				. '<td style="color:#f88;font-weight:bold;width:45px;text-align:right">' . $cnt . '×</td>'
				. '<td style="color:#666;width:80px;font-size:11px">' . $varCount . ' Varianten</td>'
				. '<td><div style="background:#f66;opacity:0.5;height:3px;width:' . $pct . '%;border-radius:2px;margin-bottom:3px"></div>'
				. '<span style="font-size:11px;color:#bbb">' . $topMsg . '</span></td>'
				. '</tr>';
		}

		// ── Tabelle: Sender ───────────────────────────────────────
		$senderRows = '';
		foreach ($topSender as $snd => $cnt) {
			$pct  = (int)round($cnt / $maxSender * 100);
			$sndH = htmlspecialchars($snd);
			$breakdown = '';
			$sTyps = $senderTypCount[$snd] ?? [];
			arsort($sTyps);
			foreach (array_slice($sTyps, 0, 4, true) as $t => $tc) {
				$fc3 = $typFarben[strtoupper($t)] ?? '#888';
				$breakdown .= '<span style="color:' . $fc3 . ';font-size:10px;margin-right:5px">' . $t . ':&nbsp;' . $tc . '</span>';
			}
			$errCnt = ($senderTypCount[$snd]['ERROR'] ?? 0);
			$warnCnt = ($senderTypCount[$snd]['WARNING'] ?? 0);
			$errStyle = ($errCnt > 0) ? 'color:#f66' : 'color:#333';
			$senderRows .= '<tr>'
				. '<td style="color:#ffd080;width:130px">' . $sndH . '</td>'
				. '<td style="color:#aaa;width:45px;text-align:right">' . $cnt . '</td>'
				. '<td style="' . $errStyle . ';width:40px;text-align:right;font-size:11px">' . ($errCnt > 0 ? '⚠ '.$errCnt : '') . '</td>'
				. '<td style="padding:0 6px;width:150px"><div style="background:#3a7a5a;height:4px;width:' . $pct . '%;border-radius:2px"></div></td>'
				. '<td style="font-size:11px">' . $breakdown . '</td>'
				. '</tr>';
		}

		// ── Tabelle: Typ-Verteilung ───────────────────────────────
		$typRows = '';
		arsort($typCount);
		$maxTyp = $typCount ? max($typCount) : 1;
		foreach ($typCount as $typ => $cnt) {
			$pct    = (int)round($cnt / $maxTyp * 100);
			$pctGes = $gesamt > 0 ? round($cnt / $gesamt * 100, 1) : 0;
			$fc3    = $typFarben[strtoupper($typ)] ?? '#aaa';
			$typRows .= '<tr>'
				. '<td style="color:' . $fc3 . ';font-weight:bold;width:90px">' . htmlspecialchars($typ) . '</td>'
				. '<td style="color:#aaa;width:55px;text-align:right">' . number_format($cnt) . '</td>'
				. '<td style="color:#555;width:45px;text-align:right">' . $pctGes . '%</td>'
				. '<td style="padding-left:6px"><div style="background:' . $fc3 . ';height:4px;width:' . $pct . '%;border-radius:2px"></div></td>'
				. '</tr>';
		}

		$dateiname    = htmlspecialchars(basename($logDatei));
		$ts           = date('d.m.Y H:i:s');
		$fehlerHeuteH = number_format(array_sum($stundenCountH));
		$fehlerGesH   = number_format(array_sum($stundenCountG));

		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
			. '<title>Statistik – ' . $dateiname . '</title>'
			. '<style>'
			. '*{box-sizing:border-box;margin:0;padding:0}'
			. 'body{font-family:Arial,sans-serif;font-size:12px;background:#1a1a1a;color:#ccc;padding:10px}'
			. 'h3{font-size:11px;color:#666;margin:0 0 5px;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px}'
			. '.card{background:#1e1e1e;border:1px solid #2a2a2a;border-radius:6px;padding:8px 10px;margin-bottom:8px}'
			. 'table{width:100%;border-collapse:collapse}'
			. 'tr:nth-child(even) td{background:rgba(255,255,255,.02)}'
			. 'tr:hover td{background:rgba(255,255,255,.04)}'
			. 'td{padding:3px 3px;vertical-align:middle;font-size:11px}'
			. '.meta{color:#444;font-size:11px;margin-bottom:8px;display:flex;gap:16px;flex-wrap:wrap}'
			. '.metaval{color:#aaa}'
			. '.grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px}'
			. '.grid3{display:grid;grid-template-columns:2fr 1fr;gap:8px}'
			. '.legend{display:flex;gap:10px;font-size:10px;margin-top:4px;flex-wrap:wrap}'
			. '.ld{width:10px;height:10px;border-radius:2px;display:inline-block;margin-right:3px;vertical-align:middle}'
			. '#chtip{position:fixed;display:none;background:#1e1e2a;border:1px solid #444;border-radius:6px;padding:8px 12px;pointer-events:none;z-index:9999;max-width:360px;box-shadow:0 4px 16px rgba(0,0,0,.7);font-size:11px;line-height:1.6}'
			. '#hmoverlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.75);z-index:10000;overflow:auto;padding:30px 20px}'
			. '#hmoverlay .panel{background:#1e1e1e;border:1px solid #333;border-radius:8px;max-width:820px;margin:0 auto}'
			. '#hmoverlay .phead{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #2a2a2a;position:sticky;top:0;background:#1e1e1e;border-radius:8px 8px 0 0;z-index:1}'
			. '#hmoverlay .phead h2{font-size:13px;color:#ffd080;margin:0}'
			. '#hmoverlay .cls{background:#2a2a2a;color:#aaa;border:1px solid #3a3a3a;border-radius:4px;padding:3px 10px;cursor:pointer;font-size:12px}'
			. '#hmoverlay .cls:hover{background:#3a3a3a}'
			. '#hmpanel-body table{width:100%;border-collapse:collapse}'
			. '#hmpanel-body td{padding:4px 8px;font-size:11px;border-bottom:1px solid #252525;vertical-align:top;word-break:break-word}'
			. '#hmpanel-body tr:hover td{background:#252525}'
			. '.typ-e{color:#f66;font-weight:bold}.typ-w{color:#fa0;font-weight:bold}.typ-d{color:#7ecfff}.typ-m{color:#888}.typ-s{color:#88ffcc}.typ-n{color:#d0aaff}.typ-c{color:#ffcc88}'
			. '@media(max-width:700px){.grid2,.grid3{grid-template-columns:1fr}}'
			. '</style></head><body>'
			. '<div id="chtip"></div>'
			. '<div id="hmoverlay"><div class="panel">'
			. '<div class="phead"><h2 id="hmpanel-title">Details</h2><button class="cls" onclick="closeOverlay()">✕ Schließen</button></div>'
			. '<div id="hmpanel-body" style="padding:10px"></div>'
			. '</div></div>'
			. '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">'
			. '<span style="font-size:14px;color:#ffd080;font-weight:bold">📊 Statistik – ' . $dateiname . '</span>'
			. '<a href="' . $h . '" style="background:#222;color:#aaa;border:1px solid #333;border-radius:4px;padding:2px 10px;text-decoration:none;font-size:11px">← Zurück</a>'
			. '</div>'
			. '<div class="meta">'
			. '<span>Gesamt: <span class="metaval">' . number_format($gesamt) . '</span> Zeilen</span>'
			. '<span>Errors/Warnings: <span class="metaval" style="color:#f88">' . number_format($gesamtFehler) . '</span></span>'
			. '<span>Heute: <span class="metaval" style="color:#f88">' . $fehlerHeuteH . '</span> Fehler</span>'
			. '<span>Gestern: <span class="metaval" style="color:#888">' . $fehlerGesH . '</span> Fehler</span>'
			. '<span style="margin-left:auto;color:#333">Stand: ' . $ts . '</span>'
			. '</div>'
			// Chart 1
			. '<div class="card">'
			. '<h3>⏰ Errors + Warnings nach Uhrzeit <span style="font-weight:normal;text-transform:none;font-size:10px;color:#444">alle Tage aggregiert</span></h3>'
			. '<svg viewBox="0 0 ' . $svgW . ' ' . $svgH . '" width="100%" style="background:#161616;border-radius:3px">' . $svgBars . '</svg>'
			. '<div class="legend"><span><span class="ld" style="background:#5a9"></span>Niedrig</span><span><span class="ld" style="background:#fa0"></span>Mittel</span><span><span class="ld" style="background:#f66"></span>Hoch</span><span style="margin-left:8px;color:#444;font-size:10px">Farbe relativ zum Maximum aller Stunden</span></div>'
			. '</div>'
			// Chart 2
			. '<div class="card">'
			. '<h3>📈 Verlauf <span id="trend-title-tage"></span><span style="font-weight:normal;text-transform:none;font-size:10px;color:#f88"> ■ Heute</span> <span style="font-size:10px;color:#444;font-weight:normal;text-transform:none">(log. Skala)</span></h3>'
			. '<script>document.getElementById("trend-title-tage").textContent="' . $tageSpanne . ' Tage";</script>'
			. '<svg viewBox="0 0 ' . $trendW . ' ' . $trendH . '" width="100%" style="background:#161616;border-radius:3px">' . $trendBars . '</svg>'
			. '</div>'
			// Charts 3+4
			. '<div class="grid3">'
			. '<div class="card"><h3>🔥 Heatmap Wochentag × Uhrzeit</h3>'
			. '<svg viewBox="0 0 ' . $hmW . ' ' . $hmH . '" width="100%" style="background:#161616;border-radius:3px">' . $hmSvg . '</svg>'
			. '</div>'
			. '<div class="card"><h3>📅 Verteilung nach Wochentag</h3>'
			. '<svg viewBox="0 0 ' . $wtW . ' ' . $wtH . '" width="100%" style="background:#161616;border-radius:3px">' . $wtSvg . '</svg>'
			. '</div>'
			. '</div>'
			// Fehler + Gruppen
			. '<div class="grid2">'
			. '<div class="card"><h3>⚠ Häufigste Fehler <span style="font-weight:normal;text-transform:none;font-size:10px;color:#444">Top 25 | mit Erstauftreten</span></h3>'
			. '<table>' . $fehlerRows . '</table></div>'
			. '<div>'
			. '<div class="card"><h3>🗂 Fehler-Gruppen <span style="font-weight:normal;text-transform:none;font-size:10px;color:#444">ähnliche zusammengefasst</span></h3>'
			. '<table>' . $gruppenRows . '</table></div>'
			. '<div class="card"><h3>👤 Aktivste Sender</h3>'
			. '<table>' . $senderRows . '</table></div>'
			. '<div class="card"><h3>🎯 Typ-Verteilung</h3>'
			. '<table>' . $typRows . '</table></div>'
			. '</div>'
			. '</div>'
			// SVG Tooltip JS
			. '<script>'
			. 'var _t=document.getElementById("chtip");'
			. 'function showTip(e,html){_t.innerHTML=html;_t.style.display="block";_t.style.left=(e.clientX+14)+"px";_t.style.top=(e.clientY-10)+"px";}'
			. 'function moveTip(e){_t.style.left=(e.clientX+14)+"px";_t.style.top=(e.clientY-10)+"px";}'
			. 'function hideTip(){_t.style.display="none";}'
			. 'document.querySelectorAll(".sh").forEach(function(r){'
			.   'r.addEventListener("mouseenter",function(e){'
			.     'var c=parseInt(r.getAttribute("data-c")||"0");'
			.     'var g=parseInt(r.getAttribute("data-g")||"0");'
			.     'if(!c&&!g){hideTip();return;}'
			.     'var tot=parseInt(r.getAttribute("data-tot")||"0");'
			.     'showTip(e,'
			.       '"<b style=\\"color:#ffd080\\">"+(r.getAttribute("data-h")||"")+" Uhr</b><br>"'
			.       '+"Gesamt (alle Tage): <b style=\\"color:#f88\\">"+tot+"</b><br>"'
			.       '+"Heute: <b style=\\"color:#fa8\\">"+c+"</b> &nbsp; Gestern: <span style=\\"color:#555\\">"+g+"</span>"'
			.       '+(r.getAttribute("data-m")?"<br><span style=\\"color:#999\\">"+r.getAttribute("data-m")+"</span>":"")'
			.     ')'
			.     ';'
			.   '});'
			.   'r.addEventListener("mousemove",moveTip);'
			.   'r.addEventListener("mouseleave",hideTip);'
			.   'r.addEventListener("click",function(){'
			.     'var hr=parseInt(r.getAttribute("data-h")||"0");'
			.     'var c=parseInt(r.getAttribute("data-c")||"0");'
			.     'var g=parseInt(r.getAttribute("data-g")||"0");'
			.     'var tot=parseInt(r.getAttribute("data-tot")||"0");'
			.     'if(tot>0){var url=_apiBase+"?a=StundenDetail&datum=alle&h="+hr;openOverlay(url,r.getAttribute("data-h")+" Uhr (alle Tage)",tot);}'
			.   '});'
			. '});'
			. 'document.querySelectorAll(".th").forEach(function(r){'
			.   'r.addEventListener("mouseenter",function(e){'
			.     'showTip(e,'
			.       '"<b style=\\"color:#ffd080\\">"+r.getAttribute("data-d")+"</b><br>"'
			.       '+"Einträge: <b style=\\"color:#f88\\">"+r.getAttribute("data-v")+"</b>"'
			.       '+"<br><span style=\\"color:#555;font-size:10px\\">Klicken für Details</span>"'
			.     ');'
			.   '});'
			.   'r.addEventListener("mousemove",moveTip);'
			.   'r.addEventListener("mouseleave",hideTip);'
			.   'r.addEventListener("click",function(){'
			.     'var url=_apiBase+"?a=TrendDetail&datum="+encodeURIComponent(r.getAttribute("data-datum")||r.getAttribute("data-d"));'
			.     'openOverlay(url,r.getAttribute("data-d"),r.getAttribute("data-v"));'
			.   '});'
			. '});'
			. 'var _apiBase="' . $h . '";'
			. 'function openOverlay(url,label,cnt){'
			.   'hideTip();'
			.   'var ov=document.getElementById("hmoverlay");'
			.   'var title=document.getElementById("hmpanel-title");'
			.   'var body=document.getElementById("hmpanel-body");'
			.   'title.textContent=label+" ("+cnt+" Einträge)";'
			.   'body.innerHTML="<div style=\\"padding:20px;color:#666\\">Lade…</div>";'
			.   'ov.style.display="block";'
			.   'fetch(url)'
			.     '.then(function(r){return r.json();})'
			.     '.then(function(d){'
			.       'if(d.error){body.innerHTML="<p style=\\"color:#f66;padding:20px\\">Fehler: "+d.error+"</p>";return;}'
			.       'var typCls={ERROR:"typ-e",WARNING:"typ-w",DEBUG:"typ-d",MESSAGE:"typ-m",SUCCESS:"typ-s",NOTIFY:"typ-n",CUSTOM:"typ-c"};'
			.       'var html="<div style=\\"padding:8px 14px 4px;color:#555;font-size:11px\\">Letzte bis zu 300 Einträge – "+d.label+"</div>";'
			.       'html+="<table><thead><tr style=\\"background:#252525\\">";'
			.       'html+="<th style=\\"padding:4px 8px;text-align:left;font-size:11px;color:#666;width:140px\\">Zeit</th>";'
			.       'html+="<th style=\\"padding:4px 8px;text-align:left;font-size:11px;color:#666;width:75px\\">Typ</th>";'
			.       'html+="<th style=\\"padding:4px 8px;text-align:left;font-size:11px;color:#666;width:130px\\">Sender</th>";'
			.       'html+="<th style=\\"padding:4px 8px;text-align:left;font-size:11px;color:#666\\">Meldung</th>";'
			.       'html+="</tr></thead><tbody>";'
			.       'if(d.eintraege.length===0){html+="<tr><td colspan=4 style=\\"padding:20px;color:#555;text-align:center\\">Keine Einträge gefunden</td></tr>";}'
			.       'd.eintraege.forEach(function(e){'
			.         'var tc=typCls[e.typ.toUpperCase()]||"typ-m";'
			.         'html+="<tr>";'
			.         'html+="<td style=\\"color:#555;white-space:nowrap\\">"+(e.zeit||"")+"</td>";'
			.         'html+="<td class=\\""+tc+"\\">"+(e.typ||"")+"</td>";'
			.         'html+="<td style=\\"color:#ffd080\\">"+(e.sender||"")+"</td>";'
			.         'html+="<td style=\\"color:#ccc\\">"+(e.msg||"")+"</td>";'
			.         'html+="</tr>";'
			.       '});'
			.       'html+="</tbody></table>";'
			.       'body.innerHTML=html;'
			.     '})'
			.     '.catch(function(){body.innerHTML="<p style=\\"color:#f66;padding:20px\\">Fehler beim Laden</p>";});'
			. '}'
			. 'function closeOverlay(){document.getElementById("hmoverlay").style.display="none";}'
			. 'document.getElementById("hmoverlay").addEventListener("click",function(e){if(e.target===this)closeOverlay();});'
			. 'document.querySelectorAll(".hm").forEach(function(r){'
			.   'r.addEventListener("mouseenter",function(e){'
			.     'showTip(e,'
			.       '"<b style=\\"color:#ffd080\\">"+r.getAttribute("data-d")+"</b><br>"'
			.       '+"Einträge: <b style=\\"color:#f88\\">"+r.getAttribute("data-v")+"</b>"'
			.       '+"<br><span style=\\"color:#555;font-size:10px\\">Klicken für Details</span>"'
			.     ');'
			.   '});'
			.   'r.addEventListener("mousemove",moveTip);'
			.   'r.addEventListener("mouseleave",hideTip);'
			.   'r.addEventListener("click",function(){'
			.     'var url=_apiBase+"?a=HeatmapDetail&dow="+r.getAttribute("data-dow")+"&h="+r.getAttribute("data-hr");'
			.     'openOverlay(url,r.getAttribute("data-d"),r.getAttribute("data-v"));'
			.   '});'
			. '});'
			. 'document.querySelectorAll(".wt").forEach(function(r){'
			.   'r.addEventListener("mouseenter",function(e){'
			.     'showTip(e,'
			.       '"<b style=\\"color:#ffd080\\">"+r.getAttribute("data-d")+"</b><br>"'
			.       '+"Einträge: <b style=\\"color:#f88\\">"+r.getAttribute("data-v")+"</b>"'
			.       '+"<br><span style=\\"color:#555;font-size:10px\\">Klicken für Details</span>"'
			.     ');'
			.   '});'
			.   'r.addEventListener("mousemove",moveTip);'
			.   'r.addEventListener("mouseleave",hideTip);'
			.   'r.addEventListener("click",function(){'
			.     'var url=_apiBase+"?a=WochentagDetail&dow="+r.getAttribute("data-dow");'
			.     'openOverlay(url,r.getAttribute("data-d"),r.getAttribute("data-v"));'
			.   '});'
			. '});'
			. '</script>'
			. '</body></html>';
	}



	public function HeatmapDetail(int $dow, int $stunde): string
	{
		$tage    = ['Mo','Di','Mi','Do','Fr','Sa','So'];
		$logDatei = $this->leseAktuelleLogDatei();
		if ($dow < 0 || $dow > 6 || $stunde < 0 || $stunde > 23 || !is_file($logDatei)) {
			return json_encode(['error' => 'Ungültige Parameter'], JSON_UNESCAPED_UNICODE);
		}

		$zielWt    = $dow + 1; // date('N'): 1=Mo..7=So
		$ergebnis  = [];
		$handle    = @fopen($logDatei, 'rb');
		if ($handle) {
			while (($zeile = fgets($handle)) !== false) {
				$p = $this->parseLogZeile($zeile);
				if ($p === null) continue;
				$zstamp = (string)($p['zeitstempel'] ?? '');

				// Datum extrahieren
				if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $zstamp, $dm)) continue;
				$datum = $dm[3] . '-' . $dm[2] . '-' . $dm[1];

				// Wochentag und Stunde prüfen
				if ((int)date('N', strtotime($datum)) !== $zielWt) continue;
				if (!preg_match('/(\d{2}):(\d{2}):(\d{2})/', $zstamp, $tm)) continue;
				if ((int)$tm[1] !== $stunde) continue;

				$ergebnis[] = [
					'zeit'   => $zstamp,
					'typ'    => (string)($p['typ']     ?? ''),
					'sender' => (string)($p['sender']  ?? ''),
					'msg'    => (string)($p['meldung'] ?? ''),
				];
			}
			fclose($handle);
		}

		// Letzte 200 Treffer, neueste zuerst
		$ergebnis = array_slice(array_reverse($ergebnis), 0, 200);

		return json_encode([
			'label'   => $tage[$dow] . ', ' . sprintf('%02d:00–%02d:59', $stunde, $stunde),
			'count'   => count($ergebnis),
			'eintraege' => $ergebnis,
		], JSON_UNESCAPED_UNICODE);
	}

	public function TrendDetail(string $datum): string
	{
		$logDatei = $this->leseAktuelleLogDatei();
		if (!$datum || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || !is_file($logDatei)) {
			return json_encode(['error' => 'Ungültige Parameter'], JSON_UNESCAPED_UNICODE);
		}
		$zielDatum = $datum; // YYYY-MM-DD
		$ergebnis  = [];
		$handle    = @fopen($logDatei, 'rb');
		if ($handle) {
			while (($zeile = fgets($handle)) !== false) {
				$p = $this->parseLogZeile($zeile);
				if ($p === null) continue;
				$zstamp = (string)($p['zeitstempel'] ?? '');
				if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $zstamp, $dm)) continue;
				$d = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
				if ($d !== $zielDatum) continue;
				$ergebnis[] = [
					'zeit'   => $zstamp,
					'typ'    => (string)($p['typ']    ?? ''),
					'sender' => (string)($p['sender'] ?? ''),
					'msg'    => (string)($p['meldung'] ?? ''),
				];
			}
			fclose($handle);
		}
		$ergebnis = array_slice(array_reverse($ergebnis), 0, 300);
		$label = date('d.m.Y', strtotime($zielDatum)) . ' (' . $this->wochentagName($zielDatum) . ')';
		return json_encode(['label' => $label, 'count' => count($ergebnis), 'eintraege' => $ergebnis], JSON_UNESCAPED_UNICODE);
	}

	public function StundenDetail(string $datum, int $stunde): string
	{
		$logDatei = $this->leseAktuelleLogDatei();
		if ($stunde < 0 || $stunde > 23 || !is_file($logDatei)) {
			return json_encode(['error' => 'Ungültige Parameter'], JSON_UNESCAPED_UNICODE);
		}
		$heute    = date('Y-m-d');
		$gestern  = date('Y-m-d', strtotime('-1 day'));
		$alle     = ($datum === 'alle');
		$zielDatum = ($datum === 'gestern') ? $gestern : ($alle ? null : $heute);
		$ergebnis  = [];
		$handle    = @fopen($logDatei, 'rb');
		if ($handle) {
			while (($zeile = fgets($handle)) !== false) {
				$p = $this->parseLogZeile($zeile);
				if ($p === null) continue;
				$zstamp = (string)($p['zeitstempel'] ?? '');
				if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $zstamp, $dm)) continue;
				$d = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
				if (!$alle && $d !== $zielDatum) continue;
				if (!preg_match('/(\d{2}):(\d{2}):(\d{2})/', $zstamp, $tm)) continue;
				if ((int)$tm[1] !== $stunde) continue;
				$ergebnis[] = [
					'zeit'   => $zstamp,
					'typ'    => (string)($p['typ']    ?? ''),
					'sender' => (string)($p['sender'] ?? ''),
					'msg'    => (string)($p['meldung'] ?? ''),
				];
			}
			fclose($handle);
		}
		$ergebnis = array_slice(array_reverse($ergebnis), 0, 500);
		if ($alle) {
			$label = sprintf('%02d:00–%02d:59 Uhr (alle Tage)', $stunde, $stunde);
		} else {
			$tagLabel = ($datum === 'gestern') ? 'Gestern' : 'Heute';
			$label = $tagLabel . ', ' . sprintf('%02d:00–%02d:59', $stunde, $stunde);
		}
		return json_encode(['label' => $label, 'count' => count($ergebnis), 'eintraege' => $ergebnis], JSON_UNESCAPED_UNICODE);
	}

	public function WochentagDetail(int $dow): string
	{
		$tage     = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];
		$logDatei = $this->leseAktuelleLogDatei();
		if ($dow < 0 || $dow > 6 || !is_file($logDatei)) {
			return json_encode(['error' => 'Ungültige Parameter'], JSON_UNESCAPED_UNICODE);
		}
		$zielWt   = $dow + 1;
		$ergebnis = [];
		$handle   = @fopen($logDatei, 'rb');
		if ($handle) {
			while (($zeile = fgets($handle)) !== false) {
				$p = $this->parseLogZeile($zeile);
				if ($p === null) continue;
				$zstamp = (string)($p['zeitstempel'] ?? '');
				if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $zstamp, $dm)) continue;
				$d = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
				if ((int)date('N', strtotime($d)) !== $zielWt) continue;
				$ergebnis[] = [
					'zeit'   => $zstamp,
					'typ'    => (string)($p['typ']    ?? ''),
					'sender' => (string)($p['sender'] ?? ''),
					'msg'    => (string)($p['meldung'] ?? ''),
				];
			}
			fclose($handle);
		}
		$ergebnis = array_slice(array_reverse($ergebnis), 0, 300);
		return json_encode(['label' => $tage[$dow], 'count' => count($ergebnis), 'eintraege' => $ergebnis], JSON_UNESCAPED_UNICODE);
	}

	private function wochentagName(string $datum): string
	{
		$tage = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];
		return $tage[(int)date('N', strtotime($datum)) - 1] ?? '';
	}


	public function ObjektIdAufloesen(string $oid): string
	{
		$id = (int) $oid;
		if ($id <= 0 || !IPS_ObjectExists($id)) {
			return json_encode(['name' => '', 'typ' => ''], JSON_UNESCAPED_UNICODE);
		}
		try {
			$obj = IPS_GetObject($id);
			$typen = [0=>'Kategorie',1=>'Instanz',2=>'Variable',3=>'Skript',4=>'Ereignis',5=>'Medien',6=>'Link'];
			$typ = $typen[$obj['ObjectType'] ?? -1] ?? 'Objekt';
			return json_encode(['name' => $obj['ObjectName'] ?? '', 'typ' => $typ], JSON_UNESCAPED_UNICODE);
		} catch (\Throwable $e) {
			return json_encode(['name' => '', 'typ' => ''], JSON_UNESCAPED_UNICODE);
		}
	}

	public function ExportierePdf(string $scope): string
	{
		$zeilen = $this->ladeZeilenFuerExport($scope);
		$status = $this->leseStatus();
		$logDatei = $this->leseAktuelleLogDatei();
		$ts = date('d.m.Y H:i:s');
		$dateiname = htmlspecialchars(basename($logDatei));
		$treffer = count($zeilen);
		$filterInfo = $this->erstelleFilterBeschreibung($status);
		$scopeLabel = $scope === 'alle' ? 'Alle Treffer' : 'Aktuelle Seite';

		$rows = '';
		$pdfZnr = 1;
		foreach ($zeilen as $z) {
			$zeit   = htmlspecialchars((string)($z['zeitstempel'] ?? ''));
			$oid    = htmlspecialchars((string)($z['objektId'] ?? ''));
			$typ    = htmlspecialchars((string)($z['typ'] ?? ''));
			$sender = htmlspecialchars((string)($z['sender'] ?? ''));
			$msg    = htmlspecialchars((string)($z['meldung'] ?? ''));
			$farbe  = $this->ermittleTypFarbe($typ);
			$rows .= "<tr><td style=\"color:#999;text-align:right\">{$pdfZnr}</td><td>{$zeit}</td><td>{$oid}</td>"
				. "<td style=\"color:{$farbe};font-weight:bold\">{$typ}</td>"
				. "<td>{$sender}</td><td>{$msg}</td></tr>";
			$pdfZnr++;
		}

		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
			. '<title>Log Export – ' . $dateiname . '</title>'
			. '<style>'
			. '*{box-sizing:border-box;margin:0;padding:0}'
			. 'body{font-family:Arial,sans-serif;font-size:11px;color:#000;background:#fff;padding:12px}'
			. 'h1{font-size:14px;margin-bottom:4px}'
			. '.meta{font-size:10px;color:#555;margin-bottom:10px;line-height:1.6}'
			. 'table{width:100%;border-collapse:collapse;font-size:10px}'
			. 'th{background:#7a4400;color:#fff;padding:4px 6px;text-align:left;white-space:nowrap}'
			. 'td{padding:3px 6px;border-bottom:1px solid #ddd;vertical-align:top;word-break:break-word}'
			. 'tr:nth-child(even)td{background:#f7f7f7}'
			. 'td:nth-child(1),td:nth-child(2){white-space:nowrap}'
			. '@media print{body{padding:0}.no-print{display:none}}'
			. '</style></head><body>'
			. '<div class="no-print" style="margin-bottom:10px">'
			. '<button onclick="window.print()" style="padding:6px 16px;background:#7a4400;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:12px">&#128196; Drucken / Als PDF speichern</button>'
			. '&nbsp;<button onclick="window.close()" style="padding:6px 12px;background:#555;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:12px">Schließen</button>'
			. '</div>'
			. '<h1>Log Analyzer – Export</h1>'
			. '<div class="meta">'
			. '<b>Datei:</b> ' . $dateiname . '&nbsp;&nbsp;'
			. '<b>Umfang:</b> ' . $scopeLabel . '&nbsp;&nbsp;'
			. '<b>Zeilen:</b> ' . $treffer . '&nbsp;&nbsp;'
			. '<b>Exportiert:</b> ' . $ts
			. ($filterInfo ? '<br><b>Filter:</b> ' . htmlspecialchars($filterInfo) : '')
			. '</div>'
			. '<table><thead><tr>'
			. '<th style="width:30px">#</th><th>Zeit</th><th>ObjektID</th><th>Typ</th><th>Sender</th><th>Meldung</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>'
			. '</body></html>';
	}

	public function ExportiereCsv(string $scope): string
	{
		$zeilen = $this->ladeZeilenFuerExport($scope);
		$logDatei = $this->leseAktuelleLogDatei();
		$dateiname = 'log-export-' . date('Y-m-d_His') . '.csv';

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $dateiname . '"');
		header('Cache-Control: no-cache');

		$out = "Zeit;ObjektID;Typ;Sender;Meldung\n";
		foreach ($zeilen as $z) {
			$out .= implode(';', [
				'"' . str_replace('"', '""',(string)($z['zeitstempel'] ?? '')) . '"',
				'"' . str_replace('"', '""',(string)($z['objektId'] ?? '')) . '"',
				'"' . str_replace('"', '""',(string)($z['typ'] ?? '')) . '"',
				'"' . str_replace('"', '""',(string)($z['sender'] ?? '')) . '"',
				'"' . str_replace('"', '""',(string)($z['meldung'] ?? '')) . '"',
			]) . "\n";
		}
		return $out;
	}

	private function ermittleTrefferFuerLetzteSeitenBerechnung(array $status): int
	{
		$treffer = (int)($status['trefferGesamt'] ?? -1);
		if ($treffer >= 0) return $treffer;
		// trefferGesamt unbekannt – echte Zählung durchführen
		try {
			return $this->zaehleGefilterteZeilen($status);
		} catch (\Throwable $e) {
			return 0;
		}
	}

	private function ladeZeilenFuerExport(string $scope): array
	{
		$status = $this->leseStatus();
		if ($scope === 'alle') {
			$statusExport = $status;
			$statusExport['seite'] = 0;
			$statusExport['maxZeilen'] = 10000; // Sicherheitslimit für RAM/Timeout
			$result = $this->ladeLogZeilen($statusExport);
			return is_array($result['zeilen'] ?? null) ? $result['zeilen'] : [];
		} else {
			$cache = $this->leseSeitenCache();
			return is_array($cache['zeilen'] ?? null) ? $cache['zeilen'] : [];
		}
	}

	private function erstelleFilterBeschreibung(array $status): string
	{
		$teile = [];
		$typen = $this->normalisiereFilterTypen($status['filterTypen'] ?? []);
		if ($typen) $teile[] = 'Typ: ' . implode(', ', $typen);
		$sender = $this->normalisiereSenderFilter($status['senderFilter'] ?? []);
		if ($sender) $teile[] = 'Sender: ' . implode(', ', $sender);
		$text = trim((string)($status['textFilter'] ?? ''));
		if ($text) $teile[] = 'Text: ' . $text;
		$oid = trim((string)($status['objektIdFilter'] ?? ''));
		if ($oid) $teile[] = 'ObjektID: ' . $oid;
		$von = trim((string)($status['zeitVon'] ?? ''));
		if ($von) $teile[] = 'Von: ' . $von;
		$bis = trim((string)($status['zeitBis'] ?? ''));
		if ($bis) $teile[] = 'Bis: ' . $bis;
		return implode(' | ', $teile);
	}

	private function ermittleTypFarbe(string $typ): string
	{
		return match(strtoupper($typ)) {
			'ERROR'   => '#f66',
			'WARNING' => '#fa0',
			'DEBUG'   => '#88f',
			'CUSTOM'  => '#6cf',
			default   => '#aaa',
		};
	}

	private function ermittleTagesZusammenfassung(string $logDatei): array
	{
		$heute = date('Y-m-d');
		$zaehler = ['ERROR' => 0, 'WARNING' => 0, 'DEBUG' => 0, 'MESSAGE' => 0, 'CUSTOM' => 0];
		$handle = @fopen($logDatei, 'rb');
		if ($handle === false) return $zaehler;
		try {
			while (($zeile = fgets($handle)) !== false) {
				$parsed = $this->parseLogZeile($zeile);
				if ($parsed === null) continue;
				$zeit = (string)($parsed['zeitstempel'] ?? '');
				if (strncmp($zeit, $heute, 10) !== 0) continue; // nur heute
				$typ = strtoupper((string)($parsed['typ'] ?? ''));
				if (isset($zaehler[$typ])) {
					$zaehler[$typ]++;
				} else {
					$zaehler['CUSTOM']++;
				}
			}
		} finally {
			fclose($handle);
		}
		return $zaehler;
	}

	private function erstelleHtmlMitLogDatei(?string $logDateiOverride, ?int $schriftgroesseOverride = null, ?int $autoRefreshOverride = null): string
	{
		try {
			$daten = $this->erstelleVisualisierungsDaten($logDateiOverride);
			$daten['status'] = $this->leseStatus();
			if ($schriftgroesseOverride !== null) {
				$daten['status']['schriftgroesse'] = $schriftgroesseOverride;
			}
			if ($autoRefreshOverride !== null) {
				$daten['status']['autoRefreshSek'] = $autoRefreshOverride;
			}
			return $this->erstelleHtmlFuerIPSView($daten);
		} catch (\Throwable $e) {
			$msg = htmlspecialchars($e->getMessage());
			return "<html><body style='background:#1a1a1a;color:#f88;padding:20px'>Fehler: {$msg}</body></html>";
		}
	}

	private function erstelleOderAktualisierHookScript(): void
	{
		$instanzId   = $this->InstanceID;
		$ident       = 'WebHookScript';
		$handlerFile = __DIR__ . '/libs/hook_handler.php';
		$handlerCode = is_file($handlerFile) ? file_get_contents($handlerFile) : '<?php echo "Handler fehlt";';
		$scriptCode  = "<?php\n\$LOGANALYZER_INSTANCE_ID = {$instanzId};\n"
			. ltrim(substr($handlerCode, strpos($handlerCode, '<?php') + 5));
		$scriptId = @IPS_GetObjectIDByIdent($ident, $instanzId);
		if ($scriptId === false || $scriptId === 0 || !IPS_ScriptExists((int)$scriptId)) {
			$scriptId = IPS_CreateScript(0);
			IPS_SetParent($scriptId, $instanzId);
			IPS_SetIdent($scriptId, $ident);
			IPS_SetName($scriptId, 'WebHook Handler');
			IPS_SetHidden($scriptId, true);
		}
		IPS_SetScriptContent((int)$scriptId, $scriptCode);
		$this->SendDebug('HookScript', "ID={$scriptId}", 0);
	}

	private function erstelleHtmlFuerIPSView(array $daten): string
	{
		$instanzId   = $this->InstanceID;
		$h           = '/hook/LogAnalyzerIPSView_' . $instanzId;
		$status      = is_array($daten['status'] ?? null) ? $daten['status'] : [];
		$zeilen      = is_array($daten['zeilen'] ?? null) ? $daten['zeilen'] : [];
		$logDatei    = (string)($daten['logDatei'] ?? '');
		$logDateiBn  = htmlspecialchars(basename($logDatei));
		$dateiGroesse= htmlspecialchars((string)($daten['dateiGroesse'] ?? ''));
		$treffer     = (int)($daten['trefferGesamt'] ?? -1);
		$von         = (int)($daten['bereichVon'] ?? 0);
		$bis         = (int)($daten['bereichBis'] ?? 0);
		$ts          = htmlspecialchars((string)($daten['zeitstempel'] ?? ''));
		$seite       = (int)($status['seite'] ?? 0);
		$maxZeilen   = (int)($daten['maxZeilen'] ?? 50);
		$ladezeitTab = (int)($daten['ladezeitMs'] ?? 0);
		$aktiveTypen = (array)($status['filterTypen'] ?? []);
		$aktiveSender= (array)($status['senderFilter'] ?? []);
		$textFilter  = htmlspecialchars((string)($status['textFilter'] ?? ''));
		$objektFilter= htmlspecialchars((string)($status['objektIdFilter'] ?? ''));
		$verfTypen   = (array)($daten['verfuegbareFilterTypen'] ?? []);
		$verfSender  = (array)($daten['verfuegbareSender'] ?? []);
		$verfDateien = (array)($daten['verfuegbareLogdateien'] ?? []);
		$hatWeitere  = (bool)($daten['hatWeitere'] ?? false);
		$modus       = htmlspecialchars((string)($daten['betriebsmodus'] ?? 'standard'));

		if (!(bool)($daten['ok'] ?? false)) {
			$msg = htmlspecialchars((string)($daten['fehlermeldung'] ?? 'Fehler'));
			return '<html><body style="background:#1a1a1a;color:#f88;padding:12px"><b>' . $msg . '</b></body></html>';
		}

		$metaTreffer = $treffer >= 0 ? $von . '&ndash;' . $bis . '&nbsp;/&nbsp;' . $treffer : '...';
		$seiteAnz    = $seite + 1;
		$letzteSeite = ($treffer > 0 && $maxZeilen > 0) ? max(0, (int)ceil($treffer / $maxZeilen) - 1) : -1;
		$schriftgroesse = max(8, min(20, (int)($status['schriftgroesse'] ?? 12)));
		$autoRefreshSek = max(0, (int)($status['autoRefreshSek'] ?? 0));
		$zeitVon        = (string)($status['zeitVon'] ?? '');
		$zeitBis        = (string)($status['zeitBis'] ?? '');

		$logOpts = '';
		foreach ($verfDateien as $d) {
			$pfad = htmlspecialchars((string)($d['pfad'] ?? ''));
			$name = htmlspecialchars((string)($d['anzeige'] ?? basename((string)($d['pfad'] ?? ''))));
			$sel  = ($pfad === $logDatei) ? ' selected' : '';
			$logOpts .= '<option value="' . $pfad . '"' . $sel . '>' . $name . '</option>';
		}
		if ($logOpts === '') $logOpts = '<option value="' . htmlspecialchars($logDatei) . '" selected>' . $logDateiBn . '</option>';

		$zeilenOpts = '';
		foreach ([20, 50, 100, 200, 500, 1000] as $z) {
			$sel = ($z === $maxZeilen) ? ' selected' : '';
			$zeilenOpts .= '<option value="' . $z . '"' . $sel . '>' . $z . '</option>';
		}

		$schriftOpts = '';
		foreach ([8, 9, 10, 11, 12, 13, 14, 16, 18, 20] as $s) {
			$sel = ($s === $schriftgroesse) ? ' selected' : '';
			$schriftOpts .= '<option value="' . $s . '"' . $sel . '>' . $s . ' px</option>';
		}

		$refreshOpts = '';
		foreach ([0=>'Aus', 5=>'5s', 10=>'10s', 30=>'30s', 60=>'60s', 120=>'2min'] as $rv => $rl) {
			$sel = ($rv === $autoRefreshSek) ? ' selected' : '';
			$refreshOpts .= '<option value="' . $rv . '"' . $sel . '>' . $rl . '</option>';
		}

		$modusOpts = '';
		foreach (['standard' => 'Standard (bis 6MB)', 'system' => 'System (grep)'] as $val => $lbl) {
			$sel = ($val === $modus) ? ' selected' : '';
			$modusOpts .= '<option value="' . $val . '"' . $sel . '>' . $lbl . '</option>';
		}

		// Farbzuordnung für Checkbox-Leiste
		$typFarben = [
			'DEBUG'   => '#7ecfff', 'INFO'    => '#aaffaa', 'WARNING' => '#ffd080',
			'ERROR'   => '#ff7070', 'FATAL'   => '#ff4444', 'NOTIFY'  => '#d0aaff',
			'SUCCESS' => '#88ffcc', 'MESSAGE' => '#cccccc', 'CUSTOM'  => '#ffcc88',
		];
		$typCbs = '';
		foreach ($verfTypen as $t) {
			$ts2    = htmlspecialchars($t);
			$aktiv  = in_array($t, $aktiveTypen, true);
			$farbe  = $typFarben[strtoupper($t)] ?? '#aaa';
			$cbId   = 'typ-cb-' . preg_replace('/[^a-z0-9]/i', '', $t);
			$chk    = $aktiv ? ' checked' : '';
			$bg     = $aktiv ? 'background:' . $farbe . '22;border-color:' . $farbe . '88;' : '';
			$typCbs .= '<label id="lbl-' . $cbId . '" style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;'
				. 'padding:3px 8px;border-radius:4px;border:1px solid #3a3a3a;' . $bg . '">'
				. '<input type="checkbox" name="ft[]" value="' . $ts2 . '" id="' . $cbId . '"' . $chk
				. ' class="typ-cb" style="accent-color:' . $farbe . ';cursor:pointer" onchange="submitTypFilter()">'
				. '<span style="color:' . $farbe . ';font-weight:bold;font-size:var(--fs,12px)">' . $ts2 . '</span>'
				. '</label>';
		}

		$sndCbs = '';
		foreach ($verfSender as $s) {
			$ss2   = htmlspecialchars($s);
			$aktiv = in_array($s, $aktiveSender, true);
			$chk   = $aktiv ? ' checked' : '';
			$cbId  = 'snd-cb-' . preg_replace('/[^a-z0-9]/i', '', $s);
			$bg    = $aktiv ? 'background:#2a3a2a;border-color:#4a7a4a;' : '';
			$sndCbs .= '<label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;'
				. 'padding:2px 7px;border-radius:4px;border:1px solid #3a3a3a;' . $bg . '">'
				. '<input type="checkbox" name="sf[]" value="' . $ss2 . '" id="' . $cbId . '"' . $chk
				. ' class="snd-cb" style="accent-color:#6a6;cursor:pointer" onchange="submitTypFilter()">'
				. '<span style="font-size:var(--fs,12px);color:#aaa">' . $ss2 . '</span>'
				. '</label>';
		}

		$fb = '';
		foreach ($aktiveTypen  as $t) $fb .= '<span class="fb">Typ: ' . htmlspecialchars($t) . '</span>';
		foreach ($aktiveSender as $s) $fb .= '<span class="fb">Sender: ' . htmlspecialchars($s) . '</span>';
		if ($textFilter)   $fb .= '<span class="fb">Text: ' . $textFilter . '</span>';
		if ($objektFilter) $fb .= '<span class="fb">ObjID: ' . $objektFilter . '</span>';
		if ($zeitVon)      $fb .= '<span class="fb">Von: ' . htmlspecialchars($zeitVon) . '</span>';
		if ($zeitBis)      $fb .= '<span class="fb">Bis: ' . htmlspecialchars($zeitBis) . '</span>';
		$fb = $fb ?: '<span class="mu">Kein Filter aktiv</span>';

		$disN = ($seite <= 0) ? ' disabled' : '';
		$disA = (!$hatWeitere && !($treffer > 0 && ($seite+1)*$maxZeilen < $treffer)) ? ' disabled' : '';

		$typF = ['DEBUG'=>'#7ecfff','INFO'=>'#aaffaa','WARNING'=>'#ffd080','ERROR'=>'#ff7070',
				 'FATAL'=>'#ff4444','NOTIFY'=>'#d0aaff','SUCCESS'=>'#88ffcc','MESSAGE'=>'#cccccc','CUSTOM'=>'#ffcc88'];
		$tbody = '';
		$znr = $seite * $maxZeilen + 1; // Startnummer für diese Seite
		if (empty($zeilen)) {
			$tbody = '<tr><td colspan="6" class="empty">Keine Eintr&auml;ge gefunden.</td></tr>';
		} else {
			foreach ($zeilen as $z) {
				$typ  = htmlspecialchars((string)($z['typ']         ?? ''));
				$snd  = htmlspecialchars((string)($z['sender']      ?? ''));
				$msgRaw = (string)($z['meldung'] ?? '');
				$msg  = htmlspecialchars($msgRaw);
				$zeit = htmlspecialchars((string)($z['zeitstempel'] ?? ''));
				$oid  = htmlspecialchars((string)($z['objektId']    ?? ''));
				$fc   = $typF[strtoupper($typ)] ?? '#cccccc';
				// Suchbegriff highlighten
				if ($textFilter !== '') {
					$hlPattern = '/' . preg_quote($textFilter, '/') . '/iu';
					$hlReplace = '<mark style="background:#7a5000;color:#ffd080;border-radius:2px;padding:0 2px">$0</mark>';
					$msg = preg_replace($hlPattern, $hlReplace, $msg) ?? $msg;
					$snd = preg_replace($hlPattern, $hlReplace, $snd) ?? $snd;
				}
				// Lange Meldungen aufklappbar machen
				$msgHtml = strlen($msgRaw) > 80
					? '<span class="cm-kurz" onclick="this.classList.toggle(\'cm-kurz\');this.classList.toggle(\'cm-voll\')" title="Klicken zum Aufklappen">' . $msg . '</span>'
					: $msg;
				$typRaw  = htmlspecialchars((string)($z['typ'] ?? ''));
				$sndRaw  = htmlspecialchars((string)($z['sender'] ?? ''));
				$oidRaw  = htmlspecialchars((string)($z['objektId'] ?? ''));
				// Schnellfilter: Klick auf Typ/Sender filtert sofort
				$typLink = '<a href="' . $h . '?a=Schnellfilter&ft=' . urlencode($typRaw) . '" style="color:' . $fc . ';text-decoration:none" title="Nach diesem Typ filtern">' . $typ . '</a>';
				$sndLink = '<a href="' . $h . '?a=Schnellfilter&sf=' . urlencode($sndRaw) . '" style="color:inherit;text-decoration:none" title="Nach diesem Sender filtern">' . $snd . '</a>';
				// ObjektID mit Name
				$oidInt = (int)$oidRaw;
				if ($oidInt > 0 && $oidRaw !== '00000' && IPS_ObjectExists($oidInt)) {
					$oidName = htmlspecialchars(IPS_GetName($oidInt));
					$oidCell = '<span class="oid-hover" data-oid="' . $oidRaw . '">'
						. '<span style="color:#666">' . $oidRaw . '</span>'
						. '<br><span style="color:#aaa;font-size:calc(var(--fs,12px) - 1px)">' . $oidName . '</span>'
						. '</span>';
				} else {
					$oidCell = $oidRaw;
				}
				$tbody .= '<tr>'
					. '<td class="cz" style="color:#444;min-width:30px;text-align:right">' . $znr++ . '</td>'
					. '<td class="cz">' . $zeit . '</td>'
					. '<td class="co">' . $oidCell . '</td>'
					. '<td class="ct">' . $typLink . '</td>'
					. '<td class="cs">' . $sndLink . '</td>'
					. '<td class="cm">' . $msgHtml . '</td>'
					. '</tr>';
			}
		}

		$css = '
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,Helvetica,sans-serif;font-size:var(--fs,12px);background:#1a1a1a;color:#ccc}
.bar{background:#222;border-bottom:1px solid #2e2e2e;padding:8px 10px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}
.bar2{background:#1e1e1e;border-bottom:1px solid #2a2a2a;padding:8px 10px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.grp{display:flex;flex-direction:column;gap:3px}
.lbl{font-size:calc(var(--fs,12px) - 2px);color:#777;text-transform:uppercase;letter-spacing:.4px}
select{background:#2a2a2a;color:#ccc;border:1px solid #3a3a3a;border-radius:4px;padding:4px 7px;font-size:var(--fs,12px)}
select:focus{outline:none;border-color:#666}
select[multiple]{min-height:unset}
input[type=text]{background:#2a2a2a;color:#ccc;border:1px solid #3a3a3a;border-radius:4px;padding:5px 8px;font-size:var(--fs,12px)}
input[type=text]:focus{outline:none;border-color:#666}
.btn{display:inline-block;background:#2a2a2a;color:#ccc;border:1px solid #3a3a3a;border-radius:4px;padding:5px 12px;font-size:var(--fs,12px);text-decoration:none;cursor:pointer;white-space:nowrap}
.btn:hover{background:#333;color:#fff}
.btn-p{background:#5a3000;border-color:#8a5000;color:#ffd080}.btn-p:hover{background:#7a4000}
.btn-r{background:#2a1a1a;border-color:#553333;color:#f99}.btn-r:hover{background:#3a2020}
.btn[disabled]{opacity:.4;pointer-events:none}
.fb{background:#5a3000;border:1px solid #8a5000;color:#ffd080;border-radius:3px;padding:2px 7px;font-size:var(--fs,12px);margin-right:3px}
.mu{color:#555;font-size:var(--fs,12px)}
.meta{padding:5px 10px;font-size:var(--fs,12px);color:#666;background:#1a1a1a;border-bottom:1px solid #2a2a2a;display:flex;flex-wrap:wrap;gap:12px;align-items:center}
table{width:100%;border-collapse:collapse}
thead th{background:#7a4400;color:#fff;padding:6px 8px;text-align:left;font-size:var(--fs,12px);position:sticky;top:0;white-space:nowrap}
tbody tr:nth-child(even){background:#1e1e1e}tbody tr:hover{background:#252525}
.tbl-wrap{overflow:auto;max-height:calc(100vh - 270px)}
.cz{color:#555;white-space:nowrap;padding:3px 8px;font-size:var(--fs,12px)}
.co{white-space:nowrap;padding:3px 8px;font-size:var(--fs,12px);color:#888}
.ct{white-space:nowrap;padding:3px 8px;font-size:var(--fs,12px);font-weight:bold}
.cs{color:#aaa;padding:3px 8px;font-size:var(--fs,12px);white-space:nowrap;max-width:200px;overflow:hidden;text-overflow:ellipsis}
.cm{padding:3px 8px;word-break:break-word;font-size:var(--fs,12px)}
.empty{padding:14px;color:#555}
mark{background:#7a5000;color:#ffd080;border-radius:2px;padding:0 2px}
.oid-hover{position:relative}
.oid-tip{position:absolute;left:0;top:100%;z-index:99;background:#333;color:#ddd;border:1px solid #555;border-radius:4px;padding:4px 8px;font-size:11px;white-space:nowrap;pointer-events:none;margin-top:2px}
.cm-kurz{max-width:600px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}
.cm-voll{word-break:break-word;white-space:pre-wrap;cursor:pointer;background:#222;padding:4px 8px}
.col-hidden{display:none}
@media print{.bar,.bar2,.no-print{display:none!important}}
';

		$fs = max(8, min(20, (int)($status['schriftgroesse'] ?? 12)));
		$cssVar = ":root{--fs:{$fs}px}";
		$refreshMeta = $autoRefreshSek > 0
			? '<meta http-equiv="refresh" content="' . $autoRefreshSek . '">'
			: '';
		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">' . $refreshMeta . '<style>' . $css . $cssVar . '</style></head><body>'
			. '<div class="bar">'
			.   '<div class="grp"><span class="lbl">Logdatei</span>'
			.   '<select onchange="location.href=\'' . $h . '?a=LogDateiAuswaehlen&v=\'+encodeURIComponent(this.value)" style="max-width:200px">' . $logOpts . '</select></div>'
			.   '<div class="grp"><span class="lbl">Zeilen</span>'
			.   '<select onchange="location.href=\'' . $h . '?a=SetzeMaxZeilen&v=\'+this.value">' . $zeilenOpts . '</select></div>'
			.   '<div class="grp"><span class="lbl">Modus</span>'
			.   '<select onchange="location.href=\'' . $h . '?a=SetzeBetriebsmodus&v=\'+this.value">' . $modusOpts . '</select></div>'
			.   '<div class="grp"><span class="lbl">Schrift</span>'
			.   '<select onchange="location.href=\'' . $h . '?a=SetzeSchriftgroesse&v=\'+this.value">' . $schriftOpts . '</select></div>'
			.   '<div class="grp"><span class="lbl">Live</span>'
			.   '<select onchange="location.href=\'' . $h . '?a=SetzeAutoRefresh&v=\'+this.value">' . $refreshOpts . '</select></div>'
			.   '<span style="width:1px;background:#333;align-self:stretch;margin:0 2px"></span>'
			.   '<span style="width:1px;background:#333;align-self:stretch;margin:0 2px"></span>'
			.   '<a class="btn" href="' . $h . '?a=ExportPdf&amp;scope=seite" target="_blank">&#128196; PDF</a>'
			.   '<a class="btn" href="' . $h . '?a=ExportPdf&amp;scope=alle" target="_blank">&#128196; PDF Alle</a>'
			.   '<a class="btn" href="' . $h . '?a=ExportCsv&amp;scope=seite">&#128190; CSV</a>'
			.   '<a class="btn" href="' . $h . '?a=ExportCsv&amp;scope=alle">&#128190; CSV Alle</a>'
			.   '<span style="flex:1"></span>'
			.   '<a class="btn"' . ($seite <= 0 ? ' disabled' : '') . ' href="' . $h . '?a=ErsteSeite" title="Neueste">&#8676;</a>'
			.   '<a class="btn"' . $disN . ' href="' . $h . '?a=SeiteZurueck" title="Neuere">&#8249;</a>'
			.   '<input type="number" value="' . $seiteAnz . '" min="1"'
			.   ($treffer > 0 ? ' max="' . ($letzteSeite+1) . '"' : '')
			.   ' style="width:46px;text-align:center;background:#2a2a2a;color:#ccc;border:1px solid #3a3a3a;border-radius:4px;padding:3px 4px;font-size:var(--fs,12px)"'
			.   ' onchange="location.href=\'' . $h . '?a=SprungSeite&v=\'+this.value">'
			.   '<span class="mu" style="align-self:center">' . ($treffer > 0 ? '/' . ($letzteSeite+1) : '') . '</span>'
			.   '<a class="btn"' . $disA . ' href="' . $h . '?a=SeiteVor" title="Ältere">&#8250;</a>'
			.   (($letzteSeite > 0 || $hatWeitere) ? '<a class="btn"' . ($letzteSeite > 0 && $seite >= $letzteSeite ? ' disabled' : '') . ' href="' . $h . '?a=LetzteSeite" title="Älteste">&#8677;</a>' : '')
			.   '<a class="btn" href="' . $h . '?a=Aktualisieren" title="Aktualisieren (R)">&#8635;</a>'
			.   '<a class="btn" href="' . $h . '?a=Statistik" title="Statistik">&#128202;</a>'
			. '</div>'
			. '<form id="filter-form" method="GET" action="' . $h . '" onsubmit="return doFilter(event);"><input type="hidden" name="a" value="FilterAnwenden">'
			. '<div class="bar2">'
			.   '<div class="grp" style="flex:1"><span class="lbl">Sender</span>'
			.   '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:3px 8px;padding-top:3px">'
			.   ($sndCbs ?: '<span style="color:#555;font-size:var(--fs,12px)">...</span>')
			.   '</div></div>'
			.   '<div class="grp"><span class="lbl">Text</span>'
			.   '<input type="text" name="tf" value="' . $textFilter . '" placeholder="Freitext..." style="width:120px"></div>'
			.   '<div class="grp"><span class="lbl">ObjID</span>'
			.   '<input type="text" name="oi" value="' . $objektFilter . '" placeholder="ObjektID..." style="width:80px"></div>'
			.   '<div class="grp"><span class="lbl">Von</span>'
			.   '<input type="text" name="zv" value="' . htmlspecialchars($zeitVon) . '" placeholder="2026-01-01" style="width:95px"></div>'
			.   '<div class="grp"><span class="lbl">Bis</span>'
			.   '<input type="text" name="zb" value="' . htmlspecialchars($zeitBis) . '" placeholder="2026-12-31" style="width:95px"></div>'
			.   '<div class="grp" style="align-self:flex-end;flex-direction:row;gap:4px">'
			.   '<button type="submit" class="btn btn-p">&#10003; Filter</button>'
			.   '<a class="btn btn-r" href="' . $h . '?a=FilterReset">&#10005; Reset</a>'
			.   '</div>'
			. '</div>'
			. '<div class="bar2" style="align-items:flex-start">'
			.   '<div class="grp" style="flex:0 0 auto"><span class="lbl">Typ</span>'
			.   '<div style="display:flex;flex-wrap:wrap;gap:3px 6px;padding-top:3px">'
			.   ($typCbs ?: '<span style="color:#555;font-size:var(--fs,12px)">...</span>')
			.   '</div></div>'
			. '</div>'
			. '</form>'
			.   '<div class="meta">'
			. '<span>&#128196; <b style="color:#ccc">' . $logDateiBn . '</b>&nbsp;' . $dateiGroesse . '</span>'
			. (function() use ($daten) {
				$tz = is_array($daten['tagesZusammenfassung'] ?? null) ? $daten['tagesZusammenfassung'] : [];
				$r = '';
				if (($tz['ERROR']??0)>0)   $r .= '<span style="color:#f66;font-weight:bold" title="Heute">&#9888; '.$tz['ERROR'].' Error'.($tz['ERROR']>1?'s':'').'</span> ';
				if (($tz['WARNING']??0)>0) $r .= '<span style="color:#fa0" title="Heute">&#9651; '.$tz['WARNING'].' Warning'.($tz['WARNING']>1?'s':'').'</span> ';
				if (($tz['MESSAGE']??0)>0) $r .= '<span style="color:#888" title="Heute">&#8227; '.$tz['MESSAGE'].' Message'.($tz['MESSAGE']>1?'s':'').'</span> ';
				return $r ? '<span style="margin-left:8px;padding-left:8px;border-left:1px solid #333">'.trim($r).'</span>' : '';
			})()
			. '<span>Treffer:&nbsp;' . $metaTreffer . '</span>'
			. '<span>Tab:&nbsp;' . $ladezeitTab . '&nbsp;ms</span>'
			. '<span style="margin-left:auto">Stand:&nbsp;' . $ts . '</span>'
			. '</div>'
			. '<div class="meta">' . $fb . '</div>'
			. '<div class="tbl-wrap"><table>'
			. '<thead><tr>'
			. '<th style="width:36px">#</th>'
			. '<th style="width:140px">Zeit</th><th style="width:70px">ObjektID</th>'
			. '<th style="width:90px">Typ</th><th style="width:190px">Sender</th><th>Meldung</th>'
			. '</tr></thead>'
			. '<tbody>' . $tbody . '</tbody></table></div>'
			. '<script>'
			. 'function buildFilterUrl(){'
			.   'var h="' . $h . '",p="a=FilterAnwenden";'
			.   'document.querySelectorAll("input.snd-cb:checked").forEach(function(cb){p+="&sf[]="+encodeURIComponent(cb.value);});'
			.   'var tf=document.querySelector("#filter-form input[name=tf]");if(tf&&tf.value)p+="&tf="+encodeURIComponent(tf.value);'
			.   'var oi=document.querySelector("#filter-form input[name=oi]");if(oi&&oi.value)p+="&oi="+encodeURIComponent(oi.value);'
			.   'var zv=document.querySelector("#filter-form input[name=zv]");if(zv&&zv.value)p+="&zv="+encodeURIComponent(zv.value);'
			.   'var zb=document.querySelector("#filter-form input[name=zb]");if(zb&&zb.value)p+="&zb="+encodeURIComponent(zb.value);'
			.   'document.querySelectorAll("input.typ-cb:checked").forEach(function(cb){p+="&ft[]="+encodeURIComponent(cb.value);});'
			.   'return h+"?"+p;'
			. '}'
			. 'function submitTypFilter(){location.href=buildFilterUrl();}'
			. 'function doFilter(e){if(e)e.preventDefault();location.href=buildFilterUrl();return false;}'
			. '(function(){'
			. 'var oidCache={};'
			. 'document.addEventListener("mouseover",function(e){'
			.   'var el=e.target.closest(".oid-hover");'
			.   'if(!el||el.querySelector(".oid-tip"))return;'
			.   'var oid=el.dataset.oid;'
			.   'if(oidCache[oid]){var t=document.createElement("span");t.className="oid-tip";t.textContent=oidCache[oid];el.appendChild(t);return;}'
			.   'fetch("' . $h . '?a=ObjektIdAufloesen&oid="+oid).then(function(r){return r.json();}).then(function(d){'
			.     'var txt=(d.name?d.name+" ":"")+"["+d.typ+"]";'
			.     'oidCache[oid]=txt||"#"+oid;'
			.     'var t=document.createElement("span");t.className="oid-tip";t.textContent=oidCache[oid];el.appendChild(t);'
			.   '}).catch(function(){oidCache[oid]="#"+oid;});'
			. '});'
			. 'document.addEventListener("mouseout",function(e){'
			.   'var el=e.target.closest(".oid-hover");'
			.   'if(!el)return;'
			.   'var t=el.querySelector(".oid-tip");if(t)t.remove();'
			. '});'
			. 'document.addEventListener("keydown",function(e){'
			.   'if(e.target.tagName==="INPUT"||e.target.tagName==="SELECT"||e.target.tagName==="TEXTAREA")return;'
			.   'if(e.key==="r"||e.key==="R"){location.href="' . $h . '?a=Aktualisieren";}'
			.   'if(e.key==="ArrowLeft"||e.key==="n"||e.key==="N"){var l=document.querySelector("a[href*=SeiteZurueck]:not([disabled])");if(l)location.href=l.href;}'
			.   'if(e.key==="ArrowRight"||e.key==="a"||e.key==="A"){var l=document.querySelector("a[href*=SeiteVor]:not([disabled])");if(l)location.href=l.href;}'
			.   'if(e.key==="Home"){location.href="' . $h . '?a=ErsteSeite";}'
			.   'if(e.key==="End"){location.href="' . $h . '?a=LetzteSeite";}'
			. '});'
			. ($autoRefreshSek > 0 ? 'setTimeout(function(){location.reload();},' . ($autoRefreshSek * 1000) . ');' : '')
			. '})();'
			. '</script>'
			. '</body></html>';
	}



    public function GetConfigurationForm(): string
    {
		$elements = [
			[
				"type"    => "Label",
				"caption" => 'Alle Einstellungen werden direkt in der Tile View direkt konfiguriert.'
			],
			[
				"type"    => "Label",
				"caption" => 'Die alte WebFront Visualisierung wird nicht unterstützt. Weitere Infos sind in der Anleitung zu finden.'
			]
        ];

        $hookPfad = '/hook/LogAnalyzerIPSView_' . $this->InstanceID;
        $actions = [
            [
                'type'    => 'Button',
                'caption' => 'HTML-Box aktualisieren',
                'onClick' => 'LOGANALYZER_AktualisierenVisualisierung($id);'
            ],
            ['type'=>'Label','bold'=>true,'caption'=>'── WebHook Einrichtung (einmalig) ──'],
            ['type'=>'Label','caption'=>'IPS-Verwaltung → Kern → Ereignisse → WebHooks → Neu (+)'],
            ['type'=>'Label','caption'=>'Hook-Pfad: ' . $hookPfad],
            ['type'=>'Label','caption'=>'Script: "WebHook Handler" (Kindelement dieser Instanz)'],
        ];

        return json_encode([
            'elements' => $elements,
            'actions'  => $actions
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * GetVisualizationTile
     *
     * Liefert die HTML-Visualisierung für die Tile-Ansicht.
     * - Lädt die HTML-Datei des Moduls
     * - Übergibt die initialen Visualisierungsdaten
     *
     * Parameter: keine
     * Rückgabewert: string
     */
    public function GetVisualizationTile(): string
    {
        $datei = __DIR__ . '/module.html';	// ggf auch automatisch
        if (!is_file($datei)) {
            return '<div style="padding:1rem;font-family:sans-serif;">module.html nicht gefunden.</div>';
        }

        $html = file_get_contents($datei);
        if ($html === false) {
            return '<div style="padding:1rem;font-family:sans-serif;">module.html konnte nicht geladen werden.</div>';
        }

        $initialDaten = $this->erstelleVisualisierungsDaten();
        return str_replace('%%INITIAL_DATA%%',json_encode($initialDaten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),$html);
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
				case 'FilterReset':
					$status['filterTypen']    = [];
					$status['senderFilter']   = [];
					$status['textFilter']     = '';
					$status['objektIdFilter'] = '';
					$status['zeitVon']        = '';
					$status['zeitBis']        = '';
					$status['seite']          = 0;
					$status['trefferGesamt']  = -1;
					$this->schreibeStatus($status);
					$this->leereSeitenCache();
					$this->aktualisiereVisualisierung();
					return;
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
				case 'ErsteSeite':
					$status['seite'] = 0;
					$this->schreibeStatus($status);
					$this->setzeTabellenLadezustand(true, 'Neueste Seite wird geladen …', 'RequestAction/ErsteSeite');
					$this->aktualisiereVisualisierung();
					return;

				case 'LetzteSeite':
					$treffer = $this->ermittleTrefferFuerLetzteSeitenBerechnung($status);
					$mz = $this->normalisiereMaxZeilen((int)($status['maxZeilen'] ?? 50));
					$letzte = ($treffer > 0 && $mz > 0) ? max(0, (int)ceil($treffer / $mz) - 1) : 0;
					$status['seite'] = $letzte;
					$this->schreibeStatus($status);
					$this->setzeTabellenLadezustand(true, 'Älteste Seite wird geladen …', 'RequestAction/LetzteSeite');
					$this->aktualisiereVisualisierung();
					return;

				case 'SprungSeite':
					$zielSeite = max(0, (int)$Value - 1);
					$treffer = (int)($status['trefferGesamt'] ?? -1);
					$mz = $this->normalisiereMaxZeilen((int)($status['maxZeilen'] ?? 50));
					if ($treffer > 0 && $mz > 0) {
						$zielSeite = min($zielSeite, (int)ceil($treffer / $mz) - 1);
					}
					$status['seite'] = $zielSeite;
					$this->schreibeStatus($status);
					$this->setzeTabellenLadezustand(true, 'Seite wird geladen …', 'RequestAction/SprungSeite');
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
					$status['zeitVon'] = trim((string) ($daten['zeitVon'] ?? ''));
					$status['zeitBis'] = trim((string) ($daten['zeitBis'] ?? ''));
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

					$this->WriteAttributeString('AktuelleLogDatei', $datei);

					$status = [
						'seite'                    => 0,
						'maxZeilen'                => $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50)),
						'theme'                    => $this->normalisiereTheme((string) ($status['theme'] ?? 'dark')),
						'kompakt'                  => $this->normalisiereKompakt($status['kompakt'] ?? false),
						'schriftgroesse'           => max(8, min(20, (int) ($status['schriftgroesse'] ?? 12))),
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

					$status['kompakt'] = $this->normalisiereKompakt($Value);
					$this->schreibeStatus($status);
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeAutoRefresh':
					$status['autoRefreshSek'] = max(0, (int) $Value);
					$this->schreibeStatus($status);
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeSchriftgroesse':
					$groesse = max(8, min(20, (int) $Value));
					$status['schriftgroesse'] = $groesse;
					$this->schreibeStatus($status);
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeBetriebsmodus':
					$modus = strtolower(trim((string) $Value));
					if (!in_array($modus, ['standard', 'system', 'ultra'], true)) {
						$modus = 'standard';
					}

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
		@$this->UpdateVisualizationValue(json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
		);
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

			// Tile deaktiviert
			@$this->UpdateVisualizationValue(
				json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
			);
			// iframe auf Hook in HTMLBOX setzen
			$hookUrl = '/hook/LogAnalyzerIPSView_' . $this->InstanceID;
			$this->SetValue('HTMLBOX', "<iframe src='{$hookUrl}' style='width:100%;height:800px;border:none;background:#1a1a1a'></iframe>");
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
	private function erstelleVisualisierungsDaten(?string $logDateiOverride = null): array
	{
		$status = $this->leseStatus();
		$filterMetadaten = $this->leseFilterMetadatenFuerAnzeige();
		// Synchron laden wenn Cache leer
		if (empty($filterMetadaten['verfuegbareFilterTypen']) && empty($filterMetadaten['verfuegbareSender'])) {
			$ldf = $logDateiOverride ?? $this->leseAktuelleLogDatei();
			if (is_file($ldf)) {
				$erm = $this->ermittleFilterMetadaten();
				$filterMetadaten['verfuegbareFilterTypen'] = $erm['verfuegbareFilterTypen'] ?? [];
				$filterMetadaten['verfuegbareSender']      = $erm['verfuegbareSender'] ?? [];
				$roh = $this->leseFilterMetadatenRoh();
				$roh['verfuegbareFilterTypen'] = $filterMetadaten['verfuegbareFilterTypen'];
				$roh['verfuegbareSender']      = $filterMetadaten['verfuegbareSender'];
				$roh['laedt'] = false;
				$roh['signatur'] = $this->ermittleFilterMetadatenSignatur($status);
				$this->schreibeFilterMetadaten($roh);
			}
		}
		$logDatei = $logDateiOverride ?? $this->leseAktuelleLogDatei();
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
		$ergebnis['tagesZusammenfassung'] = $this->ermittleTagesZusammenfassung($logDatei);

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
		$logDatei = $this->leseAktuelleLogDatei();
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
					basename($this->leseAktuelleLogDatei()),
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

		$logDatei = $this->leseAktuelleLogDatei();
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
					basename($this->leseAktuelleLogDatei()),
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
		$logDatei = $this->leseAktuelleLogDatei();

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

		$logDatei = $this->leseAktuelleLogDatei();
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
			'logDatei'        => $this->leseAktuelleLogDatei(),
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
			'letzteTabellenLadezeitMs' => max(0, (int) ($daten['letzteTabellenLadezeitMs'] ?? 0)),
			'schriftgroesse'           => max(8, min(20, (int) ($daten['schriftgroesse'] ?? 12))),
			'autoRefreshSek'           => max(0, (int) ($daten['autoRefreshSek'] ?? 0)),
			'zeitVon'                  => trim((string) ($daten['zeitVon'] ?? '')),
			'zeitBis'                  => trim((string) ($daten['zeitBis'] ?? ''))
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
				'letzteTabellenLadezeitMs' => max(0, (int) ($status['letzteTabellenLadezeitMs'] ?? 0)),
				'schriftgroesse'           => max(8, min(20, (int) ($status['schriftgroesse'] ?? 12))),
				'autoRefreshSek'           => max(0, (int) ($status['autoRefreshSek'] ?? 0)),
				'zeitVon'                  => trim((string) ($status['zeitVon'] ?? '')),
				'zeitBis'                  => trim((string) ($status['zeitBis'] ?? ''))
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
		$logDatei = $this->leseAktuelleLogDatei();
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
	private function leseAktuelleLogDatei(): string
	{
		$attr = trim($this->ReadAttributeString('AktuelleLogDatei'));
		if ($attr !== '' && is_file($attr)) {
			return $attr;
		}
		return $this->ReadPropertyString('LogDatei');
	}

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
		$logDatei = $this->leseAktuelleLogDatei();

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
				'logDatei'=> $this->leseAktuelleLogDatei()
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		}

		return md5(json_encode([
			'modus'          => $modus,
			'logDatei'       => $this->leseAktuelleLogDatei(),
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
		$zeitVon = trim((string) ($status['zeitVon'] ?? ''));
		$zeitBis = trim((string) ($status['zeitBis'] ?? ''));

		// Zeitraum-Filter (Format: YYYY-MM-DD HH:MM oder YYYY-MM-DD)
		if ($zeitVon !== '' || $zeitBis !== '') {
			$zeilzeit = substr((string) ($felder['zeitstempel'] ?? ''), 0, 16); // auf Minuten kürzen
			$vonNorm = strlen($zeitVon) === 10 ? $zeitVon . ' 00:00' : substr($zeitVon, 0, 16);
			$bisNorm = strlen($zeitBis) === 10 ? $zeitBis . ' 23:59' : substr($zeitBis, 0, 16);
			if ($vonNorm !== '' && strcmp($zeilzeit, $vonNorm) < 0) return false;
			if ($bisNorm !== '' && strcmp($zeilzeit, $bisNorm) > 0) return false;
		}

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
<?php
trait LogAnalyzerSystemTrait
{

    /**
     * leseLetzteZeilenRueckwaerts
     *
     * Liest die letzten Zeilen einer Datei effizient rückwärts ein.
     * - Arbeitet blockweise vom Dateiende aus
     * - Liefert die neuesten Zeilen zuerst zurück
     *
     * Parameter: string $datei, int $anzahl
     * Rückgabewert: array
     */
	private function leseLetzteZeilenRueckwaerts(string $datei, int $anzahl): array
	{
		if ($anzahl <= 0 || !is_file($datei)) {
			return [];
		}

		$handle = @fopen($datei, 'rb');
		if ($handle === false) {
			return [];
		}

		try {
			$stat = fstat($handle);
			$dateiGroesse = (int) ($stat['size'] ?? 0);

			if ($dateiGroesse <= 0) {
				return [];
			}

			$blockGroesse = 65536;
			$position = $dateiGroesse;
			$puffer = '';
			$zeilen = [];

			while ($position > 0 && count($zeilen) <= $anzahl) {
				$leseGroesse = min($blockGroesse, $position);
				$position -= $leseGroesse;

				fseek($handle, $position);
				$chunk = fread($handle, $leseGroesse);
				if ($chunk === false || $chunk === '') {
					break;
				}

				$puffer = $chunk . $puffer;

				$teile = preg_split("/\r\n|\n|\r/", $puffer);
				if ($teile === false) {
					break;
				}

				$puffer = (string) array_shift($teile);

				if (count($teile) > 0) {
					$zeilen = array_merge($teile, $zeilen);
				}
			}

			if ($puffer !== '') {
				array_unshift($zeilen, $puffer);
			}

			$zeilen = array_values(array_filter($zeilen, static function (string $zeile): bool {
				return trim($zeile) !== '';
			}));

			if (count($zeilen) > $anzahl) {
				$zeilen = array_slice($zeilen, -$anzahl);
			}

			return array_reverse($zeilen);
		} finally {
			fclose($handle);
		}
	}

    /**
     * ladeLogZeilenWindows
     *
     * Lädt Logzeilen im Systemmodus unter Windows.
     * - Nutzt je nach Filterlage PHP oder zeilenweises Einlesen
     * - Pflegt den Seiten-Cache für die Anzeige
     *
     * Parameter: array $status
     * Rückgabewert: array
     */
	private function ladeLogZeilenWindows(array $status): array
	{
		$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
		$seite = max(0, (int) ($status['seite'] ?? 0));
		$take = (($seite + 1) * $maxZeilen) + 1;
		$head = $maxZeilen + 1;
		$logDatei = $this->leseAktuelleLogDatei();

		$dateiGroesse = is_file($logDatei) ? (int) filesize($logDatei) : 0;
		$dateiMTime = is_file($logDatei) ? (int) filemtime($logDatei) : 0;
		$listenSignatur = $this->ermittleListenCacheSignatur($status);
		$zaehlSignatur = $this->ermittleZaehlsignatur($status);

		if (!$this->hatAktiveFilter($status)) {
			$start = microtime(true);

			$rawZeilen = $this->leseLetzteZeilenRueckwaerts($logDatei, $take);
			$dauerMs = (int) round((microtime(true) - $start) * 1000);

			$zeilen = [];
			foreach ($rawZeilen as $zeile) {
				$parsed = $this->parseLogZeile($zeile);
				if ($parsed === null) {
					continue;
				}
				$zeilen[] = $parsed;
			}

			$sichtbarerBlock = array_slice($zeilen, 0, $head);
			$hatWeitere = count($zeilen) > $maxZeilen;

			if ($hatWeitere) {
				array_pop($sichtbarerBlock);
			}

			$this->schreibeSeitenCache([
				'listenSignatur'    => $listenSignatur,
				'zaehlSignatur'     => $zaehlSignatur,
				'dateiGroesseCache' => $dateiGroesse,
				'dateiMTimeCache'   => $dateiMTime,
				'trefferGesamt'     => (int) ($status['trefferGesamt'] ?? -1),
				'hatWeitere'        => $hatWeitere,
				'zeilen'            => $sichtbarerBlock
			]);

			$this->SendDebug('ladeLogZeilen',
				sprintf(
					'plattform=windows modus=tail-php datei=%s seite=%d maxZeilen=%d raw=%d parsed=%d hatWeitere=%s dauerMs=%d cache=geschrieben',
					basename($logDatei),
					$seite,
					$maxZeilen,
					count($rawZeilen),
					count($sichtbarerBlock),
					$hatWeitere ? 'true' : 'false',
					$dauerMs
				),
				0
			);

			return [
				'ok'            => true,
				'fehlermeldung' => '',
				'zeilen'        => $sichtbarerBlock,
				'hatWeitere'    => $hatWeitere
			];
		}

		$cache = $this->leseSeitenCache();

		$cacheGueltig =
			((string) ($cache['listenSignatur'] ?? '') === $listenSignatur) &&
			((string) ($cache['zaehlSignatur'] ?? '') === $zaehlSignatur) &&
			((int) ($cache['dateiGroesseCache'] ?? 0) === $dateiGroesse) &&
			((int) ($cache['dateiMTimeCache'] ?? 0) === $dateiMTime);

		if ($cacheGueltig) {
			$zeilen = is_array($cache['zeilen'] ?? null) ? $cache['zeilen'] : [];
			$hatWeitere = (bool) ($cache['hatWeitere'] ?? false);
			$trefferGesamt = (int) ($cache['trefferGesamt'] ?? -1);

			$this->SendDebug('ladeLogZeilen',
				sprintf(
					'plattform=windows modus=filter-cache datei=%s seite=%d maxZeilen=%d treffer=%d parsed=%d hatWeitere=%s dauerMs=%d',
					basename($logDatei),
					$seite,
					$maxZeilen,
					$trefferGesamt,
					count($zeilen),
					$hatWeitere ? 'true' : 'false',
					0
				),
				0
			);

			return [
				'ok'            => true,
				'fehlermeldung' => '',
				'zeilen'        => $zeilen,
				'hatWeitere'    => $hatWeitere,
				'trefferGesamt' => $trefferGesamt
			];
		}

		$start = microtime(true);

		$queue = [];
		$queueLimit = $take;
		$gesamtTreffer = 0;

		$handle = @fopen($logDatei, 'rb');
		if ($handle === false) {
			return [
				'ok'            => false,
				'fehlermeldung' => 'Logdatei konnte nicht geöffnet werden: ' . $logDatei,
				'zeilen'        => [],
				'hatWeitere'    => false,
				'trefferGesamt' => -1
			];
		}

		try {
			while (($zeile = fgets($handle)) !== false) {
				$felder = $this->extrahiereLogFelder($zeile);
				if ($felder === null) {
					continue;
				}

				if (!$this->logZeileErfuelltFilter($felder, $status)) {
					continue;
				}

				$gesamtTreffer++;
				$queue[] = $this->baueAnzeigeZeileAusFeldern($felder);

				if (count($queue) > $queueLimit) {
					array_shift($queue);
				}
			}
		} finally {
			fclose($handle);
		}

		$sichtbarerBlock = array_slice($queue, 0, min($head, count($queue)));
		$zeilen = array_reverse($sichtbarerBlock);
		$hatWeitere = $gesamtTreffer > (($seite + 1) * $maxZeilen);

		$dauerMs = (int) round((microtime(true) - $start) * 1000);

		$this->schreibeSeitenCache([
			'listenSignatur'    => $listenSignatur,
			'zaehlSignatur'     => $zaehlSignatur,
			'dateiGroesseCache' => $dateiGroesse,
			'dateiMTimeCache'   => $dateiMTime,
			'trefferGesamt'     => $gesamtTreffer,
			'hatWeitere'        => $hatWeitere,
			'zeilen'            => $zeilen
		]);

		$this->SendDebug(
			'ladeLogZeilen',
			sprintf(
				'plattform=windows modus=filter datei=%s seite=%d maxZeilen=%d treffer=%d parsed=%d hatWeitere=%s dauerMs=%d cache=geschrieben',
				basename($logDatei),
				$seite,
				$maxZeilen,
				$gesamtTreffer,
				count($zeilen),
				$hatWeitere ? 'true' : 'false',
				$dauerMs
			),
			0
		);

		return [
			'ok'            => true,
			'fehlermeldung' => '',
			'zeilen'        => $zeilen,
			'hatWeitere'    => $hatWeitere,
			'trefferGesamt' => $gesamtTreffer
		];
	}

    /**
     * ermittleWindowsMetadatenUndGesamtmenge
     *
     * Ermittelt Filtermetadaten und Gesamtzeilen unter Windows.
     * - Liest Typen, Sender und Zeilenanzahl direkt aus der Datei
     * - Bereitet die Werte für die Filteranzeige auf
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function ermittleWindowsMetadatenUndGesamtmenge(): array
	{
		$logDatei = $this->leseAktuelleLogDatei();

		$typen = [];
		$sender = [];
		$gesamtZeilen = 0;

		$handle = @fopen($logDatei, 'rb');
		if ($handle === false) {
			$this->SendDebug('FilterMetadatenFehler', 'plattform=windows dateiNichtOeffenbar', 0);

			return [
				'verfuegbareFilterTypen' => [],
				'verfuegbareSender'      => [],
				'gesamtZeilen'           => -1
			];
		}

		try {
			while (($zeile = fgets($handle)) !== false) {
				$felder = $this->extrahiereLogFelder($zeile);
				if ($felder === null) {
					continue;
				}

				$gesamtZeilen++;

				if ($felder['typ'] !== '') {
					$typen[$felder['typ']] = true;
				}

				if ($felder['sender'] !== '') {
					$sender[$felder['sender']] = true;
				}
			}
		} finally {
			fclose($handle);
		}

		$typen = array_keys($typen);
		$sender = array_keys($sender);

		sort($typen, SORT_NATURAL | SORT_FLAG_CASE);
		sort($sender, SORT_NATURAL | SORT_FLAG_CASE);

		return [
			'verfuegbareFilterTypen' => $typen,
			'verfuegbareSender'      => $sender,
			'gesamtZeilen'           => $gesamtZeilen
		];
	}

    /**
     * zaehleGefilterteZeilenWindows
     *
     * Zählt gefilterte Logzeilen unter Windows.
     * - Liest die Datei zeilenweise ein
     * - Prüft jede Zeile gegen die aktiven Filter
     *
     * Parameter: array $status
     * Rückgabewert: int
     */
	private function zaehleGefilterteZeilenWindows(array $status): int
	{
		$logDatei = $this->leseAktuelleLogDatei();
		$anzahl = 0;

		$handle = @fopen($logDatei, 'rb');
		if ($handle === false) {
			$this->SendDebug('ZaehleTrefferFehler', 'plattform=windows dateiNichtOeffenbar', 0);
			return 0;
		}
		try {
			while (($zeile = fgets($handle)) !== false) {
				$felder = $this->extrahiereLogFelder($zeile);
				if ($felder === null) {
					continue;
				}

				if ($this->logZeileErfuelltFilter($felder, $status)) {
					$anzahl++;
				}
			}
		} finally {
			fclose($handle);
		}
		return $anzahl;
	}

    /**
     * baueShellBefehl
     *
     * Baut den Shell-Befehl zum Laden von Logzeilen auf.
     * - Wählt abhängig vom Betriebssystem den passenden Pfad
     * - Übergibt Status und Seitenparameter an den Unterbau
     *
     * Parameter: array $status, int $take, int $head
     * Rückgabewert: string
     */
    private function baueShellBefehl(array $status, int $take, int $head): string
    {
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            return $this->baueWindowsBefehl($status, $take);
        }
        return $this->baueLinuxBefehl($status, $take, $head);
    }

    /**
     * baueLinuxBefehl
     *
     * Baut den Linux-Shell-Befehl zum Laden von Logzeilen.
     * - Verknüpft Filter, Begrenzung und Rückwärtsausgabe
     * - Nutzt grep, awk, tail und head
     *
     * Parameter: array $status, int $take, int $head
     * Rückgabewert: string
     */
	private function baueLinuxBefehl(array $status, int $take, int $head): string
	{
		$datei = escapeshellarg($this->leseAktuelleLogDatei());
		$filterTypen = $this->normalisiereFilterTypen($status['filterTypen'] ?? []);
		$objektIds = $this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''));
		$senderFilter = $this->normalisiereSenderFilter($status['senderFilter'] ?? []);
		$textFilter = trim((string) ($status['textFilter'] ?? ''));
		$verwendeSift = $this->ReadPropertyBoolean('VerwendeSift');
		$reverseBefehl = $this->ermittleRueckwaertsBefehl();

		$hatFilter =
			(count($filterTypen) > 0) ||
			(count($objektIds) > 0) ||
			(count($senderFilter) > 0) ||
			($textFilter !== '');

		if (!$hatFilter) {
			return 'tail -n ' . $take . ' ' . $datei . ' | head -n ' . $head . ' | ' . $reverseBefehl;
		}

		$teile = [];

		if (count($filterTypen) > 0) {
			$typePattern = $this->ermittleTypPattern($filterTypen);
			if ($verwendeSift) {
				$teile[] = 'sift -e ' . escapeshellarg($typePattern) . ' ' . $datei;
			} else {
				$teile[] = 'grep -E ' . escapeshellarg($typePattern) . ' ' . $datei;
			}
		} else {
			$teile[] = 'cat ' . $datei;
		}

		if (count($objektIds) > 0) {
			$teile[] = 'grep -E ' . escapeshellarg($this->baueObjektIdRegex($objektIds));
		}

		if (count($senderFilter) > 0) {
			$teile[] = $this->baueLinuxFixedStringOderBefehl($senderFilter);
		}

		if ($textFilter !== '') {
			$teile[] = 'grep -F -- ' . escapeshellarg($textFilter);
		}

		$awk = $this->baueLinuxAwkFilter($status);
		if ($awk !== '') {
			$teile[] = $awk;
		}

		$teile[] = 'tail -n ' . $take;
		$teile[] = 'head -n ' . $head;
		$teile[] = $reverseBefehl;

		return implode(' | ', $teile);
	}

    /**
     * baueWindowsBefehl
     *
     * Baut den PowerShell-Befehl zum Laden von Logzeilen.
     * - Verarbeitet Filterbedingungen im Windows-Systempfad
     * - Liefert die benötigten Zeilen für die Anzeige zurück
     *
     * Parameter: array $status, int $take
     * Rückgabewert: string
     */
    private function baueWindowsBefehl(array $status, int $take): string
    {
        $datei = str_replace("'", "''", $this->leseAktuelleLogDatei());
        $filterTypen = $this->normalisiereFilterTypen($status['filterTypen'] ?? []);
        $objektIds = $this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''));
        $senderFilter = $this->normalisiereSenderFilter($status['senderFilter'] ?? []);
        $textFilter = trim((string) ($status['textFilter'] ?? ''));

        $ps = [];

        if (count($filterTypen) > 0) {
            $typPattern = str_replace("'", "''", $this->ermittleTypPattern($filterTypen));
            $ps[] = '$lines = Select-String -Path ' . "'" . $datei . "'" . ' -Pattern ' . "'" . $typPattern . "'" . ' | ForEach-Object { $_.Line }';
        } else {
            $ps[] = '$lines = Get-Content -Path ' . "'" . $datei . "'";
        }

        if (count($objektIds) > 0) {
            $regex = str_replace("'", "''", $this->baueObjektIdRegex($objektIds));
            $ps[] = '$lines = $lines | Where-Object { $_ -match ' . "'" . $regex . "'" . ' }';
        }

        if (count($senderFilter) > 0) {
            $regex = str_replace("'", "''", $this->baueAnyRegex($senderFilter));
            $ps[] = '$lines = $lines | Where-Object { $_ -match ' . "'" . $regex . "'" . ' }';
        }

        if ($textFilter !== '') {
            $textLike = str_replace("'", "''", $textFilter);
            $ps[] = '$lines = $lines | Where-Object { $_ -like ' . "'*" . $textLike . "*'" . ' }';
        }

        $ps[] = '$lines = $lines | Where-Object {';
        $ps[] = '    $p = $_ -split "\\|", 5';
        $ps[] = '    if ($p.Count -lt 5) { return $false }';
        $ps[] = '    $f2 = $p[1].Trim()';
        $ps[] = '    $f3 = $p[2].Trim()';
        $ps[] = '    $f4 = $p[3].Trim()';
        $ps[] = '    $f5 = $p[4].Trim()';
        $ps[] = '    ' . $this->baueWindowsFeldBedingung($filterTypen, $objektIds, $senderFilter, $textFilter);
        $ps[] = '}';

        $ps[] = '$lines | Select-Object -Last ' . $take . ' | ForEach-Object { $_ }';

        return 'powershell -NoProfile -ExecutionPolicy Bypass -Command ' . escapeshellarg(implode('; ', $ps));
    }


    /**
     * ermittleTypPattern
     *
     * Erzeugt ein Regex-Muster für die ausgewählten Filtertypen.
     * - Escaped alle Typwerte für Regex-Verwendung
     * - Verknüpft mehrere Werte per Oder-Bedingung
     *
     * Parameter: array $filterTypen
     * Rückgabewert: string
     */
    private function ermittleTypPattern(array $filterTypen): string
    {
        if (count($filterTypen) === 0) {
            return '';
        }
        $teile = [];
        foreach ($filterTypen as $typ) {
            $teile[] = preg_quote($typ, '/');
        }
        return '(' . implode('|', $teile) . ')';
    }

    /**
     * baueZaehlBefehl
     *
     * Baut den Systembefehl zum Zählen gefilterter Logzeilen.
     * - Wählt abhängig vom Betriebssystem den passenden Pfad
     * - Leitet an Linux oder Windows weiter
     *
     * Parameter: array $status
     * Rückgabewert: string
     */
    private function baueZaehlBefehl(array $status): string
    {
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            return $this->baueWindowsZaehlerBefehl($status);
        }
        return $this->baueLinuxZaehlerBefehl($status);
    }

    /**
     * baueLinuxZaehlerBefehl
     *
     * Baut den Linux-Befehl zum Zählen gefilterter Zeilen.
     * - Verknüpft Filterstufen zu einer Shell-Pipeline
     * - Liefert am Ende die Zeilenanzahl zurück
     *
     * Parameter: array $status
     * Rückgabewert: string
     */
	private function baueLinuxZaehlerBefehl(array $status): string
	{
		$datei = escapeshellarg($this->leseAktuelleLogDatei());
		$filterTypen = $this->normalisiereFilterTypen($status['filterTypen'] ?? []);
		$objektIds = $this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''));
		$senderFilter = $this->normalisiereSenderFilter($status['senderFilter'] ?? []);
		$textFilter = trim((string) ($status['textFilter'] ?? ''));

		$hatFilter =
			(count($filterTypen) > 0) ||
			(count($objektIds) > 0) ||
			(count($senderFilter) > 0) ||
			($textFilter !== '');

		if (!$hatFilter) {
			return 'awk -F"|" ' . escapeshellarg('
	{
		if (NF >= 5) c++;
	}
	END {
		print c + 0;
	}') . ' ' . $datei;
		}

		$teile = [];

		if (count($filterTypen) > 0) {
			$teile[] = 'grep -E ' . escapeshellarg($this->ermittleTypPattern($filterTypen)) . ' ' . $datei;
		} else {
			$teile[] = 'cat ' . $datei;
		}

		if (count($objektIds) > 0) {
			$teile[] = 'grep -E ' . escapeshellarg($this->baueObjektIdRegex($objektIds));
		}

		if (count($senderFilter) > 0) {
			$teile[] = $this->baueLinuxFixedStringOderBefehl($senderFilter);
		}

		if ($textFilter !== '') {
			$teile[] = 'grep -F -- ' . escapeshellarg($textFilter);
		}

		$awk = $this->baueLinuxAwkFilter($status);
		if ($awk !== '') {
			$teile[] = $awk;
		}

		$teile[] = 'wc -l';
		return implode(' | ', $teile);
	}

    /**
     * baueLinuxAwkFilter
     *
     * Baut ein AWK-Filterprogramm für Linux-Pipelines.
     * - Prüft Objekt-ID, Typ, Sender und Textinhalt
     * - Gibt nur passende Logzeilen weiter
     *
     * Parameter: array $status
     * Rückgabewert: string
     */
    private function baueLinuxAwkFilter(array $status): string
    {
        $filterTypen = $this->normalisiereFilterTypen($status['filterTypen'] ?? []);
        $objektIds = $this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''));
        $senderFilter = $this->normalisiereSenderFilter($status['senderFilter'] ?? []);
        $textFilter = trim((string) ($status['textFilter'] ?? ''));

        if (
            count($filterTypen) === 0 &&
            count($objektIds) === 0 &&
            count($senderFilter) === 0 &&
            $textFilter === ''
        ) {
            return '';
        }

        $bedingungen = [];

        if (count($objektIds) > 0) {
            $objektChecks = [];
            foreach ($objektIds as $objektId) {
                $objektChecks[] = 'f2 == "' . $this->escapiereAwkString($objektId) . '"';
            }
            $bedingungen[] = '(' . implode(' || ', $objektChecks) . ')';
        }

        if (count($filterTypen) > 0) {
            $typChecks = [];
            foreach ($filterTypen as $typ) {
                $typChecks[] = 'f3 == "' . $this->escapiereAwkString($typ) . '"';
            }
            $bedingungen[] = '(' . implode(' || ', $typChecks) . ')';
        }

        if (count($senderFilter) > 0) {
            $senderChecks = [];
            foreach ($senderFilter as $sender) {
                $senderChecks[] = 'f4 == "' . $this->escapiereAwkString($sender) . '"';
            }
            $bedingungen[] = '(' . implode(' || ', $senderChecks) . ')';
        }

        if ($textFilter !== '') {
            $bedingungen[] = 'index(f5, "' . $this->escapiereAwkString($textFilter) . '") > 0';
        }

        $awkProgramm = '
{
    if (NF < 5) next;

    f2 = $2;
    f3 = $3;
    f4 = $4;
    f5 = $5;

    gsub(/^[ \t]+|[ \t]+$/, "", f2);
    gsub(/^[ \t]+|[ \t]+$/, "", f3);
    gsub(/^[ \t]+|[ \t]+$/, "", f4);
    gsub(/^[ \t]+|[ \t]+$/, "", f5);

    if (' . implode(' && ', $bedingungen) . ') {
        print $0;
    }
}';

        return 'awk -F"|" ' . escapeshellarg($awkProgramm);
    }

    /**
     * escapiereAwkString
     *
     * Escaped einen String für die Verwendung in AWK.
     * - Maskiert Backslashes und Anführungszeichen
     * - Gibt den sicheren String zurück
     *
     * Parameter: string $wert
     * Rückgabewert: string
     */
    private function escapiereAwkString(string $wert): string
    {
        return str_replace(
            ['\\', '"'],
            ['\\\\', '\\"'],
            $wert
        );
    }


    /**
     * baueWindowsZaehlerBefehl
     *
     * Baut den PowerShell-Befehl zum Zählen gefilterter Zeilen.
     * - Verarbeitet alle aktiven Filter im Windows-Systempfad
     * - Liefert die Anzahl passender Logzeilen zurück
     *
     * Parameter: array $status
     * Rückgabewert: string
     */
    private function baueWindowsZaehlerBefehl(array $status): string
    {
        $datei = str_replace("'", "''", $this->leseAktuelleLogDatei());
        $filterTypen = $this->normalisiereFilterTypen($status['filterTypen'] ?? []);
        $objektIds = $this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''));
        $senderFilter = $this->normalisiereSenderFilter($status['senderFilter'] ?? []);
        $textFilter = trim((string) ($status['textFilter'] ?? ''));

        $ps = [];

        if (count($filterTypen) > 0) {
            $typPattern = str_replace("'", "''", $this->ermittleTypPattern($filterTypen));
            $ps[] = '$lines = Select-String -Path ' . "'" . $datei . "'" . ' -Pattern ' . "'" . $typPattern . "'" . ' | ForEach-Object { $_.Line }';
        } else {
            $ps[] = '$lines = Get-Content -Path ' . "'" . $datei . "'";
        }

        if (count($objektIds) > 0) {
            $regex = str_replace("'", "''", $this->baueObjektIdRegex($objektIds));
            $ps[] = '$lines = $lines | Where-Object { $_ -match ' . "'" . $regex . "'" . ' }';
        }

        if (count($senderFilter) > 0) {
            $regex = str_replace("'", "''", $this->baueAnyRegex($senderFilter));
            $ps[] = '$lines = $lines | Where-Object { $_ -match ' . "'" . $regex . "'" . ' }';
        }

        if ($textFilter !== '') {
            $textLike = str_replace("'", "''", $textFilter);
            $ps[] = '$lines = $lines | Where-Object { $_ -like ' . "'*" . $textLike . "*'" . ' }';
        }

        $ps[] = '$lines = $lines | Where-Object {';
        $ps[] = '    $p = $_ -split "\\|", 5';
        $ps[] = '    if ($p.Count -lt 5) { return $false }';
        $ps[] = '    $f2 = $p[1].Trim()';
        $ps[] = '    $f3 = $p[2].Trim()';
        $ps[] = '    $f4 = $p[3].Trim()';
        $ps[] = '    $f5 = $p[4].Trim()';
        $ps[] = '    ' . $this->baueWindowsFeldBedingung($filterTypen, $objektIds, $senderFilter, $textFilter);
        $ps[] = '}';

        $ps[] = '($lines | Measure-Object -Line).Lines';
        return 'powershell -NoProfile -ExecutionPolicy Bypass -Command ' . escapeshellarg(implode('; ', $ps));
    }

    /**
     * baueFilterMetadatenBefehl
     *
     * Baut den Systembefehl zur Ermittlung von Filtermetadaten.
     * - Wählt abhängig vom Betriebssystem den passenden Pfad
     * - Leitet an Linux oder Windows weiter
     *
     * Parameter: keine
     * Rückgabewert: string
     */
    private function baueFilterMetadatenBefehl(): string
    {
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            return $this->baueWindowsFilterMetadatenBefehl();
        }
        return $this->baueLinuxFilterMetadatenBefehl();
    }

    /**
     * baueLinuxFilterMetadatenBefehl
     *
     * Baut den Linux-Befehl zur Ermittlung von Filtermetadaten.
     * - Liest Typen, Sender und Gesamtmenge per AWK aus
     * - Sortiert die Ausgabe für die Weiterverarbeitung
     *
     * Parameter: keine
     * Rückgabewert: string
     */
	private function baueLinuxFilterMetadatenBefehl(): string
	{
		$datei = escapeshellarg($this->leseAktuelleLogDatei());

		$awkProgramm = '
	{
		if (NF < 5) next;

		gesamt++;

		t = $3;
		s = $4;

		gsub(/^[ \t]+|[ \t]+$/, "", t);
		gsub(/^[ \t]+|[ \t]+$/, "", s);

		if (t != "") typen[t] = 1;
		if (s != "") sender[s] = 1;
	}
	END {
		print "G\t" gesamt;

		for (t in typen) {
			print "T\t" t;
		}

		for (s in sender) {
			print "S\t" s;
		}
	}';

		return 'awk -F"|" ' . escapeshellarg($awkProgramm) . ' ' . $datei . ' | sort';
	}

    /**
     * baueWindowsFilterMetadatenBefehl
     *
     * Baut den PowerShell-Befehl zur Ermittlung von Filtermetadaten.
     * - Extrahiert Typen und Sender aus der Logdatei
     * - Liefert die Werte in auswertbarer Form zurück
     *
     * Parameter: keine
     * Rückgabewert: string
     */
    private function baueWindowsFilterMetadatenBefehl(): string
    {
        $datei = str_replace("'", "''", $this->leseAktuelleLogDatei());

        $ps = [];
        $ps[] = 'Get-Content -Path ' . "'" . $datei . "'";
        $ps[] = '| ForEach-Object {';
        $ps[] = '    $p = $_ -split "\\|", 5';
        $ps[] = '    if ($p.Count -ge 5) {';
        $ps[] = '        $t = $p[2].Trim()';
        $ps[] = '        $s = $p[3].Trim()';
        $ps[] = '        if ($t -ne "") { "T`t$t" }';
        $ps[] = '        if ($s -ne "") { "S`t$s" }';
        $ps[] = '    }';
        $ps[] = '}';
        $ps[] = '| Sort-Object -Unique';

        return 'powershell -NoProfile -ExecutionPolicy Bypass -Command ' . escapeshellarg(implode(' ', $ps));
    }

    /**
     * baueLinuxFixedStringOderBefehl
     *
     * Baut einen grep-Befehl für mehrere feste Suchbegriffe.
     * - Verwendet Stringsuche ohne Regex-Auswertung
     * - Verknüpft mehrere Werte als Oder-Bedingung
     *
     * Parameter: array $werte
     * Rückgabewert: string
     */
    private function baueLinuxFixedStringOderBefehl(array $werte): string
    {
        $teile = ['grep', '-F'];
        foreach ($werte as $wert) {
            $teile[] = '-e';
            $teile[] = escapeshellarg($wert);
        }

        return implode(' ', $teile);
    }

    /**
     * baueObjektIdRegex
     *
     * Baut ein Regex-Muster für gefilterte Objekt-IDs.
     * - Escaped alle Objektwerte für Regex-Verwendung
     * - Erwartet die Objekt-ID im Pipe-getrennten Logformat
     *
     * Parameter: array $objektIds
     * Rückgabewert: string
     */
    private function baueObjektIdRegex(array $objektIds): string
    {
        $teile = [];
        foreach ($objektIds as $objektId) {
            $teile[] = preg_quote($objektId, '/');
        }
        return '\\|\\s*(' . implode('|', $teile) . ')\\s*\\|';
    }

    /**
     * baueAnyRegex
     *
     * Baut ein Regex-Muster aus mehreren Werten.
     * - Escaped alle Einzelwerte für Regex-Verwendung
     * - Verknüpft die Werte als Oder-Ausdruck
     *
     * Parameter: array $werte
     * Rückgabewert: string
     */
    private function baueAnyRegex(array $werte): string
    {
        $teile = [];
        foreach ($werte as $wert) {
            $teile[] = preg_quote($wert, '/');
        }
        return '(' . implode('|', $teile) . ')';
    }

    /**
     * baueWindowsFeldBedingung
     *
     * Baut die Feldbedingungen für PowerShell-Filter auf.
     * - Verknüpft Objekt-ID, Typ, Sender und Textfilter
     * - Liefert einen auswertbaren Ausdruck zurück
     *
     * Parameter: array $filterTypen, array $objektIds, array $senderFilter, string $textFilter
     * Rückgabewert: string
     */
    private function baueWindowsFeldBedingung(array $filterTypen, array $objektIds, array $senderFilter, string $textFilter): string
    {
        $bedingungen = [];
        if (count($objektIds) > 0) {
            $teile = [];
            foreach ($objektIds as $objektId) {
                $teile[] = '$f2 -eq ' . "'" . str_replace("'", "''", $objektId) . "'";
            }
            $bedingungen[] = '(' . implode(' -or ', $teile) . ')';
        }

        if (count($filterTypen) > 0) {
            $teile = [];
            foreach ($filterTypen as $typ) {
                $teile[] = '$f3 -eq ' . "'" . str_replace("'", "''", $typ) . "'";
            }
            $bedingungen[] = '(' . implode(' -or ', $teile) . ')';
        }

        if (count($senderFilter) > 0) {
            $teile = [];
            foreach ($senderFilter as $sender) {
                $teile[] = '$f4 -eq ' . "'" . str_replace("'", "''", $sender) . "'";
            }
            $bedingungen[] = '(' . implode(' -or ', $teile) . ')';
        }

        if ($textFilter !== '') {
            $bedingungen[] = '$f5.Contains(' . "'" . str_replace("'", "''", $textFilter) . "'" . ')';
        }

        if (count($bedingungen) === 0) {
            return '$true';
        }
        return implode(' -and ', $bedingungen);
    }

    /**
     * ermittleRueckwaertsBefehl
     *
     * Ermittelt ein Werkzeug zur umgekehrten Zeilenausgabe.
     * - Nutzt bevorzugt tac und sonst einen AWK-Fallback
     * - Speichert das Ergebnis für spätere Aufrufe zwischen
     *
     * Parameter: keine
     * Rückgabewert: string
     */
	private function ermittleRueckwaertsBefehl(): string
	{
		static $befehl = null;

		if ($befehl !== null) {
			return $befehl;
		}

		$plattform = (stripos(PHP_OS, 'WIN') === 0) ? 'windows' : 'unix';

		$ausgabe = [];
		$rc = 0;
		@exec('command -v tac 2>/dev/null', $ausgabe, $rc);

		if ($rc === 0 && isset($ausgabe[0]) && trim((string) $ausgabe[0]) !== '') {
			$befehl = 'tac';

			$this->SendDebug(
				'ReverseTool',
				sprintf(
					'plattform=%s tool=tac pfad=%s',
					$plattform,
					trim((string)$ausgabe[0])
				),
				0
			);

			return $befehl;
		}

		$befehl = "awk '{ lines[NR] = \$0 } END { for (i = NR; i >= 1; i--) print lines[i] }'";

		$this->SendDebug(
			'ReverseTool',
			sprintf(
				'plattform=%s tool=awk-fallback',
				$plattform
			),
			0
		);
		return $befehl;
	}

}

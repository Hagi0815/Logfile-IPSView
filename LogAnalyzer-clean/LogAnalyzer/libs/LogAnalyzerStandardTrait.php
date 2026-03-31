<?php
trait LogAnalyzerStandardTrait
{

    /**
     * ladeLogZeilenStandard
     *
     * Lädt Logzeilen im Standardmodus vollständig per PHP ein.
     * - Parst und filtert die Datei zeilenweise
     * - Baut den Seiten-Cache für die Anzeige auf
     *
     * Parameter: array $status
     * Rückgabewert: array
     */
	private function ladeLogZeilenStandard(array $status): array
	{
		$logDatei = $this->ReadPropertyString('LogDatei');
		$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
		$seite = max(0, (int) ($status['seite'] ?? 0));

		$zeilen = [];
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
				$parsed = $this->parseLogZeile($zeile);
				if ($parsed === null) {
					continue;
				}

				if (!$this->logZeileErfuelltFilter([
					'zeitstempel' => (string) $parsed['zeitstempel'],
					'objektId'    => (string) $parsed['objektId'],
					'typ'         => (string) $parsed['typ'],
					'sender'      => (string) $parsed['sender'],
					'meldung'     => (string) $parsed['meldung']
				], $status)) {
					continue;
				}

				$zeilen[] = $parsed;
			}
		} finally {
			fclose($handle);
		}

		$zeilen = array_reverse($zeilen);
		$trefferGesamt = count($zeilen);
		$offset = $seite * $maxZeilen;
		$sichtbareZeilen = array_slice($zeilen, $offset, $maxZeilen);
		$hatWeitere = ($offset + $maxZeilen) < $trefferGesamt;

		$logDatei = $this->ReadPropertyString('LogDatei');
		$this->schreibeSeitenCache([
			'listenSignatur'    => $this->ermittleListenCacheSignatur($status),
			'zaehlSignatur'     => $this->ermittleZaehlsignatur($status),
			'dateiGroesseCache' => is_file($logDatei) ? (int) filesize($logDatei) : 0,
			'dateiMTimeCache'   => is_file($logDatei) ? (int) filemtime($logDatei) : 0,
			'trefferGesamt'     => $trefferGesamt,
			'hatWeitere'        => $hatWeitere,
			'zeilen'            => $sichtbareZeilen
		]);

		$this->SendDebug('ladeLogZeilen',
			sprintf(
				'plattform=php-standard datei=%s seite=%d maxZeilen=%d treffer=%d parsed=%d hatWeitere=%s',
				basename($logDatei),
				$seite,
				$maxZeilen,
				$trefferGesamt,
				count($sichtbareZeilen),
				$hatWeitere ? 'true' : 'false'
			),
			0
		);
		return [
			'ok'            => true,
			'fehlermeldung' => '',
			'zeilen'        => $sichtbareZeilen,
			'hatWeitere'    => $hatWeitere,
			'trefferGesamt' => $trefferGesamt
		];
	}

    /**
     * zaehleGefilterteZeilenStandard
     *
     * Zählt gefilterte Logzeilen im Standardmodus per PHP.
     * - Liest die Datei zeilenweise ein
     * - Prüft jede Zeile gegen die aktiven Filter
     *
     * Parameter: array $status
     * Rückgabewert: int
     */
	private function zaehleGefilterteZeilenStandard(array $status): int
	{
		$logDatei = $this->ReadPropertyString('LogDatei');
		$anzahl = 0;

		$handle = @fopen($logDatei, 'rb');
		if ($handle === false) {
			return 0;
		}

		try {
			while (($zeile = fgets($handle)) !== false) {
				$parsed = $this->parseLogZeile($zeile);
				if ($parsed === null) {
					continue;
				}

				if ($this->logZeileErfuelltFilter([
					'zeitstempel' => (string) $parsed['zeitstempel'],
					'objektId'    => (string) $parsed['objektId'],
					'typ'         => (string) $parsed['typ'],
					'sender'      => (string) $parsed['sender'],
					'meldung'     => (string) $parsed['meldung']
				], $status)) {
					$anzahl++;
				}
			}
		} finally {
			fclose($handle);
		}
		return $anzahl;
	}

    /**
     * ermittleFilterMetadatenStandard
     *
     * Ermittelt Filtermetadaten im Standardmodus per PHP.
     * - Sammelt verfügbare Typen, Sender und Gesamtzeilen
     * - Berücksichtigt dabei aktive Filterausnahmen
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function ermittleFilterMetadatenStandard(): array
	{
		$logDatei = $this->ReadPropertyString('LogDatei');
		$status = $this->leseStatus();

		$typen = [];
		$sender = [];
		$gesamtZeilen = 0;

		$handle = @fopen($logDatei, 'rb');
		if ($handle === false) {
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

				if (
					$felder['typ'] !== '' &&
					$this->logZeileErfuelltFilterMitAusnahmen($felder, $status, ['filterTypen'])
				) {
					$typen[$felder['typ']] = true;
				}

				if (
					$felder['sender'] !== '' &&
					$this->logZeileErfuelltFilterMitAusnahmen($felder, $status, ['senderFilter'])
				) {
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
     * logZeileErfuelltFilterMitAusnahmen
     *
     * Prüft Filterbedingungen unter Auslassung einzelner Filtergruppen.
     * - Ignoriert die angegebenen Filter gezielt
     * - Wird für die Ermittlung von Filteroptionen verwendet
     *
     * Parameter: array $felder, array $status, array $ignoriereFilter
     * Rückgabewert: bool
     */
	private function logZeileErfuelltFilterMitAusnahmen(array $felder, array $status, array $ignoriereFilter = []): bool
	{
		$ignoriere = array_fill_keys($ignoriereFilter, true);

		$filterTypen = isset($ignoriere['filterTypen'])
			? []
			: $this->normalisiereFilterTypen($status['filterTypen'] ?? []);

		$objektIds = isset($ignoriere['objektIdFilter'])
			? []
			: $this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''));

		$senderFilter = isset($ignoriere['senderFilter'])
			? []
			: $this->normalisiereSenderFilter($status['senderFilter'] ?? []);

		$textFilter = isset($ignoriere['textFilter'])
			? ''
			: trim((string) ($status['textFilter'] ?? ''));

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

}
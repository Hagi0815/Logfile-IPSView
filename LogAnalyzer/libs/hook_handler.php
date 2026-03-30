<?php
// KEIN declare(strict_types=1)
// $module = Instanz von LogAnalyzerIPSView (gesetzt in ProcessHookData)

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';
$v = isset($_GET['v']) ? (string)$_GET['v'] : '';

try {
    $status = $module->leseStatus();

    switch ($a) {
        case 'FilterAnwenden':
            $ft = isset($_GET['ft']) ? array_values(array_filter((array)$_GET['ft'])) : [];
            $sf = isset($_GET['sf']) ? array_values(array_filter((array)$_GET['sf'])) : [];
            $status['filterTypen']    = $ft;
            $status['objektIdFilter'] = trim((string)($_GET['oi'] ?? ''));
            $status['senderFilter']   = $sf;
            $status['textFilter']     = trim((string)($_GET['tf'] ?? ''));
            $status['seite']          = 0;
            $status['trefferGesamt']  = -1;
            $module->schreibeStatus($status);
            $module->leereSeitenCache();
            break;

        case 'SeiteVor':
            $status['seite'] = (int)$status['seite'] + 1;
            $module->schreibeStatus($status);
            break;

        case 'SeiteZurueck':
            $status['seite'] = max(0, (int)$status['seite'] - 1);
            $module->schreibeStatus($status);
            break;

        case 'SetzeMaxZeilen':
            $status['maxZeilen'] = $module->normalisiereMaxZeilen((int)$v);
            $status['seite']     = 0;
            $module->schreibeStatus($status);
            break;

        case 'LogDateiAuswaehlen':
            if (is_file($v)) {
                $module->WriteAttributeString('AktuelleLogDatei', $v);
                $status['seite']         = 0;
                $status['trefferGesamt'] = -1;
                $status['filterTypen']   = [];
                $status['senderFilter']  = [];
                $module->schreibeStatus($status);
                $module->schreibeFilterMetadaten([
                    'verfuegbareFilterTypen' => [], 'verfuegbareSender' => [],
                    'gesamtZeilenCache' => -1, 'dateiGroesseCache' => 0,
                    'dateiMTimeCache' => 0, 'ladezeitMs' => 0,
                    'laedt' => false, 'signatur' => '',
                ]);
                $module->leereSeitenCache();
            }
            break;

        case 'SetzeBetriebsmodus':
            $m = strtolower(trim($v));
            if (in_array($m, ['standard', 'system'])) {
                IPS_SetProperty($module->InstanceID, 'Betriebsmodus', $m);
            }
            break;

        case 'FilterReset':
            $status['filterTypen']    = [];
            $status['senderFilter']   = [];
            $status['textFilter']     = '';
            $status['objektIdFilter'] = '';
            $status['seite']          = 0;
            $status['trefferGesamt']  = -1;
            $module->schreibeStatus($status);
            $module->leereSeitenCache();
            break;
    }

    $module->aktualisiereVisualisierung();
} catch (Throwable $e) {
    $module->SendDebug('Hook Fehler', $e->getMessage(), 0);
}

echo $module->GetValue('HTMLBOX');

<?php
// Hook-Handler für Log Analyzer IPSView
// Wird als IPS-Script ausgeführt wenn der WebHook aufgerufen wird
// $LOGANALYZER_INSTANCE_ID wird vom Modul gesetzt

if (!isset($_IPS) || $_IPS['SENDER'] !== 'WebHook') {
    echo "Nur als WebHook aufrufbar.";
    return;
}

$instId = isset($LOGANALYZER_INSTANCE_ID) ? (int)$LOGANALYZER_INSTANCE_ID : 0;
if ($instId === 0 || !IPS_InstanceExists($instId)) {
    echo "Instanz nicht gefunden.";
    return;
}

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';
$v = isset($_GET['v']) ? (string)$_GET['v'] : '';

// Status lesen
$attrStatus = IPS_GetProperty($instId, '_') ?: '{}';
// Status über Attribut lesen
try {
    $status = json_decode(IPS_GetAttribute($instId, 'VisualisierungsStatus'), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $status = [];
}

// Aktion ausführen
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
        IPS_SetAttribute($instId, 'VisualisierungsStatus', json_encode($status, JSON_UNESCAPED_UNICODE));
        IPS_SetAttribute($instId, 'SeitenCache', json_encode([
            'listenSignatur'=>'','zaehlSignatur'=>'','dateiGroesseCache'=>0,
            'dateiMTimeCache'=>0,'trefferGesamt'=>-1,'hatWeitere'=>false,'zeilen'=>[]
        ]));
        break;

    case 'SeiteVor':
        $status['seite'] = (int)($status['seite'] ?? 0) + 1;
        IPS_SetAttribute($instId, 'VisualisierungsStatus', json_encode($status, JSON_UNESCAPED_UNICODE));
        break;

    case 'SeiteZurueck':
        $status['seite'] = max(0, (int)($status['seite'] ?? 0) - 1);
        IPS_SetAttribute($instId, 'VisualisierungsStatus', json_encode($status, JSON_UNESCAPED_UNICODE));
        break;

    case 'SetzeMaxZeilen':
        $erlaubt = [20, 50, 100, 200, 500, 1000, 2000, 3000];
        $mz = in_array((int)$v, $erlaubt) ? (int)$v : 50;
        $status['maxZeilen'] = $mz;
        $status['seite']     = 0;
        IPS_SetAttribute($instId, 'VisualisierungsStatus', json_encode($status, JSON_UNESCAPED_UNICODE));
        break;

    case 'LogDateiAuswaehlen':
        if (is_file($v)) {
            IPS_SetAttribute($instId, 'AktuelleLogDatei', $v);
            $status['seite']         = 0;
            $status['trefferGesamt'] = -1;
            $status['filterTypen']   = [];
            $status['senderFilter']  = [];
            IPS_SetAttribute($instId, 'VisualisierungsStatus', json_encode($status, JSON_UNESCAPED_UNICODE));
            IPS_SetAttribute($instId, 'FilterMetadaten', json_encode([
                'verfuegbareFilterTypen'=>[],'verfuegbareSender'=>[],
                'dateiGroesseCache'=>0,'dateiMTimeCache'=>0,
                'ladezeitMs'=>0,'laedt'=>false,'signatur'=>''
            ]));
            IPS_SetAttribute($instId, 'SeitenCache', json_encode([
                'listenSignatur'=>'','zaehlSignatur'=>'','dateiGroesseCache'=>0,
                'dateiMTimeCache'=>0,'trefferGesamt'=>-1,'hatWeitere'=>false,'zeilen'=>[]
            ]));
        }
        break;

    case 'SetzeBetriebsmodus':
        $m = strtolower(trim($v));
        if (in_array($m, ['standard', 'system'])) {
            IPS_SetProperty($instId, 'Betriebsmodus', $m);
            IPS_ApplyChanges($instId);
        }
        break;

    case 'FilterReset':
        $status['filterTypen']    = [];
        $status['senderFilter']   = [];
        $status['textFilter']     = '';
        $status['objektIdFilter'] = '';
        $status['seite']          = 0;
        $status['trefferGesamt']  = -1;
        IPS_SetAttribute($instId, 'VisualisierungsStatus', json_encode($status, JSON_UNESCAPED_UNICODE));
        IPS_SetAttribute($instId, 'SeitenCache', json_encode([
            'listenSignatur'=>'','zaehlSignatur'=>'','dateiGroesseCache'=>0,
            'dateiMTimeCache'=>0,'trefferGesamt'=>-1,'hatWeitere'=>false,'zeilen'=>[]
        ]));
        break;
}

// Visualisierung aktualisieren
LOGANALYZER_AktualisierenVisualisierung($instId);

// Neue HTML-Box ausgeben
header('Content-Type: text/html; charset=utf-8');
echo GetValue(IPS_GetObjectIDByIdent('HTMLBOX', $instId));

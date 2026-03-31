<?php
// Hook-Handler für Log Analyzer IPSView
if (!isset($_IPS) || $_IPS['SENDER'] !== 'WebHook') { echo "Nur als WebHook aufrufbar."; return; }
$instId = isset($LOGANALYZER_INSTANCE_ID) ? (int)$LOGANALYZER_INSTANCE_ID : 0;
if (!$instId || !IPS_InstanceExists($instId)) { echo "Instanz nicht gefunden."; return; }

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';
$v = isset($_GET['v']) ? (string)$_GET['v'] : '';

switch ($a) {
    case 'FilterAnwenden':
        $ft = isset($_GET['ft']) ? array_values(array_filter((array)$_GET['ft'])) : [];
        $sf = isset($_GET['sf']) ? array_values(array_filter((array)$_GET['sf'])) : [];
        IPS_RequestAction($instId, 'FilterAnwenden', json_encode([
            'filterTypen'=>$ft, 'objektIdFilter'=>trim($_GET['oi']??''),
            'senderFilter'=>$sf, 'textFilter'=>trim($_GET['tf']??''),
        ], JSON_UNESCAPED_UNICODE));
        break;
    case 'SeiteVor':        IPS_RequestAction($instId, 'SeiteVor', ''); break;
    case 'SeiteZurueck':    IPS_RequestAction($instId, 'SeiteZurueck', ''); break;
    case 'SetzeMaxZeilen':  IPS_RequestAction($instId, 'SetzeMaxZeilen', (int)$v); break;
    case 'LogDateiAuswaehlen': IPS_RequestAction($instId, 'LogDateiAuswaehlen', $v); break;
    case 'SetzeBetriebsmodus': IPS_RequestAction($instId, 'SetzeBetriebsmodus', $v); break;
    case 'FilterReset':     IPS_RequestAction($instId, 'FilterReset', ''); break;
    case 'Aktualisieren':   LOGANALYZER_AktualisierenVisualisierung($instId); break;
}

header('Content-Type: text/html; charset=utf-8');
echo LOGANALYZER_ErstelleHtmlDirekt($instId);

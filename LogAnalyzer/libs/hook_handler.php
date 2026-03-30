<?php
// Hook-Handler für Log Analyzer IPSView
// Rendert die Visualisierung direkt als HTML-Seite

if (!isset($_IPS) || $_IPS['SENDER'] !== 'WebHook') {
    echo "Nur als WebHook aufrufbar.";
    return;
}

$instId = isset($LOGANALYZER_INSTANCE_ID) ? (int)$LOGANALYZER_INSTANCE_ID : 0;
if ($instId === 0 || !IPS_InstanceExists($instId)) {
    echo "Instanz nicht gefunden (ID=$instId).";
    return;
}

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';
$v = isset($_GET['v']) ? (string)$_GET['v'] : '';

// Aktion ausführen
if ($a !== '') {
    switch ($a) {
        case 'FilterAnwenden':
            $ft = isset($_GET['ft']) ? array_values(array_filter((array)$_GET['ft'])) : [];
            $sf = isset($_GET['sf']) ? array_values(array_filter((array)$_GET['sf'])) : [];
            IPS_RequestAction($instId, 'FilterAnwenden', json_encode([
                'filterTypen'    => $ft,
                'objektIdFilter' => trim((string)($_GET['oi'] ?? '')),
                'senderFilter'   => $sf,
                'textFilter'     => trim((string)($_GET['tf'] ?? '')),
            ], JSON_UNESCAPED_UNICODE));
            break;
        case 'SeiteVor':
            IPS_RequestAction($instId, 'SeiteVor', '');
            break;
        case 'SeiteZurueck':
            IPS_RequestAction($instId, 'SeiteZurueck', '');
            break;
        case 'SetzeMaxZeilen':
            IPS_RequestAction($instId, 'SetzeMaxZeilen', (int)$v);
            break;
        case 'LogDateiAuswaehlen':
            IPS_RequestAction($instId, 'LogDateiAuswaehlen', $v);
            break;
        case 'SetzeBetriebsmodus':
            IPS_RequestAction($instId, 'SetzeBetriebsmodus', $v);
            break;
        case 'FilterReset':
            IPS_RequestAction($instId, 'FilterReset', '');
            break;
        case 'Aktualisieren':
            LOGANALYZER_AktualisierenVisualisierung($instId);
            break;
    }
}

// HTML direkt rendern über Modul-Funktion
// LOGANALYZER_ErstelleHtmlDirekt gibt das fertige HTML zurück
header('Content-Type: text/html; charset=utf-8');
echo LOGANALYZER_ErstelleHtmlDirekt($instId);

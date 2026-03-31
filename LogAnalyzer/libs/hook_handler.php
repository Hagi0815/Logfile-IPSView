<?php
// Hook-Handler für Log Analyzer IPSView
if (!isset($_IPS) || $_IPS['SENDER'] !== 'WebHook') { echo "Nur als WebHook aufrufbar."; return; }
$instId = isset($LOGANALYZER_INSTANCE_ID) ? (int)$LOGANALYZER_INSTANCE_ID : 0;
if (!$instId || !IPS_InstanceExists($instId)) { echo "Instanz nicht gefunden."; return; }

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';
$v = isset($_GET['v']) ? (string)$_GET['v'] : '';

// Export-Aktionen direkt ausgeben (eigene Header)
if ($a === 'ExportPdf') {
    $scope = isset($_GET['scope']) ? (string)$_GET['scope'] : 'seite';
    header('Content-Type: text/html; charset=utf-8');
    echo LOGANALYZER_ExportierePdf($instId, $scope);
    return;
}
if ($a === 'ExportCsv') {
    $scope = isset($_GET['scope']) ? (string)$_GET['scope'] : 'seite';
    echo LOGANALYZER_ExportiereCsv($instId, $scope);
    return;
}

header('Content-Type: text/html; charset=utf-8');

// Aktionen mit komplexen Werten separat vorbereiten
switch ($a) {
    case 'FilterAnwenden':
        $ft = isset($_GET['ft']) ? array_values(array_filter((array)$_GET['ft'])) : [];
        $sf = isset($_GET['sf']) ? array_values(array_filter((array)$_GET['sf'])) : [];
        $v = json_encode([
            'filterTypen'   => $ft,
            'objektIdFilter'=> trim($_GET['oi'] ?? ''),
            'senderFilter'  => $sf,
            'textFilter'    => trim($_GET['tf'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        break;
    case 'SetzeMaxZeilen':
        $v = (string)(int)$v;
        break;
}

// Synchron: Aktion ausführen UND HTML rendern in einem Aufruf
echo LOGANALYZER_VerarbeiteHookAktion($instId, $a, $v);

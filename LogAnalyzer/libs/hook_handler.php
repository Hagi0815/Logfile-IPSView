<?php
// Hook-Handler für Log Analyzer IPSView
if (!isset($_IPS) || $_IPS['SENDER'] !== 'WebHook') { echo "Nur als WebHook aufrufbar."; return; }
$instId = isset($LOGANALYZER_INSTANCE_ID) ? (int)$LOGANALYZER_INSTANCE_ID : 0;
if (!$instId || !IPS_InstanceExists($instId)) { echo "Instanz nicht gefunden."; return; }

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';
$v = isset($_GET['v']) ? (string)$_GET['v'] : '';

// Statistik-Seite
if ($a === 'Statistik') {
    header('Content-Type: text/html; charset=utf-8');
    echo LOGANALYZER_ErstelleStatistik($instId);
    return;
}

// JSON-Detail-Endpunkte: Output-Buffer nutzen damit PHP-Warnings das JSON nicht verschmutzen
$jsonAktionen = ['HeatmapDetail', 'TrendDetail', 'StundenDetail', 'WochentagDetail'];
if (in_array($a, $jsonAktionen)) {
    ob_start();
    try {
        if ($a === 'HeatmapDetail') {
            $dow = isset($_GET['dow']) ? (int)$_GET['dow'] : -1;
            $h2  = isset($_GET['h'])   ? (int)$_GET['h']   : -1;
            $result = LOGANALYZER_HeatmapDetail($instId, $dow, $h2);
        } elseif ($a === 'TrendDetail') {
            $datum = isset($_GET['datum']) ? preg_replace('/[^0-9-]/', '', $_GET['datum']) : '';
            $result = LOGANALYZER_TrendDetail($instId, $datum);
        } elseif ($a === 'StundenDetail') {
            $datum = isset($_GET['datum']) ? $_GET['datum'] : 'heute';
            $h2    = isset($_GET['h'])     ? (int)$_GET['h'] : -1;
            $result = LOGANALYZER_StundenDetail($instId, $datum, $h2);
        } elseif ($a === 'WochentagDetail') {
            $dow = isset($_GET['dow']) ? (int)$_GET['dow'] : -1;
            $result = LOGANALYZER_WochentagDetail($instId, $dow);
        }
    } catch (Throwable $e) {
        $result = json_encode(['error' => $e->getMessage()]);
    }
    ob_end_clean(); // alle PHP-Warnings verwerfen
    header('Content-Type: application/json; charset=utf-8');
    echo $result ?? json_encode(['error' => 'Unbekannter Fehler']);
    return;
}

// ObjektID auflösen (JSON-API)
if ($a === 'ObjektIdAufloesen') {
    header('Content-Type: application/json; charset=utf-8');
    $oid = isset($_GET['oid']) ? (string)$_GET['oid'] : '0';
    echo LOGANALYZER_ObjektIdAufloesen($instId, $oid);
    return;
}

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

// Aktionen mit komplexen Werten vorbereiten
switch ($a) {
    case 'FilterAnwenden':
        $ft = isset($_GET['ft']) ? array_values(array_filter((array)$_GET['ft'])) : [];
        $sf = isset($_GET['sf']) ? array_values(array_filter((array)$_GET['sf'])) : [];
        $v = json_encode([
            'filterTypen'   => $ft,
            'objektIdFilter'=> trim($_GET['oi'] ?? ''),
            'senderFilter'  => $sf,
            'textFilter'    => trim($_GET['tf'] ?? ''),
            'zeitVon'       => trim($_GET['zv'] ?? ''),
            'zeitBis'       => trim($_GET['zb'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        break;
    case 'SetzeMaxZeilen':
        $v = (string)(int)$v;
        break;
    case 'Schnellfilter':
        // ft=Typ oder sf=Sender als GET-Parameter
        $sf = [];
        if (isset($_GET['ft']) && $_GET['ft'] !== '') $sf['ft'] = (string)$_GET['ft'];
        if (isset($_GET['sf']) && $_GET['sf'] !== '') $sf['sf'] = (string)$_GET['sf'];
        $v = json_encode($sf, JSON_UNESCAPED_UNICODE);
        break;
}

echo LOGANALYZER_VerarbeiteHookAktion($instId, $a, $v);

<?php
// KEIN declare(strict_types=1) – notwendig für ProcessHookData in IPS 8

class LogAnalyzerBase extends IPSModule
{
    public function ProcessHookData()
    {
        $a = isset($_GET['a']) ? (string)$_GET['a'] : '';
        $v = isset($_GET['v']) ? (string)$_GET['v'] : '';

        try {
            $status = $this->leseStatus();

            if ($a === 'FilterAnwenden') {
                $ft = isset($_GET['ft']) ? array_values(array_filter((array)$_GET['ft'])) : [];
                $sf = isset($_GET['sf']) ? array_values(array_filter((array)$_GET['sf'])) : [];
                $status['filterTypen']    = $ft;
                $status['objektIdFilter'] = trim((string)($_GET['oi'] ?? ''));
                $status['senderFilter']   = $sf;
                $status['textFilter']     = trim((string)($_GET['tf'] ?? ''));
                $status['seite']          = 0;
                $status['trefferGesamt']  = -1;
                $this->schreibeStatus($status);
                $this->leereSeitenCache();
            } elseif ($a === 'SeiteVor') {
                $status['seite'] = (int)$status['seite'] + 1;
                $this->schreibeStatus($status);
            } elseif ($a === 'SeiteZurueck') {
                $status['seite'] = max(0, (int)$status['seite'] - 1);
                $this->schreibeStatus($status);
            } elseif ($a === 'SetzeMaxZeilen') {
                $status['maxZeilen'] = $this->normalisiereMaxZeilen((int)$v);
                $status['seite']     = 0;
                $this->schreibeStatus($status);
            } elseif ($a === 'LogDateiAuswaehlen') {
                if (is_file($v)) {
                    $this->WriteAttributeString('AktuelleLogDatei', $v);
                    $status['seite']         = 0;
                    $status['trefferGesamt'] = -1;
                    $status['filterTypen']   = [];
                    $status['senderFilter']  = [];
                    $this->schreibeStatus($status);
                    $this->schreibeFilterMetadaten([
                        'verfuegbareFilterTypen' => [], 'verfuegbareSender' => [],
                        'gesamtZeilenCache' => -1, 'dateiGroesseCache' => 0,
                        'dateiMTimeCache' => 0, 'ladezeitMs' => 0,
                        'laedt' => false, 'signatur' => '',
                    ]);
                    $this->leereSeitenCache();
                }
            } elseif ($a === 'SetzeBetriebsmodus') {
                $m = strtolower(trim($v));
                if (in_array($m, ['standard', 'system'])) {
                    IPS_SetProperty($this->InstanceID, 'Betriebsmodus', $m);
                }
            } elseif ($a === 'FilterReset') {
                $status['filterTypen']    = [];
                $status['senderFilter']   = [];
                $status['textFilter']     = '';
                $status['objektIdFilter'] = '';
                $status['seite']          = 0;
                $status['trefferGesamt']  = -1;
                $this->schreibeStatus($status);
                $this->leereSeitenCache();
            }

            $this->aktualisiereVisualisierung();
        } catch (\Throwable $e) {
            $this->SendDebug('Hook Fehler', $e->getMessage(), 0);
        }

        echo $this->GetValue('HTMLBOX');
    }
}

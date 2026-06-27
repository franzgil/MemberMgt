<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Mitglied;
use App\Models\SumUp;

/**
 * SumUp-Zahlungen auf Abfrage anzeigen (Abgleichshilfe für den Trésorier).
 * Es werden nur Daten gelesen; kein Cron, kein Schreiben in SumUp.
 */
class SumupController extends Controller
{
    public function index(): void
    {
        if (!Auth::darfBeitragBestaetigen()) {
            flash('errors', 'Nur Mitglieder der Trésorier-Gruppe können SumUp-Zahlungen abrufen.');
            $this->redirect('/');
            return;
        }

        $sumup = new SumUp();
        $result = null;
        if (isset($_GET['abrufen'])) {
            $result = $sumup->recentTransactions(30);
        }

        // Diagnose, falls der Key nicht gefunden wird (zeigt nur Feldnamen/Länge,
        // niemals den Key selbst).
        $diag = null;
        if (!$sumup->configured()) {
            $cfg = Auth::config();
            $dir = defined('CONFIG_DIR') ? CONFIG_DIR : ROOT . '/config';
            $val = (string) ($cfg['sumup_api_key'] ?? ($cfg['SUMUP_API_KEY'] ?? ''));
            $env = getenv('SUMUP_API_KEY');
            $diag = [
                'file'        => $dir . '/auth.php',
                'file_exists' => is_file($dir . '/auth.php'),
                'enabled'     => Auth::enabled(),
                'keys'        => array_keys($cfg),
                'key_present' => array_key_exists('sumup_api_key', $cfg) || array_key_exists('SUMUP_API_KEY', $cfg),
                'key_len'     => strlen(trim($val)),
                'env_set'     => is_string($env) && trim($env) !== '',
            ];
        }

        // Offene Anträge (Fördermitglieder) zur manuellen Zuordnung anzeigen.
        $offene = (new Mitglied())->all(['status' => 'antrag']);

        $this->view('sumup/index', [
            'titel'      => 'SumUp-Zahlungen',
            'configured' => $sumup->configured(),
            'result'     => $result,
            'offene'     => $offene,
            'diag'       => $diag,
        ]);
    }
}

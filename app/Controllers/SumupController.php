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

        // Offene Anträge (Fördermitglieder) zur manuellen Zuordnung anzeigen.
        $offene = (new Mitglied())->all(['status' => 'antrag']);

        $this->view('sumup/index', [
            'titel'      => 'SumUp-Zahlungen',
            'configured' => $sumup->configured(),
            'result'     => $result,
            'offene'     => $offene,
        ]);
    }
}

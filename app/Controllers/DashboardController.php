<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Mitgliedschaft;
use App\Models\Antrag;
use App\Models\Beitrag;
use App\Models\Mitglied;

class DashboardController extends Controller
{
    public function index(): void
    {
        $mitglieder = new Mitglied();
        $beitrag = new Beitrag();
        $antrag = new Antrag();

        $statusCounts = $mitglieder->statusCounts();

        // Abgelaufene Mitgliedschaften unter den aktiven Mitgliedern zählen.
        $aktive = $mitglieder->all(['status' => 'aktiv']);
        $bezahlt = $beitrag->paidYearsForMany(array_column($aktive, 'id'));
        $abgelaufen = 0;
        foreach ($aktive as $m) {
            if (Mitgliedschaft::bewerten($m, $bezahlt[(int) $m['id']] ?? [])['status']
                === Mitgliedschaft::ABGELAUFEN) {
                $abgelaufen++;
            }
        }

        $this->view('dashboard/index', [
            'titel'         => 'Dashboard',
            'total'         => $mitglieder->total(),
            'statusCounts'  => $statusCounts,
            // Online-Anträge aus dem WoltLab-Formular (nicht die Alt-Mitglieder
            // mit Status 'antrag' – die bleiben in der Status-Übersicht sichtbar).
            'onlineAntraege' => $antrag->count(),
            'abgelaufen'    => $abgelaufen,
            'bezahltJahr'   => $beitrag->countPaid(AKTUELLES_JAHR),
            'aktuellesJahr' => AKTUELLES_JAHR,
            'foerderCount'  => $mitglieder->countTyp('foerder'),
            'avgVoll'       => $mitglieder->durchschnittVollstaendigkeit(),
            'aktiveOhneForum' => $mitglieder->aktiveOhneForum(),
            'luecken'       => [
                'ohne E-Mail'        => $mitglieder->countMissing('email'),
                'ohne Telefon'       => $mitglieder->countMissing('telefon'),
                'ohne Geburtsdatum'  => $mitglieder->countMissing('geburtsdatum'),
                'ohne Adresse (Ort)' => $mitglieder->countMissing('ort'),
            ],
        ]);
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
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

        $this->view('dashboard/index', [
            'titel'         => 'Dashboard',
            'total'         => $mitglieder->total(),
            'statusCounts'  => $statusCounts,
            // Online-Anträge aus dem WoltLab-Formular (nicht die Alt-Mitglieder
            // mit Status 'antrag' – die bleiben in der Status-Übersicht sichtbar).
            'onlineAntraege' => $antrag->count(),
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

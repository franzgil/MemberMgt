<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\GroupSync;

/**
 * Manueller Vollabgleich der beiden WoltLab-Gruppen (Aktive / Sympathisant)
 * mit den Mitgliedsdaten. Automatisch werden die Gruppen ohnehin bei
 * Bestätigung/Bearbeiten/Austritt/Löschen gepflegt.
 */
class GruppenController extends Controller
{
    public function index(): void
    {
        if (!Auth::darfLoeschen()) {
            flash('errors', 'Keine Berechtigung.');
            $this->redirect('/');
            return;
        }
        $result = $_SESSION['gruppen_result'] ?? null;
        unset($_SESSION['gruppen_result']);

        $this->view('gruppen/index', [
            'titel'      => 'Gruppen-Abgleich',
            'verfuegbar' => (new GroupSync())->verfuegbar(),
            'aktivId'    => Auth::gruppeAktivId(),
            'foerderId'  => Auth::gruppeFoerderId(),
            'result'     => $result,
        ]);
    }

    public function sync(): void
    {
        $this->verifyCsrf();
        if (!Auth::darfLoeschen()) {
            flash('errors', 'Keine Berechtigung.');
            $this->redirect('/');
            return;
        }
        $_SESSION['gruppen_result'] = (new GroupSync())->syncAll();
        flash('success', 'Gruppen-Abgleich ausgeführt.');
        $this->redirect('/gruppen');
    }
}

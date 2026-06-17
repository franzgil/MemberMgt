<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Beitrag;
use App\Models\Mitglied;

class MitgliederController extends Controller
{
    private Mitglied $mitglieder;
    private Beitrag $beitraege;

    public function __construct()
    {
        $this->mitglieder = new Mitglied();
        $this->beitraege = new Beitrag();
    }

    /** Liste mit Suche und Status-Filter. */
    public function index(): void
    {
        $filters = [
            'q'      => trim($_GET['q'] ?? ''),
            'status' => $_GET['status'] ?? '',
        ];
        $this->view('mitglieder/index', [
            'titel'  => 'Mitglieder',
            'liste'  => $this->mitglieder->all($filters),
            'filters' => $filters,
            'status' => Mitglied::STATUS,
        ]);
    }

    public function show(string $id = '0'): void
    {
        $mitglied = $this->mitglieder->find((int) $id);
        if ($mitglied === null) {
            $this->notFound();
            return;
        }
        $this->view('mitglieder/show', [
            'titel'    => $mitglied['vorname'] . ' ' . $mitglied['nachname'],
            'm'        => $mitglied,
            'beitraege' => $this->beitraege->forMitglied((int) $id),
            'arten'    => Beitrag::ARTEN,
            'jahr'     => AKTUELLES_JAHR,
        ]);
    }

    public function create(): void
    {
        $this->view('mitglieder/create', [
            'titel'  => 'Neuer Antrag / Mitglied',
            'status' => Mitglied::STATUS,
        ]);
    }

    public function store(): void
    {
        $this->verifyCsrf();
        $data = $_POST;
        $errors = $this->mitglieder->validate($data);

        if ($errors) {
            $_SESSION['old'] = $data;
            flash('errors', implode("\n", $errors));
            $this->redirect('/mitglieder/create');
            return;
        }

        unset($_SESSION['old']);
        $id = $this->mitglieder->create($data);
        flash('success', 'Datensatz angelegt.');
        $this->redirect('/mitglieder/show/' . $id);
    }

    public function edit(string $id = '0'): void
    {
        $mitglied = $this->mitglieder->find((int) $id);
        if ($mitglied === null) {
            $this->notFound();
            return;
        }
        $this->view('mitglieder/edit', [
            'titel'  => 'Bearbeiten: ' . $mitglied['vorname'] . ' ' . $mitglied['nachname'],
            'm'      => $mitglied,
            'status' => Mitglied::STATUS,
        ]);
    }

    public function update(string $id = '0'): void
    {
        $this->verifyCsrf();
        $id = (int) $id;
        if ($this->mitglieder->find($id) === null) {
            $this->notFound();
            return;
        }
        $data = $_POST;
        $errors = $this->mitglieder->validate($data, $id);

        if ($errors) {
            $_SESSION['old'] = $data;
            flash('errors', implode("\n", $errors));
            $this->redirect('/mitglieder/edit/' . $id);
            return;
        }

        unset($_SESSION['old']);
        $this->mitglieder->update($id, $data);
        flash('success', 'Änderungen gespeichert.');
        $this->redirect('/mitglieder/show/' . $id);
    }

    /**
     * Trésorier bestätigt die Zahlung → Beitrag fürs Jahr + Status „aktiv".
     */
    public function bestaetigen(string $id = '0'): void
    {
        $this->verifyCsrf();
        $id = (int) $id;
        $mitglied = $this->mitglieder->find($id);
        if ($mitglied === null) {
            $this->notFound();
            return;
        }

        $jahr = (int) ($_POST['jahr'] ?? AKTUELLES_JAHR);
        $art = $_POST['art'] ?? 'ueberweisung';
        $betrag = trim($_POST['betrag'] ?? '');
        $betrag = $betrag === '' ? null : (float) str_replace(',', '.', $betrag);

        // Beitrag verbuchen (Trésorier = aktuell eingeloggter WoltLab-User; später ersetzt)
        $this->beitraege->confirm($id, $jahr, $betrag, $art, $mitglied['wcf_user_id'] ?? null);

        // Antrag wird zum Mitglied
        $update = ['status' => 'aktiv'];
        if (empty($mitglied['beitrittsdatum'])) {
            $update['beitrittsdatum'] = date('Y-m-d');
        }
        // bestehende Pflichtfelder mitschreiben, damit validate() nicht greift:
        $update = array_merge($mitglied, $update);
        $this->mitglieder->update($id, $update);

        flash('success', "Zahlung für $jahr bestätigt – Mitglied ist jetzt aktiv.");
        $this->redirect('/mitglieder/show/' . $id);
    }

    /** Austritt: archivieren. */
    public function archivieren(string $id = '0'): void
    {
        $this->verifyCsrf();
        $id = (int) $id;
        if ($this->mitglieder->find($id) === null) {
            $this->notFound();
            return;
        }
        $this->mitglieder->archive($id);
        flash('success', 'Mitglied wurde als ausgetreten archiviert.');
        $this->redirect('/mitglieder/show/' . $id);
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->view('mitglieder/notfound', ['titel' => 'Nicht gefunden']);
    }
}

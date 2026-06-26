<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Mitgliedschaft;
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
            'q'       => trim($_GET['q'] ?? ''),
            'status'  => $_GET['status'] ?? '',
            'gueltig' => $_GET['gueltig'] ?? '',
        ];
        $liste = $this->mitglieder->all($filters);
        $bezahlt = $this->beitraege->paidYearsForMany(array_column($liste, 'id'));
        $gueltigkeit = [];
        foreach ($liste as $m) {
            $gueltigkeit[(int) $m['id']] = Mitgliedschaft::bewerten($m, $bezahlt[(int) $m['id']] ?? []);
        }

        // Nachgelagerter Filter auf die (in PHP berechnete) Gültigkeit.
        if (in_array($filters['gueltig'], Mitgliedschaft::FILTER, true)) {
            $liste = array_values(array_filter(
                $liste,
                fn ($m) => $gueltigkeit[(int) $m['id']]['status'] === $filters['gueltig']
            ));
        }

        $this->view('mitglieder/index', [
            'titel'  => 'Mitglieder',
            'liste'  => $liste,
            'gueltigkeit' => $gueltigkeit,
            'cardUrl' => \App\Core\Auth::cardUrl(),
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
        $beitraege = $this->beitraege->forMitglied((int) $id);
        $bezahlteJahre = [];
        foreach ($beitraege as $b) {
            if (!empty($b['bezahlt_am'])) {
                $bezahlteJahre[] = (int) $b['jahr'];
            }
        }
        // Namen der bestätigenden Trésoriers auflösen (wcf1_user).
        $bestaetiger = (new \App\Models\WoltlabUser())
            ->namesById(array_column($beitraege, 'bestaetigt_durch'));

        $this->view('mitglieder/show', [
            'titel'    => $mitglied['vorname'] . ' ' . $mitglied['nachname'],
            'm'        => $mitglied,
            'beitraege' => $beitraege,
            'bestaetiger' => $bestaetiger,
            'gueltigkeit' => Mitgliedschaft::bewerten($mitglied, $bezahlteJahre),
            'darfBestaetigen' => \App\Core\Auth::darfBeitragBestaetigen(),
            'darfLoeschen' => \App\Core\Auth::darfLoeschen(),
            'cardUrl'  => \App\Core\Auth::cardUrl(),
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

        if (!\App\Core\Auth::darfBeitragBestaetigen()) {
            flash('errors', 'Nur Mitglieder der Trésorier-Gruppe dürfen Zahlungen bestätigen.');
            $this->redirect('/mitglieder/show/' . $id);
            return;
        }

        $mitglied = $this->mitglieder->find($id);
        if ($mitglied === null) {
            $this->notFound();
            return;
        }

        $jahr = (int) ($_POST['jahr'] ?? AKTUELLES_JAHR);
        $art = $_POST['art'] ?? 'virement';
        $betrag = trim($_POST['betrag'] ?? '');
        $betrag = $betrag === '' ? null : (float) str_replace(',', '.', $betrag);
        // Zahldatum auflösen (leer/ungültig -> heute); auch fürs Beitrittsdatum.
        $bezahltAm = trim($_POST['bezahlt_am'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bezahltAm)) {
            $bezahltAm = date('Y-m-d');
        }

        // Beitrag verbuchen – bestätigt durch den eingeloggten Trésorier (WoltLab)
        $tresorier = \App\Core\Auth::user()['userID'] ?? null;
        $this->beitraege->confirm($id, $jahr, $betrag, $art, $tresorier, $bezahltAm);

        // War es eine Aktivierung (aus Antrag) oder eine Erneuerung (schon aktiv)?
        $warAktiv = ($mitglied['status'] ?? '') === 'aktiv';

        $update = ['status' => 'aktiv'];
        if (empty($mitglied['beitrittsdatum'])) {
            $update['beitrittsdatum'] = $bezahltAm;
        }
        // bestehende Pflichtfelder mitschreiben, damit validate() nicht greift:
        $update = array_merge($mitglied, $update);
        $this->mitglieder->update($id, $update);

        flash('success', $warAktiv
            ? "Beitrag für $jahr bestätigt – Mitgliedschaft verlängert."
            : "Zahlung für $jahr bestätigt – Mitglied ist jetzt aktiv.");
        $this->redirect('/mitglieder/show/' . $id);
    }

    /**
     * Hartes Löschen eines Datensatzes (Admin/Trésorier) – z. B. doppelter
     * Antrag oder Fehleingabe. Entfernt auch die zugehörigen Beiträge (FK CASCADE).
     */
    public function loeschen(string $id = '0'): void
    {
        $this->verifyCsrf();
        $id = (int) $id;

        if (!\App\Core\Auth::darfLoeschen()) {
            flash('errors', 'Keine Berechtigung zum Löschen.');
            $this->redirect('/mitglieder/show/' . $id);
            return;
        }
        if ($this->mitglieder->find($id) === null) {
            $this->notFound();
            return;
        }
        $this->mitglieder->delete($id);
        flash('success', 'Datensatz wurde endgültig gelöscht.');
        $this->redirect('/mitglieder');
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

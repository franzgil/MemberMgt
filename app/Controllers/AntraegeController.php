<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Antrag;
use App\Models\Mitglied;

/**
 * Verwaltung der Online-Anträge aus dem WoltLab-Formular.
 */
class AntraegeController extends Controller
{
    private Antrag $antraege;
    private Mitglied $mitglieder;

    public function __construct()
    {
        $this->antraege = new Antrag();
        $this->mitglieder = new Mitglied();
    }

    public function index(): void
    {
        if (!$this->antraege->verfuegbar()) {
            $this->view('antraege/nicht_verfuegbar', ['titel' => 'Online-Anträge']);
            return;
        }
        $liste = $this->antraege->all();
        $neu = 0;
        foreach ($liste as $a) {
            if (!$a['bereits_erfasst'] && $a['lesbar']) {
                $neu++;
            }
        }
        $this->view('antraege/index', [
            'titel' => 'Online-Anträge',
            'liste' => $liste,
            'neu'   => $neu,
        ]);
    }

    public function show(string $id = '0'): void
    {
        $a = $this->antraege->find((int) $id);
        if ($a === null) {
            http_response_code(404);
            $this->view('mitglieder/notfound', ['titel' => 'Nicht gefunden']);
            return;
        }
        $this->view('antraege/show', [
            'titel' => 'Antrag: ' . $a['vorname'] . ' ' . $a['nachname'],
            'a'     => $a,
        ]);
    }

    /** Einen Antrag in die Mitgliederverwaltung übernehmen (Status 'antrag'). */
    public function uebernehmen(string $id = '0'): void
    {
        $this->verifyCsrf();
        $a = $this->antraege->find((int) $id);
        if ($a === null) {
            $this->redirect('/antraege');
            return;
        }
        if ($a['bereits_erfasst']) {
            flash('errors', 'Dieser Antragsteller ist bereits in der Verwaltung erfasst.');
            $this->redirect('/antraege');
            return;
        }
        $newId = $this->mitglieder->create($this->antraege->alsMitgliedDaten($a));
        flash('success', 'Antrag übernommen – als Mitglied mit Status „antrag" angelegt.');
        $this->redirect('/mitglieder/show/' . $newId);
    }

    /** Alle neuen, lesbaren Anträge auf einmal übernehmen. */
    public function alleUebernehmen(): void
    {
        $this->verifyCsrf();
        $liste = $this->antraege->all();
        $seenEmail = [];
        $seenForum = [];
        $n = 0;
        foreach ($liste as $a) {
            if ($a['bereits_erfasst'] || !$a['lesbar']) {
                continue;
            }
            $email = $a['email'] ? mb_strtolower($a['email']) : null;
            $forum = $a['forum_name'] ? mb_strtolower($a['forum_name']) : null;
            if (($email && isset($seenEmail[$email])) || ($forum && isset($seenForum[$forum]))) {
                continue; // Dublette innerhalb des Laufs
            }
            $this->mitglieder->create($this->antraege->alsMitgliedDaten($a));
            if ($email) {
                $seenEmail[$email] = true;
            }
            if ($forum) {
                $seenForum[$forum] = true;
            }
            $n++;
        }
        flash('success', $n . ' neue Anträge übernommen.');
        $this->redirect('/antraege');
    }
}

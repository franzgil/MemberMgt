<?php
/** @var array $liste @var array $gueltigkeit @var string $cardUrl @var array $filters @var array $status */
use App\Core\Mitgliedschaft;

/** Kleine Badge-Hilfe für die Gültigkeit der Mitgliedschaft. */
$gueltigBadge = static function (?array $g): string {
    if ($g === null) {
        return '<span class="muted">–</span>';
    }
    switch ($g['status']) {
        case Mitgliedschaft::GUELTIG:
            return '<span class="badge badge-aktiv" title="Mitgliedsjahr ' . e((string) $g['bis'])
                . '">gültig ' . e((string) $g['bis']) . '</span>';
        case Mitgliedschaft::ABGELAUFEN:
            return '<span class="badge badge-erneuern" title="Erneuerung nötig – zuletzt gedeckt bis '
                . e((string) $g['bis']) . '">erneuern</span>';
        case Mitgliedschaft::UNBEKANNT:
            return '<span class="muted" title="Kein Beitritts-/Beitragsjahr bekannt">?</span>';
        default:
            return '<span class="muted">–</span>';
    }
};
?>
<div class="page-head">
    <h1>Mitglieder</h1>
    <a class="btn-primary" href="<?= url('/mitglieder/create') ?>">+ Neuer Antrag</a>
</div>

<form class="filterbar" method="get" action="<?= url('/mitglieder') ?>">
    <input type="search" name="q" placeholder="Name, E-Mail, Nr., Forenname…"
           value="<?= e($filters['q']) ?>">
    <select name="status">
        <option value="">Alle Status</option>
        <?php foreach ($status as $st): ?>
            <option value="<?= e($st) ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>>
                <?= e(ucfirst($st)) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php $gOpts = ['' => 'Alle Gültigkeit', Mitgliedschaft::GUELTIG => 'gültig',
                    Mitgliedschaft::ABGELAUFEN => 'abgelaufen', Mitgliedschaft::UNBEKANNT => 'ohne Angabe']; ?>
    <select name="gueltig">
        <?php foreach ($gOpts as $val => $label): ?>
            <option value="<?= e($val) ?>" <?= ($filters['gueltig'] ?? '') === $val ? 'selected' : '' ?>>
                <?= e($label) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filtern</button>
</form>

<?php
/** Tabelle für eine Mitglieder-Teilmenge rendern. */
$tabelle = static function (array $rows, bool $mitMatricule = false) use ($gueltigBadge, $gueltigkeit, $cardUrl): void {
    if (!$rows) {
        echo '<p class="muted">Keine Einträge in dieser Gruppe.</p>';
        return;
    }
    ?>
    <table class="data">
        <thead>
            <tr>
                <th>Nr.</th><th>Name</th>
                <?php if ($mitMatricule): ?><th>Matricule</th><?php endif; ?>
                <th>E-Mail</th><th>Forum</th>
                <th>Status</th><th>Gültig</th><th class="num">Vollst.</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $m): ?>
            <tr>
                <td><?= e($m['mitgliedsnummer']) ?></td>
                <td><strong><?= e($m['nachname']) ?></strong>, <?= e($m['vorname']) ?></td>
                <?php if ($mitMatricule): ?><td><?= e($m['matricule']) ?></td><?php endif; ?>
                <td><?= e($m['email']) ?></td>
                <td><?= e($m['forum_name']) ?></td>
                <td><span class="badge badge-<?= e($m['status']) ?>"><?= e($m['status']) ?></span></td>
                <td><?= $gueltigBadge($gueltigkeit[(int) $m['id']] ?? null) ?></td>
                <td class="num"><?= e($m['vollstaendigkeit']) ?>%</td>
                <td class="row-actions">
                    <a href="<?= url('/mitglieder/show/' . $m['id']) ?>">Details</a>
                    <?php if (!empty($cardUrl) && !empty($m['wcf_user_id'])): ?>
                        <a class="card-link" target="_blank" rel="noopener"
                           title="Mitgliedskarte erzeugen" aria-label="Mitgliedskarte erzeugen"
                           href="<?= e($cardUrl) ?>?page=home&amp;userID=<?= e((string) $m['wcf_user_id']) ?>">🪪</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
};

// Vorstand = Matricule ausgefüllt. Diese werden aus Aktive/Förder herausgenommen,
// damit niemand doppelt erscheint. Rest nach Typ trennen.
$hatMatricule = static fn ($m) => trim((string) ($m['matricule'] ?? '')) !== '';
$vorstand = array_values(array_filter($liste, $hatMatricule));
$rest     = array_filter($liste, static fn ($m) => !$hatMatricule($m));
$aktive   = array_values(array_filter($rest, static fn ($m) => ($m['typ'] ?? '') !== 'foerder'));
$foerder  = array_values(array_filter($rest, static fn ($m) => ($m['typ'] ?? '') === 'foerder'));
?>

<?php if (!$liste): ?>
    <p class="muted">Keine Datensätze gefunden.</p>
<?php else: ?>
    <details class="liste-gruppe" open>
        <summary>Vorstand <span class="muted">(<?= count($vorstand) ?>)</span></summary>
        <?php $tabelle($vorstand, true); ?>
    </details>

    <details class="liste-gruppe" open>
        <summary>Aktive Mitglieder <span class="muted">(<?= count($aktive) ?>)</span></summary>
        <?php $tabelle($aktive); ?>
    </details>

    <details class="liste-gruppe" open>
        <summary>Fördermitglieder <span class="muted">(<?= count($foerder) ?>)</span></summary>
        <?php $tabelle($foerder); ?>
    </details>

    <p class="muted"><?= count($liste) ?> Datensätze gesamt</p>
<?php endif; ?>

<?php
/** @var array $liste @var array $gueltigkeit @var array $filters @var array $status */
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
$tabelle = static function (array $rows) use ($gueltigBadge, $gueltigkeit): void {
    if (!$rows) {
        echo '<p class="muted">Keine Einträge in dieser Gruppe.</p>';
        return;
    }
    ?>
    <table class="data">
        <thead>
            <tr>
                <th>Nr.</th><th>Name</th><th>E-Mail</th><th>Forum</th>
                <th>Status</th><th>Gültig</th><th class="num">Vollst.</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $m): ?>
            <tr>
                <td><?= e($m['mitgliedsnummer']) ?></td>
                <td><strong><?= e($m['nachname']) ?></strong>, <?= e($m['vorname']) ?></td>
                <td><?= e($m['email']) ?></td>
                <td><?= e($m['forum_name']) ?></td>
                <td><span class="badge badge-<?= e($m['status']) ?>"><?= e($m['status']) ?></span></td>
                <td><?= $gueltigBadge($gueltigkeit[(int) $m['id']] ?? null) ?></td>
                <td class="num"><?= e($m['vollstaendigkeit']) ?>%</td>
                <td><a href="<?= url('/mitglieder/show/' . $m['id']) ?>">Details</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
};

// Nach Typ trennen: Aktive Mitglieder und Fördermitglieder.
$aktive  = array_values(array_filter($liste, static fn ($m) => ($m['typ'] ?? '') !== 'foerder'));
$foerder = array_values(array_filter($liste, static fn ($m) => ($m['typ'] ?? '') === 'foerder'));
?>

<?php if (!$liste): ?>
    <p class="muted">Keine Datensätze gefunden.</p>
<?php else: ?>
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

<?php
/** @var array $a @var string $csrf */
function feld($label, $value) {
    echo '<div class="kv"><span class="k">' . e($label) . '</span><span class="val">'
        . ($value !== null && $value !== '' ? e($value) : '<em class="muted">–</em>') . '</span></div>';
}
?>
<div class="page-head">
    <h1>Antrag: <?= e($a['vorname'] . ' ' . $a['nachname']) ?>
        <?php if ($a['bereits_erfasst']): ?>
            <span class="badge badge-aktiv">bereits erfasst</span>
        <?php else: ?>
            <span class="badge badge-antrag">neu</span>
        <?php endif; ?>
    </h1>
    <a class="btn" href="<?= url('/antraege') ?>">Zurück</a>
</div>

<?php if (!$a['lesbar']): ?>
    <div class="alert alert-error">Dieser Antrag konnte nicht gelesen werden (defektes JSON).</div>
<?php endif; ?>

<div class="grid-2">
    <section class="panel">
        <h2>Antragsdaten</h2>
        <?php
        feld('Antragsdatum', $a['antragsdatum']);
        feld('Vorname', $a['vorname']);
        feld('Nachname', $a['nachname']);
        feld('Geburtsdatum', $a['geburtsdatum']);
        feld('E-Mail', $a['email']);
        feld('Telefon', $a['telefon']);
        feld('Adresse', trim(($a['hausnummer'] ?? '') . ' ' . ($a['strasse'] ?? '')));
        feld('PLZ / Ort', trim(($a['plz'] ?? '') . ' ' . ($a['ort'] ?? '')));
        feld('Land', $a['land']);
        feld('Forenname', $a['forum_name']);
        feld('WoltLab-User-ID', $a['wcf_user_id']);
        feld('Sprachen', $a['sprachen']);
        ?>
    </section>

    <section class="panel">
        <h2>Übernehmen</h2>
        <?php if ($a['bereits_erfasst']): ?>
            <p class="muted">Dieser Antragsteller ist bereits in der Mitgliederverwaltung
            erfasst (Abgleich über E-Mail, Forenname oder WoltLab-Konto).</p>
        <?php elseif (!$a['lesbar']): ?>
            <p class="muted">Nicht lesbare Anträge können nicht automatisch übernommen werden.</p>
        <?php else: ?>
            <p>Diesen Antrag als Mitglied mit Status <strong>„antrag"</strong> anlegen.
               Die Bestätigung der Zahlung (durch den Trésorier) erfolgt anschließend
               in der Mitgliederverwaltung.</p>
            <form method="post" action="<?= url('/antraege/uebernehmen/' . $a['responseID']) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="btn-primary">In die Mitgliederverwaltung übernehmen</button>
            </form>
        <?php endif; ?>
    </section>
</div>

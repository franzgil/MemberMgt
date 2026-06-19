<?php
/** @var array $m @var array $beitraege @var array $bestaetiger @var array $gueltigkeit
 *  @var bool $darfBestaetigen @var string $cardUrl @var array $arten @var int $jahr @var string $csrf */
function feld($label, $value) {
    echo '<div class="kv"><span class="k">' . e($label) . '</span><span class="val">'
        . ($value !== null && $value !== '' ? e($value) : '<em class="muted">–</em>') . '</span></div>';
}
?>
<div class="page-head">
    <h1><?= e($m['vorname'] . ' ' . $m['nachname']) ?>
        <span class="badge badge-<?= e($m['status']) ?>"><?= e($m['status']) ?></span>
    </h1>
    <div>
        <?php if (!empty($cardUrl) && !empty($m['wcf_user_id'])): ?>
            <a class="btn" target="_blank" rel="noopener"
               href="<?= e($cardUrl) ?>?page=card&amp;userID=<?= e((string) $m['wcf_user_id']) ?>">Mitgliedskarte (PDF)</a>
        <?php endif; ?>
        <a class="btn" href="<?= url('/mitglieder/edit/' . $m['id']) ?>">Bearbeiten</a>
        <a class="btn" href="<?= url('/mitglieder') ?>">Zurück</a>
    </div>
</div>

<p class="muted">Vollständigkeit: <strong><?= e($m['vollstaendigkeit']) ?>%</strong>
    <?php if ($m['mitgliedsnummer']): ?> · Nr. <?= e($m['mitgliedsnummer']) ?><?php endif; ?></p>

<?php
$g = $gueltigkeit ?? null;
if ($g && $g['status'] !== \App\Core\Mitgliedschaft::NA):
    if ($g['status'] === \App\Core\Mitgliedschaft::GUELTIG): ?>
        <p>Mitgliedschaft: <span class="badge badge-aktiv">gültig</span>
           <span class="muted">für das Mitgliedsjahr <?= e((string) $g['bis']) ?> (bis zur nächsten Generalversammlung)</span></p>
    <?php elseif ($g['status'] === \App\Core\Mitgliedschaft::ABGELAUFEN): ?>
        <p>Mitgliedschaft: <span class="badge badge-erneuern">erneuern</span>
           <span class="muted">zuletzt gedeckt bis <?= e((string) $g['bis']) ?>, Erneuerung für <?= e((string) $g['jahr']) ?> nötig</span></p>
    <?php else: ?>
        <p>Mitgliedschaft: <span class="muted">unbekannt (kein Beitritts-/Beitragsjahr hinterlegt)</span></p>
    <?php endif;
endif;
?>

<div class="grid-2">
    <section class="panel">
        <h2>Stammdaten</h2>
        <?php
        feld('Typ', \App\Models\Mitglied::TYPEN[$m['typ']] ?? $m['typ']);
        feld('E-Mail', $m['email']);
        feld('Telefon', $m['telefon']);
        feld('Geburtsdatum', $m['geburtsdatum']);
        feld('Geburtsort', $m['geburtsort']);
        feld('Geburtsland', $m['geburtsland']);
        feld('Matricule', $m['matricule']);
        feld('Adresse', trim(($m['hausnummer'] ?? '') . ' ' . ($m['strasse'] ?? '')));
        feld('PLZ / Ort', trim(($m['plz'] ?? '') . ' ' . ($m['ort'] ?? '')));
        feld('Land', $m['land']);
        feld('Forenname', $m['forum_name']);
        feld('WoltLab-User-ID', $m['wcf_user_id']);
        feld('Mitgliedskarte', $m['karte_ausgestellt'] ? 'ausgehändigt' : 'nein');
        feld('Beitritt', $m['beitrittsdatum']);
        feld('Quelle', $m['quelle']);
        if (!empty($m['bemerkung'])) feld('Bemerkung', $m['bemerkung']);
        ?>
    </section>

    <section class="panel">
        <h2>Beiträge (Cotisation)</h2>
        <?php if (!$beitraege): ?>
            <p class="muted">Noch keine Beiträge erfasst.</p>
        <?php else: ?>
            <table class="mini">
                <tr><th>Jahr</th><th>Betrag</th><th>Art</th><th>Bezahlt am</th><th>bestätigt durch</th></tr>
                <?php foreach ($beitraege as $b): ?>
                    <?php $bd = (int) ($b['bestaetigt_durch'] ?? 0); ?>
                    <tr>
                        <td><?= e($b['jahr']) ?></td>
                        <td><?= $b['betrag'] !== null ? e(number_format((float) $b['betrag'], 2, ',', '.')) . ' €' : '–' ?></td>
                        <td><?= $b['art'] ? e(\App\Models\Beitrag::artLabel($b['art'])) : '<span class="muted">–</span>' ?></td>
                        <td><?= e($b['bezahlt_am']) ?></td>
                        <td><?php
                            if ($bd && isset($bestaetiger[$bd])) {
                                echo e($bestaetiger[$bd]);
                            } elseif ($bd) {
                                echo '<span class="muted" title="WoltLab-User-ID">#' . e((string) $bd) . '</span>';
                            } else {
                                echo '<span class="muted">–</span>';
                            }
                        ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <h3>Zahlung bestätigen (Trésorier)</h3>
        <?php if (!empty($darfBestaetigen)): ?>
        <form class="form-inline" method="post" action="<?= url('/mitglieder/bestaetigen/' . $m['id']) ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <label>Jahr<input type="number" name="jahr" value="<?= e($jahr) ?>" style="width:5em"></label>
            <label>Bezahlt am<input type="date" name="bezahlt_am" value="<?= e(date('Y-m-d')) ?>"></label>
            <label>Betrag €<input name="betrag" placeholder="z. B. 25,00" style="width:7em"></label>
            <label>Art
                <select name="art">
                    <?php foreach ($arten as $a): ?>
                        <option value="<?= e($a) ?>"><?= e(\App\Models\Beitrag::artLabel($a)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn-primary">Bestätigen → aktiv</button>
        </form>
        <?php else: ?>
            <p class="muted">Nur Mitglieder der Trésorier-Gruppe können Zahlungen bestätigen.</p>
        <?php endif; ?>
    </section>
</div>

<?php if ($m['status'] !== 'ausgetreten'): ?>
<form method="post" action="<?= url('/mitglieder/archivieren/' . $m['id']) ?>"
      onsubmit="return confirm('Mitglied wirklich als ausgetreten archivieren?');" class="danger-zone">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button type="submit" class="btn-danger">Austritt / archivieren</button>
</form>
<?php endif; ?>

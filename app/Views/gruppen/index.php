<?php
/** @var bool $verfuegbar */
/** @var int $aktivId */
/** @var int $foerderId */
/** @var array|null $result */
?>
<h1>Gruppen-Abgleich (WoltLab)</h1>
<p class="muted">Hält die WoltLab-Benutzergruppen mit dem Mitgliedstyp synchron.
Bestätigte Mitglieder (Status „aktiv") kommen je nach Typ in genau eine der
beiden Gruppen; alle anderen werden aus beiden entfernt. Andere Gruppen der
Nutzer bleiben unberührt. Voraussetzung: das Mitglied hat eine
<code>wcf_user_id</code> (verknüpftes Forenkonto).</p>

<table class="data" style="max-width:520px;">
    <tbody>
        <tr><td>Aktive Mitglieder (typ&nbsp;=&nbsp;aktiv)</td><td>Gruppen-ID <strong><?= (int) $aktivId ?></strong></td></tr>
        <tr><td>Membres Sympathisant (typ&nbsp;=&nbsp;foerder)</td><td>Gruppen-ID <strong><?= (int) $foerderId ?></strong></td></tr>
    </tbody>
</table>

<?php if (!$verfuegbar): ?>
    <div class="alert alert-error" style="margin-top:1rem;">
        Das WoltLab-Framework ist nicht verfügbar – der Abgleich kann nicht laufen.
        (Läuft die App im WoltLab-Kontext? <code>config/auth.php</code> → <code>woltlab_global</code>.)
    </div>
<?php else: ?>
    <form method="post" action="<?= url('/gruppen/sync') ?>" style="margin-top:1rem;"
          onsubmit="return confirm('Alle Mitglieder mit den WoltLab-Gruppen abgleichen?');">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="btn-primary">Jetzt vollständig synchronisieren</button>
    </form>
<?php endif; ?>

<?php if ($result !== null): ?>
    <h2 style="margin-top:1.5rem;">Ergebnis</h2>
    <table class="data" style="max-width:520px;">
        <tbody>
            <tr><td>Geprüft (mit Konto)</td><td class="num"><?= (int) $result['geprueft'] ?></td></tr>
            <tr><td>Geändert</td><td class="num"><?= (int) $result['geaendert'] ?></td></tr>
            <tr><td>→ in „Aktive Mitglieder"</td><td class="num"><?= (int) $result['nach_aktiv'] ?></td></tr>
            <tr><td>→ in „Membres Sympathisant"</td><td class="num"><?= (int) $result['nach_foerder'] ?></td></tr>
            <tr><td>→ aus Gruppen entfernt</td><td class="num"><?= (int) $result['entfernt'] ?></td></tr>
            <tr><td>Ohne Forenkonto (übersprungen)</td><td class="num"><?= (int) $result['ohne_konto'] ?></td></tr>
            <tr><td>Fehler</td><td class="num"><?= (int) $result['fehler'] ?></td></tr>
        </tbody>
    </table>
    <?php if (!empty($result['fehlerListe'])): ?>
        <div class="alert alert-error" style="margin-top:.6rem;">
            <?php foreach ($result['fehlerListe'] as $z): ?>
                <div><?= e($z) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

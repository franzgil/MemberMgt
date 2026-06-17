<?php /** @var array $liste @var int $neu @var string $csrf */ ?>
<div class="page-head">
    <h1>Online-Anträge</h1>
    <?php if ($neu > 0): ?>
    <form method="post" action="<?= url('/antraege/alleUebernehmen') ?>"
          onsubmit="return confirm('<?= e($neu) ?> neue Anträge in die Mitgliederverwaltung übernehmen?');">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="btn-primary">Alle <?= e($neu) ?> neuen übernehmen</button>
    </form>
    <?php endif; ?>
</div>

<p class="muted">
    Live aus dem WoltLab-Formular. <strong><?= count($liste) ?></strong> Anträge,
    davon <strong><?= e($neu) ?></strong> neu (noch nicht in der Verwaltung).
</p>

<?php if (!$liste): ?>
    <p class="muted">Keine Anträge vorhanden.</p>
<?php else: ?>
<table class="data">
    <thead>
        <tr>
            <th>Datum</th><th>Name</th><th>Ort</th><th>E-Mail</th>
            <th>Forenname</th><th>Status</th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($liste as $a): ?>
        <tr>
            <td><?= e($a['antragsdatum']) ?></td>
            <td>
                <?php if (!$a['lesbar']): ?>
                    <em class="muted">— nicht lesbar —</em>
                <?php else: ?>
                    <strong><?= e($a['nachname']) ?></strong>, <?= e($a['vorname']) ?>
                <?php endif; ?>
            </td>
            <td><?= e($a['ort']) ?></td>
            <td><?= e($a['email']) ?></td>
            <td><?= e($a['forum_name']) ?></td>
            <td>
                <?php if (!$a['lesbar']): ?>
                    <span class="badge badge-abgelehnt">unlesbar</span>
                <?php elseif ($a['bereits_erfasst']): ?>
                    <span class="badge badge-aktiv">erfasst</span>
                <?php else: ?>
                    <span class="badge badge-antrag">neu</span>
                <?php endif; ?>
            </td>
            <td><a href="<?= url('/antraege/show/' . $a['responseID']) ?>">Details</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php
/** @var int $total @var array $statusCounts @var int $onlineAntraege @var int $abgelaufen */
/** @var int $bezahltJahr @var int $aktuellesJahr @var int $avgVoll */
/** @var int $aktiveOhneForum @var array $luecken */
?>
<h1>Dashboard</h1>

<section class="cards">
    <div class="card">
        <div class="card-num"><?= e($total) ?></div>
        <div class="card-label">Datensätze gesamt</div>
    </div>
    <div class="card">
        <div class="card-num"><?= e($statusCounts['aktiv']) ?></div>
        <div class="card-label">aktive Mitglieder</div>
    </div>
    <div class="card card-accent">
        <div class="card-num"><?= e($onlineAntraege) ?></div>
        <div class="card-label">Online-Anträge</div>
        <a href="<?= url('/antraege') ?>">ansehen →</a>
    </div>
    <div class="card<?= $abgelaufen > 0 ? ' card-accent' : '' ?>">
        <div class="card-num"><?= e($abgelaufen) ?></div>
        <div class="card-label">abgelaufen</div>
        <a href="<?= url('/mitglieder?gueltig=abgelaufen') ?>">erneuern →</a>
    </div>
    <div class="card">
        <div class="card-num"><?= e($bezahltJahr) ?></div>
        <div class="card-label">Beitrag <?= e($aktuellesJahr) ?> bezahlt</div>
    </div>
    <div class="card">
        <div class="card-num"><?= e($foerderCount) ?></div>
        <div class="card-label">Fördermitglieder</div>
    </div>
</section>

<div class="grid-2">
    <section class="panel">
        <h2>Mitglieder nach Status</h2>
        <table class="mini">
            <?php foreach ($statusCounts as $st => $n): ?>
                <tr>
                    <td><a href="<?= url('/mitglieder?status=' . urlencode($st)) ?>"><?= e(ucfirst($st)) ?></a></td>
                    <td class="num"><?= e($n) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>

    <section class="panel">
        <h2>Datenqualität</h2>
        <p class="big">Ø Vollständigkeit: <strong><?= e($avgVoll) ?>%</strong></p>
        <table class="mini">
            <?php if ($aktiveOhneForum > 0): ?>
                <tr class="warn">
                    <td>aktive Mitglieder ohne Forum-Account</td>
                    <td class="num"><?= e($aktiveOhneForum) ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($luecken as $label => $n): ?>
                <tr<?= $n > 0 ? ' class="warn"' : '' ?>>
                    <td><?= e($label) ?></td>
                    <td class="num"><?= e($n) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>
</div>

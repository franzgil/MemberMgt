<?php
/** @var bool $configured */
/** @var array|null $result */
/** @var array $offene */

/** Zeitstempel (ISO) hübsch ausgeben. */
$fmtTime = static function (string $iso): string {
    $ts = strtotime($iso);
    return $ts ? date('d.m.Y H:i', $ts) : e($iso);
};
$fmtAmount = static function (?float $a, string $cur): string {
    if ($a === null) {
        return '';
    }
    return number_format($a, 2, ',', '.') . ' ' . e($cur);
};
?>
<h1>SumUp-Zahlungen</h1>
<p class="muted">Abgleichshilfe: hier kannst du die letzten SumUp-Transaktionen
abrufen und mit den offenen Anträgen vergleichen. Bestätigt wird die Zahlung
weiterhin beim jeweiligen Mitglied (Detailseite → „Bestätigen → aktiv").</p>

<?php if (!$configured): ?>
    <div class="alert alert-error">
        Kein SumUp-API-Key hinterlegt. In <code>config/auth.php</code> den Wert
        <code>sumup_api_key</code> setzen (oder Umgebungsvariable
        <code>SUMUP_API_KEY</code>).
    </div>
    <?php if (!empty($diag)): ?>
        <div class="alert" style="background:#f6f8fa;border:1px solid #e1e1e1;">
            <strong>Diagnose</strong> (nur Feldnamen/Länge, nie der Key):
            <ul style="margin:.4rem 0 0 1.1rem;">
                <li>Config-Datei: <code><?= e($diag['file']) ?></code>
                    <?= $diag['file_exists'] ? '✓ vorhanden' : '✗ FEHLT' ?></li>
                <li>Schutz aktiv (enabled): <?= $diag['enabled'] ? 'ja' : 'nein' ?></li>
                <li>Geladene Config-Schlüssel: <code><?= e(implode(', ', $diag['keys'])) ?></code></li>
                <li>Schlüssel <code>sumup_api_key</code>/<code>SUMUP_API_KEY</code> vorhanden:
                    <?= $diag['key_present'] ? 'ja' : 'NEIN' ?></li>
                <li>Länge des Werts: <?= (int) $diag['key_len'] ?> Zeichen</li>
                <li>Umgebungsvariable <code>SUMUP_API_KEY</code> gesetzt:
                    <?= $diag['env_set'] ? 'ja' : 'nein' ?></li>
            </ul>
            <p class="muted" style="margin-top:.4rem;">
                Steht <code>sumup_api_key</code> nicht in der Schlüssel-Liste, wird eine
                andere/ältere <code>auth.php</code> geladen oder die Datei wurde nicht
                deployt. Ist die Länge 0, ist der Wert leer.
            </p>
        </div>
    <?php endif; ?>
<?php else: ?>
    <p>
        <a class="btn-primary" href="<?= url('/sumup?abrufen=1') ?>">Zahlungen abrufen</a>
    </p>
<?php endif; ?>

<?php if ($result !== null): ?>
    <?php if (empty($result['ok'])): ?>
        <div class="alert alert-error"><?= e($result['error'] ?? 'Unbekannter Fehler.') ?></div>
    <?php elseif (empty($result['items'])): ?>
        <p class="muted">Keine Transaktionen gefunden.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>Datum</th><th class="num">Betrag</th><th>Status</th>
                    <th>Name</th><th>Bemerkung</th>
                    <th>Zahlart</th><th>Transaktion</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['items'] as $t): ?>
                <tr>
                    <td><?= $fmtTime((string) $t['time']) ?></td>
                    <td class="num"><?= $fmtAmount($t['amount'], (string) $t['currency']) ?></td>
                    <td>
                        <?php $ok = strtoupper((string) $t['status']) === 'SUCCESSFUL'; ?>
                        <span class="badge badge-<?= $ok ? 'aktiv' : 'inaktiv' ?>"><?= e($t['status']) ?></span>
                    </td>
                    <td><?= e($t['name'] ?? '') ?: '<span class="muted">—</span>' ?></td>
                    <td><?= e($t['note'] ?? '') ?: '<span class="muted">—</span>' ?></td>
                    <td><?= e($t['payment']) ?></td>
                    <td class="muted"><?= e($t['code']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (empty(array_filter($result['items'], static fn ($t) => ($t['name'] ?? '') !== '' || ($t['note'] ?? '') !== ''))): ?>
            <p class="muted">Hinweis: SumUp liefert für diese Zahlungen offenbar
            keinen Namen/keine Bemerkung. Das hängt davon ab, ob euer Zahlungslink
            diese Felder erfasst. Unten siehst du die Rohdaten – sag mir, welches Feld
            den Namen enthält, dann blende ich es gezielt ein.</p>
        <?php endif; ?>

        <?php if (!empty($result['sample'])): ?>
            <details style="margin-top:1rem;">
                <summary>Rohdaten der 1. Transaktion (zur Feldanalyse)</summary>
                <pre style="white-space:pre-wrap;word-break:break-word;background:#f6f8fa;border:1px solid #e1e1e1;padding:.6rem;border-radius:6px;font-size:.8rem;max-height:380px;overflow:auto;"><?= e(json_encode($result['sample'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            </details>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<h2 style="margin-top:1.5rem;">Offene Anträge (<?= count($offene) ?>)</h2>
<?php if (!$offene): ?>
    <p class="muted">Keine offenen Anträge.</p>
<?php else: ?>
    <table class="data">
        <thead>
            <tr><th>Nr.</th><th>Name</th><th>E-Mail</th><th>Antrag vom</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($offene as $m): ?>
            <tr>
                <td><?= e($m['mitgliedsnummer']) ?></td>
                <td><strong><?= e($m['nachname']) ?></strong>, <?= e($m['vorname']) ?></td>
                <td><?= e($m['email']) ?></td>
                <td><?= e($m['antragsdatum']) ?></td>
                <td class="row-actions">
                    <a href="<?= url('/mitglieder/show/' . $m['id']) ?>">Öffnen / bestätigen</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

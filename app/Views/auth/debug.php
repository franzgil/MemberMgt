<?php
/** @var bool $enabled @var ?string $bootError @var ?array $user @var array $config
 *  @var bool $darfRein @var bool $darfBestaetigen */
?>
<h1>Login-Diagnose</h1>

<section class="panel">
    <h2>Status</h2>
    <div class="kv"><span class="k">Schutz aktiv</span><span class="val"><?= $enabled ? 'ja' : 'nein' ?></span></div>
    <div class="kv"><span class="k">WoltLab-Fehler</span><span class="val"><?= $bootError ? e($bootError) : '<em class="muted">keiner</em>' ?></span></div>
    <div class="kv"><span class="k">Zugriff erlaubt</span><span class="val"><?= $darfRein ? '✅ ja' : '❌ nein' ?></span></div>
    <div class="kv"><span class="k">Darf Beiträge bestätigen (Trésorier)</span><span class="val"><?= $darfBestaetigen ? '✅ ja' : '❌ nein' ?></span></div>
</section>

<section class="panel">
    <h2>Aktueller WoltLab-Benutzer</h2>
    <?php if ($user === null): ?>
        <p class="muted">Nicht eingeloggt (Gast) – oder WoltLab konnte nicht geladen werden.</p>
    <?php else: ?>
        <div class="kv"><span class="k">userID</span><span class="val"><?= e($user['userID']) ?></span></div>
        <div class="kv"><span class="k">Benutzername</span><span class="val"><?= e($user['username']) ?></span></div>
        <div class="kv"><span class="k">Gruppen-IDs</span><span class="val"><?= e(implode(', ', $user['groupIDs'])) ?></span></div>
        <div class="kv"><span class="k">Gruppen-Namen</span><span class="val"><?= e(implode(' · ', $user['groupNames'])) ?></span></div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Erlaubte Gruppen (config/auth.php)</h2>
    <div class="kv"><span class="k">Namen</span><span class="val"><?= e(implode(' · ', $config['allowed_groups'] ?? [])) ?: '<em class="muted">–</em>' ?></span></div>
    <div class="kv"><span class="k">IDs</span><span class="val"><?= e(implode(', ', $config['allowed_group_ids'] ?? [])) ?: '<em class="muted">–</em>' ?></span></div>
    <div class="kv"><span class="k">Trésorier-Gruppen</span><span class="val"><?= e(implode(' · ', $config['tresorier_groups'] ?? [])) ?: '<em class="muted">–</em>' ?></span></div>
    <div class="kv"><span class="k">Trésorier-IDs</span><span class="val"><?= e(implode(', ', $config['tresorier_group_ids'] ?? [])) ?: '<em class="muted">–</em>' ?></span></div>
    <p class="muted">Stimmen die Gruppen-Namen/IDs oben mit deiner Vorstandsgruppe überein?
        Falls nicht, in <code>config/auth.php</code> anpassen (IDs sind am sichersten).</p>
</section>

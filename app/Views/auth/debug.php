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
    <h2>Konfiguration (woher liest die App?)</h2>
    <div class="kv"><span class="k">Konfig-Verzeichnis (CONFIG_DIR)</span><span class="val"><?= e(defined('CONFIG_DIR') ? CONFIG_DIR : '(nicht gesetzt)') ?></span></div>
    <div class="kv"><span class="k">auth.php gefunden</span><span class="val"><?= (defined('CONFIG_DIR') && is_file(CONFIG_DIR . '/auth.php')) ? '✅ ja' : '❌ nein' ?></span></div>
    <div class="kv"><span class="k">card_url</span><span class="val"><?= !empty($config['card_url']) ? e($config['card_url']) : '<em class="muted">❌ fehlt – kein Karten-Button</em>' ?></span></div>
    <p class="muted">Die App liest <code>auth.php</code> und <code>database.php</code> aus dem
        oben genannten Verzeichnis. Ist ein <code>config/secrets-dir.php</code> gesetzt, zeigt
        CONFIG_DIR dorthin – dann muss <code>card_url</code> in <em>jener</em> auth.php stehen.</p>
</section>

<section class="panel">
    <h2>Erlaubte Gruppen</h2>
    <div class="kv"><span class="k">Namen</span><span class="val"><?= e(implode(' · ', $config['allowed_groups'] ?? [])) ?: '<em class="muted">–</em>' ?></span></div>
    <div class="kv"><span class="k">IDs</span><span class="val"><?= e(implode(', ', $config['allowed_group_ids'] ?? [])) ?: '<em class="muted">–</em>' ?></span></div>
    <div class="kv"><span class="k">Trésorier-Gruppen</span><span class="val"><?= e(implode(' · ', $config['tresorier_groups'] ?? [])) ?: '<em class="muted">–</em>' ?></span></div>
    <div class="kv"><span class="k">Trésorier-IDs</span><span class="val"><?= e(implode(', ', $config['tresorier_group_ids'] ?? [])) ?: '<em class="muted">–</em>' ?></span></div>
    <p class="muted">Stimmen die Gruppen-Namen/IDs oben mit deiner Vorstandsgruppe überein?
        Falls nicht, in <code>config/auth.php</code> anpassen (IDs sind am sichersten).</p>
</section>

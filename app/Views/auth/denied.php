<?php /** @var ?array $user @var array $config */ ?>
<h1>Kein Zugriff</h1>
<div class="alert alert-error">
    Du bist angemeldet<?= $user ? ' als <strong>' . e($user['username']) . '</strong>' : '' ?>,
    aber dein Konto ist nicht in einer berechtigten Gruppe.
</div>
<p class="muted">
    Zugriff auf die Mitgliederverwaltung haben nur Mitglieder der Gruppe(n):
    <strong><?= e(implode(' · ', $config['allowed_groups'] ?? [])) ?: '—' ?></strong>.
    Wende dich an den Vorstand, falls du Zugriff benötigst.
</p>
<p><a class="btn" href="https://afol55.afol.lu/">Zurück zur Community</a></p>

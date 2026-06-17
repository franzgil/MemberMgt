<?php /** @var array $m @var array $status @var string $csrf */ ?>
<h1>Bearbeiten: <?= e($m['vorname'] . ' ' . $m['nachname']) ?></h1>
<?php
$action = url('/mitglieder/update/' . $m['id']);
$submit = 'Speichern';
require ROOT . '/app/Views/mitglieder/_form.php';

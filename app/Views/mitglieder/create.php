<?php /** @var array $status @var string $csrf */ ?>
<h1>Neuer Antrag / Mitglied</h1>
<?php
$m = [];
$action = url('/mitglieder/store');
$submit = 'Anlegen';
require ROOT . '/app/Views/mitglieder/_form.php';

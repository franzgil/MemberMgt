<?php /** @var string $titel */ ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titel ?? '') ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('/assets/css/style.css') ?>">
</head>
<body>
<header class="topbar">
    <div class="wrap">
        <a class="brand" href="<?= url('/') ?>">AFOL.lu</a>
        <nav>
            <a href="<?= url('/') ?>">Dashboard</a>
            <a href="<?= url('/mitglieder') ?>">Mitglieder</a>
            <a href="<?= url('/antraege') ?>">Anträge</a>
            <?php if (\App\Core\Auth::darfBeitragBestaetigen()): ?>
                <a href="<?= url('/sumup') ?>">SumUp</a>
            <?php endif; ?>
            <a class="btn-primary" href="<?= url('/mitglieder/create') ?>">+ Neu</a>
            <?php if (($au = \App\Core\Auth::user()) !== null): ?>
                <span class="navuser"><?= e($au['username']) ?></span>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="wrap">
    <?php if ($s = flash('success')): ?>
        <div class="alert alert-success"><?= e($s) ?></div>
    <?php endif; ?>
    <?php if ($err = flash('errors')): ?>
        <div class="alert alert-error">
            <?php foreach (explode("\n", $err) as $line): ?>
                <div><?= e($line) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

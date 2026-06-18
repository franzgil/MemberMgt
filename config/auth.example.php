<?php
/**
 * Zugriffsschutz über WoltLab – Vorlage.
 * Kopieren nach config/auth.php und anpassen (config/auth.php ist gitignored).
 *
 * Solange config/auth.php FEHLT, ist der Schutz AUS (App offen) – praktisch
 * für die lokale Entwicklung.
 */
return [
    // Schutz aktiv?
    'enabled' => true,

    // Pfad zu WoltLabs global.php.
    // Leer lassen ('') = automatische Suche in den übergeordneten Verzeichnissen.
    // Beispiel absolut: '/var/www/afol55/global.php'
    'woltlab_global' => '',

    // Zugriff erlaubt für diese WoltLab-Benutzergruppen (Name, Groß/Klein egal)
    'allowed_groups' => ['AFOL.lu a.s.b.l. Vorsitz'],

    // …oder alternativ/zusätzlich über Gruppen-IDs (sicherer als Namen):
    'allowed_group_ids' => [],

    // Beiträge bestätigen dürfen nur Mitglieder dieser Gruppe(n) (Trésorier):
    'tresorier_groups' => ['Tresorier'],
    'tresorier_group_ids' => [],

    // WoltLab-Login-Seite (Weiterleitung, wenn nicht eingeloggt)
    'login_url' => 'https://afol55.afol.lu/index.php?login/',
];

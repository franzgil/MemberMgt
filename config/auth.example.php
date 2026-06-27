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

    // Adresse des Mitgliedskarten-Skripts (woltlab/membercard.php im WoltLab-Root).
    // Leer lassen, um die Karten-Buttons in der App auszublenden.
    'card_url' => 'https://afol55.afol.lu/membercard.php',

    // SumUp-API-Key (Secret) für die Zahlungs-Abfrage (Menü „SumUp").
    // Aus dem SumUp-Dashboard (Developers/API keys). Leer = Funktion aus.
    // Alternativ über die Umgebungsvariable SUMUP_API_KEY setzen.
    // NICHT öffentlich machen – config/auth.php ist gitignored.
    'sumup_api_key' => '',
];

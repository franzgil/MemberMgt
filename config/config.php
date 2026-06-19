<?php
/**
 * Allgemeine Anwendungs-Konfiguration.
 */
declare(strict_types=1);

define('APP_NAME', 'AFOL.lu – Mitgliederverwaltung');

// Basis-URL = Verzeichnis, in dem der Front Controller (index.php) liegt.
// Funktioniert im Web-Root, in Unterverzeichnissen und mit dem eingebauten Server.
// Beispiele:
//   SCRIPT_NAME = /index.php                       -> BASE_URL = ''
//   SCRIPT_NAME = /apps/MemberMgt/public/index.php -> BASE_URL = '/apps/MemberMgt/public'
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$base = str_replace('\\', '/', dirname($scriptName));
$base = rtrim($base, '/');
define('BASE_URL', ($base === '' || $base === '.') ? '' : $base);

// Verzeichnis für umgebungsspezifische Konfiguration MIT Geheimnissen
// (database.php, auth.php). Für mehr Sicherheit kann es AUSSERHALB von public_html
// liegen. Auflösung in dieser Reihenfolge:
//   1. Umgebungsvariable MEMBERMGT_CONFIG_DIR (falls verfügbar)
//   2. Datei config/secrets-dir.php, die einen absoluten Pfad zurückgibt
//      (für Hosting OHNE Umgebungsvariablen – einfach diese Datei anlegen)
//   3. Standard: ROOT/config
$cfgDir = getenv('MEMBERMGT_CONFIG_DIR') ?: '';
if ($cfgDir === '' && is_file(ROOT . '/config/secrets-dir.php')) {
    $cfgDir = (string) (require ROOT . '/config/secrets-dir.php');
}
if ($cfgDir === '') {
    $cfgDir = ROOT . '/config';
}
define('CONFIG_DIR', rtrim(str_replace('\\', '/', $cfgDir), '/'));

// Aktuelles Beitragsjahr (für „Mitglied = bezahlt im laufenden Jahr")
define('AKTUELLES_JAHR', (int) date('Y'));

// Monat der jährlichen Generalversammlung (Mitgliedsausweise werden dort
// erstellt). Das Mitgliedsjahr läuft von GV zu GV; vor diesem Monat zählt
// noch das Vorjahr.
define('GV_MONAT', 3);

date_default_timezone_set('Europe/Luxembourg');
mb_internal_encoding('UTF-8');

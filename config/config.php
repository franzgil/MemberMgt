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
// (database.php, auth.php). Standard: ROOT/config. Für mehr Sicherheit kann es
// per Umgebungsvariable MEMBERMGT_CONFIG_DIR auf einen Ort AUSSERHALB von
// public_html gelegt werden – dann sind die Zugangsdaten nie über das Web erreichbar.
$cfgDir = getenv('MEMBERMGT_CONFIG_DIR') ?: (ROOT . '/config');
define('CONFIG_DIR', rtrim(str_replace('\\', '/', $cfgDir), '/'));

// Aktuelles Beitragsjahr (für „Mitglied = bezahlt im laufenden Jahr")
define('AKTUELLES_JAHR', (int) date('Y'));

// Monat der jährlichen Generalversammlung (Mitgliedsausweise werden dort
// erstellt). Das Mitgliedsjahr läuft von GV zu GV; vor diesem Monat zählt
// noch das Vorjahr.
define('GV_MONAT', 3);

date_default_timezone_set('Europe/Luxembourg');
mb_internal_encoding('UTF-8');

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

// Aktuelles Beitragsjahr (für „Mitglied = bezahlt im laufenden Jahr")
define('AKTUELLES_JAHR', (int) date('Y'));

date_default_timezone_set('Europe/Luxembourg');
mb_internal_encoding('UTF-8');

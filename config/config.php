<?php
/**
 * Allgemeine Anwendungs-Konfiguration.
 */
declare(strict_types=1);

define('APP_NAME', 'AFOL.lu – Mitgliederverwaltung');

// Basis-URL ermitteln (funktioniert im Web-Root und in Unterverzeichnissen).
// Bei Betrieb über den eingebauten PHP-Server: leer ('').
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$base = str_replace('\\', '/', dirname($scriptName));
// '/public' aus dem Pfad entfernen, falls vorhanden
$base = preg_replace('#/public$#', '', $base);
$base = rtrim($base, '/');
define('BASE_URL', $base === '/' ? '' : $base);

// Aktuelles Beitragsjahr (für „Mitglied = bezahlt im laufenden Jahr")
define('AKTUELLES_JAHR', (int) date('Y'));

date_default_timezone_set('Europe/Luxembourg');
mb_internal_encoding('UTF-8');

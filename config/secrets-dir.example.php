<?php
/**
 * Geheimnisse außerhalb von public_html – OHNE Umgebungsvariablen.
 *
 * So nutzt du es (cPanel ohne Subdomain/Env):
 *   1. Lege außerhalb von public_html einen Ordner an, z. B.:
 *        /home/vid10000/afol-secrets
 *   2. Verschiebe dorthin deine echten Dateien:
 *        database.php  (und ggf. auth.php)
 *   3. Kopiere diese Datei nach  config/secrets-dir.php  und trage den Pfad ein.
 *
 * Die Datei gibt einfach den absoluten Pfad zum Geheimnis-Ordner zurück.
 * (config/secrets-dir.php ist per .gitignore vom Repo ausgeschlossen.)
 */
return '/home/vid10000/afol-secrets';

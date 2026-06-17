# AFOL.lu – Mitgliederverwaltung

Web-Anwendung zur Verwaltung der Mitglieder von **AFOL.lu** (Adult Fans of LEGO
Luxembourg). Eigenes schlankes **PHP-MVC** (kein Framework), **MySQL/MariaDB**,
serverseitig gerenderte Views.

> Konzept & Datenmodell: siehe [`KONZEPT.md`](KONZEPT.md).

## Funktionsumfang (Stufe 1)

- **Dashboard**: Kennzahlen, offene Anträge, Datenqualität/Vollständigkeit.
- **Mitglieder**: Liste mit Suche & Status-Filter, Detailansicht, Anlegen,
  Bearbeiten, Archivieren (Austritt).
- **Lebenszyklus**: `antrag` → (Trésorier bestätigt Zahlung) → `aktiv`.
- **Beiträge** (Cotisation) je Jahr, normalisiert in eigener Tabelle.
- Sicherheit: PDO Prepared Statements, XSS-Escaping, CSRF-Token.

## Projektstruktur

```
public/        Web-Root (Front Controller index.php, .htaccess, assets)
app/Core/      Router, Controller, Model, Database, Helpers
app/Controllers/  DashboardController, MitgliederController
app/Models/    Mitglied, Beitrag
app/Views/     Layout + Dashboard- und Mitglieder-Views
config/        config.php, database.example.php (database.php lokal anlegen)
sql/           schema.sql, seed.sql
```

## Einrichtung

1. **Datenbank anlegen** und Schema einspielen:
   ```bash
   mysql -u root -p -e "CREATE DATABASE afol_membres CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p afol_membres < sql/schema.sql
   mysql -u root -p afol_membres < sql/seed.sql   # optional: Beispieldaten
   ```

2. **Zugangsdaten** konfigurieren:
   ```bash
   cp config/database.example.php config/database.php
   # config/database.php anpassen (host, dbname, user, pass)
   ```
   `config/database.php` ist per `.gitignore` ausgeschlossen.

4. **Echtdaten importieren** (statt der Beispieldaten) aus den Bestandstabellen
   `gf_membres` und `wcf1_form_response` – in **derselben** Datenbank ausführen
   (z. B. in phpMyAdmin oder per CLI):
   ```bash
   mysql -u DEINUSER -p DEINEDB < sql/import.sql
   ```
   Das Skript **leert zuerst** `mitglieder`/`beitraege` (entfernt damit die
   Beispieldaten) und importiert anschließend:
   - `gf_membres` → Mitglieder + Jahresbeiträge (`Cot 2024/2025/2026`)
   - `wcf1_form_response` (`formID = 3`) → Online-Anträge (Status `antrag`)

   > Voraussetzung: MySQL 5.7+ / MariaDB 10.2+ (JSON-Funktionen). Das
   > Status-Mapping (`gf_membres.status`) ggf. im CASE oben anpassen –
   > vorkommende Werte zeigt `SELECT status, COUNT(*) FROM gf_membres GROUP BY status;`

3. **Starten** (lokal, eingebauter PHP-Server):
   ```bash
   php -S localhost:8000 -t public public/index.php
   ```
   Dann <http://localhost:8000> öffnen. (`-t public` setzt den Web-Root korrekt,
   damit Assets unter `public/` ausgeliefert werden.)

   Bei Apache: idealerweise den `DocumentRoot`/Alias auf `public/` zeigen lassen.

### Ohne mod_rewrite

Die App funktioniert **auch ohne mod_rewrite**: Alle Links laufen über den Front
Controller via `PATH_INFO` (z. B. `…/public/index.php/mitglieder`). Die
mitgelieferte `public/.htaccess` ist nur eine optionale Verschönerung für saubere
URLs; ist `mod_rewrite`/`AllowOverride` nicht verfügbar, ändert sich nichts an der
Funktion. Statische Dateien (CSS) werden direkt ausgeliefert, nicht über `index.php`.

## Anforderungen

- PHP 7.4+ (kompatibel zu PHP 8) mit `pdo_mysql`
- MySQL 5.7+ / MariaDB 10.2+

## Ausblick

- WoltLab-Login-Anbindung (Session/Cookie-Abgleich), Rollen (Trésorier/Vorstand)
- Import aus `gf_membres` und dem WoltLab-Formular (`formID 3`)
- Beitrags-Reporting je Jahr

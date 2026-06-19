# AFOL.lu – Mitgliederverwaltung

Web-Anwendung zur Verwaltung der Mitglieder von **AFOL.lu** (Adult Fans of LEGO
Luxembourg). Eigenes schlankes **PHP-MVC** (kein Framework), **MySQL/MariaDB**,
serverseitig gerenderte Views.

> Konzept & Datenmodell: siehe [`KONZEPT.md`](KONZEPT.md).

## Funktionsumfang (Stufe 1)

- **Dashboard**: Kennzahlen, offene Anträge, Datenqualität/Vollständigkeit.
- **Mitglieder**: Liste mit Suche & Status-Filter, Detailansicht, Anlegen,
  Bearbeiten, Archivieren (Austritt).
- **Anträge**: Online-Anträge aus dem WoltLab-Formular werden **live** angezeigt
  (robustes JSON-Parsing in PHP inkl. Emoji/Surrogate), als „neu/erfasst/unlesbar"
  markiert und einzeln oder gesammelt in die Mitgliederverwaltung übernommen.
- **Lebenszyklus**: `antrag` → (Trésorier bestätigt Zahlung) → `aktiv`.
- **Mitglieds-Typ**: Aktives Mitglied / Fördermitglied.
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
   Beispieldaten) und importiert anschließend `gf_membres` → Mitglieder +
   Jahresbeiträge (`Cot 2024/2025/2026`).

   Die **Online-Anträge** (`wcf1_form_response`, `formID 3`) werden **nicht**
   importiert, sondern live im App-Modul **„Anträge"** angezeigt und dort in die
   Mitgliederverwaltung übernommen.

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

## Mitgliedschaft & Gültigkeit

In der Mitgliederliste (Spalte **Gültig**) und auf der Detailseite wird angezeigt,
ob die Mitgliedschaft noch läuft. Regeln (AFOL.lu a.s.b.l.):

- Die Mitgliedsausweise werden jährlich zur **Generalversammlung (März)** erstellt;
  das Mitgliedsjahr läuft von GV zu GV (Monat konfigurierbar über `GV_MONAT`).
- Wer im laufenden Jahr beitritt, ist **auch im Folgejahr** Mitglied
  (Beitrittsjahr + 1 ist gedeckt). Danach jährliche Erneuerung per bestätigtem Beitrag.
- Anzeige: **gültig &lt;Jahr&gt;** · **erneuern** (abgelaufen) · **?** (kein Beitritts-/
  Beitragsjahr bekannt) · **–** (kein aktives Mitglied).

Das Gnadenjahr (+1) greift nur bei bekanntem Beitritts-/Antragsdatum; bei reinen
Altdaten (nur Cot-Jahre) zählt das zuletzt bestätigte Beitragsjahr.

## Sicheres Deployment (welche Dateien dürfen ins Web?)

**Grundregel:** Nur der Ordner **`public/`** (Front Controller + `assets/`) darf über
das Web erreichbar sein. Alles andere – `config/` (DB-Passwort!), `app/`, `sql/`,
`.git/`, Doku – muss **unerreichbar** bleiben.

Liegt die App unter `…/public_html/afol55/apps/MemberMgt`, ist standardmäßig der
ganze Ordner im Web. Drei Schutzebenen (von best zu Minimum):

1. **Schon enthalten – `.htaccess`-Sperren (auf cPanel/Apache ausreichend).**
   `config/`, `app/`, `sql/` enthalten je eine `.htaccess` mit `Require all denied`;
   die Wurzel-`.htaccess` sperrt `.git`, Punktdateien sowie `*.md/*.sql/*.example.php`
   und schaltet das Verzeichnis-Listing ab (Apache 2.4/2.2). Das wirkt auch, wenn die
   App unter `public_html` liegt und über `…/apps/MemberMgt/public/` aufgerufen wird –
   **ohne Subdomain und ohne Umgebungsvariablen**. Hinweis: `*.php`-Konfigdateien geben
   bei direktem Aufruf ohnehin nichts aus (PHP führt sie aus), die Sperren sind
   zusätzlicher Schutz.

2. **Geheimnisse aus `public_html` auslagern – ohne Umgebungsvariablen.**
   Ordner außerhalb des Web-Roots anlegen (per cPanel File Manager eine Ebene über
   `public_html`), z. B. `/home/vid10000/afol-secrets/`, und dorthin `database.php`
   (und ggf. `auth.php`) verschieben. Dann `config/secrets-dir.example.php` nach
   `config/secrets-dir.php` kopieren und den Pfad eintragen – fertig. (Alternativ per
   Umgebungsvariable `MEMBERMGT_CONFIG_DIR`, falls doch verfügbar.)

3. **Am besten, falls je möglich – Document-Root auf `public/`.**
   Zeigt eine (Sub-)Domain mit *Document Root* `…/apps/MemberMgt/public`, liegt alles
   andere physisch oberhalb des Web-Roots. Aktuell nicht möglich → Schritt 1 (+2).

> **Wichtig – `.git` nicht hochladen:** Lade die Dateien per File Manager hoch, **nicht**
> per `git clone` in `public_html` (sonst wäre `…/apps/MemberMgt/.git/` ladbar; die
> Wurzel-`.htaccess` blockt es zwar, aber besser gar nicht erst hochladen).
> `config/database.php`, `config/auth.php` und `config/secrets-dir.php` sind per
> `.gitignore` ausgeschlossen und auf dem Server manuell anzulegen.

### Schutz prüfen (im Browser/per curl)

Diese URLs dürfen **kein** Klartext/Inhalt liefern (erwartet 403 oder 404):

```
https://afol55.afol.lu/apps/MemberMgt/sql/schema.sql        -> 403/404 (NICHT der SQL-Text)
https://afol55.afol.lu/apps/MemberMgt/config/database.php   -> 403 oder leere Seite
https://afol55.afol.lu/apps/MemberMgt/.git/config           -> 404
https://afol55.afol.lu/apps/MemberMgt/app/Core/Database.php -> 403 oder leere Seite
```

## Zugriffsschutz über WoltLab

Die Verwaltung wird über die bestehende **WoltLab-Anmeldung** geschützt – kein
eigenes Passwort-System. Zugriff nur für berechtigte WoltLab-Benutzergruppen
(z. B. den Vorstand).

1. Konfiguration anlegen:
   ```bash
   cp config/auth.example.php config/auth.php
   ```
   (`config/auth.php` ist per `.gitignore` ausgeschlossen.)
2. In `config/auth.php` setzen:
   - `woltlab_global`: Pfad zu WoltLabs `global.php` (leer = automatische Suche).
   - `allowed_groups` / `allowed_group_ids`: berechtigte Gruppe(n).
   - `login_url`: WoltLab-Login-Seite.
3. **Verifizieren:** `…/index.php/auth/debug` öffnen – zeigt den erkannten
   WoltLab-Benutzer, seine Gruppen, ob der Zugriff erlaubt ist und ob er Beiträge
   bestätigen darf (Trésorier). Dort die passenden Gruppen-Namen/IDs ablesen und
   in `config/auth.php` eintragen.

**Rollen:** Beiträge bestätigen dürfen nur Mitglieder der in `tresorier_groups`
(bzw. `tresorier_group_ids`) genannten WoltLab-Gruppe(n) – Standard: `Tresorier`.
Das Bestätigungsformular wird sonst ausgeblendet und der Server lehnt den Vorgang
ab (fail-closed).

> Solange `config/auth.php` fehlt, ist der Schutz **aus** (praktisch für lokale
> Entwicklung). Zum vorübergehenden Deaktivieren `enabled => false` setzen.

## Anforderungen

- PHP 7.4+ (kompatibel zu PHP 8) mit `pdo_mysql`
- MySQL 5.7+ / MariaDB 10.2+

## Ausblick

- WoltLab-Login-Anbindung (Session/Cookie-Abgleich), Rollen (Trésorier/Vorstand)
- Import aus `gf_membres` und dem WoltLab-Formular (`formID 3`)
- Beitrags-Reporting je Jahr

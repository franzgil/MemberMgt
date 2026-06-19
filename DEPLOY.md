# Installation auf dem cPanel-Server (Schritt für Schritt)

Ziel-Ort: `…/public_html/afol55/apps/MemberMgt`
Aufruf später: `https://afol55.afol.lu/apps/MemberMgt/public/`

> Die App nutzt **dieselbe Datenbank wie WoltLab** (sie liest `wcf1_user`,
> `wcf1_form_response`, `gf_membres`). Die neuen Tabellen kommen daher in die
> **WoltLab-Datenbank**.

---

## 1. Dateien hochladen (OHNE `.git`)

1. Auf GitHub den Branch als **ZIP herunterladen**
   (Code → Download ZIP) – so ist kein `.git`-Ordner dabei.
2. cPanel → **File Manager** → nach `public_html/afol55/` gehen.
3. Ordner `apps` anlegen (falls nicht vorhanden), hineingehen, **ZIP hochladen**
   und **entpacken**.
4. Ordner ggf. in `MemberMgt` umbenennen. Ergebnis muss sein:
   `…/afol55/apps/MemberMgt/public/index.php`
5. Prüfen, dass die **`.htaccess`-Dateien** mitgekommen sind (in `File Manager`
   unter Settings „Show Hidden Files" aktivieren). Es muss geben:
   `MemberMgt/.htaccess`, `config/.htaccess`, `app/.htaccess`, `sql/.htaccess`,
   `public/.htaccess`.

## 2. WoltLab-DB-Zugangsdaten heraussuchen

1. File Manager → Datei **`public_html/afol55/config.inc.php`** ansehen.
2. Notieren: `$dbHost`, `$dbName`, `$dbUser`, `$dbPassword`.

## 3. `config/database.php` anlegen

1. Im File Manager `config/database.example.php` **kopieren** zu
   `config/database.php` (Rechtsklick → Copy).
2. `config/database.php` **bearbeiten** und die Werte aus Schritt 2 eintragen:
   ```php
   <?php
   return [
       'host'    => 'localhost',
       'port'    => 3306,
       'dbname'  => 'WOLTLAB_DBNAME',
       'charset' => 'utf8mb4',
       'user'    => 'WOLTLAB_DBUSER',
       'pass'    => 'WOLTLAB_DBPASSWORT',
   ];
   ```

## 4. Tabellen anlegen + Daten importieren (phpMyAdmin)

1. cPanel → **phpMyAdmin** → links die **WoltLab-Datenbank** auswählen.
2. Reiter **SQL** → den **gesamten Inhalt von `sql/schema.sql`** einfügen → **OK**.
   (Erstellt die Tabellen `mitglieder` und `beitraege`.)
3. Erneut Reiter **SQL** → den Inhalt von **`sql/import.sql`** einfügen → **OK**.
   (Übernimmt die Mitglieder aus `gf_membres`.)
   > `sql/seed.sql` ist nur für lokale Tests – auf dem Server **nicht** ausführen.

## 5. `config/auth.php` anlegen (WoltLab-Login + Trésorier)

1. `config/auth.example.php` **kopieren** zu `config/auth.php`.
2. Inhalt prüfen/anpassen:
   ```php
   <?php
   return [
       'enabled'             => true,
       'woltlab_global'      => '',                      // leer = automatisch finden
       'allowed_groups'      => ['AFOL.lu a.s.b.l. Vorsitz'],
       'allowed_group_ids'   => [],
       'tresorier_groups'    => ['Tresorier'],
       'tresorier_group_ids' => [],
       'login_url'           => 'https://afol55.afol.lu/index.php?login/',
   ];
   ```

## 6. App öffnen

Im Browser: **`https://afol55.afol.lu/apps/MemberMgt/public/`**

- Nicht eingeloggt → Weiterleitung zum WoltLab-Login. Als **Vorstands-Mitglied**
  anmelden.
- Danach erscheinen Dashboard und Mitgliederliste.

## 7. Login & Rollen prüfen

Aufrufen: **`…/apps/MemberMgt/public/index.php/auth/debug`**
- „Zugriff erlaubt" muss **ja** sein.
- „Darf Beiträge bestätigen (Trésorier)" zeigt, ob die Gruppe `Tresorier` erkannt wird.
- Stimmt ein Gruppenname nicht, in `config/auth.php` die dort angezeigte **Gruppen-ID**
  unter `allowed_group_ids` bzw. `tresorier_group_ids` eintragen.

## 8. Sicherheit prüfen

Diese URLs müssen **403 oder 404** liefern (KEIN Inhalt):
```
…/apps/MemberMgt/sql/schema.sql         -> nicht der SQL-Text!
…/apps/MemberMgt/config/database.php    -> 403 oder leere Seite
…/apps/MemberMgt/.git/config            -> 404
…/apps/MemberMgt/app/Core/Database.php  -> 403 oder leere Seite
```
Erscheint beim ersten Link SQL-Text, ist `.htaccess` nicht aktiv → bei mir melden.

---

## Optional: Geheimnisse außerhalb von `public_html`

Noch sicherer (ohne Subdomain/Env-Variablen):
1. Eine Ebene über `public_html` (z. B. `/home/vid10000/`) Ordner `afol-secrets` anlegen.
2. `config/database.php` (und `config/auth.php`) **dorthin verschieben**.
3. `config/secrets-dir.example.php` nach `config/secrets-dir.php` kopieren und den Pfad
   eintragen, z. B. `return '/home/vid10000/afol-secrets';`

## Updates später einspielen

Geänderte Dateien per File Manager neu hochladen. Die selbst angelegten Dateien
(`config/database.php`, `config/auth.php`, ggf. `config/secrets-dir.php`) bleiben
unangetastet. Bei DB-Änderungen sage ich jeweils, ob ein SQL-Schritt nötig ist.

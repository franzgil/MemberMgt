# Mitgliedskarte (`membercard.php`)

Eigenständiges Skript für **digitale Mitgliedskarten** der AFOL.lu:

- `membercard.php?page=home` – Auswahlseite (PDF oder Handy-Karte), mehrsprachig
- `membercard.php?page=card` – Karte als **PDF** (Scheckkartenformat, FPDF)
- `membercard.php?page=wallet` – **Handy-Karte** (Web, „Zum Startbildschirm")
- `membercard.php?page=verify&t=…` – **Verifizierungsseite** (Ziel des QR-Codes)
- `membercard.php?page=qrtest` – Diagnose (QR/FPDF/gf_membres-Abgleich)

Die Karte liest WoltLab-Benutzer + die Tabelle `gf_membres` (Mitgliedsnummer aus
`Membre` + `No`, Gültigkeit aus den `Cot <Jahr>`-Spalten). Der QR-Code enthält eine
**HMAC-signierte** Verify-URL; beim Scannen wird live gegen die DB geprüft.

> Dieses Skript läuft **im WoltLab-Root** (neben `global.php`), nicht innerhalb der
> MemberMgt-App. Beide teilen sich dieselbe Datenbank.

## Installation (cPanel)

1. **`membercard.php`** nach `…/public_html/afol55/` hochladen (neben `global.php`).
2. **FPDF** besorgen: `fpdf.php` von <http://www.fpdf.org> herunterladen und
   ebenfalls nach `…/afol55/` legen (eine einzelne, abhängigkeitsfreie Datei).
3. **Logo**: `…/afol55/images/afol-logo.png` ablegen (PNG/JPEG; optional).
4. **Geheimnis setzen** (für die QR-Signatur) – ohne Umgebungsvariable:
   Datei **`card-secret.txt`** mit einer langen Zufallszeichenkette anlegen, am besten
   **außerhalb** von `public_html`, z. B. `…/home/<user>/afol-secrets/card-secret.txt`.
   Das Skript sucht automatisch in dieser Reihenfolge:
   `../../afol-secrets/card-secret.txt`, `../afol-secrets/card-secret.txt`,
   `./card-secret.txt`.
   Ein langes Geheimnis erzeugen (Beispiel):
   ```bash
   php -r "echo bin2hex(random_bytes(32));" > card-secret.txt
   ```
5. **Berechtigung für fremde Karten**: In `membercard.php` ist
   `CARD_ADMIN_GROUPS = 'Tresorier'` gesetzt – Admins und Mitglieder dieser
   WoltLab-Gruppe dürfen Karten für andere erzeugen. Bei Bedarf anpassen.

## Verbindung zur MemberMgt-App

Auf der **Mitglieds-Detailseite** der App erscheint ein Button **„Mitgliedskarte"**,
sobald in `config/auth.php` `card_url` gesetzt ist und das Mitglied eine
`wcf_user_id` hat. Der Link öffnet `membercard.php?page=home&userID=<wcf_user_id>`.

```php
// config/auth.php
'card_url' => 'https://afol55.afol.lu/membercard.php',
```

## Prüfen

- `…/afol55/membercard.php?page=qrtest` (als Vorstand/Trésorier eingeloggt) zeigt,
  ob QR-Bibliothek, FPDF, Logo und der `gf_membres`-Abgleich funktionieren.
- Hinweis: `fpdf.php` und `card-secret.txt` sind per `.gitignore` ausgeschlossen.

---

# Mitgliedsantrag (`mitgliedsantrag.php`)

Eigenständiges **Antragsformular**, das das fehlerhafte WoltLab-Formular-Plugin
(`form-user-response/3-mitgliedsantrag`) ersetzt und dessen Mängel behebt:

- **Bestätigung:** Der Absender erhält eine E-Mail.
- **Zahlung:** Die Mail enthält die Aufforderung, den Jahresbeitrag aufs
  Vereinskonto zu überweisen (IBAN, Betrag, Verwendungszweck).
- **Storno:** Ein persönlicher Link erlaubt, den Antrag zurückzuziehen.

Weitere Eigenschaften:

- **4 Sprachen** (🇱🇺 Lëtzebuergesch, 🇩🇪 Deutsch, 🇫🇷 Français, 🇬🇧 English) mit
  Umschalter – Formular, Danke-/Storno-Seiten und E-Mail. Eine einzige
  Übersetzungstabelle versorgt Formular-JS, Server-Seiten und Mail.
- **AFOL.lu-Design** (Palette/Hero/Logo wie `membercard.php`).
- Feld **„Bevorzugte Sprache für Newsletter"** → Spalte `mitglieder.newsletter_sprache`.
  Die Bestätigungsmail wird in dieser Sprache verschickt.
- **Kein Forenkonto nötig:** Gäste können einen Antrag stellen (`wcf_user_id`
  bleibt leer). Eingeloggte Nutzer werden automatisch verknüpft – Gäste sehen
  stattdessen den Hinweis „kein Forenkonto nötig".
- **Fördermitgliedschaft:** Neue Anträge werden als `typ='foerder'` (Membre
  sympathisant, 15 €) angelegt. Aktivmitglied (`typ='aktiv'`) macht der Vorstand
  später (frühestens nach 6 Monaten). Das Formular weist darauf hin.
- **SumUp:** Online-Kartenzahlung als Alternative zur Überweisung (`ANTRAG_SUMUP_URL`).

Seiten:

- `mitgliedsantrag.php` – öffentliches Antragsformular (POST = absenden)
- `mitgliedsantrag.php?page=storno&t=…&lang=…` – Antrag zurückziehen (HMAC-Link aus der Mail)
- `mitgliedsantrag.php?page=test` – Diagnose (nur Admin), `&mail=1` sendet eine Test-Mail

Der Antrag wird direkt in die App-Tabelle `mitglieder` geschrieben (Status
`antrag`) und erscheint in der Mitgliederverwaltung. Wie gehabt wird daraus durch
die **Trésorier-Zahlungsbestätigung** ein aktives Mitglied. Ein zurückgezogener
Antrag erhält den neuen Status `zurueckgezogen`.

> Läuft **im WoltLab-Root** (neben `global.php`) und nutzt WoltLabs Mailsystem.
> Das HMAC-Geheimnis ist dasselbe wie bei `membercard.php` (`card-secret.txt`).

## Installation

1. **`mitgliedsantrag.php`** nach `…/public_html/afol55/` hochladen.
2. Einmalig ausführen: **`sql/alter_mitglieder_zurueckgezogen.sql`** (neuer Status)
   und **`sql/alter_mitglieder_newsletter_sprache.sql`** (Newsletter-Sprachfeld).
3. Im **KONFIGURATION**-Block oben anpassen:
   - **Vereinskonto:** `ANTRAG_IBAN`, `ANTRAG_KONTO_INHABER`, optional `ANTRAG_BIC`/`ANTRAG_BANK`
   - **Beitrag:** `ANTRAG_BEITRAG`, ggf. `ANTRAG_VERWENDUNG`
   - **SumUp (optional):** `ANTRAG_SUMUP_URL` – Online-Kartenzahlung als Alternative
     zur Überweisung (leer = ausgeblendet). Erscheint auf Danke-Seite und in der Mail.
   - **E-Mail:** `ANTRAG_KONTAKT_EMAIL` (Vorstand), optional `ANTRAG_MAIL_FROM`
4. `card-secret.txt` muss gesetzt sein (wie bei der Mitgliedskarte).
5. Im Forum den **alten Plugin-Link** durch `…/afol55/mitgliedsantrag.php` ersetzen.

## Prüfen

- `…/afol55/mitgliedsantrag.php?page=test` (als Admin) zeigt die Konfiguration;
  `…?page=test&mail=1` sendet eine Test-Mail an die eigene Adresse.
- Danach das Formular selbst absenden und die Bestätigungsmail + Storno-Link prüfen.

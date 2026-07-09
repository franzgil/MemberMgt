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
- **DSGVO/GDPR:** Pflicht-Einwilligung in die Datenverarbeitung mit Link zur
  Datenschutzerklärung (`ANTRAG_DATENSCHUTZ_URL`, Standard: WoltLab-Datenschutzseite);
  separate **freiwillige** Newsletter-Einwilligung (Opt-in) – die Sprache wird nur
  bei Zustimmung gespeichert; Transparenz-Hinweis auf Formular und in der Mail;
  Einwilligung wird mit Zeitstempel in `bemerkung` dokumentiert.

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

---

# Jahresbeitrag mit SumUp (`beitrag.php`)

Eigenständige Seite, auf der ein **eingeloggtes Mitglied** seinen Jahresbeitrag
**online mit SumUp** bezahlt. Der Betrag richtet sich nach dem Mitgliedstyp
(`mitglieder.typ`):

- **Fördermitglied** (`typ='foerder'`, Membre sympathisant) → **15,00 €**
- **Aktives Mitglied** (`typ='aktiv'`, Membre actif) → **50,00 €**

Der Betrag wird **immer serverseitig aus dem Typ abgeleitet** (nie aus dem
Formular) und ist so nicht manipulierbar.

Ablauf:

1. Das Skript verlangt Login (WoltLab-Session) und lädt das Mitglied aus
   `mitglieder` (Treffer über `wcf_user_id`, sonst E-Mail).
2. Es zeigt eine Bestätigungsseite mit Typ, Beitragsjahr und Betrag.
3. Beim Klick erzeugt es per **SumUp Hosted Checkout** (`POST /v0.1/checkouts`,
   `hosted_checkout.enabled`) einen Checkout und leitet auf die von SumUp
   gehostete Bezahlseite weiter. Zur Identifikation des Mitglieds werden
   mitgegeben:
   - `checkout_reference` = `AFOL-<Mitgliedsnr>-<Jahr>-…` (eindeutig, im
     Dashboard/CSV-Export sichtbar),
   - `description` = `Cotisation <Jahr> – <Vorname Nachname> – <E-Mail>`
     (als Beleg der Transaktion in der SumUp-App/im Dashboard sichtbar).
   Es werden **nur** Name und E-Mail an SumUp übermittelt (keine Adresse,
   kein Geburtsdatum). Die E-Mail stammt aus dem Mitglieds-Datensatz, sonst
   aus dem Login.
4. Nach der Zahlung kommt der Nutzer auf die Danke-/Status-Seite zurück
   (`?page=return`), die den Checkout-Status (bezahlt/offen/fehlgeschlagen)
   anzeigt. Der Trésorier gleicht die Zahlung wie gewohnt über die
   **SumUp-Übersicht** in der App ab (die Referenz erleichtert die Zuordnung).

Weitere Eigenschaften:

- **4 Sprachen** (LB/DE/FR/EN) mit Umschalter, **AFOL.lu-Design** wie die
  anderen Skripte.
- **Fallback:** Ist kein API-Key/Merchant-Code gesetzt, können stattdessen feste
  SumUp-Bezahllinks je Typ (`BEITRAG_SUMUP_LINK_FOERDER`/`…_AKTIV`) verwendet
  werden (fester Betrag, ohne automatische Referenz).
- Schreibt **nicht** selbst in `beitraege` – die Bestätigung bleibt beim
  Trésorier (unverändertes Abgleich-Prinzip).

Seiten:

- `beitrag.php` – Bestätigungsseite (Login nötig; POST `action=pay` = Zahlung starten)
- `beitrag.php?page=return&c=…&lang=…` – Rückkehr von SumUp (Danke/Status)
- `beitrag.php?page=test` – Diagnose (nur Admin), `&ping=1` testet die SumUp-API

> Läuft **im WoltLab-Root** (neben `global.php`) und teilt sich die Datenbank
> mit der App. Das HMAC-Geheimnis ist dasselbe wie bei `membercard.php`
> (`card-secret.txt`).

## Installation

1. **`beitrag.php`** nach `…/public_html/afol55/` hochladen (neben `global.php`).
2. **SumUp konfigurieren** (für die dynamische Zahlung):
   - **API-Key** mit `payments`-Scope im SumUp-Dashboard erstellen und als
     `sumup-api-key.txt` **außerhalb** von `public_html` ablegen
     (`…/afol-secrets/sumup-api-key.txt`) oder als Umgebungsvariable
     `SUMUP_API_KEY` setzen. Das Skript sucht in derselben Reihenfolge wie beim
     Karten-Geheimnis.
   - **Merchant-Code** (Format `MCxxxxxx`, im SumUp-Profil) in `beitrag.php` als
     `BEITRAG_SUMUP_MERCHANT` eintragen oder als `SUMUP_MERCHANT_CODE` setzen.
3. Im **KONFIGURATION**-Block oben ggf. anpassen: Beträge (`BEITRAG_FOERDER`,
   `BEITRAG_AKTIV`), `BEITRAG_KONTAKT_EMAIL`, GV-Monat.
4. `card-secret.txt` muss gesetzt sein (wie bei der Mitgliedskarte).
5. Im Forum/Menü einen Link auf `…/afol55/beitrag.php` anlegen.

## Prüfen

- `…/afol55/beitrag.php?page=test` (als Admin) zeigt Konfiguration, den
  gefundenen Mitglieds-Datensatz und den fälligen Betrag; `…?page=test&ping=1`
  prüft, ob die SumUp-API mit dem Key erreichbar ist.
- Danach als Mitglied einloggen, `beitrag.php` öffnen und eine Testzahlung
  durchführen.

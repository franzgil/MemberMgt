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

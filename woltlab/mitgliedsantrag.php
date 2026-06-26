<?php
/**
 * AFOL.lu – Mitgliedsantrag (eigenständiges Formular)
 * Drop-in Skript für das WoltLab Suite 5.5 Root-Verzeichnis (neben global.php).
 *
 * Ersetzt das fehlerhafte WoltLab-Formular-Plugin und behebt dessen Mängel:
 *   1. Der Absender erhält eine Bestätigungs-E-Mail.
 *   2. Die Mail enthält die Aufforderung, den Beitrag aufs Vereinskonto zu
 *      überweisen (IBAN, Betrag, Verwendungszweck).
 *   3. Ein persönlicher Storno-Link erlaubt, den Antrag zurückzuziehen
 *      (z. B. wenn jemand sich nur im Forum anmelden wollte).
 *
 * Aufruf:
 *   mitgliedsantrag.php                     -> Antragsformular (öffentlich)
 *   mitgliedsantrag.php (POST)              -> Antrag absenden
 *   mitgliedsantrag.php?page=storno&t=TOKEN -> Antrag zurückziehen
 *   mitgliedsantrag.php?page=test           -> Diagnose (nur Admin)
 *
 * Datenfluss:
 *   Der Antrag wird direkt in die App-Tabelle `mitglieder` geschrieben
 *   (Status 'antrag'). Er erscheint danach in der Mitgliederverwaltung und
 *   wird – wie gehabt – durch die Trésorier-Zahlungsbestätigung zum Mitglied.
 */

use wcf\system\WCF;
use wcf\system\email\Email;
use wcf\system\email\Mailbox;
use wcf\system\email\mime\MimePartFacade;
use wcf\system\email\mime\HtmlTextMimePart;
use wcf\system\email\mime\PlainTextMimePart;

require_once(__DIR__ . '/global.php');

// ===========================================================================
// KONFIGURATION  –  bitte an den Verein anpassen
// ===========================================================================

// --- Vereinskonto (für die Überweisungsaufforderung in der E-Mail) ----------
define('ANTRAG_VEREIN_NAME',  'AFOL.lu a.s.b.l.');
define('ANTRAG_KONTO_INHABER','AFOL.lu a.s.b.l.');
define('ANTRAG_IBAN',         'LUxx xxxx xxxx xxxx xxxx');  // <-- echte IBAN eintragen
define('ANTRAG_BIC',          '');                          // optional, z. B. 'BCEELULL'
define('ANTRAG_BANK',         '');                          // optional, Name der Bank
define('ANTRAG_BEITRAG',      '20,00 €');                   // <-- Jahresbeitrag
// Verwendungszweck: {name} wird durch "Vorname Nachname" ersetzt, {jahr} durchs Jahr.
define('ANTRAG_VERWENDUNG',   'Mitgliedsbeitrag {jahr} – {name}');

// --- E-Mail -----------------------------------------------------------------
// Absenderadresse der Bestätigungsmail. Leer = WoltLab-Standardabsender nutzen
// (empfohlen wegen SPF/DKIM). Antworten gehen an ANTRAG_KONTAKT_EMAIL.
define('ANTRAG_MAIL_FROM',     '');
define('ANTRAG_MAIL_FROM_NAME','AFOL.lu');
// Kontaktadresse des Vorstands: erhält eine Info-Mail über neue Anträge und
// dient als Antwort-Adresse (Reply-To) der Bestätigungsmail.
define('ANTRAG_KONTAKT_EMAIL', 'vorstand@afol.lu');         // <-- anpassen

// --- Sonstiges --------------------------------------------------------------
// Wohin nach dem Absenden zurück verwiesen wird (Forum-Startseite o. Ä.).
define('ANTRAG_FORUM_URL', rtrim(WCF::getPath(), '/') . '/');
define('ANTRAG_SELF_URL',  rtrim(WCF::getPath(), '/') . '/mitgliedsantrag.php');
// Standardland (vorausgefüllt).
define('ANTRAG_LAND_DEFAULT', 'Luxembourg');

// --- Geheimnis (HMAC für Storno-/Formular-Token) ----------------------------
// Nutzt dieselbe Quelle wie membercard.php (card-secret.txt / AFOL_CARD_SECRET),
// damit kein zweites Geheimnis gepflegt werden muss.
$antragSecret = getenv('AFOL_CARD_SECRET') ?: '';
if ($antragSecret === '') {
    foreach ([
        __DIR__ . '/../../afol-secrets/card-secret.txt',
        __DIR__ . '/../afol-secrets/card-secret.txt',
        __DIR__ . '/card-secret.txt',
    ] as $secretFile) {
        if (is_file($secretFile)) {
            $antragSecret = trim((string) file_get_contents($secretFile));
            break;
        }
    }
}
define('ANTRAG_SECRET', $antragSecret ?: 'BITTE-LANGES-ZUFALLSGEHEIMNIS-SETZEN');

// ===========================================================================
// Token-Helfer (HMAC-signiert, keine zusätzliche DB-Spalte nötig)
// ===========================================================================

/** Signierten Token erzeugen. $kind z. B. 'storno' oder 'form'. */
function antrag_make_token(string $kind, string $value): string
{
    $payload = $kind . '|' . $value;
    $data = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $sig  = substr(hash_hmac('sha256', $payload, ANTRAG_SECRET), 0, 32);
    return $data . '.' . $sig;
}

/** Token prüfen. Rückgabe: der Wert (string) oder null. */
function antrag_read_token(string $kind, string $token): ?string
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    [$data, $sig] = $parts;
    $payload = base64_decode(strtr($data, '-_', '+/'), true);
    if ($payload === false || strpos($payload, '|') === false) {
        return null;
    }
    [$gotKind, $value] = explode('|', $payload, 2);
    if (!hash_equals($kind, (string) $gotKind)) {
        return null;
    }
    $expected = substr(hash_hmac('sha256', $payload, ANTRAG_SECRET), 0, 32);
    return hash_equals($expected, (string) $sig) ? $value : null;
}

// ===========================================================================
// Hilfsfunktionen
// ===========================================================================

function antrag_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Eingeloggter WoltLab-User (oder null). */
function antrag_current_user(): ?array
{
    $u = WCF::getUser();
    if (!$u || !$u->userID) {
        return null;
    }
    return [
        'userID'   => (int) $u->userID,
        'username' => (string) $u->username,
        'email'    => (string) $u->email,
    ];
}

/** Darf der eingeloggte Nutzer die Diagnose sehen? */
function antrag_is_admin(): bool
{
    try {
        return (bool) WCF::getSession()->getPermission('admin.user.canEditUser');
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Sucht einen bestehenden Eintrag mit gleicher E-Mail.
 * Rückgabe: ['id'=>int,'status'=>string] oder null.
 */
function antrag_find_existing(string $email): ?array
{
    if ($email === '') {
        return null;
    }
    $stmt = WCF::getDB()->prepareStatement(
        "SELECT id, status FROM mitglieder WHERE LOWER(email) = ? LIMIT 1"
    );
    $stmt->execute([mb_strtolower($email)]);
    $row = $stmt->fetchArray();
    return $row ? ['id' => (int) $row['id'], 'status' => (string) $row['status']] : null;
}

/** Antrag in `mitglieder` anlegen. Rückgabe: neue id. */
function antrag_insert(array $d): int
{
    $sql = "INSERT INTO mitglieder
              (vorname, nachname, email, telefon, geburtsdatum,
               hausnummer, strasse, plz, ort, land,
               wcf_user_id, forum_name,
               status, antragsart, antragsdatum, quelle, bemerkung)
            VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?, 'antrag','online',?, 'mitgliedsantrag', ?)";
    $stmt = WCF::getDB()->prepareStatement($sql);
    $stmt->execute([
        $d['vorname'], $d['nachname'], $d['email'], $d['telefon'], $d['geburtsdatum'],
        $d['hausnummer'], $d['strasse'], $d['plz'], $d['ort'], $d['land'],
        $d['wcf_user_id'], $d['forum_name'],
        $d['antragsdatum'], $d['bemerkung'],
    ]);
    return (int) WCF::getDB()->getInsertID('mitglieder', 'id');
}

/**
 * Bestätigungs- und Zahlungsdetails als Text/HTML.
 * @return array{betreff:string, text:string, html:string}
 */
function antrag_mail_inhalt(array $d, string $stornoUrl): array
{
    $name = trim($d['vorname'] . ' ' . $d['nachname']);
    $jahr = date('Y');
    $verwendung = str_replace(['{jahr}', '{name}'], [$jahr, $name], ANTRAG_VERWENDUNG);

    $kontoZeilen = [
        'Empfänger:        ' . ANTRAG_KONTO_INHABER,
        'IBAN:             ' . ANTRAG_IBAN,
    ];
    if (ANTRAG_BIC !== '')  { $kontoZeilen[] = 'BIC:              ' . ANTRAG_BIC; }
    if (ANTRAG_BANK !== '') { $kontoZeilen[] = 'Bank:             ' . ANTRAG_BANK; }
    $kontoZeilen[] = 'Betrag:           ' . ANTRAG_BEITRAG;
    $kontoZeilen[] = 'Verwendungszweck: ' . $verwendung;

    $betreff = 'Dein Mitgliedsantrag bei ' . ANTRAG_VEREIN_NAME;

    $text =
        "Hallo " . $name . ",\n\n" .
        "vielen Dank für deinen Mitgliedsantrag bei " . ANTRAG_VEREIN_NAME . ".\n\n" .
        "Damit deine Mitgliedschaft wirksam wird, überweise bitte den Jahresbeitrag " .
        "auf unser Vereinskonto:\n\n" .
        implode("\n", $kontoZeilen) . "\n\n" .
        "Sobald die Zahlung beim Verein eingegangen und bestätigt ist, bist du " .
        "offizielles Mitglied und erhältst deine Mitgliedskarte.\n\n" .
        "Du hast dich geirrt oder wolltest dich nur im Forum anmelden?\n" .
        "Dann kannst du deinen Antrag hier zurückziehen:\n" . $stornoUrl . "\n\n" .
        "Bei Fragen erreichst du uns unter " . ANTRAG_KONTAKT_EMAIL . ".\n\n" .
        "Viele Grüße\n" . ANTRAG_VEREIN_NAME . "\n";

    $kontoHtml = '';
    foreach ($kontoZeilen as $z) {
        $pos = strpos($z, ':');
        $label = antrag_e(rtrim(substr($z, 0, $pos)));
        $wert  = antrag_e(trim(substr($z, $pos + 1)));
        $kontoHtml .= '<tr><td style="padding:2px 12px 2px 0;color:#555;">' . $label .
                      '</td><td style="padding:2px 0;font-weight:bold;">' . $wert . '</td></tr>';
    }

    $html =
        '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222;line-height:1.5;">' .
        '<p>Hallo ' . antrag_e($name) . ',</p>' .
        '<p>vielen Dank für deinen Mitgliedsantrag bei <strong>' . antrag_e(ANTRAG_VEREIN_NAME) . '</strong>.</p>' .
        '<p>Damit deine Mitgliedschaft wirksam wird, überweise bitte den Jahresbeitrag auf unser Vereinskonto:</p>' .
        '<table style="border-collapse:collapse;margin:12px 0;background:#f6f8fa;padding:8px;">' . $kontoHtml . '</table>' .
        '<p>Sobald die Zahlung eingegangen und bestätigt ist, bist du offizielles Mitglied und erhältst deine Mitgliedskarte.</p>' .
        '<p style="margin-top:18px;padding:12px;background:#fff6f6;border:1px solid #f0c0c0;border-radius:6px;">' .
        'Du hast dich geirrt oder wolltest dich nur im Forum anmelden?<br>' .
        '<a href="' . antrag_e($stornoUrl) . '">Antrag hier zurückziehen</a></p>' .
        '<p>Bei Fragen erreichst du uns unter <a href="mailto:' . antrag_e(ANTRAG_KONTAKT_EMAIL) . '">' .
        antrag_e(ANTRAG_KONTAKT_EMAIL) . '</a>.</p>' .
        '<p>Viele Grüße<br>' . antrag_e(ANTRAG_VEREIN_NAME) . '</p>' .
        '</div>';

    return ['betreff' => $betreff, 'text' => $text, 'html' => $html];
}

/**
 * Versendet eine E-Mail über das WoltLab-Mailsystem.
 * Rückgabe: true bei Erfolg, sonst Fehlermeldung (string).
 */
function antrag_send_mail(string $toEmail, string $toName, string $betreff, string $text, string $html)
{
    try {
        $email = new Email();
        $email->addRecipient(new Mailbox($toEmail, $toName !== '' ? $toName : null));
        if (ANTRAG_MAIL_FROM !== '') {
            $email->setSender(new Mailbox(ANTRAG_MAIL_FROM, ANTRAG_MAIL_FROM_NAME));
        }
        if (ANTRAG_KONTAKT_EMAIL !== '') {
            $email->addReplyTo(new Mailbox(ANTRAG_KONTAKT_EMAIL, ANTRAG_MAIL_FROM_NAME));
        }
        $email->setSubject($betreff);
        $email->setBody(new MimePartFacade([
            new PlainTextMimePart($text),
            new HtmlTextMimePart($html),
        ]));
        $email->send();
        return true;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

// ===========================================================================
// Seiten-Layout (gemeinsamer Rahmen)
// ===========================================================================

function antrag_layout(string $titel, string $inhalt): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">' .
        '<meta name="viewport" content="width=device-width, initial-scale=1">' .
        '<title>' . antrag_e($titel) . ' – ' . antrag_e(ANTRAG_VEREIN_NAME) . '</title>' .
        '<style>' .
        'body{font-family:Arial,Helvetica,sans-serif;background:#eef1f4;margin:0;color:#222;}' .
        '.wrap{max-width:640px;margin:24px auto;padding:0 16px;}' .
        '.card{background:#fff;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,.08);padding:24px;}' .
        'h1{font-size:22px;margin:0 0 16px;}' .
        'h2{font-size:17px;margin:20px 0 8px;}' .
        'label{display:block;font-weight:bold;margin:12px 0 4px;font-size:14px;}' .
        'input,select,textarea{width:100%;box-sizing:border-box;padding:9px;border:1px solid #ccd;' .
        'border-radius:6px;font-size:15px;}' .
        '.row{display:flex;gap:12px;}.row>div{flex:1;}' .
        '.btn{display:inline-block;background:#2b6cb0;color:#fff;border:0;border-radius:6px;' .
        'padding:12px 20px;font-size:16px;cursor:pointer;text-decoration:none;}' .
        '.btn.gray{background:#718096;}' .
        '.muted{color:#666;font-size:13px;}' .
        '.note{background:#f6f8fa;border:1px solid #e2e8f0;border-radius:6px;padding:12px;margin:12px 0;}' .
        '.err{background:#fff5f5;border:1px solid #feb2b2;color:#9b2c2c;border-radius:6px;padding:12px;margin:12px 0;}' .
        '.ok{background:#f0fff4;border:1px solid #9ae6b4;color:#22543d;border-radius:6px;padding:12px;margin:12px 0;}' .
        'table.konto td{padding:3px 12px 3px 0;}' .
        '</style></head><body><div class="wrap"><div class="card">' . $inhalt .
        '</div><p class="muted" style="text-align:center;margin-top:14px;">' .
        antrag_e(ANTRAG_VEREIN_NAME) . '</p></div></body></html>';
}

/** Kontodaten als HTML-Tabelle (für Danke-Seite). */
function antrag_konto_html(array $d): string
{
    $name = trim($d['vorname'] . ' ' . $d['nachname']);
    $verwendung = str_replace(['{jahr}', '{name}'], [date('Y'), $name], ANTRAG_VERWENDUNG);
    $zeilen = [['Empfänger', ANTRAG_KONTO_INHABER], ['IBAN', ANTRAG_IBAN]];
    if (ANTRAG_BIC !== '')  { $zeilen[] = ['BIC', ANTRAG_BIC]; }
    if (ANTRAG_BANK !== '') { $zeilen[] = ['Bank', ANTRAG_BANK]; }
    $zeilen[] = ['Betrag', ANTRAG_BEITRAG];
    $zeilen[] = ['Verwendungszweck', $verwendung];
    $html = '<table class="konto">';
    foreach ($zeilen as $z) {
        $html .= '<tr><td style="color:#555;">' . antrag_e($z[0]) . '</td>' .
                 '<td style="font-weight:bold;">' . antrag_e($z[1]) . '</td></tr>';
    }
    return $html . '</table>';
}

// ===========================================================================
// Aktion: Formular anzeigen
// ===========================================================================

function antrag_show_form(array $errors = [], array $alt = []): void
{
    $user = antrag_current_user();
    $val = function (string $k, string $default = '') use ($alt) {
        return antrag_e($alt[$k] ?? $default);
    };
    $formToken = antrag_make_token('form', (string) time());

    $errHtml = '';
    if ($errors) {
        $errHtml = '<div class="err"><strong>Bitte prüfe deine Eingaben:</strong><ul style="margin:6px 0 0 18px;">';
        foreach ($errors as $e) {
            $errHtml .= '<li>' . antrag_e($e) . '</li>';
        }
        $errHtml .= '</ul></div>';
    }

    $userHinweis = '';
    if ($user) {
        $userHinweis = '<p class="muted">Angemeldet als <strong>' . antrag_e($user['username']) .
            '</strong> – dein Antrag wird mit deinem Forenkonto verknüpft.</p>';
    }

    $inhalt =
        '<h1>Mitgliedsantrag</h1>' .
        '<p>Werde Mitglied bei <strong>' . antrag_e(ANTRAG_VEREIN_NAME) . '</strong>. ' .
        'Nach dem Absenden erhältst du eine Bestätigung per E-Mail mit den ' .
        'Überweisungsdaten für den Jahresbeitrag (' . antrag_e(ANTRAG_BEITRAG) . ').</p>' .
        $userHinweis . $errHtml .
        '<form method="post" action="' . antrag_e(ANTRAG_SELF_URL) . '" autocomplete="on">' .
        '<input type="hidden" name="form_token" value="' . antrag_e($formToken) . '">' .
        // Honeypot (für Menschen unsichtbar)
        '<div style="position:absolute;left:-5000px;" aria-hidden="true">' .
        '<label>Bitte leer lassen</label><input type="text" name="website" tabindex="-1" autocomplete="off"></div>' .
        '<div class="row"><div><label>Vorname *</label>' .
        '<input name="vorname" required value="' . $val('vorname') . '"></div>' .
        '<div><label>Nachname *</label>' .
        '<input name="nachname" required value="' . $val('nachname') . '"></div></div>' .
        '<label>E-Mail *</label>' .
        '<input type="email" name="email" required value="' . $val('email', $user['email'] ?? '') . '">' .
        '<div class="row"><div><label>Telefon</label>' .
        '<input name="telefon" value="' . $val('telefon') . '"></div>' .
        '<div><label>Geburtsdatum</label>' .
        '<input type="date" name="geburtsdatum" value="' . $val('geburtsdatum') . '"></div></div>' .
        '<h2>Adresse</h2>' .
        '<div class="row"><div style="flex:3"><label>Straße</label>' .
        '<input name="strasse" value="' . $val('strasse') . '"></div>' .
        '<div style="flex:1"><label>Nr.</label>' .
        '<input name="hausnummer" value="' . $val('hausnummer') . '"></div></div>' .
        '<div class="row"><div style="flex:1"><label>PLZ</label>' .
        '<input name="plz" value="' . $val('plz') . '"></div>' .
        '<div style="flex:3"><label>Ort</label>' .
        '<input name="ort" value="' . $val('ort') . '"></div></div>' .
        '<label>Land</label>' .
        '<input name="land" value="' . $val('land', ANTRAG_LAND_DEFAULT) . '">' .
        '<label>Bemerkung (optional)</label>' .
        '<textarea name="bemerkung" rows="2">' . $val('bemerkung') . '</textarea>' .
        '<p class="muted" style="margin-top:14px;">Mit dem Absenden beantragst du die ' .
        'Mitgliedschaft. Die Mitgliedschaft wird erst mit dem Eingang des Jahresbeitrags wirksam.</p>' .
        '<p style="margin-top:16px;"><button class="btn" type="submit">Antrag absenden</button></p>' .
        '</form>';

    antrag_layout('Mitgliedsantrag', $inhalt);
}

// ===========================================================================
// Aktion: Antrag verarbeiten (POST)
// ===========================================================================

function antrag_handle_post(): void
{
    // Anti-Spam: Honeypot
    if (!empty($_POST['website'])) {
        // stiller Erfolg – Bots bekommen kein Feedback
        antrag_layout('Danke', '<h1>Danke</h1><p>Dein Antrag ist eingegangen.</p>');
        return;
    }
    // Formular-Token (gegen Replay / Fremd-Posts); Alter 3 s … 6 h
    $issued = antrag_read_token('form', (string) ($_POST['form_token'] ?? ''));
    if ($issued === null || (time() - (int) $issued) < 3 || (time() - (int) $issued) > 21600) {
        antrag_show_form(['Das Formular ist abgelaufen. Bitte sende es erneut ab.'], $_POST);
        return;
    }

    $g = function (string $k): string {
        return trim((string) ($_POST[$k] ?? ''));
    };
    $alt = [
        'vorname' => $g('vorname'), 'nachname' => $g('nachname'), 'email' => $g('email'),
        'telefon' => $g('telefon'), 'geburtsdatum' => $g('geburtsdatum'),
        'strasse' => $g('strasse'), 'hausnummer' => $g('hausnummer'),
        'plz' => $g('plz'), 'ort' => $g('ort'), 'land' => $g('land'),
        'bemerkung' => $g('bemerkung'),
    ];

    // Validierung
    $errors = [];
    if ($alt['vorname'] === '')  { $errors[] = 'Vorname fehlt.'; }
    if ($alt['nachname'] === '') { $errors[] = 'Nachname fehlt.'; }
    if ($alt['email'] === '' || !filter_var($alt['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    }
    $geb = null;
    if ($alt['geburtsdatum'] !== '') {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $alt['geburtsdatum'])) {
            $geb = $alt['geburtsdatum'];
        } else {
            $errors[] = 'Geburtsdatum bitte im Format JJJJ-MM-TT.';
        }
    }
    if ($errors) {
        antrag_show_form($errors, $alt);
        return;
    }

    // Dublettenprüfung
    $existing = antrag_find_existing($alt['email']);
    if ($existing !== null) {
        $msg = $existing['status'] === 'aktiv'
            ? 'Unter dieser E-Mail bist du bereits aktives Mitglied. Bei Fragen melde dich bei ' . ANTRAG_KONTAKT_EMAIL . '.'
            : 'Unter dieser E-Mail liegt bereits ein Antrag vor. Wir melden uns bei dir. Kontakt: ' . ANTRAG_KONTAKT_EMAIL . '.';
        antrag_layout('Bereits erfasst', '<h1>Schon vorhanden</h1><div class="note">' . antrag_e($msg) . '</div>' .
            '<p><a class="btn gray" href="' . antrag_e(ANTRAG_FORUM_URL) . '">Zurück zum Forum</a></p>');
        return;
    }

    // Eintrag anlegen
    $user = antrag_current_user();
    $daten = [
        'vorname'      => $alt['vorname'],
        'nachname'     => $alt['nachname'],
        'email'        => $alt['email'],
        'telefon'      => $alt['telefon'] ?: null,
        'geburtsdatum' => $geb,
        'hausnummer'   => $alt['hausnummer'] ?: null,
        'strasse'      => $alt['strasse'] ?: null,
        'plz'          => $alt['plz'] ?: null,
        'ort'          => $alt['ort'] ?: null,
        'land'         => $alt['land'] ?: ANTRAG_LAND_DEFAULT,
        'wcf_user_id'  => $user['userID'] ?? null,
        'forum_name'   => $user['username'] ?? null,
        'antragsdatum' => date('Y-m-d'),
        'bemerkung'    => $alt['bemerkung'] ?: null,
    ];

    try {
        $id = antrag_insert($daten);
    } catch (\Throwable $e) {
        antrag_layout('Fehler', '<h1>Es ist ein Fehler aufgetreten</h1>' .
            '<div class="err">Dein Antrag konnte nicht gespeichert werden. Bitte versuche es ' .
            'später erneut oder melde dich bei ' . antrag_e(ANTRAG_KONTAKT_EMAIL) . '.</div>');
        return;
    }

    // Storno-Link erzeugen
    $stornoUrl = ANTRAG_SELF_URL . '?page=storno&t=' . rawurlencode(antrag_make_token('storno', (string) $id));

    // Bestätigungsmail an den Antragsteller
    $mail = antrag_mail_inhalt($daten, $stornoUrl);
    $mailResult = antrag_send_mail($daten['email'], trim($daten['vorname'] . ' ' . $daten['nachname']),
        $mail['betreff'], $mail['text'], $mail['html']);

    // Info-Mail an den Vorstand (Fehler hier sind unkritisch)
    if (ANTRAG_KONTAKT_EMAIL !== '') {
        $infoText = "Neuer Mitgliedsantrag:\n\n" .
            trim($daten['vorname'] . ' ' . $daten['nachname']) . "\n" .
            $daten['email'] . ($daten['telefon'] ? ' / ' . $daten['telefon'] : '') . "\n" .
            "Eingegangen: " . date('d.m.Y H:i') . "\n\n" .
            "In der Mitgliederverwaltung als Status 'antrag' sichtbar.";
        antrag_send_mail(ANTRAG_KONTAKT_EMAIL, ANTRAG_MAIL_FROM_NAME,
            'Neuer Mitgliedsantrag: ' . trim($daten['vorname'] . ' ' . $daten['nachname']),
            $infoText, antrag_e($infoText));
    }

    // Danke-Seite
    $mailHinweis = $mailResult === true
        ? '<div class="ok">Wir haben dir eine Bestätigung an <strong>' . antrag_e($daten['email']) .
          '</strong> geschickt.</div>'
        : '<div class="note">Hinweis: Die Bestätigungs-E-Mail konnte gerade nicht versendet werden – ' .
          'die Überweisungsdaten findest du aber unten. Bei Fragen: ' . antrag_e(ANTRAG_KONTAKT_EMAIL) . '.</div>';

    $inhalt =
        '<h1>Danke für deinen Antrag!</h1>' .
        $mailHinweis .
        '<p>Damit deine Mitgliedschaft wirksam wird, überweise bitte den Jahresbeitrag ' .
        'auf unser Vereinskonto:</p>' .
        antrag_konto_html($daten) .
        '<p>Sobald die Zahlung eingegangen und bestätigt ist, bist du offizielles Mitglied ' .
        'und erhältst deine Mitgliedskarte.</p>' .
        '<div class="note"><strong>Versehentlich beantragt?</strong> Wolltest du dich nur im Forum ' .
        'anmelden? Dann kannst du deinen Antrag direkt zurückziehen:<br>' .
        '<a class="btn gray" style="margin-top:8px;" href="' . antrag_e($stornoUrl) . '">Antrag zurückziehen</a></div>' .
        '<p style="margin-top:16px;"><a class="btn" href="' . antrag_e(ANTRAG_FORUM_URL) . '">Zum Forum</a></p>';

    antrag_layout('Danke', $inhalt);
}

// ===========================================================================
// Aktion: Antrag zurückziehen (Storno)
// ===========================================================================

function antrag_handle_storno(): void
{
    $id = antrag_read_token('storno', (string) ($_GET['t'] ?? ''));
    if ($id === null || (int) $id <= 0) {
        antrag_layout('Ungültiger Link', '<h1>Link ungültig</h1>' .
            '<div class="err">Dieser Storno-Link ist ungültig oder unvollständig.</div>');
        return;
    }
    $id = (int) $id;

    $stmt = WCF::getDB()->prepareStatement(
        "SELECT vorname, nachname, status FROM mitglieder WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetchArray();
    if (!$row) {
        antrag_layout('Nicht gefunden', '<h1>Nicht gefunden</h1>' .
            '<div class="note">Dieser Antrag existiert nicht (mehr).</div>');
        return;
    }
    $name = trim($row['vorname'] . ' ' . $row['nachname']);

    // Nur offene Anträge können zurückgezogen werden.
    if ($row['status'] !== 'antrag') {
        $txt = $row['status'] === 'zurueckgezogen'
            ? 'Dieser Antrag wurde bereits zurückgezogen.'
            : 'Dieser Antrag kann nicht mehr selbst zurückgezogen werden (Status: ' . $row['status'] .
              '). Bitte wende dich an ' . ANTRAG_KONTAKT_EMAIL . '.';
        antrag_layout('Storno', '<h1>Antrag zurückziehen</h1><div class="note">' . antrag_e($txt) . '</div>');
        return;
    }

    // Bestätigung per POST (gegen versehentliches/automatisches Aufrufen).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirm'])) {
        $upd = WCF::getDB()->prepareStatement(
            "UPDATE mitglieder
                SET status = 'zurueckgezogen',
                    austrittsdatum = ?,
                    bemerkung = TRIM(CONCAT(COALESCE(bemerkung,''), ?))
              WHERE id = ? AND status = 'antrag'");
        $upd->execute([date('Y-m-d'), "\nAntrag vom Antragsteller zurückgezogen am " . date('d.m.Y'), $id]);

        antrag_layout('Zurückgezogen', '<h1>Antrag zurückgezogen</h1>' .
            '<div class="ok">Dein Mitgliedsantrag wurde zurückgezogen, ' . antrag_e($name) . '. ' .
            'Es entstehen dir keine Verpflichtungen.</div>' .
            '<p class="muted">Falls du dich nur im Forum anmelden wolltest: Dein Forenkonto bleibt davon unberührt.</p>' .
            '<p style="margin-top:14px;"><a class="btn" href="' . antrag_e(ANTRAG_FORUM_URL) . '">Zum Forum</a></p>');
        return;
    }

    // Bestätigungs-Seite
    antrag_layout('Antrag zurückziehen',
        '<h1>Antrag zurückziehen</h1>' .
        '<p>Möchtest du deinen Mitgliedsantrag (<strong>' . antrag_e($name) . '</strong>) wirklich zurückziehen?</p>' .
        '<form method="post" action="' . antrag_e(ANTRAG_SELF_URL . '?page=storno&t=' . rawurlencode((string) ($_GET['t'] ?? ''))) . '">' .
        '<input type="hidden" name="confirm" value="1">' .
        '<p style="margin-top:12px;"><button class="btn gray" type="submit">Ja, Antrag zurückziehen</button> ' .
        '<a class="btn" style="margin-left:8px;" href="' . antrag_e(ANTRAG_FORUM_URL) . '">Abbrechen</a></p>' .
        '</form>');
}

// ===========================================================================
// Aktion: Diagnose (nur Admin)
// ===========================================================================

function antrag_handle_test(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    if (!antrag_is_admin()) {
        echo "Nur für Administratoren.\n";
        return;
    }
    echo "AFOL Mitgliedsantrag – Diagnose\n";
    echo "DIAGNOSE-VERSION: 2026-06-26-antrag\n\n";
    echo "Verein:        " . ANTRAG_VEREIN_NAME . "\n";
    echo "IBAN gesetzt:  " . (strpos(ANTRAG_IBAN, 'x') === false ? 'ja' : 'NEIN – bitte echte IBAN eintragen') . "\n";
    echo "Beitrag:       " . ANTRAG_BEITRAG . "\n";
    echo "Kontakt:       " . ANTRAG_KONTAKT_EMAIL . "\n";
    echo "Secret gesetzt: " . (ANTRAG_SECRET !== 'BITTE-LANGES-ZUFALLSGEHEIMNIS-SETZEN' ? 'ja' : 'NEIN') . "\n";
    echo "Self-URL:      " . ANTRAG_SELF_URL . "\n\n";

    $u = antrag_current_user();
    echo "Eingeloggt als: " . ($u ? $u['username'] . ' <' . $u['email'] . '>' : '(niemand)') . "\n\n";

    // Test-Mail an die Admin-Adresse
    if (isset($_GET['mail']) && $u) {
        $r = antrag_send_mail($u['email'], $u['username'], 'AFOL Test-Mail (Mitgliedsantrag)',
            "Dies ist eine Test-Mail des Mitgliedsantrag-Skripts.", '<p>Dies ist eine <strong>Test-Mail</strong>.</p>');
        echo "Test-Mail an " . $u['email'] . ": " . ($r === true ? 'gesendet (Posteingang prüfen)' : 'FEHLER: ' . $r) . "\n";
    } else {
        echo "Test-Mail: mitgliedsantrag.php?page=test&mail=1 aufrufen (sendet an deine Adresse).\n";
    }
}

// ===========================================================================
// Router
// ===========================================================================

$page = (string) ($_GET['page'] ?? '');

if ($page === 'test') {
    antrag_handle_test();
} elseif ($page === 'storno') {
    antrag_handle_storno();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    antrag_handle_post();
} else {
    antrag_show_form();
}

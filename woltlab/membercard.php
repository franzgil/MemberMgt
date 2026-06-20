<?php
/**
 * AFOL.lu – Mitgliedskarte
 * Drop-in Skript für das WoltLab Suite 5.5 Root-Verzeichnis (neben global.php).
 *
 * Aufruf:
 *   membercard.php?page=card                 -> Karte des eingeloggten Mitglieds (PDF)
 *   membercard.php?page=card&userID=123      -> Karte eines Mitglieds (nur Admin)
 *   membercard.php?page=verify&t=<token>     -> Verifizierungsseite (Ziel des QR-Codes)
 *
 * Sicherheit:
 *   - QR enthält eine signierte Verify-URL (HMAC-SHA256), kein Klartext-Profil.
 *   - Beim Scannen wird gegen die DB geprüft (Konto aktiv?), nicht nur der Token.
 *   - CARD_SECRET außerhalb des Webroots ablegen (siehe unten).
 */

use wcf\system\WCF;

require_once(__DIR__ . '/global.php');

// ---------------------------------------------------------------------------
// Konfiguration
// ---------------------------------------------------------------------------

// GEHEIMNIS: idealerweise außerhalb des Webroots. Reihenfolge:
//   1. Umgebungsvariable AFOL_CARD_SECRET (falls verfügbar)
//   2. Datei card-secret.txt – zuerst eine Ebene ÜBER public_html, dann lokal.
//      (Am besten unter /home/<user>/afol-secrets/card-secret.txt ablegen.)
//   3. Platzhalter (nur für Tests – unbedingt ersetzen).
// NICHT im Repository einchecken.
$cardSecret = getenv('AFOL_CARD_SECRET') ?: '';
if ($cardSecret === '') {
    foreach ([
        __DIR__ . '/../../afol-secrets/card-secret.txt',
        __DIR__ . '/../afol-secrets/card-secret.txt',
        __DIR__ . '/card-secret.txt',
    ] as $secretFile) {
        if (is_file($secretFile)) {
            $cardSecret = trim((string) file_get_contents($secretFile));
            break;
        }
    }
}
define('CARD_SECRET', $cardSecret ?: 'BITTE-LANGES-ZUFALLSGEHEIMNIS-SETZEN');

// Basis-URL des Forums (für die Verify-URL im QR-Code)
define('CARD_BASE_URL', rtrim(WCF::getPath(), '/') . '/membercard.php');

// Pfad zum Logo (im Webroot ablegen)
define('CARD_LOGO', __DIR__ . '/images/afol-logo.png');

// Gültigkeitsdauer des Tokens in Sekunden (z. B. 1 Jahr). 0 = unbegrenzt.
define('CARD_TOKEN_TTL', 365 * 24 * 3600);

// Monat der Generalversammlung – dort werden die Karten neu ausgegeben.
// Muss zur App-Konstante GV_MONAT passen: das Mitgliedsjahr läuft von GV zu GV.
define('CARD_GV_MONTH', 3);

// --- Mitgliedsnummer aus eigener Tabelle gf_membres (verknüpft über E-Mail) ---
// Nummer auf der Karte = erster Buchstabe aus Membre (groß) + No, 3-stellig
// mit führenden Nullen. Bei allen Typen außer "A" wird von No 1000 abgezogen.
// Beispiele: Membre "A", No 42 -> "A042";  Membre "B", No 1042 -> "B042".
// Falls Spaltennamen abweichen, hier anpassen.
define('CARD_MEMBERS_TABLE',   'gf_membres'); // Name der Tabelle
define('CARD_MEMBERS_NUMCOL',  'No');         // Spalte mit der laufenden Nummer
define('CARD_MEMBERS_TYPECOL', 'Membre');     // Spalte für den Anfangsbuchstaben
define('CARD_MEMBERS_MAILCOL', 'E-Mail');     // Spalte mit der E-Mail-Adresse
define('CARD_MEMBERS_STATUSCOL','status');     // Spalte mit dem Mitgliedsstatus
// status-Werte (kleingeschrieben), die als INAKTIV gelten -> Karte UNGÜLTIG.
// Unbekannte/leere Werte gelten als aktiv (keine versehentliche Sperre).
define('CARD_MEMBERS_INACTIVE', 'inactif,inactive,demission,démission,radie,radié,quitte,exclu');

// Zusätzlich zu Administratoren dürfen Mitglieder dieser WoltLab-Gruppe(n)
// fremde Karten erzeugen. Mehrere Namen mit Komma trennen möglich.
define('CARD_ADMIN_GROUPS', 'Tresorier');

// ---------------------------------------------------------------------------
// Token-Helfer (HMAC-signiert, keine DB-Änderung nötig)
// ---------------------------------------------------------------------------

/**
 * Erzeugt einen signierten Token für eine userID.
 * Aufbau: base64url( userID|validUntil ) . "." . hmac
 * $validUntil: Unix-Zeit (aus den Beiträgen). 0 = unbegrenzt/unbekannt.
 */
function card_make_token(int $userID, int $validUntil = 0): string {
    $payload = $userID . '|' . $validUntil;
    $data = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $payload, CARD_SECRET);
    return $data . '.' . substr($sig, 0, 32);
}

/**
 * Prüft einen Token. Rückgabe: ['userID'=>int,'validUntil'=>int] oder false.
 */
function card_verify_token(string $token) {
    $parts = explode('.', $token);
    if (count($parts) !== 2) return false;
    [$data, $sig] = $parts;
    $payload = base64_decode(strtr($data, '-_', '+/'));
    if ($payload === false || strpos($payload, '|') === false) return false;
    [$userID, $validUntil] = explode('|', $payload, 2);
    $expected = substr(hash_hmac('sha256', $payload, CARD_SECRET), 0, 32);
    if (!hash_equals($expected, $sig)) return false;
    if ((int)$validUntil !== 0 && (int)$validUntil < time()) return false;
    return ['userID' => (int)$userID, 'validUntil' => (int)$validUntil];
}

/**
 * Darf der eingeloggte Nutzer fremde Karten erzeugen?
 * Erlaubt für Administratoren ODER Mitglieder der in CARD_ADMIN_GROUPS
 * konfigurierten Gruppen (Abgleich über den Gruppennamen).
 */
function card_user_may_manage(): bool {
    // 1) Administrator-Recht
    if (WCF::getSession()->getPermission('admin.user.canEditUser')) {
        return true;
    }
    // 2) Mitglied einer erlaubten Gruppe (per Name)
    $names = array_filter(array_map('trim', explode(',', CARD_ADMIN_GROUPS)));
    if (!$names) {
        return false;
    }
    try {
        $userGroupIDs = WCF::getUser()->getGroupIDs();
        if (!$userGroupIDs) {
            return false;
        }
        // Platzhalter für IN-Liste der Namen
        $place = implode(',', array_fill(0, count($names), '?'));
        $sql = "SELECT groupID FROM wcf" . WCF_N . "_user_group
                WHERE  groupName IN (" . $place . ")";
        $stmt = WCF::getDB()->prepareStatement($sql);
        $stmt->execute(array_values($names));
        $allowedGroupIDs = [];
        while ($r = $stmt->fetchArray()) {
            $allowedGroupIDs[] = (int)$r['groupID'];
        }
        foreach ($userGroupIDs as $gid) {
            if (in_array((int)$gid, $allowedGroupIDs, true)) {
                return true;
            }
        }
    } catch (\Throwable $e) {
        // Im Zweifel keine erweiterte Berechtigung
    }
    return false;
}

/**
 * Lädt Mitgliedsdaten aus der WoltLab-DB.
 * Mitgliedsnummer kommt aus der Tabelle gf_membres (verknüpft über E-Mail).
 * Rückgabe: ['userID','username','displayName','memberNumber','email','active'] oder null.
 */
function card_load_member(int $userID): ?array {
    // Vorname = Profilfeld 68, Nachname = Profilfeld 69.
    // Profilfeldwerte liegen in wcf*_user_option_value als Spalten userOption{ID}.
    $sql = "SELECT u.userID, u.username, u.email, u.banned, u.activationCode,
                   ov.userOption68 AS firstName,
                   ov.userOption69 AS lastName
            FROM   wcf" . WCF_N . "_user u
            LEFT JOIN wcf" . WCF_N . "_user_option_value ov ON ov.userID = u.userID
            WHERE  u.userID = ?";
    $stmt = WCF::getDB()->prepareStatement($sql);
    $stmt->execute([$userID]);
    $row = $stmt->fetchArray();
    if (!$row) return null;

    // "aktiv" = nicht gesperrt und Konto aktiviert (activationCode == 0)
    $active = ((int)$row['banned'] === 0 && (int)$row['activationCode'] === 0);

    // Anzeigename aus Vor- und Nachname; Fallback auf username
    $first = trim((string)($row['firstName'] ?? ''));
    $last  = trim((string)($row['lastName'] ?? ''));
    $displayName = trim($first . ' ' . $last);
    if ($displayName === '') {
        $displayName = $row['username'];
    }

    // Mitgliedsnummer aus eigener Tabelle gf_membres beziehen (über E-Mail).
    // Zusammensetzung: erster Buchstabe aus Membre (groß) + No (3-stellig).
    // Außerdem: Beitragsjahre (Cot JJJJ) lesen und daraus die Gültigkeit
    // berechnen. Fallback auf die WoltLab userID, falls kein Eintrag existiert.
    $memberNumber = (string)$row['userID'];
    $validUntil   = 0;   // Unix-Zeit; 0 = unbekannt / kein Beitrag erfasst
    $paidYears    = [];  // Liste der bezahlten Jahre (für Diagnose/Anzeige)
    $payDates     = [];  // Jahr => Zahldatum (aus gf_membres oder beitraege)
    $email = trim((string)$row['email']);
    $statusActive = true;   // aus gf_membres.status; Standard aktiv
    $statusValue  = '';
    $lastPaidDate = '';     // Date_de_payement des letzten bezahlten Jahres
    if ($email !== '') {
        // Nur die tatsächlich vorhandenen "Cot JJJJ"-Spalten abfragen, damit
        // eine fehlende Jahresspalte nicht die ganze Abfrage scheitern lässt.
        $cotYears = [];
        $hasPayDate = [];   // Jahre, für die eine Date_de_payement-Spalte existiert
        try {
            $colStmt = WCF::getDB()->prepareStatement(
                "SHOW COLUMNS FROM `" . CARD_MEMBERS_TABLE . "`"
            );
            $colStmt->execute();
            while ($c = $colStmt->fetchArray()) {
                $field = (string)reset($c);
                if (preg_match('/^Cot\s+(\d{4})$/', $field, $mm)) {
                    $cotYears[] = (int)$mm[1];
                } elseif (preg_match('/^Date_de_payement_(\d{4})$/', $field, $mm2)) {
                    $hasPayDate[(int)$mm2[1]] = true;
                }
            }
            sort($cotYears);
        } catch (\Throwable $e) {
            $cotYears = [];
        }

        $cotSelect = '';
        foreach ($cotYears as $y) {
            $cotSelect .= ", `Cot " . $y . "` AS cot" . $y;
            if (!empty($hasPayDate[$y])) {
                $cotSelect .= ", `Date_de_payement_" . $y . "` AS pay" . $y;
            }
        }
        $msql = "SELECT `" . CARD_MEMBERS_TYPECOL . "` AS memberType,
                        `" . CARD_MEMBERS_NUMCOL . "`  AS memberNo,
                        `" . CARD_MEMBERS_STATUSCOL . "` AS memberStatus"
                . $cotSelect . "
                 FROM   `" . CARD_MEMBERS_TABLE . "`
                 WHERE  `" . CARD_MEMBERS_MAILCOL . "` = ?
                 LIMIT  1";
        try {
            $mstmt = WCF::getDB()->prepareStatement($msql);
            $mstmt->execute([$email]);
            $mrow = $mstmt->fetchArray();
            if ($mrow && trim((string)$mrow['memberNo']) !== '') {
                // erster Buchstabe aus Membre, großgeschrieben (mehrbyte-sicher)
                $type = trim((string)($mrow['memberType'] ?? ''));
                $letter = '';
                if ($type !== '') {
                    $letter = function_exists('mb_substr')
                        ? mb_strtoupper(mb_substr($type, 0, 1, 'UTF-8'), 'UTF-8')
                        : strtoupper(substr($type, 0, 1));
                }
                // Bei allen Typen außer "A" wird von der laufenden Nummer 1000 abgezogen.
                $rawNo = (int)$mrow['memberNo'];
                if ($letter !== 'A') {
                    $rawNo -= 1000;
                }
                if ($rawNo < 0) {
                    $rawNo = 0;
                }
                // 3-stellig mit führenden Nullen, z. B. B042
                $no = str_pad((string)$rawNo, 3, '0', STR_PAD_LEFT);
                $memberNumber = $letter . $no;

                // status auswerten: nur bekannte Inaktiv-Werte sperren.
                $statusValue = trim((string)($mrow['memberStatus'] ?? ''));
                if ($statusValue !== '') {
                    $lc = function ($s) {
                        return function_exists('mb_strtolower')
                            ? mb_strtolower($s, 'UTF-8') : strtolower($s);
                    };
                    $inactiveList = array_filter(array_map('trim',
                        explode(',', $lc(CARD_MEMBERS_INACTIVE))));
                    if (in_array($lc($statusValue), $inactiveList, true)) {
                        $statusActive = false;
                    }
                }

                // Ein Jahr gilt als bezahlt, wenn Cot JJJJ > 0 ODER ein
                // Date_de_payement_JJJJ gesetzt ist. (Die finale Gültigkeit
                // wird weiter unten aus allen Quellen berechnet.)
                foreach ($cotYears as $y) {
                    $cotOk  = isset($mrow['cot' . $y]) && (int)$mrow['cot' . $y] > 0;
                    $dateOk = isset($mrow['pay' . $y]) && $mrow['pay' . $y]
                              && $mrow['pay' . $y] !== '0000-00-00';
                    if ($cotOk || $dateOk) {
                        if (!in_array($y, $paidYears, true)) {
                            $paidYears[] = $y;
                        }
                        if ($dateOk) {
                            $payDates[$y] = (string)$mrow['pay' . $y];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Tabelle/Spalte nicht vorhanden -> still auf userID-Fallback bleiben
        }
    }

    // Maßgeblich ist die App (mitglieder/beitraege) – dieselbe Quelle wie
    // Dashboard und Trésorier-Bestätigung. gf_membres dient nur als Fallback,
    // falls das Mitglied (noch) nicht mit der App verknüpft ist (wcf_user_id).
    $appLinked     = false;
    $appPaid       = [];
    $beitrittsjahr = null;   // für das Gnadenjahr (wie in der App)
    try {
        $ms = WCF::getDB()->prepareStatement(
            "SELECT id, beitrittsdatum, antragsdatum FROM mitglieder WHERE wcf_user_id = ? LIMIT 1"
        );
        $ms->execute([(int)$row['userID']]);
        if ($mr2 = $ms->fetchArray()) {
            $appLinked = true;
            foreach (['beitrittsdatum', 'antragsdatum'] as $df) {
                if (!empty($mr2[$df]) && $mr2[$df] !== '0000-00-00') {
                    $beitrittsjahr = (int)substr((string)$mr2[$df], 0, 4);
                    break;
                }
            }
            $bs = WCF::getDB()->prepareStatement(
                "SELECT jahr, bezahlt_am FROM beitraege WHERE mitglied_id = ? AND bezahlt_am IS NOT NULL"
            );
            $bs->execute([(int)$mr2['id']]);
            while ($br = $bs->fetchArray()) {
                $y = (int)$br['jahr'];
                if ($y <= 0) {
                    continue;
                }
                if (!in_array($y, $appPaid, true)) {
                    $appPaid[] = $y;
                }
                if (!empty($br['bezahlt_am']) && $br['bezahlt_am'] !== '0000-00-00') {
                    $payDates[$y] = (string)$br['bezahlt_am'];
                }
            }
        }
    } catch (\Throwable $e) {
        // App-Tabellen evtl. nicht vorhanden -> gf_membres-Fallback bleibt
    }

    // Bezahlte Jahre vereinen: App-Beiträge (beitraege – wirken sofort) UND
    // gf_membres (Sicherheitsnetz, damit keine Zahlung verloren geht).
    $paidYears = array_values(array_unique(array_merge($paidYears, $appPaid)));

    // Gültigkeit (App-Regel): gedeckt bis zur Generalversammlung (CARD_GV_MONTH)
    // im Jahr nach dem letzten bezahlten Jahr; Beitrittsjahr + 1 ist als
    // Gnadenjahr gedeckt (nur bei bekanntem Beitritts-/Antragsdatum).
    $bisJahr = null;
    if ($beitrittsjahr !== null) {
        $bisJahr = $beitrittsjahr + 1;
    }
    if (!empty($paidYears)) {
        sort($paidYears);
        $maxPaid = (int)max($paidYears);
        $bisJahr = max((int)$bisJahr, $maxPaid);
        if (!empty($payDates[$maxPaid])) {
            $lastPaidDate = $payDates[$maxPaid];
        }
    }
    if ($bisJahr !== null) {
        $validUntil = mktime(0, 0, 0, CARD_GV_MONTH, 1, $bisJahr + 1);
    }

    // Gesamtaktiv = WoltLab-Status UND gf_membres-Status
    $active = $active && $statusActive;

    return [
        'userID'       => (int)$row['userID'],
        'username'     => $row['username'],
        'displayName'  => $displayName,
        'email'        => $row['email'],
        'memberNumber' => $memberNumber,
        'active'       => $active,
        'validUntil'   => $validUntil,   // Unix-Zeit, 0 = kein Beitrag erfasst
        'paidYears'    => $paidYears,    // Liste bezahlter Jahre
        'statusValue'  => $statusValue,  // Rohwert aus gf_membres.status
        'lastPaidDate' => $lastPaidDate, // Zahldatum des letzten bezahlten Jahres
    ];
}

// ---------------------------------------------------------------------------
// QR-Code (chillerlan/php-qrcode aus WoltLab, mit Fallback)
// ---------------------------------------------------------------------------

/**
 * Liefert ein PNG (binär) mit dem QR-Code für die gegebene URL.
 *
 * chillerlan/php-qrcode ist in WoltLab unter lib/system/api/php-qrcode/
 * gebündelt, wird aber NICHT automatisch geladen -> erst require_once.
 * Die API unterscheidet sich zwischen v4 und v5, daher versionsrobust.
 */
function card_qr_png(string $text): string {
    // chillerlan-Autoloader laden, falls Klasse noch nicht verfügbar
    if (!class_exists('\chillerlan\QRCode\QRCode')) {
        foreach ([
            WCF_DIR . 'lib/system/api/php-qrcode/vendor/autoload.php',
            WCF_DIR . 'lib/system/api/php-qrcode/autoload.php',
            WCF_DIR . 'lib/system/api/autoload.php',
        ] as $autoload) {
            if (is_file($autoload)) { require_once $autoload; break; }
        }
    }

    if (class_exists('\chillerlan\QRCode\QRCode')) {
        $qrClass   = '\chillerlan\QRCode\QRCode';
        $optsClass = '\chillerlan\QRCode\QROptions';

        // Gemeinsame Optionen für v4 und v5
        $opts = [
            'eccLevel'         => 0b00,   // ECC_L als Roh-Bitwert (versionsneutral)
            'scale'            => 8,
            'addQuietzone'     => true,
            'imageBase64'      => false,  // v4: rohe PNG-Bytes
            'outputBase64'     => false,  // v5: rohe Bytes statt data-URI
            'imageTransparent' => false,
        ];

        // Output-Format auf PNG zwingen – Konstante/Interface je nach Version
        if (defined($qrClass . '::OUTPUT_IMAGE_PNG')) {
            // v4
            $opts['outputType'] = constant($qrClass . '::OUTPUT_IMAGE_PNG');
        } elseif (class_exists('\chillerlan\QRCode\Output\QRGdImagePNG')) {
            // v5
            $opts['outputInterface'] = \chillerlan\QRCode\Output\QRGdImagePNG::class;
        } elseif (class_exists('\chillerlan\QRCode\Output\QRImage')) {
            // ältere v4-Variante
            $opts['outputInterface'] = \chillerlan\QRCode\Output\QRImage::class;
        }

        $options = new $optsClass($opts);
        $qr = new $qrClass($options);
        $out = $qr->render($text);

        // Falls doch eine data-URI zurückkommt, in Rohbytes umwandeln
        if (is_string($out) && strpos($out, 'data:') === 0) {
            $out = base64_decode(substr($out, strpos($out, ',') + 1));
        }
        if (is_string($out) && $out !== '') {
            return $out;
        }
    }

    // Fallback: WoltLab-Wrapper wcf\util\QR (falls Plugin installiert)
    if (class_exists('\wcf\util\QR')) {
        \wcf\util\QR::setOptions(['scale' => 8, 'addQuietzone' => true]);
        $uri = \wcf\util\QR::getInstance()->render($text);
        if (strpos($uri, 'data:') === 0) {
            return base64_decode(substr($uri, strpos($uri, ',') + 1));
        }
    }

    // Letzter Fallback: reiner GD-QR-Encoder fehlt -> sprechende Meldung
    throw new \RuntimeException(
        'QR-Code-Bibliothek nicht ladbar. Prüfe den Pfad '
        . 'lib/system/api/php-qrcode/ in der WoltLab-Installation.'
    );
}

// ---------------------------------------------------------------------------
// PDF-Karte (TCPDF – in WoltLab 5.5 gebündelt)
// ---------------------------------------------------------------------------

/**
 * Lädt FPDF. FPDF ist eine einzelne, abhängigkeitsfreie PHP-Datei
 * (fpdf.php) und wird neben membercard.php abgelegt – kein Composer,
 * kein WoltLab-Plugin nötig. TCPDF/DomPDF sind in WSC 5.5 nicht
 * zuverlässig vorhanden.
 */
function card_load_fpdf(): void {
    if (class_exists('\FPDF')) return;

    foreach ([
        __DIR__ . '/fpdf.php',
        __DIR__ . '/lib/fpdf.php',
        __DIR__ . '/fpdf/fpdf.php',
    ] as $file) {
        if (is_file($file)) { require_once $file; break; }
    }

    if (!class_exists('\FPDF')) {
        throw new \RuntimeException(
            'FPDF nicht gefunden. Lege fpdf.php in dasselbe Verzeichnis '
            . 'wie membercard.php.'
        );
    }
}

function card_render_pdf(array $m): void {
    // Verify-URL für den QR-Code
    $verifyUrl = CARD_BASE_URL . '?page=verify&t=' . urlencode(card_make_token($m['userID'], (int)($m['validUntil'] ?? 0)));

    // QR als temporäre PNG-Datei (FPDF::Image braucht einen Pfad)
    $qrPng = card_qr_png($verifyUrl);
    $qrTmp = tempnam(sys_get_temp_dir(), 'qr') . '.png';
    file_put_contents($qrTmp, $qrPng);

    // Kartenformat: 85.6 x 54 mm (Querformat)
    card_load_fpdf();
    $pdf = new \FPDF('L', 'mm', [54, 85.6]);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->AddPage();

    $W = 85.6; $H = 54; $MARGIN = 6;

    // Hintergrund schwarz
    $pdf->SetFillColor(0, 0, 0);
    $pdf->Rect(0, 0, $W, $H, 'F');

    // Helvetica = Core-Font (Arial), keine externen Fontdateien nötig.
    // FPDF-Core-Fonts sind Latin-1: Umlaute via utf8->latin1 absichern.
    $enc = function (string $s): string {
        // mb_convert_encoding gibt es i. d. R.; sonst iconv-Fallback
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        }
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;
    };

    // Titel: CARTE DE MEMBRE (gleichmäßig gesperrt über die Breite)
    $title = 'CARTE DE MEMBRE';
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(255, 255, 255);
    $chars = str_split($title); // reines ASCII
    $avail = $W - 2 * $MARGIN;
    $rawW  = $pdf->GetStringWidth($title);
    $gaps  = max(count($chars) - 1, 1);
    $extra = ($avail - $rawW) / $gaps;
    $x = $MARGIN; $ty = 5.5;
    foreach ($chars as $ch) {
        $pdf->Text($x, $ty, $ch);
        $x += $pdf->GetStringWidth($ch) + $extra;
    }

    // Trennlinie unter dem Titel
    $pdf->SetDrawColor(42, 42, 42);
    $pdf->SetLineWidth(0.3);
    $pdf->Line($MARGIN, 9, $W - $MARGIN, 9);

    // Logo (Held, mittig mit Luft)
    if (is_file(CARD_LOGO)) {
        // Echtes Format anhand des Inhalts erkennen (Endung kann täuschen:
        // eine als .png gespeicherte JPEG-Datei würde FPDF sonst ablehnen).
        $type = '';
        $info = @getimagesize(CARD_LOGO);
        if ($info !== false) {
            switch ($info[2]) {
                case IMAGETYPE_PNG:  $type = 'PNG';  break;
                case IMAGETYPE_JPEG: $type = 'JPEG'; break;
                case IMAGETYPE_GIF:  $type = 'GIF';  break;
            }
        }
        if ($type !== '') {
            $logoW = 46; $logoH = 23;            // 2:1
            $logoX = ($W - $logoW) / 2;
            $logoY = 13;
            try {
                $pdf->Image(CARD_LOGO, $logoX, $logoY, $logoW, $logoH, $type);
            } catch (\Throwable $e) {
                // Logo überspringen statt die ganze Karte scheitern zu lassen
            }
        }
    }

    // QR-Code unten rechts (weißer Rahmen)
    $qrSize = 15;
    $qrX = $W - $qrSize - $MARGIN;
    $qrY = $H - $qrSize - $MARGIN;
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($qrX - 0.7, $qrY - 0.7, $qrSize + 1.4, $qrSize + 1.4, 'F');
    $pdf->Image($qrTmp, $qrX, $qrY, $qrSize, $qrSize, 'PNG');

    // Mitgliedsnummer (Eyebrow, gelb) + kleine WoltLab-ID daneben
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetTextColor(242, 183, 5);
    $numLabel = 'No ' . $m['memberNumber'];
    $pdf->Text($MARGIN, $H - 16, $enc($numLabel));
    // WoltLab-ID sehr klein, grau, mit etwas Abstand rechts daneben
    $numW = $pdf->GetStringWidth($numLabel);
    $pdf->SetFont('Helvetica', '', 4);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Text($MARGIN + $numW + 3, $H - 16, $enc('ID ' . $m['userID']));

    // Name
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Text($MARGIN, $H - 11, $enc($m['displayName']));

    // Gültigkeit (aus Beiträgen), dezent über der Fußzeile.
    // Gültig nur, wenn Konto aktiv UND Deckung noch nicht abgelaufen.
    $vu = (int)($m['validUntil'] ?? 0);
    $gueltig = !empty($m['active']) && $vu > time();
    if ($gueltig) {
        $pdf->SetFont('Helvetica', '', 5);
        $pdf->SetTextColor(180, 180, 180);
        $pdf->Text($MARGIN, $H - 7, $enc('Gültig bis ' . date('d.m.Y', $vu)));
    } else {
        // Abgelaufen / kein aktueller Beitrag -> deutlich in Rot
        $pdf->SetFont('Helvetica', 'B', 5.5);
        $pdf->SetTextColor(200, 40, 40);
        $label = ($vu > 0)
            ? ('ABGELAUFEN seit ' . date('d.m.Y', $vu))
            : 'KEIN AKTUELLER BEITRAG';
        $pdf->Text($MARGIN, $H - 7, $enc($label));
    }

    // Fußzeile: rechtliche Angabe, dezent
    $pdf->SetFont('Helvetica', '', 4.5);
    $pdf->SetTextColor(138, 138, 138);
    $pdf->Text($MARGIN, $H - 4, $enc('AFOL.lu a.s.b.l.  -  RCS F14202'));

    @unlink($qrTmp);

    $filename = 'AFOL-Mitgliedskarte-' . $m['memberNumber'] . '.pdf';
    $pdf->Output('I', $filename); // FPDF 1.8+: (dest, name); I = inline
    exit;
}

// ---------------------------------------------------------------------------
// Verifizierungsseite (Ziel des QR-Codes)
// ---------------------------------------------------------------------------

function card_render_verify(string $token): void {
    header('Content-Type: text/html; charset=utf-8');

    $result = card_verify_token($token);
    $state = 'invalid';          // 'valid' | 'invalid' | 'warn'
    $reason = '';
    $member = null;
    $validUntilStr = '';

    if ($result === false) {
        $reason = 'Ungültige oder abgelaufene Signatur.';
    } else {
        $member = card_load_member($result['userID']);
        if ($member === null) {
            $reason = 'Mitglied nicht gefunden.';
        } elseif (!$member['active']) {
            $reason = 'Mitgliedschaft gesperrt.';
        } else {
            // Gültigkeit LIVE aus den Beiträgen (validUntil), nicht nur aus Token.
            $vu = (int)($member['validUntil'] ?? 0);
            if ($vu === 0) {
                // Kein Beitrag erfasst -> nur warnen (nicht hart ablehnen).
                $state = 'warn';
                $reason = 'Kein Beitrag erfasst – bitte beim Vorstand prüfen.';
            } elseif ($vu < time()) {
                $state = 'invalid';
                $reason = 'Beitrag nicht aktuell – Mitgliedschaft abgelaufen.';
                $validUntilStr = date('d.m.Y', $vu);
            } else {
                $state = 'valid';
                $validUntilStr = date('d.m.Y', $vu);
            }
        }
    }

    $colors = ['valid' => '#1a7f37', 'invalid' => '#b42318', 'warn' => '#b8860b'];
    $labels = ['valid' => 'GÜLTIG', 'invalid' => 'UNGÜLTIG', 'warn' => 'PRÜFEN'];
    $bg   = $colors[$state];
    $text = $labels[$state];

    if ($state === 'valid' || $state === 'warn') {
        $sub = htmlspecialchars($member['displayName'] . ' · No ' . $member['memberNumber'], ENT_QUOTES);
    } else {
        $sub = htmlspecialchars($reason, ENT_QUOTES);
    }

    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>AFOL.lu – Verifizierung</title>';
    echo '<style>body{margin:0;font-family:system-ui,Arial,sans-serif;'
       . 'background:' . $bg . ';color:#fff;display:flex;min-height:100vh;'
       . 'align-items:center;justify-content:center;text-align:center}'
       . '.card{padding:2rem}.big{font-size:3rem;font-weight:800;letter-spacing:.05em}'
       . '.sub{margin-top:1rem;font-size:1.1rem;opacity:.95}'
       . '.until{margin-top:.6rem;font-size:1rem;opacity:.9}'
       . '.note{margin-top:.6rem;font-size:.95rem;opacity:.9}'
       . '.tag{margin-top:2rem;font-size:.8rem;opacity:.7}</style></head><body>';
    echo '<div class="card"><div class="big">' . $text . '</div>';
    echo '<div class="sub">' . $sub . '</div>';
    if ($validUntilStr !== '' && $state === 'valid') {
        echo '<div class="until">Gültig bis ' . $validUntilStr . '</div>';
    }
    if ($state === 'warn') {
        echo '<div class="note">' . htmlspecialchars($reason, ENT_QUOTES) . '</div>';
    }
    echo '<div class="tag">AFOL.lu a.s.b.l. · RCS F14202</div></div></body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// Web-Karte fürs Handy (ohne Google-Konto / ohne Wallet-API)
// ---------------------------------------------------------------------------

function card_render_wallet(array $m): void {
    header('Content-Type: text/html; charset=utf-8');

    // QR-Code als eingebettetes Bild (data-URI), kein temporäres File nötig
    $verifyUrl = CARD_BASE_URL . '?page=verify&t=' . urlencode(card_make_token($m['userID'], (int)($m['validUntil'] ?? 0)));
    $qrDataUri = '';
    try {
        $qrPng = card_qr_png($verifyUrl);
        $qrDataUri = 'data:image/png;base64,' . base64_encode($qrPng);
    } catch (\Throwable $e) {
        $qrDataUri = '';
    }

    // Logo als data-URI (falls vorhanden)
    $logoDataUri = '';
    if (is_file(CARD_LOGO)) {
        $info = @getimagesize(CARD_LOGO);
        if ($info !== false) {
            $logoDataUri = 'data:' . $info['mime'] . ';base64,'
                         . base64_encode(file_get_contents(CARD_LOGO));
        }
    }

    $name = htmlspecialchars($m['displayName'], ENT_QUOTES);
    $num  = htmlspecialchars($m['memberNumber'], ENT_QUOTES);

    $html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
      . '<meta name="theme-color" content="#000000">'
      . '<title>AFOL.lu Mitgliedskarte</title>'
      . '<style>'
      . '*{margin:0;padding:0;box-sizing:border-box}'
      . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;'
      . 'background:#0d0d0f;color:#fff;min-height:100vh;display:flex;flex-direction:column;'
      . 'align-items:center;justify-content:center;padding:20px;gap:18px}'
      . '.card{width:100%;max-width:420px;aspect-ratio:85.6/54;background:#000;border-radius:16px;'
      . 'box-shadow:0 10px 40px rgba(0,0,0,.6);position:relative;overflow:hidden;'
      . 'padding:5% 6%;display:flex;flex-direction:column}'
      . '.title{color:#fff;font-weight:700;font-size:clamp(11px,3.4vw,16px);'
      . 'letter-spacing:.32em;text-align:center;padding-left:.32em}'
      . '.rule{height:1px;background:#2a2a2a;margin:4% 0}'
      . '.logo{flex:1;display:flex;align-items:center;justify-content:center;min-height:0;padding:2% 0}'
      . '.logo img{max-width:88%;max-height:100%;object-fit:contain}'
      . '.bottom{display:flex;justify-content:space-between;align-items:flex-end;gap:8px}'
      . '.info .no{color:#f2b705;font-weight:700;font-size:clamp(10px,2.8vw,13px)}'
      . '.info .uid{color:#8a8a8a;font-weight:400;font-size:clamp(6px,1.6vw,8px);margin-left:8px;letter-spacing:.02em}'
      . '.info .nm{color:#fff;font-weight:700;font-size:clamp(14px,4.2vw,19px);margin-top:2px}'
      . '.qr{background:#fff;border-radius:6px;padding:4px;width:23%;max-width:84px;flex-shrink:0}'
      . '.qr img{width:100%;display:block}'
      . '.foot{color:#8a8a8a;font-size:9px;margin-top:5%}'
      . '.hint{max-width:420px;color:#aeb4bb;font-size:13px;text-align:center;line-height:1.5}'
      . '.hint b{color:#fff}'
      . '.actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}'
      . '.btn{background:#1f6fb2;color:#fff;text-decoration:none;font-weight:600;font-size:14px;'
      . 'padding:11px 18px;border-radius:10px;border:none;cursor:pointer}'
      . '.btn.alt{background:#2a2d31}'
      . '@media print{'
      . '  *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important}'
      . '  @page{size:auto;margin:10mm}'
      . '  body{background:#fff !important;display:block;padding:0;gap:0}'
      . '  .hint,.actions{display:none !important}'
      . '  .card{width:85.6mm !important;height:54mm !important;max-width:none !important;'
      . '    aspect-ratio:auto !important;border-radius:3mm !important;box-shadow:none !important;'
      . '    background:#000 !important;margin:0 auto;padding:4mm 5mm !important;page-break-inside:avoid}'
      . '  .title{font-size:9pt !important;white-space:nowrap !important;letter-spacing:.28em !important}'
      . '  .rule{margin:2mm 0 !important}'
      . '  .logo{flex:0 0 auto !important;height:20mm !important;padding:0 !important}'
      . '  .logo img{max-width:60mm !important;max-height:20mm !important}'
      . '  .bottom{margin-top:auto !important}'
      . '  .info .nm{font-size:13pt !important}'
      . '  .info .no{font-size:9pt !important}'
      . '  .info .uid{font-size:5pt !important;margin-left:3mm !important}'
      . '  .qr{width:15mm !important;max-width:15mm !important;padding:1mm !important}'
      . '  .foot{font-size:6pt !important;margin-top:2mm !important}'
      . '}'
      . '</style></head><body>';

    // Gültigkeit für die Anzeige (gleiche Regel wie PDF/Verify)
    $wVu = (int)($m['validUntil'] ?? 0);
    $wGueltig = !empty($m['active']) && $wVu > time();
    $wBadge = $wGueltig
        ? '<span style="color:#7bd88f">Gültig bis ' . date('d.m.Y', $wVu) . '</span>'
        : '<span style="color:#ff6b6b;font-weight:700">'
          . ($wVu > 0 ? 'Abgelaufen seit ' . date('d.m.Y', $wVu) : 'Kein aktueller Beitrag')
          . '</span>';

    // Karte
    $html .= '<div class="card" id="card">'
      . '<div class="title">CARTE DE MEMBRE</div>'
      . '<div class="rule"></div>'
      . '<div class="logo">'
      . ($logoDataUri ? '<img src="' . $logoDataUri . '" alt="AFOL.lu">' : '')
      . '</div>'
      . '<div class="bottom">'
      . '<div class="info"><div class="no">No ' . $num
      . '<span class="uid">ID ' . (int)$m['userID'] . '</span></div>'
      . '<div class="nm">' . $name . '</div></div>'
      . ($qrDataUri ? '<div class="qr"><img src="' . $qrDataUri . '" alt="QR"></div>' : '')
      . '</div>'
      . '<div class="foot">' . $wBadge . '  ·  AFOL.lu a.s.b.l.</div>'
      . '</div>';

    // Hinweistext + Aktionen
    $html .= '<div class="hint">Lege diese Karte auf dem Startbildschirm ab: '
      . 'Im Browser-Menü <b>„Zum Startbildschirm hinzufügen“</b> wählen. '
      . 'So hast du sie jederzeit griffbereit.</div>'
      . '<div class="actions">'
      . '<button class="btn" onclick="window.print()">Als PDF / drucken</button>'
      . '<a class="btn alt" href="' . htmlspecialchars(CARD_BASE_URL, ENT_QUOTES)
      . '?page=card&amp;userID=' . (int) $m['userID'] . '">PDF-Version</a>'
      . '</div>';

    // Web-App-Verhalten beim Ablegen auf dem Startbildschirm
    $html .= '<script>'
      . 'var l=document.createElement("link");l.rel="manifest";'
      . 'var mf={name:"AFOL.lu Mitgliedskarte",short_name:"AFOL.lu",display:"standalone",'
      . 'background_color:"#0d0d0f",theme_color:"#000000",start_url:location.href};'
      . 'l.href="data:application/manifest+json,"+encodeURIComponent(JSON.stringify(mf));'
      . 'document.head.appendChild(l);'
      . '</script>';

    $html .= '</body></html>';
    echo $html;
    exit;
}

// ---------------------------------------------------------------------------
// Landingpage: Auswahl PDF-Karte / Handy-Karte (AFOL.lu / CoLab-Design)
// ---------------------------------------------------------------------------

function card_render_home(array $m): void {
    header('Content-Type: text/html; charset=utf-8');

    $base = htmlspecialchars(CARD_BASE_URL, ENT_QUOTES);
    $name = htmlspecialchars($m['displayName'], ENT_QUOTES);
    // Ziel-userID an die Auswahl-Buttons weiterreichen (sonst eigene Karte)
    $uidParam = '&amp;userID=' . (int) $m['userID'];

    // Logo als data-URI
    $logoDataUri = '';
    if (is_file(CARD_LOGO)) {
        $info = @getimagesize(CARD_LOGO);
        if ($info !== false) {
            $logoDataUri = 'data:' . $info['mime'] . ';base64,'
                         . base64_encode(file_get_contents(CARD_LOGO));
        }
    }

    $css = ':root{--ink:#1c2833;--red:#d01012;--yellow:#ffcf00;--blue:#006cb7;'
      . '--green:#237841;--gold:#c8a951;--bg:#f5f6f7;--border:#e1e1e1;--text:#2c2e30}'
      . '*{margin:0;padding:0;box-sizing:border-box}'
      . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;'
      . 'background:var(--bg);color:var(--text);line-height:1.6;font-size:15px}'
      . '.page{max-width:1000px;margin:0 auto;padding:0 16px 60px}'
      . '.langbar{display:flex;justify-content:flex-end;gap:6px;margin-top:16px;flex-wrap:wrap}'
      . '.langbar button{border:1px solid var(--border);background:#fff;color:#44484c;cursor:pointer;'
      . 'font-size:13px;font-weight:600;padding:7px 12px;border-radius:6px;transition:all .12s}'
      . '.langbar button:hover{border-color:var(--blue);color:var(--blue)}'
      . '.langbar button.active{background:var(--blue);color:#fff;border-color:var(--blue)}'
      . '.hero{position:relative;margin-top:12px;border-radius:8px;overflow:hidden;'
      . 'background:linear-gradient(135deg,#1c2833 0%,#224a6e 55%,#006cb7 100%);color:#fff;padding:40px}'
      . '.hero::after{content:"";position:absolute;top:0;right:0;bottom:0;width:6px;'
      . 'background:linear-gradient(180deg,var(--red),var(--yellow),var(--blue),var(--green))}'
      . '.hero .eyebrow{display:inline-block;font-size:12px;letter-spacing:1.5px;text-transform:uppercase;'
      . 'font-weight:700;color:var(--yellow);margin-bottom:12px}'
      . '.hero h1{font-size:30px;line-height:1.2;margin-bottom:12px}'
      . '.hero p{font-size:16px;max-width:640px;color:#e8eef3}'
      . '.box{background:#fff;border:1px solid var(--border);border-radius:8px;padding:26px 30px;margin-top:24px}'
      . '.box h2{font-size:21px;color:#1c1d1f;margin-bottom:14px;padding-bottom:10px;'
      . 'border-bottom:2px solid var(--gold);display:inline-block}'
      . '.box p{margin-bottom:12px}'
      . '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-top:6px}'
      . '.opt{border:1px solid var(--border);border-top:4px solid var(--blue);border-radius:10px;'
      . 'padding:24px;display:flex;flex-direction:column;transition:box-shadow .15s,transform .15s;background:#fff}'
      . '.opt:hover{box-shadow:0 8px 22px rgba(0,0,0,.09);transform:translateY(-2px)}'
      . '.opt.pdf{border-top-color:var(--red)}'
      . '.opt .ico{width:50px;height:50px;border-radius:12px;display:flex;align-items:center;'
      . 'justify-content:center;font-size:26px;color:#fff;background:var(--blue);margin-bottom:14px}'
      . '.opt.pdf .ico{background:var(--red)}'
      . '.opt h3{font-size:19px;color:#1c1d1f;margin-bottom:6px}'
      . '.opt .tag{font-size:12px;text-transform:uppercase;letter-spacing:.8px;font-weight:700;'
      . 'color:var(--blue);margin-bottom:12px}'
      . '.opt.pdf .tag{color:var(--red)}'
      . '.opt ul{list-style:none;margin:0 0 18px;padding:0}'
      . '.opt li{position:relative;padding-left:24px;margin-bottom:8px;font-size:14px;color:#4a4e52}'
      . '.opt li::before{content:"\\2713";position:absolute;left:0;color:var(--green);font-weight:700}'
      . '.opt .btn{margin-top:auto;display:block;text-align:center;background:var(--blue);color:#fff;'
      . 'text-decoration:none;font-weight:700;padding:13px 20px;border-radius:8px;transition:background .15s}'
      . '.opt .btn:hover{background:#005a99}'
      . '.opt.pdf .btn{background:var(--red)}.opt.pdf .btn:hover{background:#a50d0f}'
      . '.steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-top:10px;counter-reset:s}'
      . '.stepc{counter-increment:s;position:relative;padding:16px;border:1px solid var(--border);'
      . 'border-radius:8px;background:#fafbfc;font-size:14px;color:#4a4e52}'
      . '.stepc::before{content:counter(s);display:inline-flex;align-items:center;justify-content:center;'
      . 'width:28px;height:28px;border-radius:50%;background:var(--blue);color:#fff;font-weight:700;margin-bottom:8px}'
      . '.disclaimer{margin-top:26px;font-size:12.5px;color:#7a7e82;line-height:1.5;'
      . 'border-top:1px solid var(--border);padding-top:16px}'
      . '@media(max-width:600px){.hero{padding:28px 22px}.hero h1{font-size:24px}.box{padding:22px 20px}}';

    $h = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<title>AFOL.lu Mitgliedskarte</title><style>' . $css . '</style></head><body><div class="page">';

    // Sprachumschalter
    $h .= '<div class="langbar">'
      . '<button data-lang="fr" onclick="setLang(\'fr\')">Français</button>'
      . '<button data-lang="de" onclick="setLang(\'de\')">Deutsch</button>'
      . '<button data-lang="en" onclick="setLang(\'en\')">English</button>'
      . '<button data-lang="lb" onclick="setLang(\'lb\')">Lëtzebuergesch</button>'
      . '</div>';

    // Hero
    $h .= '<header class="hero"><span class="eyebrow" data-i="eyebrow"></span>'
      . '<h1 data-i="h1"></h1>'
      . '<p><span data-i="hello"></span> ' . $name . '! <span data-i="intro"></span></p></header>';

    // Auswahl
    $h .= '<section class="box"><h2 data-i="choose"></h2><div class="grid">';
    // Handy
    $h .= '<div class="opt"><span class="ico">📱</span>'
      . '<div class="tag" data-i="w_tag"></div>'
      . '<h3 data-i="w_title"></h3>'
      . '<ul><li data-i="w1"></li><li data-i="w2"></li><li data-i="w3"></li><li data-i="w4"></li></ul>'
      . '<a class="btn" href="' . $base . '?page=wallet' . $uidParam . '" data-i="w_btn"></a></div>';
    // PDF
    $h .= '<div class="opt pdf"><span class="ico">🖨️</span>'
      . '<div class="tag" data-i="p_tag"></div>'
      . '<h3 data-i="p_title"></h3>'
      . '<ul><li data-i="p1"></li><li data-i="p2"></li><li data-i="p3"></li><li data-i="p4"></li></ul>'
      . '<a class="btn" href="' . $base . '?page=card' . $uidParam . '" data-i="p_btn"></a></div>';
    $h .= '</div></section>';

    // So geht es
    $h .= '<section class="box"><h2 data-i="how"></h2><div class="steps">'
      . '<div class="stepc" data-i="s1"></div>'
      . '<div class="stepc" data-i="s2"></div>'
      . '<div class="stepc" data-i="s3"></div>'
      . '</div></section>';

    // QR
    $h .= '<section class="box"><h2 data-i="qr_h"></h2><p data-i="qr_p"></p></section>';

    // Footer
    $h .= '<div class="disclaimer"><p data-i="foot1"></p><p data-i="foot2"></p></div>';

    // Übersetzungen + Logik
    $h .= '<script>'
      . 'var T={'
      . 'fr:{eyebrow:"Espace membre",h1:"Ta carte de membre AFOL.lu",hello:"Bonjour",'
      . 'intro:"voici ta carte de membre personnelle. Choisis simplement si tu veux l\'imprimer ou l\'utiliser sur ton téléphone.",'
      . 'choose:"Quelle version souhaites-tu ?",'
      . 'w_tag:"Pour smartphone",w_title:"Carte pour téléphone",'
      . 'w1:"Visible immédiatement à l\'écran",w2:"À ajouter à l\'écran d\'accueil",w3:"Toujours sur toi, sans impression",w4:"Avec QR code de vérification",w_btn:"Ouvrir la carte mobile",'
      . 'p_tag:"À imprimer",p_title:"Carte en PDF",'
      . 'p1:"Au vrai format carte bancaire",p2:"À imprimer et découper",p3:"À enregistrer comme fichier",p4:"Avec QR code de vérification",p_btn:"Créer le PDF",'
      . 'how:"Comment faire",s1:"Choisis une version ci-dessus.",s2:"La carte est générée automatiquement avec ton nom.",s3:"Enregistre-la sur ton téléphone ou imprime-la \u2013 c\'est fait.",'
      . 'qr_h:"À quoi sert le QR code ?",qr_p:"Les deux versions portent un QR code. Scanné, il indique si la carte est authentique et si ton adhésion est active. Lors d\'événements, on peut ainsi vérifier facilement qui est membre actuel \u2013 une simple copie ne suffit pas.",'
      . 'foot1:"AFOL.lu a.s.b.l. \u00b7 RCS F14202 \u00b7 Cartes de membre numériques, développées par Gilbert Franzetti, 2026.",'
      . 'foot2:"LEGO\u00ae et le logo LEGO sont des marques du groupe LEGO, qui ne parraine ni ne soutient cette page. AFOL.lu a.s.b.l. est une communauté de fans indépendante."},'
      . 'de:{eyebrow:"Mitgliederbereich",h1:"Deine AFOL.lu Mitgliedskarte",hello:"Hallo",'
      . 'intro:"hier bekommst du deine persönliche Mitgliedskarte. Wähle einfach, ob du sie ausdrucken oder auf dem Handy nutzen möchtest.",'
      . 'choose:"Welche Variante möchtest du?",'
      . 'w_tag:"Fürs Smartphone",w_title:"Karte fürs Handy",'
      . 'w1:"Sofort am Bildschirm sichtbar",w2:"Auf dem Startbildschirm ablegbar",w3:"Immer dabei, kein Ausdruck nötig",w4:"Mit QR-Code zur Echtheitsprüfung",w_btn:"Handy-Karte öffnen",'
      . 'p_tag:"Zum Ausdrucken",p_title:"Karte als PDF",'
      . 'p1:"Im echten Scheckkartenformat",p2:"Zum Ausdrucken & Ausschneiden",p3:"Als Datei speichern und aufbewahren",p4:"Mit QR-Code zur Echtheitsprüfung",p_btn:"PDF erstellen",'
      . 'how:"So einfach geht es",s1:"Variante oben auswählen.",s2:"Karte wird automatisch mit deinem Namen erzeugt.",s3:"Auf dem Handy speichern oder ausdrucken \u2013 fertig.",'
      . 'qr_h:"Wofür der QR-Code?",qr_p:"Auf beiden Varianten ist ein QR-Code aufgedruckt. Wird er gescannt, zeigt er an, ob die Karte echt und deine Mitgliedschaft aktiv ist. So lässt sich bei Veranstaltungen unkompliziert prüfen, wer aktuelles Mitglied ist \u2013 eine einfache Kopie reicht dafür nicht aus.",'
      . 'foot1:"AFOL.lu a.s.b.l. \u00b7 RCS F14202 \u00b7 Digitale Mitgliedskarten, entwickelt von Gilbert Franzetti, 2026.",'
      . 'foot2:"LEGO\u00ae und das LEGO-Logo sind Marken der LEGO Group, die diese Seite weder sponsert noch unterstützt. AFOL.lu a.s.b.l. ist eine unabhängige Fan-Community."},'
      . 'en:{eyebrow:"Members area",h1:"Your AFOL.lu membership card",hello:"Hello",'
      . 'intro:"here is your personal membership card. Just choose whether to print it or use it on your phone.",'
      . 'choose:"Which version would you like?",'
      . 'w_tag:"For smartphone",w_title:"Card for your phone",'
      . 'w1:"Visible on screen right away",w2:"Can be added to your home screen",w3:"Always with you, no printing needed",w4:"With QR code for verification",w_btn:"Open phone card",'
      . 'p_tag:"For printing",p_title:"Card as PDF",'
      . 'p1:"In true credit-card format",p2:"To print and cut out",p3:"Save and keep as a file",p4:"With QR code for verification",p_btn:"Create PDF",'
      . 'how:"How it works",s1:"Choose a version above.",s2:"The card is generated automatically with your name.",s3:"Save it on your phone or print it \u2013 done.",'
      . 'qr_h:"What is the QR code for?",qr_p:"Both versions carry a QR code. When scanned, it shows whether the card is genuine and your membership is active. At events this makes it easy to check who is a current member \u2013 a simple copy is not enough.",'
      . 'foot1:"AFOL.lu a.s.b.l. \u00b7 RCS F14202 \u00b7 Digital membership cards, developed by Gilbert Franzetti, 2026.",'
      . 'foot2:"LEGO\u00ae and the LEGO logo are trademarks of the LEGO Group, which does not sponsor or endorse this page. AFOL.lu a.s.b.l. is an independent fan community."},'
      . 'lb:{eyebrow:"Membersberäich",h1:"Deng AFOL.lu Memberskaart",hello:"Moien",'
      . 'intro:"hei kriss du deng perséinlech Memberskaart. Wiel einfach, ob du se ausdrécke oder um Handy benotze wëlls.",'
      . 'choose:"Wéi eng Variant hätts du gär?",'
      . 'w_tag:"Firt Handy",w_title:"Kaart firt Handy",'
      . 'w1:"Direkt um Ecran ze gesinn",w2:"Kann um Startbildschirm ofgeluecht ginn",w3:"Ëmmer derbäi, keen Ausdrock néideg",w4:"Mat QR-Code fir d\'Echtheetspréiwung",w_btn:"Handy-Kaart opmaachen",'
      . 'p_tag:"Fir auszedrécken",p_title:"Kaart als PDF",'
      . 'p1:"Am richtege Scheckkaartenformat",p2:"Fir auszedrécken an auszeschneiden",p3:"Als Datei späicheren an opbewaren",p4:"Mat QR-Code fir d\'Echtheetspréiwung",p_btn:"PDF erstellen",'
      . 'how:"Esou einfach geet et",s1:"Variant uewen auswielen.",s2:"D\'Kaart gëtt automatesch mat dengem Numm erstallt.",s3:"Um Handy späicheren oder ausdrécken \u2013 fäerdeg.",'
      . 'qr_h:"Wofir den QR-Code?",qr_p:"Op béide Varianten ass en QR-Code. Gëtt en gescannt, weist en, ob d\'Kaart echt ass an deng Memberschaft aktiv ass. Esou kann een op Evenementer einfach préiwen, wien aktuelle Member ass \u2013 eng einfach Kopie duer net.",'
      . 'foot1:"AFOL.lu a.s.b.l. \u00b7 RCS F14202 \u00b7 Digital Memberskaarten, entwéckelt vum Gilbert Franzetti, 2026.",'
      . 'foot2:"LEGO\u00ae an de LEGO-Logo si Maarken vun der LEGO Group, déi dës Säit weder sponsert nach ënnerstëtzt. AFOL.lu a.s.b.l. ass eng onofhängeg Fan-Community."}'
      . '};'
      . 'function setLang(l){var d=T[l];if(!d)return;'
      . 'document.querySelectorAll("[data-i]").forEach(function(e){var k=e.getAttribute("data-i");if(d[k]!=null)e.textContent=d[k];});'
      . 'document.documentElement.lang=l;'
      . 'document.querySelectorAll(".langbar button").forEach(function(b){b.classList.toggle("active",b.getAttribute("data-lang")===l);});'
      . 'try{localStorage.setItem("afol_lang",l);}catch(e){}}'
      . 'var saved=null;try{saved=localStorage.getItem("afol_lang");}catch(e){}'
      . 'var nav=(navigator.language||"de").slice(0,2).toLowerCase();'
      . 'var start=saved||(T[nav]?nav:"de");'
      . 'setLang(start);'
      . '</script>';

    $h .= '</div></body></html>';
    echo $h;
    exit;
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

$page = $_GET['page'] ?? 'card';

if ($page === 'qrtest') {
    // Diagnose: zeigt, ob die QR-Erzeugung funktioniert
    header('Content-Type: text/plain; charset=utf-8');
    echo "DIAGNOSE-VERSION: 2026-06-20-quellen\n";
    echo "Empfangene GET-Parameter: " . json_encode($_GET) . "\n";
    // Welche userID würde die Routing-Logik wählen?
    $dbgTarget = (int)WCF::getUser()->userID;
    foreach (['userID', 'userId', 'userid', 'UserID'] as $k) {
        if (isset($_GET[$k])) {
            echo "  -> Parameter '$k' = " . $_GET[$k] . " erkannt\n";
            $dbgTarget = (int)$_GET[$k];
            break;
        }
    }
    echo "  -> Ziel-userID nach Logik: " . $dbgTarget . "\n";
    echo "  -> Darf fremde Karten erzeugen: "
       . (card_user_may_manage() ? 'JA' : 'NEIN') . "\n";
    // Erlaubte Gruppen auflösen
    try {
        $gn = array_filter(array_map('trim', explode(',', CARD_ADMIN_GROUPS)));
        if ($gn) {
            $place = implode(',', array_fill(0, count($gn), '?'));
            $gst = WCF::getDB()->prepareStatement(
                "SELECT groupID, groupName FROM wcf" . WCF_N . "_user_group WHERE groupName IN (" . $place . ")"
            );
            $gst->execute(array_values($gn));
            $found = [];
            while ($gr = $gst->fetchArray()) { $found[] = $gr['groupName'] . '=' . $gr['groupID']; }
            echo "  -> Gruppen '" . CARD_ADMIN_GROUPS . "' gefunden: "
               . ($found ? implode(', ', $found) : '(KEINE - Name prüfen!)') . "\n";
            echo "  -> Eigene Gruppen-IDs: " . implode(',', WCF::getUser()->getGroupIDs()) . "\n";
        }
    } catch (\Throwable $e) {
        echo "  -> Gruppen-Check Fehler: " . $e->getMessage() . "\n";
    }
    echo "\n";
    echo "WCF_DIR: " . WCF_DIR . "\n";
    foreach ([
        'lib/system/api/php-qrcode/vendor/autoload.php',
        'lib/system/api/php-qrcode/autoload.php',
        'lib/system/api/autoload.php',
    ] as $p) {
        echo (is_file(WCF_DIR . $p) ? '[gefunden] ' : '[fehlt]    ') . $p . "\n";
    }
    try {
        $png = card_qr_png('https://afol.lu/');
        echo "QR-Erzeugung OK, " . strlen($png) . " Bytes PNG.\n";
        echo "chillerlan geladen: " . (class_exists('\chillerlan\QRCode\QRCode') ? 'ja' : 'nein') . "\n";
    } catch (\Throwable $e) {
        echo "QR-FEHLER: " . $e->getMessage() . "\n";
    }
    echo "\n-- FPDF --\n";
    foreach ([
        'fpdf.php', 'lib/fpdf.php', 'fpdf/fpdf.php',
    ] as $p) {
        echo (is_file(__DIR__ . '/' . $p) ? '[gefunden] ' : '[fehlt]    ') . $p . "\n";
    }
    try {
        card_load_fpdf();
        echo "FPDF geladen: " . (class_exists('\FPDF') ? 'ja' : 'nein') . "\n";
    } catch (\Throwable $e) {
        echo "FPDF-FEHLER: " . $e->getMessage() . "\n";
    }
    echo "\n-- Logo --\n";
    echo (is_file(CARD_LOGO) ? '[gefunden] ' : '[fehlt]    ') . CARD_LOGO . "\n";

    echo "\n-- gf_membres / Mitgliedsnummer --\n";
    // Diagnose für die ANGEFRAGTE userID (sofern Berechtigung), sonst eigene.
    $ownUid = (int)WCF::getUser()->userID;
    $uid = card_user_may_manage() ? $dbgTarget : $ownUid;
    echo "Eingeloggte userID: " . ($ownUid ?: '(nicht eingeloggt)') . "\n";
    echo "Geprüfte userID:    " . ($uid ?: '(keine)')
       . ($uid !== $ownUid ? "  (angefragte fremde userID)" : "  (eigene)") . "\n";
    if ($uid) {
        // WoltLab-E-Mail holen
        $est = WCF::getDB()->prepareStatement(
            "SELECT email FROM wcf" . WCF_N . "_user WHERE userID = ?"
        );
        $est->execute([$uid]);
        $erow = $est->fetchArray();
        $wmail = $erow ? trim((string)$erow['email']) : '';
        echo "WoltLab-E-Mail:     " . ($wmail !== '' ? $wmail : '(leer)') . "\n";
        echo "Tabelle/Spalten:    " . CARD_MEMBERS_TABLE . " ("
           . CARD_MEMBERS_TYPECOL . ", " . CARD_MEMBERS_NUMCOL . ", "
           . CARD_MEMBERS_MAILCOL . ")\n";

        // 1) Existiert die Tabelle / sind die Spalten lesbar?
        try {
            $cst = WCF::getDB()->prepareStatement(
                "SELECT `" . CARD_MEMBERS_TYPECOL . "` AS t, `" . CARD_MEMBERS_NUMCOL . "` AS n, "
                . "`" . CARD_MEMBERS_MAILCOL . "` AS e FROM `" . CARD_MEMBERS_TABLE . "` LIMIT 1"
            );
            $cst->execute();
            $cst->fetchArray();
            echo "Tabellenzugriff:    OK\n";
        } catch (\Throwable $e) {
            echo "Tabellenzugriff:    FEHLER\n";
            // echten MySQL-Fehler zeigen (PDO-Vorheriger Exception)
            $prev = $e->getPrevious();
            if ($prev) echo "  MySQL: " . $prev->getMessage() . "\n";

            // Welche Tabellen heißen ähnlich? (mit/ohne WoltLab-Präfix)
            try {
                $like = WCF::getDB()->prepareStatement("SHOW TABLES LIKE '%membres%'");
                $like->execute();
                $found = [];
                while ($r = $like->fetchArray()) { $found[] = reset($r); }
                echo "  Tabellen mit 'membres': "
                   . ($found ? implode(', ', $found) : '(keine gefunden)') . "\n";

                // Auch nach 'gf_' suchen
                $like2 = WCF::getDB()->prepareStatement("SHOW TABLES LIKE '%gf%'");
                $like2->execute();
                $found2 = [];
                while ($r = $like2->fetchArray()) { $found2[] = reset($r); }
                echo "  Tabellen mit 'gf':      "
                   . ($found2 ? implode(', ', $found2) : '(keine gefunden)') . "\n";

                // Spalten der ersten Kandidaten-Tabelle zeigen
                $cands = array_unique(array_merge($found, $found2));
                foreach ($cands as $cand) {
                    try {
                        $cols = WCF::getDB()->prepareStatement("SHOW COLUMNS FROM `" . $cand . "`");
                        $cols->execute();
                        $cn = [];
                        while ($r = $cols->fetchArray()) { $cn[] = $r['Field']; }
                        echo "  Spalten in " . $cand . ": " . implode(', ', $cn) . "\n";
                    } catch (\Throwable $e3) { /* ignore */ }
                }
            } catch (\Throwable $e2) {
                echo "  SHOW TABLES Fehler: " . $e2->getMessage() . "\n";
            }
        }

        // 2) Treffer für genau diese E-Mail?
        if ($wmail !== '') {
            try {
                $mst = WCF::getDB()->prepareStatement(
                    "SELECT `" . CARD_MEMBERS_TYPECOL . "` AS memberType, "
                    . "`" . CARD_MEMBERS_NUMCOL . "` AS memberNo "
                    . "FROM `" . CARD_MEMBERS_TABLE . "` WHERE `" . CARD_MEMBERS_MAILCOL . "` = ? LIMIT 1"
                );
                $mst->execute([$wmail]);
                $mr = $mst->fetchArray();
                if ($mr) {
                    echo "Treffer in gf_membres: Membre='" . $mr['memberType']
                       . "', No='" . $mr['memberNo'] . "'\n";
                } else {
                    echo "Treffer in gf_membres: KEINER für diese E-Mail.\n";
                    echo "  (E-Mail in gf_membres weicht evtl. ab, z. B. Groß/Klein oder Tippfehler.)\n";
                }
            } catch (\Throwable $e) {
                echo "Abfrage-Fehler: " . $e->getMessage() . "\n";
            }
        }

        // 3) Was liefert die echte Funktion?
        $mem = card_load_member($uid);
        if ($mem) {
            echo "Ergebnis memberNumber: " . $mem['memberNumber']
               . ($mem['memberNumber'] === (string)$uid ? "  (= userID -> Fallback aktiv!)" : "  (aus gf_membres)") . "\n";
            echo "status-Wert:           '" . ($mem['statusValue'] ?? '') . "'"
               . ($mem['active'] ? "  (aktiv)" : "  (INAKTIV -> Karte ungültig)") . "\n";
            echo "Bezahlte Jahre:        "
               . (!empty($mem['paidYears']) ? implode(', ', $mem['paidYears']) : '(keine)') . "\n";
            echo "Gültig bis:            "
               . ((int)($mem['validUntil'] ?? 0) > 0 ? date('d.m.Y', $mem['validUntil']) : '(kein Beitrag -> Warnung)') . "\n";
            if (!empty($mem['lastPaidDate'])) {
                echo "Letztes Zahldatum:     " . $mem['lastPaidDate'] . "\n";
            }
        }
    }
    exit;
}

if ($page === 'verify') {
    card_render_verify((string)($_GET['t'] ?? ''));
}

if ($page === 'card' || $page === 'wallet' || $page === 'home') {
    $loggedIn = WCF::getUser()->userID;

    // Welche Karte? Standard: eigene. Admins dürfen eine userID übergeben.
    // Parameter tolerant lesen: userID / userId / userid werden akzeptiert.
    $targetID = $loggedIn;
    $reqParam = null;
    foreach (['userID', 'userId', 'userid', 'UserID'] as $k) {
        if (isset($_GET[$k])) { $reqParam = $_GET[$k]; break; }
    }
    if ($reqParam !== null) {
        $requested = (int)$reqParam;
        if ($requested !== (int)$loggedIn) {
            // Admins ODER Mitglieder der erlaubten Gruppe(n) dürfen fremde Karten erzeugen
            if (!card_user_may_manage()) {
                header('HTTP/1.1 403 Forbidden');
                exit('Keine Berechtigung.');
            }
            $targetID = $requested;
        }
    }

    if (!$targetID) {
        header('HTTP/1.1 401 Unauthorized');
        exit('Bitte einloggen, um die Mitgliedskarte zu erzeugen.');
    }

    $member = card_load_member((int)$targetID);
    if ($member === null) {
        header('HTTP/1.1 404 Not Found');
        exit('Mitglied nicht gefunden.');
    }

    if ($page === 'home') {
        card_render_home($member);
    }
    if ($page === 'wallet') {
        card_render_wallet($member);
    }
    card_render_pdf($member);
}

header('HTTP/1.1 400 Bad Request');
exit('Unbekannte Aktion.');

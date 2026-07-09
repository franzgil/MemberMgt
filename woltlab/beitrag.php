<?php
/**
 * AFOL.lu – Jahresbeitrag online mit SumUp bezahlen (4-sprachig)
 * Drop-in Skript für das WoltLab Suite 5.5 Root-Verzeichnis (neben global.php).
 *
 * Ein eingeloggtes Mitglied bezahlt seinen Jahresbeitrag per SumUp:
 *   - Fördermitglied (mitglieder.typ = 'foerder')  ->  15,00 €
 *   - Aktives Mitglied (mitglieder.typ = 'aktiv')   ->  50,00 €
 *
 * Der Betrag wird IMMER serverseitig aus dem Mitgliedstyp abgeleitet
 * (nie aus dem Formular), damit er nicht manipulierbar ist.
 *
 * Zahlung: SumUp „Hosted Checkout" (developer.sumup.com). Das Skript erzeugt
 * per API einen Checkout mit exaktem Betrag und Mitglieds-Referenz und leitet
 * auf die von SumUp gehostete Bezahlseite weiter. Nach der Zahlung kommt der
 * Nutzer auf die Danke-Seite zurück; der Trésorier gleicht die Zahlung wie
 * gewohnt über die SumUp-Übersicht ab.
 *
 * Aufruf:
 *   beitrag.php                       -> Bestätigungsseite (Login nötig)
 *   beitrag.php (POST, action=pay)    -> Checkout erzeugen und weiterleiten
 *   beitrag.php?page=return&c=TOKEN   -> Rückkehr von SumUp (Danke/Status)
 *   beitrag.php?page=test             -> Diagnose (nur Admin)
 */

use wcf\system\WCF;

require_once(__DIR__ . '/global.php');

// ===========================================================================
// KONFIGURATION  –  bitte an den Verein anpassen
// ===========================================================================

// --- Verein / Kontakt -------------------------------------------------------
define('BEITRAG_VEREIN_NAME',   'AFOL.lu a.s.b.l.');
define('BEITRAG_KONTAKT_EMAIL',  'comite@afol.lu');

// --- Beträge (Jahresbeitrag) ------------------------------------------------
// In EUR, als Zahl. Fördermitglied 15, aktives Mitglied 50.
define('BEITRAG_FOERDER',  15.00);
define('BEITRAG_AKTIV',    50.00);
define('BEITRAG_CURRENCY', 'EUR');

// Monat der Generalversammlung – das Beitragsjahr läuft von GV zu GV. Vor
// diesem Monat wird noch das Vorjahr als Beitragsjahr angesetzt. Muss zur
// App-Konstante GV_MONAT / CARD_GV_MONTH passen.
define('BEITRAG_GV_MONAT', 3);

// --- SumUp Hosted Checkout --------------------------------------------------
// Merchant-Code aus dem SumUp-Dashboard (Profil / „Merchant code", Format MCxxxxxx).
// Leer lassen -> es werden nur die statischen Links unten benutzt (falls gesetzt).
define('BEITRAG_SUMUP_MERCHANT', getenv('SUMUP_MERCHANT_CODE') ?: '');
define('BEITRAG_SUMUP_API_BASE', 'https://api.sumup.com');

// Optionaler Fallback: fertige SumUp-Bezahllinks je Typ (aus dem Dashboard).
// Werden verwendet, wenn kein API-Key / Merchant-Code konfiguriert ist.
// Achtung: statische Links haben einen FESTEN Betrag – je Betrag ein Link.
define('BEITRAG_SUMUP_LINK_FOERDER', '');   // z. B. 'https://pay.sumup.com/b2c/XXXXXXXX'
define('BEITRAG_SUMUP_LINK_AKTIV',   '');

// --- URLs / Assets ----------------------------------------------------------
define('BEITRAG_FORUM_URL', rtrim(WCF::getPath(), '/') . '/');
define('BEITRAG_SELF_URL',  rtrim(WCF::getPath(), '/') . '/beitrag.php');
define('BEITRAG_LOGIN_URL', rtrim(WCF::getPath(), '/') . '/index.php?login/');
// Mitgliedsantrag (für Forennutzer, die noch kein Mitglied sind).
define('BEITRAG_ANTRAG_URL', rtrim(WCF::getPath(), '/') . '/mitgliedsantrag.php');
define('BEITRAG_LOGO', __DIR__ . '/images/afol-logo.png');

// --- SumUp-API-Key (Secret) -------------------------------------------------
// Reihenfolge: Umgebungsvariable, dann Secret-Datei außerhalb des Webroots.
// NICHT im Repository ablegen. (Ein Key mit „payments"-Scope ist nötig, um
// Checkouts zu erzeugen – nicht nur der reine Lese-Key.)
$beitragSumupKey = getenv('SUMUP_API_KEY') ?: '';
if ($beitragSumupKey === '') {
    foreach ([
        __DIR__ . '/../../afol-secrets/sumup-api-key.txt',
        __DIR__ . '/../afol-secrets/sumup-api-key.txt',
        __DIR__ . '/sumup-api-key.txt',
    ] as $keyFile) {
        if (is_file($keyFile)) {
            $beitragSumupKey = trim((string) file_get_contents($keyFile));
            break;
        }
    }
}
define('BEITRAG_SUMUP_KEY', $beitragSumupKey);

// --- Geheimnis (HMAC für Formular-/Rückkehr-Token) --------------------------
$beitragSecret = getenv('AFOL_CARD_SECRET') ?: '';
if ($beitragSecret === '') {
    foreach ([
        __DIR__ . '/../../afol-secrets/card-secret.txt',
        __DIR__ . '/../afol-secrets/card-secret.txt',
        __DIR__ . '/card-secret.txt',
    ] as $secretFile) {
        if (is_file($secretFile)) {
            $beitragSecret = trim((string) file_get_contents($secretFile));
            break;
        }
    }
}
define('BEITRAG_SECRET', $beitragSecret ?: 'BITTE-LANGES-ZUFALLSGEHEIMNIS-SETZEN');

// Unterstützte Sprachen.
const BEITRAG_LANGS = ['lb', 'de', 'fr', 'en'];

// ===========================================================================
// Übersetzungen
// Platzhalter: {verein} {mail} werden global ersetzt; {name} {betrag} {jahr}
// {typ} bleiben für die Laufzeit erhalten.
// ===========================================================================

function beitrag_T(): array
{
    $T = [
        'lb' => [
            'eyebrow' => 'Joresbäitrag', 'h1' => 'Bäitrag bezuelen',
            'intro' => 'Bezuel däi Joresbäitrag bei {verein} bequem online mat SumUp.',
            'loggedin_as' => 'Ageloggt als',
            'typ_foerder' => 'Förder-Member (Membre sympathisant)',
            'typ_aktiv' => 'Aktive Member (Membre actif)',
            'your_membership' => 'Deng Memberschaft', 'your_fee' => 'Däi Joresbäitrag',
            'year' => 'Bäitragsjoer', 'amount' => 'Betrag',
            'pay_intro' => 'Kléck op de Knäppchen, fir sécher mat der Kaart iwwer SumUp ze bezuelen.',
            'pay_btn' => 'Elo mat SumUp bezuelen',
            'secure_note' => 'D\'Bezuelung leeft sécher iwwer SumUp of. Mir späicheren keng Kaartendonnéeën.',
            'after_note' => 'Soubal d\'Bezuelung bestätegt ass, gëllt däi Bäitrag als bezuelt. De Comité gläicht d\'Zuelung of.',
            'already_paid' => 'Fir d\'Joer {jahr} ass schonn e Bäitrag agedroen. Du kanns awer nach eng Kéier bezuelen (z. B. Spend oder nächst Joer).',
            'notmember_title' => 'Kee Member fonnt',
            'notmember' => 'Mir konnten deng Memberschaft net fannen. Wann s du nach kee Member bass, maach w.e.g. fir d\'éischt eng Member-Demande.',
            'notmember_btn' => 'Zur Member-Demande',
            'contact' => 'Bei Froen: {mail}.', 'back_forum' => 'Bei d\'Forum',
            'err_title' => 'Et huet net geklappt',
            'err_config' => 'D\'Online-Bezuelung ass grad net konfiguréiert. Mell dech w.e.g. bei {mail}.',
            'err_generic' => 'D\'Bezuelung konnt net gestart ginn. Probéier w.e.g. méi spéit nach eng Kéier oder mell dech bei {mail}.',
            'err_expired' => 'D\'Säit ass ofgelaf. Lued se w.e.g. nei.',
            'ret_paid_title' => 'Merci – Bezuelung krut!',
            'ret_paid' => 'Merci {name}, deng Bezuelung ass ugaang. Däi Bäitrag fir {jahr} gëllt als bezuelt.',
            'ret_pending_title' => 'Bezuelung a Veraarbechtung',
            'ret_pending' => 'Deng Bezuelung gëtt nach veraarbecht, {name}. Du kriss eng Bestätegung, soubal se duerch ass.',
            'ret_failed_title' => 'Bezuelung net ofgeschloss',
            'ret_failed' => 'D\'Bezuelung gouf net ofgeschloss. Du kanns et gär nach eng Kéier probéieren.',
            'ret_generic_title' => 'Merci',
            'ret_generic' => 'Merci {name}. Wann s du bezuelt hues, gëtt d\'Zuelung geschwënn ofgeglach.',
            'try_again' => 'Nach eng Kéier probéieren',
        ],
        'de' => [
            'eyebrow' => 'Jahresbeitrag', 'h1' => 'Beitrag bezahlen',
            'intro' => 'Bezahle deinen Jahresbeitrag bei {verein} bequem online mit SumUp.',
            'loggedin_as' => 'Angemeldet als',
            'typ_foerder' => 'Fördermitglied (Membre sympathisant)',
            'typ_aktiv' => 'Aktives Mitglied (Membre actif)',
            'your_membership' => 'Deine Mitgliedschaft', 'your_fee' => 'Dein Jahresbeitrag',
            'year' => 'Beitragsjahr', 'amount' => 'Betrag',
            'pay_intro' => 'Klick auf den Button, um sicher mit Karte über SumUp zu bezahlen.',
            'pay_btn' => 'Jetzt mit SumUp bezahlen',
            'secure_note' => 'Die Zahlung wird sicher über SumUp abgewickelt. Wir speichern keine Kartendaten.',
            'after_note' => 'Sobald die Zahlung bestätigt ist, gilt dein Beitrag als bezahlt. Der Vorstand gleicht die Zahlung ab.',
            'already_paid' => 'Für {jahr} ist bereits ein Beitrag eingetragen. Du kannst trotzdem erneut bezahlen (z. B. Spende oder Folgejahr).',
            'notmember_title' => 'Kein Mitglied gefunden',
            'notmember' => 'Wir konnten deine Mitgliedschaft nicht finden. Wenn du noch kein Mitglied bist, stelle bitte zuerst einen Mitgliedsantrag.',
            'notmember_btn' => 'Zum Mitgliedsantrag',
            'contact' => 'Bei Fragen: {mail}.', 'back_forum' => 'Zum Forum',
            'err_title' => 'Es hat nicht geklappt',
            'err_config' => 'Die Online-Zahlung ist gerade nicht konfiguriert. Bitte melde dich bei {mail}.',
            'err_generic' => 'Die Zahlung konnte nicht gestartet werden. Bitte versuche es später erneut oder melde dich bei {mail}.',
            'err_expired' => 'Die Seite ist abgelaufen. Bitte lade sie neu.',
            'ret_paid_title' => 'Danke – Zahlung erhalten!',
            'ret_paid' => 'Danke {name}, deine Zahlung ist eingegangen. Dein Beitrag für {jahr} gilt als bezahlt.',
            'ret_pending_title' => 'Zahlung in Bearbeitung',
            'ret_pending' => 'Deine Zahlung wird noch verarbeitet, {name}. Du erhältst eine Bestätigung, sobald sie abgeschlossen ist.',
            'ret_failed_title' => 'Zahlung nicht abgeschlossen',
            'ret_failed' => 'Die Zahlung wurde nicht abgeschlossen. Du kannst es gerne erneut versuchen.',
            'ret_generic_title' => 'Danke',
            'ret_generic' => 'Danke {name}. Falls du bezahlt hast, wird die Zahlung in Kürze abgeglichen.',
            'try_again' => 'Erneut versuchen',
        ],
        'fr' => [
            'eyebrow' => 'Cotisation annuelle', 'h1' => 'Payer la cotisation',
            'intro' => 'Paie ta cotisation annuelle à {verein} facilement en ligne avec SumUp.',
            'loggedin_as' => 'Connecté en tant que',
            'typ_foerder' => 'Membre sympathisant',
            'typ_aktiv' => 'Membre actif',
            'your_membership' => 'Ton adhésion', 'your_fee' => 'Ta cotisation annuelle',
            'year' => 'Année de cotisation', 'amount' => 'Montant',
            'pay_intro' => 'Clique sur le bouton pour payer en toute sécurité par carte via SumUp.',
            'pay_btn' => 'Payer maintenant avec SumUp',
            'secure_note' => 'Le paiement est traité en toute sécurité par SumUp. Nous ne stockons aucune donnée de carte.',
            'after_note' => 'Dès que le paiement est confirmé, ta cotisation est considérée comme payée. Le comité effectue le rapprochement.',
            'already_paid' => 'Une cotisation est déjà enregistrée pour {jahr}. Tu peux tout de même payer à nouveau (don ou année suivante).',
            'notmember_title' => 'Membre introuvable',
            'notmember' => 'Nous n\'avons pas trouvé ton adhésion. Si tu n\'es pas encore membre, fais d\'abord une demande d\'adhésion.',
            'notmember_btn' => 'Vers la demande d\'adhésion',
            'contact' => 'Questions : {mail}.', 'back_forum' => 'Vers le forum',
            'err_title' => 'Cela n\'a pas fonctionné',
            'err_config' => 'Le paiement en ligne n\'est pas configuré pour le moment. Merci de contacter {mail}.',
            'err_generic' => 'Le paiement n\'a pas pu être lancé. Réessaie plus tard ou contacte {mail}.',
            'err_expired' => 'La page a expiré. Merci de la recharger.',
            'ret_paid_title' => 'Merci – paiement reçu !',
            'ret_paid' => 'Merci {name}, ton paiement a été reçu. Ta cotisation pour {jahr} est considérée comme payée.',
            'ret_pending_title' => 'Paiement en cours',
            'ret_pending' => 'Ton paiement est encore en cours de traitement, {name}. Tu recevras une confirmation dès qu\'il sera terminé.',
            'ret_failed_title' => 'Paiement non finalisé',
            'ret_failed' => 'Le paiement n\'a pas été finalisé. Tu peux réessayer.',
            'ret_generic_title' => 'Merci',
            'ret_generic' => 'Merci {name}. Si tu as payé, le paiement sera bientôt rapproché.',
            'try_again' => 'Réessayer',
        ],
        'en' => [
            'eyebrow' => 'Annual fee', 'h1' => 'Pay your fee',
            'intro' => 'Pay your annual fee for {verein} easily online with SumUp.',
            'loggedin_as' => 'Signed in as',
            'typ_foerder' => 'Supporting member (Membre sympathisant)',
            'typ_aktiv' => 'Active member (Membre actif)',
            'your_membership' => 'Your membership', 'your_fee' => 'Your annual fee',
            'year' => 'Fee year', 'amount' => 'Amount',
            'pay_intro' => 'Click the button to pay securely by card via SumUp.',
            'pay_btn' => 'Pay now with SumUp',
            'secure_note' => 'The payment is handled securely by SumUp. We do not store any card details.',
            'after_note' => 'Once the payment is confirmed, your fee counts as paid. The committee reconciles the payment.',
            'already_paid' => 'A fee is already recorded for {jahr}. You can still pay again (e.g. a donation or next year).',
            'notmember_title' => 'No membership found',
            'notmember' => 'We could not find your membership. If you are not a member yet, please submit a membership application first.',
            'notmember_btn' => 'To the membership application',
            'contact' => 'Questions: {mail}.', 'back_forum' => 'To the forum',
            'err_title' => 'That did not work',
            'err_config' => 'Online payment is not configured right now. Please contact {mail}.',
            'err_generic' => 'The payment could not be started. Please try again later or contact {mail}.',
            'err_expired' => 'The page has expired. Please reload it.',
            'ret_paid_title' => 'Thank you – payment received!',
            'ret_paid' => 'Thank you {name}, your payment was received. Your fee for {jahr} counts as paid.',
            'ret_pending_title' => 'Payment processing',
            'ret_pending' => 'Your payment is still being processed, {name}. You\'ll get a confirmation once it\'s complete.',
            'ret_failed_title' => 'Payment not completed',
            'ret_failed' => 'The payment was not completed. Feel free to try again.',
            'ret_generic_title' => 'Thank you',
            'ret_generic' => 'Thank you {name}. If you paid, the payment will be reconciled shortly.',
            'try_again' => 'Try again',
        ],
    ];

    $repl = ['{verein}' => BEITRAG_VEREIN_NAME, '{mail}' => BEITRAG_KONTAKT_EMAIL];
    foreach ($T as &$lang) {
        foreach ($lang as &$s) {
            $s = strtr($s, $repl);
        }
    }
    return $T;
}

function beitrag_tr(string $lang, string $key, array $vars = []): string
{
    static $T = null;
    if ($T === null) {
        $T = beitrag_T();
    }
    $s = $T[$lang][$key] ?? ($T['de'][$key] ?? $key);
    if ($vars) {
        $s = strtr($s, $vars);
    }
    return $s;
}

function beitrag_lang(): string
{
    $l = (string) ($_POST['lang'] ?? $_GET['lang'] ?? '');
    if (in_array($l, BEITRAG_LANGS, true)) {
        return $l;
    }
    $accept = strtolower(substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 2));
    if (in_array($accept, BEITRAG_LANGS, true)) {
        return $accept;
    }
    return 'de';
}

// ===========================================================================
// Token-Helfer (HMAC-signiert)
// ===========================================================================

function beitrag_make_token(string $kind, string $value): string
{
    $payload = $kind . '|' . $value;
    $data = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $sig  = substr(hash_hmac('sha256', $payload, BEITRAG_SECRET), 0, 32);
    return $data . '.' . $sig;
}

function beitrag_read_token(string $kind, string $token): ?string
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
    $expected = substr(hash_hmac('sha256', $payload, BEITRAG_SECRET), 0, 32);
    return hash_equals($expected, (string) $sig) ? $value : null;
}

// ===========================================================================
// Hilfsfunktionen
// ===========================================================================

function beitrag_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function beitrag_current_user(): ?array
{
    $u = WCF::getUser();
    if (!$u || !$u->userID) {
        return null;
    }
    return ['userID' => (int) $u->userID, 'username' => (string) $u->username, 'email' => (string) $u->email];
}

function beitrag_is_admin(): bool
{
    try {
        return (bool) WCF::getSession()->getPermission('admin.user.canEditUser');
    } catch (\Throwable $e) {
        return false;
    }
}

/** Geldbetrag sprachgerecht formatieren. */
function beitrag_money(float $v, string $lang): string
{
    if ($lang === 'en') {
        return '€' . number_format($v, 2, '.', ',');
    }
    return number_format($v, 2, ',', '.') . ' €';
}

/** Beitragsjahr: vor dem GV-Monat zählt noch das Vorjahr. */
function beitrag_jahr(): int
{
    $y = (int) date('Y');
    return ((int) date('n') < BEITRAG_GV_MONAT) ? $y - 1 : $y;
}

/**
 * Mitglied des eingeloggten Nutzers laden (aus `mitglieder`).
 * Bevorzugt Treffer über wcf_user_id, sonst über die E-Mail.
 * @return array{id:int,mitgliedsnummer:string,vorname:string,nachname:string,email:string,typ:string,status:string}|null
 */
function beitrag_load_member(array $user): ?array
{
    $sql = "SELECT id, mitgliedsnummer, vorname, nachname, email, typ, status
            FROM mitglieder
            WHERE wcf_user_id = ? " . ($user['email'] !== '' ? "OR LOWER(email) = ?" : "") . "
            ORDER BY (wcf_user_id = ?) DESC,
                     FIELD(status,'aktiv','pausiert','antrag','inaktiv','ausgetreten','abgelehnt')
            LIMIT 1";
    $params = [$user['userID']];
    if ($user['email'] !== '') {
        $params[] = mb_strtolower($user['email']);
    }
    $params[] = $user['userID'];

    try {
        $stmt = WCF::getDB()->prepareStatement($sql);
        $stmt->execute($params);
        $row = $stmt->fetchArray();
    } catch (\Throwable $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    return [
        'id'              => (int) $row['id'],
        'mitgliedsnummer' => (string) ($row['mitgliedsnummer'] ?? ''),
        'vorname'         => (string) $row['vorname'],
        'nachname'        => (string) $row['nachname'],
        'email'           => (string) ($row['email'] ?? ''),
        'typ'             => (string) $row['typ'],
        'status'          => (string) $row['status'],
    ];
}

/** Betrag (EUR) für den Mitgliedstyp. */
function beitrag_amount(string $typ): float
{
    return $typ === 'foerder' ? BEITRAG_FOERDER : BEITRAG_AKTIV;
}

/** Übersetzter Typ-Name. */
function beitrag_typ_label(string $typ, string $lang): string
{
    return beitrag_tr($lang, $typ === 'foerder' ? 'typ_foerder' : 'typ_aktiv');
}

/** Ist für dieses Jahr schon ein Beitrag eingetragen? (best effort) */
function beitrag_already_paid(int $mitgliedId, int $jahr): bool
{
    try {
        $stmt = WCF::getDB()->prepareStatement(
            "SELECT COUNT(*) AS n FROM beitraege WHERE mitglied_id = ? AND jahr = ? AND bezahlt_am IS NOT NULL");
        $stmt->execute([$mitgliedId, $jahr]);
        $row = $stmt->fetchArray();
        return ((int) ($row['n'] ?? 0)) > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

// ===========================================================================
// SumUp Hosted Checkout
// ===========================================================================

/** cURL-JSON-Aufruf gegen die SumUp-API. */
function beitrag_sumup_request(string $method, string $path, ?array $body = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL ist auf dem Server nicht verfügbar.'];
    }
    if (BEITRAG_SUMUP_KEY === '') {
        return ['ok' => false, 'error' => 'Kein SumUp-API-Key konfiguriert.'];
    }
    $ch = curl_init(BEITRAG_SUMUP_API_BASE . $path);
    $headers = [
        'Authorization: Bearer ' . BEITRAG_SUMUP_KEY,
        'Accept: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'error' => 'Verbindungsfehler: ' . $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300) {
        $short = trim(mb_substr((string) $raw, 0, 300));
        return ['ok' => false, 'status' => $status, 'error' => 'SumUp HTTP ' . $status . ' – ' . $short,
                'data' => is_array($data) ? $data : null];
    }
    return ['ok' => true, 'status' => $status, 'data' => is_array($data) ? $data : []];
}

/**
 * Erzeugt einen Hosted Checkout und liefert ['url'=>..., 'id'=>...] oder Fehler.
 * $email: E-Mail für die Zuordnung (Mitglieds-E-Mail, sonst Login-E-Mail).
 * $login: WoltLab-Benutzername (Login-Name) für die Zuordnung.
 */
function beitrag_create_checkout(array $member, float $amount, int $jahr, string $lang, string $email = '', string $login = ''): array
{
    $name = trim($member['vorname'] . ' ' . $member['nachname']);
    $nr   = $member['mitgliedsnummer'] !== '' ? $member['mitgliedsnummer'] : ('id' . $member['id']);
    // Eindeutige Referenz für den Abgleich beim Trésorier.
    $reference = 'AFOL-' . $nr . '-' . $jahr . '-' . substr((string) time(), -6);
    // Beschreibung zur Identifikation in SumUp: Vor-/Nachname + E-Mail + Login (+ Jahr).
    // Sie ist in der SumUp-App/im Dashboard als Beleg der Transaktion sichtbar.
    $description = 'Cotisation ' . $jahr . ' – ' . $name
                 . ($email !== '' ? ' – ' . $email : '')
                 . ($login !== '' ? ' – @' . $login : '');

    // Signierter Rückkehr-Link mit der Checkout-Referenz.
    $returnUrl = BEITRAG_SELF_URL . '?page=return&lang=' . $lang
               . '&c=' . rawurlencode(beitrag_make_token('return', $reference));

    $payload = [
        'checkout_reference' => $reference,
        'amount'             => round($amount, 2),
        'currency'           => BEITRAG_CURRENCY,
        'merchant_code'      => BEITRAG_SUMUP_MERCHANT,
        'description'        => $description,
        'redirect_url'       => $returnUrl,
        'hosted_checkout'    => ['enabled' => true],
    ];

    $res = beitrag_sumup_request('POST', '/v0.1/checkouts', $payload);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'] ?? 'Unbekannter Fehler'];
    }
    $url = (string) ($res['data']['hosted_checkout_url'] ?? '');
    if ($url === '') {
        return ['ok' => false, 'error' => 'SumUp lieferte keine hosted_checkout_url.'];
    }
    return ['ok' => true, 'url' => $url, 'id' => (string) ($res['data']['id'] ?? ''), 'reference' => $reference];
}

/** Status eines Checkouts anhand seiner Referenz ermitteln (best effort). */
function beitrag_checkout_status(string $reference): ?string
{
    $res = beitrag_sumup_request('GET', '/v0.1/checkouts?checkout_reference=' . rawurlencode($reference));
    if (!$res['ok'] || !is_array($res['data'])) {
        return null;
    }
    // Antwort kann ein Array von Checkouts sein.
    $item = isset($res['data']['status']) ? $res['data'] : ($res['data'][0] ?? null);
    if (!is_array($item)) {
        return null;
    }
    return strtoupper((string) ($item['status'] ?? '')) ?: null;
}

// ===========================================================================
// Design / Layout (AFOL.lu)
// ===========================================================================

function beitrag_css(): string
{
    return ':root{--ink:#1c2833;--red:#d01012;--yellow:#ffcf00;--blue:#006cb7;'
      . '--green:#237841;--gold:#c8a951;--bg:#f5f6f7;--border:#e1e1e1;--text:#2c2e30;--sumup:#0b6b62}'
      . '*{margin:0;padding:0;box-sizing:border-box}'
      . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;'
      . 'background:var(--bg);color:var(--text);line-height:1.6;font-size:15px}'
      . '.page{max-width:620px;margin:0 auto;padding:0 16px 60px}'
      . '.langbar{display:flex;justify-content:flex-end;gap:6px;margin-top:16px;flex-wrap:wrap}'
      . '.langbar button{border:1px solid var(--border);background:#fff;color:#44484c;cursor:pointer;'
      . 'font-size:13px;font-weight:600;padding:7px 12px;border-radius:6px;transition:all .12s}'
      . '.langbar button:hover{border-color:var(--blue);color:var(--blue)}'
      . '.langbar button.active{background:var(--blue);color:#fff;border-color:var(--blue)}'
      . '.hero{position:relative;margin-top:12px;border-radius:8px;overflow:hidden;'
      . 'background:linear-gradient(135deg,#1c2833 0%,#224a6e 55%,#006cb7 100%);color:#fff;padding:34px 40px}'
      . '.hero::after{content:"";position:absolute;top:0;right:0;bottom:0;width:6px;'
      . 'background:linear-gradient(180deg,var(--red),var(--yellow),var(--blue),var(--green))}'
      . '.hero img.logo{height:34px;margin-bottom:14px}'
      . '.hero .eyebrow{display:inline-block;font-size:12px;letter-spacing:1.5px;text-transform:uppercase;'
      . 'font-weight:700;color:var(--yellow);margin-bottom:10px}'
      . '.hero h1{font-size:27px;line-height:1.2;margin-bottom:10px}'
      . '.hero p{font-size:15px;max-width:560px;color:#e8eef3}'
      . '.box{background:#fff;border:1px solid var(--border);border-radius:8px;padding:24px 28px;margin-top:22px}'
      . '.who{font-size:13.5px;color:#6a6e72;margin-bottom:6px}'
      . '.summary{border:1px solid var(--border);border-radius:10px;overflow:hidden;margin:6px 0 4px}'
      . '.summary .r{display:flex;justify-content:space-between;align-items:center;padding:13px 16px;border-top:1px solid var(--border)}'
      . '.summary .r:first-child{border-top:0}'
      . '.summary .r .k{color:#5a5e62;font-size:14px}'
      . '.summary .r .v{font-weight:600}'
      . '.summary .amount .v{font-size:26px;color:var(--ink)}'
      . '.summary .amount{background:#f7fbff}'
      . '.btn{display:inline-block;background:var(--blue);color:#fff;border:0;border-radius:8px;'
      . 'padding:13px 24px;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .15s}'
      . '.btn:hover{background:#005a99}.btn.gray{background:#6b7178}.btn.gray:hover{background:#565b61}'
      . '.btn.pay{background:var(--sumup);width:100%;text-align:center;font-size:17px;padding:15px 24px}'
      . '.btn.pay:hover{background:#095a52}'
      . '.note-secure{font-size:12.5px;color:#7a7e82;margin-top:10px;text-align:center}'
      . '.alert{border-radius:8px;padding:12px 14px;margin:14px 0;font-size:14px}'
      . '.alert.err{background:#fff5f5;border:1px solid #feb2b2;color:#9b2c2c}'
      . '.alert.ok{background:#f0fff4;border:1px solid #9ae6b4;color:#22543d}'
      . '.alert.note{background:#fff8f0;border:1px solid #f0d2ad;color:#7a4b16}'
      . '.consent{color:#5a5e62;font-size:13px;margin-top:16px}'
      . '.foot{text-align:center;color:#9a9ea2;font-size:12px;margin-top:18px}'
      . '@media(max-width:600px){.hero{padding:26px 22px}.hero h1{font-size:22px}.box{padding:20px}}';
}

function beitrag_logo_img(): string
{
    if (is_file(BEITRAG_LOGO)) {
        $info = @getimagesize(BEITRAG_LOGO);
        if ($info !== false) {
            return '<img class="logo" src="data:' . $info['mime'] . ';base64,'
                 . base64_encode((string) file_get_contents(BEITRAG_LOGO)) . '" alt="AFOL.lu">';
        }
    }
    return '';
}

/** Sprachleiste (mit JS-Umschalter, der ?lang= neu lädt). */
function beitrag_langbar(string $current): string
{
    $out = '<div class="langbar">';
    foreach (['fr' => 'Français', 'de' => 'Deutsch', 'en' => 'English', 'lb' => 'Lëtzebuergesch'] as $code => $name) {
        $active = $code === $current ? ' class="active"' : '';
        $out .= '<button type="button"' . $active . ' onclick="beitragSetLang(\'' . $code . '\')">' . $name . '</button>';
    }
    $out .= '</div>';
    return $out;
}

/** Vollständige Seite (Hero + Inhalt) ausgeben. */
function beitrag_layout(string $lang, string $heroTitel, string $inhaltHtml, string $eyebrow = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    $eyebrowHtml = $eyebrow !== '' ? '<span class="eyebrow">' . beitrag_e($eyebrow) . '</span>' : '';
    echo '<!DOCTYPE html><html lang="' . beitrag_e($lang) . '"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="theme-color" content="#1c2833">'
       . '<title>' . beitrag_e($heroTitel) . ' – ' . beitrag_e(BEITRAG_VEREIN_NAME) . '</title>'
       . '<style>' . beitrag_css() . '</style></head><body><div class="page">'
       . beitrag_langbar($lang)
       . '<header class="hero">' . beitrag_logo_img() . $eyebrowHtml
       . '<h1>' . beitrag_e($heroTitel) . '</h1></header>'
       . '<section class="box">' . $inhaltHtml . '</section>'
       . '<p class="foot">' . beitrag_e(BEITRAG_VEREIN_NAME) . '</p></div>'
       . '<script>function beitragSetLang(l){var u=new URL(window.location.href);u.searchParams.set("lang",l);'
       . 'window.location.href=u.toString();}</script>'
       . '</body></html>';
}

// ===========================================================================
// Aktion: Bestätigungsseite / Formular
// ===========================================================================

function beitrag_show_confirm(string $lang, array $user): void
{
    $member = beitrag_load_member($user);

    // Kein Mitglied gefunden -> Hinweis auf Mitgliedsantrag.
    if ($member === null) {
        beitrag_layout($lang, beitrag_tr($lang, 'notmember_title'),
            '<div class="alert note">' . beitrag_e(beitrag_tr($lang, 'notmember')) . '</div>'
            . '<p style="margin-top:14px;"><a class="btn" href="' . beitrag_e(BEITRAG_ANTRAG_URL) . '">'
            . beitrag_e(beitrag_tr($lang, 'notmember_btn')) . '</a> '
            . '<a class="btn gray" style="margin-left:8px;" href="' . beitrag_e(BEITRAG_FORUM_URL) . '">'
            . beitrag_e(beitrag_tr($lang, 'back_forum')) . '</a></p>'
            . '<p class="consent">' . beitrag_e(beitrag_tr($lang, 'contact')) . '</p>',
            beitrag_tr($lang, 'eyebrow'));
        return;
    }

    $jahr   = beitrag_jahr();
    $amount = beitrag_amount($member['typ']);
    $name   = trim($member['vorname'] . ' ' . $member['nachname']);

    $paidNote = beitrag_already_paid($member['id'], $jahr)
        ? '<div class="alert note">' . beitrag_e(beitrag_tr($lang, 'already_paid', ['{jahr}' => (string) $jahr])) . '</div>'
        : '';

    $formToken = beitrag_make_token('pay', (string) time());

    $summary =
        '<div class="summary">'
        . '<div class="r"><span class="k">' . beitrag_e(beitrag_tr($lang, 'your_membership')) . '</span>'
        . '<span class="v">' . beitrag_e(beitrag_typ_label($member['typ'], $lang)) . '</span></div>'
        . '<div class="r"><span class="k">' . beitrag_e(beitrag_tr($lang, 'year')) . '</span>'
        . '<span class="v">' . beitrag_e((string) $jahr) . '</span></div>'
        . '<div class="r amount"><span class="k">' . beitrag_e(beitrag_tr($lang, 'amount')) . '</span>'
        . '<span class="v">' . beitrag_e(beitrag_money($amount, $lang)) . '</span></div>'
        . '</div>';

    $inhalt =
        '<p class="who">' . beitrag_e(beitrag_tr($lang, 'loggedin_as')) . ' <strong>' . beitrag_e($name) . '</strong></p>'
        . $paidNote
        . $summary
        . '<p style="margin-top:16px;">' . beitrag_e(beitrag_tr($lang, 'pay_intro')) . '</p>'
        . '<form method="post" action="' . beitrag_e(BEITRAG_SELF_URL) . '">'
        . '<input type="hidden" name="action" value="pay">'
        . '<input type="hidden" name="lang" value="' . beitrag_e($lang) . '">'
        . '<input type="hidden" name="pay_token" value="' . beitrag_e($formToken) . '">'
        . '<p style="margin-top:14px;"><button class="btn pay" type="submit">'
        . beitrag_e(beitrag_tr($lang, 'pay_btn')) . ' · ' . beitrag_e(beitrag_money($amount, $lang)) . '</button></p>'
        . '</form>'
        . '<p class="note-secure">' . beitrag_e(beitrag_tr($lang, 'secure_note')) . '</p>'
        . '<div class="alert note" style="margin-top:16px;">' . beitrag_e(beitrag_tr($lang, 'after_note')) . '</div>'
        . '<p class="consent">' . beitrag_e(beitrag_tr($lang, 'contact')) . '</p>';

    beitrag_layout($lang, beitrag_tr($lang, 'h1'), $inhalt, beitrag_tr($lang, 'eyebrow'));
}

// ===========================================================================
// Aktion: Zahlung starten (POST)
// ===========================================================================

function beitrag_handle_pay(string $lang, array $user): void
{
    // CSRF-/Frische-Prüfung des Formular-Tokens.
    $issued = beitrag_read_token('pay', (string) ($_POST['pay_token'] ?? ''));
    if ($issued === null || (time() - (int) $issued) > 3600) {
        beitrag_layout($lang, beitrag_tr($lang, 'err_title'),
            '<div class="alert err">' . beitrag_e(beitrag_tr($lang, 'err_expired')) . '</div>'
            . '<p style="margin-top:14px;"><a class="btn" href="' . beitrag_e(BEITRAG_SELF_URL . '?lang=' . $lang) . '">'
            . beitrag_e(beitrag_tr($lang, 'try_again')) . '</a></p>');
        return;
    }

    $member = beitrag_load_member($user);
    if ($member === null) {
        beitrag_show_confirm($lang, $user);   // zeigt den „kein Mitglied"-Hinweis
        return;
    }

    $jahr   = beitrag_jahr();
    // WICHTIG: Betrag serverseitig aus dem Typ – NIE aus dem POST.
    $amount = beitrag_amount($member['typ']);

    // E-Mail zur Zuordnung: bevorzugt aus dem Mitglieds-Datensatz, sonst Login.
    $email = $member['email'] !== '' ? $member['email'] : ($user['email'] ?? '');
    $login = (string) ($user['username'] ?? '');

    // 1) Hosted Checkout per API (bevorzugt, exakter Betrag + Referenz).
    if (BEITRAG_SUMUP_KEY !== '' && BEITRAG_SUMUP_MERCHANT !== '') {
        $res = beitrag_create_checkout($member, $amount, $jahr, $lang, $email, $login);
        if ($res['ok']) {
            header('Location: ' . $res['url']);
            echo '<a href="' . beitrag_e($res['url']) . '">SumUp…</a>';
            return;
        }
        $detail = beitrag_is_admin()
            ? '<p class="consent"><strong>[Admin]</strong> ' . beitrag_e((string) ($res['error'] ?? '')) . '</p>'
            : '';
        beitrag_layout($lang, beitrag_tr($lang, 'err_title'),
            '<div class="alert err">' . beitrag_e(beitrag_tr($lang, 'err_generic')) . '</div>' . $detail
            . '<p style="margin-top:14px;"><a class="btn" href="' . beitrag_e(BEITRAG_SELF_URL . '?lang=' . $lang) . '">'
            . beitrag_e(beitrag_tr($lang, 'try_again')) . '</a></p>');
        return;
    }

    // 2) Fallback: statischer SumUp-Link je Typ.
    $link = $member['typ'] === 'foerder' ? BEITRAG_SUMUP_LINK_FOERDER : BEITRAG_SUMUP_LINK_AKTIV;
    if ($link !== '') {
        header('Location: ' . $link);
        echo '<a href="' . beitrag_e($link) . '">SumUp…</a>';
        return;
    }

    // 3) Nichts konfiguriert.
    $detail = beitrag_is_admin()
        ? '<p class="consent"><strong>[Admin]</strong> BEITRAG_SUMUP_MERCHANT / SumUp-API-Key oder statische Links fehlen.</p>'
        : '';
    beitrag_layout($lang, beitrag_tr($lang, 'err_title'),
        '<div class="alert err">' . beitrag_e(beitrag_tr($lang, 'err_config')) . '</div>' . $detail);
}

// ===========================================================================
// Aktion: Rückkehr von SumUp
// ===========================================================================

function beitrag_handle_return(string $lang, array $user): void
{
    $member = beitrag_load_member($user);
    $name   = $member ? trim($member['vorname'] . ' ' . $member['nachname']) : ($user['username'] ?? '');
    $jahr   = beitrag_jahr();

    $reference = beitrag_read_token('return', (string) ($_GET['c'] ?? ''));
    $status = ($reference !== null && BEITRAG_SUMUP_KEY !== '')
        ? beitrag_checkout_status($reference)
        : null;

    $tryAgain = '<p style="margin-top:14px;">'
        . '<a class="btn gray" href="' . beitrag_e(BEITRAG_FORUM_URL) . '">' . beitrag_e(beitrag_tr($lang, 'back_forum')) . '</a></p>';

    if ($status === 'PAID') {
        beitrag_layout($lang, beitrag_tr($lang, 'ret_paid_title'),
            '<div class="alert ok">' . beitrag_e(beitrag_tr($lang, 'ret_paid', ['{name}' => $name, '{jahr}' => (string) $jahr])) . '</div>' . $tryAgain);
        return;
    }
    if ($status === 'FAILED') {
        beitrag_layout($lang, beitrag_tr($lang, 'ret_failed_title'),
            '<div class="alert err">' . beitrag_e(beitrag_tr($lang, 'ret_failed')) . '</div>'
            . '<p style="margin-top:14px;"><a class="btn" href="' . beitrag_e(BEITRAG_SELF_URL . '?lang=' . $lang) . '">'
            . beitrag_e(beitrag_tr($lang, 'try_again')) . '</a></p>');
        return;
    }
    if ($status === 'PENDING') {
        beitrag_layout($lang, beitrag_tr($lang, 'ret_pending_title'),
            '<div class="alert note">' . beitrag_e(beitrag_tr($lang, 'ret_pending', ['{name}' => $name])) . '</div>' . $tryAgain);
        return;
    }

    // Kein/unbekannter Status -> generische Danke-Seite.
    beitrag_layout($lang, beitrag_tr($lang, 'ret_generic_title'),
        '<div class="alert ok">' . beitrag_e(beitrag_tr($lang, 'ret_generic', ['{name}' => $name])) . '</div>' . $tryAgain);
}

// ===========================================================================
// Aktion: Diagnose (nur Admin)
// ===========================================================================

function beitrag_handle_test(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    if (!beitrag_is_admin()) {
        echo "Nur für Administratoren.\n";
        return;
    }
    echo "AFOL Beitrag/SumUp – Diagnose\n";
    echo "DIAGNOSE-VERSION: 2026-07-09-beitrag1\n\n";
    echo "Verein:          " . BEITRAG_VEREIN_NAME . "\n";
    echo "Beitrag Förder:  " . number_format(BEITRAG_FOERDER, 2, ',', '.') . " EUR\n";
    echo "Beitrag Aktiv:   " . number_format(BEITRAG_AKTIV, 2, ',', '.') . " EUR\n";
    echo "Währung:         " . BEITRAG_CURRENCY . "\n";
    echo "Beitragsjahr:    " . beitrag_jahr() . " (GV-Monat " . BEITRAG_GV_MONAT . ")\n";
    echo "Secret gesetzt:  " . (BEITRAG_SECRET !== 'BITTE-LANGES-ZUFALLSGEHEIMNIS-SETZEN' ? 'ja' : 'NEIN') . "\n";
    echo "Self-URL:        " . BEITRAG_SELF_URL . "\n\n";

    echo "--- SumUp ---\n";
    echo "API-Key:         " . (BEITRAG_SUMUP_KEY !== '' ? 'gesetzt (' . strlen(BEITRAG_SUMUP_KEY) . ' Zeichen)' : 'FEHLT') . "\n";
    echo "Merchant-Code:   " . (BEITRAG_SUMUP_MERCHANT !== '' ? BEITRAG_SUMUP_MERCHANT : 'FEHLT') . "\n";
    echo "Statik Förder:   " . (BEITRAG_SUMUP_LINK_FOERDER !== '' ? BEITRAG_SUMUP_LINK_FOERDER : '(leer)') . "\n";
    echo "Statik Aktiv:    " . (BEITRAG_SUMUP_LINK_AKTIV !== '' ? BEITRAG_SUMUP_LINK_AKTIV : '(leer)') . "\n";
    echo "cURL vorhanden:  " . (function_exists('curl_init') ? 'ja' : 'NEIN') . "\n\n";

    // Spalte typ vorhanden?
    try {
        WCF::getDB()->prepareStatement("SELECT typ FROM mitglieder LIMIT 1")->execute();
        echo "Tabelle mitglieder.typ: vorhanden\n";
    } catch (\Throwable $e) {
        echo "Tabelle mitglieder.typ: FEHLER – " . $e->getMessage() . "\n";
    }

    $u = beitrag_current_user();
    echo "\nEingeloggt als:  " . ($u ? $u['username'] . ' <' . $u['email'] . '>' : '(niemand)') . "\n";
    if ($u) {
        $m = beitrag_load_member($u);
        if ($m) {
            echo "Mitglied:        #" . $m['id'] . " " . trim($m['vorname'] . ' ' . $m['nachname'])
               . " (Nr. " . ($m['mitgliedsnummer'] ?: '-') . ")\n";
            echo "Typ / Status:    " . $m['typ'] . " / " . $m['status'] . "\n";
            echo "Betrag:          " . number_format(beitrag_amount($m['typ']), 2, ',', '.') . " EUR\n";
            echo "Schon bezahlt " . beitrag_jahr() . ": " . (beitrag_already_paid($m['id'], beitrag_jahr()) ? 'ja' : 'nein') . "\n";
        } else {
            echo "Mitglied:        nicht in der Tabelle mitglieder gefunden\n";
        }
    }

    if (isset($_GET['ping']) && BEITRAG_SUMUP_KEY !== '') {
        echo "\n--- SumUp API Ping (letzte Transaktion lesen) ---\n";
        $res = beitrag_sumup_request('GET', '/v0.1/me/transactions/history?limit=1');
        echo $res['ok'] ? "OK (API erreichbar, Key gültig)\n" : ("FEHLER: " . ($res['error'] ?? '?') . "\n");
    } else {
        echo "\nAPI-Test: beitrag.php?page=test&ping=1\n";
    }
}

// ===========================================================================
// Router
// ===========================================================================

$page = (string) ($_GET['page'] ?? '');
$lang = beitrag_lang();

if ($page === 'test') {
    beitrag_handle_test();
    exit;
}

// Ab hier ist Login nötig.
$user = beitrag_current_user();
if ($user === null) {
    $back = BEITRAG_SELF_URL . ($page !== '' ? '?page=' . rawurlencode($page) : '');
    $sep  = strpos(BEITRAG_LOGIN_URL, '?') === false ? '?' : '&';
    header('Location: ' . BEITRAG_LOGIN_URL . $sep . 'url=' . rawurlencode($back));
    echo '<a href="' . beitrag_e(BEITRAG_LOGIN_URL) . '">Login…</a>';
    exit;
}

if ($page === 'return') {
    beitrag_handle_return($lang, $user);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    beitrag_handle_pay($lang, $user);
} else {
    beitrag_show_confirm($lang, $user);
}

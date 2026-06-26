<?php
/**
 * AFOL.lu – Mitgliedsantrag (eigenständiges Formular, 4-sprachig)
 * Drop-in Skript für das WoltLab Suite 5.5 Root-Verzeichnis (neben global.php).
 *
 * Ersetzt das fehlerhafte WoltLab-Formular-Plugin und behebt dessen Mängel:
 *   1. Der Absender erhält eine Bestätigungs-E-Mail.
 *   2. Die Mail enthält die Aufforderung, den Beitrag aufs Vereinskonto zu
 *      überweisen (IBAN, Betrag, Verwendungszweck).
 *   3. Ein persönlicher Storno-Link erlaubt, den Antrag zurückzuziehen.
 *
 * Zusätzlich:
 *   - Sprachen LB / DE / FR / EN (Umschalter), AFOL.lu-Design.
 *   - Feld „Bevorzugte Sprache für Newsletter" (Spalte mitglieder.newsletter_sprache).
 *
 * Aufruf:
 *   mitgliedsantrag.php                     -> Antragsformular (öffentlich)
 *   mitgliedsantrag.php (POST)              -> Antrag absenden
 *   mitgliedsantrag.php?page=storno&t=TOKEN -> Antrag zurückziehen
 *   mitgliedsantrag.php?page=test           -> Diagnose (nur Admin)
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
define('ANTRAG_BEITRAG',      '15,00 €');                   // <-- Jahresbeitrag (Fördermitglied)
// Verwendungszweck: {name} -> "Vorname Nachname", {jahr} -> Jahr.
define('ANTRAG_VERWENDUNG',   'Fördermitgliedsbeitrag {jahr} – {name}');
// Alternative Online-Zahlung per SumUp-Link (leer = ausgeblendet).
define('ANTRAG_SUMUP_URL',    'https://pay.sumup.com/b2c/Q3MX52O5');

// --- E-Mail -----------------------------------------------------------------
define('ANTRAG_MAIL_FROM',     '');   // leer = WoltLab-Standardabsender (empfohlen)
define('ANTRAG_MAIL_FROM_NAME','AFOL.lu');
define('ANTRAG_KONTAKT_EMAIL', 'vorstand@afol.lu');         // <-- anpassen

// --- Sonstiges --------------------------------------------------------------
define('ANTRAG_FORUM_URL', rtrim(WCF::getPath(), '/') . '/');
define('ANTRAG_SELF_URL',  rtrim(WCF::getPath(), '/') . '/mitgliedsantrag.php');
define('ANTRAG_LAND_DEFAULT', 'Luxembourg');
define('ANTRAG_LOGO', __DIR__ . '/images/afol-logo.png');   // optional (wie membercard)

// --- Geheimnis (HMAC für Storno-/Formular-Token) ----------------------------
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

// Unterstützte Sprachen.
const ANTRAG_LANGS = ['lb', 'de', 'fr', 'en'];
// Native Bezeichnungen für die Newsletter-Auswahl (sprachunabhängig).
const ANTRAG_LANG_NAMES = [
    'lb' => '🇱🇺 Lëtzebuergesch',
    'de' => '🇩🇪 Deutsch',
    'fr' => '🇫🇷 Français',
    'en' => '🇬🇧 English',
];

// ===========================================================================
// Übersetzungen (eine Quelle für Formular-JS, Server-Seiten und E-Mail)
// Platzhalter: {verein} {beitrag} {mail} werden hier ersetzt; {name} {email}
// {status} bleiben für die Laufzeit erhalten.
// ===========================================================================

function antrag_T(): array
{
    $T = [
        'lb' => [
            'eyebrow' => 'Förder-Memberschaft', 'h1' => 'Member-Demande',
            'foerder_note' => 'Du gëss fir d\'éischt Förder-Member (Membre sympathisant). Aktive Member kann ee fréistens no 6 Méint Memberschaft ginn.',
            'intro' => 'Gëff Member bei {verein}. No der Demande kriss du eng Bestätegung per E-Mail mat de Kontosdonnéeën fir de Joresbäitrag ({beitrag}).',
            'loggedin' => 'Ageloggt – deng Demande gëtt mat dengem Forums-Kont verbonnen.',
            'guest_hint' => 'Du brauchs kee Forums-Kont fir eng Demande ze stellen. Wann s du een hues, logg dech virdrun an – da gëtt deng Demande domat verbonnen.',
            'sec_person' => 'Deng Donnéeën', 'sec_address' => 'Adress',
            'vorname' => 'Virnumm', 'nachname' => 'Numm', 'email' => 'E-Mail',
            'telefon' => 'Telefon', 'geburtsdatum' => 'Gebuertsdatum',
            'strasse' => 'Strooss', 'nr' => 'Nr.', 'plz' => 'Postleitzuel',
            'ort' => 'Uertschaft', 'land' => 'Land',
            'newsletter' => 'Bevorzugt Sprooch fir den Newsletter',
            'bemerkung' => 'Bemierkung (fakultativ)',
            'consent' => 'Mat dem Ofschécken freet du d\'Memberschaft un. Si gëtt eréischt mam Agang vum Joresbäitrag wierksam.',
            'submit' => 'Demande ofschécken', 'req' => '* Flichtfeld',
            'err_title' => 'Iwwerpréif w.e.g. deng Agaben:',
            'err_vorname' => 'Virnumm feelt.', 'err_nachname' => 'Numm feelt.',
            'err_email' => 'Gëff w.e.g. eng gülteg E-Mail-Adress un.',
            'err_geb' => 'Gebuertsdatum am Format JJJJ-MM-DD.',
            'err_expired' => 'D\'Formulaire ass ofgelaf. Schéck et w.e.g. nach eng Kéier.',
            'dup_title' => 'Schonn do', 'back_forum' => 'Bei d\'Forum',
            'dup_aktiv' => 'Mat dëser E-Mail bass du schonn aktive Member. Bei Froen: {mail}.',
            'dup_antrag' => 'Mat dëser E-Mail läit schonn eng Demande vir. Mir mellen eis. Kontakt: {mail}.',
            'save_err_title' => 'Et ass e Feeler geschitt',
            'save_err' => 'Deng Demande konnt net gespäichert ginn. Probéier w.e.g. méi spéit nach eng Kéier oder mell dech bei {mail}.',
            'ty_title' => 'Merci fir deng Demande!',
            'ty_mail_ok' => 'Mir hunn der eng Bestätegung op {email} geschéckt.',
            'ty_mail_fail' => 'Hiweis: D\'Bestätegungs-E-Mail konnt grad net geschéckt ginn – d\'Kontosdonnéeën fënns du awer hei drënner.',
            'pay_alt' => 'Oder bezuel bequem online mat der Kaart:',
            'pay_sumup_btn' => 'Online mat SumUp bezuelen',
            'ty_pay_intro' => 'Fir datt deng Memberschaft wierksam gëtt, iwwerweis w.e.g. de Joresbäitrag op eist Veräinskonto:',
            'ty_after' => 'Soubal d\'Bezuelung do ass a bestätegt ass, bass du offiziell Member a kriss deng Memberskaart.',
            'ty_storno_title' => 'Verseenlech ugefrot?',
            'ty_storno_text' => 'Wollts du dech just am Forum umellen? Dann kanns du deng Demande direkt zerécksetzen:',
            'storno_btn' => 'Demande zerécksetzen',
            'k_empf' => 'Empfänger', 'k_iban' => 'IBAN', 'k_bic' => 'BIC',
            'k_bank' => 'Bank', 'k_betrag' => 'Betrag', 'k_zweck' => 'Verwendungszweck',
            'st_invalid_title' => 'Link ongülteg', 'st_invalid' => 'Dëse Storno-Link ass ongülteg oder onvollstänneg.',
            'st_notfound_title' => 'Net fonnt', 'st_notfound' => 'Dës Demande gëtt et net (méi).',
            'st_already' => 'Dës Demande gouf scho zeréckgesat.',
            'st_cant' => 'Dës Demande kann net méi selwer zeréckgesat ginn (Status: {status}). Mell dech w.e.g. bei {mail}.',
            'st_confirm_title' => 'Demande zerécksetzen',
            'st_confirm_q' => 'Wëlls du deng Member-Demande ({name}) wierklech zerécksetzen?',
            'st_yes' => 'Jo, Demande zerécksetzen', 'st_cancel' => 'Ofbriechen',
            'st_done_title' => 'Demande zeréckgesat',
            'st_done' => 'Deng Member-Demande gouf zeréckgesat, {name}. Et entstinn der keng Verflichtungen.',
            'st_done_note' => 'Wann s du dech just am Forum umelle wollts: Däi Forums-Kont bleift dovun onberéiert.',
            'mail_subject' => 'Deng Member-Demande bei {verein}',
            'mail_hello' => 'Salut {name},',
            'mail_thanks' => 'villmools merci fir deng Member-Demande bei {verein}.',
            'mail_pay' => 'Fir datt deng Memberschaft wierksam gëtt, iwwerweis w.e.g. de Joresbäitrag op eist Veräinskonto:',
            'mail_after' => 'Soubal d\'Bezuelung do ass a bestätegt ass, bass du offiziell Member a kriss deng Memberskaart.',
            'mail_storno' => 'Du hues dech geiert oder wollts dech just am Forum umellen? Dann kanns du deng Demande hei zerécksetzen:',
            'mail_storno_link' => 'Demande hei zerécksetzen',
            'mail_contact' => 'Bei Froen erreechs du eis ënner {mail}.',
            'mail_greeting' => 'Vill Gréiss',
        ],
        'de' => [
            'eyebrow' => 'Fördermitgliedschaft', 'h1' => 'Mitgliedsantrag',
            'foerder_note' => 'Du wirst zunächst Fördermitglied (Membre sympathisant). Aktives Mitglied kann man frühestens nach 6 Monaten Mitgliedschaft werden.',
            'intro' => 'Werde Mitglied bei {verein}. Nach dem Absenden erhältst du eine Bestätigung per E-Mail mit den Überweisungsdaten für den Jahresbeitrag ({beitrag}).',
            'loggedin' => 'Angemeldet – dein Antrag wird mit deinem Forenkonto verknüpft.',
            'guest_hint' => 'Du brauchst kein Forenkonto, um einen Antrag zu stellen. Hast du eines, melde dich vorher an – dann wird dein Antrag damit verknüpft.',
            'sec_person' => 'Deine Daten', 'sec_address' => 'Adresse',
            'vorname' => 'Vorname', 'nachname' => 'Nachname', 'email' => 'E-Mail',
            'telefon' => 'Telefon', 'geburtsdatum' => 'Geburtsdatum',
            'strasse' => 'Straße', 'nr' => 'Nr.', 'plz' => 'PLZ',
            'ort' => 'Ort', 'land' => 'Land',
            'newsletter' => 'Bevorzugte Sprache für Newsletter',
            'bemerkung' => 'Bemerkung (optional)',
            'consent' => 'Mit dem Absenden beantragst du die Mitgliedschaft. Sie wird erst mit Eingang des Jahresbeitrags wirksam.',
            'submit' => 'Antrag absenden', 'req' => '* Pflichtfeld',
            'err_title' => 'Bitte prüfe deine Eingaben:',
            'err_vorname' => 'Vorname fehlt.', 'err_nachname' => 'Nachname fehlt.',
            'err_email' => 'Bitte eine gültige E-Mail-Adresse angeben.',
            'err_geb' => 'Geburtsdatum bitte im Format JJJJ-MM-TT.',
            'err_expired' => 'Das Formular ist abgelaufen. Bitte sende es erneut ab.',
            'dup_title' => 'Schon vorhanden', 'back_forum' => 'Zum Forum',
            'dup_aktiv' => 'Unter dieser E-Mail bist du bereits aktives Mitglied. Bei Fragen: {mail}.',
            'dup_antrag' => 'Unter dieser E-Mail liegt bereits ein Antrag vor. Wir melden uns. Kontakt: {mail}.',
            'save_err_title' => 'Es ist ein Fehler aufgetreten',
            'save_err' => 'Dein Antrag konnte nicht gespeichert werden. Bitte versuche es später erneut oder melde dich bei {mail}.',
            'ty_title' => 'Danke für deinen Antrag!',
            'ty_mail_ok' => 'Wir haben dir eine Bestätigung an {email} geschickt.',
            'ty_mail_fail' => 'Hinweis: Die Bestätigungs-E-Mail konnte gerade nicht versendet werden – die Überweisungsdaten findest du aber unten.',
            'pay_alt' => 'Oder bezahle bequem online mit Karte:',
            'pay_sumup_btn' => 'Online mit SumUp bezahlen',
            'ty_pay_intro' => 'Damit deine Mitgliedschaft wirksam wird, überweise bitte den Jahresbeitrag auf unser Vereinskonto:',
            'ty_after' => 'Sobald die Zahlung eingegangen und bestätigt ist, bist du offizielles Mitglied und erhältst deine Mitgliedskarte.',
            'ty_storno_title' => 'Versehentlich beantragt?',
            'ty_storno_text' => 'Wolltest du dich nur im Forum anmelden? Dann kannst du deinen Antrag direkt zurückziehen:',
            'storno_btn' => 'Antrag zurückziehen',
            'k_empf' => 'Empfänger', 'k_iban' => 'IBAN', 'k_bic' => 'BIC',
            'k_bank' => 'Bank', 'k_betrag' => 'Betrag', 'k_zweck' => 'Verwendungszweck',
            'st_invalid_title' => 'Link ungültig', 'st_invalid' => 'Dieser Storno-Link ist ungültig oder unvollständig.',
            'st_notfound_title' => 'Nicht gefunden', 'st_notfound' => 'Dieser Antrag existiert nicht (mehr).',
            'st_already' => 'Dieser Antrag wurde bereits zurückgezogen.',
            'st_cant' => 'Dieser Antrag kann nicht mehr selbst zurückgezogen werden (Status: {status}). Bitte wende dich an {mail}.',
            'st_confirm_title' => 'Antrag zurückziehen',
            'st_confirm_q' => 'Möchtest du deinen Mitgliedsantrag ({name}) wirklich zurückziehen?',
            'st_yes' => 'Ja, Antrag zurückziehen', 'st_cancel' => 'Abbrechen',
            'st_done_title' => 'Antrag zurückgezogen',
            'st_done' => 'Dein Mitgliedsantrag wurde zurückgezogen, {name}. Es entstehen dir keine Verpflichtungen.',
            'st_done_note' => 'Falls du dich nur im Forum anmelden wolltest: Dein Forenkonto bleibt davon unberührt.',
            'mail_subject' => 'Dein Mitgliedsantrag bei {verein}',
            'mail_hello' => 'Hallo {name},',
            'mail_thanks' => 'vielen Dank für deinen Mitgliedsantrag bei {verein}.',
            'mail_pay' => 'Damit deine Mitgliedschaft wirksam wird, überweise bitte den Jahresbeitrag auf unser Vereinskonto:',
            'mail_after' => 'Sobald die Zahlung eingegangen und bestätigt ist, bist du offizielles Mitglied und erhältst deine Mitgliedskarte.',
            'mail_storno' => 'Du hast dich geirrt oder wolltest dich nur im Forum anmelden? Dann kannst du deinen Antrag hier zurückziehen:',
            'mail_storno_link' => 'Antrag hier zurückziehen',
            'mail_contact' => 'Bei Fragen erreichst du uns unter {mail}.',
            'mail_greeting' => 'Viele Grüße',
        ],
        'fr' => [
            'eyebrow' => 'Membre sympathisant', 'h1' => 'Demande d\'adhésion',
            'foerder_note' => 'Tu deviens d\'abord membre sympathisant. On peut devenir membre actif au plus tôt après 6 mois d\'adhésion.',
            'intro' => 'Deviens membre d\'{verein}. Après l\'envoi, tu recevras une confirmation par e-mail avec les coordonnées bancaires pour la cotisation annuelle ({beitrag}).',
            'loggedin' => 'Connecté – ta demande sera liée à ton compte du forum.',
            'guest_hint' => 'Pas besoin de compte du forum pour faire une demande. Si tu en as un, connecte-toi d\'abord – ta demande y sera alors liée.',
            'sec_person' => 'Tes données', 'sec_address' => 'Adresse',
            'vorname' => 'Prénom', 'nachname' => 'Nom', 'email' => 'E-mail',
            'telefon' => 'Téléphone', 'geburtsdatum' => 'Date de naissance',
            'strasse' => 'Rue', 'nr' => 'N°', 'plz' => 'Code postal',
            'ort' => 'Localité', 'land' => 'Pays',
            'newsletter' => 'Langue préférée pour la newsletter',
            'bemerkung' => 'Remarque (facultatif)',
            'consent' => 'En envoyant ce formulaire, tu demandes l\'adhésion. Elle ne prend effet qu\'à la réception de la cotisation annuelle.',
            'submit' => 'Envoyer la demande', 'req' => '* Champ obligatoire',
            'err_title' => 'Merci de vérifier tes données :',
            'err_vorname' => 'Le prénom manque.', 'err_nachname' => 'Le nom manque.',
            'err_email' => 'Merci d\'indiquer une adresse e-mail valide.',
            'err_geb' => 'Date de naissance au format AAAA-MM-JJ.',
            'err_expired' => 'Le formulaire a expiré. Merci de le renvoyer.',
            'dup_title' => 'Déjà enregistré', 'back_forum' => 'Vers le forum',
            'dup_aktiv' => 'Avec cet e-mail, tu es déjà membre actif. Questions : {mail}.',
            'dup_antrag' => 'Une demande existe déjà avec cet e-mail. Nous te recontactons. Contact : {mail}.',
            'save_err_title' => 'Une erreur s\'est produite',
            'save_err' => 'Ta demande n\'a pas pu être enregistrée. Réessaie plus tard ou contacte {mail}.',
            'ty_title' => 'Merci pour ta demande !',
            'ty_mail_ok' => 'Nous t\'avons envoyé une confirmation à {email}.',
            'ty_mail_fail' => 'Remarque : l\'e-mail de confirmation n\'a pas pu être envoyé – tu trouveras les coordonnées bancaires ci-dessous.',
            'pay_alt' => 'Ou paie facilement en ligne par carte :',
            'pay_sumup_btn' => 'Payer en ligne avec SumUp',
            'ty_pay_intro' => 'Pour que ton adhésion prenne effet, vire la cotisation annuelle sur le compte de l\'association :',
            'ty_after' => 'Dès que le paiement est reçu et confirmé, tu es membre officiel et reçois ta carte de membre.',
            'ty_storno_title' => 'Demande par erreur ?',
            'ty_storno_text' => 'Tu voulais seulement t\'inscrire au forum ? Tu peux retirer ta demande directement :',
            'storno_btn' => 'Retirer la demande',
            'k_empf' => 'Bénéficiaire', 'k_iban' => 'IBAN', 'k_bic' => 'BIC',
            'k_bank' => 'Banque', 'k_betrag' => 'Montant', 'k_zweck' => 'Communication',
            'st_invalid_title' => 'Lien invalide', 'st_invalid' => 'Ce lien de retrait est invalide ou incomplet.',
            'st_notfound_title' => 'Introuvable', 'st_notfound' => 'Cette demande n\'existe pas (plus).',
            'st_already' => 'Cette demande a déjà été retirée.',
            'st_cant' => 'Cette demande ne peut plus être retirée par toi-même (statut : {status}). Merci de contacter {mail}.',
            'st_confirm_title' => 'Retirer la demande',
            'st_confirm_q' => 'Veux-tu vraiment retirer ta demande d\'adhésion ({name}) ?',
            'st_yes' => 'Oui, retirer la demande', 'st_cancel' => 'Annuler',
            'st_done_title' => 'Demande retirée',
            'st_done' => 'Ta demande d\'adhésion a été retirée, {name}. Aucune obligation pour toi.',
            'st_done_note' => 'Si tu voulais seulement t\'inscrire au forum : ton compte du forum n\'est pas affecté.',
            'mail_subject' => 'Ta demande d\'adhésion à {verein}',
            'mail_hello' => 'Bonjour {name},',
            'mail_thanks' => 'merci beaucoup pour ta demande d\'adhésion à {verein}.',
            'mail_pay' => 'Pour que ton adhésion prenne effet, vire la cotisation annuelle sur le compte de l\'association :',
            'mail_after' => 'Dès que le paiement est reçu et confirmé, tu es membre officiel et reçois ta carte de membre.',
            'mail_storno' => 'Erreur, ou tu voulais seulement t\'inscrire au forum ? Tu peux retirer ta demande ici :',
            'mail_storno_link' => 'Retirer la demande ici',
            'mail_contact' => 'Pour toute question : {mail}.',
            'mail_greeting' => 'Cordialement',
        ],
        'en' => [
            'eyebrow' => 'Supporting membership', 'h1' => 'Membership application',
            'foerder_note' => 'You first become a supporting member (membre sympathisant). You can become an active member at the earliest after 6 months of membership.',
            'intro' => 'Become a member of {verein}. After submitting, you\'ll receive an e-mail confirmation with the bank details for the annual fee ({beitrag}).',
            'loggedin' => 'Signed in – your application will be linked to your forum account.',
            'guest_hint' => 'You don\'t need a forum account to apply. If you have one, sign in first – your application will then be linked to it.',
            'sec_person' => 'Your details', 'sec_address' => 'Address',
            'vorname' => 'First name', 'nachname' => 'Last name', 'email' => 'E-mail',
            'telefon' => 'Phone', 'geburtsdatum' => 'Date of birth',
            'strasse' => 'Street', 'nr' => 'No.', 'plz' => 'Postal code',
            'ort' => 'Town', 'land' => 'Country',
            'newsletter' => 'Preferred language for the newsletter',
            'bemerkung' => 'Note (optional)',
            'consent' => 'By submitting, you apply for membership. It only takes effect once the annual fee is received.',
            'submit' => 'Submit application', 'req' => '* Required field',
            'err_title' => 'Please check your entries:',
            'err_vorname' => 'First name is missing.', 'err_nachname' => 'Last name is missing.',
            'err_email' => 'Please enter a valid e-mail address.',
            'err_geb' => 'Date of birth in format YYYY-MM-DD.',
            'err_expired' => 'The form has expired. Please submit it again.',
            'dup_title' => 'Already registered', 'back_forum' => 'To the forum',
            'dup_aktiv' => 'You are already an active member with this e-mail. Questions: {mail}.',
            'dup_antrag' => 'An application already exists for this e-mail. We\'ll get back to you. Contact: {mail}.',
            'save_err_title' => 'An error occurred',
            'save_err' => 'Your application could not be saved. Please try again later or contact {mail}.',
            'ty_title' => 'Thanks for your application!',
            'ty_mail_ok' => 'We\'ve sent a confirmation to {email}.',
            'ty_mail_fail' => 'Note: the confirmation e-mail could not be sent right now – the bank details are below.',
            'pay_alt' => 'Or pay easily online by card:',
            'pay_sumup_btn' => 'Pay online with SumUp',
            'ty_pay_intro' => 'To activate your membership, please transfer the annual fee to our association account:',
            'ty_after' => 'Once the payment is received and confirmed, you are an official member and receive your membership card.',
            'ty_storno_title' => 'Applied by mistake?',
            'ty_storno_text' => 'Did you only want to register on the forum? You can withdraw your application directly:',
            'storno_btn' => 'Withdraw application',
            'k_empf' => 'Recipient', 'k_iban' => 'IBAN', 'k_bic' => 'BIC',
            'k_bank' => 'Bank', 'k_betrag' => 'Amount', 'k_zweck' => 'Reference',
            'st_invalid_title' => 'Invalid link', 'st_invalid' => 'This withdrawal link is invalid or incomplete.',
            'st_notfound_title' => 'Not found', 'st_notfound' => 'This application no longer exists.',
            'st_already' => 'This application has already been withdrawn.',
            'st_cant' => 'This application can no longer be withdrawn by you (status: {status}). Please contact {mail}.',
            'st_confirm_title' => 'Withdraw application',
            'st_confirm_q' => 'Do you really want to withdraw your membership application ({name})?',
            'st_yes' => 'Yes, withdraw application', 'st_cancel' => 'Cancel',
            'st_done_title' => 'Application withdrawn',
            'st_done' => 'Your membership application has been withdrawn, {name}. No obligations arise for you.',
            'st_done_note' => 'If you only wanted to register on the forum: your forum account is not affected.',
            'mail_subject' => 'Your membership application at {verein}',
            'mail_hello' => 'Hello {name},',
            'mail_thanks' => 'thank you very much for your membership application at {verein}.',
            'mail_pay' => 'To activate your membership, please transfer the annual fee to our association account:',
            'mail_after' => 'Once the payment is received and confirmed, you are an official member and receive your membership card.',
            'mail_storno' => 'Made a mistake, or only wanted to register on the forum? You can withdraw your application here:',
            'mail_storno_link' => 'Withdraw application here',
            'mail_contact' => 'For any questions, reach us at {mail}.',
            'mail_greeting' => 'Best regards',
        ],
    ];

    // Konstante Platzhalter global ersetzen.
    $repl = ['{verein}' => ANTRAG_VEREIN_NAME, '{beitrag}' => ANTRAG_BEITRAG, '{mail}' => ANTRAG_KONTAKT_EMAIL];
    foreach ($T as &$lang) {
        foreach ($lang as &$s) {
            $s = strtr($s, $repl);
        }
    }
    return $T;
}

/** Übersetzungs-Helfer für eine Sprache; ersetzt zusätzlich Laufzeit-Platzhalter. */
function antrag_tr(string $lang, string $key, array $vars = []): string
{
    static $T = null;
    if ($T === null) {
        $T = antrag_T();
    }
    $s = $T[$lang][$key] ?? ($T['de'][$key] ?? $key);
    if ($vars) {
        $s = strtr($s, $vars);
    }
    return $s;
}

/** Gewählte Sprache aus GET/POST oder Browser; validiert. */
function antrag_lang(): string
{
    $l = (string) ($_POST['lang'] ?? $_GET['lang'] ?? '');
    if (in_array($l, ANTRAG_LANGS, true)) {
        return $l;
    }
    $accept = strtolower(substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 2));
    if (in_array($accept, ANTRAG_LANGS, true)) {
        return $accept;
    }
    return 'de';
}

// ===========================================================================
// Token-Helfer (HMAC-signiert)
// ===========================================================================

function antrag_make_token(string $kind, string $value): string
{
    $payload = $kind . '|' . $value;
    $data = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $sig  = substr(hash_hmac('sha256', $payload, ANTRAG_SECRET), 0, 32);
    return $data . '.' . $sig;
}

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

function antrag_current_user(): ?array
{
    $u = WCF::getUser();
    if (!$u || !$u->userID) {
        return null;
    }
    return ['userID' => (int) $u->userID, 'username' => (string) $u->username, 'email' => (string) $u->email];
}

function antrag_is_admin(): bool
{
    try {
        return (bool) WCF::getSession()->getPermission('admin.user.canEditUser');
    } catch (\Throwable $e) {
        return false;
    }
}

function antrag_find_existing(string $email): ?array
{
    if ($email === '') {
        return null;
    }
    $stmt = WCF::getDB()->prepareStatement("SELECT id, status FROM mitglieder WHERE LOWER(email) = ? LIMIT 1");
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
               wcf_user_id, forum_name, newsletter_sprache,
               status, typ, antragsart, antragsdatum, quelle, bemerkung)
            VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?,?, 'antrag','foerder','online',?, 'mitgliedsantrag', ?)";
    $stmt = WCF::getDB()->prepareStatement($sql);
    $stmt->execute([
        $d['vorname'], $d['nachname'], $d['email'], $d['telefon'], $d['geburtsdatum'],
        $d['hausnummer'], $d['strasse'], $d['plz'], $d['ort'], $d['land'],
        $d['wcf_user_id'], $d['forum_name'], $d['newsletter_sprache'],
        $d['antragsdatum'], $d['bemerkung'],
    ]);
    return (int) WCF::getDB()->getInsertID('mitglieder', 'id');
}

// ===========================================================================
// E-Mail
// ===========================================================================

/** @return array{betreff:string, text:string, html:string} */
function antrag_mail_inhalt(array $d, string $stornoUrl, string $lang): array
{
    $name = trim($d['vorname'] . ' ' . $d['nachname']);
    $jahr = date('Y');
    $verwendung = str_replace(['{jahr}', '{name}'], [$jahr, $name], ANTRAG_VERWENDUNG);

    $konto = [[antrag_tr($lang, 'k_empf'), ANTRAG_KONTO_INHABER], [antrag_tr($lang, 'k_iban'), ANTRAG_IBAN]];
    if (ANTRAG_BIC !== '')  { $konto[] = [antrag_tr($lang, 'k_bic'), ANTRAG_BIC]; }
    if (ANTRAG_BANK !== '') { $konto[] = [antrag_tr($lang, 'k_bank'), ANTRAG_BANK]; }
    $konto[] = [antrag_tr($lang, 'k_betrag'), ANTRAG_BEITRAG];
    $konto[] = [antrag_tr($lang, 'k_zweck'), $verwendung];

    $betreff = antrag_tr($lang, 'mail_subject');

    $kontoText = '';
    foreach ($konto as $z) {
        $kontoText .= str_pad($z[0] . ':', 18) . $z[1] . "\n";
    }
    $sumupText = ANTRAG_SUMUP_URL !== ''
        ? antrag_tr($lang, 'pay_alt') . "\n" . ANTRAG_SUMUP_URL . "\n\n"
        : '';

    $text =
        antrag_tr($lang, 'mail_hello', ['{name}' => $name]) . "\n\n" .
        antrag_tr($lang, 'mail_thanks') . "\n\n" .
        antrag_tr($lang, 'mail_pay') . "\n\n" . $kontoText . "\n" .
        $sumupText .
        antrag_tr($lang, 'mail_after') . "\n\n" .
        antrag_tr($lang, 'mail_storno') . "\n" . $stornoUrl . "\n\n" .
        antrag_tr($lang, 'mail_contact') . "\n\n" .
        antrag_tr($lang, 'mail_greeting') . "\n" . ANTRAG_VEREIN_NAME . "\n";

    $kontoHtml = '';
    foreach ($konto as $z) {
        $kontoHtml .= '<tr><td style="padding:2px 14px 2px 0;color:#555;">' . antrag_e($z[0]) .
                      '</td><td style="padding:2px 0;font-weight:bold;">' . antrag_e($z[1]) . '</td></tr>';
    }
    $html =
        '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222;line-height:1.5;">' .
        '<div style="height:5px;background:linear-gradient(90deg,#d01012,#ffcf00,#006cb7,#237841);"></div>' .
        '<p style="margin-top:16px;">' . antrag_e(antrag_tr($lang, 'mail_hello', ['{name}' => $name])) . '</p>' .
        '<p>' . antrag_e(antrag_tr($lang, 'mail_thanks')) . '</p>' .
        '<p>' . antrag_e(antrag_tr($lang, 'mail_pay')) . '</p>' .
        '<table style="border-collapse:collapse;margin:12px 0;background:#f5f6f7;padding:8px;">' . $kontoHtml . '</table>' .
        (ANTRAG_SUMUP_URL !== ''
            ? '<p style="margin:4px 0 14px;">' . antrag_e(antrag_tr($lang, 'pay_alt')) . '<br>' .
              '<a href="' . antrag_e(ANTRAG_SUMUP_URL) . '" style="display:inline-block;margin-top:6px;' .
              'background:#0f766e;color:#fff;text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:6px;">' .
              antrag_e(antrag_tr($lang, 'pay_sumup_btn')) . '</a></p>'
            : '') .
        '<p>' . antrag_e(antrag_tr($lang, 'mail_after')) . '</p>' .
        '<p style="margin-top:18px;padding:12px;background:#fff6f6;border:1px solid #f0c0c0;border-radius:6px;">' .
        antrag_e(antrag_tr($lang, 'mail_storno')) . '<br>' .
        '<a href="' . antrag_e($stornoUrl) . '">' . antrag_e(antrag_tr($lang, 'mail_storno_link')) . '</a></p>' .
        '<p>' . str_replace(antrag_e(ANTRAG_KONTAKT_EMAIL),
            '<a href="mailto:' . antrag_e(ANTRAG_KONTAKT_EMAIL) . '">' . antrag_e(ANTRAG_KONTAKT_EMAIL) . '</a>',
            antrag_e(antrag_tr($lang, 'mail_contact'))) . '</p>' .
        '<p>' . antrag_e(antrag_tr($lang, 'mail_greeting')) . '<br>' . antrag_e(ANTRAG_VEREIN_NAME) . '</p>' .
        '</div>';

    return ['betreff' => $betreff, 'text' => $text, 'html' => $html];
}

/** Versendet eine E-Mail über das WoltLab-Mailsystem. true oder Fehlertext. */
function antrag_send_mail(string $toEmail, string $toName, string $betreff, string $text, string $html)
{
    try {
        $email = new Email();
        $email->addRecipient(new Mailbox($toEmail, $toName !== '' ? $toName : null));
        if (ANTRAG_MAIL_FROM !== '' && method_exists($email, 'setSender')) {
            $email->setSender(new Mailbox(ANTRAG_MAIL_FROM, ANTRAG_MAIL_FROM_NAME));
        }
        if (ANTRAG_KONTAKT_EMAIL !== '' && method_exists($email, 'setReplyTo')) {
            $email->setReplyTo(new Mailbox(ANTRAG_KONTAKT_EMAIL, ANTRAG_MAIL_FROM_NAME));
        }
        $email->setSubject($betreff);
        $email->setBody(new MimePartFacade([new PlainTextMimePart($text), new HtmlTextMimePart($html)]));
        $email->send();
        antrag_flush_queue();
        return true;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

/** WoltLab-Hintergrund-Warteschlange inline abarbeiten (best effort). */
function antrag_flush_queue(): void
{
    try {
        $cls = '\wcf\system\background\BackgroundQueueHandler';
        if (!class_exists($cls)) {
            return;
        }
        $bq = $cls::getInstance();
        if (method_exists($bq, 'performNextJob')) {
            for ($i = 0; $i < 8; $i++) {
                $bq->performNextJob();
            }
        } elseif (method_exists($bq, 'forceCheck')) {
            $bq->forceCheck();
        }
    } catch (\Throwable $e) {
    }
}

// ===========================================================================
// Design / Layout (AFOL.lu)
// ===========================================================================

function antrag_css(): string
{
    return ':root{--ink:#1c2833;--red:#d01012;--yellow:#ffcf00;--blue:#006cb7;'
      . '--green:#237841;--gold:#c8a951;--bg:#f5f6f7;--border:#e1e1e1;--text:#2c2e30}'
      . '*{margin:0;padding:0;box-sizing:border-box}'
      . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;'
      . 'background:var(--bg);color:var(--text);line-height:1.6;font-size:15px}'
      . '.page{max-width:720px;margin:0 auto;padding:0 16px 60px}'
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
      . '.hero p{font-size:15px;max-width:640px;color:#e8eef3}'
      . '.box{background:#fff;border:1px solid var(--border);border-radius:8px;padding:24px 28px;margin-top:22px}'
      . '.box h2{font-size:18px;color:#1c1d1f;margin:0 0 16px;padding-bottom:8px;'
      . 'border-bottom:2px solid var(--gold);display:inline-block}'
      . 'label{display:block;font-weight:600;margin:14px 0 4px;font-size:13.5px;color:#3a3e42}'
      . 'input,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccd2d8;'
      . 'border-radius:6px;font-size:15px;font-family:inherit}'
      . 'input:focus,textarea:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,108,183,.12)}'
      . '.row{display:flex;gap:14px;flex-wrap:wrap}.row>div{flex:1;min-width:120px}'
      . '.req{color:#7a7e82;font-size:12.5px;margin-top:14px}'
      . '.consent{color:#5a5e62;font-size:13px;margin-top:16px}'
      . '.nl{display:flex;gap:10px;flex-wrap:wrap;margin-top:4px}'
      . '.nl label{display:flex;align-items:center;gap:7px;margin:0;padding:9px 13px;border:1px solid var(--border);'
      . 'border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;background:#fff;transition:all .12s}'
      . '.nl label:hover{border-color:var(--blue)}'
      . '.nl input{width:auto;margin:0;accent-color:var(--blue)}'
      . '.nl label.sel{border-color:var(--blue);background:#eef6fc;color:var(--blue)}'
      . '.btn{display:inline-block;background:var(--blue);color:#fff;border:0;border-radius:8px;'
      . 'padding:13px 24px;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .15s}'
      . '.btn:hover{background:#005a99}.btn.gray{background:#6b7178}.btn.gray:hover{background:#565b61}'
      . '.btn.red{background:var(--red)}.btn.red:hover{background:#a50d0f}'
      . '.alert{border-radius:8px;padding:12px 14px;margin:14px 0;font-size:14px}'
      . '.alert.err{background:#fff5f5;border:1px solid #feb2b2;color:#9b2c2c}'
      . '.alert.ok{background:#f0fff4;border:1px solid #9ae6b4;color:#22543d}'
      . '.alert.note{background:#fff8f0;border:1px solid #f0d2ad;color:#7a4b16}'
      . '.alert ul{margin:6px 0 0 18px}'
      . 'table.konto{border-collapse:collapse;margin:6px 0 4px}'
      . 'table.konto td{padding:4px 16px 4px 0;font-size:14.5px}'
      . 'table.konto td.l{color:#555}.table.konto td.v{font-weight:bold}'
      . '.foot{text-align:center;color:#9a9ea2;font-size:12px;margin-top:18px}'
      . '@media(max-width:600px){.hero{padding:26px 22px}.hero h1{font-size:22px}.box{padding:20px}}';
}

function antrag_page_close(): string
{
    return '<p class="foot">' . antrag_e(ANTRAG_VEREIN_NAME) . '</p></div></body></html>';
}

/** Logo als <img>-Tag (oder leer). */
function antrag_logo_img(): string
{
    if (is_file(ANTRAG_LOGO)) {
        $info = @getimagesize(ANTRAG_LOGO);
        if ($info !== false) {
            return '<img class="logo" src="data:' . $info['mime'] . ';base64,'
                 . base64_encode((string) file_get_contents(ANTRAG_LOGO)) . '" alt="AFOL.lu">';
        }
    }
    return '';
}

/** Einfache servergerenderte Seite mit Hero-Titel + Inhalt. */
function antrag_layout(string $lang, string $heroTitel, string $inhaltHtml): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="' . antrag_e($lang) . '"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="theme-color" content="#1c2833">'
       . '<title>' . antrag_e($heroTitel) . ' – ' . antrag_e(ANTRAG_VEREIN_NAME) . '</title>'
       . '<style>' . antrag_css() . '</style></head><body><div class="page">'
       . '<header class="hero">' . antrag_logo_img()
       . '<h1>' . antrag_e($heroTitel) . '</h1></header>'
       . '<section class="box">' . $inhaltHtml . '</section>'
       . antrag_page_close();
}

/** Kontodaten als HTML-Tabelle. */
function antrag_konto_html(array $d, string $lang): string
{
    $name = trim($d['vorname'] . ' ' . $d['nachname']);
    $verwendung = str_replace(['{jahr}', '{name}'], [date('Y'), $name], ANTRAG_VERWENDUNG);
    $zeilen = [[antrag_tr($lang, 'k_empf'), ANTRAG_KONTO_INHABER], [antrag_tr($lang, 'k_iban'), ANTRAG_IBAN]];
    if (ANTRAG_BIC !== '')  { $zeilen[] = [antrag_tr($lang, 'k_bic'), ANTRAG_BIC]; }
    if (ANTRAG_BANK !== '') { $zeilen[] = [antrag_tr($lang, 'k_bank'), ANTRAG_BANK]; }
    $zeilen[] = [antrag_tr($lang, 'k_betrag'), ANTRAG_BEITRAG];
    $zeilen[] = [antrag_tr($lang, 'k_zweck'), $verwendung];
    $html = '<table class="konto">';
    foreach ($zeilen as $z) {
        $html .= '<tr><td class="l">' . antrag_e($z[0]) . '</td><td class="v" style="font-weight:bold;">'
               . antrag_e($z[1]) . '</td></tr>';
    }
    return $html . '</table>';
}

// ===========================================================================
// Aktion: Formular anzeigen
// ===========================================================================

function antrag_show_form(array $errors = [], array $alt = []): void
{
    $lang = antrag_lang();
    $user = antrag_current_user();
    $val = function (string $k, string $default = '') use ($alt) {
        return antrag_e($alt[$k] ?? $default);
    };
    $formToken = antrag_make_token('form', (string) time());

    $errHtml = '';
    if ($errors) {
        $errHtml = '<div class="alert err"><strong>' . antrag_e(antrag_tr($lang, 'err_title')) . '</strong><ul>';
        foreach ($errors as $e) {
            $errHtml .= '<li>' . antrag_e($e) . '</li>';
        }
        $errHtml .= '</ul></div>';
    }

    // Newsletter-Radios (native Bezeichnungen, sprachunabhängig)
    $nl = '<div class="nl">';
    foreach (ANTRAG_LANG_NAMES as $code => $name) {
        $checked = (($alt['newsletter'] ?? '') === $code) ? ' checked' : '';
        $nl .= '<label data-nl="' . $code . '"><input type="radio" name="newsletter" value="' . $code . '"' . $checked . '> '
             . antrag_e($name) . '</label>';
    }
    $nl .= '</div>';

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="' . antrag_e($lang) . '"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="theme-color" content="#1c2833">'
       . '<title>' . antrag_e(ANTRAG_VEREIN_NAME) . '</title><style>' . antrag_css() . '</style></head>'
       . '<body><div class="page">';

    // Sprachleiste
    echo '<div class="langbar">';
    foreach (['fr' => 'Français', 'de' => 'Deutsch', 'en' => 'English', 'lb' => 'Lëtzebuergesch'] as $code => $name) {
        echo '<button type="button" data-lang="' . $code . '" onclick="setLang(\'' . $code . '\')">' . $name . '</button>';
    }
    echo '</div>';

    // Hero
    echo '<header class="hero">' . antrag_logo_img()
       . '<span class="eyebrow" data-i="eyebrow"></span>'
       . '<h1 data-i="h1"></h1><p data-i="intro"></p></header>';

    echo '<section class="box">';
    // Hinweis zur Fördermitgliedschaft (6-Monats-Regel).
    echo '<p class="consent" style="margin-top:0;font-weight:600;" data-i="foerder_note"></p>';
    // Hinweis: für Gäste „kein Forenkonto nötig", für Eingeloggte „wird verknüpft".
    echo '<div class="alert note" data-i="' . ($user ? 'loggedin' : 'guest_hint') . '"></div>';
    echo $errHtml;

    echo '<form method="post" action="' . antrag_e(ANTRAG_SELF_URL) . '" autocomplete="on">'
       . '<input type="hidden" name="form_token" value="' . antrag_e($formToken) . '">'
       . '<input type="hidden" name="lang" id="lang" value="' . antrag_e($lang) . '">'
       // Honeypot
       . '<div style="position:absolute;left:-5000px;" aria-hidden="true">'
       . '<label>Leave empty</label><input type="text" name="website" tabindex="-1" autocomplete="off"></div>'

       . '<div class="row"><div><label><span data-i="vorname"></span> *</label>'
       . '<input name="vorname" required value="' . $val('vorname') . '"></div>'
       . '<div><label><span data-i="nachname"></span> *</label>'
       . '<input name="nachname" required value="' . $val('nachname') . '"></div></div>'

       . '<label><span data-i="email"></span> *</label>'
       . '<input type="email" name="email" required value="' . $val('email', $user['email'] ?? '') . '">'

       . '<div class="row"><div><label data-i="telefon"></label>'
       . '<input name="telefon" value="' . $val('telefon') . '"></div>'
       . '<div><label data-i="geburtsdatum"></label>'
       . '<input type="date" name="geburtsdatum" value="' . $val('geburtsdatum') . '"></div></div>'

       . '<label data-i="newsletter"></label>' . $nl

       . '<h2 style="margin-top:22px;" data-i="sec_address"></h2>'
       . '<div class="row"><div style="flex:3"><label data-i="strasse"></label>'
       . '<input name="strasse" value="' . $val('strasse') . '"></div>'
       . '<div style="flex:1"><label data-i="nr"></label>'
       . '<input name="hausnummer" value="' . $val('hausnummer') . '"></div></div>'
       . '<div class="row"><div style="flex:1"><label data-i="plz"></label>'
       . '<input name="plz" value="' . $val('plz') . '"></div>'
       . '<div style="flex:3"><label data-i="ort"></label>'
       . '<input name="ort" value="' . $val('ort') . '"></div></div>'
       . '<label data-i="land"></label>'
       . '<input name="land" value="' . $val('land', ANTRAG_LAND_DEFAULT) . '">'

       . '<label data-i="bemerkung"></label>'
       . '<textarea name="bemerkung" rows="2">' . $val('bemerkung') . '</textarea>'

       . '<p class="req" data-i="req"></p>'
       . '<p class="consent" data-i="consent"></p>'
       . '<p style="margin-top:16px;"><button class="btn" type="submit" data-i="submit"></button></p>'
       . '</form></section>';

    echo antrag_page_close();

    // i18n-Logik (eine Übersetzungstabelle als JSON)
    $json = json_encode(antrag_T(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    echo '<script>var T=' . $json . ';'
       . 'function setLang(l){if(!T[l])l="de";document.documentElement.lang=l;var d=T[l];'
       . 'document.querySelectorAll("[data-i]").forEach(function(el){var k=el.getAttribute("data-i");if(d[k]!=null)el.textContent=d[k];});'
       . 'document.querySelectorAll(".langbar button").forEach(function(b){b.classList.toggle("active",b.getAttribute("data-lang")===l);});'
       . 'var hf=document.getElementById("lang");if(hf)hf.value=l;'
       . 'try{localStorage.setItem("afolLang",l);}catch(e){}'
       . 'if(!window.nlTouched){var r=document.querySelector("input[name=newsletter][value="+l+"]");if(r){r.checked=true;markNl();}}}'
       . 'function markNl(){document.querySelectorAll(".nl label").forEach(function(l){var i=l.querySelector("input");l.classList.toggle("sel",i&&i.checked);});}'
       . 'document.addEventListener("change",function(e){if(e.target&&e.target.name==="newsletter"){window.nlTouched=true;markNl();}});'
       . '(function(){var pre=document.querySelector("input[name=newsletter]:checked");if(pre)window.nlTouched=true;'
       . 'var s;try{s=localStorage.getItem("afolLang");}catch(e){}'
       . 'var n=(navigator.language||"de").slice(0,2).toLowerCase();'
       . 'var init=s||(T[n]?n:"de");if(!T[init])init="' . antrag_e($lang) . '";setLang(init);markNl();})();'
       . '</script></body></html>';
}

// ===========================================================================
// Aktion: Antrag verarbeiten (POST)
// ===========================================================================

function antrag_handle_post(): void
{
    $lang = antrag_lang();

    if (!empty($_POST['website'])) { // Honeypot
        antrag_layout($lang, antrag_tr($lang, 'ty_title'), '<p>OK</p>');
        return;
    }
    $issued = antrag_read_token('form', (string) ($_POST['form_token'] ?? ''));
    if ($issued === null || (time() - (int) $issued) < 3 || (time() - (int) $issued) > 21600) {
        antrag_show_form([antrag_tr($lang, 'err_expired')], $_POST);
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
        'bemerkung' => $g('bemerkung'), 'newsletter' => $g('newsletter'),
    ];

    $errors = [];
    if ($alt['vorname'] === '')  { $errors[] = antrag_tr($lang, 'err_vorname'); }
    if ($alt['nachname'] === '') { $errors[] = antrag_tr($lang, 'err_nachname'); }
    if ($alt['email'] === '' || !filter_var($alt['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = antrag_tr($lang, 'err_email');
    }
    $geb = null;
    if ($alt['geburtsdatum'] !== '') {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $alt['geburtsdatum'])) {
            $geb = $alt['geburtsdatum'];
        } else {
            $errors[] = antrag_tr($lang, 'err_geb');
        }
    }
    if ($errors) {
        antrag_show_form($errors, $alt);
        return;
    }

    $existing = antrag_find_existing($alt['email']);
    if ($existing !== null) {
        $msg = $existing['status'] === 'aktiv' ? antrag_tr($lang, 'dup_aktiv') : antrag_tr($lang, 'dup_antrag');
        antrag_layout($lang, antrag_tr($lang, 'dup_title'),
            '<div class="alert note">' . antrag_e($msg) . '</div>'
            . '<p><a class="btn gray" href="' . antrag_e(ANTRAG_FORUM_URL) . '">' . antrag_e(antrag_tr($lang, 'back_forum')) . '</a></p>');
        return;
    }

    $nlLang = in_array($alt['newsletter'], ANTRAG_LANGS, true) ? $alt['newsletter'] : $lang;
    $user = antrag_current_user();
    $daten = [
        'vorname' => $alt['vorname'], 'nachname' => $alt['nachname'], 'email' => $alt['email'],
        'telefon' => $alt['telefon'] ?: null, 'geburtsdatum' => $geb,
        'hausnummer' => $alt['hausnummer'] ?: null, 'strasse' => $alt['strasse'] ?: null,
        'plz' => $alt['plz'] ?: null, 'ort' => $alt['ort'] ?: null,
        'land' => $alt['land'] ?: ANTRAG_LAND_DEFAULT,
        'wcf_user_id' => $user['userID'] ?? null, 'forum_name' => $user['username'] ?? null,
        'newsletter_sprache' => $nlLang,
        'antragsdatum' => date('Y-m-d'), 'bemerkung' => $alt['bemerkung'] ?: null,
    ];

    try {
        $id = antrag_insert($daten);
    } catch (\Throwable $e) {
        antrag_layout($lang, antrag_tr($lang, 'save_err_title'),
            '<div class="alert err">' . antrag_e(antrag_tr($lang, 'save_err')) . '</div>');
        return;
    }

    // Storno-Link (in der Newsletter-Sprache)
    $stornoUrl = ANTRAG_SELF_URL . '?page=storno&lang=' . $nlLang
               . '&t=' . rawurlencode(antrag_make_token('storno', (string) $id));

    // Bestätigungsmail (Newsletter-Sprache)
    $mail = antrag_mail_inhalt($daten, $stornoUrl, $nlLang);
    $mailResult = antrag_send_mail($daten['email'], trim($daten['vorname'] . ' ' . $daten['nachname']),
        $mail['betreff'], $mail['text'], $mail['html']);

    // Info-Mail an den Vorstand
    if (ANTRAG_KONTAKT_EMAIL !== '') {
        $info = "Neuer Mitgliedsantrag:\n\n" . trim($daten['vorname'] . ' ' . $daten['nachname']) . "\n"
              . $daten['email'] . ($daten['telefon'] ? ' / ' . $daten['telefon'] : '') . "\n"
              . 'Newsletter-Sprache: ' . $nlLang . "\n"
              . 'Eingegangen: ' . date('d.m.Y H:i') . "\n\nIn der Mitgliederverwaltung als Status 'antrag' sichtbar.";
        antrag_send_mail(ANTRAG_KONTAKT_EMAIL, ANTRAG_MAIL_FROM_NAME,
            'Neuer Mitgliedsantrag: ' . trim($daten['vorname'] . ' ' . $daten['nachname']),
            $info, antrag_e($info));
    }

    // Danke-Seite (Formular-Sprache)
    $mailHinweis = $mailResult === true
        ? '<div class="alert ok">' . antrag_e(antrag_tr($lang, 'ty_mail_ok', ['{email}' => $daten['email']])) . '</div>'
        : '<div class="alert note">' . antrag_e(antrag_tr($lang, 'ty_mail_fail')) . '</div>';

    $sumupHtml = ANTRAG_SUMUP_URL !== ''
        ? '<p style="margin-top:16px;">' . antrag_e(antrag_tr($lang, 'pay_alt')) . '</p>'
          . '<p><a class="btn" style="background:#0f766e;" href="' . antrag_e(ANTRAG_SUMUP_URL)
          . '" target="_blank" rel="noopener">' . antrag_e(antrag_tr($lang, 'pay_sumup_btn')) . '</a></p>'
        : '';

    $inhalt = $mailHinweis
        . '<p>' . antrag_e(antrag_tr($lang, 'ty_pay_intro')) . '</p>'
        . antrag_konto_html($daten, $lang)
        . $sumupHtml
        . '<p style="margin-top:10px;">' . antrag_e(antrag_tr($lang, 'ty_after')) . '</p>'
        . '<div class="alert note" style="margin-top:18px;"><strong>' . antrag_e(antrag_tr($lang, 'ty_storno_title')) . '</strong><br>'
        . antrag_e(antrag_tr($lang, 'ty_storno_text')) . '<br>'
        . '<a class="btn gray" style="margin-top:8px;" href="' . antrag_e($stornoUrl) . '">' . antrag_e(antrag_tr($lang, 'storno_btn')) . '</a></div>'
        . '<p style="margin-top:16px;"><a class="btn" href="' . antrag_e(ANTRAG_FORUM_URL) . '">' . antrag_e(antrag_tr($lang, 'back_forum')) . '</a></p>';

    antrag_layout($lang, antrag_tr($lang, 'ty_title'), $inhalt);
}

// ===========================================================================
// Aktion: Antrag zurückziehen (Storno)
// ===========================================================================

function antrag_handle_storno(): void
{
    $lang = antrag_lang();
    $id = antrag_read_token('storno', (string) ($_GET['t'] ?? ''));
    if ($id === null || (int) $id <= 0) {
        antrag_layout($lang, antrag_tr($lang, 'st_invalid_title'),
            '<div class="alert err">' . antrag_e(antrag_tr($lang, 'st_invalid')) . '</div>');
        return;
    }
    $id = (int) $id;

    $stmt = WCF::getDB()->prepareStatement("SELECT vorname, nachname, status FROM mitglieder WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetchArray();
    if (!$row) {
        antrag_layout($lang, antrag_tr($lang, 'st_notfound_title'),
            '<div class="alert note">' . antrag_e(antrag_tr($lang, 'st_notfound')) . '</div>');
        return;
    }
    $name = trim($row['vorname'] . ' ' . $row['nachname']);

    if ($row['status'] !== 'antrag') {
        $txt = $row['status'] === 'zurueckgezogen'
            ? antrag_tr($lang, 'st_already')
            : antrag_tr($lang, 'st_cant', ['{status}' => $row['status']]);
        antrag_layout($lang, antrag_tr($lang, 'st_confirm_title'), '<div class="alert note">' . antrag_e($txt) . '</div>');
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirm'])) {
        $upd = WCF::getDB()->prepareStatement(
            "UPDATE mitglieder SET status='zurueckgezogen', austrittsdatum=?,
                    bemerkung=TRIM(CONCAT(COALESCE(bemerkung,''), ?))
             WHERE id=? AND status='antrag'");
        $upd->execute([date('Y-m-d'), "\nAntrag vom Antragsteller zurückgezogen am " . date('d.m.Y'), $id]);

        antrag_layout($lang, antrag_tr($lang, 'st_done_title'),
            '<div class="alert ok">' . antrag_e(antrag_tr($lang, 'st_done', ['{name}' => $name])) . '</div>'
            . '<p class="consent">' . antrag_e(antrag_tr($lang, 'st_done_note')) . '</p>'
            . '<p style="margin-top:14px;"><a class="btn" href="' . antrag_e(ANTRAG_FORUM_URL) . '">' . antrag_e(antrag_tr($lang, 'back_forum')) . '</a></p>');
        return;
    }

    $tParam = rawurlencode((string) ($_GET['t'] ?? ''));
    antrag_layout($lang, antrag_tr($lang, 'st_confirm_title'),
        '<p>' . antrag_e(antrag_tr($lang, 'st_confirm_q', ['{name}' => $name])) . '</p>'
        . '<form method="post" action="' . antrag_e(ANTRAG_SELF_URL . '?page=storno&lang=' . $lang . '&t=' . $tParam) . '">'
        . '<input type="hidden" name="confirm" value="1">'
        . '<p style="margin-top:14px;"><button class="btn red" type="submit">' . antrag_e(antrag_tr($lang, 'st_yes')) . '</button> '
        . '<a class="btn gray" style="margin-left:8px;" href="' . antrag_e(ANTRAG_FORUM_URL) . '">' . antrag_e(antrag_tr($lang, 'st_cancel')) . '</a></p></form>');
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
    echo "DIAGNOSE-VERSION: 2026-06-26-antrag7\n\n";
    echo "Verein:        " . ANTRAG_VEREIN_NAME . "\n";
    echo "IBAN gesetzt:  " . (strpos(ANTRAG_IBAN, 'x') === false ? 'ja' : 'NEIN – bitte echte IBAN eintragen') . "\n";
    echo "Beitrag:       " . ANTRAG_BEITRAG . "\n";
    echo "Kontakt:       " . ANTRAG_KONTAKT_EMAIL . "\n";
    echo "Secret gesetzt: " . (ANTRAG_SECRET !== 'BITTE-LANGES-ZUFALLSGEHEIMNIS-SETZEN' ? 'ja' : 'NEIN') . "\n";
    echo "Sprachen:      " . implode(', ', ANTRAG_LANGS) . "\n";
    echo "Self-URL:      " . ANTRAG_SELF_URL . "\n\n";

    // Spalte newsletter_sprache vorhanden?
    try {
        WCF::getDB()->prepareStatement("SELECT newsletter_sprache FROM mitglieder LIMIT 1")->execute();
        echo "Spalte newsletter_sprache: vorhanden\n";
    } catch (\Throwable $e) {
        echo "Spalte newsletter_sprache: FEHLT -> sql/alter_mitglieder_newsletter_sprache.sql ausführen\n";
    }

    $sendMethod = defined('MAIL_SEND_METHOD') ? MAIL_SEND_METHOD : '(unbekannt)';
    echo "\n--- WoltLab-Mail ---\n";
    echo "Versandmethode: " . $sendMethod . "\n";
    if ($sendMethod === 'debug') {
        echo "  >> ACHTUNG: 'debug' = Mails werden NUR protokolliert, NICHT versendet!\n";
    }
    echo "Absender:       " . (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '?')
       . (defined('MAIL_FROM_NAME') ? ' (' . MAIL_FROM_NAME . ')' : '') . "\n\n";

    $u = antrag_current_user();
    echo "Eingeloggt als: " . ($u ? $u['username'] . ' <' . $u['email'] . '>' : '(niemand)') . "\n\n";

    if (isset($_GET['mail']) && $u) {
        $r = antrag_send_mail($u['email'], $u['username'], 'AFOL Test-Mail (Mitgliedsantrag)',
            "Test-Mail des Mitgliedsantrag-Skripts.", '<p>Test-Mail.</p>');
        echo "Test-Mail (WoltLab) an " . $u['email'] . ": " . ($r === true ? 'gesendet (Posteingang/Spam prüfen)' : 'FEHLER: ' . $r) . "\n";
    } else {
        echo "Test-Mail: mitgliedsantrag.php?page=test&mail=1\n";
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

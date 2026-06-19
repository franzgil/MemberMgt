-- =============================================================
-- AFOL.lu Mitgliederverwaltung – Datenimport aus den Bestandstabellen
--
-- Quelle (gleiche Datenbank wie WoltLab):
--   * gf_membres            -> Mitglieder (Stammdaten + Jahresbeiträge)
-- Online-Anträge (wcf1_form_response, formID 3) werden NICHT hier importiert,
-- sondern live im App-Modul „Anträge" verwaltet (siehe Abschnitt 3).
-- Ziel:
--   * mitglieder, beitraege  (vorher mit schema.sql angelegt)
--
-- VORAUSSETZUNG: MySQL 5.7+ / MariaDB 10.2+ (JSON-Funktionen).
-- Dieses Skript ist wiederholbar gedacht: es LEERT zuerst die Zieltabellen
-- (entfernt damit auch die Beispieldaten aus seed.sql) und importiert neu.
--
-- Spalten-Deutung (aus Bestandsanalyse):
--   No      = fortlaufende Mitgliedsnummer        -> mitgliedsnummer
--   status  = Bearbeitungsstatus ('beantragt' -> antrag, 'accepted'/'' -> aktiv)
--   Membre  = Mitglieds-Typ: Actif -> typ 'aktiv', B (Bienfaiteur) -> typ 'foerder'
-- Unbekannte status-Werte landen auf 'aktiv'.
-- =============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------------
-- 0) Zieltabellen leeren (entfernt Beispiel-/Seed-Daten)
-- ------------------------------------------------------------------
DELETE FROM `beitraege`;
DELETE FROM `mitglieder`;
ALTER TABLE `mitglieder` AUTO_INCREMENT = 1;
ALTER TABLE `beitraege`  AUTO_INCREMENT = 1;

-- ------------------------------------------------------------------
-- 1) Mitglieder aus gf_membres
--    legacy_no = gf_membres.id  (stabiler Schlüssel für den Beitrags-Join)
-- ------------------------------------------------------------------
INSERT INTO `mitglieder`
    (`legacy_no`, `mitgliedsnummer`, `vorname`, `nachname`, `email`, `telefon`,
     `geburtsdatum`, `geburtsort`, `geburtsland`, `matricule`,
     `hausnummer`, `strasse`, `plz`, `ort`, `land`,
     `forum_name`, `status`, `typ`, `karte_ausgestellt`, `quelle`, `vollstaendigkeit`)
SELECT
    g.`id`,
    NULLIF(g.`No`, 0),  /* Mitgliedsnummer = fortlaufende Nummer 'No' */
    COALESCE(NULLIF(TRIM(g.`Prenom`), ''), '?'),
    COALESCE(NULLIF(TRIM(g.`Nom`), ''), '?'),
    NULLIF(TRIM(g.`E-Mail`), ''),
    NULLIF(TRIM(g.`Telephone`), ''),
    g.`Date de naissance`,
    NULLIF(TRIM(g.`Lieu de naissance`), ''),
    NULLIF(TRIM(g.`Pays de naissance`), ''),
    NULLIF(TRIM(g.`Matricule`), ''),
    NULLIF(TRIM(g.`Numero`), ''),
    NULLIF(TRIM(g.`Rue`), ''),
    NULLIF(TRIM(g.`Code postal`), ''),
    NULLIF(TRIM(g.`Localite`), ''),
    COALESCE(NULLIF(TRIM(g.`Pays`), ''), 'Luxembourg'),
    NULLIF(TRIM(g.`Username`), ''),
    /* Lebenszyklus aus gf_membres.status: beantragt -> antrag, sonst aktiv */
    CASE LOWER(TRIM(COALESCE(g.`status`, '')))
        WHEN 'beantragt' THEN 'antrag'
        WHEN 'accepted'  THEN 'aktiv'
        WHEN 'declined'  THEN 'abgelehnt'
        WHEN 'refuse'    THEN 'abgelehnt'
        WHEN ''          THEN 'aktiv'
        ELSE 'aktiv'
    END,
    /* Mitglieds-Typ: Actif -> aktiv, B (Bienfaiteur) -> foerder */
    CASE LOWER(TRIM(COALESCE(g.`Membre`, '')))
        WHEN 'b'              THEN 'foerder'
        WHEN 'foerder'        THEN 'foerder'
        WHEN 'foerdermitglied' THEN 'foerder'
        ELSE 'aktiv'
    END,
    CASE WHEN LOWER(COALESCE(g.`cartemembre_delivre`, '')) IN ('1','y','o','j','x','oui','yes')
         THEN 1 ELSE 0 END,
    'gf_membres',
    ROUND((
        (COALESCE(TRIM(g.`Prenom`), '') <> '') +
        (COALESCE(TRIM(g.`Nom`), '') <> '') +
        (COALESCE(TRIM(g.`E-Mail`), '') <> '') +
        (COALESCE(TRIM(g.`Telephone`), '') <> '') +
        (g.`Date de naissance` IS NOT NULL) +
        (COALESCE(TRIM(g.`Rue`), '') <> '') +
        (COALESCE(TRIM(g.`Code postal`), '') <> '') +
        (COALESCE(TRIM(g.`Localite`), '') <> '') +
        (COALESCE(TRIM(g.`Pays`), '') <> '') +
        (COALESCE(TRIM(g.`Username`), '') <> '')
    ) / 10 * 100)
FROM `gf_membres` g;

-- ------------------------------------------------------------------
-- 1b) WoltLab-Konto verknüpfen: wcf_user_id über die E-Mail setzen.
--     Nötig u. a. für den Mitgliedskarten-Button (braucht die WoltLab-userID)
--     und die Namensauflösung. UPDATE IGNORE überspringt etwaige Dubletten,
--     ohne den UNIQUE-Schlüssel uq_wcf_user_id zu verletzen.
-- ------------------------------------------------------------------
UPDATE IGNORE `mitglieder` m
JOIN `wcf1_user` u
  ON CONVERT(LOWER(TRIM(u.`email`)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
   = CONVERT(LOWER(TRIM(m.`email`)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
SET m.`wcf_user_id` = u.`userID`
WHERE m.`wcf_user_id` IS NULL
  AND TRIM(COALESCE(m.`email`, '')) <> '';

-- ------------------------------------------------------------------
-- 2) Jahresbeiträge aus den Cot-Spalten (nur wenn Betrag > 0)
--    bezahlt_am = Date_de_payement_<Jahr> (Fallback: 1. Januar des Jahres)
--    art        = moyen_de_payement_<Jahr> (cash/virement/payconiq/sumup)
--    Jahre 2024–2029. Voraussetzung: gf_membres hat diese Spalten (siehe
--    sql/alter_gf_membres_zahlungen*.sql).
-- ------------------------------------------------------------------
INSERT INTO `beitraege` (`mitglied_id`, `jahr`, `betrag`, `ist_komitee`, `bezahlt_am`, `art`)
SELECT m.`id`, 2024, g.`Cot 2024`, 0,
       COALESCE(g.`Date_de_payement_2024`, MAKEDATE(2024, 1)), g.`moyen_de_payement_2024`
FROM `gf_membres` g
JOIN `mitglieder` m ON m.`quelle` = 'gf_membres' AND m.`legacy_no` = g.`id`
WHERE g.`Cot 2024` IS NOT NULL AND g.`Cot 2024` > 0;

INSERT INTO `beitraege` (`mitglied_id`, `jahr`, `betrag`, `ist_komitee`, `bezahlt_am`, `art`)
SELECT m.`id`, 2025, g.`Cot 2025`, 0,
       COALESCE(g.`Date_de_payement_2025`, MAKEDATE(2025, 1)), g.`moyen_de_payement_2025`
FROM `gf_membres` g
JOIN `mitglieder` m ON m.`quelle` = 'gf_membres' AND m.`legacy_no` = g.`id`
WHERE g.`Cot 2025` IS NOT NULL AND g.`Cot 2025` > 0;

INSERT INTO `beitraege` (`mitglied_id`, `jahr`, `betrag`, `ist_komitee`, `bezahlt_am`, `art`)
SELECT m.`id`, 2026, g.`Cot 2026`, 0,
       COALESCE(g.`Date_de_payement_2026`, MAKEDATE(2026, 1)), g.`moyen_de_payement_2026`
FROM `gf_membres` g
JOIN `mitglieder` m ON m.`quelle` = 'gf_membres' AND m.`legacy_no` = g.`id`
WHERE g.`Cot 2026` IS NOT NULL AND g.`Cot 2026` > 0;

INSERT INTO `beitraege` (`mitglied_id`, `jahr`, `betrag`, `ist_komitee`, `bezahlt_am`, `art`)
SELECT m.`id`, 2027, g.`Cot 2027`, 0,
       COALESCE(g.`Date_de_payement_2027`, MAKEDATE(2027, 1)), g.`moyen_de_payement_2027`
FROM `gf_membres` g
JOIN `mitglieder` m ON m.`quelle` = 'gf_membres' AND m.`legacy_no` = g.`id`
WHERE g.`Cot 2027` IS NOT NULL AND g.`Cot 2027` > 0;

INSERT INTO `beitraege` (`mitglied_id`, `jahr`, `betrag`, `ist_komitee`, `bezahlt_am`, `art`)
SELECT m.`id`, 2028, g.`Cot 2028`, 0,
       COALESCE(g.`Date_de_payement_2028`, MAKEDATE(2028, 1)), g.`moyen_de_payement_2028`
FROM `gf_membres` g
JOIN `mitglieder` m ON m.`quelle` = 'gf_membres' AND m.`legacy_no` = g.`id`
WHERE g.`Cot 2028` IS NOT NULL AND g.`Cot 2028` > 0;

INSERT INTO `beitraege` (`mitglied_id`, `jahr`, `betrag`, `ist_komitee`, `bezahlt_am`, `art`)
SELECT m.`id`, 2029, g.`Cot 2029`, 0,
       COALESCE(g.`Date_de_payement_2029`, MAKEDATE(2029, 1)), g.`moyen_de_payement_2029`
FROM `gf_membres` g
JOIN `mitglieder` m ON m.`quelle` = 'gf_membres' AND m.`legacy_no` = g.`id`
WHERE g.`Cot 2029` IS NOT NULL AND g.`Cot 2029` > 0;

-- ------------------------------------------------------------------
-- 3) Online-Anträge  ->  jetzt im App-Modul „Anträge"
-- ------------------------------------------------------------------
-- Die Online-Anträge aus wcf1_form_response (formID 3) werden NICHT mehr per
-- SQL importiert, sondern LIVE im App-Modul „Anträge" angezeigt und einzeln
-- (oder gesammelt) in die Mitgliederverwaltung übernommen.
--   Vorteil: PHP parst das WoltLab-JSON zuverlässig (Emoji/Surrogate-Escapes,
--   Steuerzeichen) – unabhängig von der JSON-Strenge der DB-Version – und die
--   Anträge bleiben einsehbar und verwaltbar statt nur einmalig importiert.
-- Siehe: app/Models/Antrag.php, app/Controllers/AntraegeController.php

-- ------------------------------------------------------------------
-- 4) Kontrolle / Hilfsabfragen (nach dem Import einzeln ausführen)
-- ------------------------------------------------------------------
-- Vorkommende Werte zum Anpassen des CASE oben (bitte mir schicken):
--   SELECT `Membre`, COUNT(*) FROM gf_membres GROUP BY `Membre`;
--   SELECT `status`, COUNT(*) FROM gf_membres GROUP BY `status`;
-- Ergebnis prüfen:
--   SELECT status, COUNT(*) FROM mitglieder GROUP BY status;
--   SELECT COUNT(*) AS antraege FROM mitglieder WHERE quelle = 'woltlab_form';
-- Übersprungene Antworten mit ungültigem/leerem JSON (formID 3):
--   SELECT COUNT(*) FROM wcf1_form_response WHERE formID=3 AND NOT JSON_VALID(`fields`);
--   SELECT * FROM beitraege ORDER BY mitglied_id, jahr LIMIT 50;

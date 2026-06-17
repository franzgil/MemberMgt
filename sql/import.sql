-- =============================================================
-- AFOL.lu Mitgliederverwaltung – Datenimport aus den Bestandstabellen
--
-- Quelle (gleiche Datenbank wie WoltLab):
--   * gf_membres            -> Mitglieder (Stammdaten + Jahresbeiträge)
--   * wcf1_form_response    -> Online-Anträge (formID = 3)
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
-- 2) Jahresbeiträge aus den Cot-Spalten (nur wenn Betrag > 0)
--    bezahlt_am = 1. Januar des jeweiligen Jahres (Bestand gilt als bezahlt)
-- ------------------------------------------------------------------
INSERT INTO `beitraege` (`mitglied_id`, `jahr`, `betrag`, `ist_komitee`, `bezahlt_am`)
SELECT m.`id`, 2024, g.`Cot 2024`, 0, MAKEDATE(2024, 1)
FROM `gf_membres` g
JOIN `mitglieder` m ON m.`quelle` = 'gf_membres' AND m.`legacy_no` = g.`id`
WHERE g.`Cot 2024` IS NOT NULL AND g.`Cot 2024` > 0;

INSERT INTO `beitraege` (`mitglied_id`, `jahr`, `betrag`, `ist_komitee`, `bezahlt_am`)
SELECT m.`id`, 2025, g.`Cot 2025`, 0, MAKEDATE(2025, 1)
FROM `gf_membres` g
JOIN `mitglieder` m ON m.`quelle` = 'gf_membres' AND m.`legacy_no` = g.`id`
WHERE g.`Cot 2025` IS NOT NULL AND g.`Cot 2025` > 0;

INSERT INTO `beitraege` (`mitglied_id`, `jahr`, `betrag`, `ist_komitee`, `bezahlt_am`)
SELECT m.`id`, 2026, g.`Cot 2026`, 0, MAKEDATE(2026, 1)
FROM `gf_membres` g
JOIN `mitglieder` m ON m.`quelle` = 'gf_membres' AND m.`legacy_no` = g.`id`
WHERE g.`Cot 2026` IS NOT NULL AND g.`Cot 2026` > 0;

-- ------------------------------------------------------------------
-- 3) Online-Anträge aus wcf1_form_response (formID = 3)
--    Echtes Feld-Schema des Mitgliedsantrags:
--      22=Nachname 23=Vorname 24=Geburtsdatum 25=Sprachen(Array)
--      27=Forenname 28=Straße 29=PLZ 30=Ort 31=Land 32=Telefon
--      33=E-Mail 34=Hausnummer
--    Defektes JSON wird (Zeilenumbrüche entfernt) repariert; bleibt es
--    ungültig, wird die Zeile übersprungen. Dubletten zu bestehenden
--    Mitgliedern (User-ID / Forenname / E-Mail) werden ausgelassen.
-- ------------------------------------------------------------------
INSERT INTO `mitglieder`
    (`vorname`, `nachname`, `email`, `telefon`, `geburtsdatum`,
     `hausnummer`, `strasse`, `plz`, `ort`, `land`,
     `wcf_user_id`, `forum_name`,
     `status`, `antragsart`, `antragsdatum`, `quelle`, `bemerkung`, `vollstaendigkeit`)
SELECT
    COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."23"'))), ''), '?'),
    COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."22"'))), ''), '?'),
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."33"'))), ''),
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."32"'))), ''),
    CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."24"')) REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
         THEN JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."24"')) ELSE NULL END,
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."34"'))), ''),
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."28"'))), ''),
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."29"'))), ''),
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."30"'))), ''),
    COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."31"'))), ''), 'Luxembourg'),
    NULLIF(r.`userID`, 0),
    COALESCE(NULLIF(TRIM(r.`username`), ''),
             NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."27"'))), '')),
    'antrag',
    'online',
    DATE(FROM_UNIXTIME(r.`time`)),
    'woltlab_form',
    CASE WHEN JSON_EXTRACT(r.`fields`, '$."25"') IS NOT NULL
         THEN CONCAT('Sprachen: ', JSON_EXTRACT(r.`fields`, '$."25"')) ELSE NULL END,
    ROUND((
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."23"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."22"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."33"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."32"'))), '') <> '') +
        (JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."24"')) IS NOT NULL) +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."28"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."29"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."30"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."31"'))), '') <> '') +
        (COALESCE(TRIM(r.`username`), '') <> '')
    ) / 10 * 100)
FROM (
        /* `fields` wird repariert: ALLE Steuerzeichen raus, dann validiert.
           Ungültiges JSON -> '{}' (JSON_EXTRACT trifft nie auf defektes JSON,
           auch bei derived_merge). `is_valid` filtert leere Datensätze heraus. */
        SELECT `responseID`, `userID`, `username`, `time`,
               JSON_VALID(`clean`) AS `is_valid`,
               IF(JSON_VALID(`clean`), `clean`, '{}') AS `fields`
        FROM (
            SELECT `responseID`, `userID`, `username`, `time`,
                   REGEXP_REPLACE(`fields`, '[[:cntrl:]]', ' ') AS `clean`
            FROM `wcf1_form_response`
            WHERE `formID` = 3
        ) x
     ) r
WHERE r.`is_valid` = 1
  AND NOT EXISTS (
        SELECT 1 FROM `mitglieder` m
        WHERE (m.`wcf_user_id` IS NOT NULL AND m.`wcf_user_id` = r.`userID`)
           OR (m.`forum_name` IS NOT NULL AND TRIM(r.`username`) <> ''
               AND m.`forum_name` =
                   CONVERT(TRIM(r.`username`) USING utf8mb4) COLLATE utf8mb4_unicode_ci)
           OR (m.`email` IS NOT NULL
               AND m.`email` =
                   CONVERT(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."33"'))) USING utf8mb4)
                   COLLATE utf8mb4_unicode_ci)
  );

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

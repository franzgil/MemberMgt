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
--    Nur Personen, die noch NICHT als Mitglied existieren
--    (Abgleich über WoltLab-User-ID bzw. E-Mail).
--    Feld-Mapping: 39=Vorname 40=Nachname 41=Adresse 42=PLZ 43=Ort
--                  44=Land 45=Telefon 46=E-Mail
-- ------------------------------------------------------------------
INSERT INTO `mitglieder`
    (`vorname`, `nachname`, `email`, `telefon`,
     `strasse`, `plz`, `ort`, `land`,
     `wcf_user_id`, `forum_name`,
     `status`, `antragsart`, `antragsdatum`, `quelle`, `vollstaendigkeit`)
SELECT
    COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."39"'))), ''), '?'),
    COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."40"'))), ''), '?'),
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."46"'))), ''),
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."45"'))), ''),
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."41"'))), ''),
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."42"'))), ''),
    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."43"'))), ''),
    COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."44"'))), ''), 'Luxembourg'),
    NULLIF(r.`userID`, 0),
    NULLIF(TRIM(r.`username`), ''),
    'antrag',
    'online',
    DATE(FROM_UNIXTIME(r.`time`)),
    'woltlab_form',
    ROUND((
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."39"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."40"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."46"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."45"'))), '') <> '') +
        0 + /* Geburtsdatum nicht im Formular */
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."41"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."42"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."43"'))), '') <> '') +
        (COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."44"'))), '') <> '') +
        (COALESCE(TRIM(r.`username`), '') <> '')
    ) / 10 * 100)
FROM (
        /* Nur Antworten mit GÜLTIGEM JSON verarbeiten – defekte/leere
           `fields` (z. B. Altdaten) würden JSON_EXTRACT sonst abbrechen (#4038). */
        SELECT * FROM `wcf1_form_response`
        WHERE `formID` = 3 AND JSON_VALID(`fields`)
     ) r
WHERE NOT EXISTS (
        SELECT 1 FROM `mitglieder` m
        WHERE (m.`wcf_user_id` IS NOT NULL AND m.`wcf_user_id` = r.`userID`)
           OR (m.`forum_name` IS NOT NULL AND TRIM(r.`username`) <> ''
               AND m.`forum_name` =
                   CONVERT(TRIM(r.`username`) USING utf8mb4) COLLATE utf8mb4_unicode_ci)
           OR (m.`email` IS NOT NULL
               AND m.`email` =
                   CONVERT(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.`fields`, '$."46"'))) USING utf8mb4)
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

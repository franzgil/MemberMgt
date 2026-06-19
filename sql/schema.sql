-- =============================================================
-- AFOL.lu Mitgliederverwaltung – Datenbankschema
-- MySQL 5.7+ / MariaDB, utf8mb4
-- Stand: 2026-06-17
--
-- Entscheidungen:
--  * Bereinigtes Schema (klare Namen) statt direkter gf_membres-Nutzung
--  * Bestandsdaten werden aus gf_membres einmalig migriert (s. migration.sql)
--  * Jahresbeiträge normalisiert in eigener Tabelle `beitraege`
--    (keine Cot-2024/2025/2026-Spalten mehr)
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- Tabelle: mitglieder  (zentraler Mitgliederbestand / Anträge)
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `mitglieder`;
CREATE TABLE `mitglieder` (
  `id`               INT(11)        NOT NULL AUTO_INCREMENT,

  -- Identifikation
  `legacy_no`        INT(11)        DEFAULT NULL COMMENT 'alte gf_membres.No',
  `mitgliedsnummer`  VARCHAR(6)     DEFAULT NULL COMMENT 'gf_membres.Membre',

  -- Person
  `vorname`          VARCHAR(50)    NOT NULL,
  `nachname`         VARCHAR(50)    NOT NULL,
  `email`            VARCHAR(150)   DEFAULT NULL,
  `telefon`          VARCHAR(30)    DEFAULT NULL,
  `geburtsdatum`     DATE           DEFAULT NULL,
  `geburtsort`       VARCHAR(50)    DEFAULT NULL,
  `geburtsland`      VARCHAR(50)    DEFAULT NULL,
  `matricule`        VARCHAR(13)    DEFAULT NULL COMMENT 'LU-Personenkennziffer (sensibel)',

  -- Adresse (getrennt wie in gf_membres)
  `hausnummer`       VARCHAR(10)    DEFAULT NULL,
  `strasse`          VARCHAR(100)   DEFAULT NULL,
  `plz`              VARCHAR(11)    DEFAULT NULL,
  `ort`              VARCHAR(50)    DEFAULT NULL,
  `land`             VARCHAR(50)    DEFAULT 'Luxembourg',

  -- WoltLab-Verknüpfung
  `wcf_user_id`      INT(11)        DEFAULT NULL COMMENT 'wcf1_user.userID',
  `forum_name`       VARCHAR(50)    DEFAULT NULL COMMENT 'gf_membres.Username / WoltLab username',

  -- Mitgliedschafts-Lebenszyklus
  `status`           ENUM('antrag','aktiv','pausiert','inaktiv','ausgetreten','abgelehnt')
                                    NOT NULL DEFAULT 'antrag',
  `typ`              ENUM('aktiv','foerder') NOT NULL DEFAULT 'aktiv'
                                    COMMENT 'Aktives Mitglied vs Foerdermitglied (gf_membres.Membre)',
  `antragsart`       ENUM('online','papier') DEFAULT NULL,
  `antragsdatum`     DATE           DEFAULT NULL,
  `beitrittsdatum`   DATE           DEFAULT NULL COMMENT 'gesetzt bei erster Beitragsbestätigung',
  `austrittsdatum`   DATE           DEFAULT NULL,
  `karte_ausgestellt` TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'gf_membres.cartemembre_delivre',

  -- Datenherkunft / Qualität
  `quelle`           VARCHAR(50)    DEFAULT NULL COMMENT 'z.B. gf_membres, woltlab_form, papier',
  `vollstaendigkeit` TINYINT        DEFAULT NULL COMMENT 'berechneter Vollständigkeits-% (Cache)',
  `bemerkung`        TEXT           DEFAULT NULL,

  `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mitgliedsnummer` (`mitgliedsnummer`),
  UNIQUE KEY `uq_wcf_user_id` (`wcf_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_nachname` (`nachname`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Tabelle: beitraege  (Jahresbeiträge / Cotisation, normalisiert)
--   Ersetzt die Spalten Cot 2024/2025/2026 + Cot Comite.
--   Ein bezahlter Beitrag fürs laufende Jahr = aktives Mitglied.
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `beitraege`;
CREATE TABLE `beitraege` (
  `id`               INT(11)        NOT NULL AUTO_INCREMENT,
  `mitglied_id`      INT(11)        NOT NULL,
  `jahr`             SMALLINT(4)    NOT NULL COMMENT 'Beitragsjahr, z.B. 2026',
  `betrag`           DECIMAL(7,2)   DEFAULT NULL COMMENT 'Betrag in EUR (Einheit final zu klären)',
  `ist_komitee`      TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'aus gf_membres.Cot Comite (zu bestätigen)',
  `art`              ENUM('cash','virement','payconiq','sumup') DEFAULT NULL,
  `bezahlt_am`       DATE           DEFAULT NULL,
  `bestaetigt_durch` INT(11)        DEFAULT NULL COMMENT 'wcf_user_id des Trésorier',
  `bemerkung`        VARCHAR(255)   DEFAULT NULL,
  `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mitglied_jahr` (`mitglied_id`, `jahr`),
  KEY `idx_jahr` (`jahr`),
  CONSTRAINT `fk_beitrag_mitglied` FOREIGN KEY (`mitglied_id`)
      REFERENCES `mitglieder` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- Offene Punkte (vor Produktivbetrieb klären):
--  * gf_membres.status: welche Werte? -> Mapping auf ENUM oben.
--  * Cot-Beträge: Einheit (EUR/Cent) und Bedeutung von 'Cot Comite'.
--  * Pflichtfelder für 'aktiv' (z.B. wcf_user_id verpflichtend).
-- =============================================================

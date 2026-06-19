-- ------------------------------------------------------------------
-- gf_membres: Jahre 2027, 2028, 2029 ergänzen (nicht-destruktiv)
-- ------------------------------------------------------------------
-- Fügt je Jahr drei Spalten hinzu – passend zum bestehenden Muster:
--   Cot <Jahr>              (Beitrag, INT)
--   Date_de_payement_<Jahr> (Zahlungsdatum, DATE)
--   moyen_de_payement_<Jahr> ENUM(cash, virement, payconiq, sumup)
-- Voraussetzung: das Skript für 2024–2026 wurde bereits ausgeführt.
-- In phpMyAdmin die WoltLab-Datenbank wählen und EINMAL ausführen.
-- ------------------------------------------------------------------

ALTER TABLE `gf_membres`
    ADD COLUMN `Cot 2027`               INT(8) NULL DEFAULT NULL
        AFTER `moyen_de_payement_2026`,
    ADD COLUMN `Date_de_payement_2027`  DATE NULL DEFAULT NULL
        AFTER `Cot 2027`,
    ADD COLUMN `moyen_de_payement_2027` ENUM('cash','virement','payconiq','sumup') NULL DEFAULT NULL
        AFTER `Date_de_payement_2027`,

    ADD COLUMN `Cot 2028`               INT(8) NULL DEFAULT NULL
        AFTER `moyen_de_payement_2027`,
    ADD COLUMN `Date_de_payement_2028`  DATE NULL DEFAULT NULL
        AFTER `Cot 2028`,
    ADD COLUMN `moyen_de_payement_2028` ENUM('cash','virement','payconiq','sumup') NULL DEFAULT NULL
        AFTER `Date_de_payement_2028`,

    ADD COLUMN `Cot 2029`               INT(8) NULL DEFAULT NULL
        AFTER `moyen_de_payement_2028`,
    ADD COLUMN `Date_de_payement_2029`  DATE NULL DEFAULT NULL
        AFTER `Cot 2029`,
    ADD COLUMN `moyen_de_payement_2029` ENUM('cash','virement','payconiq','sumup') NULL DEFAULT NULL
        AFTER `Date_de_payement_2029`;

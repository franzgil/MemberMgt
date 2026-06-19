-- ------------------------------------------------------------------
-- gf_membres: Zahlungsdatum + Zahlungsmittel je Jahr ergänzen
-- ------------------------------------------------------------------
-- Nicht-destruktiv: fügt nur Spalten hinzu, bestehende Daten bleiben erhalten.
-- In phpMyAdmin die WoltLab-Datenbank wählen und EINMAL ausführen.
--
-- Zahlungsmittel: cash, virement (Überweisung), payconiq, sumup
-- (Schreibweise korrigiert: payconic -> payconiq, sumo -> sumup)
-- ------------------------------------------------------------------

ALTER TABLE `gf_membres`
    ADD COLUMN `Date_de_payement_2024`  DATE NULL DEFAULT NULL
        AFTER `Cot 2024`,
    ADD COLUMN `moyen_de_payement_2024` ENUM('cash','virement','payconiq','sumup') NULL DEFAULT NULL
        AFTER `Date_de_payement_2024`,

    ADD COLUMN `Date_de_payement_2025`  DATE NULL DEFAULT NULL
        AFTER `Cot 2025`,
    ADD COLUMN `moyen_de_payement_2025` ENUM('cash','virement','payconiq','sumup') NULL DEFAULT NULL
        AFTER `Date_de_payement_2025`,

    ADD COLUMN `Date_de_payement_2026`  DATE NULL DEFAULT NULL
        AFTER `Cot 2026`,
    ADD COLUMN `moyen_de_payement_2026` ENUM('cash','virement','payconiq','sumup') NULL DEFAULT NULL
        AFTER `Date_de_payement_2026`;

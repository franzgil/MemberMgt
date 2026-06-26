-- =============================================================
-- Spalte newsletter_sprache ergänzen
-- Bevorzugte Sprache für den Newsletter (aus dem Mitgliedsantrag):
--   lb = Lëtzebuergesch, de = Deutsch, fr = Français, en = English
-- Einmalig ausführen.
-- =============================================================

ALTER TABLE `mitglieder`
  ADD COLUMN `newsletter_sprache` ENUM('lb','de','fr','en') DEFAULT NULL
  COMMENT 'Bevorzugte Newsletter-Sprache (Mitgliedsantrag)'
  AFTER `bemerkung`;

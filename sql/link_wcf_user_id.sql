-- ------------------------------------------------------------------
-- WoltLab-Konten mit Mitgliedern verknüpfen (wcf_user_id setzen)
-- ------------------------------------------------------------------
-- Verknüpft über die E-Mail (case-insensitiv, kollationssicher). Danach
-- erscheinen die WoltLab-IDs in der Liste und der Mitgliedskarten-Button.
-- Gefahrlos wiederholbar: setzt nur noch leere wcf_user_id; UPDATE IGNORE
-- überspringt Dubletten, ohne den UNIQUE-Schlüssel zu verletzen.
-- In phpMyAdmin in der WoltLab-Datenbank ausführen.
-- ------------------------------------------------------------------

UPDATE IGNORE `mitglieder` m
JOIN `wcf1_user` u
  ON CONVERT(LOWER(TRIM(u.`email`)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
   = CONVERT(LOWER(TRIM(m.`email`)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
SET m.`wcf_user_id` = u.`userID`
WHERE m.`wcf_user_id` IS NULL
  AND TRIM(COALESCE(m.`email`, '')) <> '';

-- Kontrolle: wie viele sind jetzt verknüpft?
--   SELECT COUNT(*) AS verknuepft FROM mitglieder WHERE wcf_user_id IS NOT NULL;

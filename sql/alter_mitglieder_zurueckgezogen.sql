-- =============================================================
-- Status 'zurueckgezogen' ergänzen
-- Für vom Antragsteller selbst zurückgezogene Online-Anträge
-- (Storno-Link aus mitgliedsantrag.php).
-- Einmalig ausführen.
-- =============================================================

ALTER TABLE `mitglieder`
  MODIFY `status` ENUM('antrag','aktiv','pausiert','inaktiv',
                       'ausgetreten','abgelehnt','zurueckgezogen')
         NOT NULL DEFAULT 'antrag';

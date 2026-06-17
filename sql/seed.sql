-- Beispieldaten zum lokalen Testen
-- Voraussetzung: schema.sql wurde eingespielt.

INSERT INTO `mitglieder`
    (`mitgliedsnummer`, `vorname`, `nachname`, `email`, `telefon`,
     `geburtsdatum`, `hausnummer`, `strasse`, `plz`, `ort`, `land`,
     `forum_name`, `status`, `typ`, `antragsart`, `antragsdatum`, `beitrittsdatum`,
     `quelle`, `vollstaendigkeit`)
VALUES
    ('1', 'Chantal', 'Welfringer', 'brixembourg@example.lu', '00352691199916',
     '1980-05-12', '49', 'rue Michel Gehrend', '1619', 'Luxembourg', 'Luxembourg',
     'brixembourg', 'aktiv', 'aktiv', 'online', '2024-02-01', '2024-02-15',
     'woltlab_form', 100),
    ('2', 'Marco', 'Schmit', 'marco.schmit@example.lu', '00352621000000',
     '1992-09-30', '7', 'Grand-Rue', '1660', 'Luxembourg', 'Luxembourg',
     'mschmit', 'aktiv', 'foerder', 'papier', '2024-05-20', '2024-06-01',
     'gf_membres', 80),
    ('3', 'Anne', 'Muller', 'anne.muller@example.lu', NULL,
     NULL, '12', 'Avenue de la Gare', '1611', 'Esch-sur-Alzette', 'Luxembourg',
     NULL, 'antrag', 'aktiv', 'online', '2026-06-01', NULL,
     'woltlab_form', 50);

INSERT INTO `beitraege` (`mitglied_id`, `jahr`, `betrag`, `art`, `bezahlt_am`)
VALUES
    (1, 2024, 25.00, 'ueberweisung', '2024-02-15'),
    (1, 2025, 25.00, 'bar', '2025-01-20'),
    (1, 2026, 25.00, 'ueberweisung', '2026-01-10');

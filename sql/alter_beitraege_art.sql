-- ------------------------------------------------------------------
-- beitraege.art auf die vier Zahlungsmittel umstellen (cash/virement/
-- payconiq/sumup) – passend zu gf_membres.moyen_de_payement.
-- ------------------------------------------------------------------
-- Nur nötig, wenn die DB schon mit dem alten Schema ('ueberweisung','bar')
-- angelegt wurde. Bei einer frischen Installation reicht schema.sql.
-- Nicht-destruktiv: bestehende Werte werden zuerst übersetzt.
-- ------------------------------------------------------------------

-- 1) ENUM vorübergehend erweitern (alte + neue Werte), damit nichts verloren geht
ALTER TABLE `beitraege`
    MODIFY `art` ENUM('ueberweisung','bar','cash','virement','payconiq','sumup') DEFAULT NULL;

-- 2) Alte Werte übersetzen: bar -> cash, ueberweisung -> virement
UPDATE `beitraege` SET `art` = 'cash'     WHERE `art` = 'bar';
UPDATE `beitraege` SET `art` = 'virement' WHERE `art` = 'ueberweisung';

-- 3) ENUM auf die endgültigen vier Werte festlegen
ALTER TABLE `beitraege`
    MODIFY `art` ENUM('cash','virement','payconiq','sumup') DEFAULT NULL;

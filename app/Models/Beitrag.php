<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Jahresbeiträge (Cotisation). Die Bestätigung durch den Trésorier
 * entspricht einem bestätigten Beitragseintrag fürs jeweilige Jahr.
 */
class Beitrag extends Model
{
    public const ARTEN = ['ueberweisung', 'bar'];

    /** Alle Beiträge eines Mitglieds (neueste zuerst). */
    public function forMitglied(int $mitgliedId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM beitraege WHERE mitglied_id = :id ORDER BY jahr DESC'
        );
        $stmt->execute(['id' => $mitgliedId]);
        return $stmt->fetchAll();
    }

    public function paidForYear(int $mitgliedId, int $jahr): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM beitraege WHERE mitglied_id = :id AND jahr = :j AND bezahlt_am IS NOT NULL'
        );
        $stmt->execute(['id' => $mitgliedId, 'j' => $jahr]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Beitrag fürs Jahr bestätigen (Upsert). Trésorier-Aktion.
     */
    public function confirm(int $mitgliedId, int $jahr, ?float $betrag, string $art, ?int $bestaetigtDurch): void
    {
        if (!in_array($art, self::ARTEN, true)) {
            $art = 'ueberweisung';
        }
        $sql = 'INSERT INTO beitraege (mitglied_id, jahr, betrag, art, bezahlt_am, bestaetigt_durch)
                VALUES (:id, :jahr, :betrag, :art, CURDATE(), :durch)
                ON DUPLICATE KEY UPDATE
                    betrag = VALUES(betrag),
                    art = VALUES(art),
                    bezahlt_am = CURDATE(),
                    bestaetigt_durch = VALUES(bestaetigt_durch)';
        $this->db->prepare($sql)->execute([
            'id'     => $mitgliedId,
            'jahr'   => $jahr,
            'betrag' => $betrag,
            'art'    => $art,
            'durch'  => $bestaetigtDurch,
        ]);
    }

    /** Anzahl Mitglieder mit bezahltem Beitrag im Jahr. */
    public function countPaid(int $jahr): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT mitglied_id) FROM beitraege WHERE jahr = :j AND bezahlt_am IS NOT NULL'
        );
        $stmt->execute(['j' => $jahr]);
        return (int) $stmt->fetchColumn();
    }
}

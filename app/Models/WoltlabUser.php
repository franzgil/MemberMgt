<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use Throwable;

/**
 * Liest Benutzernamen direkt aus der WoltLab-Tabelle wcf1_user
 * (gleiche Datenbank). Dient z. B. der Anzeige „bestätigt durch".
 */
class WoltlabUser extends Model
{
    /**
     * Namen zu mehreren WoltLab-User-IDs.
     * @param int[] $ids
     * @return array<int,string>  userID => username (leer, falls Tabelle fehlt)
     */
    public function namesById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        try {
            $platzhalter = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare(
                "SELECT userID, username FROM wcf1_user WHERE userID IN ($platzhalter)"
            );
            $stmt->execute($ids);
            $map = [];
            foreach ($stmt->fetchAll() as $r) {
                $map[(int) $r['userID']] = (string) $r['username'];
            }
            return $map;
        } catch (Throwable $e) {
            return []; // WoltLab-Tabelle nicht erreichbar (z. B. lokale Test-DB)
        }
    }
}

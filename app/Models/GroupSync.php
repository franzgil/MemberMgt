<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;

/**
 * Hält zwei WoltLab-Benutzergruppen mit dem Mitgliedstyp synchron:
 *   - typ = 'aktiv'   -> Gruppe „Aktive Mitglieder"   (Auth::gruppeAktivId)
 *   - typ = 'foerder' -> Gruppe „Membres Sympathisant" (Auth::gruppeFoerderId)
 *
 * Nur bestätigte Mitglieder (Status 'aktiv') mit verknüpftem WoltLab-Konto
 * (wcf_user_id) kommen in eine Gruppe; alle anderen werden aus BEIDEN entfernt.
 * Andere Gruppen des Nutzers bleiben unberührt.
 */
class GroupSync
{
    /** Ist das WoltLab-Framework verfügbar (App im WoltLab-Kontext gebootet)? */
    public function verfuegbar(): bool
    {
        return class_exists('\wcf\data\user\UserAction') && class_exists('\wcf\system\WCF');
    }

    /** Ziel-Gruppen-ID für ein Mitglied, oder null (in keine der beiden Gruppen). */
    public function zielGruppe(array $m): ?int
    {
        if (($m['status'] ?? '') !== 'aktiv') {
            return null;
        }
        return (($m['typ'] ?? 'aktiv') === 'foerder')
            ? Auth::gruppeFoerderId()
            : Auth::gruppeAktivId();
    }

    /**
     * Ein Mitglied mit seinen Gruppen abgleichen.
     * @return array{changed:bool, ziel:?int, error:?string, skipped:?string}
     */
    public function syncMitglied(array $m): array
    {
        $userId = (int) ($m['wcf_user_id'] ?? 0);
        if ($userId <= 0) {
            return ['changed' => false, 'ziel' => null, 'error' => null, 'skipped' => 'ohne_konto'];
        }
        if (!$this->verfuegbar()) {
            return ['changed' => false, 'ziel' => null, 'error' => 'WoltLab-Framework nicht verfügbar.', 'skipped' => null];
        }

        $aktiv   = Auth::gruppeAktivId();
        $foerder = Auth::gruppeFoerderId();
        $ziel    = $this->zielGruppe($m);
        $current = $this->currentGroups($userId, [$aktiv, $foerder]);

        $add = ($ziel !== null && !in_array($ziel, $current, true)) ? [$ziel] : [];
        $remove = [];
        foreach ([$aktiv, $foerder] as $g) {
            if ($g !== $ziel && in_array($g, $current, true)) {
                $remove[] = $g;
            }
        }
        if (!$add && !$remove) {
            return ['changed' => false, 'ziel' => $ziel, 'error' => null, 'skipped' => null];
        }

        try {
            if ($add) {
                (new \wcf\data\user\UserAction([$userId], 'addToGroups', [
                    'groups' => $add, 'deleteOldGroups' => false, 'addDefaultGroups' => false,
                ]))->executeAction();
            }
            if ($remove) {
                (new \wcf\data\user\UserAction([$userId], 'removeFromGroups', [
                    'groups' => $remove,
                ]))->executeAction();
            }
        } catch (\Throwable $e) {
            return ['changed' => false, 'ziel' => $ziel, 'error' => $e->getMessage(), 'skipped' => null];
        }
        return ['changed' => true, 'ziel' => $ziel, 'error' => null, 'skipped' => null];
    }

    /** Nutzer aus beiden verwalteten Gruppen entfernen (z. B. beim Löschen). */
    public function entferneAlle(int $userId): void
    {
        if ($userId <= 0 || !$this->verfuegbar()) {
            return;
        }
        try {
            (new \wcf\data\user\UserAction([$userId], 'removeFromGroups', [
                'groups' => [Auth::gruppeAktivId(), Auth::gruppeFoerderId()],
            ]))->executeAction();
        } catch (\Throwable $e) {
            // unkritisch
        }
    }

    /**
     * Alle Mitglieder abgleichen.
     * @return array<string,mixed>
     */
    public function syncAll(): array
    {
        $stats = [
            'geprueft' => 0, 'geaendert' => 0, 'nach_aktiv' => 0, 'nach_foerder' => 0,
            'entfernt' => 0, 'ohne_konto' => 0, 'fehler' => 0, 'fehlerListe' => [],
        ];
        if (!$this->verfuegbar()) {
            $stats['fehler'] = 1;
            $stats['fehlerListe'][] = 'WoltLab-Framework nicht verfügbar.';
            return $stats;
        }

        $alle = (new Mitglied())->all([]);
        foreach ($alle as $m) {
            if (empty($m['wcf_user_id'])) {
                $stats['ohne_konto']++;
                continue;
            }
            $stats['geprueft']++;
            $r = $this->syncMitglied($m);
            if ($r['error'] !== null) {
                $stats['fehler']++;
                if (count($stats['fehlerListe']) < 12) {
                    $stats['fehlerListe'][] = trim(($m['nachname'] ?? '') . ' ' . ($m['vorname'] ?? '')) . ': ' . $r['error'];
                }
                continue;
            }
            if ($r['changed']) {
                $stats['geaendert']++;
                if ($r['ziel'] === Auth::gruppeAktivId()) {
                    $stats['nach_aktiv']++;
                } elseif ($r['ziel'] === Auth::gruppeFoerderId()) {
                    $stats['nach_foerder']++;
                } else {
                    $stats['entfernt']++;
                }
            }
        }
        return $stats;
    }

    /** Welche der relevanten Gruppen hat der Nutzer aktuell? @return int[] */
    private function currentGroups(int $userId, array $relevant): array
    {
        $relevant = array_values(array_filter(array_map('intval', $relevant)));
        if (!$relevant) {
            return [];
        }
        $in = implode(',', $relevant);
        $out = [];
        try {
            $stmt = \wcf\system\WCF::getDB()->prepareStatement(
                "SELECT groupID FROM wcf1_user_to_group WHERE userID = ? AND groupID IN (" . $in . ")");
            $stmt->execute([$userId]);
            while ($row = $stmt->fetchArray()) {
                $out[] = (int) $row['groupID'];
            }
        } catch (\Throwable $e) {
            // Tabelle evtl. nicht erreichbar -> leere Liste
        }
        return $out;
    }
}

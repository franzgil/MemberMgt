<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Bewertet die Gültigkeit einer Mitgliedschaft.
 *
 * Regeln (AFOL.lu a.s.b.l.):
 *  - Die Mitgliedsausweise werden jährlich zur Generalversammlung (März)
 *    erstellt; das Mitgliedsjahr läuft von GV zu GV.
 *  - Wer im laufenden (angebrochenen) Jahr beitritt, ist auch im Folgejahr
 *    Mitglied → Beitrittsjahr + 1 ist automatisch gedeckt.
 *  - Danach muss die Mitgliedschaft jährlich erneuert werden (= bestätigter
 *    Beitrag fürs jeweilige Jahr).
 */
class Mitgliedschaft
{
    public const GUELTIG    = 'gueltig';
    public const ABGELAUFEN = 'abgelaufen';
    public const UNBEKANNT  = 'unbekannt';
    public const NA         = 'na'; // kein aktives Mitglied → nicht anwendbar

    /** Filterbare Gültigkeits-Werte (für die Mitgliederliste). */
    public const FILTER = [self::GUELTIG, self::ABGELAUFEN, self::UNBEKANNT];

    /** Aktuelles Mitgliedsjahr, am GV-Monat ausgerichtet. */
    public static function aktuellesJahr(?int $jahr = null, ?int $monat = null): int
    {
        $jahr  = $jahr  ?? (int) date('Y');
        $monat = $monat ?? (int) date('n');
        $gv = defined('GV_MONAT') ? (int) GV_MONAT : 3;
        return $monat >= $gv ? $jahr : $jahr - 1;
    }

    /**
     * @param array $m              Mitglied (status, beitrittsdatum, antragsdatum)
     * @param int[] $bezahlteJahre  Jahre mit bestätigtem Beitrag
     * @return array{status:string, bis:?int, jahr:int}
     */
    public static function bewerten(array $m, array $bezahlteJahre = []): array
    {
        $aktuell = self::aktuellesJahr();

        // Nur aktive Mitglieder haben eine laufende Gültigkeit.
        if (($m['status'] ?? '') !== 'aktiv') {
            return ['status' => self::NA, 'bis' => null, 'jahr' => $aktuell];
        }

        // Beitrittsjahr nur aus einem ECHTEN Datum ableiten – nicht aus
        // Zahlungsjahren (Altdaten haben nur Cot-Jahre und kein Beitrittsjahr;
        // ein altes Mitglied sähe sonst wie ein Neuzugang aus).
        $beitrittsjahr = self::jahrAus($m['beitrittsdatum'] ?? null)
            ?? self::jahrAus($m['antragsdatum'] ?? null);

        // Gedeckt bis: Beitrittsjahr + Folgejahr (Gnadenjahr) bzw. letztes
        // bestätigtes Beitragsjahr – je nachdem, was weiter reicht.
        $bis = null;
        if ($beitrittsjahr !== null) {
            $bis = $beitrittsjahr + 1;
        }
        if (count($bezahlteJahre)) {
            $bis = max((int) $bis, max($bezahlteJahre));
        }

        if ($bis === null) {
            return ['status' => self::UNBEKANNT, 'bis' => null, 'jahr' => $aktuell];
        }

        return [
            'status' => $bis >= $aktuell ? self::GUELTIG : self::ABGELAUFEN,
            'bis'    => $bis,
            'jahr'   => $aktuell,
        ];
    }

    private static function jahrAus(?string $datum): ?int
    {
        if (!$datum) {
            return null;
        }
        $ts = strtotime($datum);
        return $ts ? (int) date('Y', $ts) : null;
    }
}

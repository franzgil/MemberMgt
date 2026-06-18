<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use Throwable;

/**
 * Online-Anträge aus dem WoltLab-Formular (wcf1_form_response, formID 3).
 *
 * Liest die Anträge LIVE aus der WoltLab-Tabelle und parst das `fields`-JSON
 * tolerant in PHP (Emoji/Surrogate-Escapes, Steuerzeichen, ungültiges UTF-8) –
 * unabhängig von der JSON-Strenge der MySQL/MariaDB-Version.
 */
class Antrag extends Model
{
    /** Formular-ID des Mitgliedsantrags. */
    public const FORM_ID = 3;

    /** Feld-Mapping (WoltLab fieldID -> interner Schlüssel). */
    private const MAP = [
        '23' => 'vorname',
        '22' => 'nachname',
        '24' => 'geburtsdatum',
        '33' => 'email',
        '32' => 'telefon',
        '34' => 'hausnummer',
        '28' => 'strasse',
        '29' => 'plz',
        '30' => 'ort',
        '31' => 'land',
        '27' => 'forum_feld',
    ];

    /** Ist die WoltLab-Tabelle erreichbar? */
    public function verfuegbar(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM wcf1_form_response LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Anzahl der Online-Anträge (formID 3); 0 wenn WoltLab nicht erreichbar. */
    public function count(): int
    {
        if (!$this->verfuegbar()) {
            return 0;
        }
        return (int) $this->db
            ->query('SELECT COUNT(*) FROM wcf1_form_response WHERE formID = ' . self::FORM_ID)
            ->fetchColumn();
    }

    /** Alle Anträge (neueste zuerst), inkl. Markierung „bereits erfasst". */
    public function all(): array
    {
        $stmt = $this->db->query(
            'SELECT responseID, userID, username, time, fields, isDone
             FROM wcf1_form_response WHERE formID = ' . self::FORM_ID . ' ORDER BY time DESC'
        );
        $antraege = array_map([$this, 'parse'], $stmt->fetchAll());

        // Abgleich mit bestehenden Mitgliedern
        [$users, $emails, $forums] = $this->bestehendeSchluessel();
        foreach ($antraege as &$a) {
            $a['bereits_erfasst'] =
                ($a['wcf_user_id'] && isset($users[$a['wcf_user_id']]))
                || ($a['email'] && isset($emails[mb_strtolower($a['email'])]))
                || ($a['forum_name'] && isset($forums[mb_strtolower($a['forum_name'])]));
        }
        unset($a);

        return $antraege;
    }

    /** Einen Antrag anhand der responseID. */
    public function find(int $responseID): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT responseID, userID, username, time, fields, isDone
             FROM wcf1_form_response WHERE responseID = :id AND formID = ' . self::FORM_ID
        );
        $stmt->execute(['id' => $responseID]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $a = $this->parse($row);
        [$users, $emails, $forums] = $this->bestehendeSchluessel();
        $a['bereits_erfasst'] =
            ($a['wcf_user_id'] && isset($users[$a['wcf_user_id']]))
            || ($a['email'] && isset($emails[mb_strtolower($a['email'])]))
            || ($a['forum_name'] && isset($forums[mb_strtolower($a['forum_name'])]));
        return $a;
    }

    /** Antrag -> Datensatz für die `mitglieder`-Tabelle (Status 'antrag'). */
    public function alsMitgliedDaten(array $a): array
    {
        return [
            'vorname'      => $a['vorname'] ?: '?',
            'nachname'     => $a['nachname'] ?: '?',
            'email'        => $a['email'],
            'telefon'      => $a['telefon'],
            'geburtsdatum' => $a['geburtsdatum'],
            'hausnummer'   => $a['hausnummer'],
            'strasse'      => $a['strasse'],
            'plz'          => $a['plz'],
            'ort'          => $a['ort'],
            'land'         => $a['land'] ?: 'Luxembourg',
            'wcf_user_id'  => $a['wcf_user_id'],
            'forum_name'   => $a['forum_name'],
            'status'       => 'antrag',
            'antragsart'   => 'online',
            'antragsdatum' => $a['antragsdatum'],
            'quelle'       => 'woltlab_form',
            'bemerkung'    => $a['sprachen'] ? ('Sprachen: ' . $a['sprachen']) : null,
        ];
    }

    // ----------------------------------------------------------------

    private function parse(array $row): array
    {
        $data = $this->decode((string) $row['fields']);

        $a = [
            'responseID'   => (int) $row['responseID'],
            'wcf_user_id'  => ((int) $row['userID']) ?: null,
            'forum_name'   => trim((string) $row['username']) ?: null,
            'antragsdatum' => date('Y-m-d', (int) $row['time']),
            'isDone'       => (int) $row['isDone'],
            'lesbar'       => $data !== null,
            'sprachen'     => null,
        ];
        foreach (self::MAP as $fid => $key) {
            $val = $data[$fid] ?? null;
            $a[$key] = is_string($val) ? (trim($val) ?: null) : (is_scalar($val) ? (string) $val : null);
        }

        // Geburtsdatum nur bei gültigem Format übernehmen
        if (!empty($a['geburtsdatum']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['geburtsdatum'])) {
            $a['geburtsdatum'] = null;
        }
        // Forenname: WoltLab-Account, sonst Formularfeld 27
        if (empty($a['forum_name'])) {
            $a['forum_name'] = $a['forum_feld'] ?? null;
        }
        // Sprachen (Mehrfachauswahl, Feld 25)
        if ($data !== null && !empty($data['25']) && is_array($data['25'])) {
            $a['sprachen'] = implode(', ', array_map('strval', $data['25']));
        }

        return $a;
    }

    /** Tolerantes JSON-Decoding (PHP versteht Surrogate-Escapes nativ). */
    private function decode(string $raw): ?array
    {
        $d = json_decode($raw, true);
        if (is_array($d)) {
            return $d;
        }
        // Reparieren: Steuerzeichen entfernen, ungültiges UTF-8 bereinigen
        $clean = preg_replace('/[\x00-\x1F]/u', ' ', $raw);
        if ($clean === null) {
            $clean = preg_replace('/[\x00-\x1F]/', ' ', $raw);
        }
        $clean = mb_convert_encoding((string) $clean, 'UTF-8', 'UTF-8');
        $d = json_decode($clean, true);
        return is_array($d) ? $d : null;
    }

    /** @return array{0: array<int,bool>, 1: array<string,bool>, 2: array<string,bool>} */
    private function bestehendeSchluessel(): array
    {
        $users = $emails = $forums = [];
        $rows = $this->db->query(
            'SELECT wcf_user_id, email, forum_name FROM mitglieder'
        )->fetchAll();
        foreach ($rows as $r) {
            if (!empty($r['wcf_user_id'])) {
                $users[(int) $r['wcf_user_id']] = true;
            }
            if (!empty($r['email'])) {
                $emails[mb_strtolower($r['email'])] = true;
            }
            if (!empty($r['forum_name'])) {
                $forums[mb_strtolower($r['forum_name'])] = true;
            }
        }
        return [$users, $emails, $forums];
    }
}

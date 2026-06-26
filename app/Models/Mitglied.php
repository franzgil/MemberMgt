<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDO;

/**
 * Mitglieder-Stammdaten.
 */
class Mitglied extends Model
{
    /** Gültige Statuswerte (entsprechen dem ENUM in schema.sql). */
    public const STATUS = ['antrag', 'aktiv', 'pausiert', 'inaktiv', 'ausgetreten', 'abgelehnt', 'zurueckgezogen'];

    /** Mitglieds-Typen: aktiv = Aktives Mitglied, foerder = Fördermitglied. */
    public const TYPEN = ['aktiv' => 'Aktives Mitglied', 'foerder' => 'Fördermitglied'];

    /** Bearbeitbare Spalten. */
    private const FIELDS = [
        'mitgliedsnummer', 'vorname', 'nachname', 'email', 'telefon',
        'geburtsdatum', 'geburtsort', 'geburtsland', 'matricule',
        'hausnummer', 'strasse', 'plz', 'ort', 'land',
        'wcf_user_id', 'forum_name',
        'status', 'typ', 'antragsart', 'antragsdatum', 'beitrittsdatum',
        'austrittsdatum', 'karte_ausgestellt', 'quelle', 'bemerkung',
        'newsletter_sprache',
    ];

    /** Felder, die in die Vollständigkeit einfließen. */
    private const COMPLETENESS_FIELDS = [
        'vorname', 'nachname', 'email', 'telefon', 'geburtsdatum',
        'strasse', 'plz', 'ort', 'land', 'forum_name',
    ];

    /** Liste mit optionalem Status-Filter und Suche. */
    public function all(array $filters = []): array
    {
        $sql = 'SELECT * FROM mitglieder WHERE 1=1';
        $args = [];

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUS, true)) {
            $sql .= ' AND status = :status';
            $args['status'] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $sql .= ' AND (nachname LIKE :q OR vorname LIKE :q OR email LIKE :q'
                  . ' OR mitgliedsnummer LIKE :q OR forum_name LIKE :q)';
            $args['q'] = '%' . $filters['q'] . '%';
        }
        $sql .= ' ORDER BY nachname, vorname';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mitglieder WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $data = $this->prepare($data);
        $cols = array_keys($data);
        $sql = 'INSERT INTO mitglieder (' . implode(', ', $cols) . ', vollstaendigkeit) VALUES (:'
            . implode(', :', $cols) . ', :vollstaendigkeit)';
        $data['vollstaendigkeit'] = $this->completeness($data);
        $this->db->prepare($sql)->execute($data);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $data = $this->prepare($data);
        $data['vollstaendigkeit'] = $this->completeness($data);
        $set = [];
        foreach ($data as $col => $_) {
            $set[] = "$col = :$col";
        }
        $data['id'] = $id;
        $sql = 'UPDATE mitglieder SET ' . implode(', ', $set) . ' WHERE id = :id';
        $this->db->prepare($sql)->execute($data);
    }

    /** Austritt: archivieren statt löschen. */
    public function archive(int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE mitglieder SET status = 'ausgetreten',
             austrittsdatum = COALESCE(austrittsdatum, CURDATE()) WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    /** Hartes Löschen (Admin). */
    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM mitglieder WHERE id = :id')->execute(['id' => $id]);
    }

    /** Anzahl je Status. */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(self::STATUS, 0);
        $stmt = $this->db->query('SELECT status, COUNT(*) AS n FROM mitglieder GROUP BY status');
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    public function total(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM mitglieder')->fetchColumn();
    }

    /** Anzahl je Typ ('aktiv'/'foerder'). */
    public function countTyp(string $typ): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mitglieder WHERE typ = :t');
        $stmt->execute(['t' => $typ]);
        return (int) $stmt->fetchColumn();
    }

    /** Datensätze, denen ein bestimmtes Feld fehlt (für Datenqualität). */
    public function countMissing(string $field): int
    {
        if (!in_array($field, self::FIELDS, true)) {
            return 0;
        }
        $sql = "SELECT COUNT(*) FROM mitglieder WHERE $field IS NULL OR $field = ''";
        return (int) $this->db->query($sql)->fetchColumn();
    }

    /** Aktive Mitglieder ohne WoltLab-Verknüpfung (Pflicht!). */
    public function aktiveOhneForum(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM mitglieder WHERE status = 'aktiv' AND wcf_user_id IS NULL"
        )->fetchColumn();
    }

    public function durchschnittVollstaendigkeit(): int
    {
        $v = $this->db->query('SELECT AVG(vollstaendigkeit) FROM mitglieder')->fetchColumn();
        return (int) round((float) $v);
    }

    // ----------------------------------------------------------------

    /** Nur erlaubte Felder übernehmen, Leerwerte zu NULL normalisieren. */
    private function prepare(array $data): array
    {
        $clean = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '' ) {
                $value = null;
            }
            $clean[$field] = $value;
        }
        if (array_key_exists('karte_ausgestellt', $clean)) {
            $clean['karte_ausgestellt'] = $clean['karte_ausgestellt'] ? 1 : 0;
        }
        if (array_key_exists('typ', $clean) && !isset(self::TYPEN[$clean['typ']])) {
            $clean['typ'] = 'aktiv';
        }
        return $clean;
    }

    /** Vollständigkeit in Prozent (0–100). */
    public function completeness(array $data): int
    {
        $filled = 0;
        foreach (self::COMPLETENESS_FIELDS as $field) {
            if (!empty($data[$field])) {
                $filled++;
            }
        }
        return (int) round($filled / count(self::COMPLETENESS_FIELDS) * 100);
    }

    /**
     * Server-seitige Validierung. Gibt ein Array von Fehlermeldungen zurück
     * (leer = gültig).
     */
    public function validate(array $data, ?int $ignoreId = null): array
    {
        $errors = [];

        if (empty(trim($data['vorname'] ?? ''))) {
            $errors['vorname'] = 'Vorname ist erforderlich.';
        }
        if (empty(trim($data['nachname'] ?? ''))) {
            $errors['nachname'] = 'Nachname ist erforderlich.';
        }

        $email = trim($data['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Ungültige E-Mail-Adresse.';
        }
        if ($email !== '' && $this->existsBy('email', $email, $ignoreId)) {
            $errors['email'] = 'Diese E-Mail ist bereits vergeben.';
        }

        $nr = trim($data['mitgliedsnummer'] ?? '');
        if ($nr !== '' && $this->existsBy('mitgliedsnummer', $nr, $ignoreId)) {
            $errors['mitgliedsnummer'] = 'Diese Mitgliedsnummer ist bereits vergeben.';
        }

        $status = $data['status'] ?? 'antrag';
        if (!in_array($status, self::STATUS, true)) {
            $errors['status'] = 'Ungültiger Status.';
        }

        foreach (['geburtsdatum', 'antragsdatum', 'beitrittsdatum', 'austrittsdatum'] as $df) {
            $val = trim($data[$df] ?? '');
            if ($val !== '' && !$this->isValidDate($val)) {
                $errors[$df] = 'Ungültiges Datum (Format JJJJ-MM-TT).';
            }
        }

        // Aktive Mitglieder benötigen einen Forum-Account (WoltLab).
        if ($status === 'aktiv'
            && empty(trim($data['forum_name'] ?? ''))
            && empty(trim((string) ($data['wcf_user_id'] ?? '')))) {
            $errors['forum_name'] = 'Aktive Mitglieder benötigen einen Forum-Account (Pflicht).';
        }

        return $errors;
    }

    private function existsBy(string $column, string $value, ?int $ignoreId): bool
    {
        $sql = "SELECT COUNT(*) FROM mitglieder WHERE $column = :v";
        $args = ['v' => $value];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $args['id'] = $ignoreId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function isValidDate(string $value): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }
}

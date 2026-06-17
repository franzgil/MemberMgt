<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Basis-Model: stellt die DB-Verbindung bereit.
 */
abstract class Model
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }
}

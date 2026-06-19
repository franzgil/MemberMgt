<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * PDO-Verbindung als Singleton.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dir = defined('CONFIG_DIR') ? CONFIG_DIR : ROOT . '/config';
            $configFile = $dir . '/database.php';
            if (!is_file($configFile)) {
                throw new RuntimeException(
                    'database.php fehlt – bitte config/database.example.php kopieren und anpassen.'
                );
            }
            $c = require $configFile;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'],
                $c['port'],
                $c['dbname'],
                $c['charset']
            );
            self::$instance = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$instance;
    }
}

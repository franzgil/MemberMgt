<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Zugriffsschutz über die bestehende WoltLab-Anmeldung.
 *
 * Bindet WoltLabs global.php ein und liest den aktuell eingeloggten Benutzer
 * samt Benutzergruppen. Es wird KEIN eigenes Passwort-System verwendet.
 */
class Auth
{
    private static ?array $config = null;
    private static bool $booted = false;
    private static ?string $bootError = null;
    /** @var array|null  null = Gast / nicht eingeloggt */
    private static ?array $user = null;

    public static function config(): array
    {
        if (self::$config === null) {
            $file = ROOT . '/config/auth.php';
            self::$config = is_file($file) ? (array) require $file : ['enabled' => false];
        }
        return self::$config;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    public static function bootError(): ?string
    {
        return self::$bootError;
    }

    /** WoltLab laden und aktuellen Benutzer ermitteln (einmalig). */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if (!self::enabled()) {
            return;
        }

        try {
            $global = (string) (self::config()['woltlab_global'] ?? '');
            if ($global === '') {
                $global = (string) self::findGlobal();
            }
            if ($global === '' || !is_file($global)) {
                throw new RuntimeException(
                    'WoltLab global.php nicht gefunden. Bitte "woltlab_global" in config/auth.php setzen.'
                );
            }

            // WoltLab booten – etwaige Ausgabe abfangen.
            ob_start();
            require_once $global;
            ob_end_clean();

            if (!class_exists('\\wcf\\system\\WCF')) {
                throw new RuntimeException('WoltLab-Framework konnte nicht geladen werden.');
            }

            $wcfUser = \wcf\system\WCF::getUser();
            if (!$wcfUser || !$wcfUser->userID) {
                self::$user = null; // Gast
                return;
            }

            $groupIDs = array_map('intval', $wcfUser->getGroupIDs());
            $groupNames = [];
            foreach ($groupIDs as $gid) {
                $group = \wcf\data\user\group\UserGroup::getGroupByID($gid);
                if ($group) {
                    $groupNames[$gid] = $group->getName();
                }
            }

            self::$user = [
                'userID'     => (int) $wcfUser->userID,
                'username'   => (string) $wcfUser->username,
                'groupIDs'   => $groupIDs,
                'groupNames' => $groupNames,
            ];
        } catch (Throwable $e) {
            self::$bootError = $e->getMessage();
        }
    }

    /** @return array|null  Aktueller Benutzer oder null (Gast). */
    public static function user(): ?array
    {
        return self::$user;
    }

    /** Ist der aktuelle Benutzer eingeloggt UND in einer erlaubten Gruppe? */
    public static function check(): bool
    {
        if (!self::enabled()) {
            return true;
        }
        if (self::$user === null) {
            return false;
        }
        $cfg = self::config();
        $allowedIDs = array_map('intval', $cfg['allowed_group_ids'] ?? []);
        $allowedNames = array_map(
            static fn ($n) => mb_strtolower(trim((string) $n)),
            $cfg['allowed_groups'] ?? []
        );

        foreach (self::$user['groupIDs'] as $gid) {
            if (in_array($gid, $allowedIDs, true)) {
                return true;
            }
        }
        foreach (self::$user['groupNames'] as $name) {
            if (in_array(mb_strtolower(trim($name)), $allowedNames, true)) {
                return true;
            }
        }
        return false;
    }

    /** WoltLab-Login-URL (mit Rücksprung). */
    public static function loginUrl(): string
    {
        $base = (string) (self::config()['login_url'] ?? '');
        return $base;
    }

    /** global.php in den übergeordneten Verzeichnissen suchen. */
    private static function findGlobal(): string
    {
        $dir = ROOT;
        for ($i = 0; $i < 6; $i++) {
            $dir = dirname($dir);
            if ($dir === '' || $dir === '/' || $dir === '.') {
                break;
            }
            if (is_file($dir . '/global.php') && is_dir($dir . '/wcf')) {
                return $dir . '/global.php';
            }
        }
        return '';
    }
}

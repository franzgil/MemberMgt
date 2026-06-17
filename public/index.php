<?php
/**
 * Front Controller – einziger Einstiegspunkt der Anwendung.
 */
declare(strict_types=1);

// Eingebauter PHP-Server: existierende Dateien direkt ausliefern
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

session_start();

define('ROOT', dirname(__DIR__));

// Konfiguration & Hilfsfunktionen
require ROOT . '/config/config.php';
require ROOT . '/app/Core/helpers.php';

// Einfacher PSR-4-Autoloader: App\  ->  app/
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Route ermitteln (htaccess liefert ?url=…, sonst aus REQUEST_URI ableiten)
$route = $_GET['url'] ?? null;
if ($route === null) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (BASE_URL !== '' && strpos($uri, BASE_URL) === 0) {
        $uri = substr($uri, strlen(BASE_URL));
    }
    $route = $uri;
}

try {
    (new App\Core\Router())->dispatch((string) $route);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Fehler</h1><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}

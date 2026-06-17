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

// Route ermitteln – funktioniert in drei Betriebsarten:
//   1) mit mod_rewrite  -> ?url=…
//   2) ohne mod_rewrite -> PATH_INFO (index.php/mitglieder/…)
//   3) Direktaufruf      -> aus REQUEST_URI abgeleitet
$route = $_GET['url'] ?? null;
if ($route === null || $route === '') {
    if (!empty($_SERVER['PATH_INFO'])) {
        $route = $_SERVER['PATH_INFO'];
    } else {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        if (BASE_URL !== '' && strpos($uri, BASE_URL) === 0) {
            $uri = substr($uri, strlen(BASE_URL));
        }
        $route = $uri;
    }
}
// Ein evtl. enthaltenes 'index.php' am Anfang der Route entfernen.
$route = preg_replace('#^/?index\.php#', '', (string) $route);

// Zugriffsschutz über WoltLab (sofern config/auth.php aktiviert).
// Die /auth-Routen (Diagnose, „Kein Zugriff") bleiben ohne Prüfung erreichbar.
if (App\Core\Auth::enabled()) {
    App\Core\Auth::boot();
    $istAuthRoute = (bool) preg_match('#^/?auth(/|$)#', (string) $route);
    if (!$istAuthRoute && !App\Core\Auth::check()) {
        if (App\Core\Auth::bootError() !== null) {
            http_response_code(500);
            echo '<h1>Login-Konfiguration</h1><pre>'
                . htmlspecialchars(App\Core\Auth::bootError(), ENT_QUOTES, 'UTF-8')
                . '</pre><p>Bitte config/auth.php prüfen oder den Schutz vorübergehend '
                . 'deaktivieren (enabled = false).</p>';
            exit;
        }
        if (App\Core\Auth::user() === null) {
            // nicht eingeloggt -> WoltLab-Login
            header('Location: ' . App\Core\Auth::loginUrl());
            exit;
        }
        // eingeloggt, aber nicht in erlaubter Gruppe -> Hinweisseite
        header('Location: ' . BASE_URL . '/index.php/auth/denied');
        exit;
    }
}

try {
    (new App\Core\Router())->dispatch((string) $route);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Fehler</h1><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}

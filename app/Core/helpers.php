<?php
/**
 * Globale Hilfsfunktionen.
 */
declare(strict_types=1);

if (!function_exists('e')) {
    /** HTML-sicher ausgeben (XSS-Schutz). */
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Link auf eine App-Route – läuft über den Front Controller (index.php),
     * damit die App auch OHNE mod_rewrite funktioniert (PATH_INFO).
     * Beispiel: url('/mitglieder') -> /apps/MemberMgt/public/index.php/mitglieder
     */
    function url(string $path = ''): string
    {
        $path = ($path === '' || $path === '/') ? '' : '/' . ltrim($path, '/');
        return BASE_URL . '/index.php' . $path;
    }
}

if (!function_exists('asset')) {
    /** Link auf eine statische Datei (CSS, JS, Bilder) – ohne index.php. */
    function asset(string $path = ''): string
    {
        return BASE_URL . '/' . ltrim($path, '/');
    }
}

if (!function_exists('flash')) {
    /** Flash-Nachricht setzen (mit $msg) oder einmalig auslesen. */
    function flash(string $key, ?string $msg = null): ?string
    {
        if ($msg !== null) {
            $_SESSION['flash'][$key] = $msg;
            return null;
        }
        $value = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $value;
    }
}

if (!function_exists('old')) {
    /** Vorherigen Formularwert nach Validierungsfehler zurückgeben. */
    function old(string $key, $default = '')
    {
        return $_SESSION['old'][$key] ?? $default;
    }
}

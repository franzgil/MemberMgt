<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimaler Router: /controller/action/param1/param2 …
 * Standard: Dashboard::index
 */
class Router
{
    public function dispatch(string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '';
        $path = trim($path, '/');
        $segments = $path === '' ? [] : explode('/', $path);

        $controllerName = !empty($segments[0]) ? ucfirst(strtolower($segments[0])) : 'Dashboard';
        $action = $segments[1] ?? 'index';
        $params = array_slice($segments, 2);

        $class = 'App\\Controllers\\' . $controllerName . 'Controller';
        if (!class_exists($class)) {
            $this->notFound();
            return;
        }

        $controller = new $class();
        if (!method_exists($controller, $action) || $action[0] === '_') {
            $this->notFound();
            return;
        }

        call_user_func_array([$controller, $action], $params);
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><h1>404 – Seite nicht gefunden</h1>'
            . '<p><a href="' . BASE_URL . '/">Zur Startseite</a></p>';
    }
}

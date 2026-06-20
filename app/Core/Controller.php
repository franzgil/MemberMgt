<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Basis-Controller: View-Rendering, Redirects, CSRF-Schutz.
 */
abstract class Controller
{
    /** View innerhalb des gemeinsamen Layouts rendern. */
    protected function view(string $view, array $data = []): void
    {
        $viewFile = ROOT . '/app/Views/' . $view . '.php';
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'View nicht gefunden: ' . e($view);
            return;
        }
        // CSRF-Token immer für Formulare verfügbar machen
        $data['csrf'] = $this->csrfToken();
        extract($data, EXTR_SKIP);

        require ROOT . '/app/Views/layout/header.php';
        require $viewFile;
        require ROOT . '/app/Views/layout/footer.php';

        // Alte Formulareingaben (old()) sind nur für genau diese eine
        // (Fehler-)Anzeige gedacht. Danach verwerfen, sonst überlagern sie
        // spätere Bearbeiten-/Anlegen-Formulare mit fremden Daten.
        unset($_SESSION['old']);
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . BASE_URL . $path);
        exit;
    }

    /** Aktuelles CSRF-Token (erzeugt es bei Bedarf). */
    protected function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** Bei POST das CSRF-Token prüfen. */
    protected function verifyCsrf(): void
    {
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(419);
            exit('Ungültiges oder abgelaufenes CSRF-Token.');
        }
    }

    protected function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}

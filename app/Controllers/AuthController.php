<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;

class AuthController extends Controller
{
    /** Diagnoseseite: zeigt, was WoltLab über den aktuellen Benutzer liefert. */
    public function debug(): void
    {
        Auth::boot();
        $this->view('auth/debug', [
            'titel'     => 'Login-Diagnose',
            'enabled'   => Auth::enabled(),
            'bootError' => Auth::bootError(),
            'user'      => Auth::user(),
            'config'    => Auth::config(),
            'darfRein'  => Auth::check(),
            'darfBestaetigen' => Auth::darfBeitragBestaetigen(),
        ]);
    }

    /** „Kein Zugriff" – eingeloggt, aber nicht in einer erlaubten Gruppe. */
    public function denied(): void
    {
        Auth::boot();
        http_response_code(403);
        $this->view('auth/denied', [
            'titel'  => 'Kein Zugriff',
            'user'   => Auth::user(),
            'config' => Auth::config(),
        ]);
    }
}

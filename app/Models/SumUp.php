<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;

/**
 * Minimaler SumUp-API-Client (nur Lesen, „auf Abfrage").
 * Holt die letzten Transaktionen, damit der Trésorier Zahlungen abgleichen kann.
 *
 * Authentifizierung über den SumUp-API-Key als Bearer-Token.
 * Endpoint: GET https://api.sumup.com/v0.1/me/transactions/history
 */
class SumUp
{
    private const BASE = 'https://api.sumup.com';

    public function configured(): bool
    {
        return Auth::sumupApiKey() !== '';
    }

    /**
     * Letzte Transaktionen abrufen.
     * @return array{ok:bool, items?:array<int,array<string,mixed>>, error?:string, status?:int}
     */
    public function recentTransactions(int $limit = 30): array
    {
        $key = Auth::sumupApiKey();
        if ($key === '') {
            return ['ok' => false, 'error' => 'Kein SumUp-API-Key konfiguriert (config/auth.php → sumup_api_key oder Umgebungsvariable SUMUP_API_KEY).'];
        }
        $limit = max(1, min(100, $limit));
        $url = self::BASE . '/v0.1/me/transactions/history?limit=' . $limit . '&order=descending';

        $res = $this->httpGet($url, $key);
        if (!$res['ok']) {
            return $res;
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Unerwartete Antwort von SumUp (kein JSON).'];
        }
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        $out = [];
        foreach ($items as $t) {
            if (!is_array($t)) {
                continue;
            }
            $out[] = [
                'code'     => (string) ($t['transaction_code'] ?? $t['id'] ?? ''),
                'time'     => (string) ($t['timestamp'] ?? ''),
                'amount'   => isset($t['amount']) ? (float) $t['amount'] : null,
                'currency' => (string) ($t['currency'] ?? ''),
                'status'   => (string) ($t['status'] ?? ''),
                'type'     => (string) ($t['type'] ?? ''),
                'payment'  => (string) ($t['payment_type'] ?? ''),
            ];
        }
        return ['ok' => true, 'items' => $out];
    }

    /** @return array{ok:bool, body?:string, error?:string, status?:int} */
    private function httpGet(string $url, string $key): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL ist auf dem Server nicht verfügbar.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => 'Verbindungsfehler: ' . $err];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            $short = trim(mb_substr((string) $body, 0, 300));
            $hint = $status === 401
                ? ' (Key ungültig/abgelaufen oder kein Bearer-Key – im SumUp-Dashboard prüfen.)'
                : '';
            return ['ok' => false, 'status' => $status,
                    'error' => 'SumUp-API HTTP ' . $status . $hint . ' – ' . $short];
        }
        return ['ok' => true, 'body' => (string) $body];
    }
}

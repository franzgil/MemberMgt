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
     * Letzte Transaktionen abrufen. Mit $withDetails werden pro Transaktion die
     * Detaildaten geladen, um Name/Bemerkung (falls vorhanden) anzuzeigen.
     * @return array{ok:bool, items?:array<int,array<string,mixed>>, sample?:array, error?:string, status?:int}
     */
    public function recentTransactions(int $limit = 25, bool $withDetails = true): array
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
        $sample = null;          // Rohdaten der 1. Detail-Antwort (Feldanalyse)
        $detailBudget = 25;      // Begrenzung der zusätzlichen Detail-Abrufe
        foreach ($items as $t) {
            if (!is_array($t)) {
                continue;
            }
            $code = (string) ($t['transaction_code'] ?? $t['id'] ?? '');
            $row = [
                'code'     => $code,
                'time'     => (string) ($t['timestamp'] ?? ''),
                'amount'   => isset($t['amount']) ? (float) $t['amount'] : null,
                'currency' => (string) ($t['currency'] ?? ''),
                'status'   => (string) ($t['status'] ?? ''),
                'type'     => (string) ($t['type'] ?? ''),
                'payment'  => (string) ($t['payment_type'] ?? ''),
                'name'     => '',
                'note'     => '',
            ];
            if ($withDetails && $code !== '' && $detailBudget > 0) {
                $detailBudget--;
                $detail = $this->transactionDetail($code, $key);
                if (is_array($detail)) {
                    if ($sample === null) {
                        $sample = $detail;
                    }
                    $row['name'] = $this->pickName($detail);
                    $row['note'] = $this->pickNote($detail);
                }
            }
            $out[] = $row;
        }
        return ['ok' => true, 'items' => $out, 'sample' => $sample];
    }

    /** Detaildaten einer Transaktion (oder null). */
    private function transactionDetail(string $code, string $key): ?array
    {
        $url = self::BASE . '/v0.1/me/transactions?transaction_code=' . rawurlencode($code);
        $res = $this->httpGet($url, $key, 8);
        if (!$res['ok']) {
            return null;
        }
        $d = json_decode($res['body'], true);
        return is_array($d) ? $d : null;
    }

    /** Alle skalaren Werte zu bestimmten (kleingeschriebenen) Schlüsseln finden. */
    private function deepFind(array $data, array $keys): array
    {
        $found = [];
        array_walk_recursive($data, static function ($v, $k) use (&$found, $keys) {
            $lk = strtolower((string) $k);
            if (in_array($lk, $keys, true) && is_scalar($v) && trim((string) $v) !== '') {
                if (!isset($found[$lk])) {
                    $found[$lk] = (string) $v;
                }
            }
        });
        return $found;
    }

    /** Versucht, einen Personennamen aus den Detaildaten zu lesen. */
    private function pickName(array $d): string
    {
        $f = $this->deepFind($d, [
            'first_name', 'given_name', 'last_name', 'family_name', 'name',
            'holder_name', 'card_holder', 'cardholder_name', 'customer_name', 'full_name',
        ]);
        $first = $f['first_name'] ?? $f['given_name'] ?? '';
        $last  = $f['last_name'] ?? $f['family_name'] ?? '';
        if ($first !== '' || $last !== '') {
            return trim($first . ' ' . $last);
        }
        foreach (['full_name', 'name', 'holder_name', 'card_holder', 'cardholder_name', 'customer_name'] as $k) {
            if (!empty($f[$k])) {
                return $f[$k];
            }
        }
        return '';
    }

    /** Versucht, eine Bemerkung/Beschreibung aus den Detaildaten zu lesen. */
    private function pickNote(array $d): string
    {
        $f = $this->deepFind($d, [
            'description', 'checkout_reference', 'reference', 'message', 'note', 'memo', 'remark',
        ]);
        foreach (['description', 'note', 'memo', 'remark', 'message', 'reference', 'checkout_reference'] as $k) {
            if (!empty($f[$k])) {
                return $f[$k];
            }
        }
        return '';
    }

    /** @return array{ok:bool, body?:string, error?:string, status?:int} */
    private function httpGet(string $url, string $key, int $timeout = 15): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL ist auf dem Server nicht verfügbar.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
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

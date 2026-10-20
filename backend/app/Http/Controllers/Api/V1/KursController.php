<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kurs IDR vs major currencies. Pakai chain dengan fallback:
 *   1. open.er-api.com (sumber ExchangeRate-API, no key, daily update)
 *   2. fawazahmed0/currency-api (jsdelivr CDN, no rate limit, no key)
 *   3. frankfurter.app (ECB rates, weekday update only)
 *
 * Cache 6 jam karena nilai kurs daily, tidak fluctuate tiap menit.
 *
 *   GET /api/v1/kurs   → list 1 currency = ? IDR
 */
class KursController extends ApiController
{
    private const TARGETS = ['USD', 'EUR', 'SGD', 'JPY', 'MYR', 'CNY', 'AUD', 'GBP'];

    public function index(): JsonResponse
    {
        $result = Cache::remember('kurs.idr.v2', 60 * 60 * 6, function () {
            foreach (['fetchOpenErApi', 'fetchFawazahmed0', 'fetchFrankfurter'] as $method) {
                try {
                    $data = $this->$method();
                    if ($data && !empty($data['rows'])) return $data;
                } catch (\Throwable $e) {
                    Log::warning("Kurs source {$method} failed: " . $e->getMessage());
                }
            }
            return ['rows' => [], 'updated_at' => null, 'source' => 'unavailable'];
        });

        return $this->success($result);
    }

    /** open.er-api.com: 1 IDR = ? X. Convert ke 1 X = ? IDR. */
    private function fetchOpenErApi(): ?array
    {
        $r = Http::timeout(8)->acceptJson()->get('https://open.er-api.com/v6/latest/IDR');
        if (!$r->successful()) return null;
        $j = $r->json();
        if (($j['result'] ?? null) !== 'success') return null;
        $rates = $j['rates'] ?? [];
        $rows = [];
        foreach (self::TARGETS as $c) {
            if (!isset($rates[$c]) || (float) $rates[$c] <= 0) continue;
            $rows[] = ['currency' => $c, 'idr_per_one' => round(1 / (float) $rates[$c], 2)];
        }
        if (!$rows) return null;
        return [
            'rows'       => $rows,
            'updated_at' => $j['time_last_update_utc'] ?? null,
            'source'     => 'open.er-api.com',
        ];
    }

    /** fawazahmed0 via jsdelivr CDN. Path: /v1/currencies/idr.json. */
    private function fetchFawazahmed0(): ?array
    {
        $r = Http::timeout(8)->acceptJson()->get('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/idr.json');
        if (!$r->successful()) return null;
        $j = $r->json();
        $rates = $j['idr'] ?? [];
        $rows = [];
        foreach (self::TARGETS as $c) {
            $k = strtolower($c);
            if (!isset($rates[$k]) || (float) $rates[$k] <= 0) continue;
            $rows[] = ['currency' => $c, 'idr_per_one' => round(1 / (float) $rates[$k], 2)];
        }
        if (!$rows) return null;
        return [
            'rows'       => $rows,
            'updated_at' => $j['date'] ?? null,
            'source'     => 'fawazahmed0/currency-api',
        ];
    }

    /** Frankfurter (ECB): 1 IDR = ? X. Weekday update only (no weekend). */
    private function fetchFrankfurter(): ?array
    {
        $r = Http::timeout(8)->acceptJson()->get('https://api.frankfurter.app/latest', [
            'from' => 'IDR',
            'to'   => implode(',', self::TARGETS),
        ]);
        if (!$r->successful()) return null;
        $j = $r->json();
        $rates = $j['rates'] ?? [];
        $rows = [];
        foreach (self::TARGETS as $c) {
            if (!isset($rates[$c]) || (float) $rates[$c] <= 0) continue;
            $rows[] = ['currency' => $c, 'idr_per_one' => round(1 / (float) $rates[$c], 2)];
        }
        if (!$rows) return null;
        return [
            'rows'       => $rows,
            'updated_at' => $j['date'] ?? null,
            'source'     => 'frankfurter.app (ECB)',
        ];
    }
}

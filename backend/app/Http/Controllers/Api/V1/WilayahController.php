<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proxy untuk emsifa/api-wilayah-indonesia (data resmi Kemendagri). Karena
 * GitHub Pages tidak set CORS headers, kita proxy via backend + cache 7 hari.
 * Data hampir tidak pernah berubah (hierarki wilayah Indonesia).
 *
 * Endpoint:
 *   GET /api/v1/wilayah/provinces                  → list 38 provinsi
 *   GET /api/v1/wilayah/regencies/{provinceId}     → list kab/kota
 *   GET /api/v1/wilayah/districts/{regencyId}      → list kecamatan
 *   GET /api/v1/wilayah/villages/{districtId}      → list kelurahan/desa
 */
class WilayahController extends ApiController
{
    private const SOURCE = 'https://emsifa.github.io/api-wilayah-indonesia/api';
    private const TTL = 60 * 60 * 24 * 7;  // 7 hari

    public function provinces(): JsonResponse
    {
        return $this->success($this->fetchCached('provinces', '/provinces.json'));
    }

    public function regencies(string $provinceId): JsonResponse
    {
        $this->assertIdSafe($provinceId);
        return $this->success($this->fetchCached("regencies.{$provinceId}", "/regencies/{$provinceId}.json"));
    }

    public function districts(string $regencyId): JsonResponse
    {
        $this->assertIdSafe($regencyId);
        return $this->success($this->fetchCached("districts.{$regencyId}", "/districts/{$regencyId}.json"));
    }

    public function villages(string $districtId): JsonResponse
    {
        $this->assertIdSafe($districtId);
        return $this->success($this->fetchCached("villages.{$districtId}", "/villages/{$districtId}.json"));
    }

    /**
     * GET /api/v1/wilayah/kodepos?village=&district=&regency=
     * Lookup kode pos dari nama wilayah. Source: kodepos.vercel.app (community,
     * sumber Pos Indonesia). Cache 30 hari per kombinasi nama.
     */
    public function kodepos(Request $request): JsonResponse
    {
        $request->validate([
            'village'  => ['required', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'regency'  => ['nullable', 'string', 'max:100'],
        ]);
        $village  = trim($request->string('village')->toString());
        $district = trim($request->string('district')->toString());
        $regency  = trim($request->string('regency')->toString());

        $cacheKey = 'kodepos.' . md5(strtolower($village . '|' . $district . '|' . $regency));

        $result = \Illuminate\Support\Facades\Cache::remember($cacheKey, 60 * 60 * 24 * 30, function () use ($village, $district, $regency) {
            try {
                $r = \Illuminate\Support\Facades\Http::timeout(10)
                    ->acceptJson()
                    ->get('https://kodepos.vercel.app/search/', ['q' => $village]);
                if (!$r->successful()) return null;
                $rows = $r->json('data') ?? [];
                if (!is_array($rows)) return null;

                // Filter by district + regency kalau ada (case-insensitive contains)
                $matches = collect($rows)->filter(function ($row) use ($village, $district, $regency) {
                    $v = mb_strtolower((string) ($row['village'] ?? ''));
                    if ($v !== mb_strtolower($village)) return false;
                    if ($district !== '' && stripos((string) ($row['district'] ?? ''), $district) === false) return false;
                    if ($regency !== '' && stripos((string) ($row['regency'] ?? ''), $regency) === false) return false;
                    return true;
                })->values();

                if ($matches->isEmpty()) {
                    // Fallback: cari first exact village match
                    $matches = collect($rows)->filter(fn ($r) => mb_strtolower((string) ($r['village'] ?? '')) === mb_strtolower($village))->values();
                }
                return $matches->take(5)->map(fn ($r) => [
                    'code'      => (string) ($r['code'] ?? ''),
                    'village'   => $r['village'] ?? '',
                    'district'  => $r['district'] ?? '',
                    'regency'   => $r['regency'] ?? '',
                    'province'  => $r['province'] ?? '',
                    'latitude'  => $r['latitude'] ?? null,
                    'longitude' => $r['longitude'] ?? null,
                ])->all();
            } catch (\Throwable $e) {
                return null;
            }
        });

        return $this->success($result ?? []);
    }

    private function fetchCached(string $key, string $path): array
    {
        return Cache::remember("wilayah.{$key}", self::TTL, function () use ($path) {
            try {
                $r = Http::timeout(15)->acceptJson()->get(self::SOURCE . $path);
                if ($r->successful() && is_array($r->json())) {
                    return $r->json();
                }
                Log::warning('Wilayah fetch non-200', ['path' => $path, 'status' => $r->status()]);
            } catch (\Throwable $e) {
                Log::warning('Wilayah fetch error: ' . $e->getMessage());
            }
            return [];
        });
    }

    /** Hanya allow numeric ID supaya tidak bisa di-injection ke URL. */
    private function assertIdSafe(string $id): void
    {
        if (!preg_match('/^\d{1,10}$/', $id)) {
            abort(422, 'Invalid wilayah ID.');
        }
    }
}

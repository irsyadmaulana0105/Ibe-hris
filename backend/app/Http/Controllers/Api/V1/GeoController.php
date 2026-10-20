<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Geocoding proxy ke OpenStreetMap Nominatim (free, no API key).
 *
 *   GET /api/v1/geo/search?q=Jl+Veteran+Surabaya  → forward geocoding
 *   GET /api/v1/geo/reverse?lat=&lng=             → reverse geocoding
 *
 * Cache 30 hari per query (data alamat jarang berubah). Nominatim policy
 * minta User-Agent custom + max 1 req/sec, kita pakai cache supaya tidak
 * langgar fair-use.
 */
class GeoController extends ApiController
{
    private const UA = 'IBE-Reality-HRIS/1.0 (https://app-erp.ibereality.com)';

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:3', 'max:200']]);
        $q = trim($request->string('q')->toString());

        $key = 'geo.search.' . md5(strtolower($q));
        $result = Cache::remember($key, 60 * 60 * 24 * 30, function () use ($q) {
            try {
                $r = Http::timeout(10)
                    ->withHeaders(['User-Agent' => self::UA])
                    ->acceptJson()
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $q,
                        'format' => 'json',
                        'limit' => 5,
                        'countrycodes' => 'id',
                        'addressdetails' => 1,
                    ]);
                if (!$r->successful()) return [];
                return collect($r->json())->map(fn ($x) => [
                    'display_name' => $x['display_name'] ?? '',
                    'lat' => isset($x['lat']) ? (float) $x['lat'] : null,
                    'lng' => isset($x['lon']) ? (float) $x['lon'] : null,
                    'address' => $x['address'] ?? null,
                    'osm_type' => $x['osm_type'] ?? null,
                ])->all();
            } catch (\Throwable $e) {
                return [];
            }
        });

        return $this->success($result);
    }

    public function reverse(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $lat = round((float) $request->input('lat'), 6);
        $lng = round((float) $request->input('lng'), 6);

        $key = "geo.reverse.{$lat},{$lng}";
        $result = Cache::remember($key, 60 * 60 * 24 * 30, function () use ($lat, $lng) {
            try {
                $r = Http::timeout(10)
                    ->withHeaders(['User-Agent' => self::UA])
                    ->acceptJson()
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'lat' => $lat,
                        'lon' => $lng,
                        'format' => 'json',
                        'addressdetails' => 1,
                        'accept-language' => 'id',
                    ]);
                if (!$r->successful()) return null;
                $x = $r->json();
                return [
                    'display_name' => $x['display_name'] ?? '',
                    'address'      => $x['address'] ?? null,
                ];
            } catch (\Throwable $e) {
                return null;
            }
        });

        return $this->success($result ?? ['display_name' => '', 'address' => null]);
    }
}

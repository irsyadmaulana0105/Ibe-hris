<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Proxy untuk beberapa public API (Tier C/D/E):
 *   - GET /qr?data=...&size=    → QR code PNG dari api.qrserver.com (cached 7d)
 *   - GET /countries            → REST Countries list (cached 30d)
 *   - GET /quote                → Quote of the day (cached 6h)
 *   - GET /github-releases      → IBE HRIS changelog from GitHub (cached 1h)
 *   - GET /password-pwned?hash= → SHA-1 prefix range check (no cache - sensitive)
 *
 * Semua endpoint cached server-side untuk hemat bandwidth + respek fair-use
 * policy public APIs.
 */
class IntegrationProxyController extends ApiController
{
    private const UA = 'IBE-Reality-HRIS/1.0 (+https://app-erp.ibereality.com)';

    /**
     * GET /api/v1/qr?data=...&size=200
     * Return QR code PNG dari api.qrserver.com.
     * Cached 7 hari per (data, size) supaya tidak hit upstream tiap render.
     */
    public function qr(Request $request): Response
    {
        $request->validate([
            'data' => ['required', 'string', 'max:2000'],
            'size' => ['nullable', 'integer', 'min:50', 'max:1000'],
            'margin' => ['nullable', 'integer', 'min:0', 'max:50'],
            'color' => ['nullable', 'string', 'max:6'],     // hex without #
            'bgcolor' => ['nullable', 'string', 'max:6'],
        ]);
        $data = $request->string('data')->toString();
        $size = (int) $request->integer('size', 240);
        $margin = (int) $request->integer('margin', 4);
        $color = $request->string('color')->toString() ?: '000000';
        $bgcolor = $request->string('bgcolor')->toString() ?: 'ffffff';

        // Cache binary di disk (file driver) supaya tidak hit qrserver.com tiap render
        $cacheKey = 'qr_' . md5("{$data}|{$size}|{$margin}|{$color}|{$bgcolor}");
        $cachePath = storage_path('app/qr-cache/' . $cacheKey . '.png');
        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

        if (file_exists($cachePath) && (time() - filemtime($cachePath)) < 60 * 60 * 24 * 7) {
            return response(file_get_contents($cachePath), 200, [
                'Content-Type'  => 'image/png',
                'Cache-Control' => 'public, max-age=604800',
            ]);
        }

        try {
            $r = Http::timeout(10)->withHeaders(['User-Agent' => self::UA])->get('https://api.qrserver.com/v1/create-qr-code/', [
                'data'    => $data,
                'size'    => "{$size}x{$size}",
                'margin'  => $margin,
                'color'   => $color,
                'bgcolor' => $bgcolor,
                'format'  => 'png',
                'ecc'     => 'M',
            ]);
            if (!$r->successful()) {
                return response('QR upstream returned HTTP ' . $r->status(), 502);
            }
            $png = $r->body();
            @file_put_contents($cachePath, $png);
            return response($png, 200, [
                'Content-Type'  => 'image/png',
                'Cache-Control' => 'public, max-age=604800',
            ]);
        } catch (\Throwable $e) {
            return response('QR generation error: ' . $e->getMessage(), 502);
        }
    }

    /** GET /api/v1/countries → list 250+ country (REST Countries) */
    public function countries(): JsonResponse
    {
        $data = Cache::remember('countries.v1', 60 * 60 * 24 * 30, function () {
            try {
                $r = Http::timeout(15)->acceptJson()->get('https://restcountries.com/v3.1/all', [
                    'fields' => 'name,cca2,cca3,idd,currencies,flag,region,languages,timezones',
                ]);
                if (!$r->successful()) return null;
                return collect($r->json())->map(fn ($c) => [
                    'name'      => $c['name']['common'] ?? '',
                    'code'      => $c['cca2'] ?? '',  // ISO 3166-1 alpha-2
                    'code3'     => $c['cca3'] ?? '',  // alpha-3
                    'flag'      => $c['flag'] ?? '',
                    'region'    => $c['region'] ?? '',
                    'dial_code' => isset($c['idd']['root']) ? ($c['idd']['root'] . ($c['idd']['suffixes'][0] ?? '')) : null,
                    'currency'  => collect($c['currencies'] ?? [])->keys()->first(),
                    'currency_symbol' => collect($c['currencies'] ?? [])->first()['symbol'] ?? null,
                    'timezones' => $c['timezones'] ?? [],
                    'languages' => array_values($c['languages'] ?? []),
                ])->sortBy('name')->values()->all();
            } catch (\Throwable $e) {
                return [];
            }
        });
        return $this->success($data ?? []);
    }

    /** GET /api/v1/quote → motivational quote of day */
    public function quote(): JsonResponse
    {
        $data = Cache::remember('quote.daily.' . date('Y-m-d'), 60 * 60 * 6, function () {
            // Bundled Indonesian quotes - quotable.io kadang slow/timeout
            $local = [
                ['quote' => 'Sukses bukan kebetulan. Itu adalah kerja keras, ketekunan, belajar, mau berkorban, dan cinta atas apa yang dikerjakan.', 'author' => 'Pelé'],
                ['quote' => 'Jangan tunda sampai besok apa yang bisa dikerjakan hari ini.', 'author' => 'Pepatah Indonesia'],
                ['quote' => 'Hidup adalah 10% apa yang terjadi padamu dan 90% bagaimana kamu meresponsnya.', 'author' => 'Charles R. Swindoll'],
                ['quote' => 'Ilmu tanpa amal seperti pohon tanpa buah.', 'author' => 'Pepatah Arab'],
                ['quote' => 'Bersyukur adalah obat paling manjur untuk hati yang gelisah.', 'author' => 'Anonim'],
                ['quote' => 'Inovasi membedakan antara pemimpin dan pengikut.', 'author' => 'Steve Jobs'],
                ['quote' => 'Cara terbaik untuk memprediksi masa depan adalah menciptakannya.', 'author' => 'Peter Drucker'],
                ['quote' => 'Tidak ada yang mustahil bagi mereka yang mau berusaha.', 'author' => 'B.J. Habibie'],
                ['quote' => 'Bangsa yang besar adalah bangsa yang menghormati jasa pahlawannya.', 'author' => 'Soekarno'],
                ['quote' => 'Selama ada keyakinan, semua jadi mungkin.', 'author' => 'Anonim'],
            ];
            // Pakai tanggal sebagai seed supaya quote stabil per hari
            $idx = (int) date('z') % count($local);
            $q = $local[$idx];

            // Optional: try quotable.io as enhancement (no fail kalau down)
            try {
                $r = Http::timeout(3)->get('https://api.quotable.io/random');
                if ($r->successful()) {
                    $j = $r->json();
                    if (!empty($j['content']) && !empty($j['author'])) {
                        $q = ['quote' => $q['quote'], 'author' => $q['author'], 'extra' => [
                            'en' => ['quote' => $j['content'], 'author' => $j['author']],
                        ]];
                    }
                }
            } catch (\Throwable $e) { /* ignore */ }

            return $q;
        });
        return $this->success($data);
    }

    /**
     * GET /api/v1/github-releases?repo=anthropics/claude-code
     * Fetch latest releases dari GitHub public API. Default repo=ourselves.
     */
    public function githubReleases(Request $request): JsonResponse
    {
        $repo = $request->string('repo')->toString() ?: 'laravel/laravel';
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
            return response()->json(['message' => 'Invalid repo format. Use owner/name.'], 422);
        }
        $data = Cache::remember("gh.releases." . md5($repo), 60 * 60, function () use ($repo) {
            try {
                $r = Http::timeout(10)->withHeaders(['User-Agent' => self::UA, 'Accept' => 'application/vnd.github+json'])
                    ->get("https://api.github.com/repos/{$repo}/releases", ['per_page' => 10]);
                if (!$r->successful()) return [];
                return collect($r->json())->map(fn ($x) => [
                    'tag'        => $x['tag_name'] ?? '',
                    'name'       => $x['name'] ?? '',
                    'body'       => mb_substr((string) ($x['body'] ?? ''), 0, 2000),
                    'url'        => $x['html_url'] ?? '',
                    'published_at' => $x['published_at'] ?? '',
                    'prerelease' => (bool) ($x['prerelease'] ?? false),
                ])->all();
            } catch (\Throwable $e) {
                return [];
            }
        });
        return $this->success(['repo' => $repo, 'releases' => $data]);
    }

    /**
     * GET /api/v1/password-pwned?password=...
     * K-anonymity check: hash SHA-1 dulu, kirim 5 char pertama ke HIBP,
     * cek apakah suffix ada di response. Tidak pernah kirim password full.
     */
    public function passwordPwned(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'min:1', 'max:200']]);
        $password = $request->string('password')->toString();
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $r = Http::timeout(8)
                ->withHeaders(['User-Agent' => self::UA, 'Add-Padding' => 'true'])
                ->get("https://api.pwnedpasswords.com/range/{$prefix}");
            if (!$r->successful()) {
                return $this->success(['pwned' => false, 'count' => 0, 'source' => 'unavailable']);
            }
            $body = $r->body();
            $count = 0;
            foreach (preg_split('/\r?\n/', $body) as $line) {
                if (str_starts_with($line, $suffix)) {
                    [$_, $cnt] = explode(':', trim($line));
                    $count = (int) $cnt;
                    break;
                }
            }
            return $this->success([
                'pwned'  => $count > 0,
                'count'  => $count,
                'source' => 'haveibeenpwned.com',
                'safe'   => $count === 0,
            ]);
        } catch (\Throwable $e) {
            return $this->success(['pwned' => false, 'count' => 0, 'source' => 'error']);
        }
    }
}

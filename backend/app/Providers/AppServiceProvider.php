<?php

namespace App\Providers;

use App\Services\EnterpriseAuthorizationService;
use App\Surat\Models\Letter;
use App\Surat\Policies\LetterPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Cache policy decisions within one HTTP/job lifecycle, never across
        // Octane/queue lifecycles where role assignments may have changed.
        $this->app->scoped(
            EnterpriseAuthorizationService::class,
            fn ($app) => new EnterpriseAuthorizationService,
        );
    }

    public function boot(): void
    {
        // Surat module policies
        Gate::policy(Letter::class, LetterPolicy::class);

        // Kebijakan password terpusat. Sebelum 2026-09-22 tidak ada sama sekali:
        // acceptInvite hanya `min:6` dan changePassword `min:8`, sehingga kata
        // "password" lolos sebagai kata sandi yang sah — dan memang itulah yang
        // dipakai akun-akun produksi.
        //
        // Panjang + cek kebocoran dipilih ketimbang aturan komposisi (harus ada
        // simbol, angka, huruf besar). Aturan komposisi justru mendorong pola
        // yang mudah ditebak seperti "Password123!", dan pola itu tetap lolos.
        // uncompromised() memeriksa ke basis data kebocoran publik memakai
        // k-anonymity: hanya 5 karakter pertama hash SHA-1 yang dikirim, jadi
        // kata sandinya sendiri tidak pernah meninggalkan server.
        //
        // Catatan: kalau server tidak bisa menjangkau API-nya, Laravel memilih
        // gagal-terbuka (password diterima). Panjang minimum tetap berlaku.
        Password::defaults(fn () => Password::min(12)->uncompromised());

        // Login API: 10 percobaan/menit per AKUN+IP (hentikan tebak sandi pada satu
        // akun) dan 100/menit per IP (hentikan bot). Dulu hanya 10/menit per IP,
        // sehingga satu kantor (satu IP publik) saling memblokir saat login.
        \Illuminate\Support\Facades\RateLimiter::for('api-login', function (\Illuminate\Http\Request $request) {
            $akun = mb_strtolower(trim((string) ($request->input('email') ?? $request->input('identifier') ?? $request->input('username') ?? '')));

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by('akun|'.$akun.'|'.$request->ip()),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(100)->by('ip|'.$request->ip()),
            ];
        });

        // Folder kerja DomPDF (config/dompdf.php). Kalau tidak ada, DomPDF
        // diam-diam kembali ke /tmp - persis masalah yang mau dihindari.
        $pdfTemp = config('dompdf.options.temp_dir');
        if (is_string($pdfTemp) && $pdfTemp !== '' && ! is_dir($pdfTemp)) {
            @mkdir($pdfTemp, 0775, true);
        }
    }
}

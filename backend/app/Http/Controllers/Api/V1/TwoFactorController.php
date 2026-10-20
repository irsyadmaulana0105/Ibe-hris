<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\RecoveryCode;
use Laravel\Fortify\TwoFactorAuthenticationProvider;

/**
 * Verifikasi dua langkah untuk login berbasis token.
 *
 * Fortify sudah menyediakan mesinnya (TOTP, QR, kode pemulihan) tapi alurnya
 * dirancang untuk login berbasis sesi web. SPA di sini login lewat API dan
 * menerima token Sanctum, jadi tantangan 2FA-nya perlu dijembatani sendiri:
 *
 *   1. POST /auth/login          kata sandi benar + 2FA aktif -> TIDAK terbit
 *                                token, melainkan tiket tantangan berumur pendek
 *   2. POST /auth/two-factor-challenge   tiket + kode 6 digit -> token terbit
 *
 * Tiket disimpan di cache, sekali pakai, dan tidak memuat apa pun selain id
 * pengguna. Kalau cache hilang, dampaknya pengguna mengulang login — bukan
 * terkunci permanen.
 */
class TwoFactorController extends ApiController
{
    /** Umur tiket tantangan. Cukup untuk membuka aplikasi authenticator. */
    private const CHALLENGE_TTL_SECONDS = 300;

    /** Kode TOTP hanya 6 digit, jadi percobaan wajib dibatasi ketat. */
    private const MAX_CHALLENGE_ATTEMPTS = 5;

    private const CHALLENGE_LOCKOUT_SECONDS = 900;

    public function __construct(private readonly TwoFactorAuthenticationProvider $provider) {}

    /**
     * Terbitkan tiket tantangan setelah kata sandi terverifikasi.
     * Dipanggil AuthController, bukan rute HTTP.
     */
    public static function issueChallenge(User $user): array
    {
        $ticket = bin2hex(random_bytes(32));

        Cache::put(self::cacheKey($ticket), $user->getKey(), self::CHALLENGE_TTL_SECONDS);

        return [
            'two_factor_required' => true,
            'challenge' => $ticket,
            'expires_in' => self::CHALLENGE_TTL_SECONDS,
        ];
    }

    private static function cacheKey(string $ticket): string
    {
        // Simpan digest saja: bocornya isi cache tidak langsung bisa diputar ulang.
        return '2fa:challenge:'.hash('sha256', $ticket);
    }

    /**
     * POST /api/v1/auth/two-factor-challenge  (publik — tiket adalah otorisasinya)
     *
     * Menerima kode TOTP ATAU kode pemulihan. Kode pemulihan langsung dicoret
     * setelah dipakai supaya tidak bisa diputar ulang.
     */
    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $key = '2fa-attempt:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_CHALLENGE_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => ['Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.'],
            ]);
        }

        $cacheKey = self::cacheKey($data['challenge']);
        $userId = Cache::get($cacheKey);
        $user = $userId ? User::find($userId) : null;

        if (! $user || ! $user->hasEnabledTwoFactorAuthentication()) {
            RateLimiter::hit($key, self::CHALLENGE_LOCKOUT_SECONDS);
            throw ValidationException::withMessages([
                'challenge' => ['Sesi verifikasi tidak valid atau sudah kedaluwarsa. Silakan masuk ulang.'],
            ]);
        }

        $code = trim($data['code']);
        $secret = decrypt($user->two_factor_secret);
        $verified = $this->provider->verify($secret, $code);

        if (! $verified) {
            // Bukan TOTP — coba sebagai kode pemulihan.
            $match = collect($user->recoveryCodes())->first(
                fn ($stored) => hash_equals($stored, $code)
            );

            if ($match) {
                $user->replaceRecoveryCode($match);
                $verified = true;
            }
        }

        if (! $verified) {
            RateLimiter::hit($key, self::CHALLENGE_LOCKOUT_SECONDS);
            Log::warning('auth.2fa.failed', ['user_id' => $user->getKey(), 'ip' => $request->ip()]);
            throw ValidationException::withMessages([
                'code' => ['Kode verifikasi salah.'],
            ]);
        }

        // Sekali pakai: tiket dibuang sebelum token terbit.
        Cache::forget($cacheKey);
        RateLimiter::clear($key);

        $abilities = $user->isAdmin ? ['admin', 'employee'] : ['employee'];
        $token = $user->createToken('2fa:'.$request->ip(), $abilities)->plainTextToken;
        $refreshCookie = app(\App\Services\RefreshTokenService::class)->issue($user, $request);

        Log::info('auth.2fa.success', ['user_id' => $user->getKey(), 'ip' => $request->ip()]);

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ])->withCookie($refreshCookie);
    }

    /**
     * POST /api/v1/auth/two-factor/enable
     * Menyiapkan rahasia + kode pemulihan, TAPI belum mengaktifkan. Aktif hanya
     * setelah confirm(), supaya tidak ada yang terkunci karena salah memindai.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => encrypt($this->provider->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(
                collect()->times(8, fn () => RecoveryCode::generate())->all()
            )),
            'two_factor_confirmed_at' => null,
        ])->save();

        return $this->success([
            'qr_svg' => $user->twoFactorQrCodeSvg(),
            'secret' => decrypt($user->two_factor_secret),
            'recovery_codes' => $user->recoveryCodes(),
        ], 'Pindai kode QR, lalu konfirmasi dengan kode 6 digit.');
    }

    /**
     * POST /api/v1/auth/two-factor/confirm
     * Baru di sini 2FA benar-benar menyala.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->two_factor_secret) {
            throw ValidationException::withMessages([
                'code' => ['Belum ada pendaftaran 2FA. Mulai dari langkah aktifkan.'],
            ]);
        }

        if (! $this->provider->verify(decrypt($user->two_factor_secret), trim($data['code']))) {
            throw ValidationException::withMessages(['code' => ['Kode verifikasi salah.']]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        Log::info('auth.2fa.enabled', ['user_id' => $user->getKey()]);

        return $this->success([
            'recovery_codes' => $user->recoveryCodes(),
        ], 'Verifikasi dua langkah aktif. Simpan kode pemulihan di tempat aman.');
    }

    /**
     * DELETE /api/v1/auth/two-factor
     * Wajib kata sandi: kalau token dicuri, penyerang tidak bisa mematikan 2FA.
     */
    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['Kata sandi salah.']]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Log::warning('auth.2fa.disabled', ['user_id' => $user->getKey(), 'ip' => $request->ip()]);

        return $this->success(null, 'Verifikasi dua langkah dimatikan.');
    }

    /** GET /api/v1/auth/two-factor — status + sisa kode pemulihan. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'pending_confirmation' => (bool) $user->two_factor_secret && ! $user->two_factor_confirmed_at,
            'recovery_codes_left' => $user->two_factor_recovery_codes ? count($user->recoveryCodes()) : 0,
        ]);
    }

    /** POST /api/v1/auth/two-factor/recovery-codes — terbitkan ulang. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'code' => ['Verifikasi dua langkah belum aktif.'],
            ]);
        }

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode(
                collect()->times(8, fn () => RecoveryCode::generate())->all()
            )),
        ])->save();

        return $this->success([
            'recovery_codes' => $user->recoveryCodes(),
        ], 'Kode pemulihan baru dibuat. Kode lama tidak berlaku lagi.');
    }
}

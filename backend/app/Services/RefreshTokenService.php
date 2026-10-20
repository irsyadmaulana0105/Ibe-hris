<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Refresh token management dengan rotation & reuse detection.
 *
 * Cookie domain '.ibereality.com' supaya bisa ke-share antar subdomain
 * (portal.ibereality.com, app-erp.ibereality.com, crm.ibereality.com, dsb).
 *
 * Untuk cross-eTLD calls (portal.ibereality.com → app-erp.ibereality.com API),
 * SameSite=None + Secure + CORS Access-Control-Allow-Credentials=true
 * memungkinkan cookie ikut. Firefox/Chrome modern: works. Safari ITP
 * mungkin block - fallback gracefully ke soft logout modal yang sudah ada.
 */
class RefreshTokenService
{
    public const COOKIE_NAME = 'ibe_refresh';
    public const LIFETIME_MINUTES = 480;   // 8 jam - match Sanctum lama

    /**
     * Issue refresh token baru dan kembalikan cookie object siap di-attach
     * ke response. Caller wajib `->withCookie($cookie)` ke response.
     */
    public function issue(User $user, Request $request): SymfonyCookie
    {
        $plain = Str::random(80);
        $hash  = hash('sha256', $plain);

        RefreshToken::create([
            'token_hash' => $hash,
            'user_id'    => $user->id,
            'expires_at' => Carbon::now()->addMinutes(self::LIFETIME_MINUTES),
            'ip_address' => Str::limit((string) $request->ip(), 45, ''),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);

        return $this->makeCookie($plain);
    }

    /**
     * Rotate: validate cookie, mark used, issue new, link chain.
     * Throw kalau invalid/expired/reuse-detected.
     */
    public function rotate(Request $request): array
    {
        $plain = $request->cookie(self::COOKIE_NAME);
        if (!$plain) {
            throw new \RuntimeException('NO_REFRESH_COOKIE');
        }

        $hash = hash('sha256', $plain);
        $token = RefreshToken::where('token_hash', $hash)->first();

        if (!$token) {
            throw new \RuntimeException('REFRESH_NOT_FOUND');
        }

        // Reuse detection: kalau token sudah used_at != NULL → breach signal,
        // revoke seluruh chain user → force re-login + alert log.
        if ($token->used_at !== null) {
            $this->revokeAllForUser($token->user_id);
            Log::warning('refresh_token reuse detected - possible breach', [
                'user_id'       => $token->user_id,
                'token_id'      => $token->id,
                'used_at'       => $token->used_at?->toIso8601String(),
                'replaced_by'   => $token->replaced_by_id,
                'reuse_ip'      => $request->ip(),
                'reuse_ua'      => Str::limit((string) $request->userAgent(), 120, ''),
            ]);
            throw new \RuntimeException('REFRESH_REUSE_DETECTED');
        }

        if ($token->expires_at->isPast()) {
            throw new \RuntimeException('REFRESH_EXPIRED');
        }

        $user = $token->user;
        if (!$user) {
            throw new \RuntimeException('USER_GONE');
        }

        // User sudah off-boarded - revoke refresh chain + tolak. SPA akan
        // dapat 401 → soft logout modal.
        if ($user->is_inactive) {
            $this->revokeAllForUser($user->id);
            throw new \RuntimeException('USER_INACTIVE');
        }

        return DB::transaction(function () use ($user, $token, $request) {
            $plainNew = Str::random(80);
            $hashNew  = hash('sha256', $plainNew);

            $new = RefreshToken::create([
                'token_hash' => $hashNew,
                'user_id'    => $user->id,
                'expires_at' => Carbon::now()->addMinutes(self::LIFETIME_MINUTES),
                'ip_address' => Str::limit((string) $request->ip(), 45, ''),
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            ]);

            $token->update([
                'used_at'        => Carbon::now(),
                'replaced_by_id' => $new->id,
            ]);

            return ['user' => $user, 'cookie' => $this->makeCookie($plainNew)];
        });
    }

    public function revokeAllForUser(string $userId): void
    {
        RefreshToken::where('user_id', $userId)
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]);
    }

    /**
     * Revoke HANYA refresh token yang match dengan cookie di request ini -
     * untuk single-device logout. Sesi user di device lain tetap valid.
     * No-op kalau request tidak punya cookie.
     */
    public function revokeByCurrentCookie(Request $request): void
    {
        $plain = $request->cookie(self::COOKIE_NAME);
        if (!$plain) return;
        $hash = hash('sha256', $plain);
        RefreshToken::where('token_hash', $hash)
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]);
    }

    /**
     * Clear cookie response (logout). Browser hapus cookie karena MaxAge=0.
     */
    public function forgetCookie(): SymfonyCookie
    {
        return Cookie::make(
            self::COOKIE_NAME, '', 0,
            '/', $this->domain(), true, true, false, 'none'
        );
    }

    private function makeCookie(string $plain): SymfonyCookie
    {
        return Cookie::make(
            name: self::COOKIE_NAME,
            value: $plain,
            minutes: self::LIFETIME_MINUTES,
            path: '/',
            domain: $this->domain(),
            secure: true,          // HTTPS only
            httpOnly: true,        // JS tidak bisa baca
            raw: false,
            sameSite: 'none',      // cross-site (.com → .id) allowed
        );
    }

    /**
     * Cookie domain '.ibereality.com' di production agar share antar subdomain
     * induk. Di local/test env null → cookie ke-bind ke host saat ini.
     */
    private function domain(): ?string
    {
        return config('session.refresh_cookie_domain')
            ?? (app()->environment('production') ? '.ibereality.com' : null);
    }
}

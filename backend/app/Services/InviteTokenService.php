<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteTokenService
{
    private const TTL_HOURS = 72;

    /**
     * Generate a new invite token for the given employee.
     * Stores the SHA-256 hash in the DB and returns the plain token once.
     */
    public function generate(User $employee): string
    {
        $plainToken = Str::random(64);

        $employee->update([
            'invite_token'            => hash('sha256', $plainToken),
            'invite_token_expires_at' => Carbon::now()->addHours(self::TTL_HOURS),
            'invite_accepted_at'      => null,
        ]);

        return $plainToken;
    }

    /**
     * Accept an invite: validate the plain token, set the employee's password,
     * and consume (clear) the token so it can never be reused.
     */
    public function accept(string $plainToken, string $password): User
    {
        $hashed = hash('sha256', $plainToken);

        $employee = User::withoutGlobalScopes()
            ->where('invite_token', $hashed)
            ->whereNull('invite_accepted_at')
            ->where('invite_token_expires_at', '>', now())
            ->first();

        if (!$employee) {
            throw ValidationException::withMessages([
                'token' => ['Link undangan tidak valid atau sudah kadaluarsa.'],
            ]);
        }

        $employee->update([
            'password'               => Hash::make($password),
            'invite_token'           => null,
            'invite_token_expires_at' => null,
            'invite_accepted_at'     => now(),
        ]);

        return $employee->fresh();
    }

    /** Return TTL so callers can compute the expiry timestamp. */
    public function ttlHours(): int
    {
        return self::TTL_HOURS;
    }

    public function activationUrl(string $plainToken): string
    {
        $frontendUrl = trim(explode(' ', (string) config('app.frontend_url'))[0]);

        return rtrim($frontendUrl, '/').'/set-password?token='.urlencode($plainToken);
    }
}

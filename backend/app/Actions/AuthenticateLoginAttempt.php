<?php

namespace App\Actions;

use App\Helpers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthenticateLoginAttempt
{
    public function __invoke(Request $request)
    {
        $this->validateRecaptcha($request);

        if (filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $request->email)->first();
        } else {
            $user = User::where('phone', $request->email)->first();
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            return null;
        }

        if (!$user->isAdmin) {
            throw ValidationException::withMessages([
                'email' => 'Akun employee tidak bisa login di portal admin. Gunakan frontend employee: '
                    . Helpers::getFrontendUrl(),
            ]);
        }

        return $user;
    }

    protected function validateRecaptcha(Request $request): void
    {
        if (!config('services.recaptcha.enabled')) {
            return;
        }

        $secret = config('services.recaptcha.secret');
        if (!$secret) {
            return;
        }

        $token = $request->input('g-recaptcha-response');
        if (!$token) {
            Log::warning('reCAPTCHA missing token on login', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            throw ValidationException::withMessages([
                'email' => __('recaptcha.failed'),
            ]);
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('reCAPTCHA request failed', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'email' => __('recaptcha.failed'),
            ]);
        }

        $payload = $response->json();
        if (!$response->ok() || !data_get($payload, 'success')) {
            Log::warning('reCAPTCHA verification failed', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => $response->status(),
                'payload' => $payload,
            ]);
            throw ValidationException::withMessages([
                'email' => __('recaptcha.failed'),
            ]);
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh token rotation - pasangan dengan Sanctum access token short-lived.
 *
 * Alur:
 *   Login → access token (60 min) di Authorization header
 *         + refresh token (8 jam) di HttpOnly cookie scope .ibereality.com
 *   Access expire → /auth/refresh dengan cookie → access baru + refresh rotate
 *   Refresh expire / reuse detected → revoke chain → soft logout
 *
 * Defense vs XSS:
 *   Walaupun localStorage access token bocor lewat XSS, attacker hanya punya
 *   60 menit (vs 8 jam sebelumnya). Refresh token tidak bisa di-curi karena
 *   HttpOnly - JS tidak bisa baca.
 *
 * Defense vs replay:
 *   replaced_by_id chain - kalau refresh token yang sudah used_at != NULL
 *   dipakai lagi (artinya: dua client pakai token sama → token bocor),
 *   revoke seluruh chain user → force re-login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $t) {
            $t->id();
            $t->char('token_hash', 64)->unique()->comment('sha256 hash plain token');
            $t->char('user_id', 26);
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable()->comment('Set saat token di-redeem; replay setelah ini = breach');
            $t->unsignedBigInteger('replaced_by_id')->nullable()->comment('FK ke row baru hasil rotation');
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamps();

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};

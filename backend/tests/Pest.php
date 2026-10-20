<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

// ── Test helpers ──────────────────────────────────────────────────────────────

/**
 * Create an employee user + issue a Sanctum token.
 * Returns [$user, $token].
 */
function employeeWithToken(array $overrides = []): array
{
    $user  = \App\Models\User::factory()->create(array_merge(
        ['group' => 'user', 'company_id' => 1, 'employment_status' => 'permanent'],
        $overrides
    ));
    $token = $user->createToken('test')->plainTextToken;
    return [$user, $token];
}

/**
 * Create an admin user + issue a Sanctum token.
 * Returns [$admin, $token].
 */
function adminWithToken(array $overrides = []): array
{
    $admin = \App\Models\User::factory()->admin()->create(array_merge(
        ['company_id' => 1],
        $overrides
    ));
    $token = $admin->createToken('test', ['admin', 'employee'])->plainTextToken;
    return [$admin, $token];
}

/**
 * Return auth headers for Sanctum token requests.
 */
function bearerHeaders(string $token): array
{
    return [
        'Authorization' => "Bearer {$token}",
        'Accept'        => 'application/json',
    ];
}

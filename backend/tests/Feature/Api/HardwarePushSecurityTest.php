<?php

use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\User;

uses()->group('api');

it('requires a fresh signed non-replayed hardware push', function () {
    config()->set('security.hardware_push_require_signature', true);
    $secret = 'devsec_test_'.str_repeat('x', 32);
    AttendanceDevice::query()->create([
        'company_id' => 1,
        'name' => 'Secure test device',
        'brand' => 'zkteco',
        'serial_number' => 'ZK-SECURE-1',
        'push_secret' => $secret,
        'enabled' => true,
    ]);
    User::factory()->create(['company_id' => 1, 'nip' => '9988776655443322']);

    $payload = json_encode(['events' => [[
        'device_user_id' => '9988776655443322',
        'timestamp' => '2026-08-02 08:00:00',
        'type' => 'in',
    ]]], JSON_UNESCAPED_SLASHES);

    $this->call('POST', '/api/v1/hardware/push', [], [], [], [
        'HTTP_X_DEVICE_SERIAL' => 'ZK-SECURE-1',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertUnauthorized();

    $timestamp = (string) now()->timestamp;
    $nonce = 'nonce-1234567890abcdef';
    $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$payload, $secret);
    $server = [
        'HTTP_X_DEVICE_SERIAL' => 'ZK-SECURE-1',
        'HTTP_X_DEVICE_TIMESTAMP' => $timestamp,
        'HTTP_X_DEVICE_NONCE' => $nonce,
        'HTTP_X_DEVICE_SIGNATURE' => 'sha256='.$signature,
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', '/api/v1/hardware/push', [], [], [], $server, $payload)
        ->assertOk()
        ->assertJsonPath('data.processed', 1);
    expect(Attendance::query()->count())->toBe(1);

    $this->call('POST', '/api/v1/hardware/push', [], [], [], $server, $payload)
        ->assertStatus(409);
});

it('rejects an expired hardware signature', function () {
    config()->set('security.hardware_push_require_signature', true);
    $secret = 'devsec_test_'.str_repeat('y', 32);
    AttendanceDevice::query()->create([
        'company_id' => 1, 'name' => 'Expired test device', 'brand' => 'zkteco',
        'serial_number' => 'ZK-EXPIRED-1', 'push_secret' => $secret, 'enabled' => true,
    ]);
    $payload = json_encode(['events' => []]);
    $timestamp = (string) now()->subMinutes(10)->timestamp;
    $nonce = 'nonce-expired-12345678';

    $this->call('POST', '/api/v1/hardware/push', [], [], [], [
        'HTTP_X_DEVICE_SERIAL' => 'ZK-EXPIRED-1',
        'HTTP_X_DEVICE_TIMESTAMP' => $timestamp,
        'HTTP_X_DEVICE_NONCE' => $nonce,
        'HTTP_X_DEVICE_SIGNATURE' => hash_hmac('sha256', $timestamp."\n".$nonce."\n".$payload, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertUnauthorized();
});

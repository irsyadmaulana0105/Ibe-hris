<?php

/**
 * Audit & pengerasan verifikasi wajah (19 Okt 2026):
 * - skor kecocokan / wajah hidup / antispoof dicatat per absen dan ditandai,
 *   mode "shadow" tidak menolak, mode "enforce" menolak;
 * - token rahasia dikirim ke engine;
 * - skor hanya terlihat di rute admin;
 * - foto pendaftaran wajah disimpan & tampil untuk HR, ikut berpindah saat
 *   disetujui dan terhapus saat ditolak;
 * - template biometrik tidak pernah ikut terserialisasi.
 */

use App\Models\Attendance;
use App\Models\User;
use App\Support\FotoWajah;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses()->group('api', 'attendance', 'face');

function selfieUji(): string
{
    $image = UploadedFile::fake()->image('selfie.jpg', 4, 4);

    return 'data:image/jpeg;base64,'.base64_encode(file_get_contents($image->getRealPath()));
}

function engineCocok(array $quality = [], int $faceCount = 1, float $similarity = 0.82): void
{
    Http::fake([
        rtrim((string) config('face.engine_url'), '/').'/compare' => Http::response([
            'matched' => true, 'similarity' => $similarity, 'threshold' => 0.6, 'faceCount' => $faceCount,
            'quality' => $quality + ['liveness' => 0.97, 'antispoof' => 0.91, 'antispoofAvailable' => true, 'faceSize' => 180, 'faceScore' => 0.9],
        ], 200),
        rtrim((string) config('face.engine_url'), '/').'/extract' => Http::response([
            'descriptor' => array_fill(0, 1024, 0.2), 'faceCount' => $faceCount,
            'quality' => $quality + ['liveness' => 0.95, 'antispoof' => 0.9, 'antispoofAvailable' => true, 'faceSize' => 200],
        ], 200),
    ]);
}

function absenMasuk($test, string $token)
{
    return $test->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => selfieUji(), 'latitude' => -6.2, 'longitude' => 106.8, 'address' => 'Jakarta',
    ], bearerHeaders($token));
}

beforeEach(function () {
    config(['face.liveness_mode' => 'shadow', 'face.engine_token' => 'rahasia-uji']);
    Storage::fake(FotoWajah::DISK);
});

it('records face scores on the attendance without flags for a clean selfie', function () {
    [$user, $token] = employeeWithToken(['face_descriptor' => json_encode(array_fill(0, 1024, 0.1))]);
    engineCocok();

    absenMasuk($this, $token)->assertCreated();

    $check = Attendance::where('user_id', $user->id)->first()->face_check_in;
    expect($check['similarity'])->toBe(0.82)
        ->and($check['liveness'])->toBe(0.97)
        ->and($check['antispoof'])->toBe(0.91)
        ->and($check['flags'])->toBe([])
        ->and($check['mode'])->toBe('shadow');

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/compare')
        && $r->header('x-face-engine-token') === ['rahasia-uji']);
});

it('only flags a suspected photo-of-photo in shadow mode', function () {
    [$user, $token] = employeeWithToken(['face_descriptor' => json_encode(array_fill(0, 1024, 0.1))]);
    engineCocok(['liveness' => 0.12, 'antispoof' => 0.08], faceCount: 2, similarity: 0.62);

    absenMasuk($this, $token)->assertCreated();

    expect(Attendance::where('user_id', $user->id)->first()->face_check_in['flags'])
        ->toBe(['liveness_low', 'antispoof_low', 'multi_face', 'borderline']);
});

it('rejects a suspected photo-of-photo in enforce mode', function () {
    config(['face.liveness_mode' => 'enforce']);
    [$user, $token] = employeeWithToken(['face_descriptor' => json_encode(array_fill(0, 1024, 0.1))]);
    engineCocok(['liveness' => 0.12]);

    absenMasuk($this, $token)->assertUnprocessable();
    expect(Attendance::where('user_id', $user->id)->exists())->toBeFalse();
});

it('shows face scores to admins but not to the employee', function () {
    [$user, $token] = employeeWithToken(['face_descriptor' => json_encode(array_fill(0, 1024, 0.1))]);
    engineCocok();
    absenMasuk($this, $token)->assertCreated();

    $milikSendiri = $this->getJson('/api/v1/attendances', bearerHeaders($token))->assertOk()->json('data.0');
    expect($milikSendiri)->not->toHaveKey('face_check_in');

    app('auth')->forgetGuards();
    [, $adminToken] = adminWithToken(['company_id' => $user->company_id]);
    // Grid rekap HR (karyawan × tanggal).
    $baris = collect($this->getJson('/api/v1/admin/attendances?date='.today()->toDateString(), bearerHeaders($adminToken))
        ->assertOk()->json('data'))->firstWhere('id', $user->id);
    expect($baris['attendances'][0]['face_check_in']['similarity'] ?? null)->toBe(0.82);

    // Detail satu absen.
    $id = Attendance::where('user_id', $user->id)->value('id');
    $detail = $this->getJson("/api/v1/admin/attendances/{$id}", bearerHeaders($adminToken))->assertOk()->json('data');
    expect($detail['face_check_in']['flags'] ?? null)->toBe([]);
});

it('keeps the enrollment photo for HR review and moves it on approval', function () {
    [$user, $token] = employeeWithToken(['face_descriptor' => null]);
    engineCocok();

    $this->postJson('/api/v1/auth/face-setup', ['selfie_base64' => selfieUji()], bearerHeaders($token))->assertOk();

    $user->refresh();
    expect($user->face_photo_pending)->toContain('face-enrollments/')->toContain((string) $user->id)
        ->and($user->face_quality_pending['liveness'])->toBe(0.95);
    Storage::disk(FotoWajah::DISK)->assertExists($user->face_photo_pending);

    app('auth')->forgetGuards();
    [, $adminToken] = adminWithToken(['company_id' => $user->company_id]);
    $row = collect($this->getJson('/api/v1/admin/face-approvals', bearerHeaders($adminToken))->assertOk()->json('data'))
        ->firstWhere('id', $user->id);
    expect($row['enrollment_photo_url'])->toContain('signature=');
    $this->get($row['enrollment_photo_url'])->assertOk();
    $this->get(strtok($row['enrollment_photo_url'], '?'))->assertForbidden();

    $foto = $user->face_photo_pending;
    $this->postJson("/api/v1/admin/face-approvals/{$user->id}/approve", [], bearerHeaders($adminToken))->assertOk();
    $user->refresh();
    expect($user->face_photo)->toBe($foto)->and($user->face_photo_pending)->toBeNull()
        ->and($user->face_descriptor)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'face_approval.approved']);
    $log = \Illuminate\Support\Facades\DB::table('audit_logs')->where('action', 'face_approval.approved')->latest('id')->first();
    $meta = json_decode($log->metadata ?? $log->meta ?? '{}', true);
    expect($meta['registration_type'] ?? null)->toBe('new');
});

it('deletes the enrollment photo when HR rejects it', function () {
    [$user, $token] = employeeWithToken(['face_descriptor' => null]);
    engineCocok();
    $this->postJson('/api/v1/auth/face-setup', ['selfie_base64' => selfieUji()], bearerHeaders($token))->assertOk();
    $foto = $user->refresh()->face_photo_pending;

    app('auth')->forgetGuards();
    [, $adminToken] = adminWithToken(['company_id' => $user->company_id]);
    $this->postJson("/api/v1/admin/face-approvals/{$user->id}/reject", [], bearerHeaders($adminToken))->assertOk();

    Storage::disk(FotoWajah::DISK)->assertMissing($foto);
    expect($user->refresh()->face_photo_pending)->toBeNull();
});

it('never serializes the biometric face template', function () {
    $user = User::factory()->create(['face_descriptor' => '[0.1]', 'face_descriptor_pending' => '[0.2]']);

    expect($user->toArray())->not->toHaveKey('face_descriptor')->not->toHaveKey('face_descriptor_pending');
});

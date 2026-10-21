<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\JobTitle;
use App\Models\OfficeLocation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

uses()->group('api', 'attendance');

function descriptorJson(float $value): string
{
    return json_encode(array_fill(0, 1024, $value), JSON_THROW_ON_ERROR);
}

function fakeSelfie(): string
{
    $image = UploadedFile::fake()->image('selfie.jpg', 2, 2);

    return 'data:image/jpeg;base64,'.base64_encode(file_get_contents($image->getRealPath()));
}

it('rejects clock in when face is not registered', function () {
    [, $token] = employeeWithToken([
        'face_descriptor' => null,
        'face_descriptor_pending' => null,
    ]);

    $this->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => fakeSelfie(),
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Wajah Anda belum terdaftar. Silakan daftar wajah terlebih dahulu sebelum absen.');

    expect(Attendance::count())->toBe(0);
});

it('rejects clock in when face approval is still pending', function () {
    [, $token] = employeeWithToken([
        'face_descriptor' => null,
        'face_descriptor_pending' => descriptorJson(0.10),
    ]);

    $this->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => fakeSelfie(),
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Pendaftaran wajah Anda masih menunggu persetujuan admin. Anda belum bisa absen.');

    expect(Attendance::count())->toBe(0);
});

it('reports a server fault, not a missing selfie, when the sent body never arrives', function () {
    // 2 Okt 2026: /tmp penuh -> PHP kehilangan isi permintaan ~50 KB, dan
    // karyawan diberi tahu "Selfie base64 wajib diisi" padahal foto terkirim.
    [, $token] = employeeWithToken(['face_descriptor' => descriptorJson(0.10)]);

    $this->call('POST', '/api/v1/attendances/clock-in', [], [], [], [
        'CONTENT_LENGTH' => '51200',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], '')
        ->assertStatus(503)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'server sedang gangguan'));

    expect(Attendance::count())->toBe(0);
});

it('rejects clock in when captured selfie is missing', function () {
    [, $token] = employeeWithToken([
        'face_descriptor' => descriptorJson(0.10),
    ]);

    $this->postJson('/api/v1/attendances/clock-in', [], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('selfie_base64');

    expect(Attendance::count())->toBe(0);
});

it('rejects clock in when engine says face does not match', function () {
    [, $token] = employeeWithToken([
        'face_descriptor' => descriptorJson(0.10),
    ]);

    Http::fake([
        rtrim((string) config('face.engine_url'), '/').'/compare' => Http::response([
            'matched' => false,
            'similarity' => 0.34,
            'faceCount' => 1,
        ], 200),
    ]);

    $this->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => fakeSelfie(),
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Wajah tidak cocok dengan data terdaftar. Absen ditolak.');

    expect(Attendance::count())->toBe(0);
});

it('creates clock in attendance when engine verifies the selfie', function () {
    [$user, $token] = employeeWithToken([
        'face_descriptor' => descriptorJson(0.10),
    ]);

    Http::fake([
        rtrim((string) config('face.engine_url'), '/').'/compare' => Http::response([
            'matched' => true,
            'similarity' => 0.82,
            'faceCount' => 1,
        ], 200),
    ]);

    $this->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => fakeSelfie(),
        'latitude' => -6.2,
        'longitude' => 106.8,
        'address' => 'Jakarta',
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('message', 'Absen masuk berhasil.')
        ->assertJsonStructure(['data' => ['id', 'date', 'status', 'time_in']]);

    $this->assertDatabaseHas('attendances', [
        'user_id' => $user->id,
        'selfie_in_address' => 'Jakarta',
    ]);
});

it('allows sales mobile attendance anywhere with fresh precise gps and verified face', function () {
    [$user, $token] = employeeWithToken([
        'face_descriptor' => descriptorJson(0.10),
        'attendance_location_mode' => 'mobile_field',
    ]);

    Http::fake([
        rtrim((string) config('face.engine_url'), '/').'/compare' => Http::response([
            'matched' => true,
            'similarity' => 0.91,
            'faceCount' => 1,
        ], 200),
    ]);

    $this->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => fakeSelfie(),
        'latitude' => -7.2575,
        'longitude' => 112.7521,
        'location_accuracy' => 18.4,
        'location_recorded_at' => now()->toIso8601String(),
        'device_id' => 'demo-sales-device-001',
    ], bearerHeaders($token))->assertCreated();

    $this->assertDatabaseHas('attendances', [
        'user_id' => $user->id,
        'location_policy' => 'mobile_field',
        'attendance_device_id' => 'demo-sales-device-001',
    ]);
});

it('rejects sales mobile attendance when gps evidence is stale or imprecise', function () {
    [, $token] = employeeWithToken([
        'face_descriptor' => descriptorJson(0.10),
        'attendance_location_mode' => 'mobile_field',
    ]);

    Http::fake([
        rtrim((string) config('face.engine_url'), '/').'/compare' => Http::response([
            'matched' => true,
            'similarity' => 0.91,
            'faceCount' => 1,
        ], 200),
    ]);

    $this->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => fakeSelfie(),
        'latitude' => -7.2575,
        'longitude' => 112.7521,
        'location_accuracy' => 250,
        'location_recorded_at' => now()->subMinutes(10)->toIso8601String(),
        'device_id' => 'demo-sales-device-001',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Akurasi lokasi harus 100 meter atau lebih baik. Aktifkan GPS presisi lalu coba lagi.');
});

function activeOfficeFor(User $user): OfficeLocation
{
    return OfficeLocation::withoutGlobalScopes()->create([
        'company_id' => $user->company_id,
        'name' => 'Kantor Uji',
        'address' => 'Jakarta',
        'latitude' => -6.2,
        'longitude' => 106.8,
        'radius_meters' => 100,
        'is_active' => true,
    ]);
}

it('rejects clock in outside the office radius', function () {
    [$user, $token] = employeeWithToken(['face_descriptor' => descriptorJson(0.10)]);
    activeOfficeFor($user);

    $this->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => fakeSelfie(),
        'latitude' => -7.2575,
        'longitude' => 112.7521,
    ], bearerHeaders($token))->assertUnprocessable();

    expect(Attendance::count())->toBe(0);
});

it('rejects clock in without coordinates when the company has an active office', function () {
    [$user, $token] = employeeWithToken(['face_descriptor' => descriptorJson(0.10)]);
    activeOfficeFor($user);

    $this->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => fakeSelfie(),
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Lokasi Anda tidak terbaca. Izinkan akses lokasi (GPS) lalu coba lagi.');

    expect(Attendance::count())->toBe(0);
});

it('rejects clock in when only one coordinate is sent and the company has an active office', function () {
    [$user, $token] = employeeWithToken(['face_descriptor' => descriptorJson(0.10)]);
    activeOfficeFor($user);

    $this->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => fakeSelfie(),
        'latitude' => -6.2,
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Lokasi Anda tidak terbaca. Izinkan akses lokasi (GPS) lalu coba lagi.');

    expect(Attendance::count())->toBe(0);
});

it('still allows clock in without coordinates when the company has no active office', function () {
    [$user, $token] = employeeWithToken(['face_descriptor' => descriptorJson(0.10)]);

    Http::fake([
        rtrim((string) config('face.engine_url'), '/').'/compare' => Http::response([
            'matched' => true,
            'similarity' => 0.82,
            'faceCount' => 1,
        ], 200),
    ]);

    $this->postJson('/api/v1/attendances/clock-in', [
        'selfie_base64' => fakeSelfie(),
    ], bearerHeaders($token))->assertCreated();
});

it('rejects clock out without coordinates when the company has an active office', function () {
    [$user, $token] = employeeWithToken(['face_descriptor' => descriptorJson(0.10)]);
    activeOfficeFor($user);

    Attendance::create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'time_in' => '08:00:00',
        'status' => 'present',
    ]);

    $this->postJson('/api/v1/attendances/clock-out', [
        'selfie_base64' => fakeSelfie(),
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Lokasi Anda tidak terbaca. Izinkan akses lokasi (GPS) lalu coba lagi.');
});

it('rejects clock out when engine says face does not match', function () {
    [$user, $token] = employeeWithToken([
        'face_descriptor' => descriptorJson(0.10),
    ]);

    Attendance::create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'time_in' => '08:00:00',
        'status' => 'present',
    ]);

    Http::fake([
        rtrim((string) config('face.engine_url'), '/').'/compare' => Http::response([
            'matched' => false,
            'similarity' => 0.28,
            'faceCount' => 1,
        ], 200),
    ]);

    $this->postJson('/api/v1/attendances/clock-out', [
        'selfie_base64' => fakeSelfie(),
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Wajah tidak cocok dengan data terdaftar. Absen ditolak.');
});

it('admin can list company attendance grid with workforce references', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $division = Division::create(['company_id' => 1, 'name' => 'Operations']);
    $jobTitle = JobTitle::create(['company_id' => 1, 'division_id' => $division->id, 'name' => 'Supervisor']);
    $otherCompany = Company::create(['name' => 'Other Company', 'slug' => 'other-company']);

    $employee = User::factory()->create([
        'company_id' => 1,
        'group' => 'user',
        'division_id' => $division->id,
        'job_title_id' => $jobTitle->id,
        'name' => 'Employee Attendance',
    ]);

    User::factory()->create([
        'company_id' => $otherCompany->id,
        'group' => 'user',
        'division_id' => null,
        'job_title_id' => null,
        'name' => 'Other Company Employee',
    ]);

    Attendance::create([
        'user_id' => $employee->id,
        'date' => '2026-04-23',
        'time_in' => '08:03:00',
        'time_out' => '17:01:00',
        'status' => 'present',
    ]);

    $this->getJson('/api/v1/admin/attendances?date=2026-04-23', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('dates.0', '2026-04-23')
        ->assertJsonPath('references.divisions.0.name', 'Operations')
        ->assertJsonPath('references.job_titles.0.name', 'Supervisor')
        ->assertJsonPath('data.0.name', 'Employee Attendance')
        ->assertJsonPath('data.0.attendances.0.status', 'present')
        ->assertJsonMissing(['name' => 'Other Company Employee']);
});

it('regular employees cannot access admin attendance grid', function () {
    [, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/attendances', bearerHeaders($token))
        ->assertForbidden();
});

it('admin can export attendance recap as csv', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $division = Division::create(['company_id' => 1, 'name' => 'Warehouse']);
    $jobTitle = JobTitle::create(['company_id' => 1, 'division_id' => $division->id, 'name' => 'Staff']);

    $employee = User::factory()->create([
        'company_id' => 1,
        'group' => 'user',
        'division_id' => $division->id,
        'job_title_id' => $jobTitle->id,
        'name' => 'Export Attendance',
        'email' => 'attendance-export@example.test',
        'nip' => 'ATT-001',
    ]);

    Attendance::create([
        'user_id' => $employee->id,
        'date' => '2026-04-10',
        'time_in' => '08:05:00',
        'time_out' => '17:00:00',
        'status' => 'late',
    ]);

    $response = $this->get('/api/v1/admin/attendances/export?month=2026-04', bearerHeaders($token));

    $response
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('content-disposition');

    expect($response->streamedContent())->toContain('Attendance Recap Report')
        ->toContain('Export Attendance')
        ->toContain('ATT-001')
        ->toContain('2026-04-10')
        ->toContain('LATE (08:05:00-17:00:00)');
});

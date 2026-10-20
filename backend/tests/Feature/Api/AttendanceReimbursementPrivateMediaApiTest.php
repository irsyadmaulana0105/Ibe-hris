<?php

use App\Exceptions\PrivateAssetIntegrityException;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\ReimbursementResource;
use App\Models\Attendance;
use App\Models\Reimbursement;
use App\Models\ReimbursementCategory;
use App\Services\AttendanceReimbursementMediaService;
use App\Services\PrivateMediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses()->group('api', 'private-media');

function arPrivateJpeg(): string
{
    $image = UploadedFile::fake()->image('private.jpg', 5, 4);

    return file_get_contents($image->getRealPath());
}

function arPrivateMetadata(string $prefix, array $stored, string $pathField): array
{
    return [
        $pathField => $stored['path'], $prefix.'_sha256' => $stored['sha256'],
        $prefix.'_size_bytes' => $stored['size'], $prefix.'_mime_type' => $stored['mime'],
        $prefix.'_width' => $stored['width'], $prefix.'_height' => $stored['height'],
    ];
}

function arResolveResource(object $resource, object $user): array
{
    $request = Request::create('/api/v1/test', 'GET');
    $request->setUserResolver(fn () => $user);

    return $resource->resolve($request);
}

it('streams attendance selfies only through authorized fixed-field routes and short signed URLs', function () {
    Storage::fake('local');
    Storage::fake('public');
    [$employee, $employeeToken] = employeeWithToken(['company_id' => 1]);
    [$hr, $hrToken] = adminWithToken(['company_id' => 1]);
    DB::table('companies')->insert([
        'id' => 2, 'name' => 'Private Tenant 2', 'slug' => 'private-tenant-2',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    [$outsider, $outsiderToken] = employeeWithToken(['company_id' => 2]);
    $attendance = Attendance::create([
        'user_id' => $employee->id, 'date' => now()->toDateString(),
    ]);
    $jpeg = arPrivateJpeg();
    $stored = app(PrivateMediaStorage::class)->storeBytes(
        1, AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
        $attendance->id, 'selfie-in', $jpeg, ['image/jpeg'],
    );
    $attendance->update(arPrivateMetadata('selfie_in', $stored, 'selfie_in_path'));

    $owner = $this->getJson(
        "/api/v1/attendances/{$attendance->id}/media/selfie-in",
        bearerHeaders($employeeToken),
    )->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($owner->baseResponse->getContent())->toBe($jpeg);

    app('auth')->forgetGuards();
    $this->getJson(
        "/api/v1/attendances/{$attendance->id}/media/selfie-in",
        bearerHeaders($hrToken),
    )->assertOk();
    app('auth')->forgetGuards();
    $this->getJson(
        "/api/v1/attendances/{$attendance->id}/media/selfie-in",
        bearerHeaders($outsiderToken),
    )->assertNotFound();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/attendances/{$attendance->id}/media/selfie-in")->assertUnauthorized();
    $this->getJson(
        "/api/v1/attendances/{$attendance->id}/media/not-a-field",
        bearerHeaders($employeeToken),
    )->assertNotFound();

    $attendance->refresh();
    $payload = arResolveResource(new AttendanceResource($attendance), $employee);
    expect($payload['selfie_in_available'])->toBeTrue()
        ->and($payload['selfie_in_url'])->toContain('/api/v1/private-media/attendances/')
        ->and($payload)->not->toHaveKeys(['selfie_in_path', 'selfie_in_sha256', 'attachment']);

    $signedUrl = $payload['selfie_in_url'];
    $this->getJson($signedUrl)->assertOk();
    $separator = str_contains($signedUrl, '?') ? '&' : '?';
    $this->getJson($signedUrl.$separator.'field=selfie-out')->assertForbidden();

    $now = now();
    Carbon::setTestNow($now);
    $expiring = app(AttendanceReimbursementMediaService::class)
        ->attendanceUrl($employee, $attendance, 'selfie-in');
    Carbon::setTestNow($now->copy()->addSeconds(121));
    $this->getJson($expiring)->assertForbidden();
    Carbon::setTestNow();
});

it('returns unavailable without raw paths or server errors for missing and tampered attendance media', function () {
    Storage::fake('local');
    [$employee, $token] = employeeWithToken(['company_id' => 1]);
    $attendance = Attendance::create([
        'user_id' => $employee->id, 'date' => now()->toDateString(),
        'selfie_in_unavailable_reason' => 'legacy_object_missing',
        'selfie_in_reference_fingerprint' => str_repeat('a', 64),
    ]);
    $payload = arResolveResource(new AttendanceResource($attendance), $employee);
    expect($payload['selfie_in_url'])->toBeNull()
        ->and($payload['selfie_in_available'])->toBeFalse();
    $this->getJson(
        "/api/v1/attendances/{$attendance->id}/media/selfie-in",
        bearerHeaders($token),
    )->assertStatus(410)->assertJsonMissing(['selfie_in_path']);

    $stored = app(PrivateMediaStorage::class)->storeBytes(
        1, AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
        $attendance->id, 'selfie-in', arPrivateJpeg(), ['image/jpeg'],
    );
    $attendance->update([
        ...arPrivateMetadata('selfie_in', $stored, 'selfie_in_path'),
        'selfie_in_unavailable_reason' => null,
        'selfie_in_reference_fingerprint' => null,
    ]);
    Storage::disk('local')->put($stored['path'], arPrivateJpeg().'tampered');
    $payload = arResolveResource(new AttendanceResource($attendance->fresh()), $employee);
    expect($payload['selfie_in_url'])->toBeNull()->and($payload['selfie_in_available'])->toBeFalse();
    $this->getJson(
        "/api/v1/attendances/{$attendance->id}/media/selfie-in",
        bearerHeaders($token),
    )->assertStatus(409);

    $wrongField = app(PrivateMediaStorage::class)->storeBytes(
        1, AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
        $attendance->id, 'selfie-out', arPrivateJpeg(), ['image/jpeg'],
    );
    $attendance->update(arPrivateMetadata('selfie_in', $wrongField, 'selfie_in_path'));
    $this->getJson(
        "/api/v1/attendances/{$attendance->id}/media/selfie-in",
        bearerHeaders($token),
    )->assertStatus(409);
});

it('authorizes reimbursement media for owner HR and finance but not another tenant', function () {
    Storage::fake('local');
    [$employee, $employeeToken] = employeeWithToken(['company_id' => 1]);
    [$hr, $hrToken] = adminWithToken(['company_id' => 1]);
    [$finance, $financeToken] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    DB::table('companies')->insert([
        'id' => 2, 'name' => 'Private Tenant 2', 'slug' => 'private-tenant-2',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    [, $outsiderToken] = employeeWithToken(['company_id' => 2]);
    $category = ReimbursementCategory::withoutGlobalScopes()->create([
        'company_id' => 1, 'name' => 'Private proof', 'is_active' => true,
    ]);
    $reimbursement = Reimbursement::withoutGlobalScopes()->create([
        'company_id' => 1, 'user_id' => $employee->id, 'category_id' => $category->id,
        'date' => now()->toDateString(),
        'event' => 'Private proof', 'unit_price' => 100, 'quantity' => 1,
        'total_amount' => 100, 'status' => 'settled',
    ]);
    $stored = app(PrivateMediaStorage::class)->storeBytes(
        1, AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
        $reimbursement->id, 'payment-proof', arPrivateJpeg(), ['image/jpeg'],
    );
    $reimbursement->update(arPrivateMetadata('payment_proof', $stored, 'payment_proof_path'));

    foreach ([$employeeToken, $hrToken, $financeToken] as $token) {
        app('auth')->forgetGuards();
        $this->getJson(
            "/api/v1/reimbursements/{$reimbursement->id}/media/payment-proof",
            bearerHeaders($token),
        )->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }
    app('auth')->forgetGuards();
    $this->getJson(
        "/api/v1/reimbursements/{$reimbursement->id}/media/payment-proof",
        bearerHeaders($outsiderToken),
    )->assertNotFound();

    $payload = arResolveResource(new ReimbursementResource($reimbursement->fresh()), $finance);
    expect($payload['payment_proof_available'])->toBeTrue()
        ->and($payload['payment_proof_url'])->toContain('/api/v1/private-media/reimbursements/')
        ->and($payload)->not->toHaveKeys(['payment_proof_path', 'payment_proof_sha256', 'receipt_pdf_path']);

    $signedUrl = $payload['payment_proof_url'];
    $this->getJson($signedUrl)->assertOk();
    $this->getJson(str_replace('/payment-proof?', '/receipt?', $signedUrl))->assertForbidden();

    $now = now();
    Carbon::setTestNow($now);
    $expiring = app(AttendanceReimbursementMediaService::class)
        ->reimbursementUrl($finance, $reimbursement->fresh(), 'payment-proof');
    Carbon::setTestNow($now->copy()->addSeconds(301));
    $this->getJson($expiring)->assertForbidden();
    Carbon::setTestNow();
});

it('strictly rejects disguised or malformed image data before a private write', function () {
    Storage::fake('local');
    $storage = app(PrivateMediaStorage::class);

    expect(fn () => $storage->storeImageDataUri(
        1, AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY, 1, 'selfie-in',
        'data:image/jpeg;base64,'.base64_encode('not-an-image'),
    ))->toThrow(PrivateAssetIntegrityException::class)
        ->and(fn () => $storage->storeImageDataUri(
            1, AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY, 1, 'selfie-in',
            'data:image/jpeg;base64,'.base64_encode(arPrivateJpeg())."\n",
        ))->toThrow(PrivateAssetIntegrityException::class)
        ->and(fn () => $storage->storeImageDataUri(
            1, AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY, 1, 'selfie-in',
            'data:image/png;base64,'.base64_encode(arPrivateJpeg()),
        ))->toThrow(PrivateAssetIntegrityException::class)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('keeps signed media credentials out of both gateway and inner nginx access logs', function () {
    // Konfigurasi gateway ada di deploy/ root repo; paket pengembangan HRIS tidak membawanya.
    if (! is_file(base_path('../deploy/gateway/ibe-platform-tls.conf'))) {
        $this->markTestSkipped('Konfigurasi deploy/ tidak tersedia di checkout ini.');
    }

    $tls = file_get_contents(base_path('../deploy/gateway/ibe-platform-tls.conf'));
    $http = file_get_contents(base_path('../deploy/gateway/ibe-platform-http.conf'));
    $inner = file_get_contents(base_path('../deploy/docker/nginx/default.conf'));

    foreach ([$tls, $http] as $gateway) {
        expect($gateway)->toContain('log_format ibe_private_media')
            ->toContain('"$request_method $uri $server_protocol"')
            ->toContain('location ^~ /api/v1/private-media/')
            ->toContain('ibe-private-media-access.log ibe_private_media')
            ->toContain('error_log /dev/null crit;');
        expect(substr_count($gateway, 'location ^~ /api/v1/private-media/'))
            ->toBe(substr_count($gateway, 'error_log /dev/null crit;'));
    }
    expect($inner)->toContain('location ^~ /api/v1/private-media/')
        ->toContain('access_log off;')
        ->toContain('error_log /dev/null crit;');
    expect(substr_count($inner, 'location ^~ /api/v1/private-media/'))
        ->toBe(substr_count($inner, 'error_log /dev/null crit;'));
});

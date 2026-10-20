<?php

use App\Exceptions\PrivateAssetIntegrityException;
use App\Models\Company;
use App\Models\Contract;
use App\Services\PrivateAssetStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses()->group('api', 'private-assets');

function privateAssetPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
}

function privateAssetCompany(int $id = 1): Company
{
    $company = Company::query()->find($id);
    if (! $company) {
        $company = new Company;
        $company->id = $id;
    }
    $company->forceFill(['name' => 'Tenant '.$id, 'slug' => 'tenant-'.$id, 'is_active' => true])->save();

    return $company;
}

it('stores company signatures privately and exposes only availability plus an authenticated blob', function () {
    Storage::fake('local');
    Storage::fake('public');
    privateAssetCompany();
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($token);

    $response = $this->post('/api/v1/admin/company', [
        'director_signature' => UploadedFile::fake()->createWithContent('signature.png', privateAssetPng()),
    ], $headers)->assertOk()
        ->assertJsonPath('data.director_signature_available', true)
        ->assertJsonMissingPath('data.director_signature_path')
        ->assertJsonMissingPath('data.director_signature_url');

    $company = Company::query()->findOrFail(1);
    expect($company->director_signature_path)->toStartWith('private/tenants/1/company-signatures/1/')
        ->and($company->director_signature_sha256)->toBe(hash('sha256', privateAssetPng()))
        ->and(Storage::disk('local')->exists($company->director_signature_path))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and($company->toArray())->not->toHaveKeys(['director_signature_path', 'director_signature_sha256']);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/admin/company/assets/director-signature')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $asset = $this->getJson('/api/v1/admin/company/assets/director-signature', $headers)->assertOk();
    expect($asset->getContent())->toBe(privateAssetPng())
        ->and($asset->headers->get('Cache-Control'))->toContain('no-store')
        ->and($asset->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($asset->headers->get('Digest'))->toStartWith('sha-256=');

    $response->assertJsonMissing(['private/tenants']);
});

it('fails closed for wrong tenant, tampered, missing, and invalid company signature assets', function () {
    Storage::fake('local');
    Storage::fake('public');
    privateAssetCompany(1);
    privateAssetCompany(2);
    [, $adminToken] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    [, $otherToken] = adminWithToken(['company_id' => 2, 'group' => 'superadmin']);
    $headers = bearerHeaders($adminToken);

    $this->post('/api/v1/admin/company', [
        'director_signature' => UploadedFile::fake()->createWithContent('signature.png', privateAssetPng()),
    ], $headers)->assertOk();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/admin/company/assets/director-signature', bearerHeaders($otherToken))->assertNotFound();
    $this->app['auth']->forgetGuards();
    $company = Company::query()->findOrFail(1);
    Storage::disk('local')->put($company->director_signature_path, privateAssetPng().'tampered');
    $this->getJson('/api/v1/admin/company/assets/director-signature', $headers)->assertStatus(409)
        ->assertJsonPath('error', 'private_asset_tampered');

    Storage::disk('local')->delete($company->director_signature_path);
    $this->getJson('/api/v1/admin/company/assets/director-signature', $headers)->assertStatus(410)
        ->assertJsonPath('error', 'private_asset_missing');

    $this->post('/api/v1/admin/company', [
        'director_signature' => UploadedFile::fake()->createWithContent('signature.png', 'not-a-png'),
    ], $headers)->assertUnprocessable();

    $this->post('/api/v1/admin/company', [
        'logo' => UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>',
        ),
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('logo');
    expect(Storage::disk('public')->allFiles('logos'))->toBe([]);
});

it('authorizes contract assets by owner or tenant capability and redacts storage references', function () {
    Storage::fake('local');
    Storage::fake('public');
    privateAssetCompany(1);
    privateAssetCompany(2);
    [$owner, $ownerToken] = employeeWithToken(['company_id' => 1]);
    [, $adminToken] = adminWithToken(['company_id' => 1, 'group' => 'admin']);
    [, $foreignToken] = employeeWithToken(['company_id' => 2]);

    $contract = Contract::query()->create([
        'user_id' => $owner->id,
        'company_id' => 1,
        'contract_number' => 'TEST/PRIVATE/001',
        'contract_type' => 'permanent',
        'issued_at' => now()->toDateString(),
        'start_date' => now()->toDateString(),
        'status' => 'signed',
        'placeholders' => [],
    ]);
    $storage = app(PrivateAssetStorage::class);
    $signature = $storage->storePng(1, 'contracts', $contract->id, 'employee-signature', privateAssetPng());
    $pdf = $storage->storePdf(1, 'contracts', $contract->id, 'signed-pdf', Pdf::loadHTML('<p>Verified contract</p>')->output());
    $contract->update([
        'karyawan_signature_path' => $signature['path'],
        'karyawan_signature_sha256' => $signature['sha256'],
        'signed_pdf_path' => $pdf['path'],
        'signed_pdf_sha256' => $pdf['sha256'],
    ]);

    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/contracts/{$contract->id}/assets/employee-signature")->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/contracts/{$contract->id}/assets/employee-signature", bearerHeaders($ownerToken))
        ->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/contracts/{$contract->id}/assets/signed-pdf", bearerHeaders($adminToken))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/contracts/{$contract->id}/assets/signed-pdf", bearerHeaders($foreignToken))
        ->assertNotFound();

    expect($contract->fresh()->toArray())->not->toHaveKeys([
        'karyawan_signature_path', 'karyawan_signature_sha256', 'signed_pdf_path', 'signed_pdf_sha256',
    ]);
});

it('rejects traversal and reports missing, tampered, and orphaned private objects', function () {
    Storage::fake('local');
    $storage = app(PrivateAssetStorage::class);
    $stored = $storage->storePng(1, 'contracts', '42', 'employee-signature', privateAssetPng());

    expect(fn () => $storage->readVerified(1, 'contracts', '42', '../private.png', $stored['sha256'], 'png'))
        ->toThrow(PrivateAssetIntegrityException::class);

    Storage::disk('local')->put('private/tenants/1/contracts/orphan.png', privateAssetPng());
    $report = $storage->report(1, 'contracts', [$stored['path'] => $stored['sha256']], 'png');
    expect($report['orphans'])->toBe(1)->and($report['missing'])->toBe(0)->and($report['tampered'])->toBe(0);

    Storage::disk('local')->put($stored['path'], privateAssetPng().'x');
    $report = $storage->report(1, 'contracts', [$stored['path'] => $stored['sha256']], 'png');
    expect($report['tampered'])->toBe(1);
});

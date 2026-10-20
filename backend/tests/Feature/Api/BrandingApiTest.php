<?php

use App\Models\Company;
use App\Services\R2Service;
use Illuminate\Http\UploadedFile;

function brandingCompany(array $overrides = []): Company
{
    // Perusahaan id 1 sudah dibuat migrasi; `id` tidak fillable sehingga create()
    // diam-diam membuat id 2 dan karyawan uji (company_id 1) membaca branding lain.
    return Company::query()->updateOrCreate(['id' => 1], array_merge([
        'name' => 'Tenant Example',
        'slug' => 'tenant-example',
        'is_active' => true,
    ], $overrides));
}

it('returns public default branding without authentication', function () {
    $this->getJson('/api/v1/branding/default')
        ->assertOk()
        ->assertJsonPath('data.name', 'IBE Reality')
        ->assertJsonPath('data.brand_color_primary', '#1a7a8c');
});

it('returns company scoped branding for an authenticated employee', function () {
    brandingCompany(['short_name' => 'Tenant', 'brand_color_primary' => '#123456']);
    [, $token] = employeeWithToken(['company_id' => 1]);

    $this->getJson('/api/v1/company/branding', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'Tenant Example')
        ->assertJsonPath('data.short_name', 'Tenant')
        ->assertJsonPath('data.brand_color_primary', '#123456');
});

it('forbids employees from changing branding', function () {
    brandingCompany();
    [, $token] = employeeWithToken(['company_id' => 1]);

    $this->patchJson('/api/v1/company/branding', ['tagline' => 'Nope'], bearerHeaders($token))
        ->assertForbidden();
});

it('allows an admin to update branding text and colors', function () {
    brandingCompany();
    [, $token] = adminWithToken(['company_id' => 1]);

    $this->patchJson('/api/v1/company/branding', [
        'short_name' => 'TE',
        'tagline' => 'Build better',
        'brand_color_primary' => '#112233',
        'brand_color_accent' => '#AABBCC',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.short_name', 'TE')
        ->assertJsonPath('data.tagline', 'Build better');

    expect(Company::query()->find(1)->brand_color_primary)->toBe('#112233');
});

it('uploads a tenant logo to the branding prefix for an admin', function () {
    brandingCompany();
    [, $token] = adminWithToken(['company_id' => 1]);
    $r2 = Mockery::mock(R2Service::class);
    $r2->shouldReceive('putFile')
        ->once()
        ->withArgs(fn (string $key, UploadedFile $file) => str_starts_with($key, 'branding/1/logo-') && str_ends_with($key, '.png')
        )
        ->andReturn('https://attachments.ibereality.com/branding/1/logo-test.png');
    $this->app->instance(R2Service::class, $r2);

    $this->post('/api/v1/company/branding/logo', [
        'image' => UploadedFile::fake()->image('logo.png', 256, 256),
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.logo_url', 'https://attachments.ibereality.com/branding/1/logo-test.png');
});

it('validates branding colors and image payloads', function () {
    brandingCompany();
    [, $token] = adminWithToken(['company_id' => 1]);

    $this->patchJson('/api/v1/company/branding', [
        'brand_color_primary' => 'teal',
    ], bearerHeaders($token))->assertUnprocessable();

    $this->post('/api/v1/company/branding/favicon', [
        'image' => UploadedFile::fake()->create('favicon.txt', 10, 'text/plain'),
    ], bearerHeaders($token))->assertUnprocessable();
});

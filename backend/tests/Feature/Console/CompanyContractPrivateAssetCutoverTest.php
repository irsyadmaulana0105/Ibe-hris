<?php

use App\Models\Company;
use App\Models\Contract;
use App\Services\CompanyContractPrivateAssetCutover;
use App\Services\PrivateAssetStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses()->group('api', 'private-assets', 'private-assets-cutover');

function cutoverAssetPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
}

function cutoverAssetCompany(int $id = 1): Company
{
    $company = Company::query()->find($id);
    if (! $company) {
        $company = new Company;
        $company->id = $id;
    }
    $company->forceFill(['name' => 'Cutover Tenant '.$id, 'slug' => 'cutover-tenant-'.$id, 'is_active' => true])->save();

    return $company;
}

/** @return array{cutover: CompanyContractPrivateAssetCutover, manifest_path: string, manifest: string, legacy_paths: list<string>, private_paths: array<string, string>} */
function cutoverAssetPrepareRestoreFailure(): array
{
    Storage::fake('local');
    Storage::fake('public');
    $company = cutoverAssetCompany(1);
    $png = cutoverAssetPng();
    $legacyPaths = [
        'signatures/director-restore-failure.png',
        'stamps/stamp-restore-failure.png',
    ];
    Storage::disk('public')->put($legacyPaths[0], $png);
    Storage::disk('public')->put($legacyPaths[1], $png);
    $company->update([
        'director_signature_path' => $legacyPaths[0],
        'stamp_path' => $legacyPaths[1],
    ]);

    $cutover = app(CompanyContractPrivateAssetCutover::class);
    expect($cutover->run('copy-private', 1, false, null, true)['ok'])->toBeTrue();
    expect($cutover->run('switch-private', 1, false, null, true)['ok'])->toBeTrue();
    $company->refresh();
    $privatePaths = [
        'director_signature_path' => (string) $company->director_signature_path,
        'stamp_path' => (string) $company->stamp_path,
    ];
    expect($cutover->run('purge-public', 1, true, null, true)['ok'])->toBeTrue();

    $manifestPath = 'private/cutovers/'.CompanyContractPrivateAssetCutover::RELEASE.'/manifest.json';

    return [
        'cutover' => $cutover,
        'manifest_path' => $manifestPath,
        'manifest' => Storage::disk('local')->get($manifestPath),
        'legacy_paths' => $legacyPaths,
        'private_paths' => $privatePaths,
    ];
}

it('copies switches quarantines purges and restores company and contract assets idempotently', function () {
    Storage::fake('local');
    Storage::fake('public');
    $company = cutoverAssetCompany(1);
    [$employee] = employeeWithToken(['company_id' => 1]);
    $png = cutoverAssetPng();
    $pdf = Pdf::loadHTML('<p>Legacy signed contract</p>')->output();
    Storage::disk('public')->put('signatures/director.png', $png);
    Storage::disk('public')->put('signatures/orphan.png', $png);
    Storage::disk('public')->put('stamps/orphan-a.png', $png);
    Storage::disk('public')->put('stamps/orphan-b.png', $png);
    Storage::disk('public')->put('contracts/signatures/employee.png', $png);
    Storage::disk('public')->put('contracts/signed/final.pdf', $pdf);
    $company->update(['director_signature_path' => 'signatures/director.png']);
    $contract = Contract::query()->create([
        'user_id' => $employee->id, 'company_id' => 1,
        'contract_number' => 'CUTOVER/001', 'contract_type' => 'permanent',
        'issued_at' => now()->toDateString(), 'start_date' => now()->toDateString(),
        'status' => 'signed', 'placeholders' => [],
        'signature_path' => 'contracts/signatures/employee.png',
        'signed_pdf_path' => 'contracts/signed/final.pdf',
    ]);

    $cutover = app(CompanyContractPrivateAssetCutover::class);
    expect($cutover->run('plan', 1)['ok'])->toBeTrue();
    expect(fn () => $cutover->run('copy-private', 1))->toThrow(RuntimeException::class);
    $copy = $cutover->run('copy-private', 1, false, null, true);
    expect($copy['ok'])->toBeTrue()->and($copy['copied'])->toBe(3)
        ->and($copy['orphans_captured'])->toBe(3)
        ->and(Storage::disk('public')->exists('signatures/director.png'))->toBeTrue()
        ->and(Company::query()->findOrFail(1)->director_signature_path)->toBe('signatures/director.png');

    expect($cutover->run('copy-private', 1, false, null, true)['reused'])->toBe(3);
    $switch = $cutover->run('switch-private', 1, false, null, true);
    expect($switch['ok'])->toBeTrue()->and($switch['switched'])->toBe(3);
    $company = Company::query()->findOrFail(1);
    $contract = Contract::query()->findOrFail($contract->id);
    expect($company->director_signature_path)->toStartWith('private/tenants/1/company-signatures/1/')
        ->and($company->director_signature_sha256)->toBe(hash('sha256', $png))
        ->and($contract->signed_pdf_path)->toStartWith("private/tenants/1/contracts/{$contract->id}/")
        ->and(Storage::disk('public')->exists('contracts/signed/final.pdf'))->toBeTrue();

    expect(fn () => $cutover->run('purge-public', 1, false, null, true))->toThrow(RuntimeException::class);
    $purge = $cutover->run('purge-public', 1, true, null, true);
    expect($purge['ok'])->toBeTrue()->and($purge['purged'])->toBe(3)
        ->and($purge['orphans_purged'])->toBe(3)
        ->and(Storage::disk('public')->exists('signatures/director.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists('signatures/orphan.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists('stamps/orphan-a.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists('stamps/orphan-b.png'))->toBeFalse()
        ->and(Storage::disk('local')->allFiles('private/cutovers/'.CompanyContractPrivateAssetCutover::RELEASE.'/quarantine'))->not->toBeEmpty();

    $newStamp = app(PrivateAssetStorage::class)->storePng(1, 'company-signatures', 1, 'stamp', $png);
    Company::query()->findOrFail(1)->update(['stamp_path' => $newStamp['path'], 'stamp_sha256' => $newStamp['sha256']]);
    $restore = $cutover->run('restore-public', 1, false, null, true);
    expect($restore['ok'])->toBeTrue()->and($restore['restored'])->toBe(4)->and($restore['new_refs'])->toBe(1)
        ->and($restore['orphans_restored'])->toBe(3)
        ->and(Company::query()->findOrFail(1)->director_signature_path)->toBe('signatures/director.png')
        ->and(Company::query()->findOrFail(1)->stamp_path)->toStartWith('stamps/rollback-')
        ->and(Storage::disk('local')->exists($newStamp['path']))->toBeTrue()
        ->and(Storage::disk('public')->exists('signatures/director.png'))->toBeTrue()
        ->and(Storage::disk('public')->exists('signatures/orphan.png'))->toBeTrue()
        ->and(Storage::disk('public')->exists('stamps/orphan-a.png'))->toBeTrue()
        ->and(Storage::disk('public')->exists('stamps/orphan-b.png'))->toBeTrue();

    expect($cutover->run('restore-public', 1, false, null, true)['restored'])->toBe(0);
});

it('fails switch closed when a referenced legacy object is missing or changed after copy', function () {
    Storage::fake('local');
    Storage::fake('public');
    $company = cutoverAssetCompany(1);
    Storage::disk('public')->put('signatures/director.png', cutoverAssetPng());
    $company->update(['director_signature_path' => 'signatures/director.png']);
    $cutover = app(CompanyContractPrivateAssetCutover::class);

    expect($cutover->run('copy-private', 1, false, null, true)['ok'])->toBeTrue();
    Storage::disk('public')->put('signatures/director.png', cutoverAssetPng().'changed');
    $report = $cutover->run('switch-private', 1, false, null, true);
    expect($report['ok'])->toBeFalse()->and($report['errors'])->toBeGreaterThan(0)
        ->and(Company::query()->findOrFail(1)->director_signature_path)->toBe('signatures/director.png');

    Storage::disk('public')->delete('signatures/director.png');
    $plan = $cutover->run('plan', 1);
    expect($plan['ok'])->toBeFalse()->and($plan['error_reasons'])->toHaveKey('missing');
});

it('requires sealed evidence before tombstoning an archived missing director reference', function () {
    Storage::fake('local');
    Storage::fake('public');
    $company = cutoverAssetCompany(1);
    [$employee, $token] = employeeWithToken(['company_id' => 1]);
    Storage::disk('public')->put('signatures/director.png', cutoverAssetPng());
    $company->update(['director_signature_path' => 'signatures/director.png']);
    $contract = Contract::query()->create([
        'user_id' => $employee->id,
        'company_id' => 1,
        'contract_number' => 'CUTOVER/MISSING/001',
        'contract_type' => 'permanent',
        'issued_at' => now()->toDateString(),
        'start_date' => now()->toDateString(),
        'status' => 'archived',
        'placeholders' => [],
        'direktur_signature_path' => 'contracts/signatures/missing-director.png',
    ]);
    $cutover = app(CompanyContractPrivateAssetCutover::class);

    $plan = $cutover->run('plan', 1);
    expect($plan['ok'])->toBeFalse()
        ->and($plan['error_reasons'])->toHaveKey('missing')
        ->and($plan['remediation_evidence'])->toHaveCount(1);
    $evidence = $plan['remediation_evidence'][0];

    $withoutEvidence = $cutover->run('remediate-missing', 1, false, null, true);
    expect($withoutEvidence['ok'])->toBeFalse()
        ->and($withoutEvidence['error_reasons'])->toHaveKey('remediation_evidence_required')
        ->and(Contract::query()->findOrFail($contract->id)->direktur_signature_path)
        ->toBe('contracts/signatures/missing-director.png');

    $remediated = $cutover->run('remediate-missing', 1, false, $evidence, true);
    $contract->refresh();
    expect($remediated['ok'])->toBeTrue()
        ->and($remediated['remediated'])->toBe(1)
        ->and($contract->direktur_signature_path)->toBeNull()
        ->and($contract->direktur_signature_unavailable_reason)->toBe('legacy_object_missing')
        ->and($contract->direktur_signature_reference_fingerprint)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($cutover->run('plan', 1)['ok'])->toBeTrue();

    $this->getJson("/api/v1/contracts/{$contract->id}/assets/director-signature", [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->assertStatus(410)
        ->assertJsonPath('error', 'private_asset_unavailable')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
});

it('detects an unsealed orphan added after copy and blocks switch', function () {
    Storage::fake('local');
    Storage::fake('public');
    cutoverAssetCompany(1);
    $cutover = app(CompanyContractPrivateAssetCutover::class);

    expect($cutover->run('copy-private', 1, false, null, true)['ok'])->toBeTrue();
    Storage::disk('public')->put('stamps/new-unsealed-orphan.png', cutoverAssetPng());

    $switch = $cutover->run('switch-private', 1, false, null, true);
    expect($switch['ok'])->toBeFalse()
        ->and($switch['error_reasons'])->toHaveKey('orphan_copy_required');
});

it('requires explicit apply confirmation from the artisan command for mutating phases', function () {
    Storage::fake('local');
    Storage::fake('public');
    $company = cutoverAssetCompany(1);
    Storage::disk('public')->put('signatures/director-command-gate.png', cutoverAssetPng());
    $company->update(['director_signature_path' => 'signatures/director-command-gate.png']);

    $this->artisan('private-assets:company-contract-20260809', [
        'phase' => 'copy-private',
        '--tenant' => '1',
    ])->expectsOutput('Cutover phase failed closed. Review application logs; no paths are printed.')
        ->assertExitCode(Command::FAILURE);

    expect(Storage::disk('local')->exists(
        'private/cutovers/'.CompanyContractPrivateAssetCutover::RELEASE.'/manifest.json',
    ))->toBeFalse()
        ->and(Company::query()->findOrFail(1)->director_signature_path)
        ->toBe('signatures/director-command-gate.png');
});

it('fails closed when another company contract cutover owns the global lock', function () {
    Storage::fake('local');
    Storage::fake('public');
    cutoverAssetCompany(1);
    $lock = Cache::lock('cutover-lock:'.CompanyContractPrivateAssetCutover::RELEASE.':global', 900);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(CompanyContractPrivateAssetCutover::class)->run('plan', 1))
            ->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }
});

it('removes partially restored public objects when a later public write fails', function () {
    $fixture = cutoverAssetPrepareRestoreFailure();
    $manager = Storage::getFacadeRoot();
    $public = Storage::disk('public');
    $local = Storage::disk('local');
    $publicMock = Mockery::mock($public)->makePartial();
    $writeCount = 0;
    $publicMock->shouldReceive('put')->twice()->andReturnUsing(
        function (string $path, string $bytes, array $options = []) use ($public, &$writeCount): bool {
            $writeCount++;

            return $writeCount === 2 ? false : $public->put($path, $bytes, $options);
        },
    );
    $managerMock = Mockery::mock($manager)->makePartial();
    $managerMock->shouldReceive('disk')->with('public')->andReturn($publicMock);
    $managerMock->shouldReceive('disk')->with('local')->andReturn($local);
    Storage::swap($managerMock);

    try {
        expect(fn () => $fixture['cutover']->run('restore-public', 1, false, null, true))
            ->toThrow(RuntimeException::class, 'Public restore write failed.');
    } finally {
        Storage::swap($manager);
    }

    $company = Company::query()->findOrFail(1);
    expect($writeCount)->toBe(2)
        ->and($public->exists($fixture['legacy_paths'][0]))->toBeFalse()
        ->and($public->exists($fixture['legacy_paths'][1]))->toBeFalse()
        ->and($company->director_signature_path)->toBe($fixture['private_paths']['director_signature_path'])
        ->and($company->stamp_path)->toBe($fixture['private_paths']['stamp_path'])
        ->and($local->get($fixture['manifest_path']))->toBe($fixture['manifest']);
});

it('removes restored public objects and rolls back database updates after a database failure', function () {
    $fixture = cutoverAssetPrepareRestoreFailure();
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER cutover_company_restore_failure
        BEFORE UPDATE OF director_signature_path ON companies
        WHEN NEW.director_signature_path LIKE 'signatures/%'
        BEGIN
            SELECT RAISE(ABORT, 'injected restore database failure');
        END
    SQL);

    try {
        expect(fn () => $fixture['cutover']->run('restore-public', 1, false, null, true))
            ->toThrow(QueryException::class, 'injected restore database failure');
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS cutover_company_restore_failure');
    }

    $company = Company::query()->findOrFail(1);
    expect(Storage::disk('public')->exists($fixture['legacy_paths'][0]))->toBeFalse()
        ->and(Storage::disk('public')->exists($fixture['legacy_paths'][1]))->toBeFalse()
        ->and($company->director_signature_path)->toBe($fixture['private_paths']['director_signature_path'])
        ->and($company->stamp_path)->toBe($fixture['private_paths']['stamp_path'])
        ->and(Storage::disk('local')->get($fixture['manifest_path']))->toBe($fixture['manifest']);
});

it('removes restored public objects and restores the sealed manifest after a manifest write failure', function () {
    $fixture = cutoverAssetPrepareRestoreFailure();
    $manager = Storage::getFacadeRoot();
    $public = Storage::disk('public');
    $local = Storage::disk('local');
    $localMock = Mockery::mock($local)->makePartial();
    $localMock->shouldReceive('put')
        ->with($fixture['manifest_path'], Mockery::type('string'))
        ->twice()
        ->andReturn(false, true);
    $managerMock = Mockery::mock($manager)->makePartial();
    $managerMock->shouldReceive('disk')->with('public')->andReturn($public);
    $managerMock->shouldReceive('disk')->with('local')->andReturn($localMock);
    Storage::swap($managerMock);

    try {
        expect(fn () => $fixture['cutover']->run('restore-public', 1, false, null, true))
            ->toThrow(RuntimeException::class, 'Cutover manifest write failed.');
    } finally {
        Storage::swap($manager);
    }

    $company = Company::query()->findOrFail(1);
    expect($public->exists($fixture['legacy_paths'][0]))->toBeFalse()
        ->and($public->exists($fixture['legacy_paths'][1]))->toBeFalse()
        ->and($company->director_signature_path)->toBe($fixture['private_paths']['director_signature_path'])
        ->and($company->stamp_path)->toBe($fixture['private_paths']['stamp_path'])
        ->and($local->get($fixture['manifest_path']))->toBe($fixture['manifest']);
});

<?php

use App\Console\Commands\CutoverAttendanceReimbursementPrivateMedia;
use App\Exceptions\PrivateAssetIntegrityException;
use App\Models\Attendance;
use App\Models\Reimbursement;
use App\Models\ReimbursementCategory;
use App\Services\AttendanceReimbursementMediaService;
use App\Services\AttendanceReimbursementPrivateMediaCutover;
use App\Services\PrivateMediaStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Tester\CommandTester;

uses()->group('api', 'private-assets', 'private-media-cutover');

function arCutoverJpeg(): string
{
    $image = UploadedFile::fake()->image('cutover.jpg', 4, 3);

    return file_get_contents($image->getRealPath());
}

function arCutoverPdf(): string
{
    return Pdf::loadHTML('<p>Private reimbursement receipt</p>')->output();
}

function arCutoverFixture(): array
{
    Storage::fake('local');
    Storage::fake('public');
    [$employee] = employeeWithToken(['company_id' => 1]);
    $jpeg = arCutoverJpeg();
    $pdf = arCutoverPdf();
    Storage::disk('public')->put('attendance-selfies/in.jpg', $jpeg);
    Storage::disk('public')->put('attendance-selfies/orphan-invalid.bin', 'legacy-invalid-orphan');
    Storage::disk('public')->put('reimbursements/receipt.jpg', $jpeg);
    Storage::disk('public')->put('reimbursements/orphan.bin', 'x');
    Storage::disk('public')->put('reimbursement-proofs/proof.jpg', $jpeg);
    Storage::disk('public')->put('reimbursement-receipts/receipt.pdf', $pdf);
    $attendance = Attendance::create([
        'user_id' => $employee->id, 'date' => now()->toDateString(),
        'selfie_in_path' => 'attendance-selfies/in.jpg',
        'selfie_out_path' => 'attendance-selfies/missing.jpg',
        'attachment' => 'attachments/missing.pdf',
    ]);
    $category = ReimbursementCategory::withoutGlobalScopes()->create([
        'company_id' => 1, 'name' => 'Private cutover', 'is_active' => true,
    ]);
    $reimbursement = Reimbursement::withoutGlobalScopes()->create([
        'company_id' => 1, 'user_id' => $employee->id, 'category_id' => $category->id,
        'date' => now()->toDateString(),
        'event' => 'Private cutover', 'unit_price' => 100, 'quantity' => 1,
        'total_amount' => 100, 'status' => 'settled',
        'attachment_path' => 'reimbursements/receipt.jpg',
        'payment_proof_path' => 'reimbursement-proofs/proof.jpg',
        'receipt_pdf_path' => 'reimbursement-receipts/receipt.pdf',
    ]);

    return compact('employee', 'attendance', 'reimbursement', 'jpeg', 'pdf');
}

function arCutoverToPurged(): array
{
    $fixture = arCutoverFixture();
    $cutover = app(AttendanceReimbursementPrivateMediaCutover::class);
    $plan = $cutover->run('plan', null, false, null, false, false);
    $cutover->run('copy-private', null, false, null, true, false);
    $cutover->run('remediate-missing', null, false, $plan['missing_evidence'], true, false);
    $cutover->run('switch-private', null, false, null, true, false);
    $privateAttendancePath = $fixture['attendance']->fresh()->selfie_in_path;
    $privateReimbursementPath = $fixture['reimbursement']->fresh()->attachment_path;
    $cutover->run('purge-public', null, true, null, true, false);

    return [
        'cutover' => $cutover,
        'fixture' => $fixture,
        'manifest' => Storage::disk('local')->get(AttendanceReimbursementPrivateMediaCutover::MANIFEST_PATH),
        'private_attendance_path' => $privateAttendancePath,
        'private_reimbursement_path' => $privateReimbursementPath,
    ];
}

it('copies remediates switches quarantines purges and restores private media idempotently', function () {
    $fixture = arCutoverFixture();
    $cutover = app(AttendanceReimbursementPrivateMediaCutover::class);
    $plan = $cutover->run('plan', null, false, null, false, false);
    expect($plan['missing'])->toBe(2)
        ->and($plan['missing_evidence'])->toBeString();

    expect(fn () => $cutover->run('copy-private', null, false, null, false, false))
        ->toThrow(RuntimeException::class);
    $copy = $cutover->run('copy-private', null, false, null, true, false);
    expect($copy['ok'])->toBeTrue()
        ->and($copy['copied'])->toBe(4)
        ->and($copy['orphans_captured'])->toBe(2)
        ->and(Storage::disk('public')->exists('attendance-selfies/in.jpg'))->toBeTrue();

    $blocked = $cutover->run('switch-private', null, false, null, true, false);
    expect($blocked['ok'])->toBeFalse()
        ->and($blocked['error_reasons'])->toHaveKey('missing');

    $remediated = $cutover->run(
        'remediate-missing', null, false, $plan['missing_evidence'], true, false,
    );
    expect($remediated['ok'])->toBeTrue()->and($remediated['remediated'])->toBe(2);
    $switch = $cutover->run('switch-private', null, false, null, true, false);
    expect($switch['ok'])->toBeTrue()->and($switch['switched'])->toBe(4);

    $attendance = $fixture['attendance']->fresh();
    $reimbursement = $fixture['reimbursement']->fresh();
    expect($attendance->selfie_in_path)->toStartWith('private/tenants/1/attendance-media/')
        ->and($attendance->selfie_out_path)->toBeNull()
        ->and($attendance->selfie_out_unavailable_reason)->toBe('legacy_object_missing')
        ->and($attendance->attachment)->toBeNull()
        ->and($attendance->attachment_reference_fingerprint)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($reimbursement->receipt_pdf_path)->toStartWith('private/tenants/1/reimbursement-media/');

    expect(fn () => $cutover->run('purge-public', null, false, null, true, false))
        ->toThrow(RuntimeException::class);
    $purge = $cutover->run('purge-public', null, true, null, true, false);
    expect($purge['ok'])->toBeTrue()
        ->and($purge['purged'])->toBe(4)
        ->and($purge['orphans_purged'])->toBe(2)
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $restore = $cutover->run('restore-public', null, false, null, true, false);
    $attendance->refresh();
    $reimbursement->refresh();
    expect($restore['ok'])->toBeTrue()
        ->and($restore['restored'])->toBe(4)
        ->and($attendance->selfie_in_path)->toBe('attendance-selfies/in.jpg')
        ->and($attendance->selfie_out_path)->toBeNull()
        ->and($attendance->selfie_out_unavailable_reason)->toBe('legacy_object_missing')
        ->and($reimbursement->attachment_path)->toBe('reimbursements/receipt.jpg')
        ->and(Storage::disk('public')->exists('attendance-selfies/orphan-invalid.bin'))->toBeTrue()
        ->and(Storage::disk('public')->exists('reimbursements/orphan.bin'))->toBeTrue();
});

it('preserves legitimate private writes created after switch during rollback', function () {
    $fixture = arCutoverFixture();
    $cutover = app(AttendanceReimbursementPrivateMediaCutover::class);
    $plan = $cutover->run('plan', null, false, null, false, false);
    $cutover->run('copy-private', null, false, null, true, false);
    $cutover->run('remediate-missing', null, false, $plan['missing_evidence'], true, false);
    $cutover->run('switch-private', null, false, null, true, false);
    $cutover->run('purge-public', null, true, null, true, false);

    $newAttendance = Attendance::create([
        'user_id' => $fixture['employee']->id, 'date' => now()->subDay()->toDateString(),
    ]);
    $stored = app(PrivateMediaStorage::class)->storeBytes(
        1, AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
        $newAttendance->id, 'selfie-in', $fixture['jpeg'], ['image/jpeg'],
    );
    $newAttendance->update([
        'selfie_in_path' => $stored['path'], 'selfie_in_sha256' => $stored['sha256'],
        'selfie_in_size_bytes' => $stored['size'], 'selfie_in_mime_type' => $stored['mime'],
        'selfie_in_width' => $stored['width'], 'selfie_in_height' => $stored['height'],
    ]);

    $restore = $cutover->run('restore-public', null, false, null, true, false);
    $newAttendance->refresh();
    expect($restore['new_refs'])->toBe(1)
        ->and($newAttendance->selfie_in_path)->toStartWith('attendance-selfies/rollback-')
        ->and(Storage::disk('public')->exists($newAttendance->selfie_in_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($stored['path']))->toBeTrue();
});

it('requires command apply confirmation and fails closed under lock contention', function () {
    arCutoverFixture();
    $this->artisan('private-media:attendance-reimbursement-20260809', ['phase' => 'copy-private'])
        ->expectsOutput('Cutover phase failed closed. Review application logs; no paths are printed.')
        ->assertExitCode(Command::FAILURE);

    $lock = Cache::lock('cutover-lock:'.AttendanceReimbursementPrivateMediaCutover::RELEASE.':global', 900);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => app(AttendanceReimbursementPrivateMediaCutover::class)
            ->run('plan', null, false, null, false, false))
            ->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }
});

it('never writes a raw legacy path from a cutover exception to console or logs', function () {
    arCutoverFixture();
    $sentinel = 'attendance-selfies/sentinel-secret-object.jpg';
    $manager = Storage::getFacadeRoot();
    $public = Storage::disk('public');
    $local = Storage::disk('local');
    $publicMock = Mockery::mock($public)->makePartial();
    $publicMock->shouldReceive('exists')->atLeast()->once()
        ->andThrow(new RuntimeException($sentinel));
    $managerMock = Mockery::mock($manager)->makePartial();
    $managerMock->shouldReceive('disk')->with('public')->andReturn($publicMock);
    $managerMock->shouldReceive('disk')->with('local')->andReturn($local);
    Storage::swap($managerMock);
    Log::spy();
    $command = app(CutoverAttendanceReimbursementPrivateMedia::class);
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    try {
        $exit = $tester->execute([
            'phase' => 'plan',
        ]);
    } finally {
        Storage::swap($manager);
    }

    expect($exit)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->not->toContain($sentinel);
    Log::shouldHaveReceived('error')->once()->withArgs(
        function (string $message, array $context) use ($sentinel): bool {
            return $message === 'Attendance and reimbursement private-media cutover failed closed.'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $sentinel)
                && $context['reason'] === 'unexpected_failure';
        },
    );
});

it('rejects a changed public original and an unsealed orphan before switch', function () {
    arCutoverFixture();
    $cutover = app(AttendanceReimbursementPrivateMediaCutover::class);
    $plan = $cutover->run('plan', null, false, null, false, false);
    $cutover->run('copy-private', null, false, null, true, false);
    $cutover->run('remediate-missing', null, false, $plan['missing_evidence'], true, false);
    Storage::disk('public')->put('attendance-selfies/in.jpg', arCutoverJpeg().'changed');
    Storage::disk('public')->put('attendance-selfies/new-orphan.bin', 'unsealed');

    $switch = $cutover->run('switch-private', null, false, null, true, false);
    expect($switch['ok'])->toBeFalse()
        ->and($switch['errors'])->toBeGreaterThanOrEqual(2)
        ->and($switch['error_reasons'])->toHaveKey('orphan_copy_required');
});

it('fails closed when two existing rows reference the same public object', function () {
    $fixture = arCutoverFixture();
    Attendance::create([
        'user_id' => $fixture['employee']->id,
        'date' => now()->subDay()->toDateString(),
        'selfie_in_path' => 'attendance-selfies/in.jpg',
    ]);
    $cutover = app(AttendanceReimbursementPrivateMediaCutover::class);
    $plan = $cutover->run('plan', null, false, null, false, false);
    expect($plan['duplicate_existing_refs'])->toBe(1);

    $cutover->run('copy-private', null, false, null, true, false);
    $cutover->run('remediate-missing', null, false, $plan['missing_evidence'], true, false);
    $switch = $cutover->run('switch-private', null, false, null, true, false);

    expect($switch['ok'])->toBeFalse()
        ->and($switch['error_reasons'])->toHaveKey('duplicate_existing_reference')
        ->and($fixture['attendance']->fresh()->selfie_in_path)->toBe('attendance-selfies/in.jpg');
});

it('never treats a referenced object without a valid tenant as an orphan', function () {
    Storage::fake('local');
    Storage::fake('public');
    [$employee] = employeeWithToken(['company_id' => 1]);
    DB::table('users')->where('id', $employee->id)->update(['company_id' => null]);
    Storage::disk('public')->put('attendance-selfies/tenantless.jpg', arCutoverJpeg());
    $attendance = Attendance::create([
        'user_id' => $employee->id,
        'date' => now()->toDateString(),
        'selfie_in_path' => 'attendance-selfies/tenantless.jpg',
    ]);

    expect(fn () => app(AttendanceReimbursementPrivateMediaCutover::class)
        ->run('plan', null, false, null, false, false))
        ->toThrow(PrivateAssetIntegrityException::class, 'valid tenant owner')
        ->and(Storage::disk('public')->exists('attendance-selfies/tenantless.jpg'))->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($attendance->fresh()->selfie_in_path)->toBe('attendance-selfies/tenantless.jpg');
});

it('compensates every public deletion when purge manifest persistence fails', function () {
    $fixture = arCutoverFixture();
    $cutover = app(AttendanceReimbursementPrivateMediaCutover::class);
    $plan = $cutover->run('plan', null, false, null, false, false);
    $cutover->run('copy-private', null, false, null, true, false);
    $cutover->run('remediate-missing', null, false, $plan['missing_evidence'], true, false);
    $cutover->run('switch-private', null, false, null, true, false);
    $manifestPath = AttendanceReimbursementPrivateMediaCutover::MANIFEST_PATH;
    $manifest = Storage::disk('local')->get($manifestPath);

    $manager = Storage::getFacadeRoot();
    $public = Storage::disk('public');
    $local = Storage::disk('local');
    $localMock = Mockery::mock($local)->makePartial();
    $localMock->shouldReceive('put')->with($manifestPath, Mockery::type('string'))
        ->twice()->andReturn(false, true);
    $managerMock = Mockery::mock($manager)->makePartial();
    $managerMock->shouldReceive('disk')->with('public')->andReturn($public);
    $managerMock->shouldReceive('disk')->with('local')->andReturn($localMock);
    Storage::swap($managerMock);
    try {
        expect(fn () => $cutover->run('purge-public', null, true, null, true, false))
            ->toThrow(RuntimeException::class, 'Cutover manifest write failed.');
    } finally {
        Storage::swap($manager);
    }

    expect($public->exists('attendance-selfies/in.jpg'))->toBeTrue()
        ->and($public->exists('reimbursements/receipt.jpg'))->toBeTrue()
        ->and($public->exists('reimbursement-proofs/proof.jpg'))->toBeTrue()
        ->and($public->exists('reimbursement-receipts/receipt.pdf'))->toBeTrue()
        ->and($public->exists('attendance-selfies/orphan-invalid.bin'))->toBeTrue()
        ->and($public->exists('reimbursements/orphan.bin'))->toBeTrue()
        ->and($local->get($manifestPath))->toBe($manifest);
});

it('detects a failed public-object write during purge compensation', function () {
    $fixture = arCutoverFixture();
    $cutover = app(AttendanceReimbursementPrivateMediaCutover::class);
    $plan = $cutover->run('plan', null, false, null, false, false);
    $cutover->run('copy-private', null, false, null, true, false);
    $cutover->run('remediate-missing', null, false, $plan['missing_evidence'], true, false);
    $cutover->run('switch-private', null, false, null, true, false);

    $manager = Storage::getFacadeRoot();
    $public = Storage::disk('public');
    $local = Storage::disk('local');
    $manifestPath = AttendanceReimbursementPrivateMediaCutover::MANIFEST_PATH;
    $manifest = $local->get($manifestPath);
    $publicMock = Mockery::mock($public)->makePartial();
    $publicMock->shouldReceive('put')->atLeast()->once()->andReturn(false);
    $localMock = Mockery::mock($local)->makePartial();
    $localMock->shouldReceive('put')->with($manifestPath, Mockery::type('string'))
        ->twice()->andReturn(false, true);
    $managerMock = Mockery::mock($manager)->makePartial();
    $managerMock->shouldReceive('disk')->with('public')->andReturn($publicMock);
    $managerMock->shouldReceive('disk')->with('local')->andReturn($localMock);
    Storage::swap($managerMock);
    try {
        expect(fn () => $cutover->run('purge-public', null, true, null, true, false))
            ->toThrow(RuntimeException::class, 'Purge failed and compensation was incomplete.');
    } finally {
        Storage::swap($manager);
    }

    expect($public->allFiles())->toBe([])
        ->and($local->get($manifestPath))->toBe($manifest);
});

it('detects and removes a corrupt readback during purge compensation', function () {
    $fixture = arCutoverFixture();
    $cutover = app(AttendanceReimbursementPrivateMediaCutover::class);
    $plan = $cutover->run('plan', null, false, null, false, false);
    $cutover->run('copy-private', null, false, null, true, false);
    $cutover->run('remediate-missing', null, false, $plan['missing_evidence'], true, false);
    $cutover->run('switch-private', null, false, null, true, false);

    $manager = Storage::getFacadeRoot();
    $public = Storage::disk('public');
    $local = Storage::disk('local');
    $manifestPath = AttendanceReimbursementPrivateMediaCutover::MANIFEST_PATH;
    $publicMock = Mockery::mock($public)->makePartial();
    $publicMock->shouldReceive('put')->atLeast()->once()
        ->andReturnUsing(function (string $path) use ($public): bool {
            return $public->put($path, 'corrupt-compensation');
        });
    $localMock = Mockery::mock($local)->makePartial();
    $localMock->shouldReceive('put')->with($manifestPath, Mockery::type('string'))
        ->twice()->andReturn(false, true);
    $managerMock = Mockery::mock($manager)->makePartial();
    $managerMock->shouldReceive('disk')->with('public')->andReturn($publicMock);
    $managerMock->shouldReceive('disk')->with('local')->andReturn($localMock);
    Storage::swap($managerMock);
    try {
        expect(fn () => $cutover->run('purge-public', null, true, null, true, false))
            ->toThrow(RuntimeException::class, 'Purge failed and compensation was incomplete.');
    } finally {
        Storage::swap($manager);
    }

    expect($public->allFiles())->toBe([]);
});

it('removes all restored public objects and rolls back DB changes after restore DB failure', function () {
    $state = arCutoverToPurged();
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER private_media_restore_failure
        BEFORE UPDATE OF selfie_in_path ON attendances
        WHEN NEW.selfie_in_path LIKE 'attendance-selfies/%'
        BEGIN
            SELECT RAISE(ABORT, 'injected private media restore failure');
        END
    SQL);
    try {
        expect(fn () => $state['cutover']->run('restore-public', null, false, null, true, false))
            ->toThrow(QueryException::class, 'injected private media restore failure');
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS private_media_restore_failure');
    }

    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and($state['fixture']['attendance']->fresh()->selfie_in_path)->toBe($state['private_attendance_path'])
        ->and($state['fixture']['reimbursement']->fresh()->attachment_path)->toBe($state['private_reimbursement_path'])
        ->and(Storage::disk('local')->get(AttendanceReimbursementPrivateMediaCutover::MANIFEST_PATH))
        ->toBe($state['manifest']);
});

it('cleans public writes and restores the sealed manifest after restore manifest failure', function () {
    $state = arCutoverToPurged();
    $manager = Storage::getFacadeRoot();
    $public = Storage::disk('public');
    $local = Storage::disk('local');
    $manifestPath = AttendanceReimbursementPrivateMediaCutover::MANIFEST_PATH;
    $localMock = Mockery::mock($local)->makePartial();
    $localMock->shouldReceive('put')->with($manifestPath, Mockery::type('string'))
        ->twice()->andReturn(false, true);
    $managerMock = Mockery::mock($manager)->makePartial();
    $managerMock->shouldReceive('disk')->with('public')->andReturn($public);
    $managerMock->shouldReceive('disk')->with('local')->andReturn($localMock);
    Storage::swap($managerMock);
    try {
        expect(fn () => $state['cutover']->run('restore-public', null, false, null, true, false))
            ->toThrow(RuntimeException::class, 'Cutover manifest write failed.');
    } finally {
        Storage::swap($manager);
    }

    expect($public->allFiles())->toBe([])
        ->and($state['fixture']['attendance']->fresh()->selfie_in_path)->toBe($state['private_attendance_path'])
        ->and($state['fixture']['reimbursement']->fresh()->attachment_path)->toBe($state['private_reimbursement_path'])
        ->and($local->get($manifestPath))->toBe($state['manifest']);
});

it('removes every partial public object when a restore write fails', function () {
    $state = arCutoverToPurged();
    $manager = Storage::getFacadeRoot();
    $public = Storage::disk('public');
    $write = 0;
    $publicMock = Mockery::mock($public)->makePartial();
    $publicMock->shouldReceive('put')->atLeast()->twice()
        ->andReturnUsing(function (string $path, string $bytes) use ($public, &$write): bool {
            $write++;
            if ($write === 2) {
                return $public->put($path, 'partial-corrupt-object');
            }

            return $public->put($path, $bytes);
        });
    $managerMock = Mockery::mock($manager)->makePartial();
    $managerMock->shouldReceive('disk')->with('public')->andReturn($publicMock);
    $managerMock->shouldReceive('disk')->with('local')->andReturn(Storage::disk('local'));
    Storage::swap($managerMock);
    try {
        expect(fn () => $state['cutover']->run('restore-public', null, false, null, true, false))
            ->toThrow(RuntimeException::class, 'Public restore write failed.');
    } finally {
        Storage::swap($manager);
    }

    expect($public->allFiles())->toBe([])
        ->and($state['fixture']['attendance']->fresh()->selfie_in_path)->toBe($state['private_attendance_path'])
        ->and($state['fixture']['reimbursement']->fresh()->attachment_path)->toBe($state['private_reimbursement_path'])
        ->and(Storage::disk('local')->get(AttendanceReimbursementPrivateMediaCutover::MANIFEST_PATH))
        ->toBe($state['manifest']);
});

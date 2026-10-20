<?php

use App\Models\Company;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'enterprise-rbac', 'console');

function ajiAccessFixture(bool $withHrAssignment = true): array
{
    config()->set('enterprise_rbac.mode', 'shadow');

    $company = Company::query()->create([
        'name' => 'PT Integrasi Bisnis Eksekutif',
        'slug' => 'ibe-command-test',
        'is_active' => true,
    ]);

    $aji = User::factory()->create([
        'company_id' => $company->id,
        'name' => 'Aji Sapta Pramulen',
        'email' => 'aji@ibereality.com',
        'group' => 'admin',
    ]);

    UserRole::query()->create([
        'user_id' => $aji->id,
        'role' => 'admin',
        'is_primary' => true,
        'granted_at' => now()->subDay(),
        'notes' => 'Existing HR primary role',
    ]);

    $roleIds = DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->whereIn('key', ['tenant_owner', 'hr_manager'])
        ->pluck('id', 'key');

    $hrAssignmentId = null;
    if ($withHrAssignment) {
        $hrAssignmentId = (string) str()->ulid();
        DB::table('rbac_user_assignments')->insert([
            'id' => $hrAssignmentId,
            'user_id' => $aji->id,
            'role_id' => $roleIds['hr_manager'],
            'company_id' => $company->id,
            'status' => 'active',
            'valid_from' => now()->subDay(),
            'valid_until' => null,
            'approval_limit' => null,
            'granted_by' => null,
            'reason' => 'Existing HR assignment must be retained',
            'source' => 'manual',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    return [$company, $aji, $roleIds, $hrAssignmentId];
}

function activeAjiEnterpriseRoles(User $aji): array
{
    return DB::table('rbac_user_assignments as assignment')
        ->join('rbac_roles as role', 'role.id', '=', 'assignment.role_id')
        ->where('assignment.user_id', $aji->id)
        ->where('assignment.company_id', $aji->company_id)
        ->where('assignment.status', 'active')
        ->where(fn ($query) => $query->whereNull('assignment.valid_from')->orWhere('assignment.valid_from', '<=', now()))
        ->where(fn ($query) => $query->whereNull('assignment.valid_until')->orWhere('assignment.valid_until', '>=', now()))
        ->orderBy('role.key')
        ->pluck('role.key')
        ->all();
}

function insertAjiEnterpriseAssignment(User $aji, int $roleId, string $reason): string
{
    $id = (string) str()->ulid();
    DB::table('rbac_user_assignments')->insert([
        'id' => $id,
        'user_id' => $aji->id,
        'role_id' => $roleId,
        'company_id' => $aji->company_id,
        'status' => 'active',
        'valid_from' => now()->subMinute(),
        'valid_until' => null,
        'approval_limit' => null,
        'granted_by' => null,
        'reason' => $reason,
        'source' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function alternateAjiAccessTarget(string $group): User
{
    [$company] = ajiAccessFixture();

    $alternate = User::factory()->create([
        'company_id' => $company->id,
        'name' => 'Alternate Privileged Identity',
        'email' => "alternate-{$group}@ibereality.com",
        'group' => $group,
    ]);

    if ($group === 'superadmin') {
        UserRole::query()->create([
            'user_id' => $alternate->id,
            'role' => 'superadmin',
            'is_primary' => true,
            'granted_at' => now()->subDay(),
            'notes' => 'Existing superadmin primary role',
        ]);
    }

    UserRole::query()->create([
        'user_id' => $alternate->id,
        'role' => 'admin',
        'is_primary' => $group === 'admin',
        'granted_at' => now()->subDay(),
        'notes' => 'Existing HR role',
    ]);

    return $alternate;
}

function ajiAccessSnapshot(User $user): array
{
    return [
        'user' => (array) DB::table('users')->where('id', $user->id)->first(),
        'roles' => DB::table('user_roles')->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'assignments' => DB::table('rbac_user_assignments')->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'audits' => DB::table('audit_logs')->where('entity_id', (string) $user->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

it('is a read-only dry run by default', function () {
    [, $aji] = ajiAccessFixture();

    $this->artisan('users:align-aji-access')->assertSuccessful();

    expect($aji->fresh()->group)->toBe('admin')
        ->and(DB::table('user_roles')->where('user_id', $aji->id)->count())->toBe(1)
        ->and(activeAjiEnterpriseRoles($aji))->toBe(['hr_manager'])
        ->and(DB::table('audit_logs')->where('entity_id', $aji->id)->count())->toBe(0);
});

it('rejects every alternate HR or superadmin identity before any mutation', function (string $group, array $mode) {
    $alternate = alternateAjiAccessTarget($group);
    $before = ajiAccessSnapshot($alternate);

    $this->artisan('users:align-aji-access', [
        '--email' => $alternate->email,
        ...$mode,
    ])->assertFailed();

    expect(ajiAccessSnapshot($alternate))->toBe($before);
})->with([
    'HR dry run' => ['admin', []],
    'HR apply' => ['admin', ['--apply' => true]],
    'HR rollback preview' => ['admin', ['--rollback' => true]],
    'HR rollback apply' => ['admin', ['--rollback' => true, '--apply' => true]],
    'superadmin apply' => ['superadmin', ['--apply' => true]],
    'superadmin rollback apply' => ['superadmin', ['--rollback' => true, '--apply' => true]],
]);

it('fails closed when normalized canonical Aji email matches more than one identity', function (array $mode) {
    [$company, $aji] = ajiAccessFixture();
    $duplicate = User::factory()->create([
        'company_id' => $company->id,
        'name' => 'Ambiguous Case Variant',
        'email' => 'AJI@IBEREALITY.COM',
        'group' => 'admin',
    ]);
    $beforeAji = ajiAccessSnapshot($aji);
    $beforeDuplicate = ajiAccessSnapshot($duplicate);
    $beforeAuditCount = DB::table('audit_logs')->count();

    $this->artisan('users:align-aji-access', $mode)->assertFailed();

    expect(ajiAccessSnapshot($aji))->toBe($beforeAji)
        ->and(ajiAccessSnapshot($duplicate))->toBe($beforeDuplicate)
        ->and(DB::table('audit_logs')->count())->toBe($beforeAuditCount);
})->with([
    'dry run' => [[]],
    'apply' => [['--apply' => true]],
    'rollback preview' => [['--rollback' => true]],
    'rollback apply' => [['--rollback' => true, '--apply' => true]],
]);

it('applies the exact legacy and enterprise access while retaining HR', function () {
    [, $aji, , $hrAssignmentId] = ajiAccessFixture();

    $this->artisan('users:align-aji-access', ['--apply' => true])->assertSuccessful();

    $legacyRoles = DB::table('user_roles')
        ->where('user_id', $aji->id)
        ->orderBy('role')
        ->get(['role', 'is_primary'])
        ->map(fn ($row) => [$row->role, (bool) $row->is_primary])
        ->all();

    expect($aji->fresh()->group)->toBe('superadmin')
        ->and($legacyRoles)->toBe([
            ['admin', false],
            ['superadmin', true],
        ])
        ->and(activeAjiEnterpriseRoles($aji))->toBe(['hr_manager', 'tenant_owner'])
        ->and(DB::table('rbac_user_assignments')->where('id', $hrAssignmentId)->value('source'))->toBe('manual');

    $audit = DB::table('audit_logs')
        ->where('action', 'access.aji_hr_superadmin.applied')
        ->where('entity_id', $aji->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->user_agent)->toBe('artisan:users:align-aji-access')
        ->and(json_decode($audit->meta, true)['marker'])->toBe('20260808-aji-hr-superadmin-v1');
});

it('is idempotent for the same marker', function () {
    [, $aji] = ajiAccessFixture();

    $this->artisan('users:align-aji-access', ['--apply' => true])->assertSuccessful();
    $roleCount = DB::table('user_roles')->where('user_id', $aji->id)->count();
    $assignmentCount = DB::table('rbac_user_assignments')->where('user_id', $aji->id)->count();

    $this->artisan('users:align-aji-access', ['--apply' => true])->assertSuccessful();

    expect(DB::table('user_roles')->where('user_id', $aji->id)->count())->toBe($roleCount)
        ->and(DB::table('rbac_user_assignments')->where('user_id', $aji->id)->count())->toBe($assignmentCount)
        ->and(DB::table('audit_logs')
            ->where('action', 'access.aji_hr_superadmin.applied')
            ->where('entity_id', $aji->id)
            ->count())->toBe(1);
});

it('rejects invalid rollout preconditions without changing access', function () {
    [, $aji] = ajiAccessFixture();
    config()->set('enterprise_rbac.mode', 'enforce');

    $this->artisan('users:align-aji-access', ['--apply' => true])->assertFailed();

    expect($aji->fresh()->group)->toBe('admin')
        ->and(DB::table('user_roles')->where('user_id', $aji->id)->where('role', 'superadmin')->exists())->toBeFalse()
        ->and(activeAjiEnterpriseRoles($aji))->toBe(['hr_manager'])
        ->and(DB::table('audit_logs')->where('entity_id', $aji->id)->count())->toBe(0);
});

it('rejects inactive or offboarded identities without changing access', function (array $offboardingState) {
    [, $aji] = ajiAccessFixture();
    $aji->forceFill($offboardingState)->save();

    $this->artisan('users:align-aji-access', ['--apply' => true])->assertFailed();

    expect($aji->fresh()->group)->toBe('admin')
        ->and(DB::table('user_roles')->where('user_id', $aji->id)->where('role', 'superadmin')->exists())->toBeFalse()
        ->and(activeAjiEnterpriseRoles($aji))->toBe(['hr_manager'])
        ->and(DB::table('audit_logs')->where('entity_id', $aji->id)->count())->toBe(0);
})->with([
    'resigned employment status' => [['employment_status' => 'resigned']],
    'terminated employment status' => [['employment_status' => 'terminated']],
    'legacy resigned_at marker' => [['resigned_at' => '2026-08-08']],
]);

it('fails closed when a protected enterprise role has duplicate active assignments', function (string $roleKey) {
    [, $aji, $roleIds] = ajiAccessFixture();

    $copiesNeeded = $roleKey === 'hr_manager' ? 1 : 2;
    for ($copy = 1; $copy <= $copiesNeeded; $copy++) {
        insertAjiEnterpriseAssignment($aji, (int) $roleIds[$roleKey], "Duplicate {$roleKey} {$copy}");
    }

    $this->artisan('users:align-aji-access', ['--apply' => true])->assertFailed();

    expect($aji->fresh()->group)->toBe('admin')
        ->and(DB::table('user_roles')->where('user_id', $aji->id)->where('role', 'superadmin')->exists())->toBeFalse()
        ->and(collect(activeAjiEnterpriseRoles($aji))->filter(fn ($key) => $key === $roleKey)->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('entity_id', $aji->id)->count())->toBe(0);
})->with(['tenant_owner', 'hr_manager']);

it('rolls back only marker-owned changes and retains the existing HR assignment', function () {
    [, $aji, , $hrAssignmentId] = ajiAccessFixture();

    $this->artisan('users:align-aji-access', ['--apply' => true])->assertSuccessful();

    // Rollback also defaults to preview and must remain read-only.
    $this->artisan('users:align-aji-access', ['--rollback' => true])->assertSuccessful();
    expect($aji->fresh()->group)->toBe('superadmin');

    $this->artisan('users:align-aji-access', [
        '--rollback' => true,
        '--apply' => true,
    ])->assertSuccessful();

    $adminRole = DB::table('user_roles')->where('user_id', $aji->id)->where('role', 'admin')->first();
    $tenantOwnerAssignment = DB::table('rbac_user_assignments as assignment')
        ->join('rbac_roles as role', 'role.id', '=', 'assignment.role_id')
        ->where('assignment.user_id', $aji->id)
        ->where('role.key', 'tenant_owner')
        ->select('assignment.*')
        ->first();

    expect($aji->fresh()->group)->toBe('admin')
        ->and((bool) $adminRole->is_primary)->toBeTrue()
        ->and($adminRole->notes)->toBe('Existing HR primary role')
        ->and(DB::table('user_roles')->where('user_id', $aji->id)->where('role', 'superadmin')->exists())->toBeFalse()
        ->and(DB::table('rbac_user_assignments')->where('id', $hrAssignmentId)->exists())->toBeTrue()
        ->and($tenantOwnerAssignment)->not->toBeNull()
        ->and($tenantOwnerAssignment->status)->toBe('revoked')
        ->and($tenantOwnerAssignment->valid_until)->not->toBeNull()
        ->and(activeAjiEnterpriseRoles($aji))->toBe(['hr_manager'])
        ->and(DB::table('audit_logs')
            ->where('action', 'access.aji_hr_superadmin.rolled_back')
            ->where('entity_id', $aji->id)
            ->count())->toBe(1);

    // Completed rollback is itself idempotent.
    $this->artisan('users:align-aji-access', [
        '--rollback' => true,
        '--apply' => true,
    ])->assertSuccessful();
    expect(DB::table('audit_logs')
        ->where('action', 'access.aji_hr_superadmin.rolled_back')
        ->where('entity_id', $aji->id)
        ->count())->toBe(1);
});

it('recognizes an already-revoked marker assignment during rollback', function () {
    [, $aji] = ajiAccessFixture();

    $this->artisan('users:align-aji-access', ['--apply' => true])->assertSuccessful();
    $tenantOwnerRoleId = DB::table('rbac_roles')
        ->where('company_id', $aji->company_id)
        ->where('key', 'tenant_owner')
        ->value('id');
    $markerAssignment = DB::table('rbac_user_assignments')
        ->where('user_id', $aji->id)
        ->where('role_id', $tenantOwnerRoleId)
        ->where('source', 'artisan_role_align')
        ->first();
    DB::table('rbac_user_assignments')->where('id', $markerAssignment->id)->update([
        'status' => 'revoked',
        'valid_until' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('users:align-aji-access', [
        '--rollback' => true,
        '--apply' => true,
    ])->assertSuccessful();

    $markerAssignment = DB::table('rbac_user_assignments')->where('id', $markerAssignment->id)->first();
    expect($aji->fresh()->group)->toBe('admin')
        ->and($markerAssignment)->not->toBeNull()
        ->and($markerAssignment->status)->toBe('revoked')
        ->and($markerAssignment->valid_until)->not->toBeNull()
        ->and(activeAjiEnterpriseRoles($aji))->toBe(['hr_manager']);
});

it('refuses rollback when a marked row was manually changed', function () {
    [, $aji] = ajiAccessFixture();

    $this->artisan('users:align-aji-access', ['--apply' => true])->assertSuccessful();
    $tenantOwnerRoleId = DB::table('rbac_roles')
        ->where('company_id', $aji->company_id)
        ->where('key', 'tenant_owner')
        ->value('id');
    DB::table('rbac_user_assignments')
        ->where('user_id', $aji->id)
        ->where('role_id', $tenantOwnerRoleId)
        ->update(['reason' => 'Manually reviewed owner assignment']);

    $this->artisan('users:align-aji-access', [
        '--rollback' => true,
        '--apply' => true,
    ])->assertFailed();

    expect($aji->fresh()->group)->toBe('superadmin')
        ->and(DB::table('user_roles')->where('user_id', $aji->id)->where('role', 'superadmin')->exists())->toBeTrue()
        ->and(DB::table('rbac_user_assignments')
            ->where('user_id', $aji->id)
            ->where('role_id', $tenantOwnerRoleId)
            ->exists())->toBeTrue()
        ->and(DB::table('audit_logs')
            ->where('action', 'access.aji_hr_superadmin.rolled_back')
            ->where('entity_id', $aji->id)
            ->exists())->toBeFalse();
});

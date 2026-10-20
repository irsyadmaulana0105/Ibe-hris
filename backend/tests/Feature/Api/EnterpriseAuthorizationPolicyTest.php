<?php

use App\Keuangan\Models\Account;
use App\Models\Company;
use App\Models\Payroll;
use App\Models\RbacUserAssignment;
use App\Models\User;
use App\Services\EnterpriseAuthorizationService;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'enterprise-rbac');

function enterpriseGrant(User $user, string $roleKey, string $capability, array $assignment = []): RbacUserAssignment
{
    $roleKey = 'test_'.substr(hash('sha256', $roleKey.'|'.$user->id.'|'.$capability), 0, 40);
    $roleId = DB::table('rbac_roles')->insertGetId([
        'company_id' => $user->company_id,
        'key' => $roleKey,
        'label' => $roleKey,
        'is_builtin' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $capabilityId = DB::table('rbac_capabilities')
        ->where('company_id', $user->company_id)
        ->where('capability_key', $capability)
        ->value('id');
    if (! $capabilityId) {
        $capabilityId = DB::table('rbac_capabilities')->insertGetId([
            'company_id' => $user->company_id,
            'module' => explode('.', $capability)[0],
            'capability_key' => $capability,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    DB::table('rbac_role_capability')->insert([
        'role_id' => $roleId,
        'capability_id' => $capabilityId,
    ]);

    return RbacUserAssignment::create(array_merge([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'company_id' => $user->company_id,
        'status' => 'active',
        'valid_from' => now()->subMinute(),
        'valid_until' => now()->addDay(),
        'reason' => 'Automated enterprise policy test',
        'source' => 'test',
    ], $assignment));
}

it('keeps legacy authorization authoritative in shadow mode', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    $finance = User::factory()->create(['company_id' => 1, 'group' => 'finance']);

    expect($finance->hasCapability('finance.module.access'))->toBeTrue()
        ->and($finance->hasCapability('production.manage'))->toBeFalse();
});

it('enforces active enterprise assignments and expiration', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $user = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $active = enterpriseGrant($user, 'sales_officer', 'sales.order.create');

    expect(app(EnterpriseAuthorizationService::class)->allows($user, 'sales.order.create'))->toBeTrue();

    $active->update(['valid_until' => now()->subMinute()]);
    app()->forgetInstance(EnterpriseAuthorizationService::class);
    expect(app(EnterpriseAuthorizationService::class)->allows($user, 'sales.order.create'))->toBeFalse();
});

it('applies assignment scope and approval amount limits', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $user = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $assignment = enterpriseGrant($user, 'procurement_manager', 'procurement.po.approve', [
        'approval_limit' => 10000000,
    ]);
    DB::table('rbac_assignment_scopes')->insert([
        'assignment_id' => $assignment->id,
        'scope_type' => 'division_id',
        'scope_id' => '10',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $policy = app(EnterpriseAuthorizationService::class);
    expect($policy->allows($user, 'procurement.po.approve', [
        'company_id' => 1, 'amount' => 9000000, 'scopes' => ['division_id' => 10],
    ]))->toBeTrue()
        ->and($policy->allows($user, 'procurement.po.approve', [
            'company_id' => 1, 'amount' => 11000000, 'scopes' => ['division_id' => 10],
        ]))->toBeFalse()
        ->and($policy->allows($user, 'procurement.po.approve', [
            'company_id' => 1, 'amount' => 9000000, 'scopes' => ['division_id' => 11],
        ]))->toBeFalse();
});

it('blocks cross-tenant and self approval even when capability is granted', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $user = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    enterpriseGrant($user, 'finance_controller', 'finance.journal.post');
    $policy = app(EnterpriseAuthorizationService::class);

    expect($policy->targetDecision($user, 'finance.journal.post', ['company_id' => 2])['reason'])
        ->toBe('tenant_mismatch')
        ->and($policy->targetDecision($user, 'finance.journal.post', [
            'company_id' => 1,
            'maker_id' => $user->id,
            'irreversible' => true,
        ])['reason'])->toBe('sod_self_approval');
});

it('supports time-bound delegated capabilities', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $delegator = User::factory()->create(['company_id' => 1, 'group' => 'admin']);
    $delegate = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    DB::table('rbac_capabilities')->insert([
        'company_id' => 1,
        'module' => 'people',
        'capability_key' => 'people.leave.approve',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('rbac_delegations')->insert([
        'id' => (string) str()->ulid(),
        'company_id' => 1,
        'delegator_id' => $delegator->id,
        'delegate_id' => $delegate->id,
        'capability_key' => 'people.leave.approve',
        'valid_from' => now()->subMinute(),
        'valid_until' => now()->addHour(),
        'status' => 'active',
        'reason' => 'Manager annual leave coverage',
        'approved_by' => $delegator->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(EnterpriseAuthorizationService::class)->allows($delegate, 'people.leave.approve'))->toBeTrue();
});

it('records sanitized shadow mismatches when enabled', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    config()->set('enterprise_rbac.record_decisions', true);
    $finance = User::factory()->create(['company_id' => 1, 'group' => 'finance']);

    expect($finance->hasCapability('finance.module.access'))->toBeTrue();
    $decision = DB::table('rbac_access_decisions')->first();

    expect($decision)->not->toBeNull()
        ->and((bool) $decision->legacy_allowed)->toBeTrue()
        ->and((bool) $decision->target_allowed)->toBeFalse()
        ->and($decision->reason_code)->toBe('capability_not_granted');
});

it('lets only RBAC administrators grant scoped assignments and revoke them', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    [$superadmin, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $roleId = DB::table('rbac_roles')->insertGetId([
        'company_id' => 1,
        'key' => 'enterprise_test_manager',
        'label' => 'Enterprise Test Manager',
        'is_builtin' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $created = $this->postJson('/api/v1/admin/rbac/enterprise/assignments', [
        'user_id' => $employee->id,
        'role_id' => $roleId,
        'valid_from' => now()->toIso8601String(),
        'valid_until' => now()->addMonth()->toIso8601String(),
        'approval_limit' => 5000000,
        'reason' => 'Enterprise UAT assignment',
        'scopes' => [['type' => 'division_id', 'id' => '10']],
    ], bearerHeaders($token))->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.scopes.0.scope_type', 'division_id');

    $assignmentId = $created->json('data.id');
    $this->postJson("/api/v1/admin/rbac/enterprise/assignments/{$assignmentId}/revoke", [
        'reason' => 'UAT completed',
    ], bearerHeaders($token))->assertOk()->assertJsonPath('data.status', 'revoked');

    expect(DB::table('audit_logs')->where('action', 'rbac.assignment.granted')->exists())->toBeTrue();

    [$employeeActor, $employeeToken] = employeeWithToken(['company_id' => 1]);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/admin/rbac/enterprise/assignments', bearerHeaders($employeeToken))->assertForbidden();
});

it('prevents RBAC administrators from elevating or delegating access to themselves', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    [$superadmin, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $other = User::factory()->create(['company_id' => 1, 'group' => 'admin']);
    $roleId = DB::table('rbac_roles')->insertGetId([
        'company_id' => 1,
        'key' => 'sensitive_security_admin',
        'label' => 'Sensitive Security Admin',
        'is_builtin' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/v1/admin/rbac/enterprise/assignments', [
        'user_id' => $superadmin->id,
        'role_id' => $roleId,
        'reason' => 'Self elevation must be blocked',
    ], bearerHeaders($token))->assertForbidden();

    $this->postJson('/api/v1/admin/rbac/enterprise/delegations', [
        'delegator_id' => $other->id,
        'delegate_id' => $superadmin->id,
        'role_id' => $roleId,
        'valid_from' => now()->toIso8601String(),
        'valid_until' => now()->addHour()->toIso8601String(),
        'reason' => 'Self delegation must be blocked',
    ], bearerHeaders($token))->assertForbidden();
});

it('rejects unknown delegated capabilities and audits delegation lifecycle', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    [$superadmin, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $delegator = User::factory()->create(['company_id' => 1, 'group' => 'admin']);
    $delegate = User::factory()->create(['company_id' => 1, 'group' => 'user']);

    $payload = [
        'delegator_id' => $delegator->id,
        'delegate_id' => $delegate->id,
        'valid_from' => now()->toIso8601String(),
        'valid_until' => now()->addHour()->toIso8601String(),
        'reason' => 'Temporary approval coverage',
    ];
    $this->postJson('/api/v1/admin/rbac/enterprise/delegations', [
        ...$payload,
        'capability_key' => 'tenant.escape.capability',
    ], bearerHeaders($token))->assertUnprocessable()->assertJsonValidationErrors('capability_key');

    DB::table('rbac_capabilities')->insert([
        'company_id' => 1,
        'module' => 'people',
        'capability_key' => 'people.leave.approve',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $created = $this->postJson('/api/v1/admin/rbac/enterprise/delegations', [
        ...$payload,
        'capability_key' => 'people.leave.approve',
    ], bearerHeaders($token))->assertCreated();
    $delegationId = $created->json('data.id');

    $this->postJson("/api/v1/admin/rbac/enterprise/delegations/{$delegationId}/revoke", [
        'reason' => 'Coverage ended',
    ], bearerHeaders($token))->assertOk();

    expect(DB::table('audit_logs')->where('action', 'rbac.delegation.granted')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'rbac.delegation.revoked')->exists())->toBeTrue();
});

it('fails closed for missing sibling null and ambiguous delegated targets', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $delegator = User::factory()->create(['company_id' => 1, 'group' => 'admin']);
    $delegate = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $foreignCompany = Company::query()->create([
        'name' => 'Foreign Delegation Tenant',
        'slug' => 'foreign-delegation-'.str()->ulid(),
    ]);
    expect((int) $foreignCompany->id)->not->toBe(1);
    $now = now();

    $insertDelegation = function (?int $roleId, ?string $capabilityKey) use ($delegator, $delegate, $now): string {
        $id = (string) str()->ulid();
        DB::table('rbac_delegations')->insert([
            'id' => $id,
            'company_id' => 1,
            'delegator_id' => $delegator->id,
            'delegate_id' => $delegate->id,
            'role_id' => $roleId,
            'capability_key' => $capabilityKey,
            'valid_from' => $now->copy()->subMinute(),
            'valid_until' => $now->copy()->addHour(),
            'status' => 'active',
            'reason' => 'Delegation integrity fixture',
            'approved_by' => $delegator->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    };
    $decision = function (string $capability) use ($delegate): array {
        app()->forgetInstance(EnterpriseAuthorizationService::class);

        return app(EnterpriseAuthorizationService::class)->targetDecision($delegate, $capability);
    };

    $directKey = 'people.direct.local';
    $directCapability = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => 1,
        'module' => 'people',
        'capability_key' => $directKey,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $directId = $insertDelegation(null, $directKey);
    expect($decision($directKey)['allowed'])->toBeTrue();
    DB::table('rbac_capabilities')->where('id', $directCapability)->delete();
    expect($decision($directKey)['allowed'])->toBeFalse();
    DB::table('rbac_delegations')->where('id', $directId)->delete();

    $siblingOnlyKey = 'people.direct.sibling';
    DB::table('rbac_capabilities')->insert([
        'company_id' => $foreignCompany->id,
        'module' => 'people',
        'capability_key' => $siblingOnlyKey,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $siblingDirectId = $insertDelegation(null, $siblingOnlyKey);
    expect($decision($siblingOnlyKey)['allowed'])->toBeFalse();
    DB::table('rbac_delegations')->where('id', $siblingDirectId)->delete();

    $roleKey = 'people.delegated.role';
    $localRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => 1,
        'key' => 'local_delegated_role',
        'label' => 'Local Delegated Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $foreignRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => $foreignCompany->id,
        'key' => 'foreign_delegated_role',
        'label' => 'Foreign Delegated Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $nullRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => null,
        'key' => 'null_delegated_role',
        'label' => 'Null Delegated Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $localCapability = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => 1,
        'module' => 'people',
        'capability_key' => $roleKey,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $foreignCapability = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => $foreignCompany->id,
        'module' => 'people',
        'capability_key' => $roleKey,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $nullCapability = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => null,
        'module' => 'people',
        'capability_key' => $roleKey,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ([
        [$foreignRole, $foreignCapability],
        [$foreignRole, $localCapability],
        [$localRole, $foreignCapability],
        [$nullRole, $nullCapability],
    ] as [$roleId, $capabilityId]) {
        DB::table('rbac_role_capability')->insertOrIgnore([
            'role_id' => $roleId,
            'capability_id' => $capabilityId,
        ]);
        $delegationId = $insertDelegation($roleId, null);
        expect($decision($roleKey)['allowed'])->toBeFalse();
        DB::table('rbac_delegations')->where('id', $delegationId)->delete();
    }

    DB::table('rbac_role_capability')->insert([
        'role_id' => $localRole,
        'capability_id' => $localCapability,
    ]);
    $localRoleDelegation = $insertDelegation($localRole, null);
    expect($decision($roleKey)['allowed'])->toBeTrue();
    DB::table('rbac_delegations')->where('id', $localRoleDelegation)->delete();

    $ambiguous = $insertDelegation($localRole, $roleKey);
    expect($decision($roleKey)['allowed'])->toBeFalse();
    DB::table('rbac_delegations')->where('id', $ambiguous)->delete();
    $neither = $insertDelegation(null, null);
    expect($decision($roleKey)['allowed'])->toBeFalse();
    DB::table('rbac_delegations')->where('id', $neither)->delete();
});

it('rejects ambiguous and cross-tenant enterprise RBAC writes', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    [$actor, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($token);
    $localDelegator = User::factory()->create(['company_id' => 1, 'group' => 'admin']);
    $localDelegate = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $foreignCompany = Company::query()->create([
        'name' => 'Foreign Enterprise API Tenant',
        'slug' => 'foreign-enterprise-api-'.str()->ulid(),
    ]);
    expect((int) $foreignCompany->id)->not->toBe(1);
    $foreignDelegator = User::factory()->create(['company_id' => $foreignCompany->id, 'group' => 'admin']);
    $foreignDelegate = User::factory()->create(['company_id' => $foreignCompany->id, 'group' => 'user']);
    $now = now();

    $localRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => 1,
        'key' => 'local_enterprise_role',
        'label' => 'Local Enterprise Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $foreignRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => $foreignCompany->id,
        'key' => 'foreign_enterprise_role',
        'label' => 'Foreign Enterprise Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('rbac_capabilities')->insert([
        'company_id' => 1,
        'module' => 'people',
        'capability_key' => 'people.enterprise.delegate',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('rbac_capabilities')->insert([
        'company_id' => $foreignCompany->id,
        'module' => 'people',
        'capability_key' => 'people.foreign.delegate',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $base = [
        'delegator_id' => $localDelegator->id,
        'delegate_id' => $localDelegate->id,
        'valid_from' => $now->copy()->subMinute()->toIso8601String(),
        'valid_until' => $now->copy()->addHour()->toIso8601String(),
        'reason' => 'Enterprise boundary fixture',
    ];
    $this->postJson('/api/v1/admin/rbac/enterprise/delegations', [
        ...$base,
        'role_id' => $localRole,
        'capability_key' => 'people.enterprise.delegate',
    ], $headers)->assertUnprocessable();
    $this->postJson('/api/v1/admin/rbac/enterprise/delegations', $base, $headers)
        ->assertUnprocessable();

    foreach ([
        ['delegator_id' => $foreignDelegator->id],
        ['delegate_id' => $foreignDelegate->id],
        ['role_id' => $foreignRole],
        ['capability_key' => 'people.foreign.delegate'],
    ] as $foreignReference) {
        $payload = [...$base, ...$foreignReference];
        if (! array_key_exists('role_id', $payload) && ! array_key_exists('capability_key', $payload)) {
            $payload['role_id'] = $localRole;
        }
        $this->postJson('/api/v1/admin/rbac/enterprise/delegations', $payload, $headers)
            ->assertUnprocessable();
    }

    $this->postJson('/api/v1/admin/rbac/enterprise/assignments', [
        'user_id' => $foreignDelegate->id,
        'role_id' => $localRole,
        'reason' => 'Foreign user must be rejected',
    ], $headers)->assertUnprocessable();
    $this->postJson('/api/v1/admin/rbac/enterprise/assignments', [
        'user_id' => $localDelegate->id,
        'role_id' => $foreignRole,
        'reason' => 'Foreign role must be rejected',
    ], $headers)->assertUnprocessable();

    expect(DB::table('rbac_delegations')->count())->toBe(0)
        ->and(DB::table('rbac_user_assignments')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('actor_id', $actor->id)->count())->toBe(0);
});

it('filters corrupted enterprise assignments and cannot revoke sibling rows', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($token);
    $localUser = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $foreignCompany = Company::query()->create([
        'name' => 'Foreign Assignment Tenant',
        'slug' => 'foreign-assignment-'.str()->ulid(),
    ]);
    expect((int) $foreignCompany->id)->not->toBe(1);
    $foreignUser = User::factory()->create(['company_id' => $foreignCompany->id, 'group' => 'user']);
    $now = now();

    $localRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => 1,
        'key' => 'local_assignment_role',
        'label' => 'Local Assignment Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $foreignRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => $foreignCompany->id,
        'key' => 'foreign_assignment_role',
        'label' => 'Foreign Assignment Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $nullRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => null,
        'key' => 'null_assignment_role',
        'label' => 'Null Assignment Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $insertAssignment = function (string $userId, int $roleId, int $companyId) use ($now): string {
        $id = (string) str()->ulid();
        DB::table('rbac_user_assignments')->insert([
            'id' => $id,
            'user_id' => $userId,
            'role_id' => $roleId,
            'company_id' => $companyId,
            'status' => 'active',
            'valid_from' => $now->copy()->subMinute(),
            'valid_until' => $now->copy()->addHour(),
            'reason' => 'Assignment integrity fixture',
            'source' => 'test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    };

    $valid = $insertAssignment($localUser->id, $localRole, 1);
    $foreignUserCorruption = $insertAssignment($foreignUser->id, $localRole, 1);
    $foreignRoleCorruption = $insertAssignment($localUser->id, $foreignRole, 1);
    $nullRoleCorruption = $insertAssignment($localUser->id, $nullRole, 1);
    $sibling = $insertAssignment($foreignUser->id, $foreignRole, $foreignCompany->id);

    $response = $this->getJson('/api/v1/admin/rbac/enterprise/assignments', $headers)->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$valid])
        ->not->toContain($foreignUserCorruption, $foreignRoleCorruption, $nullRoleCorruption, $sibling);

    $this->postJson("/api/v1/admin/rbac/enterprise/assignments/{$sibling}/revoke", [
        'reason' => 'Sibling revoke must fail',
    ], $headers)->assertNotFound();
    expect(DB::table('rbac_user_assignments')->where('id', $sibling)->value('status'))->toBe('active');

    $siblingDelegation = (string) str()->ulid();
    DB::table('rbac_delegations')->insert([
        'id' => $siblingDelegation,
        'company_id' => $foreignCompany->id,
        'delegator_id' => $foreignUser->id,
        'delegate_id' => $foreignUser->id,
        'role_id' => $foreignRole,
        'capability_key' => null,
        'valid_from' => $now->copy()->subMinute(),
        'valid_until' => $now->copy()->addHour(),
        'status' => 'active',
        'reason' => 'Sibling delegation fixture',
        'approved_by' => $foreignUser->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->postJson("/api/v1/admin/rbac/enterprise/delegations/{$siblingDelegation}/revoke", [
        'reason' => 'Sibling revoke must fail',
    ], $headers)->assertNotFound();
    expect(DB::table('rbac_delegations')->where('id', $siblingDelegation)->value('status'))->toBe('active');
});

it('passes purchase order maker amount and warehouse context into enforced SoD', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    [$maker, $makerToken] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $now = now();
    $supplierId = DB::table('fin_suppliers')->insertGetId([
        'company_id' => 1, 'code' => 'SUP-SOD', 'name' => 'Supplier SoD', 'is_active' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $warehouseId = DB::table('inventory_warehouses')->insertGetId([
        'company_id' => 1, 'code' => 'WH-SOD', 'name' => 'Warehouse SoD', 'type' => 'main',
        'is_default' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $productId = DB::table('products')->insertGetId([
        'company_id' => 1, 'code' => 'RAW-SOD', 'name' => 'Raw SoD', 'product_type' => 'raw_material',
        'unit_of_measure' => 'pcs', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $po = $this->postJson('/api/v1/admin/purchase-orders', [
        'supplier_id' => $supplierId, 'warehouse_id' => $warehouseId, 'date' => '2026-07-29',
    ], bearerHeaders($makerToken))->assertCreated();
    $poId = $po->json('data.id');
    $this->putJson("/api/v1/admin/purchase-orders/{$poId}/lines", ['lines' => [[
        'product_id' => $productId, 'qty_ordered' => 2, 'unit_cost' => 100000,
    ]]], bearerHeaders($makerToken))->assertOk();

    enterpriseGrant($maker, 'po_maker_approver_test', 'inventory.manage', ['approval_limit' => 500000]);
    config()->set('enterprise_rbac.mode', 'enforce');
    app()->forgetInstance(EnterpriseAuthorizationService::class);
    $this->postJson("/api/v1/admin/purchase-orders/{$poId}/approve", [], bearerHeaders($makerToken))->assertForbidden();

    $approver = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $approverToken = $approver->createToken('po-approver', ['admin'])->plainTextToken;
    enterpriseGrant($approver, 'po_independent_approver_test', 'inventory.manage', ['approval_limit' => 500000]);
    enterpriseGrant($approver, 'admin_surface_approver_test', 'admin.access');
    app('auth')->forgetGuards();
    app()->forgetInstance(EnterpriseAuthorizationService::class);
    $this->postJson("/api/v1/admin/purchase-orders/{$poId}/approve", [], bearerHeaders($approverToken))
        ->assertOk()->assertJsonPath('data.approved_by', $approver->id);
});

it('enforces independent payroll submit approve and pay actors', function () {
    config()->set('enterprise_rbac.mode', 'enforce');

    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $submitter = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $approver = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $payer = User::factory()->create(['company_id' => 1, 'group' => 'user']);

    foreach ([$approver, $payer] as $actor) {
        enterpriseGrant($actor, "admin_surface_{$actor->id}", 'admin.access');
    }
    enterpriseGrant($approver, 'payroll_independent_approver', 'payroll.approve', [
        'approval_limit' => 10000000,
    ]);
    enterpriseGrant($approver, 'payroll_same_actor_payer', 'payroll.pay', [
        'approval_limit' => 10000000,
    ]);
    enterpriseGrant($payer, 'payroll_independent_payer', 'payroll.pay', [
        'approval_limit' => 10000000,
    ]);

    $payroll = Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'submitted',
        'gross_total' => 7500000,
        'deduction_total' => 0,
        'net_total' => 7500000,
        'generated_by' => $submitter->id,
        'submitted_by' => $submitter->id,
    ]);
    Account::withoutGlobalScopes()->create([
        'company_id' => 1, 'code' => '5210', 'name' => 'Beban Gaji',
        'type' => 'expense', 'normal_balance' => 'debit', 'is_header' => false, 'is_active' => true,
    ]);
    Account::withoutGlobalScopes()->create([
        'company_id' => 1, 'code' => '1120', 'name' => 'Bank',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_header' => false, 'is_active' => true,
    ]);

    $approverToken = $approver->createToken('payroll-approver', ['admin'])->plainTextToken;
    $payerToken = $payer->createToken('payroll-payer', ['admin'])->plainTextToken;

    app()->forgetInstance(EnterpriseAuthorizationService::class);
    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/approve", [], bearerHeaders($approverToken))
        ->assertOk()->assertJsonPath('data.status', 'approved');

    app('auth')->forgetGuards();
    app()->forgetInstance(EnterpriseAuthorizationService::class);
    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/mark-paid", [], bearerHeaders($approverToken))
        ->assertForbidden();

    app('auth')->forgetGuards();
    app()->forgetInstance(EnterpriseAuthorizationService::class);
    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/mark-paid", [], bearerHeaders($payerToken))
        ->assertOk()->assertJsonPath('data.status', 'paid');

    expect($payroll->fresh()->approved_by)->toBe($approver->id)
        ->and($payroll->fresh()->paid_by)->toBe($payer->id);
});

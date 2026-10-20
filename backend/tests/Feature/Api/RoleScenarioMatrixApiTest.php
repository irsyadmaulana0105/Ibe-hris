<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'role-scenario-matrix');

function roleHeaders(string $group, int $companyId = 1): array
{
    $user = User::factory()->create([
        'company_id' => $companyId,
        'group' => $group,
        'employment_status' => 'permanent',
    ]);

    return bearerHeaders($user->createToken("role-matrix-{$group}", ['admin', 'employee'])->plainTextToken);
}

it('enforces the employee self-service boundary', function () {
    $headers = roleHeaders('user');

    $this->getJson('/api/v1/auth/me', $headers)->assertOk();
    $this->getJson('/api/v1/payrolls', $headers)->assertOk();
    $this->getJson('/api/v1/admin/attendances', $headers)->assertForbidden();
    $this->getJson('/api/v1/crm/leads', $headers)->assertForbidden();
    $this->getJson('/api/v1/finance/dashboard', $headers)->assertForbidden();
    $this->getJson('/api/v1/admin/inventory/stock', $headers)->assertForbidden();
    $this->getJson('/api/v1/admin/complaints', $headers)->assertForbidden();
});

it('allows HR to operate people and shared operational modules but not finance posting', function () {
    $headers = roleHeaders('admin');

    $this->getJson('/api/v1/admin/attendances', $headers)->assertOk();
    $this->getJson('/api/v1/crm/leads', $headers)->assertOk();
    $this->getJson('/api/v1/admin/inventory/stock', $headers)->assertOk();
    $this->getJson('/api/v1/admin/work-orders', $headers)->assertOk();
    $this->getJson('/api/v1/admin/shipments', $headers)->assertOk();
    $this->getJson('/api/v1/finance/dashboard', $headers)->assertForbidden();
});

it('keeps Finance within finance payroll and read-only commercial visibility', function () {
    $headers = roleHeaders('finance');

    $this->getJson('/api/v1/finance/dashboard', $headers)->assertOk();
    $this->getJson('/api/v1/admin/payrolls', $headers)->assertOk();
    $this->getJson('/api/v1/crm/leads', $headers)->assertOk();
    $this->getJson('/api/v1/admin/inventory/stock', $headers)->assertOk();
    $this->postJson('/api/v1/crm/leads', ['name' => 'Forbidden Lead'], $headers)->assertForbidden();
    $this->getJson('/api/v1/admin/attendances', $headers)->assertForbidden();
    $this->getJson('/api/v1/admin/work-orders', $headers)->assertForbidden();
});

it('allows Marketing to manage CRM without HR finance or production access', function () {
    $headers = roleHeaders('marketing');

    $this->getJson('/api/v1/crm/leads', $headers)->assertOk();
    $this->postJson('/api/v1/crm/leads', [
        'name' => 'Marketing Role Lead',
        'email' => 'marketing-role@example.test',
        'source' => 'role_matrix',
    ], $headers)->assertCreated();
    $this->getJson('/api/v1/admin/attendances', $headers)->assertForbidden();
    $this->getJson('/api/v1/finance/dashboard', $headers)->assertForbidden();
    $this->getJson('/api/v1/admin/work-orders', $headers)->assertForbidden();
});

it('allows Production to operate production inventory and complaints only', function () {
    $headers = roleHeaders('produksi');

    $this->getJson('/api/v1/admin/work-orders', $headers)->assertOk();
    $this->getJson('/api/v1/admin/inventory/stock', $headers)->assertOk();
    $this->getJson('/api/v1/admin/complaints', $headers)->assertOk();
    $this->getJson('/api/v1/admin/shipments', $headers)->assertOk();
    $this->getJson('/api/v1/crm/leads', $headers)->assertForbidden();
    $this->getJson('/api/v1/finance/dashboard', $headers)->assertForbidden();
    $this->getJson('/api/v1/admin/attendances', $headers)->assertForbidden();
});

it('gives Director executive and approval access without operational mutation rights', function () {
    $headers = roleHeaders('direktur');

    $this->getJson('/api/v1/direktur/ringkasan-perusahaan', $headers)->assertOk();
    $this->getJson('/api/v1/direktur/approvals/pending', $headers)->assertOk();
    $this->getJson('/api/v1/direktur/contracts/pending-sign', $headers)->assertOk();
    $this->getJson('/api/v1/admin/attendances', $headers)->assertForbidden();
    $this->getJson('/api/v1/crm/leads', $headers)->assertForbidden();
    $this->getJson('/api/v1/finance/dashboard', $headers)->assertForbidden();
    $this->getJson('/api/v1/admin/work-orders', $headers)->assertForbidden();
});

it('keeps contract final-sign capability bounded to Director and Superadmin in shadow mode', function () {
    config()->set('enterprise_rbac.mode', 'shadow');

    $director = User::factory()->create(['company_id' => 1, 'group' => 'direktur']);
    $superadmin = User::factory()->create(['company_id' => 1, 'group' => 'superadmin']);
    $hr = User::factory()->create(['company_id' => 1, 'group' => 'admin']);
    $finance = User::factory()->create(['company_id' => 1, 'group' => 'finance']);

    expect($director->hasCapability('contract.final_sign'))->toBeTrue()
        ->and($superadmin->hasCapability('contract.final_sign'))->toBeTrue()
        ->and($hr->hasCapability('contract.final_sign'))->toBeFalse()
        ->and($finance->hasCapability('contract.final_sign'))->toBeFalse();
});

it('allows Superadmin across every module surface', function () {
    $headers = roleHeaders('superadmin');

    foreach ([
        '/api/v1/admin/attendances',
        '/api/v1/crm/leads',
        '/api/v1/finance/dashboard',
        '/api/v1/admin/inventory/stock',
        '/api/v1/admin/purchase-orders',
        '/api/v1/admin/work-orders',
        '/api/v1/admin/shipments',
        '/api/v1/admin/complaints',
        '/api/v1/direktur/ringkasan-perusahaan',
        '/api/v1/direktur/contracts/pending-sign',
    ] as $endpoint) {
        $this->getJson($endpoint, $headers)->assertOk();
    }
});

it('never lets an equivalent role cross company data', function () {
    $companyOne = roleHeaders('admin', 1);
    DB::table('companies')->insert([
        'id' => 2,
        'name' => 'Role Matrix Tenant Two',
        'slug' => 'role-matrix-tenant-two',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $companyTwo = roleHeaders('admin', 2);

    $lead = $this->postJson('/api/v1/crm/leads', [
        'name' => 'Tenant One Lead',
        'email' => 'tenant-one@example.test',
        'source' => 'role_matrix',
    ], $companyOne)->assertCreated();

    $leadId = $lead->json('data.id');
    expect($leadId)->not->toBeNull();

    // Laravel's stateful test kernel may retain the authenticated guard between
    // requests; production HTTP requests do not share that guard instance.
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/crm/leads/'.$leadId, $companyTwo)->assertNotFound();
});

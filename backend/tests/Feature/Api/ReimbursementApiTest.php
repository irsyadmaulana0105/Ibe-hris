<?php

use App\Models\Reimbursement;
use App\Models\ReimbursementCategory;
use App\Models\User;

uses()->group('api', 'reimbursement');

it('admin can view reimbursement summary from admin list', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $employee = User::factory()->create(['company_id' => 1]);
    $category = ReimbursementCategory::create([
        'company_id' => 1,
        'name' => 'Transport',
        'is_active' => true,
    ]);

    Reimbursement::create([
        'user_id' => $employee->id,
        'company_id' => 1,
        'category_id' => $category->id,
        'date' => '2026-04-10',
        'event' => 'Taxi reimbursement',
        'unit_price' => 100000,
        'quantity' => 1,
        'status' => 'pending',
        'total_amount' => 100000,
        'approved_amount' => null,
    ]);
    Reimbursement::create([
        'user_id' => $employee->id,
        'company_id' => 1,
        'category_id' => $category->id,
        'date' => '2026-04-11',
        'event' => 'Hotel reimbursement',
        'unit_price' => 250000,
        'quantity' => 1,
        'status' => 'approved',
        'total_amount' => 250000,
        'approved_amount' => 200000,
    ]);
    Reimbursement::create([
        'user_id' => $employee->id,
        'company_id' => 1,
        'category_id' => $category->id,
        'date' => '2026-04-12',
        'event' => 'Meal reimbursement',
        'unit_price' => 150000,
        'quantity' => 1,
        'status' => 'rejected',
        'total_amount' => 150000,
        'approved_amount' => null,
    ]);

    $this->getJson('/api/v1/admin/reimbursements', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('summary.total_requests', 3)
        ->assertJsonPath('summary.requested_total', 500000)
        ->assertJsonPath('summary.approved_total', 200000)
        ->assertJsonPath('summary.average_request', 166667)
        ->assertJsonPath('summary.status_counts.pending', 1)
        ->assertJsonPath('summary.status_counts.approved', 1)
        ->assertJsonPath('summary.status_counts.rejected', 1);
});

it('employee cannot access admin reimbursement endpoints', function () {
    [, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/reimbursements', bearerHeaders($token))
        ->assertForbidden();
});

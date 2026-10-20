<?php

use App\Marketing\Models\Pipeline;
use App\Marketing\Models\Stage;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'company-lifecycle-black-box');

it('runs marketing lead through deal quotation acceptance and sales order without losing references', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $headers = bearerHeaders($token);

    $pipeline = Pipeline::withoutGlobalScopes()->create([
        'company_id' => 1, 'name' => 'Pipeline UAT', 'is_default' => true, 'is_active' => true,
    ]);
    $newStage = Stage::withoutGlobalScopes()->create([
        'company_id' => 1, 'pipeline_id' => $pipeline->id, 'name' => 'Qualification',
        'type' => 'open', 'sort_order' => 1, 'default_probability' => 25,
    ]);
    $wonStage = Stage::withoutGlobalScopes()->create([
        'company_id' => 1, 'pipeline_id' => $pipeline->id, 'name' => 'Won',
        'type' => 'won', 'sort_order' => 2, 'default_probability' => 100,
    ]);

    $lead = $this->postJson('/api/v1/crm/leads', [
        'name' => 'Direktur PT Prospek',
        'email' => 'direktur@prospek.test',
        'phone' => '081234567890',
        'company_name' => 'PT Prospek Manufaktur',
        'source' => 'Web Profile IBE',
        'notes' => 'Memerlukan ERP 50 user dan tiga lokasi.',
        'budget_score' => 20, 'authority_score' => 25, 'need_score' => 25, 'timeline_score' => 20,
    ], $headers)->assertCreated()->assertJsonPath('data.score', 90);

    $leadId = $lead->json('data.id');
    $deal = $this->postJson("/api/v1/crm/leads/{$leadId}/convert", [
        'pipeline_id' => $pipeline->id,
        'title' => 'Implementasi ERP PT Prospek',
        'amount' => 125000000,
        'expected_close_date' => now()->addMonth()->toDateString(),
    ], $headers)->assertCreated();
    $dealId = $deal->json('data.id');

    $this->postJson("/api/v1/crm/deals/{$dealId}/move", [
        'stage_id' => $wonStage->id,
    ], $headers)->assertOk()->assertJsonPath('data.status', 'won');

    $quote = $this->postJson('/api/v1/admin/quotations', [
        'title' => 'Implementasi ERP Terintegrasi',
        'project_name' => 'ERP PT Prospek',
        'customer_name' => 'PT Prospek Manufaktur',
        'customer_pic_name' => 'Direktur PT Prospek',
        'quote_date' => now()->toDateString(),
        'valid_until' => now()->addDays(14)->toDateString(),
        'crm_deal_id' => $dealId,
        'tax_rate' => 11,
        'items' => [[
            'section_label' => 'Implementasi', 'description' => 'Lisensi dan implementasi ERP',
            'qty' => 1, 'unit' => 'paket', 'unit_price' => 125000000,
        ]],
    ], $headers)->assertCreated()->assertJsonPath('data.crm_deal_id', $dealId);
    $quoteId = $quote->json('data.id');

    $this->postJson("/api/v1/admin/quotations/{$quoteId}/send", [], $headers)
        ->assertOk()->assertJsonPath('data.status', 'sent');
    $this->postJson("/api/v1/admin/quotations/{$quoteId}/accept", [], $headers)
        ->assertOk()->assertJsonPath('data.status', 'accepted');
    $salesOrder = $this->postJson("/api/v1/admin/quotations/{$quoteId}/convert-sales-order", [], $headers)
        ->assertCreated()->assertJsonPath('data.quotation_id', $quoteId);

    expect(DB::table('crm_leads')->where('id', $leadId)->value('converted_deal_id'))->toBe($dealId)
        ->and(DB::table('quotations')->where('id', $quoteId)->value('crm_deal_id'))->toBe($dealId)
        ->and(DB::table('sales_orders')->where('id', $salesOrder->json('data.id'))->value('quotation_id'))->toBe($quoteId)
        ->and(DB::table('audit_logs')->where('action', 'crm.lead.converted')->exists())->toBeTrue();
});

it('runs public complaint through assignment response resolution close and reopen controls', function () {
    $production = User::factory()->create(['company_id' => 1, 'group' => 'produksi']);
    $headers = bearerHeaders($production->createToken('production-lifecycle', ['admin', 'employee'])->plainTextToken);

    $submitted = $this->postJson('/api/v1/public/complaints', [
        'customer_name' => 'Pelanggan UAT',
        'customer_email' => 'customer@example.test',
        'customer_phone' => '081122334455',
        'customer_company' => 'PT Customer',
        'product_serial' => 'IBE-UAT-001',
        'product_name' => 'Produk IBE',
        'subject' => 'Unit berhenti beroperasi',
        'description' => 'Unit berhenti setelah digunakan selama sepuluh menit.',
    ])->assertCreated();
    $ticketNumber = $submitted->json('data.ticket_number');
    $complaint = Complaint::withoutGlobalScopes()->where('ticket_number', $ticketNumber)->firstOrFail();

    $this->postJson("/api/v1/admin/complaints/{$complaint->id}/assign", [
        'assignee_id' => $production->id, 'note' => 'Ditugaskan ke tim service.',
    ], $headers)->assertOk()->assertJsonPath('data.assigned_to_user_id', $production->id);
    $this->postJson("/api/v1/admin/complaints/{$complaint->id}/transition", [
        'to' => 'in_progress', 'note' => 'Diagnosis dimulai.',
    ], $headers)->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/v1/admin/complaints/{$complaint->id}/comments", [
        'body' => 'Komponen pengganti sedang disiapkan.', 'is_internal' => false,
    ], $headers)->assertCreated();
    $this->postJson("/api/v1/admin/complaints/{$complaint->id}/transition", [
        'to' => 'resolved', 'note' => 'Komponen diganti dan unit diuji normal.',
    ], $headers)->assertOk()->assertJsonPath('data.status', 'resolved');
    $this->postJson("/api/v1/admin/complaints/{$complaint->id}/transition", [
        'to' => 'closed', 'note' => 'Pelanggan mengonfirmasi penyelesaian.',
    ], $headers)->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("/api/v1/admin/complaints/{$complaint->id}/transition", [
        'to' => 'reopened', 'note' => 'Masalah berulang dalam masa garansi.',
    ], $headers)->assertOk()->assertJsonPath('data.status', 'reopened');

    $detail = $this->getJson("/api/v1/admin/complaints/{$complaint->id}", $headers)->assertOk();
    expect($detail->json('data.reopen_count'))->toBe(1)
        ->and(count($detail->json('data.activities')))->toBeGreaterThanOrEqual(6);
});

it('blocks finance and employees from CRM and complaint production actions', function () {
    [, $employeeToken] = employeeWithToken(['company_id' => 1]);
    $finance = User::factory()->create(['company_id' => 1, 'group' => 'finance']);
    $financeToken = $finance->createToken('finance-lifecycle', ['admin', 'employee'])->plainTextToken;

    $this->postJson('/api/v1/crm/leads', ['name' => 'Tidak Sah'], bearerHeaders($employeeToken))->assertForbidden();
    $this->getJson('/api/v1/admin/complaints', bearerHeaders($employeeToken))->assertForbidden();
    $this->postJson('/api/v1/admin/complaints', ['customer_name' => 'Tidak Sah'], bearerHeaders($financeToken))->assertForbidden();
});

it('allows superadmin to operate service tickets exposed by the superadmin navigation', function () {
    $superadmin = User::factory()->create(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($superadmin->createToken('superadmin-lifecycle', ['admin', 'employee'])->plainTextToken);

    $this->getJson('/api/v1/admin/complaints', $headers)->assertOk();
    $this->postJson('/api/v1/admin/complaints', [
        'customer_name' => 'Customer Superadmin UAT',
        'subject' => 'Ticket manual oleh superadmin',
        'description' => 'Memastikan menu dan capability tidak bertentangan.',
        'priority' => 'normal',
        'source' => 'manual',
    ], $headers)->assertCreated();
});

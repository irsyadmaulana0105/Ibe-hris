<?php

use Illuminate\Support\Facades\DB;

it('creates and transitions a tenant scoped sales order', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $response = $this->postJson('/api/v1/admin/sales-orders', [
        'customer_name' => 'PT Pelanggan Uji',
        'order_date' => '2026-07-26',
        'tax_rate' => 11,
        'commission_rate' => 2,
        'lines' => [[
            'description' => 'Implementasi ERP', 'quantity' => 2, 'unit_price' => 1000000,
        ]],
    ], bearerHeaders($token))->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total', 2220000);

    $id = $response->json('data.id');
    $this->postJson("/api/v1/admin/sales-orders/{$id}/confirm", [], bearerHeaders($token))
        ->assertOk()->assertJsonPath('data.status', 'confirmed');

    DB::table('sales_orders')->insert([
        'company_id' => 2, 'order_number' => 'SO-2026-OTHER', 'customer_name' => 'Tenant Lain',
        'order_date' => '2026-07-26', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->getJson('/api/v1/admin/sales-orders', bearerHeaders($token))
        ->assertOk()->assertJsonCount(1, 'data.data');
});

it('runs purchase request and rfq workflows', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $headers = bearerHeaders($token);

    $request = $this->postJson('/api/v1/admin/purchase-requests', [
        'department' => 'Produksi', 'request_date' => '2026-07-26', 'priority' => 'high',
        'items' => [['description' => 'Bahan baku', 'quantity' => 10, 'estimated_unit_cost' => 25000]],
    ], $headers)->assertCreated()->assertJsonPath('data.status', 'draft');
    $id = $request->json('data.id');
    expect(json_decode(DB::table('purchase_requests')->find($id)->items, true)[0]['estimated_unit_cost'])->toBe(25000);
    $this->postJson("/api/v1/admin/purchase-requests/{$id}/submit", [], $headers)->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->postJson("/api/v1/admin/purchase-requests/{$id}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'approved');

    $this->postJson('/api/v1/admin/procurement-rfqs', [
        'purchase_request_id' => $id, 'issue_date' => '2026-07-26',
        'items' => [['description' => 'Bahan baku', 'quantity' => 10]],
    ], $headers)->assertCreated()->assertJsonPath('data.status', 'draft');
});

it('registers delivery fleets per tenant', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $headers = bearerHeaders($token);

    $this->postJson('/api/v1/admin/delivery-fleets', [
        'vehicle_number' => 'B 1234 IBE', 'vehicle_type' => 'Van', 'driver_name' => 'Pengemudi Uji',
    ], $headers)->assertCreated()->assertJsonPath('data.status', 'available');
    $this->getJson('/api/v1/admin/delivery-fleets', $headers)->assertOk()->assertJsonCount(1, 'data.data');
});

it('enforces commercial capabilities instead of broad admin access', function () {
    [, $employeeToken] = employeeWithToken(['company_id' => 1]);
    $this->getJson('/api/v1/admin/sales-orders', bearerHeaders($employeeToken))->assertForbidden();
    $this->getJson('/api/v1/admin/delivery-fleets', bearerHeaders($employeeToken))->assertForbidden();

    $finance = \App\Models\User::factory()->create(['company_id' => 1, 'group' => 'finance']);
    $financeToken = $finance->createToken('test', ['admin', 'employee'])->plainTextToken;
    $this->postJson('/api/v1/admin/sales-orders', [], bearerHeaders($financeToken))->assertForbidden();
});

it('allocates payments and completes audited sales return workflow', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $headers = bearerHeaders($token);
    $order = $this->postJson('/api/v1/admin/sales-orders', [
        'customer_name' => 'PT Return Uji', 'order_date' => '2026-07-26',
        'lines' => [['description' => 'Produk uji', 'quantity' => 2, 'unit_price' => 100000]],
    ], $headers)->assertCreated();

    $id = $order->json('data.id');

    $finance = \App\Models\User::factory()->create(['company_id' => 1, 'group' => 'finance']);
    $financeHeaders = bearerHeaders($finance->createToken('test-finance', ['admin', 'employee'])->plainTextToken);
    app('auth')->forgetGuards();
    $paymentResponse = $this->postJson("/api/v1/admin/sales-orders/{$id}/payments", [
        'payment_date' => '2026-07-26', 'amount' => 100000, 'method' => 'transfer', 'reference' => 'PAY-001',
    ], $financeHeaders);
    $paymentResponse->assertCreated()->assertJsonPath('data.payment_status', 'partial');

    app('auth')->forgetGuards();
    $return = $this->postJson('/api/v1/admin/sales-returns', [
        'sales_order_id' => $id, 'return_date' => '2026-07-26', 'reason' => 'Produk rusak',
        'items' => [['description' => 'Produk uji', 'quantity' => 1, 'unit_price' => 100000]],
    ], $headers)->assertCreated()->assertJsonPath('data.status', 'draft');
    $returnId = $return->json('data.id');
    $this->postJson("/api/v1/admin/sales-returns/{$returnId}/submit", [], $headers)->assertOk()->assertJsonPath('data.status', 'submitted');
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/sales-returns/{$returnId}/approve", [], $financeHeaders)->assertOk()->assertJsonPath('data.status', 'approved');

    expect(DB::table('audit_logs')->where('company_id', 1)->where('action', 'sales.payment.allocated')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('company_id', 1)->where('action', 'sales_return.status_changed')->exists())->toBeTrue();
});

it('converts an accepted quotation to one idempotent sales order', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $quoteId = DB::table('quotations')->insertGetId([
        'company_id' => 1, 'quote_number' => 'RAB-2026-0001', 'title' => 'RAB Uji',
        'customer_name' => 'PT Quotation', 'quote_date' => '2026-07-26', 'status' => 'accepted',
        'discount' => 0, 'tax_rate' => 11, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('quotation_items')->insert([
        'quotation_id' => $quoteId, 'description' => 'Implementasi', 'qty' => 1, 'unit' => 'paket',
        'unit_price' => 500000, 'subtotal' => 500000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $first = $this->postJson("/api/v1/admin/quotations/{$quoteId}/convert-sales-order", [], bearerHeaders($token))
        ->assertCreated()->assertJsonPath('data.quotation_id', $quoteId);
    $second = $this->postJson("/api/v1/admin/quotations/{$quoteId}/convert-sales-order", [], bearerHeaders($token))->assertCreated();
    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('sales_orders')->where('quotation_id', $quoteId)->count())->toBe(1);
});

<?php

use Illuminate\Support\Facades\DB;

uses()->group('api', 'distribution-survey-black-box');

it('runs shipment through POD delivery and one-time customer NPS response', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $headers = bearerHeaders($token);

    $shipment = $this->postJson('/api/v1/admin/shipments', [
        'customer_name' => 'PT Pelanggan Distribusi',
        'shipping_address' => 'Jakarta',
        'ship_date' => '2026-07-29',
        'expected_delivery_date' => '2026-07-30',
        'items' => [[
            'product_name' => 'Implementasi ERP',
            'qty' => 1,
            'unit_of_measure' => 'paket',
        ]],
    ], $headers)->assertCreated();

    $shipmentId = $shipment->json('data.id');
    $this->postJson("/api/v1/admin/shipments/{$shipmentId}/transition", ['to' => 'packed'], $headers)
        ->assertOk()->assertJsonPath('data.status', 'packed');
    $this->postJson("/api/v1/admin/shipments/{$shipmentId}/transition", ['to' => 'in_transit'], $headers)
        ->assertOk()->assertJsonPath('data.status', 'in_transit');
    $this->postJson("/api/v1/admin/shipments/{$shipmentId}/transition", [
        'to' => 'delivered',
        'actual_delivery_date' => '2026-07-30',
        'received_by_name' => 'Budi Penerima',
        'pod_receiver_phone' => '081234567890',
        'pod_latitude' => -6.2,
        'pod_longitude' => 106.8,
    ], $headers)->assertOk()->assertJsonPath('data.status', 'delivered');

    expect(DB::table('shipments')->find($shipmentId)->received_by_name)->toBe('Budi Penerima');

    $survey = $this->postJson('/api/v1/admin/customer-surveys', [
        'customer_name' => 'PT Pelanggan Distribusi',
        'customer_email' => 'customer@example.test',
        'trigger_type' => 'shipment_delivered',
        'trigger_ref_table' => 'shipments',
        'trigger_ref_id' => $shipmentId,
    ], $headers)->assertCreated();

    $token = $survey->json('data.survey_token');
    $this->getJson("/api/v1/public/surveys/{$token}")->assertOk();
    $this->postJson("/api/v1/public/surveys/{$token}", [
        'nps_score' => 9,
        'comment' => 'Implementasi berjalan baik.',
    ])->assertOk()->assertJsonPath('data.nps_category', 'promoter');
    $this->postJson("/api/v1/public/surveys/{$token}", ['nps_score' => 10])->assertUnprocessable();

    $this->getJson('/api/v1/admin/customer-surveys', $headers)
        ->assertOk()->assertJsonPath('data.data.0.status', 'responded');
});

it('rejects invalid shipment transitions and cross-tenant sales references', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $headers = bearerHeaders($token);
    $foreignOrder = DB::table('sales_orders')->insertGetId([
        'company_id' => 2,
        'order_number' => 'SO-FOREIGN-001',
        'customer_name' => 'Tenant Lain',
        'order_date' => '2026-07-29',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/v1/admin/shipments', [
        'customer_name' => 'Tenant Lain',
        'shipping_address' => 'Bandung',
        'ship_date' => '2026-07-29',
        'sales_order_id' => $foreignOrder,
        'items' => [['product_name' => 'Barang', 'qty' => 1]],
    ], $headers)->assertUnprocessable();

    $shipment = $this->postJson('/api/v1/admin/shipments', [
        'customer_name' => 'PT Lokal',
        'shipping_address' => 'Surabaya',
        'ship_date' => '2026-07-29',
        'items' => [['product_name' => 'Barang', 'qty' => 1]],
    ], $headers)->assertCreated();
    $this->postJson('/api/v1/admin/shipments/'.$shipment->json('data.id').'/transition', [
        'to' => 'delivered',
    ], $headers)->assertUnprocessable();
});

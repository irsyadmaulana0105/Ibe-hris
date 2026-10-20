<?php

use Illuminate\Support\Facades\DB;

uses()->group('api', 'procurement-production-black-box');

it('runs purchase receipt into component stock then consumes it into serialized finished goods', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($token);
    $now = now();

    $supplierId = DB::table('fin_suppliers')->insertGetId([
        'company_id' => 1,
        'code' => 'SUP-UAT',
        'name' => 'Supplier Bahan UAT',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $warehouseId = DB::table('inventory_warehouses')->insertGetId([
        'company_id' => 1,
        'code' => 'WH-UAT',
        'name' => 'Gudang UAT',
        'type' => 'main',
        'is_default' => true,
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $componentId = DB::table('products')->insertGetId([
        'company_id' => 1,
        'code' => 'RAW-UAT',
        'name' => 'Komponen UAT',
        'product_type' => 'raw_material',
        'unit_of_measure' => 'pcs',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $finishedId = DB::table('products')->insertGetId([
        'company_id' => 1,
        'code' => 'FG-UAT',
        'name' => 'Produk Jadi UAT',
        'product_type' => 'finished_good',
        'unit_of_measure' => 'unit',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $po = $this->postJson('/api/v1/admin/purchase-orders', [
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'date' => '2026-07-29',
        'tax_rate' => 11,
    ], $headers)->assertCreated();
    $poId = $po->json('data.id');
    $lines = $this->putJson("/api/v1/admin/purchase-orders/{$poId}/lines", [
        'lines' => [[
            'product_id' => $componentId,
            'qty_ordered' => 4,
            'unit_cost' => 25000,
            'unit_of_measure' => 'pcs',
        ]],
    ], $headers)->assertOk();
    $lineId = $lines->json('data.lines.0.id');

    $this->postJson("/api/v1/admin/purchase-orders/{$poId}/approve", [], $headers)
        ->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/admin/purchase-orders/{$poId}/send", [], $headers)
        ->assertOk()->assertJsonPath('data.status', 'sent');
    $this->postJson("/api/v1/admin/purchase-orders/{$poId}/receive", [
        'receipts' => [['line_id' => $lineId, 'qty' => 4]],
    ], $headers)->assertOk()->assertJsonPath('data.status', 'received');
    $this->postJson("/api/v1/admin/purchase-orders/{$poId}/close", [], $headers)
        ->assertOk()->assertJsonPath('data.status', 'closed');

    expect((float) DB::table('products')->find($componentId)->total_stock_cached)->toBe(4.0);

    $bomId = DB::table('boms')->insertGetId([
        'company_id' => 1,
        'product_id' => $finishedId,
        'version' => 'v1',
        'name' => 'BOM UAT',
        'is_active' => true,
        'total_cost_cached' => 50000,
        'created_by' => $admin->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('bom_components')->insert([
        'company_id' => 1,
        'bom_id' => $bomId,
        'component_product_id' => $componentId,
        'qty_required' => 2,
        'unit_of_measure' => 'pcs',
        'unit_cost_snapshot' => 25000,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $wo = $this->postJson('/api/v1/admin/work-orders', [
        'product_id' => $finishedId,
        'qty_planned' => 2,
        'source_warehouse_id' => $warehouseId,
        'output_warehouse_id' => $warehouseId,
        'source_type' => 'uat_demand',
        'source_id' => 'UAT-001',
    ], $headers)->assertCreated();
    $woId = $wo->json('data.id');
    $this->postJson("/api/v1/admin/work-orders/{$woId}/release", [], $headers)
        ->assertOk()->assertJsonPath('data.status', 'released');
    $this->postJson("/api/v1/admin/work-orders/{$woId}/complete", [
        'spec_values' => ['uat' => true],
    ], $headers)->assertOk()->assertJsonPath('data.status', 'completed');

    expect((float) DB::table('products')->find($componentId)->total_stock_cached)->toBe(0.0)
        ->and((float) DB::table('products')->find($finishedId)->total_stock_cached)->toBe(2.0)
        ->and(DB::table('work_order_outputs')->where('work_order_id', $woId)->count())->toBe(2)
        ->and(DB::table('product_serial_numbers')->where('company_id', 1)->where('product_id', $finishedId)->distinct('serial_number')->count('serial_number'))->toBe(2);
});

it('keeps work order planned and stock unchanged when components are insufficient', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($token);
    $now = now();
    $warehouseId = DB::table('inventory_warehouses')->insertGetId([
        'company_id' => 1, 'code' => 'WH-EMPTY', 'name' => 'Gudang Kosong', 'type' => 'main',
        'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $componentId = DB::table('products')->insertGetId([
        'company_id' => 1, 'code' => 'RAW-EMPTY', 'name' => 'Komponen Kosong',
        'product_type' => 'raw_material', 'unit_of_measure' => 'pcs', 'is_active' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $finishedId = DB::table('products')->insertGetId([
        'company_id' => 1, 'code' => 'FG-BLOCK', 'name' => 'Produk Tertahan',
        'product_type' => 'finished_good', 'unit_of_measure' => 'unit', 'is_active' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $bomId = DB::table('boms')->insertGetId([
        'company_id' => 1, 'product_id' => $finishedId, 'version' => 'v1', 'is_active' => true,
        'total_cost_cached' => 1000, 'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('bom_components')->insert([
        'company_id' => 1, 'bom_id' => $bomId, 'component_product_id' => $componentId,
        'qty_required' => 1, 'unit_cost_snapshot' => 1000, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $wo = $this->postJson('/api/v1/admin/work-orders', [
        'product_id' => $finishedId,
        'qty_planned' => 1,
        'source_warehouse_id' => $warehouseId,
        'output_warehouse_id' => $warehouseId,
    ], $headers)->assertCreated();
    $woId = $wo->json('data.id');
    $this->postJson("/api/v1/admin/work-orders/{$woId}/release", [], $headers)->assertUnprocessable();

    expect(DB::table('work_orders')->find($woId)->status)->toBe('planned')
        ->and(DB::table('work_order_components')->where('work_order_id', $woId)->count())->toBe(0)
        ->and(DB::table('inventory_movements')->where('reference_id', (string) $woId)->count())->toBe(0);
});

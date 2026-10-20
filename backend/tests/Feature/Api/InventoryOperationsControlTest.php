<?php

use App\Distribusi\Services\ShipmentService;
use App\Models\InventoryBatch;
use App\Models\InventoryBatchStock;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\User;
use App\Produksi\Http\Controllers\PublicVendorProgressController;
use App\Produksi\Http\Controllers\WorkOrderController as VendorWorkOrderController;
use App\Produksi\Models\Vendor;
use App\Produksi\Models\WorkOrder as VendorWorkOrder;
use App\Produksi\Models\WorkOrderProgress;
use Illuminate\Http\Request;

uses()->group('api', 'inventory-operations-controls');

/** @return array{0: Product, 1: InventoryWarehouse, 2: InventoryStock} */
function inventoryControlFixture(string $suffix, float $quantity = 10): array
{
    $product = Product::create([
        'company_id' => 1,
        'code' => "P0-{$suffix}",
        'name' => "P0 Product {$suffix}",
        'unit_of_measure' => 'unit',
        'is_active' => true,
        'product_type' => 'finished_good',
        'track_stock' => true,
        'track_batch' => false,
    ]);
    $warehouse = InventoryWarehouse::create([
        'company_id' => 1,
        'code' => "WH-{$suffix}",
        'name' => "Warehouse {$suffix}",
        'type' => 'main',
        'is_active' => true,
    ]);
    $stock = InventoryStock::create([
        'company_id' => 1,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'qty_on_hand' => $quantity,
        'qty_reserved' => 0,
    ]);

    return [$product, $warehouse, $stock];
}

function inventoryControlShipment(User $actor, Product $product, InventoryWarehouse $warehouse, string $suffix, float $quantity): Shipment
{
    $shipment = Shipment::create([
        'company_id' => $actor->company_id,
        'do_number' => "DO-P0-{$suffix}",
        'customer_name' => 'P0 Customer',
        'shipping_address' => 'Test Address',
        'source_warehouse_id' => $warehouse->id,
        'ship_date' => now()->toDateString(),
        'status' => 'draft',
        'created_by' => $actor->id,
    ]);
    ShipmentItem::create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'product_code' => $product->code,
        'product_name' => $product->name,
        'qty' => $quantity,
        'unit_of_measure' => 'unit',
    ]);

    return $shipment;
}

it('posts a stock count against current locked stock and replays the same idempotency key', function () {
    [$approver, $token] = adminWithToken(['group' => 'superadmin']);
    $counter = User::factory()->create([
        'company_id' => 1,
        'group' => 'produksi',
        'employment_status' => 'permanent',
    ]);
    [$product, $warehouse, $stock] = inventoryControlFixture('COUNT', 15);
    $count = StockCount::create([
        'company_id' => 1,
        'count_number' => 'CC-P0-0001',
        'warehouse_id' => $warehouse->id,
        'count_date' => now()->toDateString(),
        'status' => 'reconciliation',
        'created_by' => $counter->id,
        'counted_by' => $counter->id,
    ]);
    StockCountLine::create([
        'company_id' => 1,
        'stock_count_id' => $count->id,
        'product_id' => $product->id,
        'system_qty' => 10,
        'counted_qty' => 7,
        'status' => 'counted',
        'counted_by' => $counter->id,
        'counted_at' => now(),
    ]);
    $headers = bearerHeaders($token) + ['Idempotency-Key' => 'stock-count-p0-0001'];

    $first = $this->postJson("/api/v1/admin/stock-counts/{$count->id}/post", [
        'notes' => 'Approved physical count',
    ], $headers)->assertOk();
    expect($first->json('data.status'))->toBe('posted');

    $this->postJson("/api/v1/admin/stock-counts/{$count->id}/post", [
        'notes' => 'Approved physical count',
    ], $headers)
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect((float) $stock->fresh()->qty_on_hand)->toBe(7.0)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'StockCount')
            ->where('reference_id', (string) $count->id)
            ->count())->toBe(1)
        ->and((float) InventoryMovement::query()
            ->where('reference_type', 'StockCount')
            ->value('qty_delta'))->toBe(-8.0);
});

it('protects stock count endpoints with read manage and admin capabilities', function () {
    [, $financeToken] = adminWithToken(['group' => 'finance']);
    [, $employeeToken] = employeeWithToken();
    [, $warehouse] = inventoryControlFixture('CAP', 5);

    $this->getJson('/api/v1/admin/stock-counts', bearerHeaders($financeToken))->assertOk();
    $this->postJson('/api/v1/admin/stock-counts', [
        'warehouse_id' => $warehouse->id,
    ], bearerHeaders($financeToken))->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/admin/stock-counts', bearerHeaders($employeeToken))->assertForbidden();
});

it('replays direct inventory adjustment without applying stock twice', function () {
    [, $token] = adminWithToken(['group' => 'admin']);
    [$product, $warehouse, $stock] = inventoryControlFixture('IDEM', 10);
    $headers = bearerHeaders($token) + ['Idempotency-Key' => 'inventory-adjust-p0-0001'];
    $payload = [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'new_qty_on_hand' => 8,
        'reason' => 'P0 adjustment test',
    ];

    $this->postJson('/api/v1/admin/inventory/adjust', $payload, $headers)->assertOk();
    $this->postJson('/api/v1/admin/inventory/adjust', $payload, $headers)
        ->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect((float) $stock->fresh()->qty_on_hand)->toBe(8.0)
        ->and(InventoryMovement::query()->where('reason', 'adjustment')->count())->toBe(1);
});

it('restores cancelled in-transit shipment stock exactly once even with a stale model', function () {
    $actor = User::factory()->admin()->create(['company_id' => 1]);
    [$product, $warehouse, $stock] = inventoryControlFixture('CANCEL', 10);
    $shipment = inventoryControlShipment($actor, $product, $warehouse, 'CANCEL', 3);
    $service = app(ShipmentService::class);

    $service->transition($actor, $shipment, 'packed');
    $service->transition($actor, $shipment->fresh(), 'in_transit');
    expect((float) $stock->fresh()->qty_on_hand)->toBe(7.0);

    $firstStaleCopy = Shipment::query()->findOrFail($shipment->id);
    $secondStaleCopy = Shipment::query()->findOrFail($shipment->id);
    $service->transition($actor, $firstStaleCopy, 'cancelled');
    expect((float) $stock->fresh()->qty_on_hand)->toBe(10.0);

    expect(fn () => $service->transition($actor, $secondStaleCopy, 'cancelled'))
        ->toThrow(RuntimeException::class, 'Invalid transition');
    expect((float) $stock->fresh()->qty_on_hand)->toBe(10.0)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'ShipmentReversal')
            ->where('reference_id', (string) $shipment->id)
            ->count())->toBe(1);
});

it('restores delivered shipment returns exactly once', function () {
    $actor = User::factory()->admin()->create(['company_id' => 1]);
    [$product, $warehouse, $stock] = inventoryControlFixture('RETURN', 12);
    $shipment = inventoryControlShipment($actor, $product, $warehouse, 'RETURN', 4);
    $service = app(ShipmentService::class);

    $service->transition($actor, $shipment, 'packed');
    $service->transition($actor, $shipment->fresh(), 'in_transit');
    $service->transition($actor, $shipment->fresh(), 'delivered', [
        'received_by_name' => 'Customer Receiver',
    ]);
    expect((float) $stock->fresh()->qty_on_hand)->toBe(8.0);

    $firstStaleCopy = Shipment::query()->findOrFail($shipment->id);
    $secondStaleCopy = Shipment::query()->findOrFail($shipment->id);
    $service->transition($actor, $firstStaleCopy, 'returned');
    expect((float) $stock->fresh()->qty_on_hand)->toBe(12.0);

    expect(fn () => $service->transition($actor, $secondStaleCopy, 'returned'))
        ->toThrow(RuntimeException::class, 'Invalid transition');
    expect((float) $stock->fresh()->qty_on_hand)->toBe(12.0)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'ShipmentReversal')
            ->where('reference_id', (string) $shipment->id)
            ->count())->toBe(1);
});

it('restores a multi-batch shipment to the exact consumed lots', function () {
    $actor = User::factory()->admin()->create(['company_id' => 1]);
    [$product, $warehouse, $stock] = inventoryControlFixture('BATCH', 10);
    $product->update(['track_batch' => true, 'batch_strategy' => 'fifo']);
    $firstBatch = InventoryBatch::create([
        'company_id' => 1,
        'product_id' => $product->id,
        'batch_code' => 'LOT-P0-001',
        'received_at' => now()->subDay(),
        'unit_cost' => 100,
    ]);
    $secondBatch = InventoryBatch::create([
        'company_id' => 1,
        'product_id' => $product->id,
        'batch_code' => 'LOT-P0-002',
        'received_at' => now(),
        'unit_cost' => 110,
    ]);
    InventoryBatchStock::create([
        'company_id' => 1,
        'batch_id' => $firstBatch->id,
        'warehouse_id' => $warehouse->id,
        'qty_on_hand' => 6,
        'qty_reserved' => 0,
    ]);
    InventoryBatchStock::create([
        'company_id' => 1,
        'batch_id' => $secondBatch->id,
        'warehouse_id' => $warehouse->id,
        'qty_on_hand' => 4,
        'qty_reserved' => 0,
    ]);
    $shipment = inventoryControlShipment($actor, $product, $warehouse, 'BATCH', 7);
    $service = app(ShipmentService::class);

    $service->transition($actor, $shipment, 'packed');
    $service->transition($actor, $shipment->fresh(), 'in_transit');
    expect((float) $stock->fresh()->qty_on_hand)->toBe(3.0)
        ->and((float) InventoryBatchStock::where('batch_id', $firstBatch->id)->value('qty_on_hand'))->toBe(0.0)
        ->and((float) InventoryBatchStock::where('batch_id', $secondBatch->id)->value('qty_on_hand'))->toBe(3.0);

    $service->transition($actor, $shipment->fresh(), 'cancelled');
    expect((float) $stock->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) InventoryBatchStock::where('batch_id', $firstBatch->id)->value('qty_on_hand'))->toBe(6.0)
        ->and((float) InventoryBatchStock::where('batch_id', $secondBatch->id)->value('qty_on_hand'))->toBe(4.0)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'ShipmentReversal')
            ->where('reference_id', (string) $shipment->id)
            ->count())->toBe(2);
});

it('returns tenant-scoped internal and vendor-scoped progress views instead of 501', function () {
    $actor = User::factory()->admin()->create(['company_id' => 1]);
    $ownVendor = Vendor::create(['company_id' => 1, 'name' => 'Own Vendor']);
    $otherVendor = Vendor::create(['company_id' => 2, 'name' => 'Other Tenant Vendor']);
    $ownOrder = VendorWorkOrder::create([
        'company_id' => 1,
        'vendor_id' => $ownVendor->id,
        'ref_no' => 'VWO-OWN-001',
        'title' => 'Own tenant fabrication',
        'status' => 'in_progress',
        'created_by_user_id' => $actor->id,
    ]);
    VendorWorkOrder::create([
        'company_id' => 2,
        'vendor_id' => $otherVendor->id,
        'ref_no' => 'VWO-OTHER-001',
        'title' => 'Other tenant fabrication',
        'status' => 'in_progress',
    ]);
    WorkOrderProgress::create([
        'company_id' => 1,
        'work_order_id' => $ownOrder->id,
        'progress_percent' => 45,
        'note' => 'Fabrication ongoing',
        'submitted_by' => 'vendor',
        'submitted_at' => now(),
    ]);

    $internalRequest = Request::create('/api/v1/vendor-work-orders', 'GET');
    $internalRequest->setUserResolver(fn () => $actor);
    $internalPayload = app(VendorWorkOrderController::class)->index($internalRequest)->getData(true);
    expect($internalPayload['meta']['total'])->toBe(1)
        ->and($internalPayload['data'][0]['ref_no'])->toBe('VWO-OWN-001')
        ->and($internalPayload['data'][0]['progress'][0]['progress_percent'])->toBe(45);

    $vendorRequest = Request::create('/api/v1/vendor/token/orders', 'GET');
    $vendorRequest->attributes->set('vendor', $ownVendor);
    $vendorPayload = app(PublicVendorProgressController::class)->listOrders($vendorRequest)->getData(true);
    expect($vendorPayload['meta']['total'])->toBe(1)
        ->and($vendorPayload['vendor']['id'])->toBe($ownVendor->id)
        ->and($vendorPayload['data'][0]['ref_no'])->toBe('VWO-OWN-001')
        ->and($vendorPayload['data'][0]['progress'][0]['progress_percent'])->toBe(45)
        ->and(json_encode($vendorPayload))->not->toContain('VWO-OTHER-001');
});

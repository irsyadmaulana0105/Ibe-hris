<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log untuk SEMUA perubahan stock.
 * Setiap movement record:
 *   - product + warehouse
 *   - qty delta (positive = in, negative = out)
 *   - reason (kategori movement)
 *   - reference (link ke source dokumen: PO id, WO id, Bill id, dll)
 *   - performed_by (siapa yang trigger)
 *
 * Tabel ini IMMUTABLE - never UPDATE atau DELETE row. Setiap koreksi =
 * tambah movement baru tipe 'adjustment' dengan reverse delta.
 *
 * Reason types:
 *   purchase_receipt - barang diterima dari supplier (PO completion)
 *   sale_shipment    - barang dikirim ke customer (Invoice/SO)
 *   wo_consumption   - dipakai produksi (Work Order release)
 *   wo_output        - hasil produksi (Work Order completion → serial)
 *   transfer_in / transfer_out - antar warehouse
 *   adjustment       - koreksi stok physical count
 *   return_in / return_out - return goods
 *   damage           - barang rusak (write-off)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->decimal('qty_delta', 18, 2);    // signed: + = IN, - = OUT
            $t->decimal('qty_before', 18, 2);   // snapshot stock sebelum
            $t->decimal('qty_after', 18, 2);    // snapshot stock setelah
            $t->decimal('unit_cost', 18, 2)->nullable(); // saat IN dari PO
            $t->enum('reason', [
                'purchase_receipt', 'sale_shipment', 'wo_consumption', 'wo_output',
                'transfer_in', 'transfer_out', 'adjustment',
                'return_in', 'return_out', 'damage', 'initial_load',
            ]);
            $t->string('reference_type', 50)->nullable(); // PurchaseOrder, WorkOrder, Invoice, dll
            $t->string('reference_id', 64)->nullable();   // varchar krn ulid/int mix
            $t->text('notes')->nullable();
            $t->char('performed_by', 26);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'product_id', 'created_at']);
            $t->index(['company_id', 'warehouse_id', 'created_at']);
            $t->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};

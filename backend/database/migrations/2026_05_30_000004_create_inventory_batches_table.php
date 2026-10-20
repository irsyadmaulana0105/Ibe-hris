<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot/batch tracking - fungsi traceability:
 *   - Batch sebagai dimension tambahan untuk inventory_stock
 *   - FIFO/FEFO consumption order via received_at / expires_at
 *   - Compliance untuk industri yang butuh recall capability
 *
 * inventory_batches:
 *   1 batch = 1 record per (product, batch_code).
 *   Batch_code biasanya supplier-assigned atau internal (LOT-2026-XXX).
 *   received_at = waktu PO receive (untuk FIFO).
 *   expires_at = expiry date (untuk FEFO, food/pharma).
 *
 * inventory_batch_stock:
 *   Stock per (batch, warehouse). Kalau product gak track_batch, table ini kosong.
 *   Aggregate dari sini = inventory_stock.qty_on_hand.
 *
 * Migration sederhana - adopsi opsional per product. Existing flow tetap
 * jalan untuk product yang track_batch = false (default).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add track_batch flag ke products (opt-in per product)
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('track_batch')->default(false)->after('track_stock');
            $t->string('batch_strategy', 20)->nullable()->after('track_batch');
            // 'fifo' | 'fefo' | null (gak track)
            $t->boolean('has_expiry')->default(false)->after('batch_strategy');
        });

        // 2. Batch master per product
        Schema::create('inventory_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('product_id')->constrained('products');
            $t->string('batch_code', 100); // LOT-2026-XXX atau supplier lot
            $t->timestamp('received_at');  // untuk FIFO order
            $t->date('expires_at')->nullable(); // untuk FEFO (kalau has_expiry)
            $t->date('manufactured_at')->nullable();
            $t->string('supplier_lot', 100)->nullable();
            $t->ulid('source_po_id')->nullable(); // reference ke PO yang nge-receive
            $t->decimal('unit_cost', 14, 4)->nullable(); // per-batch cost (overrides product.unit_cost saat consumption)
            $t->text('notes')->nullable();
            $t->boolean('is_quarantined')->default(false); // QC hold
            $t->timestamps();

            $t->unique(['company_id', 'product_id', 'batch_code']);
            $t->index(['product_id', 'expires_at']);
            $t->index(['product_id', 'received_at']);
        });

        // 3. Per-batch stock di warehouse
        Schema::create('inventory_batch_stock', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('batch_id')->constrained('inventory_batches')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses');
            $t->decimal('qty_on_hand', 14, 4)->default(0);
            $t->decimal('qty_reserved', 14, 4)->default(0);
            $t->timestamp('last_movement_at')->nullable();
            $t->timestamps();

            $t->unique(['batch_id', 'warehouse_id'], 'uniq_batch_wh');
            $t->index(['warehouse_id']);
        });

        // 4. Batch_id reference di InventoryMovement (nullable - backward compat)
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->unsignedBigInteger('batch_id')->nullable()->after('product_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropColumn('batch_id');
        });
        Schema::dropIfExists('inventory_batch_stock');
        Schema::dropIfExists('inventory_batches');
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn(['track_batch', 'batch_strategy', 'has_expiry']);
        });
    }
};

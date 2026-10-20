<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cycle counting / stock take session + line items.
 *
 * Workflow:
 *   draft → counting → reconciliation → posted | canceled
 *
 *   draft         = sesi dibuat, line items belum ditambahkan (atau prefill via filter
 *                   kategori/warehouse/abc-class)
 *   counting      = operator/PIC mulai input count_qty per line (blind count -
 *                   system_qty disembunyikan sampai user selesai input)
 *   reconciliation = semua line ke-input, review variance, approver mengoreksi
 *   posted        = adjustment movement (qty_diff) dijalankan ke InventoryMovement,
 *                   stock berubah → counted_qty jadi authoritative
 *   canceled      = session dibuang, gak ada movement
 *
 * stock_count_lines:
 *   - system_qty: snapshot stock saat counted (locked saat session start)
 *   - counted_qty: input operator (kosong = belum di-count)
 *   - variance_qty: counted - system (negatif = kurang, positif = berlebih)
 *   - status per line: pending|counted|approved|posted
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('count_number', 50)->unique(); // CC-2026-0001
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnDelete();
            $t->date('count_date');
            $t->string('status', 20)->default('draft')->index();
            // draft → counting → reconciliation → posted | canceled
            $t->ulid('created_by')->nullable();
            $t->ulid('counted_by')->nullable();      // PIC operator yg input qty
            $t->ulid('approved_by')->nullable();     // approver final
            $t->timestamp('counting_started_at')->nullable();
            $t->timestamp('counting_completed_at')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->text('notes')->nullable();
            $t->json('filter_used')->nullable(); // {category, abc_class, ...} prefill criteria
            $t->timestamps();

            $t->index(['company_id', 'warehouse_id', 'status']);
            $t->index('count_date');
        });

        Schema::create('stock_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');
            $t->decimal('system_qty', 14, 4);    // snapshot saat lock
            $t->decimal('counted_qty', 14, 4)->nullable(); // input operator
            $t->decimal('variance_qty', 14, 4)->storedAs('CASE WHEN counted_qty IS NULL THEN NULL ELSE counted_qty - system_qty END');
            $t->string('status', 20)->default('pending');
            // pending | counted | approved | posted
            $t->text('counter_notes')->nullable();
            $t->text('approver_notes')->nullable();
            $t->ulid('counted_by')->nullable();
            $t->timestamp('counted_at')->nullable();
            $t->timestamps();

            $t->unique(['stock_count_id', 'product_id']);
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};

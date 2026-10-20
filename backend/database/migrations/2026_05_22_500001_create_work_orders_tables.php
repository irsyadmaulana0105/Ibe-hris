<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturing Work Order - perintah produksi N unit finished_good.
 *
 * Status flow:
 *   planned → released → in_progress → completed → closed
 *   (any non-completed) → cancelled
 *
 * Saat release: consume komponen sesuai BOM × N (inventory_movement reason=wo_consumption)
 * Saat complete: stock IN finished_good × N (reason=wo_output) + auto-generate Serial Numbers
 *
 * BOM snapshot at release: WO simpan reference ke BOM id + version supaya
 * historical traceability tetap accurate walau BOM diubah belakangan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('wo_number', 32);                  // WO-2026-0001
            $t->unsignedBigInteger('product_id');         // finished_good to produce
            $t->unsignedBigInteger('bom_id');             // snapshot of BOM used
            $t->string('bom_version', 20);                // snapshot for history
            $t->unsignedInteger('qty_planned');           // units to produce
            $t->unsignedInteger('qty_produced')->default(0); // unit yang sudah jadi
            $t->unsignedBigInteger('source_warehouse_id'); // dari mana ambil komponen
            $t->unsignedBigInteger('output_warehouse_id'); // ke mana finished goods masuk
            $t->enum('status', [
                'planned', 'released', 'in_progress', 'completed', 'closed', 'cancelled',
            ])->default('planned')->index();
            $t->date('planned_start_date')->nullable();
            $t->date('planned_complete_date')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            // Cost snapshot at release (for cost variance analysis later)
            $t->decimal('material_cost_planned', 18, 2)->default(0);
            $t->decimal('labor_cost_planned', 18, 2)->default(0);
            $t->decimal('overhead_cost_planned', 18, 2)->default(0);
            $t->decimal('total_cost_planned', 18, 2)->default(0);

            $t->text('notes')->nullable();
            // Link ke source: bisa dari Sales Order (CRM Deal won), Stock Replenishment, dll
            $t->string('source_type', 50)->nullable();
            $t->string('source_id', 64)->nullable();
            $t->char('created_by', 26);
            $t->char('released_by', 26)->nullable();
            $t->char('completed_by', 26)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'wo_number']);
            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'product_id']);
        });

        // Snapshot komponen yang di-consume (snapshot from BOM at release time)
        Schema::create('work_order_components', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('component_product_id');
            $t->decimal('qty_per_unit', 18, 4);          // dari BOM
            $t->decimal('qty_total_planned', 18, 4);     // qty_per_unit × wo.qty_planned
            $t->decimal('qty_consumed', 18, 4)->default(0); // actual consumed (can differ kalau ada wastage)
            $t->decimal('unit_cost_snapshot', 18, 2)->nullable();
            $t->timestamps();
            $t->index(['work_order_id']);
        });

        // Output: serial numbers yang dihasilkan
        Schema::create('work_order_outputs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id');
            $t->ulid('serial_number_id');  // FK ke product_serial_numbers.id (ulid)
            $t->timestamp('produced_at')->useCurrent();
            $t->index(['work_order_id']);
            $t->index('serial_number_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_outputs');
        Schema::dropIfExists('work_order_components');
        Schema::dropIfExists('work_orders');
    }
};

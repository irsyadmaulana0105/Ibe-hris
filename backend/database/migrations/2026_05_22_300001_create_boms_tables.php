<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOM (Bill of Materials) - daftar komponen yang dibutuhkan untuk produksi
 * 1 unit finished_good. Multi-version per product (BOM v1, BOM v2, dll) -
 * version baru bisa di-make ACTIVE, sementara version lama stay sebagai
 * historical record untuk WO yang sudah closed.
 *
 * Hanya 1 BOM aktif per product di waktu yang sama.
 *
 * Component bisa raw_material ATAU sub_assembly (yang punya BOM sendiri =
 * multi-level BOM). Cost roll-up rekursif: total = sum(component.unit_cost × qty)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');         // finished_good or sub_assembly
            $t->string('version', 20)->default('v1');     // v1, v2, R1-rev2, dll
            $t->string('name', 200)->nullable();          // optional label, e.g. "Standard config"
            $t->boolean('is_active')->default(false);
            $t->decimal('total_cost_cached', 18, 2)->default(0); // computed roll-up
            $t->decimal('labor_cost', 18, 2)->default(0);  // assembly labor allocation
            $t->decimal('overhead_cost', 18, 2)->default(0);  // overhead (electricity, depreciation, dll)
            $t->text('notes')->nullable();
            $t->char('created_by', 26);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'product_id', 'is_active']);
            // Unique partial: only 1 active BOM per product
            // (Laravel/MySQL doesn't support partial unique easily - enforce di service layer)
        });

        Schema::create('bom_components', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('bom_id');
            $t->unsignedBigInteger('component_product_id');
            $t->decimal('qty_required', 18, 4);            // 4-decimal precision (e.g. 0.5 liter solvent)
            $t->string('unit_of_measure', 20)->nullable();
            $t->decimal('unit_cost_snapshot', 18, 2)->nullable();  // snapshot cost saat BOM dibuat
            $t->text('notes')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['bom_id', 'component_product_id'], 'bom_comp_unique');
            $t->index(['company_id', 'component_product_id']); // reverse lookup: pakai di BOM mana?
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_components');
        Schema::dropIfExists('boms');
    }
};

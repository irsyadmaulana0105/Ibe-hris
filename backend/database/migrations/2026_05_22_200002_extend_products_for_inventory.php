<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend `products` jadi support 4 tipe:
 *   - finished_good: barang jadi yang dijual ke customer (e.g. Robot Arm RA-100)
 *   - raw_material : komponen mentah (motor, sensor, frame, kabel)
 *   - sub_assembly : komponen rakitan (gearbox sudah dirakit)
 *   - service      : non-stock item (jasa instalasi, training)
 *
 * Plus inventory tracking fields:
 *   - track_stock : true kalau di-track, false untuk service
 *   - unit_cost   : harga beli rata-rata (per-unit, weighted avg)
 *   - reorder_point: trigger order baru saat stock di bawah ini
 *   - safety_stock: minimal stock yang harus selalu ada
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->enum('product_type', ['finished_good', 'raw_material', 'sub_assembly', 'service'])
                ->default('finished_good')->after('category');
            $t->boolean('track_stock')->default(true)->after('product_type');
            $t->decimal('unit_cost', 18, 2)->nullable()->after('list_price');
            $t->unsignedInteger('reorder_point')->nullable()->after('unit_cost');
            $t->unsignedInteger('safety_stock')->nullable()->after('reorder_point');
            // Cached untuk fast lookup di list view (jumlah total across all warehouses)
            $t->decimal('total_stock_cached', 18, 2)->default(0)->after('safety_stock');
            $t->index(['company_id', 'product_type']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn([
                'product_type', 'track_stock', 'unit_cost',
                'reorder_point', 'safety_stock', 'total_stock_cached',
            ]);
        });
    }
};

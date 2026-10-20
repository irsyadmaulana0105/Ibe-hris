<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock per product per warehouse - current state.
 * Updated atomically via InventoryService::move (no direct mutation).
 *
 * qty_available = qty_on_hand - qty_reserved
 *   - on_hand: total fisik di gudang
 *   - reserved: sudah di-commit ke Work Order / Sales Order tapi belum out
 *   - available: yang bisa dipakai untuk allocation baru
 *
 * Soft delete tidak dipakai - stock row dihapus kalau qty=0 untuk waste-clean.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_stock', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->decimal('qty_on_hand', 18, 2)->default(0);
            $t->decimal('qty_reserved', 18, 2)->default(0);
            $t->decimal('qty_available', 18, 2)->storedAs('qty_on_hand - qty_reserved');
            $t->timestamp('last_movement_at')->nullable();
            $t->timestamps();
            $t->unique(['product_id', 'warehouse_id'], 'stock_unique_loc');
            $t->index(['company_id', 'warehouse_id']);
            $t->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock');
    }
};

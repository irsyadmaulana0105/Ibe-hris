<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add batch_id ke ShipmentItem - opsional override untuk FIFO/FEFO default.
 * Kalau set, ShipmentService consume akan paksa pakai batch ini (bukan
 * auto-pick). Berguna untuk:
 *   - Customer minta batch spesifik (mis. compliance / cert tertentu)
 *   - Reservation batch yang sudah dijanjikan
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_items', function (Blueprint $t) {
            $t->unsignedBigInteger('batch_id')->nullable()->after('product_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('shipment_items', function (Blueprint $t) {
            $t->dropColumn('batch_id');
        });
    }
};

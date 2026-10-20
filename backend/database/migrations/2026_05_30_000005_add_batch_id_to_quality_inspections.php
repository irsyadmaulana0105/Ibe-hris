<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah batch_id ke QualityInspection - kalau QC fail untuk batch tertentu,
 * auto-set inventory_batches.is_quarantined = true di service handler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_inspections', function (Blueprint $t) {
            $t->unsignedBigInteger('batch_id')->nullable()->after('product_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('quality_inspections', function (Blueprint $t) {
            $t->dropColumn('batch_id');
        });
    }
};

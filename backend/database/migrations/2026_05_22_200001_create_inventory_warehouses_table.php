<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lokasi fisik penyimpanan inventory. Bisa multi-warehouse (Gudang Surabaya,
 * Gudang Jakarta, Workshop Lantai 2, Customer Site, dll).
 *
 * Setiap stock movement WAJIB di-tag ke warehouse - supaya transfer
 * antar-lokasi ter-record dan stock per lokasi accurate.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_warehouses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('code', 32);              // unique per company, e.g. WH-SBY, WH-JKT
            $t->string('name', 150);
            $t->enum('type', ['main', 'workshop', 'consignment', 'in_transit', 'virtual'])->default('main');
            $t->text('address')->nullable();
            $t->string('city', 100)->nullable();
            $t->char('manager_id', 26)->nullable();   // ULID user_id
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'code']);
            $t->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_warehouses');
    }
};

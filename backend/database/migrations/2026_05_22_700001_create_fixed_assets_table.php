<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Asset Register - aset internal IBE (BUKAN produk yang dijual).
 * Beda dari Asset Registry (product_serial_numbers) yang track unit produk DIJUAL ke customer.
 *
 * Yang masuk Fixed Asset: laptop karyawan, mesin produksi, mobil dinas,
 * kursi & meja kantor, server, dll.
 *
 * Depreciation method:
 *   - straight_line: (cost - salvage) / useful_life_months per bulan
 *   - declining_balance: % per tahun (e.g. 25% reducing) - for future
 *
 * Status:
 *   - active (sedang dipakai)
 *   - in_repair (lagi diservice)
 *   - retired (sudah tidak dipakai, belum dijual)
 *   - disposed (sudah dijual/dibuang)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('asset_tag', 32);                  // FA-2026-0001 (unique per company)
            $t->string('name', 200);                      // "MacBook Pro 14 M3"
            $t->string('category', 100);                  // IT Equipment, Vehicle, Furniture, Machinery, dll
            $t->text('description')->nullable();
            $t->string('serial_number', 100)->nullable(); // manufacturer S/N (laptop, mesin)
            $t->date('acquisition_date');                 // tanggal beli
            $t->decimal('acquisition_cost', 18, 2);
            $t->string('currency', 3)->default('IDR');
            $t->decimal('exchange_rate', 18, 8)->default(1);
            $t->decimal('cost_in_base', 18, 2);            // IDR equivalent
            $t->decimal('salvage_value', 18, 2)->default(0); // nilai sisa setelah depreciate full
            $t->unsignedInteger('useful_life_months');    // umur ekonomis (dalam bulan)
            $t->enum('depreciation_method', ['straight_line', 'declining_balance', 'none'])
                ->default('straight_line');
            $t->decimal('accumulated_depreciation', 18, 2)->default(0);
            $t->decimal('book_value', 18, 2);              // cost - accumulated_depreciation
            $t->date('last_depreciation_date')->nullable(); // last time monthly depreciation di-post
            $t->enum('status', ['active', 'in_repair', 'retired', 'disposed'])->default('active');
            $t->string('location', 200)->nullable();      // "Kantor Surabaya", "Workshop Lt 2"
            $t->char('assigned_to_user_id', 26)->nullable(); // karyawan yang pegang (laptop, mobil)
            $t->ulid('supplier_id')->nullable();          // beli dari supplier mana
            $t->string('invoice_no', 50)->nullable();     // referensi invoice pembelian
            $t->date('disposal_date')->nullable();
            $t->decimal('disposal_value', 18, 2)->nullable();
            $t->string('disposal_method', 50)->nullable(); // sold, donated, scrapped, lost
            $t->text('notes')->nullable();
            $t->char('created_by', 26);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'asset_tag']);
            $t->index(['company_id', 'category']);
            $t->index(['company_id', 'status']);
            $t->index('assigned_to_user_id');
        });

        // History depresiasi per bulan (audit trail)
        Schema::create('fixed_asset_depreciations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('fixed_asset_id');
            $t->date('period');                  // bulan depreciation (YYYY-MM-01)
            $t->decimal('amount', 18, 2);        // depreciation amount untuk bulan ini
            $t->decimal('book_value_after', 18, 2);
            $t->char('posted_by', 26);
            $t->timestamp('posted_at')->useCurrent();
            $t->unique(['fixed_asset_id', 'period'], 'fa_dep_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
    }
};

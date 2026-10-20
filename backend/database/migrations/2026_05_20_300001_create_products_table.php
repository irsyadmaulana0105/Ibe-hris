<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products master - definisi produk yang IBE produksi/jual. Setiap product
 * punya kode unik per company, plus spec template fields (lihat
 * product_spec_fields) yang akan di-snapshot ke setiap unit serial number.
 *
 * Contoh kasus: "Robot Arm RA-100" sebagai master, setiap unit yang
 * dirakit dapet serial number dengan spec values (voltage, torque, dll)
 * yang specific per unit.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('code', 32);                    // RBT, ARM, AUTO, etc - unique per company
            $t->string('name', 200);
            $t->string('category', 100)->nullable();   // "Robot Arm", "Automated Cart", "Forklift", dll
            $t->string('model_number', 100)->nullable(); // 8FBCU25-COMP style
            $t->text('description')->nullable();
            $t->string('unit_of_measure', 20)->default('unit');
            $t->decimal('list_price', 18, 2)->nullable(); // harga jual default (IDR)
            $t->string('image_path', 255)->nullable();    // logo/foto produk
            $t->json('default_spec_values')->nullable();  // default values untuk spec_fields
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'code']);
            $t->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

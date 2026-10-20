<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec template fields - definisi field-field spek yang akan diisi per unit
 * serial number. Misal product Forklift Toyota:
 *   - MODEL (string)         → "8FBCU25-COMP"
 *   - VOLTAGE (number, V)    → 36
 *   - BATTERY_TYPE (string)  → "EO"
 *   - TRUCK_WEIGHT (number, kg) → 8990
 *   - CAPACITY (decimal, lb) → 2330
 *   - BACK_TILT (number, deg) → 5
 *
 * Pas operator produksi rakit unit baru → generate SN → isi spec_values
 * (JSON di product_serial_numbers.spec_values) sesuai template ini.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_spec_fields', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->string('field_key', 50);           // snake_case identifier (e.g. "voltage")
            $t->string('label', 100);              // display label (e.g. "VOLTAGE")
            $t->enum('field_type', ['text', 'number', 'decimal', 'date', 'boolean', 'select'])->default('text');
            $t->string('unit', 20)->nullable();    // "V", "kg", "mm", "lb", dll
            $t->json('options')->nullable();       // untuk type=select: ["EO", "EQ", "SO"]
            $t->boolean('is_required')->default(false);
            $t->boolean('show_on_nameplate')->default(true); // tampil di PDF nameplate
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('default_value', 200)->nullable();
            $t->timestamps();
            $t->unique(['product_id', 'field_key']);
            $t->index(['company_id', 'product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_spec_fields');
    }
};

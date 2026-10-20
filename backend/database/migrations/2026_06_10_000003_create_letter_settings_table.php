<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings KOP & company info per perusahaan - single row per company.
 * Editable lewat admin UI tanpa code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique();
            $t->string('logo_path', 255)->nullable();         // storage/letters/logo/...
            $t->string('company_name', 200)->nullable();      // "PT INTEGRASI BISNIS EKSEKUTIF"
            $t->string('brand_name', 100)->nullable();        // "IBE REALITY"
            $t->string('tagline', 200)->nullable();
            $t->string('address', 300)->nullable();
            $t->string('phone', 50)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('website', 150)->nullable();
            $t->string('kop_color', 10)->default('#0d9488'); // hex color band
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_settings');
    }
};

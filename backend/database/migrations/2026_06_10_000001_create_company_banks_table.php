<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master rekening bank perusahaan untuk Penawaran/Invoice.
 *
 * Multi-bank - user bisa CRUD beberapa rekening (Mandiri, BCA, BNI, dll)
 * dan pilih per surat. Default bank dipakai kalau letter tidak set bank_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_banks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('bank_name', 100);             // "Bank Mandiri"
            $t->string('account_number', 50);         // configured through admin
            $t->string('account_name', 200);          // "PT Integrasi Bisnis Eksekutif"
            $t->string('branch', 150)->nullable();    // "Surabaya - Tunjungan"
            $t->string('swift_code', 20)->nullable(); // untuk transfer LN
            $t->string('currency', 5)->default('IDR');
            $t->text('notes')->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('display_order')->default(100);
            $t->timestamps();

            $t->index(['company_id', 'is_active']);
        });

        // Add bank_id to letters (nullable - fallback ke default kalau null)
        Schema::table('letters', function (Blueprint $t) {
            $t->foreignId('bank_id')->nullable()->after('signer_id')->constrained('company_banks');
        });
    }

    public function down(): void
    {
        Schema::table('letters', function (Blueprint $t) {
            $t->dropForeign(['bank_id']);
            $t->dropColumn('bank_id');
        });
        Schema::dropIfExists('company_banks');
    }
};

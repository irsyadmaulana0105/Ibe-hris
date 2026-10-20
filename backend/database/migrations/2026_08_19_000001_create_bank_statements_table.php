<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening koran (bank statement) hasil cetak yang diunggah bagian keuangan.
 *
 * Berkasnya TIDAK disimpan di disk publik: dokumen ini memuat seluruh mutasi
 * rekening perusahaan, jadi hanya boleh diambil lewat endpoint terautentikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('bank_name', 100);
            $table->string('account_number', 50)->nullable();
            $table->date('period_from');
            $table->date('period_to');

            $table->string('file_path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');

            $table->text('notes')->nullable();
            $table->char('uploaded_by', 26)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'period_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};

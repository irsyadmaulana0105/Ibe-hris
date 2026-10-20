<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori surat (Penawaran, Pernyataan, Tugas, dll).
 *
 * Tiap kategori punya code pendek untuk format penomoran:
 *   001/<code>/IBE/<roman_month>/<year>
 *
 * Sequence reset per tahun. last_seq_year + last_seq_value disimpan di sini
 * supaya cepat tanpa hit ke letters table (yang bisa besar). Update via
 * LetterNumberService dengan row lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('code', 16);   // PENAW, PERN, TUGAS, BA, dll
            $t->string('name', 100);  // "Surat Penawaran", "Berita Acara", dll
            $t->text('description')->nullable();
            // Format placeholder support: {seq:03d}, {code}, {ROMAN_MONTH}, {month}, {year}
            $t->string('numbering_format', 200)->default('{seq:03d}/{code}/IBE/{ROMAN_MONTH}/{year}');
            $t->unsignedSmallInteger('last_seq_year')->default(0);
            $t->unsignedInteger('last_seq_value')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('display_order')->default(100);
            $t->timestamps();

            $t->unique(['company_id', 'code']);
            $t->index(['company_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_categories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add direction (in/out) ke letters.
 *
 * Surat KELUAR (out): default - surat yang kita kirim ke pihak luar
 * (Penawaran, Surat Tugas, dll). Full workflow DRAFT → SIGNED → SENT.
 *
 * Surat MASUK (in): surat dari mitra/klien/instansi. Skip workflow,
 * langsung tersimpan dengan PDF/scan upload + metadata (sender_name,
 * received_date). Untuk arsip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letters', function (Blueprint $t) {
            $t->enum('direction', ['out', 'in'])->default('out')->after('category_id');
            $t->string('sender_name', 200)->nullable()->after('recipient_email');     // untuk surat masuk
            $t->string('sender_company', 200)->nullable()->after('sender_name');
            $t->date('received_date')->nullable()->after('letter_date');              // tanggal terima surat masuk
            $t->string('original_number', 200)->nullable()->after('received_date');   // nomor surat dari pengirim
            $t->index(['company_id', 'direction', 'letter_date']);
        });
    }

    public function down(): void
    {
        Schema::table('letters', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'direction', 'letter_date']);
            $t->dropColumn(['direction', 'sender_name', 'sender_company', 'received_date', 'original_number']);
        });
    }
};

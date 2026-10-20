<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hari libur nasional + cuti bersama yang muncul sebagai tanggal merah
 * di kalender kehadiran. Scheduler H-1 mengirim broadcast email ke
 * semua karyawan untuk hari berikutnya yang ber-tipe libur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $t) {
            $t->id();
            $t->date('date')->index();
            $t->string('name', 200);
            $t->enum('type', ['nasional', 'cuti_bersama', 'perusahaan'])->default('nasional');
            $t->boolean('is_recurring')->default(false)->comment('TRUE = berulang setiap tahun (e.g. 17-Agt)');
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->dateTime('broadcast_sent_at')->nullable();
            $t->timestamps();
            $t->unique(['date', 'name', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah timeline ke `projects` agar Gantt bisa render project bar di atas
 * deretan task. Sebelumnya project tidak punya kolom tanggal sama sekali -
 * Gantt hanya menampilkan task.
 *
 * Kalau `start_date`/`end_date` NULL, frontend akan auto-derive dari
 * MIN/MAX tanggal task - jadi project lama tetap bekerja tanpa migrasi data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $t) {
            if (!Schema::hasColumn('projects', 'start_date')) {
                $t->date('start_date')->nullable();
            }
            if (!Schema::hasColumn('projects', 'end_date')) {
                $t->date('end_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $t) {
            $t->dropColumn(['start_date', 'end_date']);
        });
    }
};

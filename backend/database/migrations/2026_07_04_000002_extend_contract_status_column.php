<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `contracts.status` awalnya ENUM lama (pending_signature/signed).
 * New workflow butuh: draft, paraf_hrd, pending_karyawan, signed_karyawan,
 * pending_direktur, signed_direktur, pending_materai, archived, rejected.
 *
 * Ubah ke VARCHAR(32) - cukup panjang untuk semua state + fleksibel ke depan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('contracts', function (Blueprint $t) {
                $t->string('status', 32)->default('draft')->change();
            });
        } else {
            DB::statement("ALTER TABLE contracts MODIFY status VARCHAR(32) NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('contracts', function (Blueprint $t) {
                $t->enum('status', ['pending_signature', 'signed'])->default('pending_signature')->change();
            });
        } else {
            DB::statement("ALTER TABLE contracts MODIFY status ENUM('pending_signature','signed') NOT NULL DEFAULT 'pending_signature'");
        }
    }
};

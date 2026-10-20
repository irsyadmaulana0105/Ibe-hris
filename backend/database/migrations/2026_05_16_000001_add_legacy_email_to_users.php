<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setelah klaim email @ibereality.com, kolom users.email diisi alamat baru.
 * Tapi karyawan masih boleh login portal pakai email lama (Gmail) - kita
 * simpan di legacy_email. AuthController::login lookup matches keduanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('legacy_email')->nullable()->after('email')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('legacy_email');
        });
    }
};

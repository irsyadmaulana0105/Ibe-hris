<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah `npwp` ke companies + users. Format 15 digit (legacy) atau 16 digit
 * (NIK-based Coretax 2024+). Dipakai di kontrak kerja, slip gaji, SPT PPh21,
 * invoice perusahaan.
 *
 * Plus tambah ktp_number ke users (kalau berbeda dengan NIK karyawan internal -
 * users.nip dipakai sebagai NIP karyawan, sedangkan ktp_number adalah NIK KTP
 * resmi pemerintah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('npwp', 20)->nullable()->after('email');
            $t->string('nib', 20)->nullable()->after('npwp')->comment('NIB OSS dari BKPM');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('npwp', 20)->nullable()->after('nip');
            $t->string('ktp_number', 20)->nullable()->after('npwp')->comment('16 digit NIK KTP');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['npwp', 'nib']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['npwp', 'ktp_number']);
        });
    }
};

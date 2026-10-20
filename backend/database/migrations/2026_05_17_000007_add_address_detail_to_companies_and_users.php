<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah field alamat detail (provinsi/kab-kota/kecamatan/kelurahan/kode pos)
 * ke companies + users. Sebelumnya cuma ada `address` (text bebas) + `city`.
 *
 * Data hierarchical Indonesia di-fetch via emsifa/api-wilayah-indonesia
 * (sumber data Kemendagri). Disimpan sebagai string nama (bukan FK ke ID
 * pemerintah) supaya tahan ganti API source di masa depan.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['companies', 'users'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('province', 100)->nullable();
                $t->string('regency', 100)->nullable();   // kabupaten / kota
                $t->string('district', 100)->nullable();  // kecamatan
                $t->string('village', 100)->nullable();   // kelurahan / desa
                $t->string('postal_code', 10)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['companies', 'users'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['province', 'regency', 'district', 'village', 'postal_code']);
            });
        }
    }
};

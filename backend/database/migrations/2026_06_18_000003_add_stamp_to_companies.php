<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah stempel perusahaan (cap resmi) untuk dokumen formal.
 * Di Indonesia dokumen resmi biasanya butuh:
 *   1. Tanda tangan Direktur (sudah ada: director_signature_path)
 *   2. Stempel/cap perusahaan (bundling - signature + stamp) → column ini
 *
 * Di PDF template, stempel biasa di-overlay ATAU sebelah TTD. Kita
 * render side-by-side supaya keduanya visible clearly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            if (!Schema::hasColumn('companies', 'stamp_path')) {
                $t->string('stamp_path', 500)->nullable()->after('director_signature_path')
                    ->comment('Path stempel/cap perusahaan (PNG transparent) untuk dokumen resmi');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('stamp_path');
        });
    }
};

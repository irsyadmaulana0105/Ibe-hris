<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah 2 kolom untuk KOP surat profesional:
 *  - `website`: URL website perusahaan (tampil di KOP)
 *  - `letterhead_style`: pilihan format KOP
 *      'formal'  = Traditional formal (all-black, serif, cocok legal/gov)
 *      'modern'  = Modern korporat (nama pakai brand color, sans-serif, cocok B2B)
 *      default = 'modern'
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            if (!Schema::hasColumn('companies', 'website')) {
                $t->string('website', 200)->nullable()->after('email')
                    ->comment('URL website perusahaan, tampil di KOP surat');
            }
            if (!Schema::hasColumn('companies', 'letterhead_style')) {
                $t->enum('letterhead_style', ['formal', 'modern'])->default('modern')
                    ->after('director_name')
                    ->comment('formal=all-black serif | modern=teal accent sans-serif');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['website', 'letterhead_style']);
        });
    }
};

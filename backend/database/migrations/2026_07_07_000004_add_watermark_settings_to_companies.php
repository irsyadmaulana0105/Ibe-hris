<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watermark customization per company untuk PDF kontrak preview.
 * Default: "PREVIEW" merah transparan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('contract_watermark_text', 40)->default('PREVIEW')->after('letterhead_style');
            $t->string('contract_watermark_color', 20)->default('#ef4444')->after('contract_watermark_text');
            $t->unsignedTinyInteger('contract_watermark_opacity')->default(10)->after('contract_watermark_color'); // 0-100
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['contract_watermark_text', 'contract_watermark_color', 'contract_watermark_opacity']);
        });
    }
};

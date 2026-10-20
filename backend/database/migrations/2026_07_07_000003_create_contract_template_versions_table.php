<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot history untuk contract_templates.
 * Setiap kali template di-update, versi sebelumnya di-snapshot ke sini
 * supaya HR bisa lihat perubahan dan restore versi lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_template_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')->constrained('contract_templates')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('title');
            $t->longText('body_html');
            $t->string('source_type', 16); // text, docx
            $t->string('snapshot_by')->nullable(); // user_id
            $t->string('snapshot_reason', 200)->nullable(); // "update", "restore-from-v2"
            $t->timestamp('snapshot_at');
            $t->timestamps();

            $t->index(['template_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_template_versions');
    }
};

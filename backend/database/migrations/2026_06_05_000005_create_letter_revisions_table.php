<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi surat - track perubahan body & status untuk audit + diff.
 * Setiap save / state transition → 1 row baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('letter_id')->constrained('letters')->cascadeOnDelete();
            $t->unsignedSmallInteger('version');
            $t->string('status_before', 16)->nullable();
            $t->string('status_after', 16);
            $t->longText('body_html_snapshot')->nullable(); // not always - sometimes just status change
            $t->json('body_data_snapshot')->nullable();
            $t->foreignUlid('changed_by_id')->constrained('users');
            $t->text('change_note')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['letter_id', 'version']);
            $t->index(['letter_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_revisions');
    }
};

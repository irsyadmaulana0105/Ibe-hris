<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template surat - body content per kategori, support multi-version dan
 * multi-language. Author bisa pick template saat create surat, atau bikin
 * blank custom.
 *
 * body_html pakai placeholder syntax {{field.path}} yang di-resolve oleh
 * LetterTemplateService saat render. Available placeholders di-declare
 * di placeholders_schema (JSON) untuk UI field generator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('category_id')->constrained('letter_categories');
            $t->string('code', 50);
            $t->string('name', 150);
            $t->text('description')->nullable();
            $t->string('language', 5)->default('id'); // id, en
            $t->longText('body_html');
            $t->json('placeholders_schema')->nullable(); // [{key, label, type, required, default, options}]
            $t->json('default_values')->nullable();      // pre-fill umum
            $t->boolean('is_system')->default(false);    // built-in template (cannot delete)
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('version')->default(1);
            $t->unsignedSmallInteger('display_order')->default(100);
            $t->timestamps();

            $t->unique(['company_id', 'category_id', 'code', 'language']);
            $t->index(['category_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_templates');
    }
};

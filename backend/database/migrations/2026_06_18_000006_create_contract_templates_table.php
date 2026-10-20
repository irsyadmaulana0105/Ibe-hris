<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template kontrak kerja per employment_type - bisa di-edit oleh admin/superadmin
 * via WYSIWYG editor ATAU upload DOCX file.
 *
 * Content saved as HTML (rich text). Kalau user upload DOCX, backend parse ke HTML
 * dan simpan di body_html. Original DOCX di-preserve di source_docx_path untuk
 * re-download / re-edit di Word.
 *
 * Variable substitution pakai Mustache-like syntax: `{{nama}}`, `{{gaji_pokok}}`,
 * `{{tanggal_mulai}}`. Full list variable available di rendering time bergantung
 * konteks kontrak (karyawan yg di-issue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $t) {
            $t->id();
            // companies.id is BIGINT UNSIGNED (created via $table->id()).
            $t->unsignedBigInteger('company_id');
            $t->enum('employment_type', [
                'permanent', 'probation', 'training',
                'intern_campus', 'intern_hub', 'pkl_smk',
            ]);
            $t->string('title', 200)->comment('Judul kontrak - mis. "Perjanjian Kerja Waktu Tidak Tertentu"');
            $t->longText('body_html')->comment('Rich text HTML content - variable dgn syntax {{var_name}}');
            $t->enum('source_type', ['text', 'docx'])->default('text')
                ->comment('text = manual edit WYSIWYG | docx = uploaded DOCX (parsed to HTML)');
            $t->string('source_docx_path', 500)->nullable()->comment('Original DOCX path (kalau di-upload)');
            $t->json('variables')->nullable()->comment('List of {{vars}} yg detected di body');
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('version')->default(1);
            $t->char('created_by', 26)->nullable();
            $t->char('updated_by', 26)->nullable();
            $t->timestamps();

            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['company_id', 'employment_type']);
            $t->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};

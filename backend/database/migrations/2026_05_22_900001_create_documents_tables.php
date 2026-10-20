<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document Management System (DMS).
 *
 *   company_documents          - header (one logical document, e.g. "Kontrak Telkomsel 2026")
 *   document_versions  - versioned uploads (v1, v2, ...)
 *
 * Categories: contract, sop, certificate, policy, manual, invoice_scan, other.
 * Use cases:
 *   - Track kontrak customer dgn expiry alerts
 *   - SOP versioning (revisi quality control)
 *   - Sertifikat ISO/keagenan dgn expiry
 *   - Polis asuransi / lisensi software dgn renewal date
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('doc_code', 32)->unique();        // DOC-2026-0001
            $t->string('title', 250);
            $t->string('category', 32)->index();          // contract|sop|certificate|policy|manual|invoice_scan|other
            $t->text('description')->nullable();
            $t->string('owner_dept', 100)->nullable();
            $t->ulid('owner_user_id')->nullable()->index();
            $t->json('tags')->nullable();                 // ['ISO', 'ITE-Surabaya']
            $t->string('current_version', 16)->default('v1');
            $t->date('effective_date')->nullable();
            $t->date('expiry_date')->nullable()->index(); // for renewal alerts
            $t->integer('alert_days_before')->default(30);// alert N days before expiry
            $t->string('status', 16)->default('active');  // active|expired|archived|superseded
            $t->ulid('created_by')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['company_id', 'category', 'status']);
        });

        Schema::create('document_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->index();
            $t->string('version_label', 16);              // v1, v2, v2.1
            $t->string('file_path', 500);                 // storage path
            $t->string('original_filename', 250);
            $t->string('mime_type', 100)->nullable();
            $t->unsignedBigInteger('file_size_bytes')->default(0);
            $t->string('file_hash_sha256', 64)->nullable()->index();
            $t->text('change_notes')->nullable();
            $t->boolean('is_current')->default(false);
            $t->ulid('uploaded_by')->nullable();
            $t->timestamp('uploaded_at')->useCurrent();
            $t->unique(['document_id', 'version_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('company_documents');
    }
};

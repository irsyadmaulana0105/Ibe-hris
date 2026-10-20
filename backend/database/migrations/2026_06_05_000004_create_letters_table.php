<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master surat - 1 row per surat fisik yang di-generate.
 *
 * Lifecycle state machine:
 *   DRAFT       - author baru bikin, belum ada nomor
 *   REVIEW      - submit ke sekretaris untuk koreksi
 *   APPROVED    - sekretaris approve, sistem auto-gen nomor (atomic)
 *   SIGNED      - signer apply TTD, sistem render PDF final
 *   SENT        - surat sudah dikirim ke recipient
 *   ARCHIVED    - masuk arsip permanen
 *   CANCELLED   - dibatalkan (audit dipertahankan)
 *
 * Surat yang SIGNED tidak bisa di-edit. Koreksi = bikin revisi baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letters', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();  // exposed di URL
            $t->foreignId('company_id')->index();
            $t->foreignId('category_id')->constrained('letter_categories');
            $t->foreignId('template_id')->nullable()->constrained('letter_templates');

            // Number - generated saat APPROVED (atomic via LetterNumberService)
            $t->string('letter_number', 200)->nullable();
            $t->unsignedInteger('letter_number_seq')->nullable();
            $t->unsignedSmallInteger('letter_number_year')->nullable();

            // Content
            $t->string('subject', 300);
            $t->string('purpose', 200)->nullable(); // "Talkshow Bisnis" untuk SOP format
            $t->string('target_code', 4)->nullable(); // A=Internal, B=Eksternal (SOP format)
            $t->longText('body_html');
            $t->json('body_data')->nullable(); // template variables values
            $t->json('attachments_meta')->nullable(); // [{name, size, path}]

            // Recipient
            $t->string('recipient_name', 200)->nullable();
            $t->string('recipient_title', 200)->nullable();
            $t->string('recipient_company', 200)->nullable();
            $t->text('recipient_address')->nullable();
            $t->string('recipient_email', 150)->nullable();

            // Dates
            $t->date('letter_date');           // tanggal yang tercetak di surat
            $t->date('expiry_date')->nullable(); // untuk Penawaran

            // Author + workflow actors - users pakai ULID
            $t->foreignUlid('author_id')->constrained('users');
            $t->foreignUlid('reviewer_id')->nullable()->constrained('users');
            $t->foreignUlid('approver_id')->nullable()->constrained('users');
            $t->foreignId('signer_id')->nullable()->constrained('letter_signatures');
            $t->foreignId('cosigner_id')->nullable()->constrained('letter_signatures'); // BAST, MoU, dll

            // Workflow
            $t->enum('status', ['draft', 'review', 'approved', 'signed', 'sent', 'archived', 'cancelled'])
              ->default('draft');
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('archived_at')->nullable();

            // Output
            $t->string('pdf_path', 255)->nullable(); // storage/letters/{year}/{filename}.pdf
            $t->string('pdf_checksum', 64)->nullable(); // SHA-256

            $t->text('internal_notes')->nullable();
            $t->boolean('confidential')->default(false);
            $t->json('tags')->nullable(); // search/filter aid

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'status', 'letter_date']);
            $t->index(['company_id', 'category_id', 'letter_date']);
            $t->index(['letter_number']);
            $t->index(['recipient_company']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letters');
    }
};

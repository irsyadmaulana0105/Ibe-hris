<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend letters dengan kolom-kolom feature baru + bikin letter_attachments
 * (multi-file lampiran), letter_dispositions (untuk surat masuk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letters', function (Blueprint $t) {
            // Tembusan (CC) - array of strings (nama + jabatan + 'Arsip', dll)
            $t->json('cc_list')->nullable()->after('recipient_email');
            // Surat balasan link - point ke parent surat masuk
            $t->foreignId('parent_letter_id')->nullable()->after('template_id')->constrained('letters');
            // QR code token untuk verifikasi publik (UUID, public-safe)
            $t->string('qr_token', 36)->nullable()->unique();
            // FULLTEXT index untuk search body content
            // (MySQL/MariaDB InnoDB support FULLTEXT)
        });
        // FULLTEXT terpisah agar bisa skip kalau gagal di MyISAM/SQLite
        try {
            \DB::statement('ALTER TABLE letters ADD FULLTEXT INDEX letters_body_ft (body_html, subject, recipient_company, sender_company)');
        } catch (\Throwable $e) {
            // skip - search akan fallback ke LIKE
        }

        Schema::create('letter_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('letter_id')->constrained('letters')->cascadeOnDelete();
            $t->string('original_name', 255);
            $t->string('path', 255);                    // storage/letters/att/{id}/{filename}
            $t->string('mime_type', 100)->nullable();
            $t->unsignedInteger('size_bytes')->default(0);
            $t->string('checksum', 64)->nullable();
            $t->foreignUlid('uploaded_by_id')->nullable()->constrained('users');
            $t->timestamp('created_at')->useCurrent();

            $t->index('letter_id');
        });

        Schema::create('letter_dispositions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('letter_id')->constrained('letters')->cascadeOnDelete();
            $t->foreignUlid('from_user_id')->constrained('users');
            $t->foreignUlid('to_user_id')->constrained('users');
            $t->string('action_type', 50);   // tindak_lanjut, untuk_perhatian, untuk_diketahui, dll
            $t->text('instruction')->nullable();
            $t->date('deadline')->nullable();
            $t->boolean('completed')->default(false);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();

            $t->index(['letter_id', 'to_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_dispositions');
        Schema::dropIfExists('letter_attachments');
        Schema::table('letters', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_letter_id');
            $t->dropColumn(['cc_list', 'qr_token']);
        });
        try { \DB::statement('ALTER TABLE letters DROP INDEX letters_body_ft'); } catch (\Throwable) {}
    }
};

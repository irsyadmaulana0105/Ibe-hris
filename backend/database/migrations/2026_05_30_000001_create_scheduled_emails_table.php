<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side scheduled email queue.
 *
 * Stalwart 1.0.6 belum support JMAP EmailSubmission `sendAt`. Sebagai
 * workaround, webmail enqueue jadwal ke tabel ini; artisan command
 * `mail:flush-scheduled` jalan tiap menit lewat Laravel scheduler dan
 * kirim email via JMAP pakai mailbox creds user yang sudah ada.
 *
 * Payload disimpan encrypted karena kemungkinan ada attachment ID dan
 * konten body - sensitive. Decrypt on-the-fly saat flush.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_emails', function (Blueprint $t) {
            $t->id();
            $t->ulid('user_id')->index();
            $t->string('mailbox_username', 200)->index();
            $t->timestamp('scheduled_at')->index();
            $t->text('payload_encrypted'); // JSON terenkripsi
            $t->string('status', 20)->default('pending')->index();
            // pending → processing → sent | failed | canceled
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();

            $t->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_emails');
    }
};

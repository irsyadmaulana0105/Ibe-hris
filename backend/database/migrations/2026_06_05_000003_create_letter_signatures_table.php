<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Library tanda tangan + stempel.
 *
 * Tiap user yang berwenang punya 1 entry: image TTD transparan PNG + image
 * stempel transparan PNG. Saat sign surat, sistem overlay 2 image ini ke
 * sign-block dengan posisi konsisten.
 *
 * is_default per kategori - sistem auto-pick signer ini saat create surat
 * kategori yang sesuai (bisa di-override).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_signatures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignUlid('user_id')->nullable()->constrained('users');
            $t->string('name', 150);
            $t->string('title', 150);       // "Direktur Utama", "HR Manager"
            $t->string('email', 150)->nullable();
            $t->string('signature_path', 255)->nullable(); // storage/letters/sig/...
            $t->string('stamp_path', 255)->nullable();
            $t->json('default_for_categories')->nullable(); // [category_id, ...] - auto-pick
            $t->boolean('is_active')->default(true);
            $t->boolean('is_company_default')->default(false);
            $t->timestamps();

            $t->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_signatures');
    }
};

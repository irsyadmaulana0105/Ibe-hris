<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrak kerja karyawan. Saat admin set employment_status menjadi
 * "permanent", sebuah record kontrak diterbitkan otomatis (status=pending_signature).
 * Karyawan tanda tangan via canvas → signature image disimpan, PDF final
 * dengan tanda tangan ter-embed digenerate dan link tersedia di profil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->char('user_id', 26);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('contract_number', 50)->unique();
            $t->date('issued_at');
            $t->date('start_date');
            $t->enum('status', ['pending_signature', 'signed', 'voided'])->default('pending_signature');
            $t->json('placeholders');                       // snapshot of name, address, nip, salary, etc
            $t->string('signature_path')->nullable();       // employee signature PNG
            $t->string('signed_pdf_path')->nullable();      // final PDF after signing
            $t->dateTime('signed_at')->nullable();
            $t->string('signed_ip', 45)->nullable();
            $t->char('issued_by', 26)->nullable();          // admin user_id
            $t->timestamps();
            $t->index(['status', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};

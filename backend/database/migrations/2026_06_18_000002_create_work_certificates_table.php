<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surat Keterangan Kerja - dokumen resmi yang menyatakan karyawan bekerja
 * di perusahaan. Ditandatangani Direktur Utama. Karyawan bisa self-issue
 * (butuh untuk buka rekening, apply visa, dll). Track issued via table ini
 * untuk audit + nomor surat unik.
 *
 * Alur:
 *   - Karyawan klik "Terbitkan" di portal → POST /api/v1/me/work-certificate
 *   - Backend generate nomor unik + render PDF via blade template
 *   - Simpan record + return PDF stream
 *   - Karyawan bisa download lagi kalau file masih valid
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tambah kolom tanda tangan Direktur di companies (belum ada - cuma
        // finance_signature + hrd_signature)
        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'director_signature_path')) {
                $t->string('director_signature_path', 500)->nullable()->after('hrd_signature_path');
            }
            if (! Schema::hasColumn('companies', 'director_name')) {
                $t->string('director_name', 150)->nullable()->after('director_signature_path')
                    ->comment('Nama Direktur Utama yang tanda tangan surat resmi');
            }
        });

        // Sama di users (fallback kalau Company gak set - pakai user Direktur langsung)
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'director_signature_path')) {
                $t->string('director_signature_path', 500)->nullable()->after('hrd_signature_path');
            }
        });

        Schema::create('work_certificates', function (Blueprint $t) {
            $t->id();
            // companies.id is BIGINT UNSIGNED (created via $table->id()).
            // Keep the FK type identical so fresh MySQL migrations do not fail.
            $t->unsignedBigInteger('company_id');
            $t->char('user_id', 26);
            $t->string('cert_number', 50)->unique()->comment('Format: SKK/{YYYY}/{MM}/{SEQ}');
            $t->text('purpose')->nullable()->comment('Tujuan penerbitan (opsional): buka rekening, apply visa, dll');
            $t->date('issued_date');
            $t->char('issued_by', 26)->nullable()->comment('User ID admin yang issue (null = self-issued)');
            $t->string('signer_name', 150)->comment('Nama Direktur saat surat diterbitkan');
            $t->string('signer_title', 100)->default('Direktur Utama');
            $t->string('pdf_path', 500)->nullable()->comment('Path storage kalau di-cache');
            $t->string('verification_hash', 64)->unique()->comment('SHA256 untuk QR verify - /verify/skk/{hash}');
            $t->timestamps();

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->index(['user_id', 'issued_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_certificates');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('director_signature_path');
        });
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['director_signature_path', 'director_name']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend contracts table untuk multi-signer workflow:
 *   draft → paraf_hrd → pending_karyawan → signed_karyawan
 *   → pending_direktur → signed_direktur → pending_materai → archived
 *
 * Existing states 'pending_signature' dan 'signed' dari flow lama tetap
 * kompatibel - service layer petakan ke state baru saat dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            // Template yang dipakai (link ke contract_templates)
            $t->foreignId('template_id')->nullable()->after('contract_type');

            // HRD paraf (approval awal setelah generate)
            $t->string('hrd_paraf_by')->nullable()->after('placeholders');
            $t->timestamp('hrd_paraf_at')->nullable()->after('hrd_paraf_by');
            $t->string('hrd_paraf_signature_path')->nullable()->after('hrd_paraf_at');
            $t->text('hrd_paraf_notes')->nullable()->after('hrd_paraf_signature_path');

            // Karyawan sign (extend existing signature_path/signed_at)
            $t->string('karyawan_signature_path')->nullable()->after('signature_path');
            $t->timestamp('karyawan_signed_at')->nullable()->after('signed_at');
            $t->string('karyawan_signed_ip')->nullable()->after('karyawan_signed_at');

            // Direktur sign
            $t->string('direktur_signature_path')->nullable()->after('karyawan_signed_ip');
            $t->string('direktur_signed_by')->nullable()->after('direktur_signature_path');
            $t->timestamp('direktur_signed_at')->nullable()->after('direktur_signed_by');

            // E-materai manual upload
            $t->string('materai_pdf_path')->nullable()->after('signed_pdf_path');
            $t->timestamp('materai_uploaded_at')->nullable()->after('materai_pdf_path');
            $t->string('materai_uploaded_by')->nullable()->after('materai_uploaded_at');

            // Rejection tracking
            $t->text('rejection_reason')->nullable()->after('materai_uploaded_by');
            $t->string('rejected_by')->nullable()->after('rejection_reason');
            $t->timestamp('rejected_at')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->dropColumn([
                'template_id',
                'hrd_paraf_by', 'hrd_paraf_at', 'hrd_paraf_signature_path', 'hrd_paraf_notes',
                'karyawan_signature_path', 'karyawan_signed_at', 'karyawan_signed_ip',
                'direktur_signature_path', 'direktur_signed_by', 'direktur_signed_at',
                'materai_pdf_path', 'materai_uploaded_at', 'materai_uploaded_by',
                'rejection_reason', 'rejected_by', 'rejected_at',
            ]);
        });
    }
};

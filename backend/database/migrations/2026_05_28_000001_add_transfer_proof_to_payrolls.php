<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah bukti transfer untuk payroll.
 *
 * Workflow update:
 *   draft → submitted → approved → paid (paid HANYA setelah Finance upload bukti)
 *
 * Karyawan baru bisa lihat slip gaji setelah transfer_proof_path terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $t) {
            if (!Schema::hasColumn('payrolls', 'transfer_proof_path')) {
                $t->string('transfer_proof_path', 500)->nullable()->after('status');
                $t->timestamp('transfer_proof_uploaded_at')->nullable()->after('transfer_proof_path');
                $t->ulid('transfer_proof_uploaded_by')->nullable()->after('transfer_proof_uploaded_at');
                $t->string('transfer_reference', 200)->nullable()->after('transfer_proof_uploaded_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $t) {
            foreach (['transfer_proof_path','transfer_proof_uploaded_at','transfer_proof_uploaded_by','transfer_reference'] as $c) {
                if (Schema::hasColumn('payrolls', $c)) $t->dropColumn($c);
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resign / termination workflow:
 *   Admin tandai user → `resigned_at` di-set + `employment_status` jadi
 *   'resigned' atau 'terminated'. Token Sanctum + refresh token user
 *   otomatis di-revoke. Data lama (attendance, payroll, leave, dll)
 *   TETAP ada (FK cascadeOnDelete tidak dipicu - user record tidak dihapus).
 *
 *   Akses sistem ditolak via BlockInactiveUser middleware → kalau token
 *   bocor pun, request user resigned tetap 403.
 *
 *   Reactivation: admin bisa undo via /users/{id}/reactivate, reset
 *   resigned_at jadi NULL dan set status kembali ke 'permanent'/'probation'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Extend ENUM employment_status agar mendukung 'resigned' & 'terminated'
        // MySQL doesn't support ALTER ENUM clean - pakai raw ALTER COLUMN
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE users
                MODIFY COLUMN employment_status
                ENUM('permanent','probation','intern_campus','intern_hub','pkl_smk','resigned','terminated')
                NOT NULL DEFAULT 'permanent'
            ");
        }

        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'resigned_at')) {
                // Effective date - kapan user tidak lagi karyawan
                $t->date('resigned_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'last_working_date')) {
                // Tanggal kerja terakhir - biasanya = resigned_at - 1 day untuk
                // resign normal; bisa lebih awal kalau garden leave / suspended
                $t->date('last_working_date')->nullable();
            }
            if (!Schema::hasColumn('users', 'resignation_type')) {
                // 'resign' = user mengundurkan diri sukarela
                // 'terminated' = perusahaan memberhentikan (kontrak habis, PHK)
                // 'retired' = pensiun normal
                $t->enum('resignation_type', ['resign', 'terminated', 'retired'])->nullable();
            }
            if (!Schema::hasColumn('users', 'resignation_reason')) {
                $t->text('resignation_reason')->nullable();
            }
            if (!Schema::hasColumn('users', 'resigned_by')) {
                $t->char('resigned_by', 26)->nullable();
                $t->foreign('resigned_by')->references('id')->on('users')->nullOnDelete();
            }
            // Index untuk filter list "active employees" - query dominan
            $t->index(['employment_status', 'resigned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['resigned_by']);
            $t->dropIndex(['employment_status', 'resigned_at']);
            $t->dropColumn([
                'resigned_at', 'last_working_date',
                'resignation_type', 'resignation_reason', 'resigned_by',
            ]);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE users
                MODIFY COLUMN employment_status
                ENUM('permanent','probation','intern_campus','intern_hub','pkl_smk')
                NOT NULL DEFAULT 'permanent'
            ");
        }
    }
};

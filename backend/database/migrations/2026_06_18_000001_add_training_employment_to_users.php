<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah employment_status = 'training' + field daily-rate untuk karyawan
 * fase Training (gaji harian, bukan bulanan).
 *
 * Logic:
 *   - status = 'training' → payroll pakai `daily_rate × hari_hadir` (bukan
 *     salary_monthly). Late deduction tetap apply sesuai late_deduction_tiers.
 *   - `training_ends_at` = batas hari training (HR set saat hire). Default
 *     auto-calculate `training_started_at + 30 hari` kalau gak diisi.
 *   - Saat tanggal hari ini > training_ends_at AND status masih 'training'
 *     AND review_status='active', daily scheduled command flip review_status
 *     ke 'pending_review' + block login (lewat BlockInactiveUser middleware).
 *   - HR action: promote ke probation/permanent, extend training, atau
 *     terminate (status='terminated').
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Extend employment_status enum to include 'training'
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY COLUMN employment_status ENUM(
                'permanent','probation','intern_campus','intern_hub','pkl_smk',
                'training','resigned','terminated'
            ) NULL");
        }

        Schema::table('users', function (Blueprint $t) {
            // Daily rate untuk training (NULL kalau bukan training)
            if (!Schema::hasColumn('users', 'daily_rate')) {
                $t->unsignedInteger('daily_rate')->nullable()->after('salary_monthly')
                    ->comment('Gaji per hari hadir - dipakai kalau employment_status=training');
            }
            // Training period tracking
            if (!Schema::hasColumn('users', 'training_started_at')) {
                $t->date('training_started_at')->nullable()->after('daily_rate');
            }
            if (!Schema::hasColumn('users', 'training_ends_at')) {
                $t->date('training_ends_at')->nullable()->after('training_started_at');
            }
            // Review status - saat training berakhir, HR perlu decide
            if (!Schema::hasColumn('users', 'training_review_status')) {
                $t->enum('training_review_status', ['active', 'pending_review', 'reviewed'])
                    ->nullable()->after('training_ends_at')
                    ->comment('active: masih dalam periode | pending_review: butuh HR action | reviewed: done');
            }
            // Index untuk scheduled command query (find expired trainings)
            $t->index(['employment_status', 'training_ends_at'], 'users_training_expiry_idx');
        });

        // Payrolls - snapshot training context per period (data immutable
        // setelah generated, supaya histori slip tetap akurat meski user
        // promoted ke permanent setelahnya).
        Schema::table('payrolls', function (Blueprint $t) {
            if (!Schema::hasColumn('payrolls', 'payroll_basis')) {
                $t->enum('payroll_basis', ['monthly', 'daily'])->default('monthly')->after('base_salary');
            }
            if (!Schema::hasColumn('payrolls', 'daily_rate_snapshot')) {
                $t->unsignedInteger('daily_rate_snapshot')->nullable()->after('payroll_basis');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $t) {
            $t->dropColumn(['payroll_basis', 'daily_rate_snapshot']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex('users_training_expiry_idx');
            $t->dropColumn(['daily_rate', 'training_started_at', 'training_ends_at', 'training_review_status']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY COLUMN employment_status ENUM(
                'permanent','probation','intern_campus','intern_hub','pkl_smk',
                'resigned','terminated'
            ) NULL");
        }
    }
};

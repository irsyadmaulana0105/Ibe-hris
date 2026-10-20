<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema potongan terlambat bertingkat (tier menit) + per-shift grace period
 * + per-attendance late_minutes tracking.
 *
 * Logic:
 *  - Setiap shift punya grace_period_minutes sendiri (default 15)
 *  - Attendance simpan late_minutes (menit telat sejak shift_start, BUKAN
 *    sejak grace period berakhir - agar tier lookup match expectation user)
 *  - Tier evaluation: cari row tertinggi yang min_minutes <= late_minutes,
 *    max_minutes NULL (unbounded) atau >= late_minutes
 *  - Per-user tier (user_id != null) override company-default tier
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Grace period per shift (default 15 menit kalau NULL)
        Schema::table('shifts', function (Blueprint $t) {
            if (!Schema::hasColumn('shifts', 'grace_period_minutes')) {
                $t->unsignedSmallInteger('grace_period_minutes')->default(15)->after('end_time');
            }
        });

        // 2) Track menit terlambat per attendance (untuk tier lookup di payroll)
        Schema::table('attendances', function (Blueprint $t) {
            if (!Schema::hasColumn('attendances', 'late_minutes')) {
                $t->unsignedSmallInteger('late_minutes')->default(0)->after('status');
            }
        });

        // 3) Tier potongan terlambat - company-default + per-user override
        Schema::create('late_deduction_tiers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->char('user_id', 26)->nullable()->comment('NULL = default untuk seluruh company');
            $t->unsignedSmallInteger('min_minutes')->comment('Inclusive. Mis: 15');
            $t->unsignedSmallInteger('max_minutes')->nullable()->comment('Exclusive. NULL = no upper bound');
            $t->unsignedBigInteger('deduction_amount')->default(0)->comment('Rupiah per kejadian dalam range ini');
            $t->string('label')->nullable()->comment('Mis: "Telat ringan", "Telat berat"');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index(['company_id', 'user_id']);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_deduction_tiers');
        Schema::table('attendances', fn (Blueprint $t) => $t->dropColumn('late_minutes'));
        Schema::table('shifts', fn (Blueprint $t) => $t->dropColumn('grace_period_minutes'));
    }
};

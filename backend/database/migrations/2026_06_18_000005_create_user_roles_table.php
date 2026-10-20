<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-role - user bisa punya banyak roles simultan.
 *
 * Existing `users.group` tetap dipertahankan sebagai PRIMARY role (backward compat +
 * default active role saat login). Table baru `user_roles` = additional roles yang
 * superadmin grant per-akun.
 *
 * Contoh: Aji primary=admin (HR) + tambahan role superadmin.
 * Waktu login, default active role = admin (dari primary).
 * User bisa switch ke superadmin via UI dropdown.
 *
 * PENTING: Ini UI-only filter. Backend capability check tetap pakai actual
 * permissions user (real capabilities di UserCapability + policies).
 * activeRole cuma decide MENU + PAGE VISIBILITY di frontend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $t) {
            $t->id();
            $t->char('user_id', 26);
            $t->enum('role', ['admin', 'superadmin', 'finance', 'produksi'])
                ->comment('Role tambahan - user, primary tetap di users.group');
            $t->boolean('is_primary')->default(false)
                ->comment('True = mirror dari users.group (auto-created), False = additional');
            $t->char('granted_by', 26)->nullable()
                ->comment('User ID superadmin yang assign role ini');
            $t->text('notes')->nullable()->comment('Alasan grant (audit trail)');
            $t->timestamp('granted_at')->nullable();
            $t->timestamps();

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('granted_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['user_id', 'role']);
            $t->index(['user_id', 'is_primary']);
        });

        // Seed: mirror users.group ke user_roles sebagai is_primary=true.
        // Skip user biasa (group=user) karena mereka bukan role admin-level.
        DB::table('users')
            ->whereIn('group', ['admin', 'superadmin', 'finance', 'produksi'])
            ->select('id', 'group')
            ->orderBy('id')
            ->chunk(100, function ($users) {
                $rows = $users->map(fn ($u) => [
                    'user_id'    => $u->id,
                    'role'       => $u->group,
                    'is_primary' => true,
                    'granted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();
                DB::table('user_roles')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};

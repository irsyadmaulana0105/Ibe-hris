<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend shared_mailboxes: tambah `allowed_user_emails` (JSON array email)
 * untuk grant access per-user spesifik (override allowed_groups).
 *
 * Use case: admin@ibereality.com shared inbox harus bisa diakses oleh
 * patrika, aldy, revaldy, dan aji - meski mereka bukan superadmin.
 *
 * Access check di SharedMailboxService:
 *   allowed kalau:
 *     - user.group masuk allowed_groups, OR
 *     - user.email masuk allowed_user_emails (canonical OR legacy)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_mailboxes', function (Blueprint $t) {
            if (!Schema::hasColumn('shared_mailboxes', 'allowed_user_emails')) {
                $t->json('allowed_user_emails')->nullable()->after('allowed_groups');
            }
        });

        // Grant admin@ibereality.com access ke 4 user yang ditentukan
        // (patrika, aldy, revaldy, aji). Email pakai @ibereality.com canonical
        // - user yang sudah claim mailbox-nya pakai address ini.
        $allowedEmails = [
            'patrika@ibereality.com',
            'aldy@ibereality.com',
            'revaldy@ibereality.com',
            'aji@ibereality.com',
        ];

        DB::table('shared_mailboxes')
            ->where('email', 'admin@ibereality.com')
            ->update([
                'allowed_user_emails' => json_encode($allowedEmails),
                'updated_at'          => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('shared_mailboxes', function (Blueprint $t) {
            $t->dropColumn('allowed_user_emails');
        });
    }
};

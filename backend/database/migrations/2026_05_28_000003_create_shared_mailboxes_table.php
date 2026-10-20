<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared service mailboxes - akun email bersama yang dipakai untuk
 * komunikasi dengan partner/vendor (mis. admin@ibereality.com untuk
 * terima data dari perusahaan lain).
 *
 * Superadmin (atau group yang di-set di allowed_groups) bisa "switch"
 * login ke mailbox bersama ini tanpa share password manual.
 *
 * Password disimpan encrypted via Crypt::encryptString - sama pattern
 * dengan users.mailbox_password_encrypted (per-user mailbox).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_mailboxes', function (Blueprint $t) {
            $t->id();
            $t->string('email', 200)->unique();
            $t->string('display_name', 150);
            $t->text('description')->nullable();
            $t->text('password_encrypted');
            $t->json('allowed_groups')->nullable();          // e.g. ["superadmin"]
            $t->timestamp('last_switched_at')->nullable();
            $t->ulid('last_switched_by')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Reuse webmail_sso_tickets dengan nullable FK ke shared_mailbox_id.
        // Kalau set, ticket untuk shared mailbox; kalau null, ticket personal.
        if (Schema::hasTable('webmail_sso_tickets') && !Schema::hasColumn('webmail_sso_tickets', 'shared_mailbox_id')) {
            Schema::table('webmail_sso_tickets', function (Blueprint $t) {
                $t->unsignedBigInteger('shared_mailbox_id')->nullable()->after('user_id');
                $t->index('shared_mailbox_id');
            });
        }

        // Seed admin@ibereality.com - password supplied by superadmin via secure channel
        // (di-set saat migration jalan, langsung Crypt::encryptString). Idempotent - pakai
        // updateOrInsert biar gak duplikat kalau migration di-rerun.
        $seedPassword = env('SHARED_MAILBOX_ADMIN_PASSWORD');
        if ($seedPassword) {
            DB::table('shared_mailboxes')->updateOrInsert(
                ['email' => 'admin@ibereality.com'],
                [
                    'display_name'       => 'Admin IBE Reality (Shared)',
                    'description'        => 'Inbox bersama untuk komunikasi partner/vendor dan registrasi data perusahaan lain.',
                    'password_encrypted' => Crypt::encryptString($seedPassword),
                    'allowed_groups'     => json_encode(['superadmin']),
                    'is_active'          => true,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('webmail_sso_tickets') && Schema::hasColumn('webmail_sso_tickets', 'shared_mailbox_id')) {
            Schema::table('webmail_sso_tickets', function (Blueprint $t) {
                $t->dropIndex(['shared_mailbox_id']);
                $t->dropColumn('shared_mailbox_id');
            });
        }
        Schema::dropIfExists('shared_mailboxes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bugfix: kolom FK ke `users.id` di tabel CRM ditulis sebagai unsignedBigInteger
 * tapi `users` pakai ULID (string 26 char). Insert ULID ke kolom int kena
 * MySQL strict truncation error "Data truncated for column ...". Fix dengan
 * ubah tipe kolom ke CHAR(26).
 *
 * Aman dijalankan: tabel-tabel ini baru dibuat dan belum berisi data (migrasi
 * 2026_05_20_200xxx kemarin sore), jadi konversi kolom int → char(26) tidak
 * merusak data.
 */
return new class extends Migration {
    public function up(): void
    {
        // Pakai raw SQL biar bisa ubah tipe + drop index lama yang nyangkut tipe int
        // dalam satu transaksi (Laravel's Schema::change doctrine kadang miss).
        $changes = [
            'crm_companies'  => ['owner_id'],
            'crm_contacts'   => ['owner_id'],
            'crm_leads'      => ['owner_id'],
            'crm_deals'      => ['owner_id'],
            'crm_activities' => ['user_id'],
            'crm_tasks'      => ['assignee_id'],
        ];

        if (DB::getDriverName() === 'sqlite') {
            foreach ($changes as $table => $cols) {
                foreach ($cols as $col) {
                    Schema::table($table, function (Blueprint $t) use ($col) {
                        $t->char($col, 26)->nullable()->change();
                    });
                }
            }
        } else {
            foreach ($changes as $table => $cols) {
                foreach ($cols as $col) {
                    DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `{$col}` CHAR(26) NULL");
                }
            }
        }
    }

    public function down(): void
    {
        $changes = [
            'crm_companies'  => ['owner_id'],
            'crm_contacts'   => ['owner_id'],
            'crm_leads'      => ['owner_id'],
            'crm_deals'      => ['owner_id'],
            'crm_activities' => ['user_id'],
            'crm_tasks'      => ['assignee_id'],
        ];
        if (DB::getDriverName() === 'sqlite') {
            foreach ($changes as $table => $cols) {
                foreach ($cols as $col) {
                    Schema::table($table, function (Blueprint $t) use ($col) {
                        $t->unsignedBigInteger($col)->nullable()->change();
                    });
                }
            }
        } else {
            foreach ($changes as $table => $cols) {
                foreach ($cols as $col) {
                    DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `{$col}` BIGINT UNSIGNED NULL");
                }
            }
        }
    }
};

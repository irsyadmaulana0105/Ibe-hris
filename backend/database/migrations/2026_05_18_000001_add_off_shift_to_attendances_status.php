<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'off_shift' value to attendances.status ENUM. Karyawan yang clock-in
 * di luar window shift (mis. salah shift, datang lebih dari 60 menit sebelum
 * mulai, atau setelah 60 menit shift selesai) mendapat status ini sebagai
 * indikator anomali. Sebelum migrasi ini, AttendanceService set value tsb
 * tapi enum DB tolak → 500 error → karyawan tidak bisa absen.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE attendances MODIFY status "
                . "ENUM('present','late','excused','sick','absent','off_shift') "
                . "NOT NULL DEFAULT 'absent'"
            );
        }
    }

    public function down(): void
    {
        // Reset off_shift rows ke 'present' supaya enum bisa di-shrink lagi.
        DB::table('attendances')->where('status', 'off_shift')->update(['status' => 'present']);
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE attendances MODIFY status "
                . "ENUM('present','late','excused','sick','absent') "
                . "NOT NULL DEFAULT 'absent'"
            );
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: koreksi tanggal libur Indonesia 2026 yang salah seed.
 * Sumber resmi: SKB 3 Menteri RI tentang Hari Libur Nasional & Cuti Bersama 2026.
 *
 * Bug awal: ada entri di `holidays` table dengan nama "Hari Raya Waisak 2570 BE"
 * tanggal 2026-05-21 (salah) - seharusnya 1 Juni 2026 (Waisak jatuh purnama Mei
 * lunisolar, tahun 2026 jatuh di 1 Juni). Email broadcast H-1 sudah ke-trigger
 * 2026-05-20 → karyawan menerima notifikasi libur yang salah.
 *
 * Strategi:
 *   - cocokkan via LIKE name (toleran terhadap variasi "Waisak / Vesak / 2570 BE")
 *   - hanya update kalau tanggal saat ini SALAH (idempotent kalau dijalankan ulang)
 *   - reset broadcast_sent_at supaya admin bisa re-broadcast manual via UI kalau perlu
 */
return new class extends Migration {
    public function up(): void
    {
        $fixes = [
            // [name LIKE, type, correct date, label untuk log]
            // Waisak 2570 BE jatuh purnama Vaishakha - tahun 2026 di 1 Juni (per kalender Buddhist
            // Indonesia & SKB 3 Menteri). Cuti bersama umumnya di hari kerja sesudahnya.
            ['%Waisak%',              'nasional',     '2026-06-01'],
            ['%Cuti Bersama%Waisak%', 'cuti_bersama', '2026-06-02'],
        ];

        foreach ($fixes as [$nameLike, $type, $correctDate]) {
            $rows = DB::table('holidays')
                ->where('name', 'like', $nameLike)
                ->whereYear('date', 2026)
                ->where('type', $type)
                ->get();

            foreach ($rows as $row) {
                if ($row->date !== $correctDate && substr($row->date, 0, 10) !== $correctDate) {
                    DB::table('holidays')
                        ->where('id', $row->id)
                        ->update([
                            'date'              => $correctDate,
                            'broadcast_sent_at' => null,
                            'updated_at'        => now(),
                        ]);
                }
            }
        }
    }

    public function down(): void
    {
        // No-op: tidak perlu rollback ke tanggal salah.
    }
};

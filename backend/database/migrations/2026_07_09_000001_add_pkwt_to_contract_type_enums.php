<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend ENUM contract_type + employment_type untuk include 'pkwt'
 * (Perjanjian Kerja Waktu Tertentu, PP 35/2021 max 5 tahun).
 *
 * Sebelumnya cuma ada permanent + probation + internship (kontrak) dan
 * permanent/probation/training/intern_campus/intern_hub/pkl_smk (template).
 *
 * PKWT beda dari PKWTT (permanent) karena ada durasi + wajib UMK
 * (Uang Kompensasi Akhir Kontrak) tiap kontrak berakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE contracts MODIFY contract_type ENUM('probation','permanent','pkwt','internship') NOT NULL");
            DB::statement("ALTER TABLE contract_templates MODIFY employment_type ENUM('permanent','probation','pkwt','training','intern_campus','intern_hub','pkl_smk') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE contracts MODIFY contract_type ENUM('probation','permanent','internship') NOT NULL");
            DB::statement("ALTER TABLE contract_templates MODIFY employment_type ENUM('permanent','probation','training','intern_campus','intern_hub','pkl_smk') NOT NULL");
        }
    }
};

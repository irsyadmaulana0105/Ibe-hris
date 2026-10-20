<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit verifikasi wajah (19 Okt 2026).
 *
 * attendances.face_check_in/out : skor kecocokan, wajah hidup (liveness),
 *   antispoof, jumlah & ukuran wajah, dan tanda (flags) per absen. Tanpa
 *   descriptor/biometrik. Dasar penyetelan ambang & tinjauan HR.
 * users.face_photo_pending/face_photo : foto pendaftaran wajah (privat, URL
 *   bertanda tangan) supaya HR menilai wajah yang SEBENARNYA didaftarkan.
 * users.face_quality_pending : skor kualitas foto pendaftaran yang menunggu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('attendances', 'face_check_in')) {
                $table->json('face_check_in')->nullable();
            }
            if (! Schema::hasColumn('attendances', 'face_check_out')) {
                $table->json('face_check_out')->nullable();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'face_photo_pending')) {
                $table->string('face_photo_pending')->nullable();
            }
            if (! Schema::hasColumn('users', 'face_photo')) {
                $table->string('face_photo')->nullable();
            }
            if (! Schema::hasColumn('users', 'face_quality_pending')) {
                $table->json('face_quality_pending')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['face_check_in', 'face_check_out']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['face_photo_pending', 'face_photo', 'face_quality_pending']);
        });
    }
};

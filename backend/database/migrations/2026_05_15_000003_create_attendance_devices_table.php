<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesin absensi physical (ZKTeco, Solution, Fingerspot, Magic Passport).
 * UI list di Settings → Attendance Devices. Drivers actual akan polling
 * berdasarkan record di tabel ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('name', 100);
            $table->string('brand', 50)->default('zkteco');
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedSmallInteger('port')->default(4370);
            $table->string('protocol', 20)->default('zkteco_push');
            $table->foreignId('office_location_id')->nullable()->constrained('office_locations')->nullOnDelete();
            $table->string('status', 20)->default('offline');
            $table->dateTime('last_sync_at')->nullable();
            $table->unsignedInteger('total_employees_synced')->default(0);
            $table->boolean('enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_devices');
    }
};

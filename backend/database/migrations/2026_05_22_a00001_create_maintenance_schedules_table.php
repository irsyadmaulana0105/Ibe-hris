<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preventive Maintenance Schedule untuk fixed_assets.
 *
 *   maintenance_schedules - recurring schedule per asset
 *   maintenance_logs      - execution records (perform + cost + parts)
 *
 * Frequency: monthly | quarterly | semiannual | annual | every_n_days | every_n_hours
 * (every_n_hours pakai untuk runtime-based on mesin produksi yg punya jam pakai)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('fixed_asset_id')->index();
            $t->string('task_code', 32);                  // PM-001 (per asset)
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('frequency_type', 24);             // monthly|quarterly|semiannual|annual|every_n_days
            $t->integer('frequency_value')->default(1);   // for every_n_days = N
            $t->date('next_due_date')->index();
            $t->date('last_done_date')->nullable();
            $t->integer('lead_alert_days')->default(7);
            $t->string('assigned_dept', 100)->nullable();
            $t->ulid('assigned_user_id')->nullable();
            $t->decimal('estimated_cost', 14, 2)->default(0);
            $t->string('status', 16)->default('active');  // active|paused|completed
            $t->ulid('created_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status', 'next_due_date']);
        });

        Schema::create('maintenance_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('schedule_id')->index();
            $t->foreignId('company_id')->index();
            $t->foreignId('fixed_asset_id')->index();
            $t->date('performed_date');
            $t->decimal('actual_cost', 14, 2)->default(0);
            $t->text('parts_used')->nullable();
            $t->text('notes')->nullable();
            $t->text('issues_found')->nullable();
            $t->string('result', 16)->default('ok');      // ok|needs_repair|critical
            $t->ulid('performed_by')->nullable();
            $t->ulid('verified_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_logs');
        Schema::dropIfExists('maintenance_schedules');
    }
};

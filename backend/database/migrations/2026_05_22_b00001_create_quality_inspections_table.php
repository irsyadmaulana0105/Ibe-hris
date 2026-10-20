<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quality Control inspection per Work Order.
 *
 * Inspection workflow: planned → in_progress → passed | failed | rework_needed.
 * Optional: link ke serial_number (untuk QC unit-level).
 *
 * Checkpoints: stored as JSON array (e.g. [{name: 'Visual', result: 'pass'}, ...])
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_inspections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('inspection_number', 32)->unique();
            $t->foreignId('work_order_id')->nullable()->index();
            $t->string('product_serial', 64)->nullable()->index();
            $t->foreignId('product_id')->nullable()->index();
            $t->string('inspection_type', 24)->default('final');  // incoming|in_process|final|customer_return
            $t->date('inspection_date');
            $t->ulid('inspector_user_id')->nullable()->index();
            $t->ulid('verified_by_user_id')->nullable();
            $t->string('status', 16)->default('planned');         // planned|in_progress|passed|failed|rework_needed
            $t->json('checkpoints')->nullable();                  // [{name, expected, actual, result, notes}]
            $t->integer('total_checks')->default(0);
            $t->integer('passed_checks')->default(0);
            $t->integer('failed_checks')->default(0);
            $t->text('defects_found')->nullable();
            $t->text('corrective_action')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status', 'inspection_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_inspections');
    }
};

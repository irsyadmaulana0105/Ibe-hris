<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive two-stage kasbon workflow.
 *
 * Existing rows and the legacy approved_* columns remain untouched. New rows
 * flow pending -> approved (HR) -> paid (Finance). The existing status enum
 * already contains all three values, so no destructive status alteration is
 * required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->char('hr_approved_by', 26)->nullable()->after('journal_id');
            $table->timestamp('hr_approved_at')->nullable()->after('hr_approved_by');
            $table->text('hr_notes')->nullable()->after('hr_approved_at');
            $table->char('settled_by', 26)->nullable()->after('hr_notes');
            $table->timestamp('settled_at')->nullable()->after('settled_by');
            $table->string('payment_reference', 100)->nullable()->after('settled_at');

            $table->index(['company_id', 'status', 'hr_approved_at'], 'salary_advances_workflow_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->dropIndex('salary_advances_workflow_idx');
            $table->dropColumn([
                'hr_approved_by', 'hr_approved_at', 'hr_notes',
                'settled_by', 'settled_at', 'payment_reference',
            ]);
        });
    }
};

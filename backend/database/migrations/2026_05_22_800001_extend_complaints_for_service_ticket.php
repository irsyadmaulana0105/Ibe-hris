<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade Complaints → Service Tickets:
 *   - Category (hardware/software/installation/training/other)
 *   - SLA target timestamps (response & resolution)
 *   - First response tracking + breach flags
 *   - on_hold + reopened statuses (kept as string, no enum migration)
 *
 * Plus new activity log table for comments + status changes + reassignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $t) {
            if (!Schema::hasColumn('complaints', 'category')) {
                $t->string('category', 32)->default('other')->after('product_name')
                    ->comment('hardware|software|installation|training|other');
            }
            if (!Schema::hasColumn('complaints', 'sla_response_minutes')) {
                $t->unsignedInteger('sla_response_minutes')->default(60)->after('priority');
                $t->unsignedInteger('sla_resolution_minutes')->default(1440)->after('sla_response_minutes');
                $t->timestamp('target_response_at')->nullable()->after('sla_resolution_minutes');
                $t->timestamp('target_resolution_at')->nullable()->after('target_response_at');
                $t->timestamp('first_response_at')->nullable()->after('target_resolution_at');
                $t->boolean('is_response_breached')->default(false)->after('first_response_at');
                $t->boolean('is_resolution_breached')->default(false)->after('is_response_breached');
            }
            if (!Schema::hasColumn('complaints', 'reopened_at')) {
                $t->timestamp('reopened_at')->nullable()->after('resolved_at');
                $t->unsignedInteger('reopen_count')->default(0)->after('reopened_at');
            }
        });

        if (!Schema::hasTable('service_ticket_activities')) {
            Schema::create('service_ticket_activities', function (Blueprint $t) {
                $t->id();
                $t->ulid('complaint_id')->index();
                $t->foreignId('company_id')->index();
                $t->string('type', 24); // comment | status_change | assignment | escalation | sla_breach
                $t->ulid('actor_user_id')->nullable();
                $t->string('actor_label', 120)->nullable(); // for system events
                $t->text('body')->nullable();               // comment text or change description
                $t->json('meta')->nullable();               // {from: 'new', to: 'in_progress'} etc
                $t->boolean('is_internal')->default(true);  // false = visible to customer
                $t->timestamp('created_at')->useCurrent();
                $t->index(['complaint_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ticket_activities');
        Schema::table('complaints', function (Blueprint $t) {
            $cols = ['category','sla_response_minutes','sla_resolution_minutes','target_response_at',
                'target_resolution_at','first_response_at','is_response_breached','is_resolution_breached',
                'reopened_at','reopen_count'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('complaints', $c)) $t->dropColumn($c);
            }
        });
    }
};

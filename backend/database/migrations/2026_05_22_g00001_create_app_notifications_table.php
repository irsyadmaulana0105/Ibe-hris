<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications - cross-module alerts.
 *
 * Sources: SLA breach, quote expiring, PO overdue, maintenance due,
 * doc expiry, stock reorder, ticket assigned, etc.
 *
 * Recipient: user_id (ULID) atau role-based (all admins, all finance, dll).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->ulid('user_id')->nullable()->index();          // null = role-based broadcast
            $t->string('audience_role', 32)->nullable();       // 'admin' | 'superadmin' | 'finance' | 'produksi' | null
            $t->string('type', 64);                            // e.g. 'sla_breach', 'quote_expiring', etc.
            $t->string('severity', 16)->default('info');       // info | warning | error | success
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->string('link_url', 500)->nullable();           // e.g. '/quotations?id=12'
            $t->string('ref_table', 64)->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('dismissed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'user_id', 'read_at']);
            $t->index(['company_id', 'audience_role', 'read_at']);
            $t->index(['ref_table', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};

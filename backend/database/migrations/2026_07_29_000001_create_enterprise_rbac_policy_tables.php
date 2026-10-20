<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_user_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id');
            $table->foreignId('role_id')->constrained('rbac_roles')->cascadeOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->string('status', 20)->default('active');
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->decimal('approval_limit', 20, 2)->nullable();
            $table->ulid('granted_by')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('source', 30)->default('manual');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('granted_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'user_id', 'status'], 'rbac_assignment_actor_idx');
            $table->index(['company_id', 'role_id', 'status'], 'rbac_assignment_role_idx');
        });

        Schema::create('rbac_assignment_scopes', function (Blueprint $table) {
            $table->id();
            $table->ulid('assignment_id');
            $table->string('scope_type', 40);
            $table->string('scope_id', 100);
            $table->timestamps();

            $table->foreign('assignment_id')->references('id')->on('rbac_user_assignments')->cascadeOnDelete();
            $table->unique(['assignment_id', 'scope_type', 'scope_id'], 'rbac_assignment_scope_unique');
        });

        Schema::create('rbac_delegations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->ulid('delegator_id');
            $table->ulid('delegate_id');
            $table->foreignId('role_id')->nullable()->constrained('rbac_roles')->nullOnDelete();
            $table->string('capability_key', 150)->nullable();
            $table->decimal('approval_limit', 20, 2)->nullable();
            $table->dateTime('valid_from');
            $table->dateTime('valid_until');
            $table->string('status', 20)->default('active');
            $table->string('reason', 500);
            $table->ulid('approved_by')->nullable();
            $table->timestamps();

            $table->foreign('delegator_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('delegate_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'delegate_id', 'status'], 'rbac_delegation_delegate_idx');
        });

        Schema::create('rbac_access_decisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->ulid('actor_id')->nullable();
            $table->string('capability_key', 150);
            $table->string('resource_type', 100)->nullable();
            $table->string('resource_id', 100)->nullable();
            $table->string('mode', 20);
            $table->boolean('legacy_allowed');
            $table->boolean('target_allowed');
            $table->boolean('effective_allowed');
            $table->string('reason_code', 80);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'actor_id', 'created_at'], 'rbac_decision_actor_idx');
            $table->index(['capability_key', 'created_at'], 'rbac_decision_capability_idx');
            $table->index(['legacy_allowed', 'target_allowed'], 'rbac_decision_mismatch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_access_decisions');
        Schema::dropIfExists('rbac_delegations');
        Schema::dropIfExists('rbac_assignment_scopes');
        Schema::dropIfExists('rbac_user_assignments');
    }
};

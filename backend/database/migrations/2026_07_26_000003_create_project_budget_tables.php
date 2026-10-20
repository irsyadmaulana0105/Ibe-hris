<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->default('IDR');
            $table->decimal('tax_percent', 7, 4)->default(0);
            $table->decimal('contingency_percent', 7, 4)->default(0);
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'locked', 'rejected'])->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->text('notes')->nullable();
            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('project_budget_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('project_budget_items')->nullOnDelete();
            $table->string('code', 50);
            $table->enum('category', ['material', 'labor', 'equipment', 'subcontract', 'overhead', 'other']);
            $table->string('description');
            $table->string('unit', 30);
            $table->decimal('volume', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['project_budget_id', 'code']);
            $table->index(['project_budget_id', 'sort_order']);
        });

        Schema::create('project_progress_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_item_id')->constrained('project_budget_items')->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('planned_percent', 7, 4)->default(0);
            $table->decimal('actual_percent', 7, 4)->default(0);
            $table->decimal('actual_cost', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('evidence_url', 2048)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['budget_item_id', 'entry_date']);
            $table->index(['project_id', 'entry_date']);
        });

        Schema::create('project_budget_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_budget_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('grand_total', 18, 2);
            $table->json('snapshot');
            $table->text('change_reason')->nullable();
            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['project_budget_id', 'version']);
        });

        Schema::create('project_budget_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_budget_id')->constrained()->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->char('actor_id', 26)->nullable();
            $table->timestamps();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['project_budget_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_budget_audits');
        Schema::dropIfExists('project_budget_versions');
        Schema::dropIfExists('project_progress_entries');
        Schema::dropIfExists('project_budget_items');
        Schema::dropIfExists('project_budgets');
    }
};

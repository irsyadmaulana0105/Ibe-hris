<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('user_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('saas_plan_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('saas_plans')->cascadeOnDelete();
            $table->string('module_key', 50);
            $table->boolean('enabled')->default(true);
            $table->json('limits')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'module_key']);
        });

        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('saas_plans')->nullOnDelete();
            $table->string('status', 30)->default('active');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->string('external_reference')->nullable();
            $table->timestamps();
            $table->unique('company_id');
        });

        Schema::create('company_module_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 50);
            $table->boolean('enabled')->default(true);
            $table->string('source', 20)->default('plan');
            $table->json('limits')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'module_key']);
            $table->index(['company_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_module_entitlements');
        Schema::dropIfExists('company_subscriptions');
        Schema::dropIfExists('saas_plan_modules');
        Schema::dropIfExists('saas_plans');
    }
};

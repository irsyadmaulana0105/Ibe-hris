<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRIS Enterprise Modules (Sprints 5/6/9 + Settings backend).
 *
 * Adds backend persistence for the 11 modules that were previously frontend-only:
 *  - Performance (OKR + 9-Box + Review)
 *  - Recruitment ATS (Job Postings + Candidates)
 *  - Learning (Courses + Enrollments + Certificates)
 *  - RBAC (custom Roles + capabilities)
 *  - Leave Policies (quotas + holidays + globals)
 *  - Payroll Formula components
 *  - Workflow steps per request type
 *  - Notification templates
 *  - Integrations (encrypted credentials)
 *  - Backup config + history
 *  - Theme (per-company branding)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Performance ─────────────────────────────────────────────────────
        Schema::create('okrs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26)->index();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('objective');
            $table->string('metric');
            $table->decimal('target', 18, 2)->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->date('due')->nullable();
            $table->string('status', 20)->default('on_track');
            $table->timestamps();
        });

        Schema::create('nine_box_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('performance');
            $table->unsignedTinyInteger('potential');
            $table->text('notes')->nullable();
            $table->char('rated_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26)->index();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('cycle', 30);
            $table->string('stage', 20)->default('draft');
            $table->decimal('self_score', 5, 2)->nullable();
            $table->decimal('manager_score', 5, 2)->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->text('self_comments')->nullable();
            $table->text('manager_comments')->nullable();
            $table->char('reviewer_id', 26)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'cycle']);
        });

        // ── Recruitment ATS ─────────────────────────────────────────────────
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('title');
            $table->string('division', 100)->nullable();
            $table->string('location', 100)->nullable();
            $table->string('type', 20)->default('fulltime');
            $table->text('description')->nullable();
            $table->string('salary_range', 100)->nullable();
            $table->string('status', 20)->default('open');
            $table->date('posted_at')->nullable();
            $table->date('closed_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreignId('job_posting_id')->constrained('job_postings')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('cv_url', 500)->nullable();
            $table->string('source', 50)->default('Website');
            $table->string('stage', 20)->default('applied');
            $table->text('notes')->nullable();
            $table->date('applied_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'stage']);
        });

        // ── Learning & Development ──────────────────────────────────────────
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('title');
            $table->string('category', 100)->nullable();
            $table->string('provider', 150)->nullable();
            $table->unsignedSmallInteger('hours')->default(0);
            $table->string('type', 20)->default('internal');
            $table->string('url', 500)->nullable();
            $table->boolean('mandatory')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('status', 20)->default('in_progress');
            $table->date('enrolled_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'user_id']);
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26)->index();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('name');
            $table->string('issuer', 150)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('file_url', 500)->nullable();
            $table->timestamps();
        });

        // ── RBAC (custom roles + capabilities per company) ─────────────────
        Schema::create('rbac_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('key', 50);
            $table->string('label', 100);
            $table->string('color', 20)->default('#1a7a8c');
            $table->string('description', 255)->nullable();
            $table->boolean('is_builtin')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('rbac_capabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('module', 50);
            $table->string('capability_key', 100);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'capability_key']);
        });

        Schema::create('rbac_role_capability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('rbac_roles')->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained('rbac_capabilities')->cascadeOnDelete();
            $table->unique(['role_id', 'capability_id']);
        });

        // ── Leave Policy config ─────────────────────────────────────────────
        Schema::create('leave_policy_quotas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('type_key', 50);
            $table->string('name', 100);
            $table->unsignedSmallInteger('permanent')->default(0);
            $table->unsignedSmallInteger('contract')->default(0);
            $table->unsignedSmallInteger('intern')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'type_key']);
        });

        Schema::create('leave_holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->date('date');
            $table->string('name', 150);
            $table->string('type', 50)->default('Libur Nasional');
            $table->timestamps();
            $table->unique(['company_id', 'date', 'name']);
        });

        Schema::create('leave_policy_globals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->boolean('carry_over_enabled')->default(true);
            $table->unsignedSmallInteger('max_carry')->default(6);
            $table->boolean('auto_approve_enabled')->default(false);
            $table->unsignedSmallInteger('auto_approve_days')->default(7);
            $table->timestamps();
        });

        // ── Payroll Formula ─────────────────────────────────────────────────
        Schema::create('payroll_formula_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('name', 100);
            $table->string('category', 20)->default('earning');
            $table->string('formula', 500);
            $table->string('method', 20)->default('gross');
            $table->boolean('taxable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Workflow Builder ───────────────────────────────────────────────
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('key', 50);
            $table->string('name', 100);
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('role', 50);
            $table->string('color', 20)->default('#1a7a8c');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Notification Templates ─────────────────────────────────────────
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('event_key', 50);
            $table->string('event_label', 150);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->boolean('slack_enabled')->default(false);
            $table->text('body');
            $table->timestamps();
            $table->unique(['company_id', 'event_key']);
        });

        // ── 3rd Party Integrations ─────────────────────────────────────────
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('key', 50);
            $table->string('name', 100);
            $table->string('category', 50)->default('other');
            $table->string('icon', 10)->nullable();
            $table->string('field_label', 100)->nullable();
            $table->text('credential_encrypted')->nullable();
            $table->string('status', 20)->default('disconnected');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        // ── Backup ─────────────────────────────────────────────────────────
        Schema::create('backup_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->boolean('schedule_enabled')->default(true);
            $table->string('schedule_time', 8)->default('03:00');
            $table->unsignedSmallInteger('retention_daily')->default(30);
            $table->unsignedSmallInteger('retention_weekly')->default(12);
            $table->unsignedSmallInteger('retention_monthly')->default(12);
            $table->unsignedSmallInteger('retention_yearly')->default(7);
            $table->string('destination', 50)->default('s3-glacier');
            $table->timestamps();
        });

        Schema::create('backup_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->dateTime('ran_at');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('type', 20)->default('manual');
            $table->string('status', 20)->default('success');
            $table->string('storage_path', 500)->nullable();
            $table->timestamps();
        });

        // ── Theme Builder ──────────────────────────────────────────────────
        Schema::create('company_themes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->string('primary_color', 20)->default('#1a7a8c');
            $table->string('accent_color', 20)->default('#ff9d2e');
            $table->string('font_family', 100)->default('Inter');
            $table->unsignedSmallInteger('radius')->default(12);
            $table->string('logo_url', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_themes');
        Schema::dropIfExists('backup_snapshots');
        Schema::dropIfExists('backup_configs');
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_definitions');
        Schema::dropIfExists('payroll_formula_components');
        Schema::dropIfExists('leave_policy_globals');
        Schema::dropIfExists('leave_holidays');
        Schema::dropIfExists('leave_policy_quotas');
        Schema::dropIfExists('rbac_role_capability');
        Schema::dropIfExists('rbac_capabilities');
        Schema::dropIfExists('rbac_roles');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('job_postings');
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('nine_box_ratings');
        Schema::dropIfExists('okrs');
    }
};

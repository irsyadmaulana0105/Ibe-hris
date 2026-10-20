<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('name');
            $table->string('pic_name')->nullable();
            $table->string('pic_email')->nullable();
            $table->string('pic_phone')->nullable();
            $table->string('tax_id')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        // The ERP already owns `work_orders` for manufacturing. Vendor jobs use
        // an explicit table to avoid changing that stable status lifecycle.
        Schema::create('vendor_work_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('ref_no');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('budget', 18, 2)->nullable();
            $table->date('scheduled_start')->nullable();
            $table->date('scheduled_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->enum('status', ['draft', 'sent', 'in_progress', 'review', 'completed', 'cancelled'])->default('draft')->index();
            $table->char('created_by_user_id', 26)->nullable();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'ref_no']);
        });

        Schema::create('work_order_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('work_order_id')->constrained('vendor_work_orders')->cascadeOnDelete();
            $table->unsignedTinyInteger('progress_percent');
            $table->text('note')->nullable();
            $table->json('photo_paths')->nullable();
            $table->enum('submitted_by', ['vendor', 'internal']);
            $table->char('submitted_by_user_id', 26)->nullable();
            $table->foreign('submitted_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('vendor_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_access_tokens');
        Schema::dropIfExists('work_order_progress');
        Schema::dropIfExists('vendor_work_orders');
        Schema::dropIfExists('vendors');
    }
};

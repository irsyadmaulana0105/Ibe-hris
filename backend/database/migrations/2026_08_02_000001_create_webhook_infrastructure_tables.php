<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('url', 2048);
            $table->text('secret');
            $table->json('events');
            $table->json('headers')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'active']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('company_id')->index();
            $table->string('type', 120)->index();
            $table->json('payload');
            $table->string('correlation_id', 100)->nullable()->index();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_event_id')->index();
            $table->foreignId('webhook_endpoint_id')->index();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['webhook_event_id', 'webhook_endpoint_id'], 'webhook_event_endpoint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_endpoints');
    }
};

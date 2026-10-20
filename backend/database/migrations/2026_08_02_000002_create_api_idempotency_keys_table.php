<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->ulid('user_id')->index();
            $table->string('key', 100);
            $table->string('method', 10);
            $table->string('path', 500);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};

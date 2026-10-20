<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->char('user_id', 26)->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('filename', 255);          // disk-stored name (random)
            $table->string('original_name', 255);     // user-facing name
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('path', 500);              // relative to storage/app/public
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
    }
};

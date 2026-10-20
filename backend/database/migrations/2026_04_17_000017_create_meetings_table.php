<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('title');
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('location');
            $table->text('agenda');
            $table->enum('type', ['internal', 'external', 'board', 'other'])->default('internal');
            $table->timestamps();
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meeting_id');
            $table->foreign('meeting_id')->references('id')->on('meetings')->cascadeOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->boolean('attended')->default(false);
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id']);
        });

        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meeting_id');
            $table->foreign('meeting_id')->references('id')->on('meetings')->cascadeOnDelete();
            $table->char('user_id', 26)->nullable();
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_minutes');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->enum('role', ['member', 'lead', 'owner'])->default('member');
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};

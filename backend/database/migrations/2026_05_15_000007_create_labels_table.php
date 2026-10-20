<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 20)->default('slate');
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->index(['project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};

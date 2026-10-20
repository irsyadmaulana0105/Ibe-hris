<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_label', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id');
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('label_id');
            $table->foreign('label_id')->references('id')->on('labels')->cascadeOnDelete();
            $table->primary(['task_id', 'label_id']);
            $table->index(['label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_label');
    }
};

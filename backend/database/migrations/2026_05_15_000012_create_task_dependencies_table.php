<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            // `task_id` is blocked by `depends_on_task_id` - i.e. depends_on
            // must be completed before task_id can proceed.
            $table->unsignedBigInteger('task_id');
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('depends_on_task_id');
            $table->foreign('depends_on_task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['task_id', 'depends_on_task_id']);
            $table->index(['depends_on_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};

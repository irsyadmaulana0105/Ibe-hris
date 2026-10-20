<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('sprint_id')->nullable()->after('project_id');
            $table->foreign('sprint_id')->references('id')->on('sprints')->nullOnDelete();
            $table->unsignedTinyInteger('story_points')->nullable()->after('priority');
            $table->index(['sprint_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['sprint_id']);
            $table->dropIndex(['sprint_id']);
            $table->dropColumn(['sprint_id', 'story_points']);
        });
    }
};

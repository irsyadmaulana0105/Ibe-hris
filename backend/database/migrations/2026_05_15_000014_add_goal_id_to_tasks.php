<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('goal_id')->nullable()->after('sprint_id');
            $table->foreign('goal_id')->references('id')->on('goals')->nullOnDelete();
            $table->index(['goal_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['goal_id']);
            $table->dropIndex(['goal_id']);
            $table->dropColumn('goal_id');
        });
    }
};

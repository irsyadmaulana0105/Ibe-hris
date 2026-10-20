<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Stored in minutes for fractional-hour accuracy
            $table->unsignedInteger('time_estimate')->nullable()->after('story_points');
            $table->unsignedInteger('time_spent')->nullable()->after('time_estimate');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['time_estimate', 'time_spent']);
        });
    }
};

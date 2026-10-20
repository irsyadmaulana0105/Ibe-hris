<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Toggle for project app email notifications (mention/assigned).
            // Default ON - most users want to be reachable when offline.
            $table->boolean('project_email_notifications_enabled')->default(true)->after('invite_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('project_email_notifications_enabled');
        });
    }
};

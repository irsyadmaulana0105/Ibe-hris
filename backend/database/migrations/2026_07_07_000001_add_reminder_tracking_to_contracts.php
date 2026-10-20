<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track kapan reminder terakhir dikirim per contract supaya tidak duplicate.
 * Format JSON: {"30_day": "2026-07-07", "14_day": null, "7_day": null}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->json('renewal_reminders_sent')->nullable()->after('materai_uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->dropColumn('renewal_reminders_sent');
        });
    }
};

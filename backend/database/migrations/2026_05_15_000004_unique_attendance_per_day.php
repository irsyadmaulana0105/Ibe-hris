<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deduplicate attendances rows that share (user_id, date) and add a UNIQUE
 * index to prevent reoccurrence. Caused by clock-in/out racing or duplicate
 * device pushes.
 *
 * Keep strategy: retain the row with the earliest clock-in (lowest id when
 * times equal). Delete the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: dedupe - delete extras, keeping smallest id per (user_id, date)
        $duplicates = DB::table('attendances')
            ->select('user_id', 'date', DB::raw('MIN(id) as keep_id'))
            ->groupBy('user_id', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            DB::table('attendances')
                ->where('user_id', $row->user_id)
                ->where('date', $row->date)
                ->where('id', '!=', $row->keep_id)
                ->delete();
        }

        // Step 2: add UNIQUE constraint
        Schema::table('attendances', function ($table) {
            $table->unique(['user_id', 'date'], 'attendances_user_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function ($table) {
            $table->dropUnique('attendances_user_date_unique');
        });
    }
};

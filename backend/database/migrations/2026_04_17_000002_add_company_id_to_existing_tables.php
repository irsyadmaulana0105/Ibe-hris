<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['users', 'divisions', 'job_titles', 'educations', 'shifts', 'barcodes'];

        // Step 1: add column WITHOUT foreign key first so we can insert data
        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->after('id');
            });
        }

        // Step 2: ensure default company exists so we can assign it
        $companyId = DB::table('companies')->where('slug', 'ibe-reality')->value('id');
        if (!$companyId) {
            $companyId = DB::table('companies')->insertGetId([
                'name'       => 'IBE Reality',
                'slug'       => 'ibe-reality',
                'is_active'  => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Step 3: assign existing records to the default company
        foreach ($tables as $t) {
            DB::table($t)->whereNull('company_id')->update(['company_id' => $companyId]);
        }

        // Step 4: now add the foreign key constraint
        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $tables = ['users', 'divisions', 'job_titles', 'educations', 'shifts', 'barcodes'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign([$table . '.company_id']);
                $blueprint->dropColumn('company_id');
            });
        }
    }
};

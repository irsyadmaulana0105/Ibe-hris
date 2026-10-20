<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('employment_status', ['permanent', 'probation', 'intern_campus', 'intern_hub', 'pkl_smk'])
                ->nullable()
                ->after('job_title_id');
            $table->unsignedInteger('salary_monthly')->nullable()->after('employment_status');
            $table->date('join_date')->nullable()->after('salary_monthly');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['employment_status', 'salary_monthly', 'join_date']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->char('generated_by', 26)->nullable()->after('status');
            $table->char('submitted_by', 26)->nullable()->after('generated_by');
            $table->char('approved_by', 26)->nullable()->after('submitted_by');
            $table->char('paid_by', 26)->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->dropColumn(['generated_by', 'submitted_by', 'approved_by', 'paid_by']);
        });
    }
};

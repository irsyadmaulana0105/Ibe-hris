<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('pod_signature_path')->nullable();
            $table->string('pod_receiver_phone', 40)->nullable();
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->char('reviewed_by', 26)->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });

        Schema::table('delivery_returns', function (Blueprint $table) {
            $table->char('reviewed_by', 26)->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_returns', function (Blueprint $table) {
            $table->dropColumn(['reviewed_by', 'reviewed_at']);
        });
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn(['reviewed_by', 'reviewed_at']);
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['pod_signature_path', 'pod_receiver_phone']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('selfie_in_address')->nullable()->after('selfie_in_taken_at');
            $table->string('selfie_out_address')->nullable()->after('selfie_in_address');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['selfie_in_address', 'selfie_out_address']);
        });
    }
};

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
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('selfie_in_path')->nullable()->after('attachment');
            $table->string('selfie_out_path')->nullable()->after('selfie_in_path');

            $table->double('selfie_in_latitude')->nullable()->after('selfie_out_path');
            $table->double('selfie_in_longitude')->nullable()->after('selfie_in_latitude');
            $table->double('selfie_out_latitude')->nullable()->after('selfie_in_longitude');
            $table->double('selfie_out_longitude')->nullable()->after('selfie_out_latitude');

            $table->timestamp('selfie_in_taken_at')->nullable()->after('selfie_out_longitude');
            $table->timestamp('selfie_out_taken_at')->nullable()->after('selfie_in_taken_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'selfie_in_path',
                'selfie_out_path',
                'selfie_in_latitude',
                'selfie_in_longitude',
                'selfie_out_latitude',
                'selfie_out_longitude',
                'selfie_in_taken_at',
                'selfie_out_taken_at',
            ]);
        });
    }
};

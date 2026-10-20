<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_trips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->date('date');

            // Check-in
            $table->time('check_in_time')->nullable();
            $table->double('check_in_latitude')->nullable();
            $table->double('check_in_longitude')->nullable();
            $table->string('check_in_photo')->nullable();
            $table->unsignedSmallInteger('late_minutes')->nullable();

            // Check-out
            $table->time('check_out_time')->nullable();
            $table->double('check_out_latitude')->nullable();
            $table->double('check_out_longitude')->nullable();
            $table->string('check_out_photo')->nullable();
            $table->unsignedSmallInteger('early_out_minutes')->nullable();

            $table->string('destination')->nullable();
            $table->text('purpose')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_trips');
    }
};

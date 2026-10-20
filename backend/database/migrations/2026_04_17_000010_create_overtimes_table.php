<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtimes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->date('date');

            // Check-in
            $table->time('check_in_time');
            $table->double('check_in_latitude')->nullable();
            $table->double('check_in_longitude')->nullable();
            $table->string('check_in_distance')->nullable();
            $table->string('check_in_photo')->nullable();

            // Check-out
            $table->time('check_out_time')->nullable();
            $table->double('check_out_latitude')->nullable();
            $table->double('check_out_longitude')->nullable();
            $table->string('check_out_distance')->nullable();
            $table->string('check_out_photo')->nullable();

            $table->decimal('total_hours', 5, 2)->nullable();
            $table->string('reason')->nullable();
            $table->string('attachment')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtimes');
    }
};

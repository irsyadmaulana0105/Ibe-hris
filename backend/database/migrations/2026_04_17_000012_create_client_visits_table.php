<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_visits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->date('date');

            // Visit in
            $table->dateTime('visit_in')->nullable();
            $table->string('photo_in')->nullable();
            $table->double('latitude_in')->nullable();
            $table->double('longitude_in')->nullable();
            $table->text('notes_in')->nullable();

            // Visit out
            $table->dateTime('visit_out')->nullable();
            $table->string('photo_out')->nullable();
            $table->double('latitude_out')->nullable();
            $table->double('longitude_out')->nullable();
            $table->text('notes_out')->nullable();

            $table->enum('status', ['ongoing', 'completed'])->default('ongoing');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_visits');
    }
};

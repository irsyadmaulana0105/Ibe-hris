<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('budget_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('item_code')->nullable();
            $table->string('item_name');
            $table->decimal('stock', 10, 2)->default(0);
            $table->string('unit')->nullable();                 // UoM
            $table->text('description')->nullable();
            $table->unsignedBigInteger('office_location_id')->nullable();
            $table->foreign('office_location_id')->references('id')->on('office_locations')->nullOnDelete();
            $table->unsignedBigInteger('job_title_id')->nullable();
            $table->foreign('job_title_id')->references('id')->on('job_titles')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('inventory_categories');
    }
};

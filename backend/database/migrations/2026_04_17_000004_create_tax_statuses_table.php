<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // TK/0, K/0, K/1, etc.
            $table->decimal('ptkp_current', 15, 2)->default(0); // current PTKP 2016
            $table->decimal('ptkp_2015', 15, 2)->default(0);
            $table->decimal('ptkp_2009', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_statuses');
    }
};

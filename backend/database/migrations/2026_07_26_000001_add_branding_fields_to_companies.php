<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('short_name')->nullable()->after('name');
            $table->string('logo_url')->nullable()->after('short_name');
            $table->string('logo_dark_url')->nullable()->after('logo_url');
            $table->string('favicon_url')->nullable()->after('logo_dark_url');
            $table->string('brand_color_primary', 7)->default('#1a7a8c')->after('favicon_url');
            $table->string('brand_color_accent', 7)->default('#ff9d2e')->after('brand_color_primary');
            $table->string('tagline')->nullable()->after('brand_color_accent');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'short_name',
                'logo_url',
                'logo_dark_url',
                'favicon_url',
                'brand_color_primary',
                'brand_color_accent',
                'tagline',
            ]);
        });
    }
};

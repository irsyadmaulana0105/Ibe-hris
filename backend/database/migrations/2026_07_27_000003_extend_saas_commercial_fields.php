<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_plans', function (Blueprint $table) {
            $table->decimal('monthly_price', 15, 2)->nullable()->after('user_limit');
            $table->decimal('yearly_price', 15, 2)->nullable()->after('monthly_price');
            $table->string('currency', 3)->default('IDR')->after('yearly_price');
        });
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('licensed_users')->nullable()->after('status');
            $table->string('billing_email')->nullable()->after('external_reference');
        });
    }

    public function down(): void
    {
        Schema::table('company_subscriptions', fn (Blueprint $table) => $table->dropColumn(['licensed_users', 'billing_email']));
        Schema::table('saas_plans', fn (Blueprint $table) => $table->dropColumn(['monthly_price', 'yearly_price', 'currency']));
    }
};

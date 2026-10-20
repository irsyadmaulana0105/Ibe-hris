<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag is_owner = developer/owner platform yang punya capability `system.manage`
 * - bisa kelola RBAC, platform visibility, integrasi Stalwart. Beda dengan
 * superadmin biasa yang full akses business logic tapi tidak teknis platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_owner')->default(false)->after('group')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('is_owner');
        });
    }
};

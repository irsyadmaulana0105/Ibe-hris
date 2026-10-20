<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_attachments', function (Blueprint $table) {
            $table->enum('storage_provider', ['local', 'google_drive'])->default('local')->after('path');
            $table->string('drive_file_id', 100)->nullable()->after('storage_provider');
            $table->text('drive_web_view_link')->nullable()->after('drive_file_id');
            $table->text('drive_download_link')->nullable()->after('drive_web_view_link');

            $table->index(['storage_provider']);
        });

        Schema::table('projects', function (Blueprint $table) {
            // Cached folder ID per project so we don't lookup/create on every upload
            $table->string('drive_folder_id', 100)->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_attachments', function (Blueprint $table) {
            $table->dropIndex(['storage_provider']);
            $table->dropColumn(['storage_provider', 'drive_file_id', 'drive_web_view_link', 'drive_download_link']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('drive_folder_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addChecksums('companies', [
            'director_signature_sha256',
            'hrd_signature_sha256',
            'finance_signature_sha256',
            'stamp_sha256',
        ]);
        $this->addChecksums('contracts', [
            'signature_sha256',
            'signed_pdf_sha256',
            'hrd_paraf_signature_sha256',
            'karyawan_signature_sha256',
            'direktur_signature_sha256',
            'materai_pdf_sha256',
        ]);
        if (Schema::hasTable('contracts')) {
            Schema::table('contracts', function (Blueprint $blueprint): void {
                if (! Schema::hasColumn('contracts', 'direktur_signature_unavailable_at')) {
                    $blueprint->timestamp('direktur_signature_unavailable_at')->nullable();
                }
                if (! Schema::hasColumn('contracts', 'direktur_signature_unavailable_reason')) {
                    $blueprint->string('direktur_signature_unavailable_reason', 64)->nullable();
                }
                if (! Schema::hasColumn('contracts', 'direktur_signature_reference_fingerprint')) {
                    $blueprint->char('direktur_signature_reference_fingerprint', 64)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive. Rollback is performed by the release
        // cutover command, which restores verified public objects and DB refs.
        // Dropping integrity metadata would make a safe forward retry impossible.
    }

    private function addChecksums(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $blueprint->char($column, 64)->nullable();
                }
            }
        });
    }
};

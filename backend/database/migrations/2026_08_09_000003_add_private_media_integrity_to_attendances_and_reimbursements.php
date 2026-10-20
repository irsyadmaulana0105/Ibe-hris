<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addMediaColumns('attendances', [
            'selfie_in',
            'selfie_out',
            'attachment',
        ]);
        $this->addMediaColumns('reimbursements', [
            'attachment',
            'payment_proof',
            'receipt_pdf',
        ]);
    }

    /**
     * Deliberate no-op. Removing integrity or tombstone metadata can make a
     * private-media rollback ambiguous and would silently re-expose raw paths.
     */
    public function down(): void {}

    /** @param list<string> $prefixes */
    private function addMediaColumns(string $table, array $prefixes): void
    {
        foreach ($prefixes as $prefix) {
            $columns = [
                $prefix.'_sha256' => fn (Blueprint $blueprint) => $blueprint->string($prefix.'_sha256', 64)->nullable(),
                $prefix.'_size_bytes' => fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger($prefix.'_size_bytes')->nullable(),
                $prefix.'_mime_type' => fn (Blueprint $blueprint) => $blueprint->string($prefix.'_mime_type', 64)->nullable(),
                $prefix.'_width' => fn (Blueprint $blueprint) => $blueprint->unsignedInteger($prefix.'_width')->nullable(),
                $prefix.'_height' => fn (Blueprint $blueprint) => $blueprint->unsignedInteger($prefix.'_height')->nullable(),
                $prefix.'_unavailable_reason' => fn (Blueprint $blueprint) => $blueprint->string($prefix.'_unavailable_reason', 64)->nullable(),
                $prefix.'_reference_fingerprint' => fn (Blueprint $blueprint) => $blueprint->string($prefix.'_reference_fingerprint', 64)->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (Schema::hasColumn($table, $column)) {
                    continue;
                }
                Schema::table($table, function (Blueprint $blueprint) use ($definition): void {
                    $definition($blueprint);
                });
            }
        }
    }
};

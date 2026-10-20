<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertRequiredTablesExist();

        if (! Schema::hasColumn('users', 'attendance_location_mode')) {
            Schema::table('users', function (Blueprint $table): void {
                $column = $table->string('attendance_location_mode', 32)
                    ->default('office_geofence');

                $this->afterWhenSupported($column, 'users', 'office_location_id');
            });
        }

        $missingAttendanceColumns = array_filter(
            [
                'location_policy',
                'selfie_in_accuracy_m',
                'selfie_out_accuracy_m',
                'selfie_in_location_recorded_at',
                'selfie_out_location_recorded_at',
                'selfie_in_location_risk_flags',
                'selfie_out_location_risk_flags',
                'attendance_device_id',
            ],
            static fn (string $column): bool => ! Schema::hasColumn('attendances', $column),
        );

        if ($missingAttendanceColumns === []) {
            return;
        }

        Schema::table('attendances', function (Blueprint $table) use ($missingAttendanceColumns): void {
            foreach ($missingAttendanceColumns as $columnName) {
                $column = match ($columnName) {
                    'location_policy' => $table->string($columnName, 32)->nullable(),
                    'selfie_in_accuracy_m', 'selfie_out_accuracy_m' => $table->decimal($columnName, 8, 2)->nullable(),
                    'selfie_in_location_recorded_at', 'selfie_out_location_recorded_at' => $table->timestamp($columnName)->nullable(),
                    'selfie_in_location_risk_flags', 'selfie_out_location_risk_flags' => $table->json($columnName)->nullable(),
                    'attendance_device_id' => $table->string($columnName, 128)->nullable(),
                };

                $anchor = match ($columnName) {
                    'location_policy' => 'longitude',
                    'selfie_in_accuracy_m' => 'selfie_in_longitude',
                    'selfie_out_accuracy_m' => 'selfie_out_longitude',
                    'selfie_in_location_recorded_at' => 'selfie_in_taken_at',
                    'selfie_out_location_recorded_at' => 'selfie_out_taken_at',
                    default => null,
                };

                if ($anchor !== null) {
                    $this->afterWhenSupported($column, 'attendances', $anchor);
                }
            }
        });
    }

    public function down(): void
    {
        // Deliberately additive. A code rollback must preserve captured attendance
        // security evidence and user location policy for a later forward recovery.
    }

    private function assertRequiredTablesExist(): void
    {
        $missingTables = array_values(array_filter(
            ['users', 'attendances'],
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missingTables !== []) {
            throw new LogicException(sprintf(
                'Cannot add mobile attendance security fields: required table(s) missing: %s.',
                implode(', ', $missingTables),
            ));
        }
    }

    private function afterWhenSupported(
        ColumnDefinition $column,
        string $table,
        string $anchor,
    ): void {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasColumn($table, $anchor)) {
            $column->after($anchor);
        }
    }
};

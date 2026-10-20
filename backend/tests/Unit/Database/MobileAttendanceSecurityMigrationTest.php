<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class)->group('migration-safety', 'mobile-attendance');

function createMobileAttendanceBaseTables(
    bool $includeAttendances = true,
    bool $includeOrderingAnchors = true,
): void {
    Schema::create('users', function (Blueprint $table) use ($includeOrderingAnchors): void {
        $table->string('id')->primary();
        $table->string('name');

        if ($includeOrderingAnchors) {
            $table->unsignedBigInteger('office_location_id')->nullable();
        }
    });

    if (! $includeAttendances) {
        return;
    }

    Schema::create('attendances', function (Blueprint $table) use ($includeOrderingAnchors): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('user_id');
        $table->string('note')->nullable();

        if ($includeOrderingAnchors) {
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('selfie_in_longitude', 10, 7)->nullable();
            $table->decimal('selfie_out_longitude', 10, 7)->nullable();
            $table->timestamp('selfie_in_taken_at')->nullable();
            $table->timestamp('selfie_out_taken_at')->nullable();
        }
    });
}

beforeEach(function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:');
});

it('is repeatable and preserves security evidence across a code rollback', function (): void {
    createMobileAttendanceBaseTables();

    DB::table('users')->insert([
        'id' => 'user-1',
        'name' => 'Mobile Sales',
        'office_location_id' => 7,
    ]);
    DB::table('attendances')->insert([
        'id' => 1,
        'user_id' => 'user-1',
        'note' => 'Pre-migration attendance record',
        'longitude' => 112.7521,
    ]);

    $migration = require database_path('migrations/2026_08_04_000002_add_mobile_attendance_security_fields.php');

    $migration->up();
    $migration->up();

    $userColumns = Schema::getColumnListing('users');
    $attendanceColumns = Schema::getColumnListing('attendances');
    $securityColumns = [
        'location_policy',
        'selfie_in_accuracy_m',
        'selfie_out_accuracy_m',
        'selfie_in_location_recorded_at',
        'selfie_out_location_recorded_at',
        'selfie_in_location_risk_flags',
        'selfie_out_location_risk_flags',
        'attendance_device_id',
    ];

    expect(array_count_values($userColumns)['attendance_location_mode'] ?? 0)->toBe(1);

    foreach ($securityColumns as $column) {
        expect(array_count_values($attendanceColumns)[$column] ?? 0)->toBe(1);
    }

    expect(DB::table('users')->where('id', 'user-1')->value('attendance_location_mode'))
        ->toBe('office_geofence');

    DB::table('users')->where('id', 'user-1')->update([
        'attendance_location_mode' => 'mobile_anywhere',
    ]);
    DB::table('attendances')->where('id', 1)->update([
        'location_policy' => 'mobile_anywhere',
        'selfie_in_location_risk_flags' => json_encode(['mock_location' => false], JSON_THROW_ON_ERROR),
        'attendance_device_id' => 'device-proof-001',
    ]);

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('users', 'attendance_location_mode'))->toBeTrue()
        ->and(Schema::hasColumn('attendances', 'location_policy'))->toBeTrue()
        ->and(DB::table('users')->where('id', 'user-1')->value('attendance_location_mode'))
        ->toBe('mobile_anywhere')
        ->and(DB::table('attendances')->where('id', 1)->value('note'))
        ->toBe('Pre-migration attendance record')
        ->and(DB::table('attendances')->where('id', 1)->value('location_policy'))
        ->toBe('mobile_anywhere')
        ->and(DB::table('attendances')->where('id', 1)->value('selfie_in_location_risk_flags'))
        ->toBe('{"mock_location":false}')
        ->and(DB::table('attendances')->where('id', 1)->value('attendance_device_id'))
        ->toBe('device-proof-001');

    $migration->up();
});

it('fails before mutation when a required base table is missing', function (): void {
    createMobileAttendanceBaseTables(includeAttendances: false);

    $migration = require database_path('migrations/2026_08_04_000002_add_mobile_attendance_security_fields.php');

    expect(fn () => $migration->up())
        ->toThrow(
            LogicException::class,
            'Cannot add mobile attendance security fields: required table(s) missing: attendances.',
        );

    expect(Schema::hasColumn('users', 'attendance_location_mode'))->toBeFalse();
});

it('does not require physical column-order anchors on SQLite', function (): void {
    createMobileAttendanceBaseTables(includeOrderingAnchors: false);

    $migration = require database_path('migrations/2026_08_04_000002_add_mobile_attendance_security_fields.php');

    $migration->up();
    $migration->up();
    $migration->down();

    expect(Schema::hasColumn('users', 'attendance_location_mode'))->toBeTrue()
        ->and(Schema::hasColumn('attendances', 'selfie_in_accuracy_m'))->toBeTrue()
        ->and(Schema::hasColumn('attendances', 'selfie_out_location_recorded_at'))->toBeTrue();
});

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_number', 32)->nullable()->after('nip');
            $table->unique('employee_number', 'users_employee_number_unique');
        });

        $sequences = [];
        DB::table('users')
            ->select(['id', 'company_id', 'nip', 'ktp_number', 'join_date', 'created_at'])
            ->orderBy('company_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (object $user) use (&$sequences): void {
                $date = $user->join_date ?: $user->created_at;
                $year = $date ? (int) substr((string) $date, 0, 4) : (int) now()->format('Y');
                if ($year < 2000 || $year > 2100) {
                    $year = (int) now()->format('Y');
                }
                $key = (string) $year;
                $sequences[$key] = ($sequences[$key] ?? 0) + 1;

                $updates = [
                    'employee_number' => sprintf('IBE-%d-%04d', $year, $sequences[$key]),
                ];
                if (! $user->ktp_number && preg_match('/^\d{16}$/', (string) $user->nip)) {
                    $updates['ktp_number'] = $user->nip;
                }
                DB::table('users')->where('id', $user->id)->update($updates);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_employee_number_unique');
            $table->dropColumn('employee_number');
        });
    }
};

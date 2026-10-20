<?php

namespace Database\Seeders;

use App\Models\Allowance;
use App\Models\Company;
use App\Models\EmployeeGrade;
use Illuminate\Database\Seeder;

class EmployeeGradeSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('slug', 'ibe-reality')->first();
        if (!$company) {
            $this->command->warn('Run CompanySeeder first.');
            return;
        }

        $grades = [
            ['name' => 'Golongan I',   'meal' => 15000, 'transport' => 20000],
            ['name' => 'Golongan II',  'meal' => 20000, 'transport' => 25000],
            ['name' => 'Golongan III', 'meal' => 25000, 'transport' => 30000],
            ['name' => 'Golongan IV',  'meal' => 30000, 'transport' => 35000],
        ];

        foreach ($grades as $g) {
            $grade = EmployeeGrade::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'name' => $g['name']],
                ['company_id' => $company->id]
            );

            Allowance::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'employee_grade_id' => $grade->id],
                [
                    'company_id'         => $company->id,
                    'meal_allowance'     => $g['meal'],
                    'transport_allowance'=> $g['transport'],
                ]
            );
        }

        $this->command->info('Employee grades & allowances seeded.');
    }
}

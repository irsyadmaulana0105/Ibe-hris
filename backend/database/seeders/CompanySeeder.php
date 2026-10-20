<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['slug' => 'ibe-reality'],
            [
                'name'                  => 'PT. Integrasi Bisnis Eksekutif',
                'slug'                  => 'ibe-reality',
                'address'               => 'Jl. Raya Wonoayu, Sidoarjo, Jawa Timur',
                'city'                  => 'Sidoarjo',
                'phone'                 => '',
                'email'                 => 'ibereality01@gmail.com',
                'finance_email'         => 'ibereality01@gmail.com',
                'is_active'             => true,
            ]
        );

        // Ensure all existing rows point to this company
        $tables = ['users', 'divisions', 'job_titles', 'educations', 'shifts', 'barcodes'];
        foreach ($tables as $table) {
            DB::table($table)->whereNull('company_id')->update(['company_id' => $company->id]);
        }

        $this->command->info("Company '{$company->name}' ready (id={$company->id}).");
    }
}

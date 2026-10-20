<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\DocumentCounter;
use Illuminate\Database\Seeder;

class DocumentCounterSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('slug', 'ibe-reality')->first();
        if (!$company) {
            $this->command->warn('Run CompanySeeder first.');
            return;
        }

        $counters = [
            ['name' => 'Payroll',    'prefix' => 'GJ'],
            ['name' => 'Inventory',  'prefix' => 'INV'],
            ['name' => 'Assignment', 'prefix' => 'PNS'],
        ];

        foreach ($counters as $c) {
            DocumentCounter::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'name' => $c['name']],
                ['company_id' => $company->id, 'prefix' => $c['prefix'], 'counter' => 0]
            );
        }

        $this->command->info('Document counters seeded.');
    }
}

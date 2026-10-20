<?php

namespace Database\Seeders;

use App\Models\TaxStatus;
use Illuminate\Database\Seeder;

class TaxStatusSeeder extends Seeder
{
    /**
     * PTKP values based on PMK 101/PMK.010/2016 (current Indonesian regulation).
     * ptkp_current = year 2016+ | ptkp_2015 = PMK 122/2015 | ptkp_2009 = PMK 564/2009
     */
    public function run(): void
    {
        $statuses = [
            ['name' => 'TK/0', 'ptkp_current' => 54000000, 'ptkp_2015' => 36000000, 'ptkp_2009' => 15840000],
            ['name' => 'TK/1', 'ptkp_current' => 58500000, 'ptkp_2015' => 39000000, 'ptkp_2009' => 17160000],
            ['name' => 'TK/2', 'ptkp_current' => 63000000, 'ptkp_2015' => 42000000, 'ptkp_2009' => 18480000],
            ['name' => 'TK/3', 'ptkp_current' => 67500000, 'ptkp_2015' => 45000000, 'ptkp_2009' => 19800000],
            ['name' => 'K/0',  'ptkp_current' => 58500000, 'ptkp_2015' => 39000000, 'ptkp_2009' => 17160000],
            ['name' => 'K/1',  'ptkp_current' => 63000000, 'ptkp_2015' => 42000000, 'ptkp_2009' => 18480000],
            ['name' => 'K/2',  'ptkp_current' => 67500000, 'ptkp_2015' => 45000000, 'ptkp_2009' => 19800000],
            ['name' => 'K/3',  'ptkp_current' => 72000000, 'ptkp_2015' => 48000000, 'ptkp_2009' => 21120000],
            ['name' => 'K/I/0','ptkp_current' => 112500000,'ptkp_2015' => 75000000, 'ptkp_2009' => 0],
            ['name' => 'K/I/1','ptkp_current' => 117000000,'ptkp_2015' => 78000000, 'ptkp_2009' => 0],
            ['name' => 'K/I/2','ptkp_current' => 121500000,'ptkp_2015' => 81000000, 'ptkp_2009' => 0],
            ['name' => 'K/I/3','ptkp_current' => 126000000,'ptkp_2015' => 84000000, 'ptkp_2009' => 0],
        ];

        foreach ($statuses as $s) {
            TaxStatus::updateOrCreate(['name' => $s['name']], $s);
        }

        $this->command->info('Tax statuses (PTKP) seeded.');
    }
}

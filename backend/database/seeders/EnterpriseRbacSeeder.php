<?php

namespace Database\Seeders;

use App\Services\EnterpriseRbacProvisioningService;
use Illuminate\Database\Seeder;

class EnterpriseRbacSeeder extends Seeder
{
    public function run(EnterpriseRbacProvisioningService $provisioning): void
    {
        $provisioning->provisionAllCompanies();
    }
}

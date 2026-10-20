<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateFinanceUser extends Command
{
    protected $signature = 'users:create-finance';

    protected $description = 'Demo-only bootstrap for missing finance and HR identities; never mutates existing accounts';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])
            || env('IBE_ALLOW_DEMO_ADMIN_SEEDING') !== 'YES-I-UNDERSTAND') {
            $this->error('Demo identity bootstrap is disabled in this environment.');

            return self::FAILURE;
        }

        $company = Company::first();
        if (! $company) {
            $this->error('No company found. Please seed company first.');

            return self::FAILURE;
        }

        $financePassword = $this->validatedSecret('IBE_DEMO_FINANCE_PASSWORD');
        $ajiPassword = $this->validatedSecret('IBE_DEMO_AJI_PASSWORD');
        if ($financePassword === null || $ajiPassword === null || hash_equals($financePassword, $ajiPassword)) {
            $this->error('Distinct high-entropy demo passwords must be supplied through the environment.');

            return self::FAILURE;
        }

        $this->createMissingIdentity('finance@ibereality.com', [
            'name' => 'Admin Keuangan',
            'password' => Hash::make($financePassword),
            'group' => 'finance',
            'company_id' => $company->id,
            'phone' => '-',
            'gender' => 'male',
            'address' => '-',
            'city' => '-',
        ]);
        $this->createMissingIdentity('aji@ibereality.com', [
            'name' => 'Aji (HR Admin)',
            'password' => Hash::make($ajiPassword),
            'group' => 'admin',
            'company_id' => $company->id,
            'phone' => '-',
            'gender' => 'male',
            'address' => '-',
            'city' => '-',
        ]);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $attributes */
    private function createMissingIdentity(string $email, array $attributes): void
    {
        if (User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->exists()) {
            $this->warn("{$email} already exists and was preserved unchanged.");

            return;
        }

        User::query()->create(array_merge(['email' => $email], $attributes));
        $this->info("{$email} created for the explicitly enabled demo environment.");
    }

    private function validatedSecret(string $key): ?string
    {
        $value = env($key);
        if (! is_string($value) || strlen($value) < 20
            || preg_match('/^(password|admin|superadmin|changeme)/i', $value)) {
            return null;
        }

        return $value;
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        self::assertDemoSeedingAllowed();
        $superadminPassword = self::validatedSecret('IBE_DEMO_SUPERADMIN_PASSWORD');
        $adminPassword = self::validatedSecret('IBE_DEMO_ADMIN_PASSWORD');
        if (hash_equals($superadminPassword, $adminPassword)) {
            throw new \RuntimeException('Demo administrator passwords must be distinct.');
        }

        $identities = [
            'superadmin@example.com' => array_merge(User::factory()->admin(superadmin: true)->make()->only([
                'nip', 'name', 'phone', 'gender', 'address', 'city', 'employment_status',
            ]), [
                'company_id' => 1,
                'nip' => 'LOCAL-SUPERADMIN',
                'name' => 'Super Admin',
                'group' => 'superadmin',
                'password' => Hash::make($superadminPassword),
            ]),
            'admin@example.com' => array_merge(User::factory()->admin()->make()->only([
                'nip', 'name', 'phone', 'gender', 'address', 'city', 'employment_status',
            ]), [
                'company_id' => 1,
                'nip' => 'LOCAL-ADMIN',
                'name' => 'Admin',
                'group' => 'admin',
                'password' => Hash::make($adminPassword),
            ]),
        ];

        DB::transaction(function () use ($identities): void {
            $matches = [];
            foreach (array_keys($identities) as $email) {
                $normalizedMatches = User::query()
                    ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                    ->lockForUpdate()
                    ->limit(2)
                    ->get();

                if ($normalizedMatches->count() > 1) {
                    throw new \RuntimeException("Demo administrator identity {$email} is ambiguous after normalized email matching.");
                }

                $matches[$email] = $normalizedMatches;
            }

            foreach ($identities as $email => $attributes) {
                if ($matches[$email]->isNotEmpty()) {
                    continue;
                }

                User::query()->create(array_merge(['email' => $email], $attributes));
            }
        });
    }

    public static function assertDemoSeedingAllowed(): void
    {
        if (! app()->environment(['local', 'testing'])
            || env('IBE_ALLOW_DEMO_ADMIN_SEEDING') !== 'YES-I-UNDERSTAND') {
            throw new \RuntimeException('Demo database seeding is disabled in this environment.');
        }
    }

    private static function validatedSecret(string $key): string
    {
        $value = env($key);
        if (! is_string($value) || strlen($value) < 20
            || preg_match('/^(password|admin|superadmin|changeme)/i', $value)) {
            throw new \RuntimeException("{$key} must be supplied as a high-entropy demo-only secret.");
        }

        return $value;
    }
}

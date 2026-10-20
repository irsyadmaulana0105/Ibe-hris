<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses()->group('api', 'security', 'enterprise-rbac');

/** @param array<string, string|null> $values */
function withBootstrapEnvironment(array $values, callable $callback): mixed
{
    $original = [];
    $repository = Env::getRepository();
    foreach ($values as $key => $value) {
        $original[$key] = Env::get($key);
        $repository->clear($key);
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            $repository->set($key, $value);
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    try {
        return $callback();
    } finally {
        foreach ($original as $key => $value) {
            $repository->clear($key);
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $repository->set($key, (string) $value);
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

it('fails privileged bootstrap closed in production before changing identities or seeding data', function () {
    $company = Company::query()->create(['name' => 'Protected Company', 'slug' => 'protected-company']);
    $aji = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'aji@ibereality.com',
        'group' => 'superadmin',
        'password' => Hash::make('Existing-Aji-Secret-2026!'),
    ]);
    $beforePassword = $aji->password;
    $beforeCompanies = Company::query()->count();
    $originalEnvironment = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        withBootstrapEnvironment([
            'IBE_ALLOW_DEMO_ADMIN_SEEDING' => 'YES-I-UNDERSTAND',
            'IBE_DEMO_FINANCE_PASSWORD' => 'Finance-Demo-Only-Secret-2026!',
            'IBE_DEMO_AJI_PASSWORD' => 'Aji-Demo-Only-Secret-2026!',
        ], function () use ($beforeCompanies): void {
            expect(Artisan::call('users:create-finance'))->toBe(1);
            expect(fn () => app(AdminSeeder::class)->run())
                ->toThrow(RuntimeException::class, 'disabled');
            expect(fn () => app(DatabaseSeeder::class)->run())
                ->toThrow(RuntimeException::class, 'disabled');
            expect(Company::query()->count())->toBe($beforeCompanies);
        });
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }

    $aji->refresh();
    expect($aji->group)->toBe('superadmin')
        ->and($aji->password)->toBe($beforePassword)
        ->and(User::query()->where('email', 'finance@ibereality.com')->exists())->toBeFalse();
});

it('preserves existing Aji and finance credentials and roles when demo bootstrap is explicitly enabled', function () {
    $company = Company::query()->create(['name' => 'Demo Protected', 'slug' => 'demo-protected']);
    $aji = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'aji@ibereality.com',
        'group' => 'superadmin',
        'password' => Hash::make('Existing-Aji-Secret-2026!'),
    ]);
    $finance = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'finance@ibereality.com',
        'group' => 'finance',
        'password' => Hash::make('Existing-Finance-Secret-2026!'),
    ]);
    $before = [$aji->password, $finance->password];

    withBootstrapEnvironment([
        'IBE_ALLOW_DEMO_ADMIN_SEEDING' => 'YES-I-UNDERSTAND',
        'IBE_DEMO_FINANCE_PASSWORD' => 'Different-Finance-Demo-2026!',
        'IBE_DEMO_AJI_PASSWORD' => 'Different-Aji-Demo-Secret-2026!',
    ], function (): void {
        $exitCode = Artisan::call('users:create-finance');
        expect($exitCode, Artisan::output())->toBe(0);
    });

    expect($aji->fresh()->group)->toBe('superadmin')
        ->and($finance->fresh()->group)->toBe('finance')
        ->and($aji->fresh()->password)->toBe($before[0])
        ->and($finance->fresh()->password)->toBe($before[1]);
});

it('requires explicit strong secrets and never rewrites an existing demo administrator', function () {
    $company = Company::query()->create(['name' => 'Seeder Demo', 'slug' => 'seeder-demo']);
    $existing = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'superadmin@example.com',
        'group' => 'user',
        'password' => Hash::make('Existing-Demo-Identity-2026!'),
    ]);
    $existingPassword = $existing->password;

    withBootstrapEnvironment([
        'IBE_ALLOW_DEMO_ADMIN_SEEDING' => 'YES-I-UNDERSTAND',
        'IBE_DEMO_SUPERADMIN_PASSWORD' => 'Unique-Superadmin-Demo-2026!',
        'IBE_DEMO_ADMIN_PASSWORD' => 'Unique-Administrator-Demo-2026!',
    ], fn () => app(AdminSeeder::class)->run());

    $existing->refresh();
    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    expect($existing->group)->toBe('user')
        ->and($existing->password)->toBe($existingPassword)
        ->and(Hash::check('admin', $admin->password))->toBeFalse()
        ->and(Hash::check('superadmin', $existing->password))->toBeFalse();
});

it('preserves one case-variant demo administrator without creating a normalized duplicate', function () {
    $company = Company::query()->create(['name' => 'Seeder Case Variant', 'slug' => 'seeder-case-variant']);
    $existing = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'SUPERADMIN@EXAMPLE.COM',
        'group' => 'user',
        'password' => Hash::make('Existing-Case-Variant-2026!'),
    ]);
    $before = [$existing->group, $existing->password];

    withBootstrapEnvironment([
        'IBE_ALLOW_DEMO_ADMIN_SEEDING' => 'YES-I-UNDERSTAND',
        'IBE_DEMO_SUPERADMIN_PASSWORD' => 'Unique-Superadmin-Demo-2026!',
        'IBE_DEMO_ADMIN_PASSWORD' => 'Unique-Administrator-Demo-2026!',
    ], fn () => app(AdminSeeder::class)->run());

    expect(User::query()->whereRaw('LOWER(email) = ?', ['superadmin@example.com'])->count())->toBe(1)
        ->and($existing->fresh()->group)->toBe($before[0])
        ->and($existing->fresh()->password)->toBe($before[1]);
});

it('fails demo administrator seeding closed on ambiguous normalized email matches before mutation', function () {
    $company = Company::query()->create(['name' => 'Seeder Ambiguous', 'slug' => 'seeder-ambiguous']);
    $first = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'superadmin@example.com',
        'group' => 'user',
        'password' => Hash::make('Existing-First-Demo-2026!'),
    ]);
    $second = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'SUPERADMIN@EXAMPLE.COM',
        'group' => 'admin',
        'password' => Hash::make('Existing-Second-Demo-2026!'),
    ]);
    $before = [
        (array) DB::table('users')->where('id', $first->id)->first(['group', 'password', 'updated_at']),
        (array) DB::table('users')->where('id', $second->id)->first(['group', 'password', 'updated_at']),
    ];

    withBootstrapEnvironment([
        'IBE_ALLOW_DEMO_ADMIN_SEEDING' => 'YES-I-UNDERSTAND',
        'IBE_DEMO_SUPERADMIN_PASSWORD' => 'Unique-Superadmin-Demo-2026!',
        'IBE_DEMO_ADMIN_PASSWORD' => 'Unique-Administrator-Demo-2026!',
    ], fn () => expect(fn () => app(AdminSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'ambiguous'));

    expect((array) DB::table('users')->where('id', $first->id)->first(['group', 'password', 'updated_at']))->toBe($before[0])
        ->and((array) DB::table('users')->where('id', $second->id)->first(['group', 'password', 'updated_at']))->toBe($before[1])
        ->and(User::query()->whereRaw('LOWER(email) = ?', ['admin@example.com'])->exists())->toBeFalse();
});

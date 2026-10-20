<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Data minimum untuk programmer mengembangkan HRIS/absensi di laptop sendiri:
 * perusahaan, master data, superadmin + admin (AdminSeeder), dan SATU akun
 * karyawan untuk mencoba absen, daftar wajah, cuti, lembur.
 *
 * Hanya jalan di APP_ENV=local/testing dengan IBE_ALLOW_DEMO_ADMIN_SEEDING
 * diset; sandi diambil dari env (dibuat acak oleh skrip setup-lokal), tidak
 * pernah tertulis di kode.
 *
 *   php artisan db:seed --class=LocalHrisDevSeeder
 */
class LocalHrisDevSeeder extends Seeder
{
    public const EMPLOYEE_EMAIL = 'karyawan@example.com';

    public function run(): void
    {
        AdminSeeder::assertDemoSeedingAllowed();

        $password = (string) env('IBE_DEMO_EMPLOYEE_PASSWORD');
        if (strlen($password) < 20) {
            throw new \RuntimeException('IBE_DEMO_EMPLOYEE_PASSWORD must be supplied as a demo-only secret of at least 20 characters.');
        }

        // DatabaseSeeder sendiri tidak idempoten (shift/barcode dibuat ulang), jadi
        // hanya dijalankan pada database yang belum berisi data dasar.
        if (! User::query()->where('email', 'superadmin@example.com')->exists()) {
            $this->call(DatabaseSeeder::class);
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [self::EMPLOYEE_EMAIL])->exists()) {
            return;
        }

        User::factory()->create([
            'company_id' => 1,
            'nip' => 'LOCAL-KARYAWAN',
            'name' => 'Karyawan Uji',
            'email' => self::EMPLOYEE_EMAIL,
            'group' => 'user',
            'password' => Hash::make($password),
            // Wajah didaftarkan lewat aplikasi (Daftar Wajah) lalu disetujui admin.
            'face_descriptor' => null,
            'face_descriptor_pending' => null,
        ]);
    }
}

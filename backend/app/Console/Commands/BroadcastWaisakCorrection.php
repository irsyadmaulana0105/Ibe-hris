<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One-off: kirim email permintaan maaf + koreksi tanggal Waisak ke semua
 * karyawan. Email H-1 yang ke-trigger 2026-05-20 16:00 ngirim tanggal salah
 * (2026-05-21) - tanggal yang benar 2026-06-01.
 *
 * Jalankan SEKALI saja:
 *   php artisan holidays:broadcast-waisak-correction
 *
 * Tambah --dry-run kalau mau lihat list penerima dulu tanpa kirim.
 */
class BroadcastWaisakCorrection extends Command
{
    protected $signature = 'holidays:broadcast-waisak-correction {--dry-run : List penerima tanpa kirim email}';
    protected $description = 'One-off: kirim email koreksi + permintaan maaf untuk tanggal Waisak yang salah';

    public function handle(): int
    {
        $users = User::query()->withoutGlobalScopes()
            ->where('group', 'user')
            ->whereNotNull('email')
            ->get(['id', 'name', 'email']);

        if ($users->isEmpty()) {
            $this->warn('Tidak ada karyawan dengan email.');
            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Akan dikirim ke {$users->count()} karyawan:");
            foreach ($users as $u) {
                $this->line("  - {$u->name} <{$u->email}>");
            }
            return Command::SUCCESS;
        }

        if (!$this->confirm("Kirim email koreksi ke {$users->count()} karyawan?", true)) {
            $this->warn('Dibatalkan.');
            return Command::SUCCESS;
        }

        $sent = 0;
        $failed = [];

        foreach ($users as $u) {
            try {
                $body = $this->buildBody($u->name);
                Mail::raw($body, function ($m) use ($u) {
                    $m->to($u->email, $u->name)
                      ->subject('[IBE Reality] Mohon Maaf - Koreksi Tanggal Libur Waisak');
                });
                $sent++;
                $this->line("  ✓ {$u->email}");
            } catch (\Throwable $e) {
                $failed[] = "{$u->email} : {$e->getMessage()}";
                Log::warning("Waisak correction broadcast failed for {$u->email}: " . $e->getMessage());
                $this->line("  ✗ {$u->email} - {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Terkirim: {$sent} dari {$users->count()}");
        if (!empty($failed)) {
            $this->warn('Gagal:');
            foreach ($failed as $f) {
                $this->line("  - {$f}");
            }
        }

        return Command::SUCCESS;
    }

    private function buildBody(string $name): string
    {
        return <<<TXT
Halo {$name},

Mohon maaf, ada kekeliruan pada email pengingat libur yang kami kirim kemarin (19 Mei 2026 sore). Tanggal Hari Raya Waisak yang kami sampaikan TIDAK BENAR.

Tanggal libur yang SEHARUSNYA:

  - Senin, 1 Juni 2026  : Hari Raya Waisak 2570 BE
  - Selasa, 2 Juni 2026 : Cuti Bersama Waisak

Mohon abaikan email sebelumnya. Sistem kalender di aplikasi sudah kami perbaiki, dan pengingat H-1 akan dikirim ulang pada 31 Mei 2026 dengan tanggal yang benar.

Kami mohon maaf atas ketidaknyamanan dan kebingungan yang ditimbulkan. Terima kasih atas pengertiannya.

Salam,
HR IBE Reality
PT Integrasi Bisnis Eksekutif
TXT;
    }
}

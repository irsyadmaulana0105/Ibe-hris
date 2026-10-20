<?php

namespace App\Console\Commands;

use App\Models\Holiday;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Dipanggil scheduler setiap hari pukul 16:00 - kirim email broadcast ke
 * semua karyawan untuk libur/cuti bersama yang jatuh besok. Idempotent via
 * holidays.broadcast_sent_at flag - re-run dalam 1 hari yang sama tidak dobel.
 */
class BroadcastHolidayH1 extends Command
{
    protected $signature = 'holidays:broadcast-h1 {--force : Resend even if broadcast_sent_at is set}';
    protected $description = 'Kirim email pengingat ke karyawan untuk libur/cuti bersama H-1';

    public function handle(): int
    {
        $tomorrow = now()->addDay()->toDateString();

        $query = Holiday::query()
            ->whereDate('date', $tomorrow)
            ->whereIn('type', ['nasional', 'cuti_bersama', 'perusahaan']);
        if (!$this->option('force')) {
            $query->whereNull('broadcast_sent_at');
        }
        $holidays = $query->get();

        if ($holidays->isEmpty()) {
            $this->info("Tidak ada libur besok ({$tomorrow}) yang perlu dibroadcast.");
            return Command::SUCCESS;
        }

        foreach ($holidays as $holiday) {
            $this->broadcastOne($holiday);
        }

        return Command::SUCCESS;
    }

    private function broadcastOne(Holiday $holiday): void
    {
        $companyId = $holiday->company_id;
        $karyawan = User::query()->withoutGlobalScopes()
            ->where('group', 'user')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('email')
            ->get();

        $this->info("Broadcast '{$holiday->name}' ({$holiday->date->toDateString()}) ke {$karyawan->count()} karyawan...");

        $typeLabel = match ($holiday->type) {
            'cuti_bersama' => 'Cuti Bersama',
            'perusahaan'   => 'Libur Perusahaan',
            default        => 'Libur Nasional',
        };
        $dateLabel = $holiday->date->translatedFormat('l, j F Y');

        $sent = 0;
        foreach ($karyawan as $user) {
            try {
                $body = $this->buildBody($user->name, $typeLabel, $holiday->name, $dateLabel);
                Mail::raw($body, function ($m) use ($user, $holiday) {
                    $m->to($user->email, $user->name)
                      ->subject("[IBE Reality] Pengingat: Besok {$holiday->name} - Tidak Perlu Masuk");
                });
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("Holiday broadcast failed for {$user->email}: " . $e->getMessage());
            }
        }

        $holiday->update(['broadcast_sent_at' => now()]);
        $this->info("→ Terkirim ke {$sent} karyawan.");

        DB::table('audit_logs')->insert([
            'company_id'  => $companyId ?? 1,
            'actor_id'    => null,
            'action'      => 'holiday.broadcast_sent',
            'entity_type' => 'holiday',
            'entity_id'   => (string) $holiday->id,
            'meta'        => json_encode([
                'date'       => $holiday->date->toDateString(),
                'name'       => $holiday->name,
                'type'       => $holiday->type,
                'sent_count' => $sent,
            ]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function buildBody(string $name, string $typeLabel, string $holidayName, string $dateLabel): string
    {
        return <<<TXT
Halo {$name},

Sebagai pengingat, besok adalah hari libur:

  {$dateLabel}
  {$typeLabel}: {$holidayName}

Karyawan tidak perlu masuk kerja. Aktivitas absen via aplikasi juga otomatis ditandai libur.

Selamat menikmati hari libur!

Salam,
HR IBE Reality
TXT;
    }
}

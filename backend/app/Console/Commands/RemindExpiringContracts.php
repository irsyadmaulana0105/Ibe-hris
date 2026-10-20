<?php

namespace App\Console\Commands;

use App\Mail\ContractRenewalReminder;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Scan kontrak yang mendekati end_date + kirim reminder ke HRD + karyawan.
 * Trigger di 3 titik: 30 hari, 14 hari, 7 hari sebelum end_date.
 * State cek renewal_reminders_sent JSON supaya tidak duplicate.
 *
 * Jalankan via cron: 0 9 * * * (setiap pagi jam 9)
 * atau php artisan schedule:run kalau sudah setup Laravel scheduler.
 */
class RemindExpiringContracts extends Command
{
    protected $signature = 'contracts:remind-expiring
                            {--dry-run : Tampilkan kontrak yang akan dikirim tanpa benar-benar mengirim}
                            {--windows=30,14,7 : Comma-separated hari (default: 30, 14, 7)}';

    protected $description = 'Kirim reminder email untuk kontrak yang mendekati end_date';

    public function handle(): int
    {
        $windows = collect(explode(',', $this->option('windows')))
            ->map(fn ($v) => (int) trim($v))
            ->filter(fn ($v) => $v > 0)
            ->all();

        $isDry = (bool) $this->option('dry-run');
        $this->info(($isDry ? '[DRY RUN] ' : '') . 'Scanning kontrak untuk reminder ' . implode(', ', $windows) . ' hari…');

        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($windows as $days) {
            $target = Carbon::today()->addDays($days)->toDateString();
            $key = $days . '_day';

            $contracts = Contract::where('status', Contract::STATE_ARCHIVED)
                ->whereDate('end_date', $target)
                ->with('user:id,name,email,company_id')
                ->get();

            $this->line("  {$days}-hari (end_date = {$target}): {$contracts->count()} kontrak");

            foreach ($contracts as $c) {
                $sent = (array) ($c->renewal_reminders_sent ?? []);
                if (isset($sent[$key])) {
                    $stats['skipped']++;
                    continue;
                }

                if ($isDry) {
                    $this->line("    would-send: {$c->contract_number} → {$c->user?->name}");
                    $stats['sent']++;
                    continue;
                }

                try {
                    $this->sendReminder($c, $days);
                    $sent[$key] = now()->toDateString();
                    $c->update(['renewal_reminders_sent' => $sent]);
                    $stats['sent']++;
                } catch (\Throwable $e) {
                    Log::warning('Contract reminder failed: ' . $e->getMessage(), ['contract_id' => $c->id]);
                    $stats['failed']++;
                }
            }
        }

        $this->info("Selesai. Sent: {$stats['sent']} | Skipped: {$stats['skipped']} | Failed: {$stats['failed']}");
        return self::SUCCESS;
    }

    private function sendReminder(Contract $c, int $daysBeforeEnd): void
    {
        $karyawan = $c->user;
        if (!$karyawan) return;

        // Kirim ke karyawan
        if ($karyawan->email) {
            Mail::to($karyawan->email)->send(new ContractRenewalReminder($c, $daysBeforeEnd, $karyawan->name));
        }

        // Kirim ke HRD (semua admin/superadmin di company yang sama)
        $hrds = User::withoutGlobalScopes()
            ->where('company_id', $c->company_id)
            ->whereIn('group', ['admin', 'superadmin'])
            ->whereNotNull('email')
            ->get();

        foreach ($hrds as $hrd) {
            Mail::to($hrd->email)->send(new ContractRenewalReminder($c, $daysBeforeEnd, $hrd->name, forHrd: true));
        }
    }
}

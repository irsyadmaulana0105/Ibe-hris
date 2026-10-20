<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily check: karyawan dengan status 'training' yang training_ends_at sudah
 * lewat → flip training_review_status ke 'pending_review'. Plus log + (opsional)
 * notif HR via Telegram/email.
 *
 * Trigger:
 *   - employment_status = 'training'
 *   - training_review_status = 'active'
 *   - training_ends_at <= today
 *
 * Akibat:
 *   - User langsung di-block (BlockInactiveUser middleware lewat is_inactive)
 *   - HR dapat list "pending review" di dashboard → action: promote, extend,
 *     atau terminate
 */
class CheckTrainingExpiry extends Command
{
    protected $signature = 'training:check-expiry {--dry-run}';
    protected $description = 'Flip expired training karyawan ke pending_review (block akses sampai HR action)';

    public function handle(): int
    {
        $today = now()->startOfDay();

        $users = User::query()
            ->withoutGlobalScopes()
            ->where('employment_status', 'training')
            ->where('training_review_status', 'active')
            ->whereNotNull('training_ends_at')
            ->where('training_ends_at', '<=', $today->toDateString())
            ->get();

        if ($users->isEmpty()) {
            $this->info('No expired trainings found.');
            return self::SUCCESS;
        }

        $this->info("Found {$users->count()} expired training(s).");
        foreach ($users as $user) {
            $line = "  {$user->email} - training ended {$user->training_ends_at?->format('Y-m-d')}";
            if ($this->option('dry-run')) {
                $this->line("[DRY] $line");
                continue;
            }
            $user->forceFill(['training_review_status' => 'pending_review'])->save();
            Log::info("Training expired → pending_review: {$user->email}");
            $this->info("✓ $line → pending_review");
        }

        return self::SUCCESS;
    }
}

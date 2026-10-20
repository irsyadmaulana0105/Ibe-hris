<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Backfill: strip background dari foto profil karyawan yang sudah ada (sebelum
 * fitur auto-rembg). Skips file yang nama-nya sudah ".-nobg.png".
 *
 * Usage:
 *   php artisan photos:strip-backgrounds            (semua karyawan group=user)
 *   php artisan photos:strip-backgrounds --user=ID  (hanya satu user)
 */
class StripPhotoBackgrounds extends Command
{
    protected $signature = 'photos:strip-backgrounds {--user=}';
    protected $description = 'Strip background dari semua profile_photo_path yang ada (via rembg)';

    public function handle(): int
    {
        $binary = config('services.rembg.path', '/usr/local/bin/rembg-bg');
        if (!is_executable($binary)) {
            $this->error("Rembg binary tidak ditemukan di {$binary}");
            return Command::FAILURE;
        }

        $disk = Storage::disk('public');
        $query = User::query()->withoutGlobalScopes()
            ->whereNotNull('profile_photo_path')
            ->where('profile_photo_path', 'NOT LIKE', '%-nobg.png');
        if ($u = $this->option('user')) $query->where('id', $u);
        $users = $query->get();

        $this->info("Processing {$users->count()} foto...");
        $bar = $this->output->createProgressBar($users->count());

        foreach ($users as $user) {
            $bar->advance();
            $orig = $user->profile_photo_path;
            if (!$disk->exists($orig)) continue;

            $outName = 'profile-photos/' . pathinfo($orig, PATHINFO_FILENAME) . '-nobg.png';
            $process = new Process([$binary, $disk->path($orig), $disk->path($outName)]);
            $process->setTimeout(60);
            try {
                $process->run();
                if ($process->isSuccessful() && $disk->exists($outName)) {
                    $user->update(['profile_photo_path' => $outName]);
                } else {
                    $this->newLine();
                    $this->warn("Skip {$user->name}: " . $process->getErrorOutput());
                }
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("Skip {$user->name}: " . $e->getMessage());
            }
        }
        $bar->finish();
        $this->newLine();
        $this->info('Done.');
        return Command::SUCCESS;
    }
}

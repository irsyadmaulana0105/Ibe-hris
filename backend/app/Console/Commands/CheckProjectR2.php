<?php

namespace App\Console\Commands;

use App\Services\R2Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CheckProjectR2 extends Command
{
    protected $signature = 'projects:r2-check {--probe : Write, read, and delete a temporary R2 object}';

    protected $description = 'Validate Cloudflare R2 used by project attachments';

    public function handle(R2Service $r2): int
    {
        if (! $r2->isConfigured()) {
            $this->error('R2 configuration invalid: '.implode(', ', $r2->configurationErrors()));

            return self::FAILURE;
        }

        $this->info('R2 configuration is complete.');
        if (! $this->option('probe')) {
            $this->comment('Run with --probe on the VPS to verify bucket credentials and network access.');

            return self::SUCCESS;
        }

        $key = 'health-checks/projects/'.Str::uuid().'.txt';
        $payload = 'ibe-projects-r2-probe:'.now()->toIso8601String();
        $disk = Storage::disk('r2');

        try {
            if (! $disk->put($key, $payload)) {
                throw new \RuntimeException('write returned false');
            }
            if (! $disk->exists($key)) {
                throw new \RuntimeException('object not found after write');
            }
            if ($disk->get($key) !== $payload) {
                throw new \RuntimeException('read content mismatch');
            }
            if (! $disk->delete($key)) {
                throw new \RuntimeException('delete returned false');
            }
            if ($disk->exists($key)) {
                throw new \RuntimeException('object still exists after delete');
            }
        } catch (\Throwable $e) {
            try {
                $disk->delete($key);
            } catch (\Throwable) {
                // Best-effort cleanup; preserve the original failure below.
            }
            $this->error('R2 probe failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('R2 probe passed: write, read, and delete are operational.');

        return self::SUCCESS;
    }
}

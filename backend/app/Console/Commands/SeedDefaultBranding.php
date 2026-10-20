<?php

namespace App\Console\Commands;

use App\Services\R2Service;
use Illuminate\Console\Command;

class SeedDefaultBranding extends Command
{
    protected $signature = 'branding:seed-default';

    protected $description = 'Upload bundled IBE Reality branding assets to Cloudflare R2';

    public function handle(R2Service $r2): int
    {
        $assets = [
            'ibe-logo.png' => 'image/png',
            'ibe-logo-white.png' => 'image/png',
            'ibe-logo-round-green.png' => 'image/png',
        ];
        $source = base_path('../apps/portal/public/branding');

        foreach ($assets as $filename => $mime) {
            $path = $source.DIRECTORY_SEPARATOR.$filename;
            if (! is_file($path)) {
                $this->error("Missing bundled asset: {$path}");

                return self::FAILURE;
            }

            $url = $r2->putFile("branding/default/{$filename}", $path, $mime);
            $this->info("Uploaded {$filename}: {$url}");
        }

        return self::SUCCESS;
    }
}

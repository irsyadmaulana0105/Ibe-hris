<?php

namespace App\Surat\Services;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\File;

/**
 * Sanitize HTML untuk body surat & template menggunakan HTMLPurifier
 * (DOM-based parser, bukan regex). Lebih tahan terhadap edge case encoding
 * & nesting tricks dibanding implementasi regex sebelumnya.
 *
 * Profile dikonfig di config/purifier.php - kalau gagal load (custom profile
 * belum di-publish), fallback ke 'default' yang sudah aman.
 *
 * Tag yang diizinkan: heading, paragraph, span, div, table, list, blockquote,
 * code, strong/em/u/s, br, a, hr. Script/iframe/object/event handlers,
 * embedded resources, dan dangerous URIs otomatis di-strip.
 */
class HtmlSanitizerService
{
    /** @var array<string, HTMLPurifier> */
    private array $purifiers = [];

    public function clean(string $html): string
    {
        if ($html === '') {
            return '';
        }

        try {
            return $this->purifier('letter-body')->purify($html);
        } catch (\Throwable) {
            // The generic profile permits images, so it is not a safe fallback
            // for letters. Preserve readable text while failing closed.
            return nl2br(htmlspecialchars(
                strip_tags($html),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            ));
        }
    }

    /**
     * Contract bodies intentionally exclude images and embedded resources.
     * Letterhead, stamps, and signatures are rendered from verified private
     * assets outside the editable body, so allowing remote images here would
     * only create a tracking and supply-chain surface.
     */
    public function cleanContractTemplate(string $html): string
    {
        if ($html === '') {
            return '';
        }

        try {
            return $this->purifier('contract-template')->purify($html);
        } catch (\Throwable) {
            // Fail safe as escaped plain text if the dedicated profile cannot
            // be initialized; never return the original markup.
            return nl2br(htmlspecialchars(
                strip_tags($html),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            ));
        }
    }

    private function purifier(string $profile): HTMLPurifier
    {
        return $this->purifiers[$profile] ??= new HTMLPurifier($this->configuration($profile));
    }

    private function configuration(string $profile): HTMLPurifier_Config
    {
        $configuration = HTMLPurifier_Config::createDefault();
        $configuration->set('Core.Encoding', (string) config('purifier.encoding', 'UTF-8'));

        $cachePath = (string) config('purifier.cachePath', storage_path('app/purifier'));
        $cacheMode = (int) config('purifier.cacheFileMode', 0700);
        if ($cacheMode !== 0700) {
            throw new \RuntimeException('HTML purifier cache permissions must remain private.');
        }
        File::ensureDirectoryExists($cachePath, $cacheMode, true);
        if (DIRECTORY_SEPARATOR === '/' && ! File::chmod($cachePath, $cacheMode)) {
            throw new \RuntimeException('HTML purifier cache permissions could not be applied.');
        }
        $configuration->set('Cache.SerializerPath', $cachePath);
        $configuration->set('Cache.SerializerPermissions', $cacheMode);

        $settings = config("purifier.settings.{$profile}", config('purifier.settings.default', []));
        foreach ($settings as $key => $value) {
            $configuration->set($key, $value);
        }

        return $configuration;
    }
}

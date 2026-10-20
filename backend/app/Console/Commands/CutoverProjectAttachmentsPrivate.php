<?php

namespace App\Console\Commands;

use App\Services\ProjectAttachmentPrivateCutover;
use Illuminate\Console\Command;

final class CutoverProjectAttachmentsPrivate extends Command
{
    protected $signature = 'projects:cutover-private-attachments
        {phase : evidence|plan|copy-private|remediate-missing|switch-private|verify-private|restore-source}
        {--expectations-json= : Root-sealed evidence map required while legacy rows remain}
        {--manifest-sha256= : Root-sealed operational manifest checksum required for restore-source}
        {--apply : Required for every mutating phase}';

    protected $description = 'Release-bound, fail-closed project attachment private-storage cutover';

    public function handle(ProjectAttachmentPrivateCutover $cutover): int
    {
        $phase = (string) $this->argument('phase');
        $mutating = in_array($phase, ['copy-private', 'remediate-missing', 'switch-private', 'restore-source'], true);
        if ($mutating && ! $this->option('apply')) {
            $this->error('Mutating phase refused without --apply.');

            return self::FAILURE;
        }

        try {
            $expectationsPath = $this->option('expectations-json');
            if (is_string($expectationsPath) && $expectationsPath !== '') {
                $cutover->setExpectations($this->loadExpectations($expectationsPath));
            }
            if ($phase === 'restore-source') {
                $manifestSha256 = $this->option('manifest-sha256');
                if (! is_string($expectationsPath) || $expectationsPath === ''
                    || ! is_string($manifestSha256) || $manifestSha256 === '') {
                    throw new \RuntimeException('Restore-source requires sealed expectations and operational manifest checksum.');
                }
                $cutover->setRestoreManifestSha256($manifestSha256);
            }
            $report = $cutover->run($phase, (bool) $this->option('apply'));
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Project attachment cutover failed closed.');

            return self::FAILURE;
        }
    }

    /** @return array<string,mixed> */
    private function loadExpectations(string $path): array
    {
        $resolved = realpath($path);
        $size = $resolved !== false && is_file($resolved) ? filesize($resolved) : false;
        if ($resolved === false || $resolved !== $path || ! is_file($resolved) || is_link($path)
            || ! is_int($size) || $size < 2 || $size > 65_536) {
            throw new \RuntimeException('Project attachment evidence file is invalid.');
        }
        if (app()->environment('production') && PHP_OS_FAMILY !== 'Windows') {
            $metadata = lstat($resolved);
            $parent = dirname($resolved);
            $parentMetadata = lstat($parent);
            $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
            $runtimeHandoff = $euid === 10001
                && is_array($metadata) && is_array($parentMetadata)
                && ($metadata['mode'] & 0170000) === 0100000
                && (int) $metadata['uid'] === 0
                && (int) $metadata['gid'] === 10001
                && (int) $metadata['nlink'] === 1
                && str_ends_with(
                    str_replace('\\', '/', $resolved),
                    '/private/cutovers/'.ProjectAttachmentPrivateCutover::RELEASE.'/evidence/project-attachment-expectations.json',
                )
                && ($metadata['mode'] & 0777) === 0440
                && ($parentMetadata['mode'] & 0170000) === 0040000
                && (int) $parentMetadata['uid'] === 0
                && (int) $parentMetadata['gid'] === 10001
                && ($parentMetadata['mode'] & 0777) === 0550
                && ! is_writable($resolved)
                && ! is_writable($parent)
                && $this->hasImmutableAttribute($resolved);
            if (! $runtimeHandoff) {
                throw new \RuntimeException('Project attachment evidence file seal metadata is invalid.');
            }
        }
        $json = file_get_contents($resolved);
        $decoded = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($decoded)) {
            throw new \RuntimeException('Project attachment evidence JSON is invalid.');
        }

        return $decoded;
    }

    private function hasImmutableAttribute(string $path): bool
    {
        $output = [];
        $status = 1;
        exec('/usr/bin/lsattr -d -- '.escapeshellarg($path), $output, $status);
        if ($status !== 0 || count($output) !== 1) {
            return false;
        }
        $attributes = preg_split('/\s+/', trim($output[0]), 2)[0] ?? '';

        return str_contains($attributes, 'i');
    }
}

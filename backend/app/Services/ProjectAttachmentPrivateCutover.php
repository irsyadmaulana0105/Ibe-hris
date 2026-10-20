<?php

namespace App\Services;

use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ProjectAttachmentPrivateCutover
{
    public const RELEASE = '20260809-project-attachments-private-v1';

    private const MANIFEST = 'private/cutovers/'.self::RELEASE.'/manifest.json';

    private const RUNTIME_MANIFEST = 'private/cutovers/'.self::RELEASE.'/runtime/runtime-manifest.json';

    private const RUNTIME_MANIFEST_SHA256 = 'private/cutovers/'.self::RELEASE.'/runtime/runtime-manifest.sha256';

    private const RUNTIME_MANIFEST_MAX_BYTES = 65536;

    /** @var array<string,array{descriptor_sha256:string,disposition:string,content_sha256?:string}> */
    private array $expectations = [];

    private ?string $expectationsSha256 = null;

    private ?string $restoreManifestSha256 = null;

    public function __construct(private readonly ProjectAttachmentPrivateStorage $storage) {}

    public function setExpectations(array $expectations): void
    {
        $normalized = [];
        foreach ($expectations as $id => $expectation) {
            $idText = (string) $id;
            $idValue = filter_var($idText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $keys = is_array($expectation) ? array_keys($expectation) : [];
            sort($keys, SORT_STRING);
            if ($idValue === false || (string) $idValue !== $idText || ! is_array($expectation)) {
                throw new RuntimeException('Project attachment cutover expectation key is invalid.');
            }
            $descriptor = $expectation['descriptor_sha256'] ?? null;
            $disposition = $expectation['disposition'] ?? null;
            $content = $expectation['content_sha256'] ?? null;
            $expectedKeys = $disposition === 'copy'
                ? ['content_sha256', 'descriptor_sha256', 'disposition']
                : ['descriptor_sha256', 'disposition'];
            if ($keys !== $expectedKeys
                || ! is_string($descriptor) || ! is_string($disposition)
                || preg_match('/\A[a-f0-9]{64}\z/D', $descriptor) !== 1
                || ! in_array($disposition, ['copy', 'missing'], true)
                || ($disposition === 'copy'
                    && (! is_string($content) || preg_match('/\A[a-f0-9]{64}\z/D', $content) !== 1))
                || ($disposition === 'missing' && $content !== null)) {
                throw new RuntimeException('Project attachment cutover expectation is invalid.');
            }
            $normalized[(string) $idValue] = [
                'descriptor_sha256' => $descriptor,
                'disposition' => $disposition,
                ...($content !== null ? ['content_sha256' => $content] : []),
            ];
        }
        ksort($normalized, SORT_STRING);
        $this->expectations = $normalized;
        $this->expectationsSha256 = hash(
            'sha256',
            json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public function setRestoreManifestSha256(string $sha256): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            throw new RuntimeException('Project attachment restore manifest checksum is invalid.');
        }

        $this->restoreManifestSha256 = $sha256;
    }

    /** @return array<string,mixed> */
    public function run(string $phase, bool $apply = false): array
    {
        $mutating = in_array($phase, ['copy-private', 'remediate-missing', 'switch-private', 'restore-source'], true);
        if ($mutating && ! $apply) {
            throw new RuntimeException('Mutating project attachment cutover phases require explicit --apply confirmation.');
        }

        $runner = fn (): array => match ($phase) {
            'evidence' => $this->evidence(),
            'plan' => $this->plan(),
            'copy-private' => $this->copyPrivate(),
            'remediate-missing' => $this->remediateMissing(),
            'switch-private' => $this->switchPrivate(),
            'verify-private' => $this->verifyPrivate(),
            'restore-source' => $this->restoreSource(),
            default => throw new RuntimeException('Unknown project attachment cutover phase.'),
        };

        return $mutating
            ? Cache::lock('cutover-lock:'.self::RELEASE.':global', 900)->block(1, $runner)
            : $runner();
    }

    private function evidence(): array
    {
        $rows = $this->rows();
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'attachment_id' => (int) $row->id,
                'state' => $this->isUnavailable($row)
                    ? 'unavailable'
                    : ($row->storage_provider === ProjectAttachmentPrivateStorage::PROVIDER ? 'private' : 'legacy'),
                'descriptor_sha256' => $this->descriptorSha256($row),
            ];
        }

        return [
            'phase' => 'evidence',
            'total' => count($entries),
            'entries' => $entries,
            'ok' => true,
        ];
    }

    private function plan(): array
    {
        $rows = $this->rows();
        $this->assertExpectationCoverage($rows);
        $report = $this->report('plan', count($rows));
        foreach ($rows as $row) {
            try {
                if ($this->isUnavailable($row)) {
                    $report['unavailable']++;

                    continue;
                }
                if ($row->storage_provider === ProjectAttachmentPrivateStorage::PROVIDER) {
                    $this->verifyRow($row);
                    $report['private']++;
                } else {
                    $this->assertSupportedSource($row);
                    $expectation = $this->expectation($row);
                    if ($expectation['disposition'] === 'missing') {
                        $this->assertSourceMissing($row);
                        $report['unavailable']++;
                    } else {
                        $report['source']++;
                    }
                }
            } catch (\Throwable) {
                $report['errors']++;
            }
        }
        $report['ok'] = $report['errors'] === 0;

        return $report;
    }

    private function copyPrivate(): array
    {
        $manifestExisted = Storage::disk('local')->exists(self::MANIFEST);
        $manifest = $this->manifest();
        $originalManifest = $manifest;
        $this->bindManifestExpectations($manifest);
        $rows = $this->rows();
        $this->assertExpectationCoverage($rows);
        $report = $this->report('copy-private', count($rows));
        $createdPaths = [];

        foreach ($rows as $row) {
            try {
                if ($row->storage_provider === ProjectAttachmentPrivateStorage::PROVIDER) {
                    $this->verifyRow($row);
                    $report['private']++;

                    continue;
                }

                $this->assertSupportedSource($row);
                $expectation = $this->expectation($row);
                $key = (string) $row->id;
                $existing = $manifest['entries'][$key] ?? null;
                if ($expectation['disposition'] === 'missing') {
                    $this->assertSourceMissing($row);
                    $manifest['entries'][$key] = $this->sourceEntry($row) + [
                        'disposition' => 'missing',
                        'new_path' => null,
                        'new_sha256' => null,
                        'size' => (int) $row->size_bytes,
                        'mime' => (string) $row->mime_type,
                        'copied_at' => null,
                        'switched_at' => null,
                    ];
                    $report['unavailable']++;

                    continue;
                }
                if (is_array($existing) && $this->manifestPrivateValid($row, $existing, (string) $expectation['content_sha256'])) {
                    $report['reused']++;

                    continue;
                }

                $bytes = $this->downloadSource($row);
                if (! hash_equals((string) $expectation['content_sha256'], hash('sha256', $bytes))) {
                    throw new RuntimeException('Legacy R2 source checksum differs from approved evidence.');
                }
                $stored = $this->storage->storeBytes(
                    $bytes,
                    (string) $row->original_name,
                    (string) $row->mime_type,
                    (int) $row->tenant_id,
                    (int) $row->project_id,
                    (int) $row->task_id,
                );
                $createdPaths[] = $stored['path'];
                if ((int) $row->size_bytes !== (int) $stored['size']) {
                    Storage::disk('local')->delete($stored['path']);
                    throw new RuntimeException('Source attachment size differs from database metadata.');
                }

                $manifest['entries'][$key] = $this->sourceEntry($row) + [
                    'disposition' => 'copy',
                    'new_path' => $stored['path'],
                    'new_sha256' => $stored['sha256'],
                    'expected_content_sha256' => (string) $expectation['content_sha256'],
                    'size' => $stored['size'],
                    'mime' => $stored['mime'],
                    'copied_at' => now()->toIso8601String(),
                    'switched_at' => null,
                ];
                $report['copied']++;
            } catch (\Throwable) {
                $report['errors']++;
            }
        }

        try {
            $this->saveManifest($manifest);
        } catch (\Throwable $failure) {
            $cleanupFailed = false;
            foreach (array_reverse($createdPaths) as $path) {
                Storage::disk('local')->delete($path);
                $cleanupFailed = $cleanupFailed || Storage::disk('local')->exists($path);
            }
            try {
                $this->restoreManifest($manifestExisted, $originalManifest);
            } catch (\Throwable) {
                $cleanupFailed = true;
            }
            if ($cleanupFailed) {
                throw new RuntimeException('Project attachment copy failed and compensating cleanup was incomplete.', 0, $failure);
            }
            throw $failure;
        }
        $report['ok'] = $report['errors'] === 0;

        return $report;
    }

    private function remediateMissing(): array
    {
        $manifestExisted = Storage::disk('local')->exists(self::MANIFEST);
        $manifest = $this->manifest();
        $originalManifest = $manifest;
        $rows = $this->rows();
        $this->assertExpectationCoverage($rows);
        $report = $this->report('remediate-missing', count($rows));
        $updates = [];

        foreach ($rows as $row) {
            try {
                if ($this->isUnavailable($row)) {
                    $report['unavailable']++;

                    continue;
                }
                if ($row->storage_provider === ProjectAttachmentPrivateStorage::PROVIDER) {
                    $this->verifyRow($row);
                    $report['private']++;

                    continue;
                }
                $this->assertSupportedSource($row);
                $expectation = $this->expectation($row);
                if ($expectation['disposition'] !== 'missing') {
                    continue;
                }
                $this->assertSourceMissing($row);
                $entry = $manifest['entries'][(string) $row->id] ?? null;
                if (! is_array($entry) || ($entry['disposition'] ?? null) !== 'missing'
                    || ! hash_equals((string) $expectation['descriptor_sha256'], (string) ($entry['descriptor_sha256'] ?? ''))) {
                    throw new RuntimeException('Missing source has no matching evidence-bound manifest entry.');
                }
                $updates[] = $row;
            } catch (\Throwable) {
                $report['errors']++;
            }
        }
        if ($report['errors'] > 0) {
            $report['ok'] = false;

            return $report;
        }

        try {
            DB::transaction(function () use ($updates, &$manifest): void {
                foreach ($updates as $row) {
                    $changed = DB::table('task_attachments')
                        ->where('id', $row->id)
                        ->where('storage_provider', $row->storage_provider)
                        ->where('path', $row->path)
                        ->update([
                            'storage_provider' => 'unavailable',
                            'path' => '',
                            'content_sha256' => null,
                            'drive_file_id' => null,
                            'drive_web_view_link' => null,
                            'drive_download_link' => null,
                            'source_tombstoned_at' => now(),
                            'source_tombstone_reason' => 'verified_source_missing',
                            'updated_at' => now(),
                        ]);
                    if ($changed !== 1) {
                        throw new RuntimeException('Concurrent missing-source remediation drift detected.');
                    }
                    $manifest['entries'][(string) $row->id]['switched_at'] = now()->toIso8601String();
                }
                $this->saveManifest($manifest);
            });
        } catch (\Throwable $failure) {
            try {
                $this->restoreManifest($manifestExisted, $originalManifest);
            } catch (\Throwable) {
                throw new RuntimeException('Missing-source remediation failed and manifest rollback was incomplete.', 0, $failure);
            }
            throw $failure;
        }
        $report['unavailable'] += count($updates);
        $report['ok'] = true;

        return $report;
    }

    private function switchPrivate(): array
    {
        $manifestExisted = Storage::disk('local')->exists(self::MANIFEST);
        $manifest = $this->manifest();
        $originalManifest = $manifest;
        $rows = $this->rows();
        $this->assertExpectationCoverage($rows);
        $report = $this->report('switch-private', count($rows));
        $updates = [];

        foreach ($rows as $row) {
            try {
                if ($this->isUnavailable($row)) {
                    $report['unavailable']++;

                    continue;
                }
                if ($row->storage_provider === ProjectAttachmentPrivateStorage::PROVIDER) {
                    $this->verifyRow($row);
                    $report['private']++;

                    continue;
                }
                $this->assertSupportedSource($row);
                if ($this->expectation($row)['disposition'] === 'missing') {
                    throw new RuntimeException('Missing legacy source must complete remediate-missing before switch.');
                }
                $entry = $manifest['entries'][(string) $row->id] ?? null;
                if (! is_array($entry) || ! $this->manifestPrivateValid(
                    $row,
                    $entry,
                    (string) $this->expectation($row)['content_sha256'],
                )) {
                    throw new RuntimeException('Attachment has not completed verified copy-private.');
                }
                $updates[] = [$row, $entry];
            } catch (\Throwable) {
                $report['errors']++;
            }
        }
        if ($report['errors'] > 0) {
            $report['ok'] = false;

            return $report;
        }

        try {
            DB::transaction(function () use ($updates, &$manifest): void {
                foreach ($updates as [$row, $entry]) {
                    $changed = DB::table('task_attachments')
                        ->where('id', $row->id)
                        ->where('storage_provider', $row->storage_provider)
                        ->where('path', $row->path)
                        ->update([
                            'storage_provider' => ProjectAttachmentPrivateStorage::PROVIDER,
                            'path' => $entry['new_path'],
                            'content_sha256' => $entry['new_sha256'],
                            'drive_file_id' => null,
                            'drive_web_view_link' => null,
                            'drive_download_link' => null,
                            'updated_at' => now(),
                        ]);
                    if ($changed !== 1) {
                        throw new RuntimeException('Concurrent project attachment cutover drift detected.');
                    }
                    $manifest['entries'][(string) $row->id]['switched_at'] = now()->toIso8601String();
                }
                $this->saveManifest($manifest);
            });
        } catch (\Throwable $failure) {
            try {
                $this->restoreManifest($manifestExisted, $originalManifest);
            } catch (\Throwable) {
                throw new RuntimeException('Project attachment switch failed and manifest rollback was incomplete.', 0, $failure);
            }
            throw $failure;
        }
        $report['switched'] = count($updates);
        $report['ok'] = true;

        return $report;
    }

    private function verifyPrivate(): array
    {
        $rows = $this->rows();
        $report = $this->report('verify-private', count($rows));
        foreach ($rows as $row) {
            try {
                if ($this->isUnavailable($row)) {
                    $report['unavailable']++;

                    continue;
                }
                if ($row->storage_provider !== ProjectAttachmentPrivateStorage::PROVIDER) {
                    throw new RuntimeException('Non-private project attachment remains.');
                }
                $this->verifyRow($row);
                $report['private']++;
            } catch (\Throwable) {
                $report['errors']++;
            }
        }
        $report['ok'] = $report['errors'] === 0;

        return $report;
    }

    private function restoreSource(): array
    {
        if ($this->restoreManifestSha256 === null || $this->expectationsSha256 === null) {
            throw new RuntimeException('Project attachment restore requires sealed manifest and source expectations.');
        }
        $manifestBytes = Storage::disk('local')->get(self::MANIFEST);
        if (! hash_equals($this->restoreManifestSha256, hash('sha256', $manifestBytes))) {
            throw new RuntimeException('Project attachment restore manifest checksum drifted.');
        }
        $manifest = json_decode($manifestBytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest)
            || ($manifest['schema'] ?? null) !== self::RELEASE
            || ! is_array($manifest['entries'] ?? null)) {
            throw new RuntimeException('Project attachment restore manifest is invalid.');
        }
        $rows = $this->rows();
        $this->assertRestoreCoverage($rows, $manifest);
        $report = $this->report('restore-source', count($rows));

        DB::transaction(function () use (&$report, $manifest, $rows): void {
            foreach ($rows as $row) {
                $entry = $manifest['entries'][(string) $row->id];
                $expectation = $this->expectations[(string) $row->id];
                $legacy = $this->validateRestoreEntry($row, $entry, $expectation);
                if ($this->descriptorSha256($row) === $expectation['descriptor_sha256']
                    && $row->content_sha256 === null
                    && $row->source_tombstoned_at === null
                    && $row->source_tombstone_reason === null) {
                    $report['restored']++;

                    continue;
                }

                $query = DB::table('task_attachments')
                    ->where('id', $row->id)
                    ->where('task_id', $row->task_id)
                    ->where('storage_provider', $row->storage_provider)
                    ->where('path', $row->path)
                    ->where('original_name', $row->original_name)
                    ->where('mime_type', $row->mime_type)
                    ->where('size_bytes', $row->size_bytes)
                    ->whereNull('drive_file_id')
                    ->whereNull('drive_web_view_link')
                    ->whereNull('drive_download_link');
                if ($expectation['disposition'] === 'missing') {
                    if (! $this->isUnavailable($row) || $row->content_sha256 !== null) {
                        throw new RuntimeException('Missing-source restore refused because tombstone drifted.');
                    }
                    $query->where('source_tombstone_reason', 'verified_source_missing')
                        ->whereNotNull('source_tombstoned_at')
                        ->whereNull('content_sha256');
                } else {
                    if ($row->storage_provider !== ProjectAttachmentPrivateStorage::PROVIDER
                        || $row->path !== $entry['new_path']
                        || ! is_string($row->content_sha256)
                        || ! hash_equals($entry['new_sha256'], $row->content_sha256)
                        || $row->source_tombstoned_at !== null
                        || $row->source_tombstone_reason !== null) {
                        throw new RuntimeException('Project attachment restore refused because live state drifted.');
                    }
                    $stream = $this->storage->readVerified(
                        (int) $row->tenant_id,
                        (int) $row->project_id,
                        (int) $row->task_id,
                        $entry['new_path'],
                        $entry['new_sha256'],
                        (string) $row->original_name,
                        (string) $row->mime_type,
                        (int) $row->size_bytes,
                    );
                    fclose($stream);
                    $query->where('content_sha256', $entry['new_sha256'])
                        ->whereNull('source_tombstoned_at')
                        ->whereNull('source_tombstone_reason');
                }
                $changed = $query->update([
                    'storage_provider' => $legacy->storage_provider,
                    'path' => $legacy->path,
                    'content_sha256' => null,
                    'drive_file_id' => $legacy->drive_file_id,
                    'drive_web_view_link' => $legacy->drive_web_view_link,
                    'drive_download_link' => $legacy->drive_download_link,
                    'source_tombstoned_at' => null,
                    'source_tombstone_reason' => null,
                    'updated_at' => now(),
                ]);
                if ($changed !== 1) {
                    throw new RuntimeException('Project attachment restore failed because the row changed concurrently.');
                }
                $report['restored']++;
            }
        });
        $report['ok'] = true;

        return $report;
    }

    /** @param list<object> $rows */
    private function assertRestoreCoverage(array $rows, array $manifest): void
    {
        $manifestKeys = array_keys($manifest);
        sort($manifestKeys, SORT_STRING);
        if ($manifestKeys !== ['entries', 'expectations_sha256', 'schema']
            || ! is_string($manifest['expectations_sha256'] ?? null)
            || ! hash_equals($this->expectationsSha256, $manifest['expectations_sha256'])) {
            throw new RuntimeException('Project attachment restore manifest expectation binding drifted.');
        }
        $rowIds = array_map(static fn (object $row): string => (string) $row->id, $rows);
        $manifestIds = array_map('strval', array_keys($manifest['entries']));
        $expectationIds = array_map('strval', array_keys($this->expectations));
        sort($rowIds, SORT_STRING);
        sort($manifestIds, SORT_STRING);
        sort($expectationIds, SORT_STRING);
        if ($rowIds !== $manifestIds || $rowIds !== $expectationIds) {
            throw new RuntimeException('Project attachment restore coverage is not exact.');
        }
    }

    private function validateRestoreEntry(object $row, mixed $entry, array $expectation): object
    {
        if (! is_array($entry)) {
            throw new RuntimeException('Project attachment restore entry is invalid.');
        }
        $keys = array_keys($entry);
        sort($keys, SORT_STRING);
        $expectedKeys = [
            'attachment_id', 'copied_at', 'descriptor_sha256', 'disposition', 'mime', 'new_path',
            'new_sha256', 'old_drive_download_link', 'old_drive_file_id', 'old_drive_web_view_link',
            'old_path', 'old_provider', 'project_id', 'size', 'switched_at', 'task_id', 'tenant_id',
        ];
        if (($expectation['disposition'] ?? null) === 'copy') {
            $expectedKeys[] = 'expected_content_sha256';
        }
        sort($expectedKeys, SORT_STRING);
        foreach (['old_drive_download_link', 'old_drive_file_id', 'old_drive_web_view_link'] as $field) {
            if (($entry[$field] ?? null) !== null && ! is_string($entry[$field])) {
                throw new RuntimeException('Project attachment restore legacy metadata type drifted.');
            }
        }
        if ($keys !== $expectedKeys
            || ! is_int($entry['attachment_id'] ?? null)
            || ! is_int($entry['tenant_id'] ?? null)
            || ! is_int($entry['project_id'] ?? null)
            || ! is_int($entry['task_id'] ?? null)
            || ! is_int($entry['size'] ?? null)
            || ! is_string($entry['descriptor_sha256'] ?? null)
            || ! is_string($entry['old_provider'] ?? null)
            || ! is_string($entry['old_path'] ?? null)
            || ! is_string($entry['mime'] ?? null)
            || $entry['attachment_id'] !== (int) $row->id
            || $entry['tenant_id'] !== (int) $row->tenant_id
            || $entry['project_id'] !== (int) $row->project_id
            || $entry['task_id'] !== (int) $row->task_id
            || $entry['size'] !== (int) $row->size_bytes
            || $entry['mime'] !== (string) $row->mime_type
            || ($entry['disposition'] ?? null) !== ($expectation['disposition'] ?? null)) {
            throw new RuntimeException('Project attachment restore identity binding drifted.');
        }
        $legacy = (object) [
            ...get_object_vars($row),
            'storage_provider' => $entry['old_provider'],
            'path' => $entry['old_path'],
            'drive_file_id' => $entry['old_drive_file_id'],
            'drive_web_view_link' => $entry['old_drive_web_view_link'],
            'drive_download_link' => $entry['old_drive_download_link'],
        ];
        $this->assertSupportedSource($legacy);
        $descriptor = $this->descriptorSha256($legacy);
        if (! hash_equals($expectation['descriptor_sha256'], $descriptor)
            || ! hash_equals($descriptor, $entry['descriptor_sha256'])) {
            throw new RuntimeException('Project attachment restore legacy descriptor drifted.');
        }
        if ($expectation['disposition'] === 'copy') {
            foreach (['expected_content_sha256', 'new_sha256', 'new_path', 'copied_at'] as $field) {
                if (! is_string($entry[$field] ?? null) || $entry[$field] === '') {
                    throw new RuntimeException('Project attachment restore copy binding is incomplete.');
                }
            }
            if (! hash_equals($expectation['content_sha256'], $entry['expected_content_sha256'])
                || ! hash_equals($expectation['content_sha256'], $entry['new_sha256'])) {
                throw new RuntimeException('Project attachment restore content binding drifted.');
            }
        } elseif ($entry['new_path'] !== null || $entry['new_sha256'] !== null || $entry['copied_at'] !== null) {
            throw new RuntimeException('Project attachment missing-source restore entry drifted.');
        }

        return $legacy;
    }

    /** @return list<object> */
    private function rows(): array
    {
        $rows = DB::table('task_attachments as a')
            ->leftJoin('tasks as t', 't.id', '=', 'a.task_id')
            ->leftJoin('projects as p', 'p.id', '=', 't.project_id')
            ->select('a.*', 't.id as resolved_task_id', 'p.id as project_id', 'p.company_id as tenant_id')
            ->orderBy('a.id')
            ->get()->all();
        foreach ($rows as $row) {
            if ($row->resolved_task_id === null || $row->project_id === null || $row->tenant_id === null) {
                throw new RuntimeException('Orphan project attachment relationship blocks private cutover.');
            }
        }

        return $rows;
    }

    private function rowById(int $id): object
    {
        foreach ($this->rows() as $row) {
            if ((int) $row->id === $id) {
                return $row;
            }
        }
        throw new RuntimeException('Project attachment row disappeared during cutover.');
    }

    private function verifyRow(object $row): void
    {
        $stream = $this->storage->readVerified(
            (int) $row->tenant_id,
            (int) $row->project_id,
            (int) $row->task_id,
            (string) $row->path,
            $row->content_sha256,
            (string) $row->original_name,
            (string) $row->mime_type,
            (int) $row->size_bytes,
        );
        fclose($stream);
    }

    private function manifestPrivateValid(object $row, array $entry, ?string $expectedContentSha256 = null): bool
    {
        try {
            $descriptorSha256 = $this->descriptorSha256($row);
            $sealedExpectedSha256 = (string) ($entry['expected_content_sha256'] ?? '');
            if (($entry['disposition'] ?? null) !== 'copy'
                || (int) $entry['attachment_id'] !== (int) $row->id
                || (int) $entry['tenant_id'] !== (int) $row->tenant_id
                || (int) $entry['project_id'] !== (int) $row->project_id
                || (int) $entry['task_id'] !== (int) $row->task_id
                || (int) $entry['size'] !== (int) $row->size_bytes
                || (string) $entry['mime'] !== (string) $row->mime_type
                || ! hash_equals($descriptorSha256, (string) ($entry['descriptor_sha256'] ?? ''))
                || preg_match('/\A[a-f0-9]{64}\z/D', $sealedExpectedSha256) !== 1
                || ! hash_equals($sealedExpectedSha256, (string) ($entry['new_sha256'] ?? ''))
                || ($expectedContentSha256 !== null && ! hash_equals($expectedContentSha256, $sealedExpectedSha256))) {
                return false;
            }
            $stream = $this->storage->readVerified(
                (int) $entry['tenant_id'],
                (int) $entry['project_id'],
                (int) $entry['task_id'],
                (string) $entry['new_path'],
                (string) $entry['new_sha256'],
                (string) $row->original_name,
                (string) $row->mime_type,
                (int) $row->size_bytes,
            );
            fclose($stream);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return resource */
    public function readVerifiedLegacyCopy(
        TaskAttachment $attachment,
        int $tenantId,
        int $projectId,
        int $taskId,
    ) {
        $row = (object) [
            ...$attachment->getAttributes(),
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'resolved_task_id' => $taskId,
        ];
        if ($attachment->storage_provider !== 'r2'
            || (int) $attachment->task_id !== $taskId
            || $tenantId < 1 || $projectId < 1 || $taskId < 1) {
            throw new RuntimeException('Legacy attachment is outside the copy-only bridge contract.');
        }
        $this->assertSupportedSource($row);
        $manifest = $this->runtimeManifest();
        $entry = $manifest['entries'][(string) $attachment->id] ?? null;
        if (! is_array($entry) || ! $this->runtimeEntryPrivateValid($row, $entry)) {
            throw new RuntimeException('Legacy attachment private copy is not evidence-bound.');
        }

        return $this->storage->readVerified(
            $tenantId,
            $projectId,
            $taskId,
            (string) $entry['new_path'],
            (string) $entry['new_sha256'],
            (string) $attachment->original_name,
            (string) ($attachment->mime_type ?: 'application/octet-stream'),
            (int) $attachment->size_bytes,
        );
    }

    private function assertSupportedSource(object $row): void
    {
        if ($row->storage_provider !== 'r2' || ! is_string($row->drive_download_link) || $row->drive_download_link === '') {
            throw new RuntimeException('Only evidence-bound R2 source rows are accepted by this release cutover.');
        }
        $parts = parse_url($row->drive_download_link);
        $hosts = array_map('strtolower', (array) config('project_attachments.legacy_public_hosts', []));
        if (($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || ! in_array(strtolower($parts['host']), $hosts, true)
            || (isset($parts['port']) && $parts['port'] !== 443)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Legacy R2 source URL is outside the approved HTTPS hosts.');
        }
    }

    private function downloadSource(object $row): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'ibe-project-attachment-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to allocate bounded source download file.');
        }
        @chmod($temporary, 0600);
        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'sink' => $temporary,
                'progress' => static function (int $total, int $downloaded): void {
                    if ($total > ProjectAttachmentPrivateStorage::MAX_BYTES
                        || $downloaded > ProjectAttachmentPrivateStorage::MAX_BYTES) {
                        throw new RuntimeException('Legacy R2 source exceeds the private attachment limit.');
                    }
                },
            ])->accept('*/*')->connectTimeout(5)->timeout(30)
                ->get((string) $row->drive_download_link);
            if (! $response->successful()) {
                throw new RuntimeException('Legacy R2 source fetch failed.');
            }
            clearstatcache(true, $temporary);
            if ((int) filesize($temporary) === 0 && $response->body() !== '') {
                $body = $response->body();
                if (strlen($body) > ProjectAttachmentPrivateStorage::MAX_BYTES
                    || file_put_contents($temporary, $body, LOCK_EX) === false) {
                    throw new RuntimeException('Legacy R2 source exceeds the private attachment limit.');
                }
            }
            $size = (int) filesize($temporary);
            if ($size < 1 || $size > ProjectAttachmentPrivateStorage::MAX_BYTES) {
                throw new RuntimeException('Legacy R2 source size is invalid.');
            }
            $bytes = file_get_contents($temporary);
            if (! is_string($bytes)) {
                throw new RuntimeException('Legacy R2 source cannot be read after download.');
            }
            $this->storage->validate($bytes, (string) $row->original_name, (string) $row->mime_type);

            return $bytes;
        } finally {
            @unlink($temporary);
        }
    }

    private function manifest(): array
    {
        if (! Storage::disk('local')->exists(self::MANIFEST)) {
            return ['schema' => self::RELEASE, 'expectations_sha256' => null, 'entries' => []];
        }
        $decoded = json_decode(Storage::disk('local')->get(self::MANIFEST), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ($decoded['schema'] ?? null) !== self::RELEASE || ! is_array($decoded['entries'] ?? null)) {
            throw new RuntimeException('Project attachment cutover manifest is invalid.');
        }

        return $decoded;
    }

    private function bindManifestExpectations(array &$manifest): void
    {
        if (! is_string($this->expectationsSha256)
            || preg_match('/\A[a-f0-9]{64}\z/D', $this->expectationsSha256) !== 1) {
            throw new RuntimeException('Project attachment expectation set is not sealed.');
        }
        $current = $manifest['expectations_sha256'] ?? null;
        if ($current !== null && ! hash_equals($this->expectationsSha256, (string) $current)) {
            throw new RuntimeException('Project attachment manifest expectation binding drifted.');
        }
        $manifest['expectations_sha256'] = $this->expectationsSha256;
    }

    private function runtimeManifest(): array
    {
        $path = Storage::disk('local')->path(self::RUNTIME_MANIFEST);
        $shaPath = Storage::disk('local')->path(self::RUNTIME_MANIFEST_SHA256);
        clearstatcache(true, $path);
        clearstatcache(true, $shaPath);
        $manifestSize = is_file($path) ? filesize($path) : false;
        $sidecarSize = is_file($shaPath) ? filesize($shaPath) : false;
        if (! is_int($manifestSize) || $manifestSize < 1 || $manifestSize > self::RUNTIME_MANIFEST_MAX_BYTES
            || $sidecarSize !== 88
            || ! is_file($path) || is_link($path) || ! is_file($shaPath) || is_link($shaPath)
            || (! app()->environment('testing')
                && (realpath($path) !== $path || realpath($shaPath) !== $shaPath))) {
            throw new RuntimeException('Legacy attachment manifest is not a regular sealed file.');
        }
        $sidecar = file_get_contents($shaPath);
        if (! is_string($sidecar)
            || preg_match('/\A([a-f0-9]{64})  runtime-manifest\.json\n\z/D', $sidecar, $match) !== 1
            || ! hash_equals($match[1], hash_file('sha256', $path))) {
            throw new RuntimeException('Legacy attachment manifest checksum is not trusted.');
        }
        if (! app()->environment('testing')) {
            $metadata = lstat($path);
            $shaMetadata = lstat($shaPath);
            $parentMetadata = lstat(dirname($path));
            if (! is_array($metadata)
                || ! is_array($shaMetadata)
                || ! is_array($parentMetadata)
                || ($metadata['mode'] & 0170000) !== 0100000
                || ($metadata['mode'] & 0777) !== 0440
                || (int) $metadata['uid'] !== 0
                || (int) $metadata['gid'] !== 10001
                || (int) $metadata['nlink'] !== 1
                || is_writable($path)
                || ($shaMetadata['mode'] & 0170000) !== 0100000
                || ($shaMetadata['mode'] & 0777) !== 0440
                || (int) $shaMetadata['uid'] !== 0
                || (int) $shaMetadata['gid'] !== 10001
                || (int) $shaMetadata['nlink'] !== 1
                || is_writable($shaPath)
                || ($parentMetadata['mode'] & 0170000) !== 0040000
                || ($parentMetadata['mode'] & 0777) !== 0550
                || (int) $parentMetadata['uid'] !== 0
                || (int) $parentMetadata['gid'] !== 10001
                || is_writable(dirname($path))
                || ! $this->hasImmutableAttribute($path)
                || ! $this->hasImmutableAttribute($shaPath)) {
                throw new RuntimeException('Legacy attachment manifest seal metadata is invalid.');
            }
        }
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $manifestKeys = is_array($manifest) ? array_keys($manifest) : [];
        sort($manifestKeys, SORT_STRING);
        if (! is_array($manifest)
            || $manifestKeys !== ['entries', 'expectations_sha256', 'schema']
            || ($manifest['schema'] ?? null) !== self::RELEASE.'-runtime-v1'
            || ! is_string($manifest['expectations_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $manifest['expectations_sha256']) !== 1
            || ! is_array($manifest['entries'] ?? null)
            || count($manifest['entries']) !== (int) config('project_attachments.legacy_runtime_expected_entries', 2)) {
            throw new RuntimeException('Legacy attachment expectation binding is invalid.');
        }

        return $manifest;
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

    private function runtimeEntryPrivateValid(object $row, array $entry): bool
    {
        try {
            $keys = array_keys($entry);
            sort($keys, SORT_STRING);
            if ($keys !== [
                'attachment_id', 'descriptor_sha256', 'mime', 'new_path', 'new_sha256',
                'project_id', 'size', 'task_id', 'tenant_id',
            ]
                || ! is_int($entry['attachment_id'])
                || ! is_int($entry['tenant_id'])
                || ! is_int($entry['project_id'])
                || ! is_int($entry['task_id'])
                || ! is_int($entry['size'])
                || ! is_string($entry['mime'])
                || ! is_string($entry['new_path'])
                || ! is_string($entry['new_sha256'])
                || ! is_string($entry['descriptor_sha256'])
                || $entry['attachment_id'] !== (int) $row->id
                || $entry['tenant_id'] !== (int) $row->tenant_id
                || $entry['project_id'] !== (int) $row->project_id
                || $entry['task_id'] !== (int) $row->task_id
                || $entry['size'] !== (int) $row->size_bytes
                || $entry['mime'] !== (string) $row->mime_type
                || preg_match('/\A[a-f0-9]{64}\z/D', $entry['new_sha256']) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', $entry['descriptor_sha256']) !== 1
                || str_contains($entry['new_path'], "\0")
                || str_contains($entry['new_path'], '\\')
                || str_contains($entry['new_path'], '..')
                || ! str_starts_with(
                    $entry['new_path'],
                    "private/tenants/{$entry['tenant_id']}/project-attachments/projects/{$entry['project_id']}/tasks/{$entry['task_id']}/",
                )
                || ! hash_equals($this->descriptorSha256($row), $entry['descriptor_sha256'])) {
                return false;
            }
            $stream = $this->storage->readVerified(
                (int) $entry['tenant_id'],
                (int) $entry['project_id'],
                (int) $entry['task_id'],
                (string) $entry['new_path'],
                (string) $entry['new_sha256'],
                (string) $row->original_name,
                (string) $row->mime_type,
                (int) $row->size_bytes,
            );
            fclose($stream);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function saveManifest(array $manifest): void
    {
        ksort($manifest['entries']);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (! Storage::disk('local')->put(self::MANIFEST, $json)) {
            throw new RuntimeException('Project attachment cutover manifest write failed.');
        }
        if (! Storage::disk('local')->exists(self::MANIFEST)
            || ! hash_equals(hash('sha256', $json), hash('sha256', Storage::disk('local')->get(self::MANIFEST)))) {
            throw new RuntimeException('Project attachment cutover manifest read-back verification failed.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod(Storage::disk('local')->path(self::MANIFEST), 0600);
        }
    }

    private function report(string $phase, int $total): array
    {
        return [
            'phase' => $phase,
            'total' => $total,
            'source' => 0,
            'private' => 0,
            'copied' => 0,
            'reused' => 0,
            'switched' => 0,
            'restored' => 0,
            'unavailable' => 0,
            'errors' => 0,
            'ok' => false,
        ];
    }

    private function restoreManifest(bool $existed, array $manifest): void
    {
        if ($existed) {
            $this->saveManifest($manifest);

            return;
        }
        Storage::disk('local')->delete(self::MANIFEST);
        if (Storage::disk('local')->exists(self::MANIFEST)) {
            throw new RuntimeException('Project attachment cutover manifest cleanup failed.');
        }
    }

    /** @param list<object> $rows */
    private function assertExpectationCoverage(array $rows): void
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[(string) $row->id] = true;
            if (! $this->isUnavailable($row)
                && $row->storage_provider !== ProjectAttachmentPrivateStorage::PROVIDER) {
                $this->expectation($row);
            }
        }
        foreach (array_keys($this->expectations) as $id) {
            if (! isset($ids[$id])) {
                throw new RuntimeException('Project attachment evidence contains an unexpected row identifier.');
            }
        }
    }

    /** @return array{descriptor_sha256:string,disposition:string,content_sha256?:string} */
    private function expectation(object $row): array
    {
        $expectation = $this->expectations[(string) $row->id] ?? null;
        if (! is_array($expectation)
            || ! hash_equals($this->descriptorSha256($row), (string) ($expectation['descriptor_sha256'] ?? ''))) {
            throw new RuntimeException('Project attachment row is not bound to the approved source evidence.');
        }

        return $expectation;
    }

    private function descriptorSha256(object $row): string
    {
        return hash('sha256', json_encode([
            'attachment_id' => (int) $row->id,
            'tenant_id' => (int) $row->tenant_id,
            'project_id' => (int) $row->project_id,
            'task_id' => (int) $row->task_id,
            'provider' => (string) $row->storage_provider,
            'path' => (string) $row->path,
            'drive_file_id' => $row->drive_file_id,
            'drive_web_view_link' => $row->drive_web_view_link,
            'download_url' => (string) $row->drive_download_link,
            'original_name' => (string) $row->original_name,
            'mime' => (string) $row->mime_type,
            'size' => (int) $row->size_bytes,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function sourceEntry(object $row): array
    {
        return [
            'attachment_id' => (int) $row->id,
            'tenant_id' => (int) $row->tenant_id,
            'project_id' => (int) $row->project_id,
            'task_id' => (int) $row->task_id,
            'descriptor_sha256' => $this->descriptorSha256($row),
            'old_provider' => (string) $row->storage_provider,
            'old_path' => (string) $row->path,
            'old_drive_file_id' => $row->drive_file_id,
            'old_drive_web_view_link' => $row->drive_web_view_link,
            'old_drive_download_link' => $row->drive_download_link,
        ];
    }

    private function assertSourceMissing(object $row): void
    {
        $response = Http::withOptions(['allow_redirects' => false])
            ->connectTimeout(5)->timeout(15)->head((string) $row->drive_download_link);
        if (! in_array($response->status(), [404, 410], true)) {
            throw new RuntimeException('Legacy R2 source is not conclusively missing.');
        }
    }

    private function isUnavailable(object $row): bool
    {
        return $row->storage_provider === 'unavailable'
            && (string) $row->path === ''
            && $row->source_tombstoned_at !== null
            && $row->source_tombstone_reason === 'verified_source_missing';
    }
}

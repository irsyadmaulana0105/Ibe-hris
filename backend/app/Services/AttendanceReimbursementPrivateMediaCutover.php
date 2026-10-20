<?php

namespace App\Services;

use App\Exceptions\PrivateAssetIntegrityException;
use App\Models\Attendance;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Release-specific, resumable cutover for Attendance and Reimbursement media.
 *
 * The manifest intentionally retains legacy references for rollback evidence.
 * Deployment must copy it into the root-owned sealed backup before purge-public.
 * Reports never include a persisted path or user identifier.
 */
final class AttendanceReimbursementPrivateMediaCutover
{
    public const RELEASE = '20260809-attendance-reimbursement-private-media-v1';

    public const MANIFEST_PATH = 'private/cutovers/'.self::RELEASE.'/manifest.json';

    private const SCHEMA = 1;

    private const MAX_ORPHAN_BYTES = 10 * 1024 * 1024;

    /**
     * Immutable release inventory. A production copy/remediation phase will not
     * start if the frozen public snapshot has drifted.
     *
     * @var array<string,array{refs:int,existing:int,missing:int,bytes:int,max:int}>
     */
    private const EXPECTED_REFERENCES = [
        'attendance.selfie-in' => ['refs' => 901, 'existing' => 377, 'missing' => 524, 'bytes' => 4_802_848, 'max' => 27_909],
        'attendance.selfie-out' => ['refs' => 728, 'existing' => 251, 'missing' => 477, 'bytes' => 2_820_544, 'max' => 28_058],
        'attendance.attachment' => ['refs' => 39, 'existing' => 0, 'missing' => 39, 'bytes' => 0, 'max' => 0],
        'reimbursement.attachment' => ['refs' => 30, 'existing' => 30, 'missing' => 0, 'bytes' => 10_107_068, 'max' => 1_911_969],
        'reimbursement.payment-proof' => ['refs' => 25, 'existing' => 25, 'missing' => 0, 'bytes' => 4_892_451, 'max' => 313_096],
        'reimbursement.receipt' => ['refs' => 25, 'existing' => 25, 'missing' => 0, 'bytes' => 35_614_379, 'max' => 1_495_525],
    ];

    /** @var array<string,array{count:int,bytes:int,max:int}> */
    private const EXPECTED_ORPHANS = [
        'attendance-selfies' => ['count' => 141, 'bytes' => 850_589, 'max' => 83_075],
        'attachments' => ['count' => 0, 'bytes' => 0, 'max' => 0],
        'reimbursements' => ['count' => 1, 'bytes' => 68, 'max' => 68],
        'reimbursement-proofs' => ['count' => 0, 'bytes' => 0, 'max' => 0],
        'reimbursement-receipts' => ['count' => 0, 'bytes' => 0, 'max' => 0],
    ];

    private const ATTENDANCE_FIELDS = [
        'selfie_in_path' => [
            'label' => 'attendance.selfie-in', 'asset' => 'selfie-in', 'prefix' => 'selfie_in',
            'category' => AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
            'legacy' => ['attendance-selfies/'], 'mimes' => ['image/jpeg', 'image/png'],
            'rollback' => 'attendance-selfies',
        ],
        'selfie_out_path' => [
            'label' => 'attendance.selfie-out', 'asset' => 'selfie-out', 'prefix' => 'selfie_out',
            'category' => AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
            'legacy' => ['attendance-selfies/'], 'mimes' => ['image/jpeg', 'image/png'],
            'rollback' => 'attendance-selfies',
        ],
        'attachment' => [
            'label' => 'attendance.attachment', 'asset' => 'attachment', 'prefix' => 'attachment',
            'category' => AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
            'legacy' => ['attachments/'],
            'mimes' => ['image/jpeg', 'image/png', 'application/pdf'], 'rollback' => 'attendance-attachments',
        ],
    ];

    private const REIMBURSEMENT_FIELDS = [
        'attachment_path' => [
            'label' => 'reimbursement.attachment', 'asset' => 'attachment', 'prefix' => 'attachment',
            'category' => AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
            'legacy' => ['reimbursements/'], 'mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
            'rollback' => 'reimbursements',
        ],
        'payment_proof_path' => [
            'label' => 'reimbursement.payment-proof', 'asset' => 'payment-proof', 'prefix' => 'payment_proof',
            'category' => AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
            'legacy' => ['reimbursement-proofs/'], 'mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
            'rollback' => 'reimbursement-proofs',
        ],
        'receipt_pdf_path' => [
            'label' => 'reimbursement.receipt', 'asset' => 'receipt', 'prefix' => 'receipt_pdf',
            'category' => AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
            'legacy' => ['reimbursement-receipts/'], 'mimes' => ['application/pdf'],
            'rollback' => 'reimbursement-receipts',
        ],
    ];

    private const ORPHAN_NAMESPACES = [
        'attendance-selfies',
        'attachments',
        'reimbursements',
        'reimbursement-proofs',
        'reimbursement-receipts',
    ];

    public function __construct(private readonly PrivateMediaStorage $media) {}

    /** @return array<string,mixed> */
    public function run(
        string $phase,
        ?int $tenantId = null,
        bool $uatConfirmed = false,
        ?string $evidence = null,
        bool $apply = false,
        bool $enforceReleaseInventory = true,
    ): array {
        if ($phase !== 'plan' && ! $apply) {
            throw new RuntimeException('Mutating cutover phases require explicit --apply confirmation.');
        }
        if ($tenantId !== null && $phase !== 'plan') {
            throw new RuntimeException('This release uses global public namespaces; mutating phases must run without --tenant.');
        }

        return Cache::lock('cutover-lock:'.self::RELEASE.':global', 900)->block(1, fn (): array => match ($phase) {
            'plan' => $this->plan($tenantId, $enforceReleaseInventory),
            'copy-private' => $this->copyPrivate($enforceReleaseInventory),
            'remediate-missing' => $this->remediateMissing($evidence, $enforceReleaseInventory),
            'switch-private' => $this->switchPrivate(),
            'purge-public' => $this->purgePublic($uatConfirmed),
            'restore-public' => $this->restorePublic(),
            default => throw new RuntimeException('Unknown cutover phase.'),
        });
    }

    private function plan(?int $tenantId, bool $enforceReleaseInventory): array
    {
        $records = $this->records($tenantId);
        $inventory = $this->referenceInventory($records);
        $orphans = $tenantId === null ? $this->orphanInventory($records) : [];
        $duplicateExisting = $this->duplicateExistingReferences($records);
        $report = $this->baseReport('plan');
        $this->mergeInventoryCounts($report, $inventory);
        $report['public_orphans'] = array_sum(array_column($orphans, 'count'));
        $report['orphan_bytes'] = array_sum(array_column($orphans, 'bytes'));
        $report['missing_evidence'] = $this->missingEvidence($records);
        $report['duplicate_existing_refs'] = $duplicateExisting;
        $report['release_inventory_match'] = $tenantId === null
            && $this->releaseInventoryMatches($inventory, $orphans, $duplicateExisting);
        if ($enforceReleaseInventory && $tenantId === null && ! $report['release_inventory_match']) {
            $this->addError($report, 'release_inventory_drift');
        }
        $report['ok'] = $report['errors'] === 0;

        return $report;
    }

    private function copyPrivate(bool $enforceReleaseInventory): array
    {
        $records = $this->records();
        $inventory = $this->referenceInventory($records);
        $orphans = $this->orphanInventory($records);
        $duplicateExisting = $this->duplicateExistingReferences($records);
        $report = $this->baseReport('copy-private');
        $this->mergeInventoryCounts($report, $inventory);
        if ($enforceReleaseInventory && ! $this->releaseInventoryMatches($inventory, $orphans, $duplicateExisting)) {
            $this->addError($report, 'release_inventory_drift');
            $report['ok'] = false;

            return $report;
        }

        $originalManifest = $this->manifestSnapshot();
        $manifest = $this->loadManifest();
        $created = [];
        try {
            foreach ($records as $record) {
                if ($this->isTombstone($record)) {
                    $report['tombstoned']++;

                    continue;
                }
                if ($this->isPrivate($record)) {
                    $this->readPrivate($record);
                    $report['private']++;

                    continue;
                }
                if (! $this->legacyExists($record)) {
                    $report['missing']++;

                    continue;
                }

                $legacy = $this->inspectLegacy($record);
                $key = $this->entryKey($record, $record['path']);
                $entry = $manifest['entries'][$key] ?? null;
                if ($entry !== null && hash_equals($legacy['sha256'], (string) $entry['old_sha256'])
                    && $this->privateEntryValid($record, $entry)) {
                    $report['reused']++;

                    continue;
                }

                $stored = $this->media->copyLegacyToPrivate(
                    $record['tenant'], $record['category'], $record['id'], $record['asset'],
                    $record['path'], $record['legacy'], $record['mimes'],
                );
                $created[] = $stored['path'];
                $manifest['entries'][$key] = $this->newEntry($record, $legacy, $stored);
                $report['copied']++;
            }

            foreach ($this->scanOrphans($records) as $orphan) {
                $key = $this->orphanKey($orphan['path']);
                $existing = $manifest['orphans'][$key] ?? null;
                if ($existing !== null && $this->orphanEntryValid($existing, $orphan)) {
                    $report['orphans_reused']++;

                    continue;
                }
                $quarantine = $this->quarantinePath('orphan', $orphan['path'], $orphan['sha256']);
                $quarantineExisted = Storage::disk('local')->exists($quarantine);
                $this->writeQuarantine($quarantine, $orphan['bytes'], $orphan['sha256']);
                if (! $quarantineExisted) {
                    $created[] = $quarantine;
                }
                $manifest['orphans'][$key] = [
                    'namespace' => $orphan['namespace'], 'old_path' => $orphan['path'],
                    'old_sha256' => $orphan['sha256'], 'old_size' => $orphan['size'],
                    'quarantine_path' => $quarantine, 'captured_at' => now()->toIso8601String(),
                    'purged_at' => null, 'restored_at' => null,
                ];
                $report['orphans_captured']++;
            }

            $this->saveManifest($manifest);
        } catch (\Throwable $failure) {
            foreach (array_reverse($created) as $path) {
                Storage::disk('local')->delete($path);
            }
            $this->restoreManifest($originalManifest, $failure);
            throw $failure;
        }

        $report['ok'] = true;

        return $report;
    }

    private function remediateMissing(?string $evidence, bool $enforceReleaseInventory): array
    {
        $records = $this->records();
        $inventory = $this->referenceInventory($records);
        $orphans = $this->orphanInventory($records);
        $duplicateExisting = $this->duplicateExistingReferences($records);
        $report = $this->baseReport('remediate-missing');
        $expectedEvidence = $this->missingEvidence($records);
        if ($expectedEvidence === null) {
            $report['ok'] = true;

            return $report;
        }
        if ($evidence === null || ! hash_equals($expectedEvidence, $evidence)) {
            $this->addError($report, 'remediation_evidence_required');
            $report['ok'] = false;

            return $report;
        }
        if ($enforceReleaseInventory && ! $this->releaseInventoryMatches($inventory, $orphans, $duplicateExisting)) {
            $this->addError($report, 'release_inventory_drift');
            $report['ok'] = false;

            return $report;
        }

        $missing = array_values(array_filter($records, fn (array $record): bool => ! $this->isPrivate($record)
            && ! $this->isTombstone($record) && ! $this->legacyExists($record)));
        $originalManifest = $this->manifestSnapshot();
        $manifest = $this->loadManifest();
        try {
            DB::transaction(function () use ($missing, &$manifest, &$report): void {
                foreach ($missing as $record) {
                    $fingerprint = hash('sha256', $record['path']);
                    $changed = DB::table($record['table'])->where('id', $record['id'])
                        ->where($record['field'], $record['path'])
                        ->update([
                            $record['field'] => null,
                            $record['prefix'].'_unavailable_reason' => 'legacy_object_missing',
                            $record['prefix'].'_reference_fingerprint' => $fingerprint,
                            'updated_at' => now(),
                        ]);
                    if ($changed !== 1) {
                        throw new RuntimeException('Optimistic missing-reference remediation failed.');
                    }
                    $key = $this->tombstoneKey($record);
                    $manifest['tombstones'][$key] = [
                        'table' => $record['table'], 'id' => $record['id'], 'tenant' => $record['tenant'],
                        'field' => $record['field'], 'prefix' => $record['prefix'],
                        'old_path' => $record['path'], 'reference_fingerprint' => $fingerprint,
                        'reason' => 'legacy_object_missing', 'remediated_at' => now()->toIso8601String(),
                    ];
                    $report['remediated']++;
                }
                $this->saveManifest($manifest);
            });
        } catch (\Throwable $failure) {
            $this->restoreManifest($originalManifest, $failure);
            throw $failure;
        }
        $report['ok'] = true;

        return $report;
    }

    private function switchPrivate(): array
    {
        $originalManifest = $this->manifestSnapshot();
        $manifest = $this->loadManifest();
        $report = $this->baseReport('switch-private');
        $updates = [];
        $records = $this->records();
        if ($this->duplicateExistingReferences($records) > 0) {
            $this->addError($report, 'duplicate_existing_reference');
        }
        foreach ($records as $record) {
            if ($this->isTombstone($record)) {
                $report['tombstoned']++;

                continue;
            }
            try {
                if ($this->isPrivate($record)) {
                    $this->readPrivate($record);
                    $report['private']++;

                    continue;
                }
                $legacy = $this->inspectLegacy($record);
                $key = $this->entryKey($record, $record['path']);
                $entry = $manifest['entries'][$key] ?? null;
                if ($entry === null || ! hash_equals($legacy['sha256'], (string) $entry['old_sha256'])
                    || ! $this->privateEntryValid($record, $entry)) {
                    throw new PrivateAssetIntegrityException('copy_required', 'Private copy is incomplete.', 409);
                }
                $updates[] = [$record, $entry, $key];
            } catch (PrivateAssetIntegrityException $failure) {
                $this->addError($report, $failure->reason);
            }
        }
        $this->verifyOrphanSnapshot($manifest, $report);
        if ($report['errors'] > 0) {
            $report['ok'] = false;

            return $report;
        }

        try {
            DB::transaction(function () use ($updates, &$manifest): void {
                foreach ($updates as [$record, $entry, $key]) {
                    $changed = DB::table($record['table'])->where('id', $record['id'])
                        ->where($record['field'], $record['path'])
                        ->update([
                            $record['field'] => $entry['new_path'],
                            ...$this->metadataColumns($record['prefix'], $entry['new_metadata']),
                            'updated_at' => now(),
                        ]);
                    if ($changed !== 1) {
                        throw new RuntimeException('Optimistic private-media switch failed.');
                    }
                    $manifest['entries'][$key]['switched_at'] = now()->toIso8601String();
                }
                $this->saveManifest($manifest);
            });
        } catch (\Throwable $failure) {
            $this->restoreManifest($originalManifest, $failure);
            throw $failure;
        }
        $report['switched'] = count($updates);
        $report['ok'] = true;

        return $report;
    }

    private function purgePublic(bool $uatConfirmed): array
    {
        if (! $uatConfirmed) {
            throw new RuntimeException('purge-public requires explicit UAT confirmation.');
        }
        $originalManifest = $this->manifestSnapshot();
        $manifest = $this->loadManifest();
        $report = $this->baseReport('purge-public');
        $createdQuarantine = [];
        foreach ($this->records() as $record) {
            if ($this->isTombstone($record)) {
                continue;
            }
            try {
                if (! $this->isPrivate($record)) {
                    throw new PrivateAssetIntegrityException('unswitched_reference', 'A public reference remains.', 409);
                }
                $this->readPrivate($record);
            } catch (PrivateAssetIntegrityException $failure) {
                $this->addError($report, $failure->reason);
            }
        }
        $this->verifyOrphanSnapshot($manifest, $report);

        $deletions = [];
        foreach ($manifest['entries'] as $key => &$entry) {
            $path = (string) $entry['old_path'];
            if (! Storage::disk('public')->exists($path)) {
                if ($entry['purged_at'] === null) {
                    $this->addError($report, 'missing_public_original');
                }

                continue;
            }
            $bytes = Storage::disk('public')->get($path);
            if (! hash_equals((string) $entry['old_sha256'], hash('sha256', $bytes))) {
                $this->addError($report, 'public_original_drift');

                continue;
            }
            $quarantine = $entry['quarantine_path'] ?? $this->quarantinePath('referenced', $path, $entry['old_sha256']);
            try {
                $quarantineExisted = Storage::disk('local')->exists($quarantine);
                $this->writeQuarantine($quarantine, $bytes, $entry['old_sha256']);
                if (! $quarantineExisted) {
                    $createdQuarantine[] = $quarantine;
                }
                $entry['quarantine_path'] = $quarantine;
                $deletions[$path] = ['kind' => 'entry', 'key' => $key, 'quarantine' => $quarantine];
            } catch (\Throwable) {
                $this->addError($report, 'quarantine_verification_failed');
            }
        }
        unset($entry);
        foreach ($manifest['orphans'] as $key => $orphan) {
            $path = (string) $orphan['old_path'];
            if (! Storage::disk('public')->exists($path)) {
                if ($orphan['purged_at'] === null) {
                    $this->addError($report, 'missing_public_orphan');
                }

                continue;
            }
            $bytes = Storage::disk('public')->get($path);
            if (! hash_equals((string) $orphan['old_sha256'], hash('sha256', $bytes))
                || ! $this->quarantineValid($orphan['quarantine_path'], $orphan['old_sha256'])) {
                $this->addError($report, 'orphan_drift');

                continue;
            }
            $deletions[$path] = ['kind' => 'orphan', 'key' => $key, 'quarantine' => $orphan['quarantine_path']];
        }
        if ($report['errors'] > 0) {
            foreach ($createdQuarantine as $path) {
                Storage::disk('local')->delete($path);
                if (Storage::disk('local')->exists($path)) {
                    throw new RuntimeException('Purge preflight cleanup failed.');
                }
            }
            $report['ok'] = false;

            return $report;
        }

        $deleted = [];
        try {
            foreach ($deletions as $path => $object) {
                // Register the rollback intent before attempting deletion. A
                // filesystem adapter may remove an object and still report a
                // failure, so compensation must cover that ambiguous result.
                $deleted[$path] = $object;
                if (! Storage::disk('public')->delete($path) || Storage::disk('public')->exists($path)) {
                    throw new RuntimeException('Public media purge verification failed.');
                }
                if ($object['kind'] === 'entry') {
                    $manifest['entries'][$object['key']]['purged_at'] = now()->toIso8601String();
                    $report['purged']++;
                } else {
                    $manifest['orphans'][$object['key']]['purged_at'] = now()->toIso8601String();
                    $report['orphans_purged']++;
                }
            }
            $this->saveManifest($manifest);
        } catch (\Throwable $failure) {
            $cleanupFailed = false;
            foreach ($deleted as $path => $object) {
                $bytes = Storage::disk('local')->get($object['quarantine']);
                $restored = Storage::disk('public')->put($path, $bytes)
                    && Storage::disk('public')->exists($path)
                    && hash_equals(hash('sha256', $bytes), hash('sha256', Storage::disk('public')->get($path)));
                if (! $restored) {
                    // Never leave a corrupt partial compensation object
                    // publicly reachable. The original failure remains fatal.
                    Storage::disk('public')->delete($path);
                    $restored = false;
                }
                $cleanupFailed = ! $restored || $cleanupFailed;
            }
            try {
                $this->restoreManifest($originalManifest, $failure);
            } catch (\Throwable) {
                $cleanupFailed = true;
            }
            foreach ($createdQuarantine as $path) {
                Storage::disk('local')->delete($path);
                $cleanupFailed = Storage::disk('local')->exists($path) || $cleanupFailed;
            }
            if ($cleanupFailed) {
                throw new RuntimeException('Purge failed and compensation was incomplete.', 0, $failure);
            }
            throw $failure;
        }
        $report['ok'] = true;

        return $report;
    }

    private function restorePublic(): array
    {
        $originalManifest = $this->manifestSnapshot();
        $manifest = $this->loadManifest();
        $report = $this->baseReport('restore-public');
        $writes = [];
        $updates = [];

        foreach ($this->records() as $record) {
            if ($this->isTombstone($record)) {
                // Evidence-bound missing refs remain unavailable on rollback.
                $report['tombstoned']++;

                continue;
            }
            if (! $this->isPrivate($record)) {
                try {
                    $this->inspectLegacy($record);
                    $report['legacy']++;
                } catch (PrivateAssetIntegrityException $failure) {
                    $this->addError($report, $failure->reason);
                }

                continue;
            }
            try {
                $bytes = $this->readPrivate($record);
                [$key, $entry] = $this->entryForPrivate($manifest, $record);
                if ($entry === null) {
                    $metadata = $this->media->inspectBytes($bytes, $record['mimes']);
                    $oldPath = $this->newRollbackPath($record, $metadata);
                    $key = $this->entryKey($record, $oldPath);
                    $entry = $this->newEntry($record, $metadata, ['path' => $record['path'], ...$metadata]);
                    $entry['old_path'] = $oldPath;
                    $entry['old_sha256'] = $metadata['sha256'];
                    $entry['old_size'] = $metadata['size'];
                    $entry['switched_at'] = now()->toIso8601String();
                    $manifest['entries'][$key] = $entry;
                    $report['new_refs']++;
                }
                $oldPath = (string) $entry['old_path'];
                $this->media->assertLegacyPath($oldPath, $record['legacy']);
                if (Storage::disk('public')->exists($oldPath)
                    && ! hash_equals((string) $entry['old_sha256'], hash('sha256', Storage::disk('public')->get($oldPath)))) {
                    throw new PrivateAssetIntegrityException('restore_collision', 'Public rollback target differs.', 409);
                }
                if (isset($writes[$oldPath])
                    && ! hash_equals($writes[$oldPath]['sha256'], (string) $entry['old_sha256'])) {
                    throw new PrivateAssetIntegrityException('restore_target_collision', 'Rollback targets differ.', 409);
                }
                $writes[$oldPath] = ['bytes' => $bytes, 'sha256' => (string) $entry['old_sha256']];
                $updates[] = [$record, $entry, $key];
            } catch (PrivateAssetIntegrityException $failure) {
                $this->addError($report, $failure->reason);
            }
        }

        foreach ($manifest['orphans'] as $key => $orphan) {
            if (! $this->quarantineValid($orphan['quarantine_path'], $orphan['old_sha256'])) {
                $this->addError($report, 'orphan_quarantine_invalid');

                continue;
            }
            $path = (string) $orphan['old_path'];
            $bytes = Storage::disk('local')->get($orphan['quarantine_path']);
            if (Storage::disk('public')->exists($path)
                && ! hash_equals((string) $orphan['old_sha256'], hash('sha256', Storage::disk('public')->get($path)))) {
                $this->addError($report, 'orphan_restore_collision');

                continue;
            }
            if (isset($writes[$path]) && ! hash_equals($writes[$path]['sha256'], (string) $orphan['old_sha256'])) {
                $this->addError($report, 'orphan_restore_target_collision');

                continue;
            }
            $writes[$path] = ['bytes' => $bytes, 'sha256' => (string) $orphan['old_sha256']];
            $manifest['orphans'][$key]['restored_at'] = now()->toIso8601String();
        }
        if ($report['errors'] > 0) {
            $report['ok'] = false;

            return $report;
        }

        $created = [];
        try {
            foreach ($writes as $path => $object) {
                if (Storage::disk('public')->exists($path)) {
                    continue;
                }
                // Record before the write because a failed adapter can leave a
                // partial object even when it returns false.
                $created[] = $path;
                if (! Storage::disk('public')->put($path, $object['bytes'])
                    || ! Storage::disk('public')->exists($path)
                    || ! hash_equals($object['sha256'], hash('sha256', Storage::disk('public')->get($path)))) {
                    throw new RuntimeException('Public restore write failed.');
                }
            }

            DB::transaction(function () use ($updates, &$manifest, &$report): void {
                foreach ($updates as [$record, $entry, $key]) {
                    $changed = DB::table($record['table'])->where('id', $record['id'])
                        ->where($record['field'], $record['path'])
                        ->update([
                            $record['field'] => $entry['old_path'],
                            ...$this->emptyMetadataColumns($record['prefix']),
                            'updated_at' => now(),
                        ]);
                    if ($changed !== 1) {
                        throw new RuntimeException('Optimistic public restore failed.');
                    }
                    $manifest['entries'][$key]['restored_at'] = now()->toIso8601String();
                    $report['restored']++;
                }
                $this->saveManifest($manifest);
            });
        } catch (\Throwable $failure) {
            $cleanupFailed = false;
            foreach (array_reverse($created) as $path) {
                Storage::disk('public')->delete($path);
                $cleanupFailed = Storage::disk('public')->exists($path) || $cleanupFailed;
            }
            if ($cleanupFailed) {
                throw new RuntimeException('Restore failed and public cleanup was incomplete.', 0, $failure);
            }
            $this->restoreManifest($originalManifest, $failure);
            throw $failure;
        }
        $report['orphans_restored'] = count($manifest['orphans']);
        $report['ok'] = true;

        return $report;
    }

    /** @return list<array<string,mixed>> */
    private function records(?int $tenantId = null): array
    {
        $records = [];
        $users = User::withoutGlobalScopes()->pluck('company_id', 'id');
        foreach (Attendance::withoutGlobalScopes()->get() as $attendance) {
            $tenant = (int) ($users[$attendance->user_id] ?? 0);
            if ($tenant < 1) {
                if ($this->hasMediaState($attendance, self::ATTENDANCE_FIELDS)) {
                    throw new PrivateAssetIntegrityException(
                        'invalid_tenant_reference',
                        'A media reference has no valid tenant owner.',
                        409,
                    );
                }

                continue;
            }
            if ($tenantId !== null && $tenant !== $tenantId) {
                continue;
            }
            foreach (self::ATTENDANCE_FIELDS as $field => $config) {
                $this->appendRecord($records, 'attendances', $attendance, $tenant, $field, $config);
            }
        }
        foreach (Reimbursement::withoutGlobalScopes()->get() as $reimbursement) {
            $tenant = (int) ($reimbursement->company_id ?: ($users[$reimbursement->user_id] ?? 0));
            if ($tenant < 1) {
                if ($this->hasMediaState($reimbursement, self::REIMBURSEMENT_FIELDS)) {
                    throw new PrivateAssetIntegrityException(
                        'invalid_tenant_reference',
                        'A media reference has no valid tenant owner.',
                        409,
                    );
                }

                continue;
            }
            if ($tenantId !== null && $tenant !== $tenantId) {
                continue;
            }
            foreach (self::REIMBURSEMENT_FIELDS as $field => $config) {
                $this->appendRecord($records, 'reimbursements', $reimbursement, $tenant, $field, $config);
            }
        }

        return $records;
    }

    private function hasMediaState(object $model, array $fields): bool
    {
        foreach ($fields as $field => $config) {
            foreach ([
                $field,
                $config['prefix'].'_sha256',
                $config['prefix'].'_size_bytes',
                $config['prefix'].'_mime_type',
                $config['prefix'].'_width',
                $config['prefix'].'_height',
                $config['prefix'].'_unavailable_reason',
                $config['prefix'].'_reference_fingerprint',
            ] as $attribute) {
                $value = $model->getAttribute($attribute);
                if ($value !== null && $value !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function appendRecord(array &$records, string $table, object $model, int $tenant, string $field, array $config): void
    {
        $path = $model->getAttribute($field);
        $reason = $model->getAttribute($config['prefix'].'_unavailable_reason');
        if (($path === null || $path === '') && ($reason === null || $reason === '')) {
            return;
        }
        $records[] = [
            'table' => $table, 'id' => $model->id, 'tenant' => $tenant, 'field' => $field,
            'path' => $path === null ? '' : (string) $path, 'reason' => $reason,
            'fingerprint' => $model->getAttribute($config['prefix'].'_reference_fingerprint'),
            'metadata' => [
                'sha256' => $model->getAttribute($config['prefix'].'_sha256'),
                'size' => $model->getAttribute($config['prefix'].'_size_bytes'),
                'mime' => $model->getAttribute($config['prefix'].'_mime_type'),
                'width' => $model->getAttribute($config['prefix'].'_width'),
                'height' => $model->getAttribute($config['prefix'].'_height'),
            ],
            ...$config,
        ];
    }

    /** @return array<string,array{refs:int,existing:int,missing:int,bytes:int,max:int}> */
    private function referenceInventory(array $records): array
    {
        $inventory = [];
        foreach (array_keys(self::EXPECTED_REFERENCES) as $label) {
            $inventory[$label] = ['refs' => 0, 'existing' => 0, 'missing' => 0, 'bytes' => 0, 'max' => 0];
        }
        foreach ($records as $record) {
            if ($this->isTombstone($record) || $this->isPrivate($record)) {
                continue;
            }
            $inventory[$record['label']]['refs']++;
            if (! $this->legacyExists($record)) {
                $inventory[$record['label']]['missing']++;

                continue;
            }
            try {
                $metadata = $this->inspectLegacy($record);
                $inventory[$record['label']]['existing']++;
                $inventory[$record['label']]['bytes'] += $metadata['size'];
                $inventory[$record['label']]['max'] = max($inventory[$record['label']]['max'], $metadata['size']);
            } catch (PrivateAssetIntegrityException) {
                // Invalid referenced media is not silently classified as missing.
                $inventory[$record['label']]['missing']++;
            }
        }

        return $inventory;
    }

    /** @return array<string,array{count:int,bytes:int,max:int}> */
    private function orphanInventory(array $records): array
    {
        $inventory = [];
        foreach (self::ORPHAN_NAMESPACES as $namespace) {
            $inventory[$namespace] = ['count' => 0, 'bytes' => 0, 'max' => 0];
        }
        foreach ($this->scanOrphans($records) as $orphan) {
            $inventory[$orphan['namespace']]['count']++;
            $inventory[$orphan['namespace']]['bytes'] += $orphan['size'];
            $inventory[$orphan['namespace']]['max'] = max($inventory[$orphan['namespace']]['max'], $orphan['size']);
        }

        return $inventory;
    }

    /** @return list<array{namespace:string,path:string,bytes:string,sha256:string,size:int}> */
    private function scanOrphans(array $records, array $knownLegacyPaths = []): array
    {
        $referenced = array_fill_keys($knownLegacyPaths, true);
        foreach ($records as $record) {
            if (! $this->isPrivate($record) && ! $this->isTombstone($record) && $record['path'] !== '') {
                $referenced[$record['path']] = true;
            }
        }
        $orphans = [];
        foreach (self::ORPHAN_NAMESPACES as $namespace) {
            foreach (Storage::disk('public')->allFiles($namespace) as $path) {
                $this->media->assertLegacyPath($path, [$namespace.'/']);
                if (isset($referenced[$path])) {
                    continue;
                }
                $bytes = Storage::disk('public')->get($path);
                $size = strlen($bytes);
                if ($size < 1 || $size > self::MAX_ORPHAN_BYTES) {
                    throw new PrivateAssetIntegrityException('invalid_orphan_size', 'Public orphan exceeds evidence bounds.', 409);
                }
                $orphans[] = [
                    'namespace' => $namespace, 'path' => $path, 'bytes' => $bytes,
                    'sha256' => hash('sha256', $bytes), 'size' => $size,
                ];
            }
        }

        return $orphans;
    }

    private function legacyExists(array $record): bool
    {
        if ($record['path'] === '') {
            return false;
        }
        $this->media->assertLegacyPath($record['path'], $record['legacy']);

        return Storage::disk('public')->exists($record['path']);
    }

    private function inspectLegacy(array $record): array
    {
        $this->media->assertLegacyPath($record['path'], $record['legacy']);
        if (! Storage::disk('public')->exists($record['path'])) {
            throw new PrivateAssetIntegrityException('missing', 'Referenced public media is unavailable.', 410);
        }

        return $this->media->inspectBytes(Storage::disk('public')->get($record['path']), $record['mimes']);
    }

    private function readPrivate(array $record): string
    {
        return $this->media->readVerified(
            $record['tenant'], $record['category'], $record['id'], $record['asset'], $record['path'],
            $record['metadata'], $record['mimes'],
        );
    }

    private function isPrivate(array $record): bool
    {
        return $this->media->isPrivatePathFor(
            $record['tenant'], $record['category'], $record['id'], $record['asset'], $record['path'],
        );
    }

    private function isTombstone(array $record): bool
    {
        return $record['path'] === '' && $record['reason'] === 'legacy_object_missing'
            && is_string($record['fingerprint']) && preg_match('/\A[a-f0-9]{64}\z/D', $record['fingerprint']) === 1;
    }

    private function releaseInventoryMatches(array $references, array $orphans, int $duplicateExisting): bool
    {
        return $duplicateExisting === 0
            && $references === self::EXPECTED_REFERENCES
            && $orphans === self::EXPECTED_ORPHANS;
    }

    private function duplicateExistingReferences(array $records): int
    {
        $seen = [];
        $duplicates = 0;
        foreach ($records as $record) {
            if ($this->isPrivate($record) || $this->isTombstone($record) || ! $this->legacyExists($record)) {
                continue;
            }
            if (isset($seen[$record['path']])) {
                $duplicates++;
            } else {
                $seen[$record['path']] = true;
            }
        }

        return $duplicates;
    }

    private function missingEvidence(array $records): ?string
    {
        $items = [];
        foreach ($records as $record) {
            if (! $this->isPrivate($record) && ! $this->isTombstone($record) && ! $this->legacyExists($record)) {
                $items[] = $record['table'].':'.$record['id'].':'.$record['field'].':'.hash('sha256', $record['path']);
            }
        }
        if ($items === []) {
            return null;
        }
        sort($items, SORT_STRING);

        return hash_hmac('sha256', self::RELEASE."\n".implode("\n", $items), $this->hmacKey());
    }

    private function hmacKey(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? '' : $decoded;
        }
        if (strlen($key) < 16) {
            throw new RuntimeException('APP_KEY is unavailable for cutover evidence sealing.');
        }

        return $key;
    }

    private function privateEntryValid(array $record, array $entry): bool
    {
        try {
            $this->media->readVerified(
                $record['tenant'], $record['category'], $record['id'], $record['asset'], (string) $entry['new_path'],
                $entry['new_metadata'], $record['mimes'],
            );

            return true;
        } catch (PrivateAssetIntegrityException) {
            return false;
        }
    }

    private function newEntry(array $record, array $legacy, array $stored): array
    {
        return [
            'table' => $record['table'], 'id' => $record['id'], 'tenant' => $record['tenant'],
            'field' => $record['field'], 'prefix' => $record['prefix'], 'asset' => $record['asset'],
            'category' => $record['category'], 'old_path' => $record['path'],
            'old_sha256' => $legacy['sha256'], 'old_size' => $legacy['size'],
            'new_path' => $stored['path'],
            'new_metadata' => [
                'sha256' => $stored['sha256'], 'size' => $stored['size'], 'mime' => $stored['mime'],
                'width' => $stored['width'], 'height' => $stored['height'],
            ],
            'copied_at' => now()->toIso8601String(), 'switched_at' => null,
            'quarantine_path' => null, 'purged_at' => null, 'restored_at' => null,
        ];
    }

    /** @return array{0:?string,1:?array} */
    private function entryForPrivate(array $manifest, array $record): array
    {
        foreach ($manifest['entries'] as $key => $entry) {
            if ($entry['table'] === $record['table'] && (string) $entry['id'] === (string) $record['id']
                && $entry['field'] === $record['field'] && $entry['new_path'] === $record['path']) {
                return [$key, $entry];
            }
        }

        return [null, null];
    }

    private function newRollbackPath(array $record, array $metadata): string
    {
        $extension = match ($metadata['mime']) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf',
            default => throw new PrivateAssetIntegrityException('invalid_mime', 'Rollback media type is invalid.', 409),
        };

        return $record['rollback'].'/rollback-'.$record['tenant'].'-'.$record['id'].'-'.$record['asset'].'-'
            .substr($metadata['sha256'], 0, 20).'.'.$extension;
    }

    private function verifyOrphanSnapshot(array $manifest, array &$report): void
    {
        $knownLegacyPaths = array_values(array_map(
            fn (array $entry): string => (string) $entry['old_path'],
            $manifest['entries'],
        ));
        $current = $this->scanOrphans($this->records(), $knownLegacyPaths);
        $seen = [];
        foreach ($current as $orphan) {
            $key = $this->orphanKey($orphan['path']);
            $entry = $manifest['orphans'][$key] ?? null;
            if ($entry === null || ! $this->orphanEntryValid($entry, $orphan)) {
                $this->addError($report, 'orphan_copy_required');
            }
            $seen[$key] = true;
        }
        foreach ($manifest['orphans'] as $key => $entry) {
            if ($entry['purged_at'] === null && ! isset($seen[$key])) {
                $this->addError($report, 'orphan_snapshot_drift');
            }
        }
    }

    private function orphanEntryValid(array $entry, array $orphan): bool
    {
        return $entry['old_path'] === $orphan['path']
            && (int) $entry['old_size'] === $orphan['size']
            && hash_equals((string) $entry['old_sha256'], $orphan['sha256'])
            && $this->quarantineValid($entry['quarantine_path'], $entry['old_sha256']);
    }

    private function quarantineValid(string $path, string $sha256): bool
    {
        return Storage::disk('local')->exists($path)
            && hash_equals($sha256, hash('sha256', Storage::disk('local')->get($path)));
    }

    private function writeQuarantine(string $path, string $bytes, string $sha256): void
    {
        if (Storage::disk('local')->exists($path)) {
            if ($this->quarantineValid($path, $sha256)) {
                return;
            }
            throw new RuntimeException('Quarantine collision detected.');
        }
        if (! Storage::disk('local')->put($path, $bytes) || ! $this->quarantineValid($path, $sha256)) {
            Storage::disk('local')->delete($path);
            throw new RuntimeException('Quarantine write verification failed.');
        }
        $this->secureLocalPath($path);
    }

    private function quarantinePath(string $kind, string $oldPath, string $sha256): string
    {
        return 'private/cutovers/'.self::RELEASE.'/quarantine/'.$kind.'/'.hash('sha256', $oldPath)
            .'-'.substr($sha256, 0, 20).'.bin';
    }

    private function entryKey(array $record, string $path): string
    {
        return hash('sha256', $record['table'].'|'.$record['id'].'|'.$record['field'].'|'.$path);
    }

    private function tombstoneKey(array $record): string
    {
        return hash('sha256', $record['table'].'|'.$record['id'].'|'.$record['field'].'|tombstone');
    }

    private function orphanKey(string $path): string
    {
        return hash('sha256', 'orphan|'.$path);
    }

    private function metadataColumns(string $prefix, array $metadata): array
    {
        return [
            $prefix.'_sha256' => $metadata['sha256'], $prefix.'_size_bytes' => $metadata['size'],
            $prefix.'_mime_type' => $metadata['mime'], $prefix.'_width' => $metadata['width'],
            $prefix.'_height' => $metadata['height'], $prefix.'_unavailable_reason' => null,
            $prefix.'_reference_fingerprint' => null,
        ];
    }

    private function emptyMetadataColumns(string $prefix): array
    {
        return [
            $prefix.'_sha256' => null, $prefix.'_size_bytes' => null, $prefix.'_mime_type' => null,
            $prefix.'_width' => null, $prefix.'_height' => null, $prefix.'_unavailable_reason' => null,
            $prefix.'_reference_fingerprint' => null,
        ];
    }

    /** @return array{schema:int,release:string,created_at:string,updated_at:string,entries:array,tombstones:array,orphans:array} */
    private function loadManifest(): array
    {
        if (! Storage::disk('local')->exists(self::MANIFEST_PATH)) {
            return [
                'schema' => self::SCHEMA, 'release' => self::RELEASE,
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
                'entries' => [], 'tombstones' => [], 'orphans' => [],
            ];
        }
        $manifest = json_decode(Storage::disk('local')->get(self::MANIFEST_PATH), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['schema'] ?? null) !== self::SCHEMA
            || ($manifest['release'] ?? null) !== self::RELEASE
            || ! is_array($manifest['entries'] ?? null)
            || ! is_array($manifest['tombstones'] ?? null)
            || ! is_array($manifest['orphans'] ?? null)) {
            throw new RuntimeException('Cutover manifest schema or release is invalid.');
        }

        return $manifest;
    }

    private function saveManifest(array $manifest): void
    {
        $manifest['updated_at'] = now()->toIso8601String();
        ksort($manifest['entries'], SORT_STRING);
        ksort($manifest['tombstones'], SORT_STRING);
        ksort($manifest['orphans'], SORT_STRING);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! Storage::disk('local')->put(self::MANIFEST_PATH, $json)
            || ! Storage::disk('local')->exists(self::MANIFEST_PATH)
            || ! hash_equals(hash('sha256', $json), hash('sha256', Storage::disk('local')->get(self::MANIFEST_PATH)))) {
            throw new RuntimeException('Cutover manifest write failed.');
        }
        $this->secureLocalPath(self::MANIFEST_PATH);
    }

    private function manifestSnapshot(): ?string
    {
        return Storage::disk('local')->exists(self::MANIFEST_PATH)
            ? Storage::disk('local')->get(self::MANIFEST_PATH)
            : null;
    }

    private function restoreManifest(?string $snapshot, \Throwable $original): void
    {
        try {
            if ($snapshot !== null) {
                if (! Storage::disk('local')->put(self::MANIFEST_PATH, $snapshot)
                    || ! Storage::disk('local')->exists(self::MANIFEST_PATH)
                    || ! hash_equals(hash('sha256', $snapshot), hash('sha256', Storage::disk('local')->get(self::MANIFEST_PATH)))) {
                    throw new RuntimeException('Original manifest restore failed.');
                }
                $this->secureLocalPath(self::MANIFEST_PATH);
            } else {
                Storage::disk('local')->delete(self::MANIFEST_PATH);
                if (Storage::disk('local')->exists(self::MANIFEST_PATH)) {
                    throw new RuntimeException('New manifest cleanup failed.');
                }
            }
        } catch (\Throwable $rollback) {
            throw new RuntimeException('Cutover failed and manifest compensation was incomplete.', 0, $original);
        }
    }

    private function secureLocalPath(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $absolute = Storage::disk('local')->path($path);
        @chmod($absolute, 0600);
        $directory = dirname($absolute);
        while ($directory !== dirname($directory) && str_contains($directory, DIRECTORY_SEPARATOR.'private')) {
            @chmod($directory, 0700);
            $directory = dirname($directory);
        }
    }

    private function mergeInventoryCounts(array &$report, array $inventory): void
    {
        foreach ($inventory as $item) {
            $report['referenced'] += $item['refs'];
            $report['legacy'] += $item['existing'];
            $report['missing'] += $item['missing'];
            $report['referenced_bytes'] += $item['bytes'];
        }
    }

    private function baseReport(string $phase): array
    {
        return [
            'release' => self::RELEASE, 'phase' => $phase, 'ok' => false,
            'referenced' => 0, 'referenced_bytes' => 0, 'legacy' => 0, 'private' => 0,
            'missing' => 0, 'tombstoned' => 0, 'copied' => 0, 'reused' => 0,
            'switched' => 0, 'purged' => 0, 'restored' => 0, 'new_refs' => 0,
            'public_orphans' => 0, 'orphan_bytes' => 0, 'orphans_captured' => 0,
            'orphans_reused' => 0, 'orphans_purged' => 0, 'orphans_restored' => 0,
            'remediated' => 0, 'missing_evidence' => null,
            'duplicate_existing_refs' => 0,
            'errors' => 0, 'error_reasons' => [],
        ];
    }

    private function addError(array &$report, string $reason): void
    {
        $report['errors']++;
        $report['error_reasons'][$reason] = ($report['error_reasons'][$reason] ?? 0) + 1;
    }
}

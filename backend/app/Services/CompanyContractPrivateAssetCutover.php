<?php

namespace App\Services;

use App\Exceptions\PrivateAssetIntegrityException;
use App\Models\Company;
use App\Models\Contract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Release-specific, resumable cutover for company and contract private assets. */
class CompanyContractPrivateAssetCutover
{
    public const RELEASE = '20260809-company-contract-private-assets-v1';

    private const MANIFEST_PATH = 'private/cutovers/'.self::RELEASE.'/manifest.json';

    private const COMPANY_FIELDS = [
        'director_signature_path' => ['checksum' => 'director_signature_sha256', 'kind' => 'png', 'prefixes' => ['signatures/'], 'asset' => 'director-signature'],
        'hrd_signature_path' => ['checksum' => 'hrd_signature_sha256', 'kind' => 'png', 'prefixes' => ['signatures/'], 'asset' => 'hrd-signature'],
        'finance_signature_path' => ['checksum' => 'finance_signature_sha256', 'kind' => 'png', 'prefixes' => ['signatures/'], 'asset' => 'finance-signature'],
        'stamp_path' => ['checksum' => 'stamp_sha256', 'kind' => 'png', 'prefixes' => ['stamps/'], 'asset' => 'stamp'],
    ];

    private const CONTRACT_FIELDS = [
        'signature_path' => ['checksum' => 'signature_sha256', 'kind' => 'png', 'prefixes' => ['contracts/signatures/'], 'asset' => 'legacy-signature'],
        'hrd_paraf_signature_path' => ['checksum' => 'hrd_paraf_signature_sha256', 'kind' => 'png', 'prefixes' => ['contracts/signatures/'], 'asset' => 'hrd-paraf-signature'],
        'karyawan_signature_path' => ['checksum' => 'karyawan_signature_sha256', 'kind' => 'png', 'prefixes' => ['contracts/signatures/'], 'asset' => 'employee-signature'],
        'direktur_signature_path' => ['checksum' => 'direktur_signature_sha256', 'kind' => 'png', 'prefixes' => ['contracts/signatures/'], 'asset' => 'director-signature'],
        'signed_pdf_path' => ['checksum' => 'signed_pdf_sha256', 'kind' => 'pdf', 'prefixes' => ['contracts/signed/'], 'asset' => 'signed-pdf'],
        'materai_pdf_path' => ['checksum' => 'materai_pdf_sha256', 'kind' => 'pdf', 'prefixes' => ['contracts/materai/'], 'asset' => 'materai-pdf'],
    ];

    private const PUBLIC_NAMESPACES = [
        'signatures' => 'png',
        'stamps' => 'png',
        'contracts/signatures' => 'png',
        'contracts/signed' => 'pdf',
        'contracts/materai' => 'pdf',
    ];

    public function __construct(private readonly PrivateAssetStorage $privateAssets) {}

    /** @return array<string,mixed> */
    public function run(
        string $phase,
        ?int $tenantId = null,
        bool $uatConfirmed = false,
        ?string $remediationEvidence = null,
        bool $apply = false,
    ): array {
        if ($phase !== 'plan' && ! $apply) {
            throw new RuntimeException('Mutating cutover phases require explicit --apply confirmation.');
        }

        return Cache::lock('cutover-lock:'.self::RELEASE.':global', 900)->block(1, fn (): array => match ($phase) {
            'plan' => $this->plan($tenantId),
            'copy-private' => $this->copyPrivate($tenantId),
            'remediate-missing' => $this->remediateMissingDirectorReferences($tenantId, $remediationEvidence),
            'switch-private' => $this->switchPrivate($tenantId),
            'purge-public' => $this->purgePublic($tenantId, $uatConfirmed),
            'restore-public' => $this->restorePublic($tenantId),
            default => throw new RuntimeException('Unknown cutover phase.'),
        });
    }

    private function plan(?int $tenantId): array
    {
        $report = $this->baseReport('plan');
        $manifest = $this->loadManifest();
        foreach ($this->records($tenantId) as $record) {
            $report['referenced']++;
            try {
                $this->inspect($record);
                $report[$this->isPrivate($record) ? 'private' : 'legacy']++;
            } catch (PrivateAssetIntegrityException $e) {
                $this->addError($report, $e->reason);
                if ($e->reason === 'missing') {
                    $evidence = $this->missingReferenceEvidence($record);
                    if ($evidence !== null) {
                        $report['remediation_evidence'][] = $evidence;
                    }
                }
            }
        }

        $this->inspectPublicOrphans($tenantId, $manifest, $report, false);
        $report['ok'] = $report['errors'] === 0;

        return $report;
    }

    private function copyPrivate(?int $tenantId): array
    {
        $manifest = $this->loadManifest();
        $report = $this->baseReport('copy-private');

        foreach ($this->records($tenantId) as $record) {
            $report['referenced']++;
            try {
                if ($this->isPrivate($record)) {
                    $this->inspect($record);
                    $report['private']++;

                    continue;
                }

                $legacySha = $this->inspect($record);
                $entryKey = $this->entryKey($record, $record['path']);
                $entry = $manifest['entries'][$entryKey] ?? null;
                if ($entry !== null
                    && hash_equals($legacySha, (string) $entry['old_sha256'])
                    && $this->privateEntryIsValid($record, $entry)) {
                    $report['reused']++;

                    continue;
                }

                $stored = $this->privateAssets->copyLegacyToPrivate(
                    $record['tenant'],
                    $record['category'],
                    $record['resource'],
                    $record['asset'],
                    $record['path'],
                    $record['kind'],
                    $record['prefixes'],
                );
                $manifest['entries'][$entryKey] = [
                    'table' => $record['table'],
                    'id' => $record['id'],
                    'tenant' => $record['tenant'],
                    'field' => $record['field'],
                    'checksum_field' => $record['checksum_field'],
                    'kind' => $record['kind'],
                    'asset' => $record['asset'],
                    'old_path' => $record['path'],
                    'old_sha256' => $legacySha,
                    'new_path' => $stored['path'],
                    'new_sha256' => $stored['sha256'],
                    'copied_at' => now()->toIso8601String(),
                    'switched_at' => null,
                    'purged_at' => null,
                    'restored_at' => null,
                ];
                $report['copied']++;
            } catch (PrivateAssetIntegrityException $e) {
                $this->addError($report, $e->reason);
            }
        }

        $this->inspectPublicOrphans($tenantId, $manifest, $report, true);

        $this->saveManifest($manifest);
        $report['ok'] = $report['errors'] === 0;

        return $report;
    }

    private function switchPrivate(?int $tenantId): array
    {
        $manifestExisted = Storage::disk('local')->exists(self::MANIFEST_PATH);
        $manifest = $this->loadManifest();
        $originalManifest = $manifest;
        $report = $this->baseReport('switch-private');
        $updates = [];

        foreach ($this->records($tenantId) as $record) {
            $report['referenced']++;
            try {
                if ($this->isPrivate($record)) {
                    $this->inspect($record);
                    $report['private']++;

                    continue;
                }

                $legacySha = $this->inspect($record);
                $key = $this->entryKey($record, $record['path']);
                $entry = $manifest['entries'][$key] ?? null;
                if ($entry === null || ! hash_equals($legacySha, (string) $entry['old_sha256'])
                    || ! $this->privateEntryIsValid($record, $entry)) {
                    throw new PrivateAssetIntegrityException('copy_required', 'A current reference has not completed copy-private.', 409);
                }
                $updates[] = [$record, $entry, $key];
            } catch (PrivateAssetIntegrityException $e) {
                $this->addError($report, $e->reason);
            }
        }

        $this->verifyManifestOrphans($tenantId, $manifest, $report, true);

        if ($report['errors'] > 0) {
            $report['ok'] = false;

            return $report;
        }

        try {
            DB::transaction(function () use ($updates, &$manifest): void {
                foreach ($updates as [$record, $entry]) {
                    $changed = DB::table($record['table'])
                        ->where('id', $record['id'])
                        ->where($record['field'], $record['path'])
                        ->update([
                            $record['field'] => $entry['new_path'],
                            $record['checksum_field'] => $entry['new_sha256'],
                            'updated_at' => now(),
                        ]);
                    if ($changed !== 1) {
                        throw new RuntimeException('Optimistic cutover switch failed; no partial DB switch was committed.');
                    }
                }

                foreach ($updates as [, , $key]) {
                    $manifest['entries'][$key]['switched_at'] = now()->toIso8601String();
                }
                $this->saveManifest($manifest);
            });
        } catch (\Throwable $failure) {
            try {
                if ($manifestExisted) {
                    $this->saveManifest($originalManifest);
                } else {
                    Storage::disk('local')->delete(self::MANIFEST_PATH);
                }
            } catch (\Throwable) {
                throw new RuntimeException('Switch failed and manifest rollback was incomplete.', 0, $failure);
            }

            throw $failure;
        }

        $report['switched'] = count($updates);
        $report['ok'] = true;

        return $report;
    }

    private function purgePublic(?int $tenantId, bool $uatConfirmed): array
    {
        if (! $uatConfirmed) {
            throw new RuntimeException('purge-public requires explicit UAT confirmation.');
        }

        $manifest = $this->loadManifest();
        $report = $this->baseReport('purge-public');
        foreach ($this->records($tenantId) as $record) {
            $report['referenced']++;
            try {
                if (! $this->isPrivate($record)) {
                    throw new PrivateAssetIntegrityException('unswitched_reference', 'A public DB reference remains.', 409);
                }
                $this->inspect($record);
            } catch (PrivateAssetIntegrityException $e) {
                $this->addError($report, $e->reason);
            }
        }
        $this->verifyManifestOrphans($tenantId, $manifest, $report, true);

        $entries = array_filter(
            $manifest['entries'],
            fn (array $entry): bool => $tenantId === null || (int) $entry['tenant'] === $tenantId,
        );
        $currentPublicRefs = array_fill_keys(array_map(
            fn (array $record): string => $record['path'],
            array_filter($this->records(null), fn (array $record): bool => ! $this->isPrivate($record)),
        ), true);
        $objects = [];
        foreach ($entries as $key => $entry) {
            $oldPath = (string) $entry['old_path'];
            if (isset($currentPublicRefs[$oldPath])) {
                $this->addError($report, 'public_reference_remains');

                continue;
            }
            if (isset($objects[$oldPath])) {
                $objects[$oldPath]['keys'][] = $key;

                continue;
            }
            $objects[$oldPath] = ['entry' => $entry, 'keys' => [$key]];
        }

        foreach ($objects as $oldPath => &$object) {
            $entry = $object['entry'];
            if (! Storage::disk('public')->exists($oldPath)) {
                if ($entry['purged_at'] === null) {
                    $this->addError($report, 'missing_public_original');
                }

                continue;
            }

            $bytes = Storage::disk('public')->get($oldPath);
            $this->validateBytes($entry['kind'], $bytes);
            if (! hash_equals((string) $entry['old_sha256'], hash('sha256', $bytes))) {
                $this->addError($report, 'tampered_public_original');

                continue;
            }
            $quarantine = $this->quarantinePath($entry);
            if (! Storage::disk('local')->exists($quarantine)) {
                Storage::disk('local')->put($quarantine, $bytes);
                if (PHP_OS_FAMILY !== 'Windows') {
                    @chmod(Storage::disk('local')->path($quarantine), 0600);
                    @chmod(dirname(Storage::disk('local')->path($quarantine)), 0700);
                }
            }
            if (! hash_equals((string) $entry['old_sha256'], hash('sha256', Storage::disk('local')->get($quarantine)))) {
                $this->addError($report, 'quarantine_verification_failed');

                continue;
            }
            $object['quarantine'] = $quarantine;
        }
        unset($object);

        $orphanObjects = [];
        foreach ($manifest['orphans'] ?? [] as $key => $orphan) {
            if (! $this->orphanEntryInScope($tenantId, $orphan)) {
                continue;
            }

            $oldPath = (string) $orphan['old_path'];
            if (! Storage::disk('public')->exists($oldPath)) {
                continue;
            }

            $bytes = Storage::disk('public')->get($oldPath);
            try {
                $this->validateBytes((string) $orphan['kind'], $bytes);
                if (! hash_equals((string) $orphan['old_sha256'], hash('sha256', $bytes))) {
                    throw new PrivateAssetIntegrityException('orphan_drift', 'Public orphan changed after inventory.', 409);
                }
                $this->verifyQuarantineEntry($orphan);
                $orphanObjects[$oldPath] = ['key' => $key, 'entry' => $orphan];
            } catch (PrivateAssetIntegrityException $e) {
                $this->addError($report, $e->reason);
            }
        }

        if ($report['errors'] > 0) {
            $report['ok'] = false;

            return $report;
        }

        foreach ($objects as $oldPath => $object) {
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
            if (Storage::disk('public')->exists($oldPath)) {
                throw new RuntimeException('Public object purge verification failed.');
            }
            foreach ($object['keys'] as $key) {
                $manifest['entries'][$key]['quarantine_path'] = $object['quarantine'] ?? $this->quarantinePath($object['entry']);
                $manifest['entries'][$key]['purged_at'] = now()->toIso8601String();
            }
            $report['purged']++;
        }
        foreach ($orphanObjects as $oldPath => $object) {
            Storage::disk('public')->delete($oldPath);
            if (Storage::disk('public')->exists($oldPath)) {
                throw new RuntimeException('Public orphan purge verification failed.');
            }
            $manifest['orphans'][$object['key']]['purged_at'] = now()->toIso8601String();
            $manifest['orphans'][$object['key']]['restored_at'] = null;
            $report['orphans_purged']++;
        }
        $this->saveManifest($manifest);
        $report['ok'] = true;

        return $report;
    }

    private function restorePublic(?int $tenantId): array
    {
        $manifestExisted = Storage::disk('local')->exists(self::MANIFEST_PATH);
        $manifest = $this->loadManifest();
        $originalManifest = $manifest;
        $report = $this->baseReport('restore-public');
        $updates = [];
        $publicWrites = [];

        foreach ($this->records($tenantId) as $record) {
            $report['referenced']++;
            try {
                if (! $this->isPrivate($record)) {
                    $this->inspect($record);
                    $report['legacy']++;

                    continue;
                }

                $bytes = $this->privateAssets->readVerified(
                    $record['tenant'],
                    $record['category'],
                    $record['resource'],
                    $record['path'],
                    $record['checksum'],
                    $record['kind'],
                );
                $entryKey = null;
                $entry = null;
                foreach ($manifest['entries'] as $candidateKey => $candidate) {
                    if ($candidate['table'] === $record['table']
                        && (string) $candidate['id'] === (string) $record['id']
                        && $candidate['field'] === $record['field']
                        && $candidate['new_path'] === $record['path']) {
                        $entryKey = $candidateKey;
                        $entry = $candidate;
                        break;
                    }
                }

                if ($entry === null) {
                    $oldPath = $this->newRollbackPublicPath($record);
                    $sha = hash('sha256', $bytes);
                    $entryKey = $this->entryKey($record, $oldPath);
                    $entry = [
                        'table' => $record['table'], 'id' => $record['id'], 'tenant' => $record['tenant'],
                        'field' => $record['field'], 'checksum_field' => $record['checksum_field'],
                        'kind' => $record['kind'], 'asset' => $record['asset'],
                        'old_path' => $oldPath, 'old_sha256' => $sha,
                        'new_path' => $record['path'], 'new_sha256' => $sha,
                        'copied_at' => now()->toIso8601String(), 'switched_at' => now()->toIso8601String(),
                        'purged_at' => null, 'restored_at' => null,
                    ];
                    $manifest['entries'][$entryKey] = $entry;
                    $report['new_refs']++;
                }

                $oldPath = (string) $entry['old_path'];
                $this->privateAssets->assertLegacyPath($oldPath, $record['prefixes']);
                if (Storage::disk('public')->exists($oldPath)) {
                    $existing = Storage::disk('public')->get($oldPath);
                    $this->validateBytes($record['kind'], $existing);
                    if (! hash_equals((string) $entry['old_sha256'], hash('sha256', $existing))) {
                        throw new PrivateAssetIntegrityException('restore_collision', 'Rollback public object differs from manifest.', 409);
                    }
                }
                if (isset($publicWrites[$oldPath])
                    && ! hash_equals($publicWrites[$oldPath]['sha256'], (string) $entry['old_sha256'])) {
                    throw new PrivateAssetIntegrityException('restore_target_collision', 'Two restore objects target different bytes.', 409);
                }
                $publicWrites[$oldPath] = [
                    'bytes' => $bytes,
                    'sha256' => (string) $entry['old_sha256'],
                    'kind' => $record['kind'],
                ];
                $updates[] = [$record, $entry, $entryKey];
            } catch (PrivateAssetIntegrityException $e) {
                $this->addError($report, $e->reason);
            }
        }

        $orphanRestores = [];
        if (! $this->orphanScanAllowed($tenantId)) {
            $this->addError($report, 'orphan_scan_requires_global_scope');
        } else {
            foreach ($manifest['orphans'] ?? [] as $key => $orphan) {
                if (! $this->orphanEntryInScope($tenantId, $orphan)
                    || $orphan['purged_at'] === null
                    || $orphan['restored_at'] !== null) {
                    continue;
                }

                try {
                    $this->verifyQuarantineEntry($orphan);
                    $oldPath = (string) $orphan['old_path'];
                    $this->privateAssets->assertLegacyPath($oldPath, [(string) $orphan['prefix'].'/']);
                    if (Storage::disk('public')->exists($oldPath)) {
                        $bytes = Storage::disk('public')->get($oldPath);
                        $this->validateBytes((string) $orphan['kind'], $bytes);
                        if (! hash_equals((string) $orphan['old_sha256'], hash('sha256', $bytes))) {
                            throw new PrivateAssetIntegrityException('restore_collision', 'Public orphan restore target differs.', 409);
                        }
                    } else {
                        $bytes = Storage::disk('local')->get((string) $orphan['quarantine_path']);
                    }
                    if (isset($publicWrites[$oldPath])
                        && ! hash_equals($publicWrites[$oldPath]['sha256'], (string) $orphan['old_sha256'])) {
                        throw new PrivateAssetIntegrityException('restore_target_collision', 'Two restore objects target different bytes.', 409);
                    }
                    $publicWrites[$oldPath] = [
                        'bytes' => $bytes,
                        'sha256' => (string) $orphan['old_sha256'],
                        'kind' => (string) $orphan['kind'],
                    ];
                    $orphanRestores[] = [$key, $orphan];
                } catch (PrivateAssetIntegrityException $e) {
                    $this->addError($report, $e->reason);
                }
            }
        }

        if ($report['errors'] > 0) {
            $report['ok'] = false;

            return $report;
        }

        $createdPublicPaths = [];
        try {
            DB::transaction(function () use (
                $updates,
                $orphanRestores,
                $publicWrites,
                &$createdPublicPaths,
                &$manifest,
            ): void {
                foreach ($publicWrites as $path => $object) {
                    if (Storage::disk('public')->exists($path)) {
                        $existing = Storage::disk('public')->get($path);
                        $this->validateBytes($object['kind'], $existing);
                        if (! hash_equals($object['sha256'], hash('sha256', $existing))) {
                            throw new RuntimeException('Restore target changed after prevalidation.');
                        }

                        continue;
                    }
                    if (! Storage::disk('public')->put($path, $object['bytes'])) {
                        throw new RuntimeException('Public restore write failed.');
                    }
                    $createdPublicPaths[] = $path;
                    $restored = Storage::disk('public')->get($path);
                    $this->validateBytes($object['kind'], $restored);
                    if (! hash_equals($object['sha256'], hash('sha256', $restored))) {
                        throw new RuntimeException('Public restore read-back verification failed.');
                    }
                }

                foreach ($updates as [$record, $entry]) {
                    $changed = DB::table($record['table'])
                        ->where('id', $record['id'])
                        ->where($record['field'], $record['path'])
                        ->update([
                            $record['field'] => $entry['old_path'],
                            $record['checksum_field'] => $entry['old_sha256'],
                            'updated_at' => now(),
                        ]);
                    if ($changed !== 1) {
                        throw new RuntimeException('Optimistic restore failed; no partial DB restore was committed.');
                    }
                }

                foreach ($updates as [, , $key]) {
                    $manifest['entries'][$key]['restored_at'] = now()->toIso8601String();
                }
                foreach ($orphanRestores as [$key]) {
                    $manifest['orphans'][$key]['restored_at'] = now()->toIso8601String();
                }
                $this->saveManifest($manifest);
            });
        } catch (\Throwable $failure) {
            $cleanupFailed = false;
            foreach (array_reverse($createdPublicPaths) as $path) {
                Storage::disk('public')->delete($path);
                $cleanupFailed = $cleanupFailed || Storage::disk('public')->exists($path);
            }
            try {
                if ($manifestExisted) {
                    $this->saveManifest($originalManifest);
                } else {
                    Storage::disk('local')->delete(self::MANIFEST_PATH);
                    $cleanupFailed = $cleanupFailed || Storage::disk('local')->exists(self::MANIFEST_PATH);
                }
            } catch (\Throwable) {
                $cleanupFailed = true;
            }
            if ($cleanupFailed) {
                throw new RuntimeException('Restore failed and compensating cleanup was incomplete.', 0, $failure);
            }

            throw $failure;
        }

        $report['restored'] = count($updates);
        $report['orphans_restored'] = count($orphanRestores);
        $report['ok'] = true;

        return $report;
    }

    private function remediateMissingDirectorReferences(?int $tenantId, ?string $suppliedEvidence): array
    {
        $manifest = $this->loadManifest();
        $report = $this->baseReport('remediate-missing');

        foreach ($this->records($tenantId) as $record) {
            if ($record['table'] !== 'contracts' || $record['field'] !== 'direktur_signature_path') {
                continue;
            }

            try {
                $this->inspect($record);

                continue;
            } catch (PrivateAssetIntegrityException $e) {
                if ($e->reason !== 'missing') {
                    $this->addError($report, $e->reason);

                    continue;
                }
            }

            $expectedEvidence = $this->missingReferenceEvidence($record);
            if ($expectedEvidence === null) {
                $this->addError($report, 'manual_missing_reference_review_required');

                continue;
            }
            if ($suppliedEvidence === null || ! hash_equals($expectedEvidence, strtolower($suppliedEvidence))) {
                $report['remediation_evidence'][] = $expectedEvidence;
                $this->addError($report, 'remediation_evidence_required');

                continue;
            }

            $fingerprint = hash('sha256', $record['path']);
            $changed = DB::table('contracts')
                ->where('id', $record['id'])
                ->where('company_id', $record['tenant'])
                ->where('status', 'archived')
                ->whereNull('signed_pdf_path')
                ->whereNull('materai_pdf_path')
                ->where('direktur_signature_path', $record['path'])
                ->update([
                    'direktur_signature_path' => null,
                    'direktur_signature_sha256' => null,
                    'direktur_signature_unavailable_at' => now(),
                    'direktur_signature_unavailable_reason' => 'legacy_object_missing',
                    'direktur_signature_reference_fingerprint' => $fingerprint,
                    'updated_at' => now(),
                ]);
            if ($changed !== 1) {
                throw new RuntimeException('Optimistic missing-reference tombstone failed.');
            }

            $key = hash('sha256', implode('|', ['contracts', $record['id'], 'direktur_signature_path', $record['path']]));
            $manifest['remediations'][$key] = [
                'table' => 'contracts',
                'id' => $record['id'],
                'tenant' => $record['tenant'],
                'field' => 'direktur_signature_path',
                'old_reference_fingerprint' => $fingerprint,
                'evidence' => $expectedEvidence,
                'disposition' => 'tombstone_unavailable',
                'rollback_disposition' => 'preserve_tombstone_until_verified_replacement',
                'remediated_at' => now()->toIso8601String(),
            ];
            $report['remediated']++;
        }

        $this->saveManifest($manifest);
        $report['ok'] = $report['errors'] === 0;

        return $report;
    }

    private function missingReferenceEvidence(array $record): ?string
    {
        if ($record['table'] !== 'contracts' || $record['field'] !== 'direktur_signature_path') {
            return null;
        }

        $contract = Contract::query()->withoutGlobalScopes()->find($record['id']);
        if (! $contract || $contract->status !== 'archived'
            || filled($contract->signed_pdf_path) || filled($contract->materai_pdf_path)) {
            return null;
        }

        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('APP_KEY is required for remediation evidence.');
        }

        return hash_hmac('sha256', implode('|', [
            self::RELEASE,
            'contracts',
            $record['id'],
            $record['tenant'],
            'direktur_signature_path',
            hash('sha256', $record['path']),
            'archived',
            'no-signed-artifact',
        ]), $key);
    }

    private function inspectPublicOrphans(
        ?int $tenantId,
        array &$manifest,
        array &$report,
        bool $capture,
    ): void {
        if (! $this->orphanScanAllowed($tenantId)) {
            $this->addError($report, 'orphan_scan_requires_global_scope');

            return;
        }

        foreach ($this->publicOrphanInventory($manifest) as $path => $kind) {
            $report['public_orphans']++;
            try {
                $bytes = Storage::disk('public')->get($path);
                $this->validateBytes($kind, $bytes);
                $sha = hash('sha256', $bytes);
                $key = hash('sha256', $path);
                $entry = $manifest['orphans'][$key] ?? null;
                if ($entry !== null && ! hash_equals((string) $entry['old_sha256'], $sha)) {
                    throw new PrivateAssetIntegrityException('orphan_drift', 'Public orphan changed after inventory.', 409);
                }

                if (! $capture) {
                    continue;
                }

                if ($entry === null) {
                    $prefix = $this->namespaceForPath($path);
                    $entry = [
                        'tenant_hint' => $tenantId,
                        'prefix' => $prefix,
                        'kind' => $kind,
                        'old_path' => $path,
                        'old_sha256' => $sha,
                        'quarantine_path' => $this->orphanQuarantinePath($sha, $kind),
                        'captured_at' => now()->toIso8601String(),
                        'purged_at' => null,
                        'restored_at' => null,
                    ];
                    $manifest['orphans'][$key] = $entry;
                    $report['orphans_captured']++;
                } else {
                    $report['orphans_reused']++;
                }

                if (! Storage::disk('local')->exists((string) $entry['quarantine_path'])) {
                    Storage::disk('local')->put((string) $entry['quarantine_path'], $bytes);
                    if (PHP_OS_FAMILY !== 'Windows') {
                        @chmod(Storage::disk('local')->path((string) $entry['quarantine_path']), 0600);
                        @chmod(dirname(Storage::disk('local')->path((string) $entry['quarantine_path'])), 0700);
                    }
                }
                $this->verifyQuarantineEntry($entry);
            } catch (PrivateAssetIntegrityException $e) {
                $this->addError($report, $e->reason);
            }
        }
    }

    private function verifyManifestOrphans(
        ?int $tenantId,
        array $manifest,
        array &$report,
        bool $rejectUncaptured,
    ): void {
        if (! $this->orphanScanAllowed($tenantId)) {
            $this->addError($report, 'orphan_scan_requires_global_scope');

            return;
        }

        $inventory = $this->publicOrphanInventory($manifest);
        foreach ($inventory as $path => $kind) {
            $key = hash('sha256', $path);
            if (! isset($manifest['orphans'][$key])) {
                if ($rejectUncaptured) {
                    $this->addError($report, 'orphan_copy_required');
                }

                continue;
            }

            $entry = $manifest['orphans'][$key];
            try {
                $bytes = Storage::disk('public')->get($path);
                $this->validateBytes($kind, $bytes);
                if (! hash_equals((string) $entry['old_sha256'], hash('sha256', $bytes))) {
                    throw new PrivateAssetIntegrityException('orphan_drift', 'Public orphan changed after inventory.', 409);
                }
                $this->verifyQuarantineEntry($entry);
            } catch (PrivateAssetIntegrityException $e) {
                $this->addError($report, $e->reason);
            }
        }

        foreach ($manifest['orphans'] ?? [] as $entry) {
            if (! $this->orphanEntryInScope($tenantId, $entry)) {
                continue;
            }

            try {
                $this->verifyQuarantineEntry($entry);
                $expectsPublic = $entry['purged_at'] === null || $entry['restored_at'] !== null;
                if ($expectsPublic && ! Storage::disk('public')->exists((string) $entry['old_path'])) {
                    throw new PrivateAssetIntegrityException('missing_public_orphan', 'Inventoried public orphan is missing.', 409);
                }
            } catch (PrivateAssetIntegrityException $e) {
                $this->addError($report, $e->reason);
            }
        }
    }

    /** @return array<string,string> path => kind */
    private function publicOrphanInventory(array $manifest): array
    {
        $protected = $this->protectedPublicPaths($manifest);
        $orphans = [];
        foreach (self::PUBLIC_NAMESPACES as $prefix => $kind) {
            foreach (Storage::disk('public')->allFiles($prefix) as $path) {
                if (! isset($protected[$path])) {
                    $orphans[$path] = $kind;
                }
            }
        }

        ksort($orphans);

        return $orphans;
    }

    /** @return array<string,true> */
    private function protectedPublicPaths(array $manifest): array
    {
        $protected = [];
        foreach ($this->records(null) as $record) {
            if (! $this->isPrivate($record)) {
                $protected[$record['path']] = true;
            }
        }
        foreach ($manifest['entries'] ?? [] as $entry) {
            $protected[(string) $entry['old_path']] = true;
        }

        $columns = [
            'users' => ['finance_signature_path', 'hrd_signature_path', 'director_signature_path'],
            'letter_signatures' => ['signature_path', 'stamp_path'],
        ];
        foreach ($columns as $table => $fields) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($fields as $field) {
                if (! Schema::hasColumn($table, $field)) {
                    continue;
                }
                foreach (DB::table($table)->whereNotNull($field)->pluck($field) as $path) {
                    if (is_string($path) && $path !== '') {
                        $protected[$path] = true;
                    }
                }
            }
        }

        return $protected;
    }

    private function verifyQuarantineEntry(array $entry): void
    {
        $path = (string) $entry['quarantine_path'];
        if (! Storage::disk('local')->exists($path)) {
            throw new PrivateAssetIntegrityException('missing_orphan_quarantine', 'Orphan quarantine copy is missing.', 409);
        }
        $bytes = Storage::disk('local')->get($path);
        $this->validateBytes((string) $entry['kind'], $bytes);
        if (! hash_equals((string) $entry['old_sha256'], hash('sha256', $bytes))) {
            throw new PrivateAssetIntegrityException('tampered_orphan_quarantine', 'Orphan quarantine copy failed integrity validation.', 409);
        }
    }

    private function orphanScanAllowed(?int $tenantId): bool
    {
        if ($tenantId === null) {
            return true;
        }

        $ids = Company::query()->withoutGlobalScopes()->orderBy('id')->pluck('id');

        return $ids->count() === 1 && (int) $ids->first() === $tenantId;
    }

    private function orphanEntryInScope(?int $tenantId, array $entry): bool
    {
        return $tenantId === null || (int) ($entry['tenant_hint'] ?? 0) === $tenantId;
    }

    private function namespaceForPath(string $path): string
    {
        foreach (array_keys(self::PUBLIC_NAMESPACES) as $prefix) {
            if (str_starts_with($path, $prefix.'/')) {
                return $prefix;
            }
        }

        throw new PrivateAssetIntegrityException('invalid_path', 'Public orphan is outside the bounded namespaces.', 409);
    }

    private function orphanQuarantinePath(string $sha256, string $kind): string
    {
        $extension = $kind === 'png' ? 'png' : 'pdf';

        return 'private/cutovers/'.self::RELEASE.'/orphan-quarantine/'.$sha256.'.'.$extension;
    }

    /** @return list<array<string,mixed>> */
    private function records(?int $tenantId): array
    {
        $records = [];
        $companies = Company::query()->withoutGlobalScopes()
            ->when($tenantId !== null, fn ($query) => $query->whereKey($tenantId))
            ->get();
        foreach ($companies as $company) {
            foreach (self::COMPANY_FIELDS as $field => $definition) {
                if (! is_string($company->{$field}) || $company->{$field} === '') {
                    continue;
                }
                $records[] = $this->record('companies', $company->id, $company->id, 'company-signatures', $field, $company->{$field}, $company->{$definition['checksum']}, $definition);
            }
        }

        $contracts = Contract::query()->withoutGlobalScopes()
            ->when($tenantId !== null, fn ($query) => $query->where('company_id', $tenantId))
            ->get();
        foreach ($contracts as $contract) {
            foreach (self::CONTRACT_FIELDS as $field => $definition) {
                if (! is_string($contract->{$field}) || $contract->{$field} === '') {
                    continue;
                }
                $records[] = $this->record('contracts', $contract->id, $contract->company_id, 'contracts', $field, $contract->{$field}, $contract->{$definition['checksum']}, $definition);
            }
        }

        return $records;
    }

    private function record(string $table, mixed $id, mixed $tenant, string $category, string $field, string $path, ?string $checksum, array $definition): array
    {
        return [
            'table' => $table, 'id' => $id, 'tenant' => (int) $tenant,
            'category' => $category, 'resource' => (string) $id,
            'field' => $field, 'checksum_field' => $definition['checksum'],
            'path' => $path, 'checksum' => $checksum,
            'kind' => $definition['kind'], 'prefixes' => $definition['prefixes'],
            'asset' => $definition['asset'],
        ];
    }

    private function inspect(array $record): string
    {
        if ($this->isPrivate($record)) {
            $bytes = $this->privateAssets->readVerified(
                $record['tenant'], $record['category'], $record['resource'],
                $record['path'], $record['checksum'], $record['kind'],
            );

            return hash('sha256', $bytes);
        }

        $this->privateAssets->assertLegacyPath($record['path'], $record['prefixes']);
        if (! Storage::disk('public')->exists($record['path'])) {
            throw new PrivateAssetIntegrityException('missing', 'Referenced legacy object is missing.', 410);
        }
        $bytes = Storage::disk('public')->get($record['path']);
        $this->validateBytes($record['kind'], $bytes);

        return hash('sha256', $bytes);
    }

    private function isPrivate(array $record): bool
    {
        return $this->privateAssets->isPrivatePathFor(
            $record['tenant'], $record['category'], $record['resource'], $record['path'],
        );
    }

    private function privateEntryIsValid(array $record, array $entry): bool
    {
        try {
            $this->privateAssets->readVerified(
                $record['tenant'], $record['category'], $record['resource'],
                $entry['new_path'], $entry['new_sha256'], $record['kind'],
            );

            return true;
        } catch (PrivateAssetIntegrityException) {
            return false;
        }
    }

    private function validateBytes(string $kind, string $bytes): void
    {
        $kind === 'png' ? $this->privateAssets->validatePng($bytes) : $this->privateAssets->validatePdf($bytes);
    }

    private function newRollbackPublicPath(array $record): string
    {
        $prefix = rtrim($record['prefixes'][0], '/');
        $extension = $record['kind'] === 'png' ? 'png' : 'pdf';

        return $prefix.'/rollback-'.$record['tenant'].'-'.$record['id'].'-'.$record['asset'].'-'.Str::uuid().'.'.$extension;
    }

    private function quarantinePath(array $entry): string
    {
        $extension = $entry['kind'] === 'png' ? 'png' : 'pdf';

        return 'private/cutovers/'.self::RELEASE.'/quarantine/'.$entry['old_sha256'].'.'.$extension;
    }

    private function entryKey(array $record, string $oldPath): string
    {
        return hash('sha256', implode('|', [$record['table'], $record['id'], $record['field'], $oldPath]));
    }

    private function loadManifest(): array
    {
        if (! Storage::disk('local')->exists(self::MANIFEST_PATH)) {
            return [
                'schema_version' => 1,
                'release' => self::RELEASE,
                'created_at' => now()->toIso8601String(),
                'entries' => [],
                'orphans' => [],
                'remediations' => [],
            ];
        }
        $manifest = json_decode(Storage::disk('local')->get(self::MANIFEST_PATH), true, flags: JSON_THROW_ON_ERROR);
        if ((int) ($manifest['schema_version'] ?? 0) !== 1
            || ($manifest['release'] ?? null) !== self::RELEASE
            || ! is_array($manifest['entries'] ?? null)) {
            throw new RuntimeException('Cutover manifest is invalid.');
        }
        $manifest['orphans'] ??= [];
        $manifest['remediations'] ??= [];
        if (! is_array($manifest['orphans']) || ! is_array($manifest['remediations'])) {
            throw new RuntimeException('Cutover manifest extensions are invalid.');
        }

        return $manifest;
    }

    private function saveManifest(array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! Storage::disk('local')->put(self::MANIFEST_PATH, $json)) {
            throw new RuntimeException('Cutover manifest write failed.');
        }
        if (! Storage::disk('local')->exists(self::MANIFEST_PATH)
            || ! hash_equals(hash('sha256', $json), hash('sha256', Storage::disk('local')->get(self::MANIFEST_PATH)))) {
            throw new RuntimeException('Cutover manifest read-back verification failed.');
        }
        $absolute = Storage::disk('local')->path(self::MANIFEST_PATH);
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($absolute, 0600);
            @chmod(dirname($absolute), 0700);
        }
    }

    private function baseReport(string $phase): array
    {
        return [
            'release' => self::RELEASE, 'phase' => $phase, 'ok' => false,
            'referenced' => 0, 'legacy' => 0, 'private' => 0,
            'copied' => 0, 'reused' => 0, 'switched' => 0,
            'purged' => 0, 'restored' => 0, 'new_refs' => 0,
            'public_orphans' => 0, 'orphans_captured' => 0, 'orphans_reused' => 0,
            'orphans_purged' => 0, 'orphans_restored' => 0,
            'remediated' => 0, 'remediation_evidence' => [],
            'errors' => 0, 'error_reasons' => [],
        ];
    }

    private function addError(array &$report, string $reason): void
    {
        $report['errors']++;
        $report['error_reasons'][$reason] = ($report['error_reasons'][$reason] ?? 0) + 1;
    }
}

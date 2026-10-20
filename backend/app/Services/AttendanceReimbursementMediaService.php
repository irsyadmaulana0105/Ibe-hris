<?php

namespace App\Services;

use App\Exceptions\PrivateAssetIntegrityException;
use App\Models\Attendance;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Http\Response;

class AttendanceReimbursementMediaService
{
    public const ATTENDANCE_CATEGORY = 'attendance-media';

    public const REIMBURSEMENT_CATEGORY = 'reimbursement-media';

    private const ATTENDANCE_FIELDS = [
        'selfie-in' => ['path' => 'selfie_in_path', 'prefix' => 'selfie_in', 'asset' => 'selfie-in', 'mimes' => ['image/jpeg', 'image/png'], 'ttl' => 120],
        'selfie-out' => ['path' => 'selfie_out_path', 'prefix' => 'selfie_out', 'asset' => 'selfie-out', 'mimes' => ['image/jpeg', 'image/png'], 'ttl' => 120],
        'attachment' => ['path' => 'attachment', 'prefix' => 'attachment', 'asset' => 'attachment', 'mimes' => ['image/jpeg', 'image/png', 'application/pdf'], 'ttl' => 120],
    ];

    private const REIMBURSEMENT_FIELDS = [
        'attachment' => ['path' => 'attachment_path', 'prefix' => 'attachment', 'asset' => 'attachment', 'mimes' => ['image/jpeg', 'image/png', 'application/pdf'], 'ttl' => 300],
        'payment-proof' => ['path' => 'payment_proof_path', 'prefix' => 'payment_proof', 'asset' => 'payment-proof', 'mimes' => ['image/jpeg', 'image/png', 'application/pdf'], 'ttl' => 300],
        'receipt' => ['path' => 'receipt_pdf_path', 'prefix' => 'receipt_pdf', 'asset' => 'receipt', 'mimes' => ['application/pdf'], 'ttl' => 300],
    ];

    public function __construct(private readonly PrivateMediaStorage $storage) {}

    public function attendanceUrl(User $actor, Attendance $attendance, string $field): ?string
    {
        try {
            $this->authorizeAttendance($actor, $attendance);
            $config = $this->attendanceField($field);
            $this->attendanceBytes($attendance, $config);

            return $this->storage->temporarySignedRoute(
                'api.v1.private-media.attendance',
                ['attendance' => $attendance->id, 'field' => $field],
                $config['ttl'],
            );
        } catch (PrivateAssetIntegrityException) {
            return null;
        }
    }

    public function reimbursementUrl(User $actor, Reimbursement $reimbursement, string $field): ?string
    {
        try {
            $this->authorizeReimbursement($actor, $reimbursement);
            $config = $this->reimbursementField($field);
            $this->reimbursementBytes($reimbursement, $config);

            return $this->storage->temporarySignedRoute(
                'api.v1.private-media.reimbursement',
                ['reimbursement' => $reimbursement->id, 'field' => $field],
                $config['ttl'],
            );
        } catch (PrivateAssetIntegrityException) {
            return null;
        }
    }

    public function authenticatedAttendance(User $actor, int $attendanceId, string $field): Response
    {
        $attendance = Attendance::query()->with('user:id,company_id')->findOrFail($attendanceId);
        $this->authorizeAttendance($actor, $attendance);

        return $this->attendanceResponse($attendance, $field);
    }

    public function signedAttendance(int $attendanceId, string $field): Response
    {
        return $this->attendanceResponse(
            Attendance::query()->with('user:id,company_id')->findOrFail($attendanceId),
            $field,
        );
    }

    public function authenticatedReimbursement(User $actor, int $reimbursementId, string $field): Response
    {
        $reimbursement = Reimbursement::withoutGlobalScopes()->with('user:id,company_id')->findOrFail($reimbursementId);
        $this->authorizeReimbursement($actor, $reimbursement);

        return $this->reimbursementResponse($reimbursement, $field);
    }

    public function signedReimbursement(int $reimbursementId, string $field): Response
    {
        return $this->reimbursementResponse(
            Reimbursement::withoutGlobalScopes()->with('user:id,company_id')->findOrFail($reimbursementId),
            $field,
        );
    }

    private function attendanceResponse(Attendance $attendance, string $field): Response
    {
        $config = $this->attendanceField($field);
        $tenantId = $this->attendanceTenant($attendance);
        $path = (string) $attendance->getAttribute($config['path']);
        if ($path === '' || $attendance->getAttribute($config['prefix'].'_unavailable_reason')) {
            throw new PrivateAssetIntegrityException('unavailable', 'Media is unavailable.', 410);
        }
        $metadata = $this->metadata($attendance, $config['prefix']);

        return $this->storage->downloadResponse(
            $tenantId,
            self::ATTENDANCE_CATEGORY,
            $attendance->id,
            $field,
            $path,
            $metadata,
            $config['mimes'],
            'attendance-'.$attendance->id.'-'.$field.'.'.$this->extension((string) $metadata['mime']),
        );
    }

    private function reimbursementResponse(Reimbursement $reimbursement, string $field): Response
    {
        $config = $this->reimbursementField($field);
        $tenantId = $this->reimbursementTenant($reimbursement);
        $path = (string) $reimbursement->getAttribute($config['path']);
        if ($path === '' || $reimbursement->getAttribute($config['prefix'].'_unavailable_reason')) {
            throw new PrivateAssetIntegrityException('unavailable', 'Media is unavailable.', 410);
        }
        $metadata = $this->metadata($reimbursement, $config['prefix']);

        return $this->storage->downloadResponse(
            $tenantId,
            self::REIMBURSEMENT_CATEGORY,
            $reimbursement->id,
            $field,
            $path,
            $metadata,
            $config['mimes'],
            'reimbursement-'.$reimbursement->id.'-'.$field.'.'.$this->extension((string) $metadata['mime']),
        );
    }

    private function attendanceBytes(Attendance $attendance, array $config): string
    {
        $path = (string) $attendance->getAttribute($config['path']);
        if ($path === '' || $attendance->getAttribute($config['prefix'].'_unavailable_reason')) {
            throw new PrivateAssetIntegrityException('unavailable', 'Media is unavailable.', 410);
        }

        return $this->storage->readVerified(
            $this->attendanceTenant($attendance),
            self::ATTENDANCE_CATEGORY,
            $attendance->id,
            $config['asset'],
            $path,
            $this->metadata($attendance, $config['prefix']),
            $config['mimes'],
        );
    }

    private function reimbursementBytes(Reimbursement $reimbursement, array $config): string
    {
        $path = (string) $reimbursement->getAttribute($config['path']);
        if ($path === '' || $reimbursement->getAttribute($config['prefix'].'_unavailable_reason')) {
            throw new PrivateAssetIntegrityException('unavailable', 'Media is unavailable.', 410);
        }

        return $this->storage->readVerified(
            $this->reimbursementTenant($reimbursement),
            self::REIMBURSEMENT_CATEGORY,
            $reimbursement->id,
            $config['asset'],
            $path,
            $this->metadata($reimbursement, $config['prefix']),
            $config['mimes'],
        );
    }

    private function authorizeAttendance(User $actor, Attendance $attendance): void
    {
        $tenantId = $this->attendanceTenant($attendance);
        $allowed = (string) $attendance->user_id === (string) $actor->id
            || ((int) $actor->company_id === $tenantId && $actor->hasCapability('attendance.manage'));
        if (! $allowed || (int) $actor->company_id !== $tenantId) {
            throw new PrivateAssetIntegrityException('not_found', 'Media is unavailable.', 404);
        }
    }

    private function authorizeReimbursement(User $actor, Reimbursement $reimbursement): void
    {
        $tenantId = $this->reimbursementTenant($reimbursement);
        $allowed = (string) $reimbursement->user_id === (string) $actor->id
            || ((int) $actor->company_id === $tenantId
                && ($actor->hasCapability('reimbursement.manage') || $actor->hasCapability('payroll.manage')));
        if (! $allowed || (int) $actor->company_id !== $tenantId) {
            throw new PrivateAssetIntegrityException('not_found', 'Media is unavailable.', 404);
        }
    }

    private function attendanceTenant(Attendance $attendance): int
    {
        $tenantId = (int) ($attendance->user?->company_id
            ?? User::withoutGlobalScopes()->whereKey($attendance->user_id)->value('company_id'));
        if ($tenantId < 1) {
            throw new PrivateAssetIntegrityException('invalid_tenant', 'Media is unavailable.', 410);
        }

        return $tenantId;
    }

    private function reimbursementTenant(Reimbursement $reimbursement): int
    {
        $tenantId = (int) ($reimbursement->company_id ?: $reimbursement->user?->company_id
            ?? User::withoutGlobalScopes()->whereKey($reimbursement->user_id)->value('company_id'));
        if ($tenantId < 1) {
            throw new PrivateAssetIntegrityException('invalid_tenant', 'Media is unavailable.', 410);
        }

        return $tenantId;
    }

    private function metadata(object $model, string $prefix): array
    {
        return [
            'sha256' => $model->getAttribute($prefix.'_sha256'),
            'size' => $model->getAttribute($prefix.'_size_bytes'),
            'mime' => $model->getAttribute($prefix.'_mime_type'),
            'width' => $model->getAttribute($prefix.'_width'),
            'height' => $model->getAttribute($prefix.'_height'),
        ];
    }

    private function attendanceField(string $field): array
    {
        return self::ATTENDANCE_FIELDS[$field]
            ?? throw new PrivateAssetIntegrityException('not_found', 'Media is unavailable.', 404);
    }

    private function reimbursementField(string $field): array
    {
        return self::REIMBURSEMENT_FIELDS[$field]
            ?? throw new PrivateAssetIntegrityException('not_found', 'Media is unavailable.', 404);
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}

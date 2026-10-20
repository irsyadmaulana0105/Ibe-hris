<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;

class FaceApprovalService
{
    public function listForAdmin(User $admin, array $filters = []): LengthAwarePaginator
    {
        $query = $this->pendingQuery($admin)
            ->with(['division', 'jobTitle'])
            ->orderByDesc('updated_at');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }

        $registrationType = (string) ($filters['registration_type'] ?? 'all');
        if ($registrationType === 'new') {
            $query->whereNull('face_descriptor');
        } elseif ($registrationType === 'update') {
            $query->whereNotNull('face_descriptor');
        }

        return $query->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function findPending(User $admin, string $id): User
    {
        return $this->pendingQuery($admin)
            ->with(['division', 'jobTitle'])
            ->findOrFail($id);
    }

    public function listForApi(User $admin): Collection
    {
        return $this->pendingQuery($admin)
            ->orderByDesc('updated_at')
            ->get();
    }

    public function approve(User $admin, string $id): User
    {
        $user = $this->findPending($admin, $id);
        // Dihitung SEBELUM forceFill: dulu dihitung sesudahnya sehingga selalu 'update'.
        $registrationType = $user->face_descriptor ? 'update' : 'new';
        $fotoLama = $user->face_photo;

        $user->forceFill([
            'face_descriptor' => $user->face_descriptor_pending,
            'face_descriptor_pending' => null,
            // Foto pendaftaran yang disetujui menjadi foto acuan wajah aktif.
            'face_photo' => $user->face_photo_pending,
            'face_photo_pending' => null,
            'face_quality_pending' => null,
        ])->save();
        if ($fotoLama && $fotoLama !== $user->face_photo) {
            \App\Support\FotoWajah::hapus($user, $fotoLama);
        }

        app(AuditLogService::class)->log(
            'face_approval.approved',
            $admin,
            'user',
            $user->id,
            [
                'target_user_id' => $user->id,
                'registration_type' => $registrationType,
            ]
        );

        return $user->fresh(['division', 'jobTitle']);
    }

    public function reject(User $admin, string $id): User
    {
        $user = $this->findPending($admin, $id);
        \App\Support\FotoWajah::hapus($user, $user->face_photo_pending);

        $user->forceFill([
            'face_descriptor_pending' => null,
            'face_photo_pending' => null,
            'face_quality_pending' => null,
        ])->save();

        app(AuditLogService::class)->log(
            'face_approval.rejected',
            $admin,
            'user',
            $user->id,
            [
                'target_user_id' => $user->id,
                'registration_type' => $user->face_descriptor ? 'update' : 'new',
            ]
        );

        return $user->fresh(['division', 'jobTitle']);
    }

    public function pendingCount(int $companyId): int
    {
        return User::query()
            ->where('company_id', $companyId)
            ->whereNotNull('face_descriptor_pending')
            ->count();
    }

    private function pendingQuery(User $admin): Builder
    {
        return User::query()
            ->where('company_id', $admin->company_id)
            ->whereNotNull('face_descriptor_pending');
    }
}

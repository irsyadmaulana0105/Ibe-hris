<?php

namespace App\Services;

use App\Models\ClientVisit;
use App\Models\Division;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ClientVisitService
{
    public function visitIn(User $user, array $data, ?UploadedFile $photoIn = null): ClientVisit
    {
        if ($photoIn) {
            throw ValidationException::withMessages([
                'photo_in' => 'Foto kunjungan dinonaktifkan sementara sampai penyimpanan privat tersedia.',
            ]);
        }

        return ClientVisit::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'date' => $data['date'],
            'visit_in' => now(),
            'notes_in' => $data['notes_in'],
            'photo_in' => null,
            'latitude_in' => null,
            'longitude_in' => null,
            'status' => 'ongoing',
        ]);
    }

    public function visitOut(User $user, int $id, array $data, ?UploadedFile $photoOut = null): ClientVisit
    {
        $visit = ClientVisit::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->whereNull('visit_out')
            ->findOrFail($id);

        if ($photoOut) {
            throw ValidationException::withMessages([
                'photo_out' => 'Foto kunjungan dinonaktifkan sementara sampai penyimpanan privat tersedia.',
            ]);
        }

        $visit->update([
            'visit_out' => now(),
            'notes_out' => $data['notes_out'],
            'photo_out' => null,
            'latitude_out' => null,
            'longitude_out' => null,
            'status' => 'completed',
        ]);

        return $visit->fresh();
    }

    public function listForUser(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return ClientVisit::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderByDesc('date')
            ->paginate($perPage);
    }

    public function listForAdmin(User $admin, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = ClientVisit::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->with(['user.division'])
            ->orderByDesc('date');

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['month'])) {
            $d = Carbon::parse($filters['month'].'-01');
            $query->whereYear('date', $d->year)->whereMonth('date', $d->month);
        }

        if (! empty($filters['division'])) {
            $query->whereHas('user', fn ($q) => $q->where('division_id', $filters['division']));
        }

        if (! empty($filters['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
            );
        }

        return $query->paginate($perPage);
    }

    public function detail(int $id): ?ClientVisit
    {
        return ClientVisit::withoutGlobalScopes()
            ->with(['user.division'])
            ->find($id);
    }

    public function divisionsForAdmin(User $admin): Collection
    {
        return Division::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->get();
    }
}

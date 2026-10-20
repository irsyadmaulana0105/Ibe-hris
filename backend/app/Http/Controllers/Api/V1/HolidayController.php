<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Hari libur nasional + cuti bersama. Public (authenticated) read endpoint
 * dipakai kalender absensi untuk warnai tanggal merah. Admin CRUD untuk
 * tambah event perusahaan (e.g. cuti bersama internal).
 */
class HolidayController extends ApiController
{
    /** GET /api/v1/holidays?year=2026  (authenticated, semua user) */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = (int) ($request->integer('year') ?: now()->year);

        $rows = Holiday::query()
            ->where(function ($q) use ($user) {
                $q->whereNull('company_id')->orWhere('company_id', $user->company_id);
            })
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get(['id', 'date', 'name', 'type', 'is_recurring', 'broadcast_sent_at']);

        return $this->success([
            'year' => $year,
            'rows' => $rows->map(fn ($h) => [
                'id'   => $h->id,
                'date' => $h->date->toDateString(),
                'name' => $h->name,
                'type' => $h->type,
                'broadcast_sent_at' => $h->broadcast_sent_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** GET /api/v1/admin/holidays */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'master_data.manage', 'Master data access required.');
        return $this->index($request);
    }

    /** POST /api/v1/admin/holidays */
    public function store(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'master_data.manage', 'Master data access required.');
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', 'in:nasional,cuti_bersama,perusahaan'],
            'is_recurring' => ['nullable', 'boolean'],
        ]);
        $data['company_id'] = $request->user()->company_id;
        $holiday = Holiday::create($data);
        return $this->created([
            'id'   => $holiday->id,
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
            'type' => $holiday->type,
        ], 'Hari libur ditambahkan.');
    }

    /** PUT /api/v1/admin/holidays/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'master_data.manage', 'Master data access required.');
        $holiday = Holiday::query()
            ->where('company_id', $request->user()->company_id)
            ->orWhereNull('company_id')
            ->findOrFail($id);
        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'name' => ['sometimes', 'string', 'max:200'],
            'type' => ['sometimes', 'in:nasional,cuti_bersama,perusahaan'],
            'is_recurring' => ['sometimes', 'boolean'],
        ]);
        $holiday->update($data);
        return $this->success([
            'id'   => $holiday->id,
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
            'type' => $holiday->type,
        ], 'Hari libur diperbarui.');
    }

    /** DELETE /api/v1/admin/holidays/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'master_data.manage', 'Master data access required.');
        Holiday::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id)
            ->delete();
        return $this->success(message: 'Hari libur dihapus.');
    }

    /**
     * POST /api/v1/admin/holidays/sync/{year}
     * Auto-sync libur nasional Indonesia dari Nager.Date (gratis, official sources).
     * Insert kalau belum ada; update name kalau sudah ada (idempotent).
     */
    public function syncFromApi(Request $request, int $year): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'master_data.manage', 'Master data access required.');

        if ($year < 2020 || $year > 2100) {
            return response()->json(['message' => 'Tahun harus 2020–2100.'], 422);
        }

        try {
            $r = Http::timeout(15)->acceptJson()->get("https://date.nager.at/api/v3/PublicHolidays/{$year}/ID");
            if (!$r->successful()) {
                return response()->json(['message' => 'Gagal fetch dari Nager.Date: HTTP ' . $r->status()], 502);
            }
            $items = $r->json();
            if (!is_array($items)) {
                return response()->json(['message' => 'Response API tidak valid.'], 502);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal fetch: ' . $e->getMessage()], 502);
        }

        $inserted = 0;
        $updated = 0;
        foreach ($items as $h) {
            $date = $h['date'] ?? null;
            $name = $h['localName'] ?? ($h['name'] ?? null);
            if (!$date || !$name) continue;

            $existing = Holiday::query()
                ->where(function ($q) use ($admin) {
                    $q->whereNull('company_id')->orWhere('company_id', $admin->company_id);
                })
                ->where('date', $date)
                ->first();

            if ($existing) {
                if ($existing->name !== $name) {
                    $existing->update(['name' => $name]);
                    $updated++;
                }
            } else {
                Holiday::create([
                    'date'         => $date,
                    'name'         => $name,
                    'type'         => 'nasional',
                    'is_recurring' => false,
                    'company_id'   => null, // null = global, visible ke semua tenant
                ]);
                $inserted++;
            }
        }

        app(\App\Services\AuditLogService::class)->log(
            'holiday.sync_from_api',
            $admin, 'holiday', null,
            ['year' => $year, 'inserted' => $inserted, 'updated' => $updated, 'source' => 'nager.date'],
            $request,
        );

        return $this->success([
            'year'     => $year,
            'fetched'  => count($items),
            'inserted' => $inserted,
            'updated'  => $updated,
            'source'   => 'date.nager.at',
        ], "Sync libur {$year}: {$inserted} ditambah, {$updated} diperbarui.");
    }

    /**
     * POST /api/v1/admin/holidays/{id}/broadcast
     * Trigger manual broadcast pengingat untuk satu tanggal libur (force, abaikan
     * flag broadcast_sent_at supaya admin bisa re-send kalau perlu).
     */
    public function broadcast(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'master_data.manage', 'Master data access required.');

        $holiday = Holiday::query()
            ->where(function ($q) use ($admin) {
                $q->whereNull('company_id')->orWhere('company_id', $admin->company_id);
            })
            ->findOrFail($id);

        $karyawan = \App\Models\User::query()->withoutGlobalScopes()
            ->where('group', 'user')
            ->where('company_id', $admin->company_id)
            ->whereNotIn('employment_status', \App\Models\User::INACTIVE_STATUSES)
            ->whereNotNull('email')
            ->get();

        $typeLabel = match ($holiday->type) {
            'cuti_bersama' => 'Cuti Bersama',
            'perusahaan'   => 'Libur Perusahaan',
            default        => 'Libur Nasional',
        };
        $dateLabel = $holiday->date->translatedFormat('l, j F Y');
        $sent = 0;
        foreach ($karyawan as $user) {
            try {
                $body = "Halo {$user->name},\n\nPengingat hari libur:\n\n  {$dateLabel}\n  {$typeLabel}: {$holiday->name}\n\nKaryawan tidak perlu masuk kerja.\n\nSalam,\nHR IBE Reality";
                \Illuminate\Support\Facades\Mail::raw($body, function ($m) use ($user, $holiday) {
                    $m->to($user->email, $user->name)
                      ->subject("[IBE Reality] Pengingat: {$holiday->name}");
                });
                $sent++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Manual holiday broadcast failed for {$user->email}: " . $e->getMessage());
            }
        }
        $holiday->update(['broadcast_sent_at' => now()]);

        app(\App\Services\AuditLogService::class)->log(
            'holiday.broadcast_manual',
            $admin, 'holiday', (string) $holiday->id,
            ['name' => $holiday->name, 'sent_count' => $sent],
            $request,
        );

        return $this->success([
            'sent_count'   => $sent,
            'recipients'   => $karyawan->count(),
            'broadcast_at' => $holiday->broadcast_sent_at?->toIso8601String(),
        ], "Pengingat terkirim ke {$sent} karyawan.");
    }
}

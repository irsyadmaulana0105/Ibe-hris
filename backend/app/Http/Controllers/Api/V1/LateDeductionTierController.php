<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LateDeductionTier;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD tier potongan terlambat.
 *
 * Routes:
 *   GET    /admin/late-tiers                - list company-default tiers
 *   POST   /admin/late-tiers/bulk           - replace seluruh set tier (company-default)
 *   GET    /admin/users/{userId}/late-tiers - list per-user override tiers
 *   POST   /admin/users/{userId}/late-tiers/bulk - replace per-user tier set
 *   DELETE /admin/users/{userId}/late-tiers - hapus semua override (kembali ke company default)
 */
class LateDeductionTierController extends ApiController
{
    public function indexCompany(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $tiers = LateDeductionTier::query()
            ->where('company_id', $request->user()->company_id)
            ->whereNull('user_id')
            ->orderBy('min_minutes')
            ->get();
        return $this->success($tiers);
    }

    public function indexUser(Request $request, string $userId): JsonResponse
    {
        $this->requireAdmin($request->user());
        $user = User::where('company_id', $request->user()->company_id)->findOrFail($userId);
        $tiers = LateDeductionTier::query()
            ->where('user_id', $user->id)
            ->orderBy('min_minutes')
            ->get();
        return $this->success($tiers);
    }

    /**
     * Bulk replace: simpan seluruh set tier dalam 1 request (delete-then-insert
     * dalam transaksi). Lebih simple untuk UI tier editor - admin edit tabel
     * langsung, hit Save satu kali.
     *
     * Payload:
     *   {
     *     "tiers": [
     *       { "min_minutes": 15, "max_minutes": 30, "deduction_amount": 10000, "label": "Ringan" },
     *       { "min_minutes": 30, "max_minutes": 60, "deduction_amount": 25000, "label": "Sedang" },
     *       { "min_minutes": 60, "max_minutes": null, "deduction_amount": 50000, "label": "Berat" }
     *     ]
     *   }
     */
    public function bulkCompany(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $payload = $this->validateTiers($request);

        DB::transaction(function () use ($request, $payload) {
            LateDeductionTier::where('company_id', $request->user()->company_id)
                ->whereNull('user_id')->delete();
            foreach ($payload['tiers'] as $i => $t) {
                LateDeductionTier::create([
                    'company_id'       => $request->user()->company_id,
                    'user_id'          => null,
                    'min_minutes'      => $t['min_minutes'],
                    'max_minutes'      => $t['max_minutes'] ?? null,
                    'deduction_amount' => $t['deduction_amount'],
                    'label'            => $t['label'] ?? null,
                    'sort_order'       => $i,
                ]);
            }
        });

        return $this->success(
            LateDeductionTier::query()->where('company_id', $request->user()->company_id)
                ->whereNull('user_id')->orderBy('min_minutes')->get(),
            'Tier company-default tersimpan'
        );
    }

    public function bulkUser(Request $request, string $userId): JsonResponse
    {
        $this->requireAdmin($request->user());
        $user = User::where('company_id', $request->user()->company_id)->findOrFail($userId);
        $payload = $this->validateTiers($request);

        DB::transaction(function () use ($user, $payload) {
            LateDeductionTier::where('user_id', $user->id)->delete();
            foreach ($payload['tiers'] as $i => $t) {
                LateDeductionTier::create([
                    'company_id'       => $user->company_id,
                    'user_id'          => $user->id,
                    'min_minutes'      => $t['min_minutes'],
                    'max_minutes'      => $t['max_minutes'] ?? null,
                    'deduction_amount' => $t['deduction_amount'],
                    'label'            => $t['label'] ?? null,
                    'sort_order'       => $i,
                ]);
            }
        });

        return $this->success(
            LateDeductionTier::query()->where('user_id', $user->id)->orderBy('min_minutes')->get(),
            'Override per-user tersimpan'
        );
    }

    public function clearUser(Request $request, string $userId): JsonResponse
    {
        $this->requireAdmin($request->user());
        $user = User::where('company_id', $request->user()->company_id)->findOrFail($userId);
        LateDeductionTier::where('user_id', $user->id)->delete();
        return $this->success(null, 'Override per-user dihapus - kembali ke company default');
    }

    private function validateTiers(Request $request): array
    {
        return $request->validate([
            'tiers'                       => 'required|array',
            'tiers.*.min_minutes'         => 'required|integer|min:1|max:720',
            'tiers.*.max_minutes'         => 'nullable|integer|min:1|max:720|gt:tiers.*.min_minutes',
            'tiers.*.deduction_amount'    => 'required|integer|min:0',
            'tiers.*.label'               => 'nullable|string|max:50',
        ]);
    }
}

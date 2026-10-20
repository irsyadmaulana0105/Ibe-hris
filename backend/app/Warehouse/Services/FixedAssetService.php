<?php

namespace App\Warehouse\Services;

use App\Models\User;
use App\Warehouse\Models\FixedAsset;
use App\Warehouse\Models\FixedAssetDepreciation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixed Asset operations: register, post depreciation, dispose.
 *
 * Depreciation:
 *   - straight_line: (cost - salvage) / useful_life_months per bulan
 *   - declining_balance: TODO (untuk later)
 *
 * Posting biasanya monthly via scheduler atau manual trigger.
 */
class FixedAssetService
{
    public function __construct(private readonly CurrencyService $currency) {}

    public function generateAssetTag(int $companyId): string
    {
        $year = date('Y');

        return DB::transaction(function () use ($companyId, $year) {
            $last = FixedAsset::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('asset_tag', 'like', "FA-{$year}-%")
                ->orderByDesc('asset_tag')
                ->lockForUpdate()
                ->value('asset_tag');
            $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

            return "FA-{$year}-".str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    public function register(User $actor, array $data): FixedAsset
    {
        return DB::transaction(function () use ($actor, $data) {
            // Convert ke base currency
            $cost = (float) $data['acquisition_cost'];
            $currency = $data['currency'] ?? 'IDR';
            $rate = 1.0;
            $costInBase = $cost;

            if ($currency !== $this->currency->base()) {
                $rate = $this->currency->getRate($actor->company_id, $currency, Carbon::parse($data['acquisition_date']));
                $costInBase = $cost * $rate;
            }

            $asset = FixedAsset::create([
                ...$data,
                'company_id' => $actor->company_id,
                'asset_tag' => $data['asset_tag'] ?? $this->generateAssetTag($actor->company_id),
                'exchange_rate' => $rate,
                'cost_in_base' => $costInBase,
                'accumulated_depreciation' => 0,
                'book_value' => $costInBase,
                'status' => $data['status'] ?? 'active',
                'depreciation_method' => $data['depreciation_method'] ?? 'straight_line',
                'created_by' => $actor->id,
            ]);

            return $asset;
        });
    }

    /**
     * Post monthly depreciation untuk semua active assets di company.
     * Idempotent: kalau period sudah posted, skip.
     */
    public function postMonthlyDepreciation(User $actor, ?Carbon $period = null): array
    {
        $period = ($period ?? Carbon::today())->startOfMonth();
        $posted = 0;
        $skipped = 0;
        $errors = [];

        $assets = FixedAsset::query()
            ->where('company_id', $actor->company_id)
            ->where('status', 'active')
            ->where('depreciation_method', '!=', 'none')
            ->whereColumn('accumulated_depreciation', '<', DB::raw('cost_in_base - salvage_value'))
            ->get();

        foreach ($assets as $asset) {
            try {
                $existing = FixedAssetDepreciation::query()
                    ->where('fixed_asset_id', $asset->id)
                    ->where('period', $period->toDateString())
                    ->exists();
                if ($existing) {
                    $skipped++;

                    continue;
                }

                // Hitung depreciation amount
                $amount = 0;
                if ($asset->depreciation_method === 'straight_line') {
                    $depreciableBase = $asset->cost_in_base - $asset->salvage_value;
                    $monthlyAmount = $depreciableBase / max(1, $asset->useful_life_months);
                    // Don't over-depreciate beyond salvage
                    $remaining = $asset->cost_in_base - $asset->accumulated_depreciation - $asset->salvage_value;
                    $amount = min($monthlyAmount, $remaining);
                }
                if ($amount <= 0) {
                    continue;
                }

                DB::transaction(function () use ($asset, $amount, $period, $actor) {
                    $newAccum = $asset->accumulated_depreciation + $amount;
                    $newBook = $asset->cost_in_base - $newAccum;
                    $asset->update([
                        'accumulated_depreciation' => $newAccum,
                        'book_value' => $newBook,
                        'last_depreciation_date' => $period->copy()->endOfMonth()->toDateString(),
                    ]);
                    FixedAssetDepreciation::create([
                        'fixed_asset_id' => $asset->id,
                        'period' => $period->toDateString(),
                        'amount' => $amount,
                        'book_value_after' => $newBook,
                        'posted_by' => $actor->id,
                    ]);
                });
                $posted++;
            } catch (\Throwable $e) {
                $errors[] = ['asset' => $asset->asset_tag, 'error' => $e->getMessage()];
            }
        }

        return [
            'period' => $period->toDateString(),
            'posted' => $posted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    public function dispose(User $actor, FixedAsset $asset, array $data): FixedAsset
    {
        if ($asset->status === 'disposed') {
            throw new \RuntimeException('Asset sudah disposed.');
        }
        $asset->update([
            'status' => 'disposed',
            'disposal_date' => $data['disposal_date'] ?? now()->toDateString(),
            'disposal_value' => $data['disposal_value'] ?? null,
            'disposal_method' => $data['disposal_method'] ?? 'sold',
            'notes' => trim(($asset->notes ?? '')."\nDisposed: ".($data['notes'] ?? '')),
        ]);

        return $asset;
    }
}

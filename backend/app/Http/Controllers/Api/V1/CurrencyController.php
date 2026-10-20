<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\AuditLogService;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends ApiController
{
    public function __construct(
        private readonly CurrencyService $currency,
        private readonly AuditLogService $audit,
    ) {}

    /** GET /admin/currencies - list active currencies (open to all authenticated users for picker dropdown) */
    public function index(Request $request): JsonResponse
    {
        $rows = Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('code')->get();
        return $this->success($rows);
    }

    /** GET /admin/exchange-rates */
    public function listRates(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'finance.module.access');

        $rows = ExchangeRate::query()
            ->where('company_id', $user->company_id)
            ->orderByDesc('valid_from')
            ->orderBy('from_currency')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }

    /** POST /admin/exchange-rates */
    public function storeRate(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'finance.module.access');

        $data = $request->validate([
            'from_currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'rate'          => ['required', 'numeric', 'gt:0'],
            'valid_from'    => ['required', 'date'],
            'source'        => ['nullable', 'string', 'max:50'],
        ]);
        $data['to_currency'] = $this->currency->base();
        $data['company_id']  = $user->company_id;
        $data['created_by']  = $user->id;

        $rate = ExchangeRate::updateOrCreate(
            [
                'company_id'    => $user->company_id,
                'from_currency' => $data['from_currency'],
                'to_currency'   => $data['to_currency'],
                'valid_from'    => $data['valid_from'],
            ],
            ['rate' => $data['rate'], 'source' => $data['source'] ?? 'manual', 'created_by' => $user->id],
        );

        $this->audit->log('currency.rate.set', $user, 'exchange_rate', (string) $rate->id, [
            'from' => $data['from_currency'], 'rate' => $data['rate'], 'date' => $data['valid_from'],
        ]);

        return $this->success($rate, "Rate {$data['from_currency']} → {$data['to_currency']} = {$data['rate']} per {$data['valid_from']} tersimpan.");
    }

    /** GET /admin/exchange-rates/lookup?from=USD&date=2026-05-22 */
    public function lookup(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'from' => ['required', 'string', 'size:3'],
            'date' => ['nullable', 'date'],
        ]);

        try {
            $asOf = isset($data['date']) ? \Carbon\Carbon::parse($data['date']) : null;
            $rate = $this->currency->getRate($user->company_id, $data['from'], $asOf);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->success([
            'from' => $data['from'],
            'to'   => $this->currency->base(),
            'rate' => $rate,
        ]);
    }
}

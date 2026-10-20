<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TreasuryBankAccount;
use App\Models\TreasuryCashTransaction;
use App\Models\TreasuryPayment;
use App\Services\TreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TreasuryController extends ApiController
{
    public function __construct(private readonly TreasuryService $treasury) {}

    public function accounts(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        return $this->success(TreasuryBankAccount::withoutGlobalScopes()->where('company_id', $request->user()->company_id)->orderBy('name')->get());
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'treasury.payment.execute');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'bank_name' => ['required', 'string', 'max:120'], 'account_number' => ['required', 'string', 'max:80'], 'currency' => ['nullable', 'string', 'size:3', 'exists:currencies,code'], 'opening_balance' => ['nullable', 'numeric', 'min:0']]);
        return $this->created($this->treasury->createBankAccount($request->user(), $data));
    }

    public function payments(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $rows = TreasuryPayment::withoutGlobalScopes()->where('company_id', $request->user()->company_id)->latest('id')->paginate(30);
        return $this->paginated($rows, $rows->items());
    }

    public function storePayment(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'treasury.payment.execute');
        $data = $request->validate(['bank_account_id' => ['required', 'integer'], 'direction' => ['required', 'in:inbound,outbound'], 'counterparty' => ['required', 'string', 'max:160'], 'counterparty_account' => ['nullable', 'string', 'max:100'], 'amount' => ['required', 'numeric', 'gt:0'], 'scheduled_for' => ['required', 'date'], 'description' => ['nullable', 'string', 'max:500']]);
        return $this->created($this->treasury->createPayment($request->user(), $data));
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'treasury.payment.execute');
        return $this->success($this->treasury->submit($request->user(), $id));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $payment = TreasuryPayment::withoutGlobalScopes()->where('company_id', $request->user()->company_id)->findOrFail($id);
        $this->requireCapability($request->user(), 'treasury.payment.approve', context: ['company_id' => $request->user()->company_id, 'amount' => $payment->amount, 'maker_id' => $payment->maker_id, 'irreversible' => true]);
        return $this->success($this->treasury->approve($request->user(), $id));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'treasury.payment.approve');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return $this->success($this->treasury->reject($request->user(), $id, $data['reason']));
    }

    public function execute(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'treasury.payment.execute');
        $data = $request->validate(['bank_reference' => ['required', 'string', 'max:120']]);
        return $this->success($this->treasury->execute($request->user(), $id, $data['bank_reference']));
    }

    public function cashLedger(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $rows = TreasuryCashTransaction::withoutGlobalScopes()->where('company_id', $request->user()->company_id)->latest('transaction_date')->latest('id')->paginate(50);
        return $this->paginated($rows, $rows->items());
    }
}

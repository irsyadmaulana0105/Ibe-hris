<?php

namespace App\Keuangan\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Keuangan\Models\Bill;
use App\Keuangan\Services\BillService;
use App\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillController extends ApiController
{
    public function __construct(private readonly BillService $service) {}

    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'finance.module.access');
        $status = $request->string('status')->toString();
        $q = $request->string('q')->toString();
        $month = $request->string('month')->toString();

        $rows = Bill::query()
            ->where('company_id', $admin->company_id)
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($month, fn ($w) => $w->whereYear('date', substr($month, 0, 4))->whereMonth('date', substr($month, 5, 2)))
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('number', 'like', "%{$q}%")->orWhere('supplier_name', 'like', "%{$q}%");
            }))
            ->orderByDesc('date')->orderByDesc('created_at')
            ->paginate(30);

        return $this->paginated($rows, $rows->items());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'finance.module.access');
        $bill = Bill::query()
            ->where('company_id', $admin->company_id)
            ->with(['lines.expenseAccount:id,code,name'])
            ->findOrFail($id);

        return $this->success($bill);
    }

    /** GET /api/v1/finance/bills/{id}/pdf - render bill PDF dengan logo (internal record) */
    public function pdf(Request $request, string $id)
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'finance.module.access');
        $bill = Bill::query()
            ->where('company_id', $admin->company_id)
            ->with(['lines.expenseAccount:id,code,name'])
            ->findOrFail($id);

        $pdf = Pdf::loadView('pdf.bill', [
            'bill' => $bill,
            'company' => Company::find($admin->company_id),
        ])->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('/', '-', $bill->number).'.pdf"',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.bill.manage', 'finance.module.access');

        $data = $request->validate([
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['required', 'string', 'max:200'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.expense_account_id' => ['required', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
        ]);

        $bill = $this->service->create($admin, $data, $data['lines']);

        return $this->created($bill, "Bill {$bill->number} dibuat (draft).");
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.bill.manage', 'finance.module.access');
        $bill = Bill::query()->where('company_id', $admin->company_id)->findOrFail($id);
        $this->requireGranularCapability($admin, 'finance.bill.manage', 'finance.module.access', context: [
            'company_id' => $bill->company_id, 'resource_type' => 'bill', 'resource_id' => $bill->id,
            'amount' => $bill->total, 'maker_id' => $bill->created_by, 'irreversible' => true,
            'action' => 'receive', 'status' => $bill->status,
        ]);
        $received = $this->service->receive($admin, $bill);

        return $this->success($received, "Bill {$received->number} diterima, hutang tercatat.");
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.bill.manage', 'finance.module.access');
        $data = $request->validate([
            'payment_account_code' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);
        $bill = Bill::query()->where('company_id', $admin->company_id)->findOrFail($id);
        $this->requireGranularCapability($admin, 'finance.bill.manage', 'finance.module.access', context: [
            'company_id' => $bill->company_id, 'resource_type' => 'bill', 'resource_id' => $bill->id,
            'amount' => $data['amount'], 'maker_id' => $bill->created_by, 'irreversible' => true,
            'action' => 'pay', 'status' => $bill->status,
        ]);
        $paid = $this->service->pay($admin, $bill, $data['payment_account_code'], (float) $data['amount']);

        return $this->success($paid, 'Pembayaran tercatat.');
    }
}

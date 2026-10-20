<?php

namespace App\Keuangan\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Keuangan\Models\Invoice;
use App\Keuangan\Services\InvoiceService;
use App\Models\Company;
use App\Services\PrivateAssetStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends ApiController
{
    public function __construct(
        private readonly InvoiceService $service,
        private readonly PrivateAssetStorage $privateAssets,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'finance.module.access');
        $status = $request->string('status')->toString();
        $q = $request->string('q')->toString();
        $month = $request->string('month')->toString();

        $rows = Invoice::query()
            ->where('company_id', $admin->company_id)
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($month, fn ($w) => $w->whereYear('date', substr($month, 0, 4))->whereMonth('date', substr($month, 5, 2)))
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('number', 'like', "%{$q}%")->orWhere('customer_name', 'like', "%{$q}%");
            }))
            ->orderByDesc('date')->orderByDesc('created_at')
            ->paginate(30);

        return $this->paginated($rows, $rows->items());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'finance.module.access');
        $invoice = Invoice::query()
            ->where('company_id', $admin->company_id)
            ->with('lines')
            ->findOrFail($id);

        return $this->success($invoice);
    }

    /** GET /api/v1/finance/invoices/{id}/pdf - render faktur PDF dengan logo + tanda tangan finance */
    public function pdf(Request $request, string $id)
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'finance.module.access');
        $invoice = Invoice::query()
            ->where('company_id', $admin->company_id)
            ->with('lines')
            ->findOrFail($id);

        $company = Company::find($admin->company_id);
        $signatureDataUri = $company?->finance_signature_path
            ? $this->privateAssets->dataUri(
                (int) $company->id,
                'company-signatures',
                (string) $company->id,
                $company->finance_signature_path,
                $company->finance_signature_sha256,
            )
            : null;

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => $company,
            'sigData' => $signatureDataUri,
        ])->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('/', '-', $invoice->number).'.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.invoice.manage', 'finance.module.access');

        $data = $request->validate([
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['required', 'string', 'max:200'],
            'customer_npwp' => ['nullable', 'string', 'max:20'],
            'customer_address' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:100'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.item_code' => ['nullable', 'string', 'max:50'],
        ]);

        $invoice = $this->service->create($admin, $data, $data['lines']);

        return $this->created($invoice, "Invoice {$invoice->number} dibuat (draft).");
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.invoice.manage', 'finance.module.access');
        $invoice = Invoice::query()->where('company_id', $admin->company_id)->findOrFail($id);
        $this->requireGranularCapability($admin, 'finance.invoice.manage', 'finance.module.access', context: [
            'company_id' => $invoice->company_id, 'resource_type' => 'invoice', 'resource_id' => $invoice->id,
            'amount' => $invoice->total, 'maker_id' => $invoice->created_by, 'irreversible' => true,
            'action' => 'send', 'status' => $invoice->status,
        ]);
        $sent = $this->service->send($admin, $invoice);

        return $this->success($sent, "Invoice {$sent->number} dikirim, journal entry auto-posted.");
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.invoice.manage', 'finance.module.access');
        $data = $request->validate([
            'payment_account_code' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);
        $invoice = Invoice::query()->where('company_id', $admin->company_id)->findOrFail($id);
        $this->requireGranularCapability($admin, 'finance.invoice.manage', 'finance.module.access', context: [
            'company_id' => $invoice->company_id, 'resource_type' => 'invoice', 'resource_id' => $invoice->id,
            'amount' => $data['amount'], 'maker_id' => $invoice->created_by, 'irreversible' => true,
            'action' => 'pay', 'status' => $invoice->status,
        ]);
        $paid = $this->service->pay($admin, $invoice, $data['payment_account_code'], (float) $data['amount']);

        return $this->success($paid, 'Pembayaran Rp '.number_format((float) $data['amount'], 0, ',', '.').' tercatat.');
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.invoice.manage', 'finance.module.access');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $invoice = Invoice::query()->where('company_id', $admin->company_id)->findOrFail($id);
        $this->requireGranularCapability($admin, 'finance.invoice.manage', 'finance.module.access', context: [
            'company_id' => $invoice->company_id, 'resource_type' => 'invoice', 'resource_id' => $invoice->id,
            'amount' => $invoice->total, 'maker_id' => $invoice->sent_by ?? $invoice->created_by,
            'irreversible' => true, 'action' => 'cancel', 'status' => $invoice->status,
        ]);
        $cancelled = $this->service->cancel($admin, $invoice, $data['reason']);

        return $this->success($cancelled, 'Invoice cancelled.');
    }
}

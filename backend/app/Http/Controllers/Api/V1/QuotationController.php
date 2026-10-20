<?php

namespace App\Http\Controllers\Api\V1;

use App\Keuangan\Services\InvoiceService;
use App\Mail\QuotationMail;
use App\Models\Quotation;
use App\Models\QuotationTemplate;
use App\Services\QuotationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class QuotationController extends ApiController
{
    public function __construct(private readonly QuotationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $rows = Quotation::query()
            ->where('company_id', $request->user()->company_id)
            ->when($request->string('status')->toString(), fn ($w, $s) => $w->where('status', $s))
            ->when($request->string('q')->toString(), fn ($w, $q) => $w->where(function ($x) use ($q) {
                $x->where('quote_number', 'like', "%{$q}%")
                  ->orWhere('title', 'like', "%{$q}%")
                  ->orWhere('customer_name', 'like', "%{$q}%");
            }))
            ->with('preparedBy:id,name')
            ->orderByDesc('quote_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (Quotation $q) => [
                'id'             => $q->id,
                'quote_number'   => $q->quote_number,
                'title'          => $q->title,
                'project_name'   => $q->project_name,
                'customer_name'  => $q->customer_name,
                'quote_date'     => $q->quote_date?->toDateString(),
                'valid_until'    => $q->valid_until?->toDateString(),
                'subtotal'       => (float) $q->subtotal,
                'tax_amount'     => (float) $q->tax_amount,
                'total'          => (float) $q->total,
                'status'         => $q->status,
                'prepared_by'    => $q->preparedBy ? ['id' => $q->preparedBy->id, 'name' => $q->preparedBy->name] : null,
                'is_expired'     => $q->is_expired,
                'sent_at'        => $q->sent_at?->toIso8601String(),
                'accepted_at'    => $q->accepted_at?->toIso8601String(),
            ]);

        return $this->success(['data' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $q = Quotation::with(['items', 'preparedBy:id,name'])
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);
        return $this->success($this->serialize($q));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $data = $this->validatePayload($request);
        $q = $this->service->create($request->user(), $data);
        return $this->created($this->serialize($q->load('items')), "RAB {$q->quote_number} dibuat.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $q = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
        if (in_array($q->status, ['accepted', 'rejected'], true)) {
            return response()->json(['message' => "Quote sudah {$q->status}, tidak bisa diubah."], 422);
        }
        $data = $this->validatePayload($request);
        $q = $this->service->update($q, $data);
        return $this->success($this->serialize($q->load('items')), 'RAB diperbarui.');
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $q = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
        try {
            $this->service->send($q);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return $this->success(['status' => $q->status], 'RAB ditandai sebagai dikirim.');
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $q = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
        try {
            $this->service->accept($q);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return $this->success(['status' => $q->status], 'RAB ditandai sebagai accepted.');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $q = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        try {
            $this->service->reject($q, $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return $this->success(['status' => $q->status], 'RAB ditandai sebagai rejected.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $q = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
        $q->delete();
        return $this->success(['quote_number' => $q->quote_number], 'Diarsipkan.');
    }

    /** POST /api/v1/admin/quotations/{id}/email - kirim email dengan PDF attached */
    public function emailQuote(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $q = Quotation::with('items', 'preparedBy:id,name')
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);

        $data = $request->validate([
            'to'      => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:250'],
            'body'    => ['nullable', 'string'],
            'format'  => ['nullable', 'in:rab,letter,both'],
            'include_public_link' => ['nullable', 'boolean'],
        ]);

        // Ensure token + auto-move to sent if still draft
        $this->service->ensurePublicToken($q);
        if ($q->status === 'draft') {
            $this->service->send($q);
        }
        $q->refresh()->load('items', 'preparedBy:id,name');

        $publicLink = ($data['include_public_link'] ?? true)
            ? url('/quote/' . $q->public_token)
            : null;

        $subject = $data['subject'] ?? "Penawaran Harga {$q->quote_number} - {$q->title}";
        $body = $data['body'] ?? "Dengan hormat,\n\nKami sampaikan Surat Penawaran terlampir. Mohon kiranya dapat ditinjau dan kami menunggu kabar baik dari Bapak/Ibu.\n\nTerima kasih.";

        try {
            Mail::to($data['to'])->send(new QuotationMail(
                $q,
                $body,
                $subject,
                $data['format'] ?? 'both',
                $publicLink,
            ));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal kirim email: ' . $e->getMessage()], 500);
        }

        $q->update([
            'email_sent_to' => $data['to'],
            'email_sent_at' => now(),
        ]);

        return $this->success([
            'sent_to'     => $data['to'],
            'public_link' => $publicLink,
        ], "Email terkirim ke {$data['to']}.");
    }

    /** POST /api/v1/admin/quotations/{id}/convert-to-invoice */
    public function convertToInvoice(Request $request, int $id, InvoiceService $invoiceService): JsonResponse
    {
        $this->requireAdmin($request->user());
        $q = Quotation::with('items')
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);

        if ($q->status !== 'accepted') {
            return response()->json(['message' => "Quote harus berstatus 'accepted' untuk convert (sekarang: {$q->status})"], 422);
        }
        if ($q->fin_invoice_id) {
            return response()->json(['message' => "Quote sudah pernah di-convert ke invoice #{$q->fin_invoice_id}"], 422);
        }

        // Flatten quote items into invoice lines (preserve section as prefix in description)
        $lines = $q->items->map(fn ($it) => [
            'description' => trim(($it->section_label ? "[{$it->section_label}] " : '') . $it->description),
            'quantity'    => (float) $it->qty,
            'unit'        => $it->unit,
            'unit_price'  => (float) $it->unit_price,
        ])->all();

        $invoice = $invoiceService->create($request->user(), [
            'date'             => now()->toDateString(),
            'due_date'         => now()->addDays(30)->toDateString(),
            'customer_id'      => $q->customer_id,
            'customer_name'    => $q->customer_name,
            'customer_address' => $q->customer_address,
            'description'      => $q->title,
            'reference'        => $q->quote_number,
            'tax_rate'         => (float) $q->tax_rate,
            'discount'         => (float) $q->discount,
        ], $lines);

        $q->update(['fin_invoice_id' => $invoice->id]);

        return $this->success([
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->number,
            'total'          => (float) $invoice->total,
        ], "Invoice {$invoice->number} dibuat dari quote {$q->quote_number}.");
    }

    // ========== Template Library ==========

    public function templatesIndex(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $rows = QuotationTemplate::query()
            ->where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->get()
            ->map(fn (QuotationTemplate $t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'category'     => $t->category,
                'description'  => $t->description,
                'item_count'   => is_array($t->items_template) ? count($t->items_template) : 0,
                'usage_count'  => $t->usage_count,
                'default_terms'=> $t->default_terms,
                'items_template'=> $t->items_template,
                'created_at'   => $t->created_at?->toIso8601String(),
            ]);
        return $this->success(['data' => $rows]);
    }

    public function templatesStore(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:200'],
            'category'              => ['nullable', 'string', 'max:64'],
            'description'           => ['nullable', 'string'],
            'items_template'        => ['required', 'array', 'min:1'],
            'items_template.*.section_label' => ['nullable', 'string'],
            'items_template.*.description'   => ['required', 'string'],
            'items_template.*.qty'           => ['nullable', 'numeric', 'min:0'],
            'items_template.*.unit'          => ['nullable', 'string'],
            'items_template.*.unit_price'    => ['nullable', 'numeric', 'min:0'],
            'default_terms'         => ['nullable', 'array'],
        ]);
        $t = QuotationTemplate::create([
            'company_id'     => $request->user()->company_id,
            'name'           => $data['name'],
            'category'       => $data['category'] ?? 'other',
            'description'    => $data['description'] ?? null,
            'items_template' => $data['items_template'],
            'default_terms'  => $data['default_terms'] ?? null,
            'is_active'      => true,
            'usage_count'    => 0,
            'created_by'     => $request->user()->id,
        ]);
        return $this->created(['id' => $t->id, 'name' => $t->name], "Template '{$t->name}' tersimpan.");
    }

    public function templatesUse(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $t = QuotationTemplate::where('company_id', $request->user()->company_id)->findOrFail($id);
        $t->increment('usage_count');
        return $this->success([
            'items_template' => $t->items_template,
            'default_terms'  => $t->default_terms,
        ]);
    }

    public function templatesDestroy(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $t = QuotationTemplate::where('company_id', $request->user()->company_id)->findOrFail($id);
        $t->delete();
        return $this->success(['name' => $t->name], 'Template dihapus.');
    }

    // ========== Public (no auth) ==========

    /** GET /api/v1/public/quote/{token} */
    public function publicShow(Request $request, string $token): JsonResponse
    {
        $q = Quotation::with(['items', 'preparedBy:id,name'])
            ->where('public_token', $token)
            ->firstOrFail();
        return $this->success($this->serializePublic($q));
    }

    /** POST /api/v1/public/quote/{token}/accept */
    public function publicAccept(Request $request, string $token): JsonResponse
    {
        $q = Quotation::where('public_token', $token)->firstOrFail();
        if (!in_array($q->status, ['draft', 'sent'], true)) {
            return response()->json(['message' => "Penawaran ini sudah {$q->status}."], 422);
        }
        $data = $request->validate([
            'note'      => ['nullable', 'string', 'max:1000'],
            'pic_name'  => ['nullable', 'string', 'max:150'],
        ]);
        $q->update([
            'status'                  => 'accepted',
            'accepted_at'             => now(),
            'customer_responded_at'   => now(),
            'customer_response_note'  => $data['note'] ?? null,
            'customer_pic_name'       => $data['pic_name'] ?? $q->customer_pic_name,
        ]);
        return $this->success([
            'quote_number' => $q->quote_number,
            'status'       => $q->status,
        ], 'Terima kasih! Penawaran berhasil disetujui.');
    }

    /** POST /api/v1/public/quote/{token}/reject */
    public function publicReject(Request $request, string $token): JsonResponse
    {
        $q = Quotation::where('public_token', $token)->firstOrFail();
        if (!in_array($q->status, ['draft', 'sent'], true)) {
            return response()->json(['message' => "Penawaran ini sudah {$q->status}."], 422);
        }
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $q->update([
            'status'                  => 'rejected',
            'rejected_at'             => now(),
            'rejection_reason'        => $data['reason'] ?? null,
            'customer_responded_at'   => now(),
            'customer_response_note'  => $data['reason'] ?? null,
        ]);
        return $this->success([
            'quote_number' => $q->quote_number,
            'status'       => $q->status,
        ], 'Penawaran ditolak. Terima kasih atas konfirmasinya.');
    }

    protected function serializePublic(Quotation $q): array
    {
        return [
            'quote_number'      => $q->quote_number,
            'title'             => $q->title,
            'project_name'      => $q->project_name,
            'customer_name'     => $q->customer_name,
            'customer_pic_name' => $q->customer_pic_name,
            'customer_address'  => $q->customer_address,
            'quote_date'        => $q->quote_date?->toDateString(),
            'valid_until'       => $q->valid_until?->toDateString(),
            'tax_rate'          => (float) $q->tax_rate,
            'discount'          => (float) $q->discount,
            'discount_pct'      => (float) $q->discount_pct,
            'subtotal'          => (float) $q->subtotal,
            'after_discount'    => (float) $q->after_discount,
            'tax_amount'        => (float) $q->tax_amount,
            'total'             => (float) $q->total,
            'payment_terms'     => $q->payment_terms,
            'delivery_terms'    => $q->delivery_terms,
            'warranty_terms'    => $q->warranty_terms,
            'preamble'          => $q->preamble,
            'notes'             => $q->notes,
            'status'            => $q->status,
            'is_expired'        => $q->is_expired,
            'prepared_by_name'  => $q->preparedBy?->name,
            'items'             => $q->items->map(fn ($it) => [
                'section_label' => $it->section_label,
                'description'   => $it->description,
                'qty'           => (float) $it->qty,
                'unit'          => $it->unit,
                'unit_price'    => (float) $it->unit_price,
                'subtotal'      => (float) $it->subtotal,
            ]),
        ];
    }

    /** GET /api/v1/admin/quotations/{id}/pdf?format=rab|letter */
    public function pdf(Request $request, int $id): Response
    {
        $this->requireAdmin($request->user());
        $q = Quotation::with(['items', 'preparedBy:id,name'])
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);

        $format = $request->string('format')->toString() ?: 'rab';
        $template = $format === 'letter' ? 'pdf.surat-penawaran' : 'pdf.rab';

        $sections = $this->service->groupedSections($q);

        $pdf = Pdf::loadView($template, [
            'q'        => $q,
            'sections' => $sections,
            'company'  => [
                'name'    => 'PT INTEGRASI BISNIS EKSEKUTIF',
                'brand'   => 'IBE Reality',
                'address' => 'Surabaya, Jawa Timur',
                'email'   => 'info@ibereality.com',
                'website' => 'ibereality.com',
            ],
        ])->setPaper('A4', 'portrait');

        $filename = strtoupper(str_replace('/', '-', $q->quote_number)) . ($format === 'letter' ? '-PENAWARAN' : '-RAB') . '.pdf';
        return $pdf->stream($filename);
    }

    protected function validatePayload(Request $request): array
    {
        return $request->validate([
            'title'              => ['required', 'string', 'max:250'],
            'project_name'       => ['nullable', 'string', 'max:250'],
            'customer_id'        => ['nullable', 'integer'],
            'customer_name'      => ['required', 'string', 'max:200'],
            'customer_address'   => ['nullable', 'string'],
            'customer_pic_name'  => ['nullable', 'string', 'max:150'],
            'customer_pic_title' => ['nullable', 'string', 'max:100'],
            'quote_date'         => ['required', 'date'],
            'valid_until'        => ['nullable', 'date'],
            'crm_deal_id'        => ['nullable', 'integer'],
            'tax_rate'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount'           => ['nullable', 'numeric', 'min:0'],
            'discount_pct'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_terms'      => ['nullable', 'string'],
            'delivery_terms'     => ['nullable', 'string'],
            'warranty_terms'     => ['nullable', 'string'],
            'preamble'           => ['nullable', 'string'],
            'notes'              => ['nullable', 'string'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.section_label' => ['nullable', 'string', 'max:100'],
            'items.*.sort_order'    => ['nullable', 'integer'],
            'items.*.product_id'    => ['nullable', 'integer'],
            'items.*.description'   => ['required', 'string'],
            'items.*.qty'           => ['required', 'numeric', 'min:0'],
            'items.*.unit'          => ['nullable', 'string', 'max:24'],
            'items.*.unit_price'    => ['required', 'numeric', 'min:0'],
            'items.*.notes'         => ['nullable', 'string'],
        ]);
    }

    protected function serialize(Quotation $q): array
    {
        return [
            'id'                => $q->id,
            'quote_number'      => $q->quote_number,
            'title'             => $q->title,
            'project_name'      => $q->project_name,
            'customer_id'       => $q->customer_id,
            'customer_name'     => $q->customer_name,
            'customer_address'  => $q->customer_address,
            'customer_pic_name' => $q->customer_pic_name,
            'customer_pic_title'=> $q->customer_pic_title,
            'quote_date'        => $q->quote_date?->toDateString(),
            'valid_until'       => $q->valid_until?->toDateString(),
            'crm_deal_id'       => $q->crm_deal_id,
            'tax_rate'          => (float) $q->tax_rate,
            'discount'          => (float) $q->discount,
            'discount_pct'      => (float) $q->discount_pct,
            'subtotal'          => (float) $q->subtotal,
            'after_discount'    => (float) $q->after_discount,
            'tax_amount'        => (float) $q->tax_amount,
            'total'             => (float) $q->total,
            'payment_terms'     => $q->payment_terms,
            'delivery_terms'    => $q->delivery_terms,
            'warranty_terms'    => $q->warranty_terms,
            'preamble'          => $q->preamble,
            'notes'             => $q->notes,
            'status'            => $q->status,
            'is_expired'        => $q->is_expired,
            'prepared_by'       => $q->preparedBy ? ['id' => $q->preparedBy->id, 'name' => $q->preparedBy->name] : null,
            'sent_at'           => $q->sent_at?->toIso8601String(),
            'accepted_at'       => $q->accepted_at?->toIso8601String(),
            'rejected_at'       => $q->rejected_at?->toIso8601String(),
            'rejection_reason'  => $q->rejection_reason,
            'fin_invoice_id'    => $q->fin_invoice_id,
            'items'             => $q->items->map(fn ($it) => [
                'id'            => $it->id,
                'section_label' => $it->section_label,
                'sort_order'    => $it->sort_order,
                'product_id'    => $it->product_id,
                'description'   => $it->description,
                'qty'           => (float) $it->qty,
                'unit'          => $it->unit,
                'unit_price'    => (float) $it->unit_price,
                'subtotal'      => (float) $it->subtotal,
                'notes'         => $it->notes,
            ]),
        ];
    }
}

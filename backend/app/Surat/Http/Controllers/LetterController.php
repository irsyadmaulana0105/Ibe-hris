<?php

namespace App\Surat\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Surat\Http\Resources\LetterResource;
use App\Surat\Models\Letter;
use App\Surat\Models\LetterCategory;
use App\Surat\Models\LetterTemplate;
use App\Surat\Services\HtmlSanitizerService;
use App\Surat\Services\LetterNumberService;
use App\Surat\Services\LetterPdfService;
use App\Surat\Services\LetterWorkflowService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LetterController extends ApiController
{
    public function __construct(
        private LetterWorkflowService $workflow,
        private LetterPdfService $pdfSvc,
        private LetterNumberService $numberSvc,
        private HtmlSanitizerService $sanitizer,
    ) {}

    /**
     * Registry - flat list semua nomor surat (masuk + keluar) untuk audit.
     * Lebih ringkas dari index - fokus traceability tujuan nomor.
     */
    public function registry(Request $request): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.view', 'registry');

        $q = Letter::query()
            ->select(['public_id', 'letter_number', 'direction', 'category_id', 'subject', 'purpose',
                'recipient_name', 'recipient_company', 'sender_name', 'sender_company',
                'original_number', 'received_date', 'letter_date', 'status', 'author_id'])
            ->with(['category:id,code,name', 'author:id,name'])
            ->where('company_id', $request->user()->company_id)
            ->whereNotNull('letter_number'); // hanya yang sudah punya nomor

        if ($dir = $request->query('direction')) {
            $q->where('direction', $dir);
        }
        if ($year = $request->query('year')) {
            $q->whereYear('letter_date', $year);
        }
        if ($cid = $request->query('category_id')) {
            $q->where('category_id', $cid);
        }
        if ($search = $request->query('q')) {
            $q->where(function ($qq) use ($search) {
                $qq->where('letter_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('purpose', 'like', "%{$search}%")
                    ->orWhere('original_number', 'like', "%{$search}%")
                    ->orWhere('recipient_company', 'like', "%{$search}%")
                    ->orWhere('sender_company', 'like', "%{$search}%");
            });
        }

        $p = $q->orderByDesc('letter_date')->orderByDesc('id')->paginate(50);

        return $this->paginated($p, $p->getCollection());
    }

    public function index(Request $request): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.view', 'list');

        $q = Letter::query()
            ->with(['category', 'author:id,name', 'signer'])
            ->where('company_id', $request->user()->company_id);

        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($cid = $request->query('category_id')) {
            $q->where('category_id', $cid);
        }
        if ($dir = $request->query('direction')) {
            $q->where('direction', $dir);
        }
        if ($search = $request->query('q')) {
            $q->where(function ($qq) use ($search) {
                $qq->where('letter_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('recipient_company', 'like', "%{$search}%");
            });
        }
        if ($from = $request->query('date_from')) {
            $q->whereDate('letter_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $q->whereDate('letter_date', '<=', $to);
        }

        $p = $q->orderByDesc('letter_date')->orderByDesc('id')->paginate(20);

        return $this->paginated($p, LetterResource::collection($p->getCollection()));
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.view', 'view', $publicId);

        $letter = $this->findOrFail($request, $publicId, ['category', 'template', 'author', 'reviewer', 'approver', 'signer', 'cosigner', 'bank', 'parentLetter:id,public_id,letter_number,subject', 'replies:id,public_id,letter_number,subject,parent_letter_id', 'attachments.uploadedBy:id,name', 'dispositions.fromUser:id,name', 'dispositions.toUser:id,name', 'revisions.changedBy:id,name']);
        $request->user()->can('view', $letter) ?: abort(403, 'Tidak boleh akses surat ini.');

        return $this->success(new LetterResource($letter));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.create', 'create');

        $data = $request->validate([
            'direction' => ['nullable', 'in:in,out'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('letter_categories', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'template_id' => [
                'nullable',
                'integer',
                Rule::exists('letter_templates', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'subject' => ['required', 'string', 'max:300'],
            'purpose' => ['nullable', 'string', 'max:200'],
            'target_code' => ['nullable', 'in:A,B'],
            'body_html' => ['nullable', 'string'],
            'body_data' => ['nullable', 'array'],
            'recipient_name' => ['nullable', 'string', 'max:200'],
            'recipient_title' => ['nullable', 'string', 'max:200'],
            'recipient_company' => ['nullable', 'string', 'max:200'],
            'recipient_address' => ['nullable', 'string'],
            'recipient_email' => ['nullable', 'email'],
            'cc_list' => ['nullable', 'array'],
            'cc_list.*' => ['string', 'max:200'],
            'sender_name' => ['nullable', 'string', 'max:200'],
            'sender_company' => ['nullable', 'string', 'max:200'],
            'original_number' => ['nullable', 'string', 'max:200'],
            'received_date' => ['nullable', 'date'],
            'letter_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:letter_date'],
            'signer_id' => [
                'nullable',
                'integer',
                Rule::exists('letter_signatures', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'cosigner_id' => [
                'nullable',
                'integer',
                Rule::exists('letter_signatures', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'bank_id' => [
                'nullable',
                'integer',
                Rule::exists('company_banks', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'confidential' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
        ]);

        // Default body dari template kalau kosong
        if (empty($data['body_html']) && ! empty($data['template_id'])) {
            /** @var LetterTemplate $tpl */
            $tpl = LetterTemplate::where('company_id', $request->user()->company_id)
                ->findOrFail($data['template_id']);
            $data['body_html'] = $tpl->body_html;
        }

        // Sanitize body_html - strip <script>, on* handlers, javascript:/file://
        if (! empty($data['body_html'])) {
            $data['body_html'] = $this->sanitizer->clean($data['body_html']);
        }

        $direction = $data['direction'] ?? 'out';
        $extra = [
            'company_id' => $request->user()->company_id,
            'author_id' => $request->user()->id,
            'direction' => $direction,
            'status' => $direction === 'in' ? Letter::STATUS_ARCHIVED : Letter::STATUS_DRAFT,
        ];

        // Surat masuk auto-generate internal registrar number untuk traceability.
        // Format: MSK/{seq:04d}/IBE/{ROMAN_MONTH}/{year}. Counter terpisah dari
        // outgoing - pakai key khusus.
        if ($direction === 'in') {
            $extra['letter_number'] = $this->generateIncomingNumber(
                $request->user()->company_id,
                Carbon::parse($data['letter_date']),
            );
            $extra['approved_at'] = now();
            $extra['archived_at'] = now();
        }

        $letter = Letter::create(array_merge($data, $extra));

        return $this->created(new LetterResource($letter->load(['category', 'author:id,name'])));
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.manage', 'update', $publicId);

        $letter = $this->findOrFail($request, $publicId);
        $request->user()->can('update', $letter) ?: abort(403, 'Tidak boleh edit surat ini.');
        if ($letter->isLocked()) {
            abort(422, 'Surat sudah signed/sent/archived - tidak bisa di-edit. Bikin revisi baru.');
        }
        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:300'],
            'purpose' => ['nullable', 'string', 'max:200'],
            'target_code' => ['nullable', 'in:A,B'],
            'body_html' => ['sometimes', 'string'],
            'body_data' => ['nullable', 'array'],
            'body_data.products' => ['nullable', 'array'],
            'body_data.products.*.name' => ['nullable', 'string', 'max:200'],
            'body_data.products.*.description' => ['nullable', 'string', 'max:500'],
            'body_data.products.*.qty' => ['nullable', 'numeric', 'min:0'],
            'body_data.products.*.unit' => ['nullable', 'string', 'max:30'],
            'body_data.products.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'body_data.products.*.total' => ['nullable', 'numeric', 'min:0'],
            'body_data.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'body_data.show_ppn' => ['nullable', 'boolean'],
            'recipient_name' => ['nullable', 'string', 'max:200'],
            'recipient_title' => ['nullable', 'string', 'max:200'],
            'recipient_company' => ['nullable', 'string', 'max:200'],
            'recipient_address' => ['nullable', 'string'],
            'recipient_email' => ['nullable', 'email'],
            'cc_list' => ['nullable', 'array'],
            'cc_list.*' => ['string', 'max:200'],
            'sender_name' => ['nullable', 'string', 'max:200'],
            'sender_company' => ['nullable', 'string', 'max:200'],
            'original_number' => ['nullable', 'string', 'max:200'],
            'received_date' => ['nullable', 'date'],
            'letter_date' => ['sometimes', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'signer_id' => [
                'nullable',
                'integer',
                Rule::exists('letter_signatures', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'cosigner_id' => [
                'nullable',
                'integer',
                Rule::exists('letter_signatures', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'bank_id' => [
                'nullable',
                'integer',
                Rule::exists('company_banks', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'confidential' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'internal_notes' => ['nullable', 'string'],
        ]);
        if (! empty($data['body_html'])) {
            $data['body_html'] = $this->sanitizer->clean($data['body_html']);
        }
        $letter->update($data);

        return $this->success(new LetterResource($letter->fresh(['category', 'signer'])));
    }

    public function destroy(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.manage', 'delete', $publicId);

        $letter = $this->findOrFail($request, $publicId);
        $request->user()->can('delete', $letter) ?: abort(403, 'Tidak boleh hapus surat ini.');
        if ($letter->isLocked()) {
            abort(422, 'Surat sudah signed/sent/archived - gunakan cancel untuk batalkan.');
        }
        $letter->delete();

        return $this->success(null, 'Letter deleted');
    }

    // ─── Workflow transitions (semua di-gate oleh LetterPolicy) ──────
    public function submit(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.manage', 'submit', $publicId);

        $letter = $this->findOrFail($request, $publicId);
        $request->user()->can('submit', $letter) ?: abort(403, 'Tidak boleh submit surat ini.');
        $r = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->success(new LetterResource($this->workflow->submit($letter, $request->user(), $r['note'] ?? null)));
    }

    public function requestRevision(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.approve', 'request_revision', $publicId);

        $letter = $this->findOrFail($request, $publicId);
        $request->user()->can('requestRevision', $letter) ?: abort(403, 'Hanya reviewer/admin yang boleh request revisi. Reviewer tidak boleh sama dengan author.');
        $r = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->success(new LetterResource($this->workflow->requestRevision($letter, $request->user(), $r['note'])));
    }

    public function approve(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.approve', 'approve', $publicId);

        $letter = $this->findOrFail($request, $publicId);
        $request->user()->can('approve', $letter) ?: abort(403, 'Hanya admin yang boleh approve, dan tidak boleh approve surat sendiri (4-eyes principle).');
        $r = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->success(new LetterResource($this->workflow->approve($letter, $request->user(), $r['note'] ?? null)));
    }

    public function sign(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.sign', 'sign', $publicId);

        $letter = $this->findOrFail($request, $publicId);
        $r = $request->validate([
            'signer_id' => [
                'nullable',
                'integer',
                Rule::exists('letter_signatures', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        // Kalau signer_id baru di-pick, set dulu ke letter supaya policy check pakai signer baru
        if (! empty($r['signer_id']) && $r['signer_id'] !== $letter->signer_id) {
            $letter->signer_id = $r['signer_id'];
            $letter->load('signer');
        }
        $request->user()->can('sign', $letter) ?: abort(403, 'Hanya signer terdaftar atau superadmin yang boleh sign surat ini.');

        return $this->success(new LetterResource($this->workflow->sign($letter, $request->user(), $r['signer_id'] ?? null, $r['note'] ?? null)));
    }

    public function send(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.send', 'send', $publicId);

        $letter = $this->findOrFail($request, $publicId);
        $request->user()->can('send', $letter) ?: abort(403, 'Tidak boleh tandai surat ini sebagai terkirim.');
        $r = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'send_email' => ['nullable', 'boolean'],
        ]);

        return $this->success(new LetterResource(
            $this->workflow->send($letter, $request->user(), $r['note'] ?? null, (bool) ($r['send_email'] ?? false))
        ));
    }

    public function archive(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.manage', 'archive', $publicId);

        $letter = $this->findOrFail($request, $publicId);
        $request->user()->can('archive', $letter) ?: abort(403, 'Tidak boleh archive surat ini.');
        $r = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->success(new LetterResource($this->workflow->archive($letter, $request->user(), $r['note'] ?? null)));
    }

    public function cancel(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.manage', 'cancel', $publicId);

        $letter = $this->findOrFail($request, $publicId);
        $request->user()->can('cancel', $letter) ?: abort(403, 'Tidak boleh cancel surat ini.');
        $r = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->success(new LetterResource($this->workflow->cancel($letter, $request->user(), $r['reason'])));
    }

    // ─── PDF preview + download ──────────────────────────────────────
    public function previewPdf(Request $request, string $publicId)
    {
        $this->requireLetterCapability($request, 'documents.letter.view', 'preview_pdf', $publicId);

        $letter = $this->findOrFail($request, $publicId, ['category', 'signer', 'cosigner', 'author']);

        return response($this->pdfSvc->renderToString($letter), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
        ]);
    }

    public function downloadPdf(Request $request, string $publicId)
    {
        $this->requireLetterCapability($request, 'documents.letter.view', 'download_pdf', $publicId);

        $letter = $this->findOrFail($request, $publicId, ['category', 'signer', 'cosigner', 'author']);
        $filename = ($letter->letter_number ?: 'draft-'.$letter->public_id).'.pdf';

        return response($this->pdfSvc->renderToString($letter), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    // ─── Number preview untuk UI ─────────────────────────────────────
    public function previewNumber(Request $request): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.create', 'preview_number');

        $data = $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('letter_categories', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'letter_date' => ['required', 'date'],
            'purpose' => ['nullable', 'string'],
            'target_code' => ['nullable', 'in:A,B'],
        ]);
        $cat = LetterCategory::where('company_id', $request->user()->company_id)
            ->findOrFail($data['category_id']);
        $preview = $this->numberSvc->preview(
            $cat,
            Carbon::parse($data['letter_date']),
            $data['purpose'] ?? null,
            $data['target_code'] ?? null,
        );

        return $this->success(['preview' => $preview]);
    }

    /**
     * Generate internal nomor untuk surat MASUK. Atomic dengan lockForUpdate
     * di "kategori virtual" MSK (di-store di letter_categories code='MSK').
     * Format: MSK/{seq:04d}/IBE/{ROMAN_MONTH}/{year}
     */
    private function generateIncomingNumber(int $companyId, Carbon $date): string
    {
        $cat = LetterCategory::firstOrCreate(
            ['company_id' => $companyId, 'code' => 'MSK'],
            [
                'name' => '(Internal) Surat Masuk',
                'description' => 'Counter virtual untuk nomor registrar surat masuk. Tidak dipakai untuk create surat keluar.',
                'numbering_format' => 'MSK/{seq:04d}/IBE/{ROMAN_MONTH}/{year}',
                'active' => false, // hidden dari kategori UI
            ]
        );

        return \DB::transaction(function () use ($cat, $date) {
            $fresh = LetterCategory::where('id', $cat->id)->lockForUpdate()->first();
            $year = (int) $date->format('Y');
            if ((int) $fresh->last_seq_year !== $year) {
                $fresh->last_seq_year = $year;
                $fresh->last_seq_value = 0;
            }
            $fresh->last_seq_value++;
            $fresh->save();
            $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][(int) $date->format('m') - 1];

            return sprintf('MSK/%04d/IBE/%s/%d', $fresh->last_seq_value, $roman, $year);
        });
    }

    // ─── Audit log timeline (untuk frontend detail page) ──────────────
    public function revisions(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.view', 'revisions', $publicId);

        $letter = $this->findOrFail($request, $publicId);
        $request->user()->can('view', $letter) ?: abort(403);
        $revs = $letter->revisions()
            ->with('changedBy:id,name,email')
            ->orderByDesc('version')
            ->get(['id', 'letter_id', 'version', 'status_before', 'status_after', 'changed_by_id', 'change_note', 'created_at']);

        return $this->success($revs);
    }

    /**
     * Export registry surat ke CSV.
     */
    public function exportCsv(Request $request)
    {
        $this->requireLetterCapability($request, 'documents.letter.view', 'export');

        $q = Letter::query()
            ->with(['category:id,code,name', 'author:id,name'])
            ->where('company_id', $request->user()->company_id);
        if ($dir = $request->query('direction')) {
            $q->where('direction', $dir);
        }
        if ($year = $request->query('year')) {
            $q->whereYear('letter_date', $year);
        }
        if ($cid = $request->query('category_id')) {
            $q->where('category_id', $cid);
        }

        $rows = [['Arah', 'Nomor', 'Tanggal', 'Kategori', 'Subject', 'Tujuan/Perihal', 'Penerima/Pengirim', 'Status', 'Author']];
        foreach ($q->orderBy('letter_date')->cursor() as $l) {
            $party = $l->direction === 'in'
                ? trim(($l->sender_name ?? '').($l->sender_company ? ' / '.$l->sender_company : ''))
                : trim(($l->recipient_name ?? '').($l->recipient_company ? ' / '.$l->recipient_company : ''));
            $rows[] = [
                $l->direction === 'in' ? 'MASUK' : 'KELUAR',
                $l->letter_number ?? '-',
                optional($l->letter_date)->format('Y-m-d'),
                $l->category?->code ?? '',
                $l->subject,
                $l->purpose ?? '',
                $party ?: '-',
                $l->status,
                $l->author?->name ?? '',
            ];
        }

        $stamp = now()->format('Ymd-Hi');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel UTF-8
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, "registry-surat-{$stamp}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Reply / surat balasan - create surat keluar dengan parent_letter_id pointing
     * ke surat masuk yang dibalas.
     */
    public function reply(Request $request, string $publicId): JsonResponse
    {
        $this->requireLetterCapability($request, 'documents.letter.create', 'reply', $publicId);

        $parent = $this->findOrFail($request, $publicId);
        $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('letter_categories', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
        ]);
        // Pre-fill recipient dari sender surat asal (sender → recipient)
        $reply = Letter::create([
            'company_id' => $request->user()->company_id,
            'author_id' => $request->user()->id,
            'parent_letter_id' => $parent->id,
            'category_id' => $request->input('category_id'),
            'direction' => 'out',
            'status' => Letter::STATUS_DRAFT,
            'subject' => 'Balasan: '.$parent->subject,
            'letter_date' => now()->toDateString(),
            'recipient_name' => $parent->sender_name,
            'recipient_company' => $parent->sender_company,
            'body_html' => "<p>Sehubungan dengan surat Bapak/Ibu nomor <strong>{$parent->original_number}</strong> tanggal ".
                                    ($parent->letter_date?->isoFormat('D MMMM YYYY') ?? '-').
                                    " perihal <strong>{$parent->subject}</strong>, kami sampaikan tanggapan sebagai berikut:</p><p>⟨ Tulis isi balasan ⟩</p>",
        ]);

        return $this->created(new LetterResource($reply->load(['category', 'author:id,name'])));
    }

    private function findOrFail(Request $request, string $publicId, array $with = []): Letter
    {
        return Letter::with($with)
            ->where('public_id', $publicId)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
    }

    private function requireLetterCapability(
        Request $request,
        string $capability,
        string $action,
        ?string $resourceId = null,
    ): void {
        $this->requireGranularCapability(
            $request->user(),
            $capability,
            'admin.access',
            'Letter capability required.',
            array_filter([
                'company_id' => $request->user()->company_id,
                'resource_type' => 'letter',
                'resource_id' => $resourceId,
                'action' => $action,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}

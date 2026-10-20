<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CompanyDocument;
use App\Models\DocumentVersion;
use App\Services\CompanyDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyDocumentController extends ApiController
{
    public function __construct(private readonly CompanyDocumentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireDocumentCapability($request, 'documents.repository.view', 'list');
        $companyId = (int) $request->user()->company_id;

        $q = CompanyDocument::query()
            ->where('company_id', $companyId)
            ->when($request->string('category')->toString(), fn ($w, $c) => $w->where('category', $c))
            ->when($request->string('status')->toString(), fn ($w, $s) => $w->where('status', $s))
            ->when($request->string('q')->toString(), fn ($w, $s) => $w->where(function ($x) use ($s) {
                $x->where('title', 'like', "%{$s}%")->orWhere('doc_code', 'like', "%{$s}%");
            }))
            ->orderByDesc('created_at');

        $rows = $q->get()->map(fn ($d) => [
            'id' => $d->id,
            'doc_code' => $d->doc_code,
            'title' => $d->title,
            'category' => $d->category,
            'owner_dept' => $d->owner_dept,
            'current_version' => $d->current_version,
            'effective_date' => $d->effective_date?->toDateString(),
            'expiry_date' => $d->expiry_date?->toDateString(),
            'alert_days_before' => $d->alert_days_before,
            'is_expiring_soon' => $d->is_expiring_soon,
            'is_expired' => $d->is_expired,
            'status' => $d->status,
            'tags' => $d->tags,
            'created_at' => $d->created_at?->toIso8601String(),
        ]);

        return $this->success([
            'data' => $rows,
            'expiring' => $this->service->expiringList($companyId, 60),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireDocumentCapability($request, 'documents.repository.view', 'view', $id);
        $companyId = (int) $request->user()->company_id;
        $d = CompanyDocument::with([
            'versions',
            'owner' => fn ($query) => $query
                ->where('company_id', $companyId)
                ->select(['id', 'name', 'company_id']),
        ])
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);

        return $this->success([
            'id' => $d->id,
            'doc_code' => $d->doc_code,
            'title' => $d->title,
            'category' => $d->category,
            'description' => $d->description,
            'owner_dept' => $d->owner_dept,
            'owner' => $d->owner ? ['id' => $d->owner->id, 'name' => $d->owner->name] : null,
            'tags' => $d->tags,
            'current_version' => $d->current_version,
            'effective_date' => $d->effective_date?->toDateString(),
            'expiry_date' => $d->expiry_date?->toDateString(),
            'alert_days_before' => $d->alert_days_before,
            'is_expiring_soon' => $d->is_expiring_soon,
            'is_expired' => $d->is_expired,
            'status' => $d->status,
            'versions' => $d->versions->map(fn (DocumentVersion $v) => [
                'id' => $v->id,
                'version_label' => $v->version_label,
                'original_filename' => $v->original_filename,
                'mime_type' => $v->mime_type,
                'file_size_bytes' => $v->file_size_bytes,
                'change_notes' => $v->change_notes,
                'is_current' => $v->is_current,
                'uploaded_at' => $v->uploaded_at?->toIso8601String(),
                'file_hash_sha256' => $v->file_hash_sha256,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireDocumentCapability($request, 'documents.repository.manage', 'create');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'category' => ['required', 'in:contract,sop,certificate,policy,manual,invoice_scan,other'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_dept' => ['nullable', 'string', 'max:100'],
            'owner_user_id' => [
                'nullable',
                'string',
                'size:26',
                Rule::exists('users', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'tags' => ['nullable', 'array'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'alert_days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
            'file' => ['nullable', 'file', 'max:20480'], // 20MB
            'change_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $doc = $this->service->register($request->user(), $data, $request->file('file'));

        return $this->created([
            'id' => $doc->id,
            'doc_code' => $doc->doc_code,
        ], "Dokumen {$doc->doc_code} terdaftar.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireDocumentCapability($request, 'documents.repository.manage', 'update', $id);
        $d = CompanyDocument::where('company_id', $request->user()->company_id)->findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_dept' => ['nullable', 'string', 'max:100'],
            'owner_user_id' => [
                'nullable',
                'string',
                'size:26',
                Rule::exists('users', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'tags' => ['nullable', 'array'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'alert_days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
            'status' => ['nullable', 'in:active,expired,archived,superseded'],
        ]);
        $d->update($data);

        return $this->success(['doc_code' => $d->doc_code], 'Diperbarui.');
    }

    public function uploadVersion(Request $request, int $id): JsonResponse
    {
        $this->requireDocumentCapability($request, 'documents.repository.manage', 'upload_version', $id);
        $d = CompanyDocument::where('company_id', $request->user()->company_id)->findOrFail($id);
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'change_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $v = $this->service->uploadVersion(
            $request->user(),
            $d,
            $request->file('file'),
            $request->input('change_notes'),
        );

        return $this->created([
            'version_label' => $v->version_label,
            'doc_code' => $d->doc_code,
        ], "Versi {$v->version_label} diupload.");
    }

    public function download(Request $request, int $id, int $versionId): StreamedResponse
    {
        $this->requireDocumentCapability($request, 'documents.repository.view', 'download_version', $id);
        $d = CompanyDocument::where('company_id', $request->user()->company_id)->findOrFail($id);
        $v = DocumentVersion::where('document_id', $d->id)->findOrFail($versionId);

        return Storage::disk('local')->download($v->file_path, $v->original_filename);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireDocumentCapability($request, 'documents.repository.manage', 'archive', $id);
        $d = CompanyDocument::where('company_id', $request->user()->company_id)->findOrFail($id);
        $d->delete();

        return $this->success(['doc_code' => $d->doc_code], 'Diarsipkan (soft delete).');
    }

    private function requireDocumentCapability(
        Request $request,
        string $capability,
        string $action,
        ?int $resourceId = null,
    ): void {
        $this->requireGranularCapability(
            $request->user(),
            $capability,
            'admin.access',
            'Document repository capability required.',
            array_filter([
                'company_id' => $request->user()->company_id,
                'resource_type' => 'company_document',
                'resource_id' => $resourceId,
                'action' => $action,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContractTemplate;
use App\Models\ContractTemplateVersion;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ContractTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\IOFactory;

/**
 * Manage contract templates per employment_type. Admin bisa edit rich text
 * atau upload DOCX. Preview dengan sample data before activate.
 *
 * Cuma superadmin + HR admin yang bisa manage templates.
 */
class ContractTemplateController extends ApiController
{
    private const TYPES = ['permanent', 'probation', 'pkwt', 'training', 'intern_campus', 'intern_hub', 'pkl_smk'];

    public function __construct(
        private readonly ContractTemplateService $service,
        private readonly AuditLogService $auditLog,
    ) {}

    /** GET /admin/contract-templates - list semua template company */
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $templates = ContractTemplate::where('company_id', $admin->company_id)
            ->orderBy('employment_type')
            ->get();

        // Fill missing types dengan null placeholder supaya UI bisa tampil semua slot
        $byType = $templates->keyBy('employment_type');
        $data = [];
        foreach (self::TYPES as $type) {
            $tpl = $byType->get($type);
            $data[] = [
                'employment_type' => $type,
                'label' => User::EMPLOYMENT_STATUSES[$type] ?? $type,
                'exists' => (bool) $tpl,
                'id' => $tpl?->id,
                'title' => $tpl?->title,
                'is_active' => $tpl?->is_active,
                'version' => $tpl?->version,
                'source_type' => $tpl?->source_type,
                'variables' => $tpl?->variables ?? [],
                'updated_at' => $tpl?->updated_at?->format('Y-m-d H:i'),
                'updated_by' => $tpl?->updater?->name,
            ];
        }

        return $this->success(['templates' => $data]);
    }

    /** GET /admin/contract-templates/{type} - get single template full body */
    public function show(Request $request, string $type): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $tpl = ContractTemplate::where('company_id', $admin->company_id)
            ->where('employment_type', $type)
            ->firstOrFail();

        return $this->success([
            'template' => [
                'id' => $tpl->id,
                'employment_type' => $tpl->employment_type,
                'title' => $tpl->title,
                'body_html' => $this->service->sanitizeBody($tpl->body_html),
                'source_type' => $tpl->source_type,
                'source_docx_url' => null,
                'source_docx_available' => false,
                'source_docx_upload_enabled' => (bool) config('sensitive_file_features.contract_template_docx', false),
                'variables' => $tpl->variables,
                'version' => $tpl->version,
                'is_active' => $tpl->is_active,
                'updated_at' => $tpl->updated_at?->format('Y-m-d H:i'),
            ],
        ]);
    }

    /** POST /admin/contract-templates → create/update via rich text (body_html) */
    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $payload = $request->validate([
            'employment_type' => ['required', Rule::in(self::TYPES)],
            'title' => ['required', 'string', 'max:200'],
            'body_html' => ['required', 'string'],
        ]);
        $payload['body_html'] = $this->sanitizedBodyOrFail($payload['body_html']);

        // Snapshot versi sebelumnya kalau template sudah ada
        $existing = ContractTemplate::where('company_id', $admin->company_id)
            ->where('employment_type', $payload['employment_type'])
            ->first();
        if ($existing?->source_docx_path) {
            throw ValidationException::withMessages([
                'source_docx' => 'Template DOCX lama harus dikarantina melalui migrasi aset privat sebelum template dapat diperbarui.',
            ]);
        }
        if ($existing) {
            $this->snapshotVersion($existing, $admin->id, 'update');
        }

        $values = [
            'title' => $payload['title'],
            'body_html' => $payload['body_html'],
            'source_type' => 'text',
            'is_active' => true,
            'variables' => null,  // di-detect di next line
            'updated_by' => $admin->id,
        ];
        if ($existing) {
            $existing->fill($values)->save();
            $tpl = $existing;
        } else {
            $tpl = ContractTemplate::create(array_merge($values, [
                'company_id' => $admin->company_id,
                'employment_type' => $payload['employment_type'],
                'created_by' => $admin->id,
            ]));
        }
        // Detect vars fresh
        $tpl->variables = $tpl->detectVariables();
        $tpl->version = ($tpl->version ?? 0) + 1;
        $tpl->save();

        $this->auditLog->log(
            'contract_template.saved', $admin, 'contract_template', $tpl->id,
            ['employment_type' => $tpl->employment_type, 'version' => $tpl->version],
            $request,
        );

        return $this->success(['template' => $tpl], 'Template kontrak tersimpan.');
    }

    /**
     * POST /admin/contract-templates/upload-docx → upload DOCX file.
     * Backend parse DOCX ke HTML, simpan sebagai body_html + preserve original DOCX.
     */
    public function uploadDocx(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        return response()->json([
            'message' => 'Upload DOCX dinonaktifkan sementara sampai penyimpanan privat tersedia. Gunakan editor template teks.',
        ], 503);
    }

    /** GET /admin/contract-templates/{type}/preview - render HTML dengan sample data */
    public function preview(Request $request, string $type): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $tpl = ContractTemplate::where('company_id', $admin->company_id)
            ->where('employment_type', $type)
            ->firstOrFail();

        return $this->success([
            'rendered_html' => $this->service->previewSample($tpl),
        ]);
    }

    /**
     * GET /admin/contract-templates/{type}/preview-pdf
     *
     * PDF preview lengkap dengan KOP surat + signature block + watermark PREVIEW.
     * Return inline PDF (bisa di-embed iframe di frontend).
     */
    public function previewPdf(Request $request, string $type)
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $tpl = ContractTemplate::where('company_id', $admin->company_id)
            ->where('employment_type', $type)
            ->firstOrFail();

        return $this->service->generatePreviewPdf($tpl);
    }

    /** DELETE /admin/contract-templates/{type} - hapus template */
    public function destroy(Request $request, string $type): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $tpl = ContractTemplate::where('company_id', $admin->company_id)
            ->where('employment_type', $type)
            ->firstOrFail();

        if ($tpl->source_docx_path) {
            throw ValidationException::withMessages([
                'source_docx' => 'Template DOCX lama harus dikarantina melalui migrasi aset privat sebelum template dapat dihapus.',
            ]);
        }
        $tpl->delete();

        $this->auditLog->log('contract_template.deleted', $admin, 'contract_template', $tpl->id, [
            'employment_type' => $type,
        ], $request);

        return $this->success(null, 'Template kontrak dihapus.');
    }

    /** GET /admin/contract-templates/{type}/versions - list snapshot history */
    public function versions(Request $request, string $type)
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $tpl = ContractTemplate::where('company_id', $admin->company_id)
            ->where('employment_type', $type)
            ->firstOrFail();

        $versions = ContractTemplateVersion::where('template_id', $tpl->id)
            ->with('snapshotter:id,name')
            ->orderByDesc('version')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'version' => $v->version,
                'title' => $v->title,
                'body_html_size' => strlen($v->body_html),
                'snapshot_reason' => $v->snapshot_reason,
                'snapshot_by' => $v->snapshotter?->name,
                'snapshot_at' => $v->snapshot_at?->toIso8601String(),
            ]);

        return $this->success([
            'current_version' => $tpl->version,
            'versions' => $versions,
        ]);
    }

    /** GET /admin/contract-templates/{type}/versions/{id} - get full body_html versi lama */
    public function versionDetail(Request $request, string $type, int $versionId)
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $tpl = ContractTemplate::where('company_id', $admin->company_id)
            ->where('employment_type', $type)
            ->firstOrFail();

        $v = ContractTemplateVersion::where('template_id', $tpl->id)
            ->findOrFail($versionId);

        return $this->success([
            'version' => $v->version,
            'title' => $v->title,
            'body_html' => $this->service->sanitizeBody($v->body_html),
        ]);
    }

    /** POST /admin/contract-templates/{type}/restore/{id} - restore ke versi tertentu */
    public function restoreVersion(Request $request, string $type, int $versionId)
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $tpl = ContractTemplate::where('company_id', $admin->company_id)
            ->where('employment_type', $type)
            ->firstOrFail();

        $v = ContractTemplateVersion::where('template_id', $tpl->id)
            ->findOrFail($versionId);
        $restoredBody = $this->sanitizedBodyOrFail($v->body_html);

        // Snapshot versi current sebelum di-overwrite (biar bisa undo restore)
        $this->snapshotVersion($tpl, $admin->id, "before-restore-v{$v->version}");

        $tpl->update([
            'title' => $v->title,
            'body_html' => $restoredBody,
            'version' => ($tpl->version ?? 0) + 1,
            'updated_by' => $admin->id,
        ]);

        $this->auditLog->log(
            'contract_template.restored', $admin, 'contract_template', $tpl->id,
            ['employment_type' => $type, 'restored_from_version' => $v->version, 'new_version' => $tpl->version],
            $request,
        );

        return $this->success(['template' => $tpl], "Template berhasil di-restore ke versi {$v->version}.");
    }

    private function sanitizedBodyOrFail(string $bodyHtml): string
    {
        $sanitized = $this->service->sanitizeBody($bodyHtml);
        $plainText = html_entity_decode(strip_tags($sanitized), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (trim($plainText) === '') {
            throw ValidationException::withMessages([
                'body_html' => 'Isi template kosong atau hanya berisi markup yang tidak diizinkan.',
            ]);
        }

        return $sanitized;
    }

    /** Snapshot template current state ke history table. Dipakai internal. */
    private function snapshotVersion(ContractTemplate $tpl, string $userId, string $reason): void
    {
        ContractTemplateVersion::create([
            'template_id' => $tpl->id,
            'version' => $tpl->version ?? 1,
            'title' => $tpl->title,
            'body_html' => $tpl->body_html,
            'source_type' => $tpl->source_type ?? 'text',
            'snapshot_by' => $userId,
            'snapshot_reason' => $reason,
            'snapshot_at' => now(),
        ]);
    }

    /**
     * Parse DOCX to simple HTML using PhpOffice/PhpWord.
     * Preserves paragraphs, bold, italic, lists, tables. Complex formatting mungkin
     * hilang - user bisa lanjut edit di WYSIWYG.
     */
    private function parseDocxToHtml(string $absolutePath): string
    {
        $phpWord = IOFactory::load($absolutePath);
        $htmlWriter = IOFactory::createWriter($phpWord, 'HTML');

        ob_start();
        $htmlWriter->save('php://output');
        $fullHtml = ob_get_clean();

        // Extract body content only (skip head/style)
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $fullHtml, $m)) {
            return trim($m[1]);
        }

        return $fullHtml;
    }
}

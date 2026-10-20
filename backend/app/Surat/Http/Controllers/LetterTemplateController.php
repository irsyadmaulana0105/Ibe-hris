<?php

namespace App\Surat\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Surat\Models\LetterTemplate;
use App\Surat\Services\HtmlSanitizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LetterTemplateController extends ApiController
{
    public function __construct(private HtmlSanitizerService $sanitizer) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireTemplateCapability($request, 'documents.template.view', 'view');

        $q = LetterTemplate::query()
            ->where('company_id', $request->user()->company_id)
            ->with('category:id,code,name')
            ->where('active', true);
        if ($cid = $request->query('category_id')) {
            $q->where('category_id', $cid);
        }
        if ($lang = $request->query('language')) {
            $q->where('language', $lang);
        }

        return $this->success($q->orderBy('display_order')->orderBy('code')->get());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireTemplateCapability($request, 'documents.template.view', 'view', $id);

        $tpl = LetterTemplate::where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->with('category:id,code,name')
            ->firstOrFail();

        return $this->success($tpl);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireTemplateCapability($request, 'documents.template.manage', 'create');

        $data = $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('letter_categories', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'language' => ['nullable', 'in:id,en'],
            'body_html' => ['required', 'string'],
            'placeholders_schema' => ['nullable', 'array'],
            'default_values' => ['nullable', 'array'],
            'active' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
        ]);
        $data['body_html'] = $this->sanitizer->clean($data['body_html']);
        $tpl = LetterTemplate::create(array_merge($data, [
            'company_id' => $request->user()->company_id,
            'language' => $data['language'] ?? 'id',
            'is_system' => false,
        ]));

        return $this->created($tpl);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireTemplateCapability($request, 'documents.template.manage', 'update', $id);

        $tpl = LetterTemplate::where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'body_html' => ['sometimes', 'string'],
            'placeholders_schema' => ['nullable', 'array'],
            'default_values' => ['nullable', 'array'],
            'active' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
        ]);
        if (isset($data['body_html'])) {
            $data['body_html'] = $this->sanitizer->clean($data['body_html']);
        }
        $tpl->update(array_merge($data, ['version' => $tpl->version + 1]));

        return $this->success($tpl);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireTemplateCapability($request, 'documents.template.manage', 'delete', $id);

        $tpl = LetterTemplate::where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
        if ($tpl->is_system) {
            abort(422, 'Template sistem tidak bisa dihapus - di-deactivate saja.');
        }
        $tpl->delete();

        return $this->success(null, 'Template deleted');
    }

    private function requireTemplateCapability(
        Request $request,
        string $capability,
        string $action,
        ?int $resourceId = null,
    ): void {
        $this->requireGranularCapability(
            $request->user(),
            $capability,
            'admin.access',
            'Letter template capability required.',
            array_filter([
                'company_id' => $request->user()->company_id,
                'resource_type' => 'letter_template',
                'resource_id' => $resourceId,
                'action' => $action,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}

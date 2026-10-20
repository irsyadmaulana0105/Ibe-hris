<?php

namespace App\Surat\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Surat\Models\LetterCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LetterCategoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireGranularCapability(
            $request->user(),
            'documents.category.view',
            'admin.access',
            'Letter category read access required.',
            $this->authorizationContext($request, 'view'),
        );

        $rows = LetterCategory::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('display_order')->orderBy('code')
            ->get(['id', 'code', 'name', 'description', 'numbering_format', 'active', 'display_order', 'last_seq_value', 'last_seq_year']);

        return $this->success($rows);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireGranularCapability(
            $request->user(),
            'documents.category.manage',
            'admin.access',
            'Letter category management access required.',
            $this->authorizationContext($request, 'update', $id),
        );

        $cat = LetterCategory::where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'numbering_format' => ['sometimes', 'string', 'max:200'],
            'active' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
        ]);
        $cat->update($data);

        return $this->success($cat);
    }

    private function authorizationContext(Request $request, string $action, ?int $resourceId = null): array
    {
        return array_filter([
            'company_id' => $request->user()->company_id,
            'resource_type' => 'letter_category',
            'resource_id' => $resourceId,
            'action' => $action,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

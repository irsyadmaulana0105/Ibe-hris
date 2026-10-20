<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Marketing\Models\Pipeline;
use App\Marketing\Models\Stage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $pipelines = Pipeline::query()
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->with(['stages' => fn ($q) => $q->orderBy('sort_order')])
            ->orderByDesc('is_default')
            ->get();

        return $this->success($pipelines);
    }

    public function stages(Request $request, int $pipelineId): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $stages = Stage::query()
            ->where('company_id', $user->company_id)
            ->where('pipeline_id', $pipelineId)
            ->orderBy('sort_order')
            ->get();

        return $this->success($stages);
    }
}

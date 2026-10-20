<?php

namespace App\Produksi\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Produksi\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->requireCapability($actor, 'production.view', 'Akses monitoring vendor diperlukan.');

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $orders = WorkOrder::query()->withoutGlobalScopes()
            ->where('company_id', $actor->company_id)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->integer('vendor_id'), fn ($query, $vendorId) => $query->where('vendor_id', $vendorId))
            ->when($request->integer('project_id'), fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($request->string('q')->toString(), fn ($query, $search) => $query->where(function ($nested) use ($search) {
                $nested->where('ref_no', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            }))
            ->with([
                'vendor' => fn ($query) => $query->withoutGlobalScopes()
                    ->where('company_id', $actor->company_id)
                    ->select(['id', 'company_id', 'name', 'pic_name']),
                'project' => fn ($query) => $query->withoutGlobalScopes()
                    ->where('company_id', $actor->company_id)
                    ->select(['id', 'company_id', 'name']),
                'progress' => fn ($query) => $query->withoutGlobalScopes()
                    ->where('company_id', $actor->company_id)
                    ->orderBy('submitted_at'),
            ])
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        return $this->paginated($orders, $orders->getCollection());
    }
}

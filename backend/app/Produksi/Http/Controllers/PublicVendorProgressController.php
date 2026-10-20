<?php

namespace App\Produksi\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Produksi\Models\Vendor;
use App\Produksi\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicVendorProgressController extends ApiController
{
    public function listOrders(Request $request): JsonResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->attributes->get('vendor');
        abort_unless($vendor, 404);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $orders = WorkOrder::query()->withoutGlobalScopes()
            ->where('company_id', $vendor->company_id)
            ->where('vendor_id', $vendor->id)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->with([
                'progress' => fn ($query) => $query->withoutGlobalScopes()
                    ->where('company_id', $vendor->company_id)
                    ->select([
                        'id', 'company_id', 'work_order_id', 'progress_percent',
                        'note', 'photo_paths', 'submitted_by', 'submitted_at',
                    ])
                    ->orderBy('submitted_at'),
            ])
            ->select([
                'id', 'company_id', 'vendor_id', 'project_id', 'ref_no', 'title',
                'description', 'scheduled_start', 'scheduled_end', 'actual_start',
                'actual_end', 'status', 'updated_at',
            ])
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        return $this->paginated($orders, $orders->getCollection(), [
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
            ],
        ]);
    }
}

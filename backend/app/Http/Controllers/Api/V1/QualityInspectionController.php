<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\QualityInspection;
use App\Produksi\Services\QualityInspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QualityInspectionController extends ApiController
{
    public function __construct(private readonly QualityInspectionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $rows = QualityInspection::query()
            ->where('company_id', $request->user()->company_id)
            ->when($request->string('status')->toString(), fn ($w, $s) => $w->where('status', $s))
            ->when($request->string('type')->toString(), fn ($w, $t) => $w->where('inspection_type', $t))
            ->with(['workOrder:id,wo_number', 'product:id,code,name', 'inspector:id,name'])
            ->orderByDesc('inspection_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (QualityInspection $i) => [
                'id'              => $i->id,
                'inspection_number'=> $i->inspection_number,
                'work_order'      => $i->workOrder ? ['id' => $i->workOrder->id, 'wo_number' => $i->workOrder->wo_number] : null,
                'product'         => $i->product ? ['id' => $i->product->id, 'code' => $i->product->code, 'name' => $i->product->name] : null,
                'product_serial'  => $i->product_serial,
                'inspection_type' => $i->inspection_type,
                'inspection_date' => $i->inspection_date?->toDateString(),
                'inspector'       => $i->inspector ? ['id' => $i->inspector->id, 'name' => $i->inspector->name] : null,
                'status'          => $i->status,
                'total_checks'    => $i->total_checks,
                'passed_checks'   => $i->passed_checks,
                'failed_checks'   => $i->failed_checks,
                'pass_rate'       => $i->pass_rate,
            ]);

        // KPI rollup last 30 days
        $since = now()->subDays(30);
        $recent = QualityInspection::query()
            ->where('company_id', $request->user()->company_id)
            ->where('inspection_date', '>=', $since->toDateString())
            ->whereNotIn('status', ['planned', 'in_progress'])
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status="passed" THEN 1 ELSE 0 END) as passed, SUM(CASE WHEN status="failed" THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN status="rework_needed" THEN 1 ELSE 0 END) as rework, SUM(passed_checks) as sum_passed, SUM(total_checks) as sum_total')
            ->first();

        $kpi = [
            'total_30d'      => (int) ($recent->total ?? 0),
            'passed_30d'     => (int) ($recent->passed ?? 0),
            'failed_30d'     => (int) ($recent->failed ?? 0),
            'rework_30d'     => (int) ($recent->rework ?? 0),
            'first_pass_yield'=> ($recent->total ?? 0) > 0 ? round(($recent->passed / $recent->total) * 100, 1) : 0,
            'check_pass_rate' => ($recent->sum_total ?? 0) > 0 ? round(($recent->sum_passed / $recent->sum_total) * 100, 1) : 0,
        ];

        return $this->success(['data' => $rows, 'kpi' => $kpi]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $i = QualityInspection::where('company_id', $request->user()->company_id)
            ->with(['workOrder:id,wo_number', 'product:id,code,name', 'inspector:id,name'])
            ->findOrFail($id);
        return $this->success([
            'id'                => $i->id,
            'inspection_number' => $i->inspection_number,
            'work_order'        => $i->workOrder ? ['id' => $i->workOrder->id, 'wo_number' => $i->workOrder->wo_number] : null,
            'product'           => $i->product ? ['id' => $i->product->id, 'code' => $i->product->code, 'name' => $i->product->name] : null,
            'product_serial'    => $i->product_serial,
            'inspection_type'   => $i->inspection_type,
            'inspection_date'   => $i->inspection_date?->toDateString(),
            'inspector'         => $i->inspector ? ['id' => $i->inspector->id, 'name' => $i->inspector->name] : null,
            'status'            => $i->status,
            'checkpoints'       => $i->checkpoints,
            'total_checks'      => $i->total_checks,
            'passed_checks'     => $i->passed_checks,
            'failed_checks'     => $i->failed_checks,
            'pass_rate'         => $i->pass_rate,
            'defects_found'     => $i->defects_found,
            'corrective_action' => $i->corrective_action,
            'notes'             => $i->notes,
            'created_at'        => $i->created_at?->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $data = $request->validate([
            'work_order_id'   => ['nullable', 'integer', 'exists:work_orders,id'],
            'product_id'      => ['nullable', 'integer', 'exists:products,id'],
            'product_serial'  => ['nullable', 'string', 'max:64'],
            'inspection_type' => ['required', 'in:incoming,in_process,final,customer_return'],
            'inspection_date' => ['required', 'date'],
            'inspector_user_id'=> ['nullable', 'string', 'size:26'],
            'checkpoints'     => ['nullable', 'array'],
            'checkpoints.*.name'   => ['required_with:checkpoints', 'string', 'max:200'],
            'checkpoints.*.expected' => ['nullable', 'string'],
            'checkpoints.*.severity' => ['nullable', 'in:minor,major,critical'],
            'notes'           => ['nullable', 'string'],
        ]);
        $insp = $this->service->plan($request->user(), $data);
        return $this->created([
            'id'                => $insp->id,
            'inspection_number' => $insp->inspection_number,
        ], "Inspection {$insp->inspection_number} dibuat.");
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $insp = QualityInspection::where('company_id', $request->user()->company_id)->findOrFail($id);
        $data = $request->validate([
            'checkpoints'         => ['required', 'array'],
            'checkpoints.*.name'  => ['required', 'string'],
            'checkpoints.*.result'=> ['required', 'in:pass,fail'],
            'checkpoints.*.actual'=> ['nullable', 'string'],
            'checkpoints.*.severity' => ['nullable', 'in:minor,major,critical'],
            'checkpoints.*.notes' => ['nullable', 'string'],
            'defects_found'       => ['nullable', 'string'],
            'corrective_action'   => ['nullable', 'string'],
            'verified_by_user_id' => ['nullable', 'string', 'size:26'],
            'notes'               => ['nullable', 'string'],
        ]);
        $insp = $this->service->submit($request->user(), $insp, $data);
        return $this->success([
            'id'              => $insp->id,
            'status'          => $insp->status,
            'pass_rate'       => $insp->pass_rate,
            'total_checks'    => $insp->total_checks,
            'passed_checks'   => $insp->passed_checks,
            'failed_checks'   => $insp->failed_checks,
        ], "Inspection submitted: {$insp->status}.");
    }
}

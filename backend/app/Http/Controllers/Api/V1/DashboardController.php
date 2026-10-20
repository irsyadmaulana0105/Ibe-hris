<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AttendanceResource;
use App\Http\Resources\LeaveResource;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function __construct(private readonly DashboardService $service) {}

    /**
     * GET /api/v1/dashboard
     * Employee home dashboard - single call to load all summary data.
     */
    public function employee(Request $request): JsonResponse
    {
        $data = $this->service->employeeDashboard($request->user());

        return $this->success([
            'attendance'     => $data['attendance']
                ? new AttendanceResource($data['attendance'])
                : null,
            'shift' => $data['shift'] ? [
                'id'       => $data['shift']->id,
                'name'     => $data['shift']->name,
                'start_time' => $data['shift']->start_time ?? null,
                'end_time' => $data['shift']->end_time ?? null,
            ] : null,
            'annual_balance'         => $data['annual_balance'],
            'pending_leave_count'          => $data['pending_leave'],
            'pending_overtime_count'       => $data['pending_overtime'],
            'pending_salary_advance_count' => $data['pending_salary_advance'],
            'latest_payroll' => $data['latest_payroll'] ? [
                'id'         => $data['latest_payroll']->id,
                'period'     => $data['latest_payroll']->month . '/' . $data['latest_payroll']->year,
                'net_salary'  => $data['latest_payroll']->net_total,
                'status'     => $data['latest_payroll']->status,
            ] : null,
            'recent_leaves' => LeaveResource::collection($data['recent_leaves']),
            'admin_summary' => $data['admin_summary'],
            'admin_reporting' => $data['admin_reporting'],
            'recent_audit_logs' => $data['recent_audit_logs']->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'entity_type' => $log->entity_type,
                'entity_id' => (string) $log->entity_id,
                'meta' => $log->meta,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                    'email' => $log->actor->email,
                    'group' => $log->actor->group,
                ] : null,
            ])->values(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AiModuleSummaryService;
use App\Services\AuditLogService;
use App\Services\ModuleEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiModuleSummaryController extends ApiController
{
    public function __invoke(Request $request, string $module, AiModuleSummaryService $summaries, ModuleEntitlementService $entitlements, AuditLogService $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isAdmin, 403, 'Akses ringkasan ERP memerlukan peran admin.');
        abort_unless(array_key_exists($module, config('saas_modules.modules', [])), 404, 'Modul ERP tidak dikenal.');

        $companyId = (int) $user->company_id;
        $requestedCompany = $request->header('X-Company-Id');
        abort_if($requestedCompany !== null && (int) $requestedCompany !== $companyId, 403, 'Konteks tenant tidak sesuai.');
        abort_unless($entitlements->enabled($companyId, $module), 403, 'Modul ERP tidak aktif untuk tenant ini.');

        $purpose = mb_substr((string) $request->header('X-AI-Purpose', 'summary'), 0, 80);
        $data = $summaries->summary($companyId, $module);
        $audit->log('ai.module_summary.read', $user, 'ErpModule', $module, [
            'module' => $module,
            'purpose' => $purpose,
            'correlation_id' => mb_substr((string) $request->header('X-Correlation-Id'), 0, 100),
        ], $request);

        return $this->success($data);
    }
}

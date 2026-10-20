<?php

use App\Http\Controllers\Api\V1\AppNotificationController;
use App\Http\Controllers\Api\V1\ApprovalRequestController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceDeviceController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ScheduledEmailController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\BankStatementController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\BroadcastController;
use App\Http\Controllers\Api\V1\BusinessTripController;
use App\Http\Controllers\Api\V1\ClientVisitController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanyDocumentController;
use App\Http\Controllers\Api\V1\ComplaintController;
use App\Http\Controllers\Api\V1\ContractAssetController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\ContractLifecycleController;
use App\Http\Controllers\Api\V1\ContractTemplateController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\Customer360Controller;
use App\Http\Controllers\Api\V1\CustomerSurveyController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DivisionController;
use App\Http\Controllers\Api\V1\EducationController;
use App\Http\Controllers\Api\V1\EmailMigrationController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EnterpriseRbacController;
use App\Http\Controllers\Api\V1\ExecutiveSummaryController;
use App\Http\Controllers\Api\V1\FaceApprovalController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\HardwareController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\IdentityController;
use App\Http\Controllers\Api\V1\InboxController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\IntegrationProxyController;
use App\Http\Controllers\Api\V1\JobTitleController;
use App\Http\Controllers\Api\V1\KursController;
use App\Http\Controllers\Api\V1\LateDeductionTierController;
use App\Http\Controllers\Api\V1\LearningController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\LeavePolicyController;
use App\Http\Controllers\Api\V1\MailboxController;
use App\Http\Controllers\Api\V1\MailTestController;
use App\Http\Controllers\Api\V1\NameplatePdfController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfficeLocationController;
use App\Http\Controllers\Api\V1\OvertimeController;
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\PayrollFormulaController;
use App\Http\Controllers\Api\V1\PerformanceController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductionCalendarController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Http\Controllers\Api\V1\QualityInspectionController;
use App\Http\Controllers\Api\V1\QuotationController;
use App\Http\Controllers\Api\V1\RbacController;
use App\Http\Controllers\Api\V1\RecruitmentAiController;
use App\Http\Controllers\Api\V1\RecruitmentController;
use App\Http\Controllers\Api\V1\ReimbursementController;
use App\Http\Controllers\Api\V1\SalaryAdvanceController;
use App\Http\Controllers\Api\V1\SerialNumberController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\ShiftScheduleController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Http\Controllers\Api\V1\SsoController;
use App\Http\Controllers\Api\V1\ThemeController;
use App\Http\Controllers\Api\V1\TreasuryController;
use App\Http\Controllers\Api\V1\UserRoleController;
use App\Http\Controllers\Api\V1\VendorScorecardController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WebmailGatewayController;
use App\Http\Controllers\Api\V1\WilayahController;
use App\Http\Controllers\Api\V1\WorkCertificateController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Controllers\Api\V1\WorkflowEngineController;
use App\Keuangan\Http\Controllers\AccountController;
use App\Keuangan\Http\Controllers\BillController;
use App\Keuangan\Http\Controllers\CustomerController;
use App\Keuangan\Http\Controllers\FinanceDashboardController;
use App\Keuangan\Http\Controllers\InvoiceController;
use App\Keuangan\Http\Controllers\JournalController;
use App\Keuangan\Http\Controllers\ReportController;
use App\Keuangan\Http\Controllers\SupplierController;
use App\Marketing\Http\Controllers\ActivityController;
use App\Marketing\Http\Controllers\ContactController;
use App\Marketing\Http\Controllers\DealController;
use App\Marketing\Http\Controllers\LeadController;
use App\Marketing\Http\Controllers\LeadFormController;
use App\Marketing\Http\Controllers\PipelineController;
use App\Marketing\Http\Controllers\PublicLeadFormController;
use App\Marketing\Http\Controllers\PublicUnsubscribeController;
use App\Marketing\Http\Controllers\CampaignController;
use App\Models\User;
use App\Production\Http\Controllers\BatchController;
use App\Production\Http\Controllers\BomController;
use App\Production\Http\Controllers\MrpController;
use App\Production\Http\Controllers\PurchaseOrderController;
use App\Production\Http\Controllers\StockController;
use App\Production\Http\Controllers\StockCountController;
use App\Production\Http\Controllers\WarehouseController;
use App\Produksi\Http\Controllers\PublicVendorProgressController;
use App\Produksi\Http\Controllers\WorkOrderController;
use App\Produksi\Http\Middleware\VendorTokenMiddleware;
use App\Surat\Http\Controllers\CompanyBankController;
use App\Surat\Http\Controllers\LetterAnalyticsController;
use App\Surat\Http\Controllers\LetterAttachmentController;
use App\Surat\Http\Controllers\LetterCategoryController;
use App\Surat\Http\Controllers\LetterController;
use App\Surat\Http\Controllers\LetterDispositionController;
use App\Surat\Http\Controllers\LetterSettingController;
use App\Surat\Http\Controllers\LetterSignatureController;
use App\Surat\Http\Controllers\LetterTemplateController;
use App\Warehouse\Http\Controllers\FixedAssetController;
use App\Warehouse\Http\Controllers\InventoryController;
use App\Warehouse\Http\Controllers\MaintenanceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - v1
|--------------------------------------------------------------------------
| Base URL   : http://localhost/api/v1
| Auth       : Bearer token (Sanctum personal access token)
| Multi-tenant: SetActiveCompany middleware auto-scopes all queries by company
*/

Route::prefix('v1')->group(function () {

    // Public platform fallback; tenant-specific branding requires Sanctum below.
    Route::get('/branding/default', [BrandingController::class, 'default']);

    if (config('features.vendor_tracking')) {
        Route::middleware([VendorTokenMiddleware::class])
            ->prefix('vendor/{token}')
            ->group(function () {
                Route::get('/orders', [PublicVendorProgressController::class, 'listOrders']);
            });
    }

    // ── Authentication (public) ───────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        // Login - 10 attempt/menit per IP. Selain ini ada RateLimiter::for('login')
        // di FortifyServiceProvider (5/menit per username+IP) untuk credential
        // stuffing. Dua lapis: IP throttle stop bot, username throttle stop
        // targeted brute force.
        // Batas per akun+IP & per IP: lihat RateLimiter 'api-login' di AppServiceProvider.
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:api-login');
        Route::post('/accept-invite', [AuthController::class, 'acceptInvite'])->middleware('throttle:5,1');
        // Tahap kedua login. Publik karena tiket tantangan ITU otorisasinya —
        // pemanggil belum punya token Sanctum. Throttle ketat: kode cuma 6 digit,
        // dan controller menambah pembatas per-IP lagi di dalamnya.
        Route::post('/two-factor-challenge', [TwoFactorController::class, 'challenge'])
            ->middleware('throttle:10,1');
        // Refresh endpoint - public (cuma butuh HttpOnly cookie). Throttle 30/menit
        // per IP cukup; race condition di SPA di-handle pakai singleton promise.
        Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1');

        Route::middleware(['auth:sanctum', 'block.inactive', 'tenant.required', 'module.routes'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);

            // Pengelolaan 2FA milik diri sendiri.
            Route::get('/two-factor', [TwoFactorController::class, 'status']);
            Route::post('/two-factor/enable', [TwoFactorController::class, 'enable']);
            Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm']);
            Route::delete('/two-factor', [TwoFactorController::class, 'disable']);
            Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::put('/me', [AuthController::class, 'updateMe']);
            Route::post('/me/photo', [AuthController::class, 'updatePhoto']);
            Route::get('/me/avatar-data', [AuthController::class, 'avatarData']);
            Route::post('/me/password', [AuthController::class, 'changePassword']);
            Route::post('/face-setup', [AuthController::class, 'faceSetup'])->middleware('throttle:6,10'); // 6x/10 menit per pengguna
            Route::get('/webmail-session', [AuthController::class, 'webmailSession']);
            Route::get('/mailbox-credentials', [AuthController::class, 'mailboxCredentials']);
            Route::get('/sso/platforms', [SsoController::class, 'platforms']);
            Route::post('/sso/generate', [SsoController::class, 'generate']);
        });

        // SSO exchange - attacker yang mau brute-force `sso_token` (80 char,
        // ~480 bit entropy, lifetime 60s) practically infeasible, tapi tetap
        // throttle untuk DDoS prevention + reduce surface area
        Route::post('/sso/exchange', [SsoController::class, 'exchange'])->middleware('throttle:20,1');
        Route::get('/sso/verify', [SsoController::class, 'verify'])->middleware('throttle:60,1');

        // Webmail SSO ticket exchange (Bearer-protected) + redirect (public)
        Route::middleware(['auth:sanctum', 'block.inactive', 'tenant.required'])->post('/webmail-ticket', [AuthController::class, 'webmailTicket']);
        // Mounted by the webmail reverse proxy under same-origin /sso/*.
        Route::post('/webmail-gateway/exchange', [WebmailGatewayController::class, 'exchange'])
            ->middleware('throttle:30,1');
        Route::get('/webmail-gateway/session', [WebmailGatewayController::class, 'session'])
            ->middleware('throttle:120,1');
        Route::post('/webmail-gateway/switch', [WebmailGatewayController::class, 'switchMailbox'])
            ->middleware('throttle:60,1');
        Route::post('/webmail-gateway/logout', [WebmailGatewayController::class, 'logout'])
            ->middleware('throttle:30,1');
        // Must only be reachable by nginx auth_request with the internal secret.
        Route::get('/webmail-gateway/authorize', [WebmailGatewayController::class, 'authorizeUpstream'])
            ->middleware('throttle:600,1');
        Route::get('/webmail-redirect', [AuthController::class, 'webmailRedirect']);
        // NEW: ticket exchange - webmail SPA call dari browser dengan ticket di query
        // Public (ticket IS auth, 60s expiry, single-use)
        Route::post('/exchange-webmail-ticket', [AuthController::class, 'exchangeWebmailTicket'])
            ->middleware('throttle:30,1');

        // Web Push subscription - frontend PWA → push notification untuk email baru
        Route::get('/push/vapid-public-key', [PushSubscriptionController::class, 'vapidPublicKey'])
            ->middleware('throttle:60,1');
        Route::middleware(['auth:sanctum', 'block.inactive', 'tenant.required'])->group(function () {
            Route::post('/push/subscribe', [PushSubscriptionController::class, 'subscribe']);
            Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'unsubscribe']);
        });

        // Direktori autocomplete recipient untuk compose drawer webmail
        Route::middleware(['auth:sanctum', 'block.inactive', 'tenant.required'])->get('/webmail-contacts', [AuthController::class, 'webmailContactsIndex']);
        // Sama, tapi auth via mailbox password (webmail login langsung tanpa portal)
        Route::post('/webmail-directory', [AuthController::class, 'webmailDirectoryByMailbox']);

        // Scheduled email queue (workaround Stalwart 1.0.6 belum support sendAt)
        Route::post('/schedule-email', [ScheduledEmailController::class, 'store']);
        Route::post('/scheduled-emails', [ScheduledEmailController::class, 'index']);
        Route::delete('/scheduled-emails/{id}', [ScheduledEmailController::class, 'destroy'])->whereNumber('id');

        // Shared mailboxes (mis. admin@ibereality.com) - superadmin one-click switch
        Route::middleware(['auth:sanctum', 'block.inactive', 'tenant.required'])->get('/shared-mailboxes', [AuthController::class, 'sharedMailboxesIndex']);
        Route::middleware(['auth:sanctum', 'block.inactive', 'tenant.required'])->post('/shared-mailbox-ticket', [AuthController::class, 'sharedMailboxTicket']);
        Route::get('/shared-mailbox-redirect', [AuthController::class, 'sharedMailboxRedirect']);

        // Email migration claim - karyawan picks @ibereality.com username (public)
        Route::get('/claim-email/{token}', [EmailMigrationController::class, 'showClaim']);
        Route::get('/claim-email/{token}/check', [EmailMigrationController::class, 'checkUsername']);
        Route::post('/claim-email', [EmailMigrationController::class, 'claim']);
    });

    // ── Hardware PUSH (device-authenticated via X-Device-Serial header) ──────
    // Outside Sanctum middleware - devices don't have user tokens; the
    // serial in attendance_devices table is the auth principal.
    Route::post('/hardware/push', [HardwareController::class, 'push'])
        ->middleware('throttle:60,1');

    // ── Public Customer Complaint (no auth) ──────────────────────────────────
    // Form di Web Profile IBE post kesini. Rate-limited utk anti-spam.
    Route::post('/public/complaints', [ComplaintController::class, 'publicStore'])
        ->middleware('throttle:10,60'); // 10 per hour per IP

    // ── Public Customer Survey Response (no auth, token-based) ───────────────
    Route::get('/public/surveys/{token}', [CustomerSurveyController::class, 'publicShow']);
    Route::post('/public/surveys/{token}', [CustomerSurveyController::class, 'publicRespond'])
        ->middleware('throttle:5,60');

    // ── Public Quote Accept/Reject (token-based, no auth) ────────────────────
    Route::get('/public/quote/{token}', [QuotationController::class, 'publicShow']);
    Route::post('/public/quote/{token}/accept', [QuotationController::class, 'publicAccept'])->middleware('throttle:5,60');
    Route::post('/public/quote/{token}/reject', [QuotationController::class, 'publicReject'])->middleware('throttle:5,60');

    // ── Wilayah Indonesia proxy (no auth, cached 7 hari) ─────────────────────
    // Dipakai AddressSelector di HRIS Admin + absen frontend. Backend proxy
    // karena emsifa GitHub Pages tidak set CORS headers.
    Route::get('/wilayah/provinces', [WilayahController::class, 'provinces']);
    Route::get('/wilayah/regencies/{provinceId}', [WilayahController::class, 'regencies']);
    Route::get('/wilayah/districts/{regencyId}', [WilayahController::class, 'districts']);
    Route::get('/wilayah/villages/{districtId}', [WilayahController::class, 'villages']);
    Route::get('/wilayah/kodepos', [WilayahController::class, 'kodepos']);

    // ── Identity Validators (NIK, NPWP) - algorithmic, no external dep ──────
    Route::get('/identity/nik', [IdentityController::class, 'nik']);
    Route::get('/identity/npwp', [IdentityController::class, 'npwp']);

    // ── Geocoding (OpenStreetMap Nominatim, free, cached 30 hari) ───────────
    Route::get('/geo/search', [GeoController::class, 'search']);
    Route::get('/geo/reverse', [GeoController::class, 'reverse']);

    // ── Kurs IDR vs major currencies (exchangerate.host, cached 6h) ─────────
    Route::get('/kurs', [KursController::class, 'index']);

    // ── Integration proxies (QR, countries, quote, github, password-pwned) ──
    Route::get('/qr', [IntegrationProxyController::class, 'qr']);
    Route::get('/countries', [IntegrationProxyController::class, 'countries']);
    Route::get('/quote', [IntegrationProxyController::class, 'quote']);
    Route::get('/github-releases', [IntegrationProxyController::class, 'githubReleases']);
    Route::post('/password-pwned', [IntegrationProxyController::class, 'passwordPwned']);

    // ── Public Web Form endpoints (NO auth - accessible from landing page) ─
    // Throttle: 30 reqs/menit per IP. Honeypot + rate-limit tambahan di service.
    // CORS terbuka via 'public.cors' middleware supaya embed dari domain manapun.
    // HARUS di luar auth:sanctum group supaya tidak redirect ke /login!
    Route::prefix('crm/public/forms')->middleware(['public.cors', 'throttle:30,1'])->group(function () {
        Route::get('/{token}', [PublicLeadFormController::class, 'show']);
        Route::post('/{token}/submit', [PublicLeadFormController::class, 'submit']);
        Route::options('/{token}', fn () => response('', 204));
        Route::options('/{token}/submit', fn () => response('', 204));
    });

    // Berhenti berlangganan kampanye email (tautan di kaki email + header
    // List-Unsubscribe). GET hanya konfirmasi; POST yang mencatat.
    Route::prefix('crm/public/unsubscribe')->middleware('throttle:30,1')->group(function () {
        Route::get('/{token}', [PublicUnsubscribeController::class, 'show']);
        Route::post('/{token}', [PublicUnsubscribeController::class, 'store']);
    });

    // ── Protected routes ──────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'block.inactive', 'tenant.required', 'module.routes'])->group(function () {

        Route::prefix('admin/webhooks')->middleware('admin')->group(function () {
            Route::get('/', [WebhookController::class, 'index']);
            Route::post('/', [WebhookController::class, 'store'])->middleware('idempotency');
            Route::patch('/{endpoint}', [WebhookController::class, 'update']);
            Route::delete('/{endpoint}', [WebhookController::class, 'destroy']);
            Route::post('/{endpoint}/test', [WebhookController::class, 'test'])->middleware(['throttle:10,1', 'idempotency']);
            Route::get('/deliveries/log', [WebhookController::class, 'deliveries']);
            Route::post('/deliveries/{delivery}/retry', [WebhookController::class, 'retry'])->middleware(['throttle:20,1', 'idempotency']);
        });

        Route::get('/company/branding', [BrandingController::class, 'show']);
        Route::patch('/company/branding', [BrandingController::class, 'update']);
        Route::post('/company/branding/logo', [BrandingController::class, 'logo']);
        Route::post('/company/branding/logo-dark', [BrandingController::class, 'logoDark']);
        Route::post('/company/branding/favicon', [BrandingController::class, 'favicon']);
        if (config('features.vendor_tracking')) {
            Route::get('/vendor-work-orders', [WorkOrderController::class, 'index'])
                ->middleware('module:production');
        }

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'employee']);
        require __DIR__.'/api/erp_protected.php';

        // In-app Notifications (Phase 10.3) - semua user bisa akses, scope by user_id + audience_role
        Route::get('/notifications', [AppNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [AppNotificationController::class, 'unreadCount']);
        Route::post('/notifications/{id}/read', [AppNotificationController::class, 'markRead']);
        Route::post('/notifications/mark-all-read', [AppNotificationController::class, 'markAllRead']);
        Route::post('/notifications/generate', [AppNotificationController::class, 'generate']);

        // Leave - karyawan tetap saja
        Route::middleware('permanent')->group(function () {
            Route::get('/leaves', [LeaveController::class, 'index']);
            Route::post('/leaves', [LeaveController::class, 'store']);
            Route::delete('/leaves/{id}', [LeaveController::class, 'destroy']);
        });

        // Overtime - karyawan tetap saja
        Route::middleware('permanent')->group(function () {
            Route::get('/overtimes', [OvertimeController::class, 'index']);
            Route::post('/overtimes', [OvertimeController::class, 'store']);
            Route::delete('/overtimes/{id}', [OvertimeController::class, 'destroy']);
        });

        // Attendance
        Route::get('/attendances', [AttendanceController::class, 'index']);
        Route::get('/attendances/calendar', [AttendanceController::class, 'calendar']);
        Route::get('/attendances/today', [AttendanceController::class, 'today']);
        Route::get('/attendances/{id}', [AttendanceController::class, 'show']);
        Route::post('/attendances/clock-in', [AttendanceController::class, 'clockIn'])->middleware('throttle:15,1'); // batasi percobaan pencocokan wajah
        Route::post('/attendances/clock-out', [AttendanceController::class, 'clockOut'])->middleware('throttle:15,1');
        Route::get('/office-locations', [AttendanceController::class, 'officeLocations']);
        Route::get('/holidays', [HolidayController::class, 'index']);

        // Kontrak kerja karyawan
        Route::get('/me/contract', [ContractController::class, 'myPending']);
        Route::get('/me/contract/{id}/preview', [ContractController::class, 'preview']);
        Route::post('/me/contract/{id}/sign', [ContractController::class, 'sign']);
        Route::get('/me/contract/{id}/download', [ContractController::class, 'downloadMine']);

        Route::middleware('admin')->group(function () {
            // Contract templates - admin manage per employment_type
            Route::get('/admin/contract-templates', [ContractTemplateController::class, 'index']);
            Route::get('/admin/contract-templates/{type}', [ContractTemplateController::class, 'show']);
            Route::post('/admin/contract-templates', [ContractTemplateController::class, 'store']);
            Route::post('/admin/contract-templates/upload-docx', [ContractTemplateController::class, 'uploadDocx']);
            Route::get('/admin/contract-templates/{type}/preview', [ContractTemplateController::class, 'preview']);
            Route::get('/admin/contract-templates/{type}/preview-pdf', [ContractTemplateController::class, 'previewPdf']);
            Route::delete('/admin/contract-templates/{type}', [ContractTemplateController::class, 'destroy']);
            Route::get('/admin/contract-templates/{type}/versions', [ContractTemplateController::class, 'versions']);
            Route::get('/admin/contract-templates/{type}/versions/{id}', [ContractTemplateController::class, 'versionDetail'])->whereNumber('id');
            Route::post('/admin/contract-templates/{type}/restore/{id}', [ContractTemplateController::class, 'restoreVersion'])->whereNumber('id');

            // Contract Lifecycle - multi-signer workflow (HRD generate → paraf → karyawan sign → direktur sign → materai)
            Route::get('/admin/contracts', [ContractLifecycleController::class, 'adminIndex']);
            Route::post('/admin/contracts/generate', [ContractLifecycleController::class, 'generate']);
            Route::patch('/admin/contracts/{id}/paraf', [ContractLifecycleController::class, 'paraf']);
            Route::post('/admin/contracts/{id}/send-karyawan', [ContractLifecycleController::class, 'sendToKaryawan']);
            Route::post('/admin/contracts/{id}/materai-upload', [ContractLifecycleController::class, 'uploadMaterai']);
            Route::post('/admin/contracts/{id}/materai-skip', [ContractLifecycleController::class, 'skipMaterai']);
            Route::post('/admin/contracts/{id}/reject', [ContractLifecycleController::class, 'reject']);
            Route::delete('/admin/contracts/{id}', [ContractLifecycleController::class, 'destroy']);
            Route::post('/admin/contracts/bulk-delete', [ContractLifecycleController::class, 'bulkDelete']);
            Route::post('/admin/contracts/bulk-reject', [ContractLifecycleController::class, 'bulkReject']);
            Route::get('/admin/contracts/export.csv', [ContractLifecycleController::class, 'exportCsv']);
            Route::get('/admin/contracts/expiring', [ContractLifecycleController::class, 'expiring']);
            Route::get('/admin/contracts/reminder-log', [ContractLifecycleController::class, 'reminderLog']);
            Route::post('/admin/mail-test', [MailTestController::class, 'send']);
        });
        Route::get('/me/contracts', [ContractLifecycleController::class, 'myContracts']);
        Route::post('/me/contracts/{id}/sign', [ContractLifecycleController::class, 'signAsKaryawan']);
        Route::get('/direktur/contracts/pending-sign', [ContractLifecycleController::class, 'pendingSignDirektur']);
        Route::post('/direktur/contracts/{id}/sign', [ContractLifecycleController::class, 'signAsDirektur']);
        Route::get('/contracts/{id}/preview', [ContractLifecycleController::class, 'preview']);
        Route::get('/contracts/{id}/assets/{asset}', [ContractAssetController::class, 'show'])
            ->whereIn('asset', ['employee-signature', 'hrd-paraf-signature', 'director-signature', 'signed-pdf', 'materai-pdf']);

        // Executive summary - direktur beranda + ringkasan
        Route::get('/me/executive-summary', [ExecutiveSummaryController::class, 'summary']);
        Route::get('/direktur/ringkasan-perusahaan', [ExecutiveSummaryController::class, 'ringkasanPerusahaan']);

        // Approval Requests - Persetujuan Eksekutif Direktur
        Route::middleware('admin')->group(function () {
            Route::get('/admin/approvals', [ApprovalRequestController::class, 'index']);
            Route::post('/admin/approvals', [ApprovalRequestController::class, 'store']);
        });
        Route::get('/direktur/approvals/pending', [ApprovalRequestController::class, 'pendingForDirektur']);
        Route::post('/direktur/approvals/{id}/approve', [ApprovalRequestController::class, 'approve']);
        Route::post('/direktur/approvals/{id}/reject', [ApprovalRequestController::class, 'reject']);

        // Multi-role - self + admin management
        Route::get('/me/available-roles',
            [UserRoleController::class, 'selfAvailableRoles']);
        Route::middleware('admin')->group(function () {
            Route::get('/admin/user-accounts',
                [UserRoleController::class, 'listAdminAccounts']);
            Route::get('/admin/users/{id}/roles',
                [UserRoleController::class, 'listForUser']);
            Route::post('/admin/users/{id}/roles',
                [UserRoleController::class, 'assign']);
            Route::delete('/admin/users/{id}/roles/{role}',
                [UserRoleController::class, 'revoke']);
        });

        // Surat Keterangan Kerja - karyawan self-issue + list + download
        Route::post('/me/work-certificate', [WorkCertificateController::class, 'selfIssue']);
        Route::get('/me/work-certificate', [WorkCertificateController::class, 'selfList']);
        Route::get('/me/work-certificate/{id}/download', [WorkCertificateController::class, 'selfDownload']);

        // Admin issue + download (bisa untuk karyawan lain)
        Route::middleware('admin')->group(function () {
            Route::post('/admin/work-certificates', [WorkCertificateController::class, 'adminIssue']);
            Route::post('/admin/work-certificates/preview', [WorkCertificateController::class, 'adminPreview']);
            Route::get('/admin/work-certificates/{id}/download', [WorkCertificateController::class, 'adminDownload']);
        });

        // Broadcast inbox - karyawan view pengumuman direksi/HR
        Route::get('/me/broadcasts', [BroadcastController::class, 'myInbox']);
        Route::post('/me/broadcasts/read-all', [BroadcastController::class, 'markAllRead']);
        Route::post('/me/broadcasts/{id}/read', [BroadcastController::class, 'markRead']);

        // Payroll / Slip Gaji - karyawan tetap saja
        Route::middleware('permanent')->group(function () {
            Route::get('/payrolls', [PayrollController::class, 'selfIndex']);
            Route::get('/payrolls/{id}', [PayrollController::class, 'selfShow']);
            Route::get('/payrolls/{id}/slip', [PayrollController::class, 'selfDownloadSlip']);
            Route::get('/payrolls/{id}/transfer-proof', [PayrollController::class, 'downloadTransferProof']);
        });

        // Salary Advance / Kasbon - karyawan tetap saja
        Route::middleware('permanent')->group(function () {
            Route::get('/salary-advances', [SalaryAdvanceController::class, 'index']);
            Route::post('/salary-advances', [SalaryAdvanceController::class, 'store']);
            Route::delete('/salary-advances/{id}', [SalaryAdvanceController::class, 'destroy']);
        });

        // Reimbursement
        Route::get('/reimbursements', [ReimbursementController::class, 'index']);
        Route::post('/reimbursements', [ReimbursementController::class, 'store']);
        Route::delete('/reimbursements/{id}', [ReimbursementController::class, 'destroy']);
        Route::get('/reimbursements/{id}/receipt', [ReimbursementController::class, 'myReceipt']);

        // Business Trip (Perjalanan Dinas) - karyawan submit + lihat status
        Route::middleware('permanent')->group(function () {
            Route::get('/business-trips', [BusinessTripController::class, 'myIndex']);
            Route::get('/business-trips/shifts', [BusinessTripController::class, 'myShifts']);
            Route::post('/business-trips', [BusinessTripController::class, 'myStore']);
            Route::delete('/business-trips/{id}', [BusinessTripController::class, 'myDestroy'])->whereNumber('id');
        });

        // Client Visit (Kunjungan Klien) - karyawan check-in/out kunjungan
        Route::get('/client-visits', [ClientVisitController::class, 'myIndex']);
        Route::post('/client-visits/in', [ClientVisitController::class, 'myVisitIn']);
        Route::post('/client-visits/{id}/out', [ClientVisitController::class, 'myVisitOut'])->whereNumber('id');

        require __DIR__.'/api/projects.php';

        // ── Admin ──────────────────────────────────────────────────────────────
        Route::prefix('admin')->middleware('admin')->group(function () {

            require __DIR__.'/api/erp_admin.php';

            // Leave
            Route::get('/leaves', [LeaveController::class, 'adminIndex']);
            Route::get('/leaves/balance-report', [LeaveController::class, 'balanceReport']);
            Route::get('/leaves/{id}', [LeaveController::class, 'adminShow'])->whereNumber('id');
            Route::post('/leaves/{id}/approve', [LeaveController::class, 'approve']);
            Route::post('/leaves/{id}/reject', [LeaveController::class, 'reject']);
            Route::post('/leaves/{id}/cancel', [LeaveController::class, 'cancelApproved']);

            // Overtime
            Route::get('/overtimes', [OvertimeController::class, 'adminIndex']);
            Route::get('/overtimes/{id}', [OvertimeController::class, 'adminShow']);
            Route::post('/overtimes/{id}/approve', [OvertimeController::class, 'approve']);
            Route::post('/overtimes/{id}/reject', [OvertimeController::class, 'reject']);

            // Attendance
            Route::get('/attendances', [AttendanceController::class, 'adminIndex']);
            Route::get('/attendances/export', [AttendanceController::class, 'adminExport']);
            Route::get('/attendances/summary-report', [AttendanceController::class, 'summaryReport']);
            Route::get('/users/{user}/attendances', [AttendanceController::class, 'adminUserHistory']);
            Route::get('/attendances/{id}', [AttendanceController::class, 'adminShow'])->whereNumber('id');
            Route::post('/attendances/manual', [AttendanceController::class, 'adminManualEntry']);
            Route::patch('/attendances/{id}/manual', [AttendanceController::class, 'adminManualEdit'])->whereNumber('id');
            Route::get('/shift-schedules/references', [ShiftScheduleController::class, 'references']);
            Route::get('/shift-schedules/auto-shifts', [ShiftScheduleController::class, 'autoShifts']);
            Route::post('/shift-schedules/auto-shifts', [ShiftScheduleController::class, 'saveAutoShift']);
            Route::delete('/shift-schedules/auto-shifts/{id}', [ShiftScheduleController::class, 'deleteAutoShift']);
            Route::get('/shift-schedules/mappings', [ShiftScheduleController::class, 'mappings']);
            Route::post('/shift-schedules/mappings', [ShiftScheduleController::class, 'saveMapping']);
            Route::delete('/shift-schedules/mappings/{id}', [ShiftScheduleController::class, 'deleteMapping']);

            // Employees
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::post('/employees', [EmployeeController::class, 'store']);
            Route::get('/employees/{id}', [EmployeeController::class, 'show']);
            Route::put('/employees/{id}', [EmployeeController::class, 'update']);
            Route::post('/employees/{id}/regenerate-invite', [EmployeeController::class, 'regenerateInvite']);
            // Off-boarding: tandai resign / reactivate - data lama tetap, akses dimatikan
            Route::post('/employees/{id}/resign', [EmployeeController::class, 'resign']);
            Route::post('/employees/{id}/reactivate', [EmployeeController::class, 'reactivate']);

            // Salary Advance
            Route::get('/salary-advances', [SalaryAdvanceController::class, 'adminIndex']);
            Route::get('/salary-advances/{id}', [SalaryAdvanceController::class, 'adminShow']);
            Route::post('/salary-advances/{id}/approve', [SalaryAdvanceController::class, 'approve']);
            Route::post('/salary-advances/{id}/reject', [SalaryAdvanceController::class, 'reject']);
            Route::post('/salary-advances/{id}/settle', [SalaryAdvanceController::class, 'settle'])->middleware('idempotency');
            // Hapus pengajuan - dibatasi Finance (payroll.manage) di controller.
            Route::delete('/salary-advances/{id}', [SalaryAdvanceController::class, 'adminDestroy']);

            // Rekening koran (bank statement) - unggahan bagian keuangan.
            // Berkas disimpan di disk non-publik; unduhan wajib lewat endpoint ini.
            Route::get('/bank-statements', [BankStatementController::class, 'index']);
            Route::post('/bank-statements', [BankStatementController::class, 'store']);
            Route::get('/bank-statements/{id}/download', [BankStatementController::class, 'download']);
            Route::delete('/bank-statements/{id}', [BankStatementController::class, 'destroy']);

            // Reimbursement
            Route::get('/reimbursements', [ReimbursementController::class, 'adminIndex']);
            Route::get('/reimbursement-categories', [ReimbursementController::class, 'adminCategories']);
            Route::get('/reimbursements/{id}', [ReimbursementController::class, 'adminShow']);
            Route::post('/reimbursements/{id}/approve', [ReimbursementController::class, 'approve'])->middleware('idempotency');
            Route::post('/reimbursements/{id}/reject', [ReimbursementController::class, 'reject'])->middleware('idempotency');
            Route::post('/reimbursements/{id}/settle', [ReimbursementController::class, 'settle'])->middleware('idempotency');
            // Hapus pengajuan — dibatasi Finance (payroll.manage) di controller.
            Route::delete('/reimbursements/{id}', [ReimbursementController::class, 'adminDestroy']);
            Route::get('/reimbursements/{id}/receipt', [ReimbursementController::class, 'receipt']);

            // Payroll
            Route::get('/payrolls', [PayrollController::class, 'index']);
            Route::get('/payrolls/export', [PayrollController::class, 'export']);
            Route::post('/payrolls', [PayrollController::class, 'store']);
            Route::post('/payrolls/bulk-generate', [PayrollController::class, 'bulkGenerate']);
            Route::post('/payrolls/submit-bulk', [PayrollController::class, 'submitBulk'])->middleware('idempotency');
            Route::post('/payrolls/{id}/submit', [PayrollController::class, 'submit'])->middleware('idempotency');
            Route::post('/payrolls/{id}/approve', [PayrollController::class, 'approve'])->middleware('idempotency');
            Route::post('/payrolls/{id}/mark-paid', [PayrollController::class, 'markPaid'])->middleware('idempotency');
            Route::get('/payrolls/{id}/slip', [PayrollController::class, 'downloadSlip']);
            Route::get('/payrolls/{id}/transfer-proof', [PayrollController::class, 'downloadTransferProof']);
            Route::post('/payrolls/{id}/send-slip-email', [PayrollController::class, 'sendSlipEmail']);

            // Company Settings
            Route::get('/company', [CompanyController::class, 'show']);
            Route::post('/company', [CompanyController::class, 'update']);
            Route::get('/company/assets/{asset}', [CompanyController::class, 'asset'])
                ->whereIn('asset', ['director-signature', 'hrd-signature', 'finance-signature', 'stamp']);

            // Master Data
            Route::get('/master-data/divisions', [DivisionController::class, 'index']);
            Route::post('/master-data/divisions', [DivisionController::class, 'store']);
            Route::put('/master-data/divisions/{id}', [DivisionController::class, 'update']);
            Route::delete('/master-data/divisions/{id}', [DivisionController::class, 'destroy']);
            Route::get('/master-data/job-titles', [JobTitleController::class, 'index']);
            Route::post('/master-data/job-titles', [JobTitleController::class, 'store']);
            Route::put('/master-data/job-titles/{id}', [JobTitleController::class, 'update']);
            Route::delete('/master-data/job-titles/{id}', [JobTitleController::class, 'destroy']);
            Route::get('/master-data/educations', [EducationController::class, 'index']);
            Route::post('/master-data/educations', [EducationController::class, 'store']);
            Route::put('/master-data/educations/{id}', [EducationController::class, 'update']);
            Route::delete('/master-data/educations/{id}', [EducationController::class, 'destroy']);
            // Tier potongan terlambat (bertingkat)
            Route::get('/late-tiers', [LateDeductionTierController::class, 'indexCompany']);
            Route::post('/late-tiers/bulk', [LateDeductionTierController::class, 'bulkCompany']);
            Route::get('/users/{userId}/late-tiers', [LateDeductionTierController::class, 'indexUser']);
            Route::post('/users/{userId}/late-tiers/bulk', [LateDeductionTierController::class, 'bulkUser']);
            Route::delete('/users/{userId}/late-tiers', [LateDeductionTierController::class, 'clearUser']);

            Route::get('/master-data/shifts', [ShiftController::class, 'index']);
            Route::post('/master-data/shifts', [ShiftController::class, 'store']);
            Route::put('/master-data/shifts/{id}', [ShiftController::class, 'update']);
            Route::delete('/master-data/shifts/{id}', [ShiftController::class, 'destroy']);
            Route::get('/master-data/inventories', [InventoryController::class, 'index']);
            Route::post('/master-data/inventories', [InventoryController::class, 'store']);
            Route::put('/master-data/inventories/{id}', [InventoryController::class, 'update']);

            // Face Approval
            Route::get('/face-approvals', [FaceApprovalController::class, 'index']);
            Route::post('/face-approvals/{id}/approve', [FaceApprovalController::class, 'approve']);
            Route::post('/face-approvals/{id}/reject', [FaceApprovalController::class, 'reject']);

            // Audit Logs
            Route::get('/audit-logs', [AuditLogController::class, 'index']);

            // Admin Inbox (notification bell feed + pending approval counts)
            Route::get('/inbox', [InboxController::class, 'show']);

            // Email Migration Broadcast - kirim link aktivasi @ibereality.com ke karyawan
            Route::get('/email-broadcasts', [EmailMigrationController::class, 'status']);
            Route::post('/email-broadcasts', [EmailMigrationController::class, 'broadcast']);
            Route::post('/email-broadcasts/{userId}/resend', [EmailMigrationController::class, 'resendOne']);
            Route::post('/email-broadcasts/{userId}/rollback', [EmailMigrationController::class, 'rollback']);

            // Stalwart Mailbox Manager - replace backdoor mail.ibereality.com admin UI
            Route::get('/mailboxes', [MailboxController::class, 'index']);
            Route::post('/mailboxes', [MailboxController::class, 'store']);
            Route::post('/mailboxes/{accountId}/rotate-password', [MailboxController::class, 'rotatePassword']);
            Route::delete('/mailboxes/{accountId}', [MailboxController::class, 'destroy']);

            // Broadcast Pengumuman - direksi/HR kirim pengumuman ke karyawan (audience grouping)
            Route::get('/broadcasts', [BroadcastController::class, 'adminIndex']);
            Route::get('/broadcasts/audience-options', [BroadcastController::class, 'audienceOptions']);
            Route::post('/broadcasts/preview', [BroadcastController::class, 'preview']);
            Route::post('/broadcasts', [BroadcastController::class, 'store']);
            Route::get('/broadcasts/{id}', [BroadcastController::class, 'adminShow']);

            // Production: Serial Number generator + management
            Route::get('/serial-numbers', [SerialNumberController::class, 'index']);
            Route::post('/serial-numbers', [SerialNumberController::class, 'store']);
            Route::put('/serial-numbers/{id}', [SerialNumberController::class, 'update']);
            Route::get('/serial-numbers/lookup/{serial}', [SerialNumberController::class, 'lookup']);
            Route::get('/serial-numbers/{id}/nameplate', [NameplatePdfController::class, 'show']);

            // ── Produksi: Product Master + Spec Template ─────────────────────
            Route::get('/products', [ProductController::class, 'index']);
            Route::post('/products', [ProductController::class, 'store']);
            Route::get('/products/{id}', [ProductController::class, 'show']);
            Route::put('/products/{id}', [ProductController::class, 'update']);
            Route::delete('/products/{id}', [ProductController::class, 'destroy']);
            Route::put('/products/{id}/spec-fields', [ProductController::class, 'updateSpecFields']);

            // ── Inventory Management (Stock + Warehouses + Movements) ───────
            // Pemisahan namespace App\Production untuk modul manufacturing-related
            // (distinct dari App\Warehouse\Services\InventoryService yang track laptop/peralatan kantor)
            Route::get('/warehouses', [WarehouseController::class, 'index']);
            Route::post('/warehouses', [WarehouseController::class, 'store']);
            Route::put('/warehouses/{id}', [WarehouseController::class, 'update']);
            Route::delete('/warehouses/{id}', [WarehouseController::class, 'destroy']);

            Route::get('/inventory/products', [StockController::class, 'products']);
            Route::get('/inventory/stock', [StockController::class, 'index']);
            Route::post('/inventory/move', [StockController::class, 'move'])->middleware('idempotency');
            Route::post('/inventory/transfer', [StockController::class, 'transfer'])->middleware('idempotency');
            Route::post('/inventory/adjust', [StockController::class, 'adjust'])->middleware('idempotency');
            Route::get('/inventory/movements', [StockController::class, 'movements']);
            Route::get('/inventory/reorder-alerts', [StockController::class, 'reorderAlerts']);

            // ── BOM (Bill of Materials) ────────────────────────────────────
            Route::get('/boms', [BomController::class, 'index']);
            Route::post('/boms', [BomController::class, 'store']);
            Route::get('/boms/{id}', [BomController::class, 'show']);
            Route::put('/boms/{id}', [BomController::class, 'update']);
            Route::delete('/boms/{id}', [BomController::class, 'destroy']);
            Route::put('/boms/{id}/components', [BomController::class, 'replaceComponents']);
            Route::post('/boms/{id}/activate', [BomController::class, 'activate']);
            Route::post('/boms/{id}/can-produce', [BomController::class, 'canProduce']);

            // ── BOM explosion + deep feasibility (multi-level) ────────────
            Route::post('/boms/{bom}/explode', [MrpController::class, 'explode']);
            Route::get('/boms/{bom}/can-produce-deep', [MrpController::class, 'canProduceDeep']);

            // ── MRP / Planning ─────────────────────────────────────────────
            Route::get('/inventory/alerts', [MrpController::class, 'alerts']);
            Route::get('/mrp/suggestions', [MrpController::class, 'mrp']);

            // ── Lot/Batch tracking ─────────────────────────────────────────
            Route::get('/batches', [BatchController::class, 'index']);
            Route::post('/batches/receive', [BatchController::class, 'receive'])->middleware('idempotency');
            Route::get('/batches/expiring', [BatchController::class, 'expiring']);
            Route::get('/batches/{batch}', [BatchController::class, 'show']);
            Route::get('/batches/{batch}/trace', [BatchController::class, 'trace']);

            // ── Stock Count / Cycle Counting ───────────────────────────────
            Route::get('/stock-counts', [StockCountController::class, 'index']);
            Route::post('/stock-counts', [StockCountController::class, 'store']);
            Route::get('/stock-counts/{stockCount}', [StockCountController::class, 'show']);
            Route::post('/stock-counts/{stockCount}/start', [StockCountController::class, 'start']);
            Route::put('/stock-count-lines/{line}/count', [StockCountController::class, 'recordCount']);
            Route::post('/stock-counts/{stockCount}/post', [StockCountController::class, 'post'])->middleware('idempotency');
            Route::post('/stock-counts/{stockCount}/cancel', [StockCountController::class, 'cancel']);

            // ── Purchase Orders ────────────────────────────────────────────
            Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
            Route::put('/purchase-orders/{id}', [PurchaseOrderController::class, 'update']);
            Route::put('/purchase-orders/{id}/lines', [PurchaseOrderController::class, 'replaceLines']);
            Route::post('/purchase-orders/{id}/approve', [PurchaseOrderController::class, 'approve']);
            Route::post('/purchase-orders/{id}/send', [PurchaseOrderController::class, 'send']);
            Route::post('/purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->middleware('idempotency');
            Route::post('/purchase-orders/{id}/close', [PurchaseOrderController::class, 'close']);
            Route::post('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
            Route::post('/purchase-orders/{id}/match-bill', [PurchaseOrderController::class, 'matchBill']);

            // ── Surat Menyurat (Letter Management) ─────────────────────────
            Route::get('/users-light', function (Request $r) {
                return ['data' => User::query()
                    ->where('company_id', $r->user()->company_id)
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'group'])];
            });
            Route::get('/letters', [LetterController::class, 'index'])
                ->middleware('enterprise.capability:documents.letter.view,admin.access');
            Route::get('/letters-registry', [LetterController::class, 'registry'])
                ->middleware('enterprise.capability:documents.letter.view,admin.access');
            Route::get('/letters-export-csv', [LetterController::class, 'exportCsv'])
                ->middleware('enterprise.capability:documents.letter.view,admin.access');
            Route::get('/letters-analytics/dashboard', [LetterAnalyticsController::class, 'dashboard'])
                ->middleware('enterprise.capability:documents.analytics.view,admin.access');
            Route::get('/letters-analytics/sequence-gaps', [LetterAnalyticsController::class, 'sequenceGaps'])
                ->middleware('enterprise.capability:documents.analytics.view,admin.access');
            Route::post('/letters/{publicId}/reply', [LetterController::class, 'reply'])
                ->middleware('enterprise.capability:documents.letter.create,admin.access');
            // Lampiran
            Route::get('/letters/{publicId}/attachments', [LetterAttachmentController::class, 'index'])
                ->middleware('enterprise.capability:documents.letter.view,admin.access');
            Route::post('/letters/{publicId}/attachments', [LetterAttachmentController::class, 'store'])
                ->middleware('enterprise.capability:documents.letter.manage,admin.access');
            Route::delete('/letters/{publicId}/attachments/{attId}', [LetterAttachmentController::class, 'destroy'])
                ->middleware('enterprise.capability:documents.letter.manage,admin.access');
            Route::get('/letters/{publicId}/attachments/{attId}/download', [LetterAttachmentController::class, 'download'])
                ->middleware('enterprise.capability:documents.letter.view,admin.access');
            // Disposisi
            Route::get('/letters/{publicId}/dispositions', [LetterDispositionController::class, 'index'])
                ->middleware('enterprise.capability:documents.letter.view,admin.access');
            Route::post('/letters/{publicId}/dispositions', [LetterDispositionController::class, 'store'])
                ->middleware('enterprise.capability:documents.letter.manage,admin.access');
            Route::post('/letters/{publicId}/dispositions/{dispId}/complete', [LetterDispositionController::class, 'complete'])
                ->middleware('enterprise.capability:documents.letter.manage,admin.access');
            Route::delete('/letters/{publicId}/dispositions/{dispId}', [LetterDispositionController::class, 'destroy'])
                ->middleware('enterprise.capability:documents.letter.manage,admin.access');
            Route::post('/letters', [LetterController::class, 'store'])
                ->middleware('enterprise.capability:documents.letter.create,admin.access');
            Route::get('/letters/preview-number', [LetterController::class, 'previewNumber'])
                ->middleware('enterprise.capability:documents.letter.create,admin.access');
            Route::get('/letters/{publicId}', [LetterController::class, 'show'])
                ->middleware('enterprise.capability:documents.letter.view,admin.access');
            Route::put('/letters/{publicId}', [LetterController::class, 'update'])
                ->middleware('enterprise.capability:documents.letter.manage,admin.access');
            Route::delete('/letters/{publicId}', [LetterController::class, 'destroy'])
                ->middleware('enterprise.capability:documents.letter.manage,admin.access');
            Route::post('/letters/{publicId}/submit', [LetterController::class, 'submit'])
                ->middleware('enterprise.capability:documents.letter.manage,admin.access');
            Route::post('/letters/{publicId}/revise', [LetterController::class, 'requestRevision'])
                ->middleware('enterprise.capability:documents.letter.approve,admin.access');
            Route::post('/letters/{publicId}/approve', [LetterController::class, 'approve'])
                ->middleware('enterprise.capability:documents.letter.approve,admin.access');
            Route::post('/letters/{publicId}/sign', [LetterController::class, 'sign'])
                ->middleware('enterprise.capability:documents.letter.sign,admin.access');
            Route::post('/letters/{publicId}/send', [LetterController::class, 'send'])
                ->middleware('enterprise.capability:documents.letter.send,admin.access');
            Route::post('/letters/{publicId}/archive', [LetterController::class, 'archive'])
                ->middleware('enterprise.capability:documents.letter.manage,admin.access');
            Route::post('/letters/{publicId}/cancel', [LetterController::class, 'cancel'])
                ->middleware('enterprise.capability:documents.letter.manage,admin.access');
            Route::get('/letters/{publicId}/preview-pdf', [LetterController::class, 'previewPdf'])
                ->middleware('enterprise.capability:documents.letter.view,admin.access');
            Route::get('/letters/{publicId}/download-pdf', [LetterController::class, 'downloadPdf'])
                ->middleware('enterprise.capability:documents.letter.view,admin.access');
            Route::get('/letters/{publicId}/revisions', [LetterController::class, 'revisions'])
                ->middleware('enterprise.capability:documents.letter.view,admin.access');

            Route::get('/letter-categories', [LetterCategoryController::class, 'index'])
                ->middleware('enterprise.capability:documents.category.view,admin.access');
            Route::put('/letter-categories/{id}', [LetterCategoryController::class, 'update'])
                ->middleware('enterprise.capability:documents.category.manage,admin.access');

            Route::get('/letter-templates', [LetterTemplateController::class, 'index'])
                ->middleware('enterprise.capability:documents.template.view,admin.access');
            Route::post('/letter-templates', [LetterTemplateController::class, 'store'])
                ->middleware('enterprise.capability:documents.template.manage,admin.access');
            Route::get('/letter-templates/{id}', [LetterTemplateController::class, 'show'])
                ->middleware('enterprise.capability:documents.template.view,admin.access');
            Route::put('/letter-templates/{id}', [LetterTemplateController::class, 'update'])
                ->middleware('enterprise.capability:documents.template.manage,admin.access');
            Route::delete('/letter-templates/{id}', [LetterTemplateController::class, 'destroy'])
                ->middleware('enterprise.capability:documents.template.manage,admin.access');

            Route::get('/letter-signatures', [LetterSignatureController::class, 'index'])
                ->middleware('enterprise.capability:documents.signature.view,admin.access');
            Route::get('/letter-signatures/{id}/assets/{asset}', [LetterSignatureController::class, 'asset'])
                ->whereNumber('id')
                ->whereIn('asset', ['signature', 'stamp'])
                ->middleware('enterprise.capability:documents.signature.view,admin.access');
            Route::post('/letter-signatures', [LetterSignatureController::class, 'store'])
                ->middleware('enterprise.capability:documents.signature.prepare,admin.access');
            Route::put('/letter-signatures/{id}', [LetterSignatureController::class, 'update'])
                ->middleware('enterprise.capability:documents.signature.prepare,admin.access');
            Route::post('/letter-signatures/{id}/approve', [LetterSignatureController::class, 'approve'])
                ->middleware('enterprise.capability:documents.signature.approve,admin.access');
            Route::delete('/letter-signatures/{id}', [LetterSignatureController::class, 'destroy'])
                ->middleware('enterprise.capability:documents.signature.approve,admin.access');

            Route::get('/letter-settings', [LetterSettingController::class, 'show'])
                ->middleware('enterprise.capability:documents.settings.view,admin.access');
            Route::post('/letter-settings', [LetterSettingController::class, 'update'])
                ->middleware('enterprise.capability:documents.settings.manage,admin.access');

            Route::get('/company-banks', [CompanyBankController::class, 'index'])
                ->middleware('enterprise.capability:finance.company_bank.view,admin.access');
            Route::post('/company-banks', [CompanyBankController::class, 'store'])
                ->middleware('enterprise.capability:finance.company_bank.manage,admin.access');
            Route::put('/company-banks/{id}', [CompanyBankController::class, 'update'])
                ->middleware('enterprise.capability:finance.company_bank.manage,admin.access');
            Route::delete('/company-banks/{id}', [CompanyBankController::class, 'destroy'])
                ->middleware('enterprise.capability:finance.company_bank.manage,admin.access');

            // ── Manufacturing Work Orders ──────────────────────────────────
            Route::get('/work-orders', [App\Production\Http\Controllers\WorkOrderController::class, 'index']);
            Route::post('/work-orders', [App\Production\Http\Controllers\WorkOrderController::class, 'store']);
            Route::get('/work-orders/{id}', [App\Production\Http\Controllers\WorkOrderController::class, 'show']);
            Route::post('/work-orders/{id}/release', [App\Production\Http\Controllers\WorkOrderController::class, 'release'])->middleware('idempotency');
            Route::post('/work-orders/{id}/complete', [App\Production\Http\Controllers\WorkOrderController::class, 'complete'])->middleware('idempotency');
            Route::post('/work-orders/{id}/cancel', [App\Production\Http\Controllers\WorkOrderController::class, 'cancel'])->middleware('idempotency');

            // ── Multi-Currency ─────────────────────────────────────────────
            Route::get('/currencies', [CurrencyController::class, 'index']);
            Route::get('/exchange-rates', [CurrencyController::class, 'listRates']);
            Route::post('/exchange-rates', [CurrencyController::class, 'storeRate']);
            Route::get('/exchange-rates/lookup', [CurrencyController::class, 'lookup']);

            // ── Fixed Asset Register (internal) ────────────────────────────
            Route::get('/fixed-assets', [FixedAssetController::class, 'index']);
            Route::post('/fixed-assets', [FixedAssetController::class, 'store']);
            Route::get('/fixed-assets/{id}', [FixedAssetController::class, 'show']);
            Route::put('/fixed-assets/{id}', [FixedAssetController::class, 'update']);
            Route::delete('/fixed-assets/{id}', [FixedAssetController::class, 'destroy']);
            Route::post('/fixed-assets/{id}/dispose', [FixedAssetController::class, 'dispose']);
            Route::post('/fixed-assets/post-depreciation', [FixedAssetController::class, 'postDepreciation']);

            // Production: Customer Complaints (web_form + manual entry)
            Route::get('/complaints', [ComplaintController::class, 'adminIndex']);
            Route::post('/complaints', [ComplaintController::class, 'adminStore']);
            Route::get('/complaints/{id}', [ComplaintController::class, 'adminShow']);
            Route::patch('/complaints/{id}', [ComplaintController::class, 'adminUpdate']);
            // Service Ticket workflow (Phase 2.3)
            Route::post('/complaints/{id}/comments', [ComplaintController::class, 'addComment']);
            Route::post('/complaints/{id}/transition', [ComplaintController::class, 'transition']);
            Route::post('/complaints/{id}/assign', [ComplaintController::class, 'assign']);

            // DMS - Document Management (Phase 2.4)
            Route::get('/documents', [CompanyDocumentController::class, 'index'])
                ->middleware('enterprise.capability:documents.repository.view,admin.access');
            Route::post('/documents', [CompanyDocumentController::class, 'store'])
                ->middleware('enterprise.capability:documents.repository.manage,admin.access');
            Route::get('/documents/{id}', [CompanyDocumentController::class, 'show'])
                ->middleware('enterprise.capability:documents.repository.view,admin.access');
            Route::put('/documents/{id}', [CompanyDocumentController::class, 'update'])
                ->middleware('enterprise.capability:documents.repository.manage,admin.access');
            Route::delete('/documents/{id}', [CompanyDocumentController::class, 'destroy'])
                ->middleware('enterprise.capability:documents.repository.manage,admin.access');
            Route::post('/documents/{id}/versions', [CompanyDocumentController::class, 'uploadVersion'])
                ->middleware('enterprise.capability:documents.repository.manage,admin.access');
            Route::get('/documents/{id}/versions/{versionId}/download', [CompanyDocumentController::class, 'download'])
                ->middleware('enterprise.capability:documents.repository.view,admin.access');

            // Maintenance Schedules (Phase 3.1) - preventive maintenance untuk fixed assets
            Route::get('/maintenance-schedules', [MaintenanceController::class, 'index']);
            Route::post('/maintenance-schedules', [MaintenanceController::class, 'store']);
            Route::get('/maintenance-schedules/{id}', [MaintenanceController::class, 'show']);
            Route::put('/maintenance-schedules/{id}', [MaintenanceController::class, 'update']);
            Route::delete('/maintenance-schedules/{id}', [MaintenanceController::class, 'destroy']);
            Route::post('/maintenance-schedules/{id}/logs', [MaintenanceController::class, 'logExecution']);

            // Quality Control Inspection (Phase 4.2)
            Route::get('/quality-inspections', [QualityInspectionController::class, 'index']);
            Route::post('/quality-inspections', [QualityInspectionController::class, 'store']);
            Route::get('/quality-inspections/{id}', [QualityInspectionController::class, 'show']);
            Route::post('/quality-inspections/{id}/submit', [QualityInspectionController::class, 'submit']);

            // Shipment / Delivery Note (Phase 5.1) - outbound delivery ke customer
            Route::get('/shipments', [ShipmentController::class, 'index']);
            Route::post('/shipments', [ShipmentController::class, 'store']);
            Route::get('/shipments/{id}', [ShipmentController::class, 'show']);
            Route::post('/shipments/{id}/transition', [ShipmentController::class, 'transition'])->middleware('idempotency');

            // Customer Satisfaction Survey / NPS (Phase 6.1)
            Route::get('/customer-surveys', [CustomerSurveyController::class, 'index']);
            Route::post('/customer-surveys', [CustomerSurveyController::class, 'store']);

            // Customer 360° (Phase 7.3) - cross-module aggregate per customer
            Route::get('/customer-360', [Customer360Controller::class, 'index']);
            Route::get('/customer-360/{id}', [Customer360Controller::class, 'show']);

            // Quotation / RAB / Surat Penawaran (Phase 8)
            Route::get('/quotations', [QuotationController::class, 'index']);
            Route::post('/quotations', [QuotationController::class, 'store']);
            Route::get('/quotations/{id}', [QuotationController::class, 'show']);
            Route::put('/quotations/{id}', [QuotationController::class, 'update']);
            Route::delete('/quotations/{id}', [QuotationController::class, 'destroy']);
            Route::get('/quotations/{id}/pdf', [QuotationController::class, 'pdf']);
            Route::post('/quotations/{id}/send', [QuotationController::class, 'send']);
            Route::post('/quotations/{id}/accept', [QuotationController::class, 'accept']);
            Route::post('/quotations/{id}/reject', [QuotationController::class, 'reject']);
            Route::post('/quotations/{id}/email', [QuotationController::class, 'emailQuote']);
            Route::post('/quotations/{id}/convert-to-invoice', [QuotationController::class, 'convertToInvoice']);

            // Vendor Performance Scorecard (Phase 10.1)
            Route::get('/vendor-scorecards', [VendorScorecardController::class, 'index']);

            // Production Calendar (Phase 10.2)
            Route::get('/production-calendar', [ProductionCalendarController::class, 'index']);

            // Quotation Templates (Phase 9.3)
            Route::get('/quotation-templates', [QuotationController::class, 'templatesIndex']);
            Route::post('/quotation-templates', [QuotationController::class, 'templatesStore']);
            Route::post('/quotation-templates/{id}/use', [QuotationController::class, 'templatesUse']);
            Route::delete('/quotation-templates/{id}', [QuotationController::class, 'templatesDestroy']);

            // Business Trip (Perjalanan Dinas)
            Route::get('/business-trips', [BusinessTripController::class, 'adminIndex']);
            Route::post('/business-trips/{id}/approve', [BusinessTripController::class, 'approve']);
            Route::post('/business-trips/{id}/reject', [BusinessTripController::class, 'reject']);

            // Client Visit (Kunjungan Klien)
            Route::get('/client-visits', [ClientVisitController::class, 'adminIndex']);
            Route::get('/client-visits/{id}', [ClientVisitController::class, 'adminShow']);

            // Holidays (libur nasional + cuti bersama)
            Route::get('/holidays', [HolidayController::class, 'adminIndex']);
            Route::post('/holidays', [HolidayController::class, 'store']);
            Route::put('/holidays/{id}', [HolidayController::class, 'update']);
            Route::delete('/holidays/{id}', [HolidayController::class, 'destroy']);
            Route::post('/holidays/{id}/broadcast', [HolidayController::class, 'broadcast']);
            // Auto-sync libur nasional Indonesia dari Nager.Date (free, official)
            Route::post('/holidays/sync/{year}', [HolidayController::class, 'syncFromApi'])->whereNumber('year');

            // Kontrak kerja - admin issue + lihat
            Route::post('/users/{user}/issue-contract', [ContractController::class, 'issue']);
            Route::get('/users/{user}/contracts', [ContractController::class, 'adminListForUser']);
            Route::get('/contracts/{id}/preview', [ContractController::class, 'adminPreview']);

            // ── Performance Management (OKR + 9-Box + Reviews) ────────────
            Route::get('/performance/okrs', [PerformanceController::class, 'indexOkrs']);
            Route::post('/performance/okrs', [PerformanceController::class, 'storeOkr']);
            Route::put('/performance/okrs/{id}', [PerformanceController::class, 'updateOkr']);
            Route::delete('/performance/okrs/{id}', [PerformanceController::class, 'destroyOkr']);
            Route::get('/performance/nine-box', [PerformanceController::class, 'indexNineBox']);
            Route::post('/performance/nine-box', [PerformanceController::class, 'upsertNineBox']);
            Route::delete('/performance/nine-box/{id}', [PerformanceController::class, 'destroyNineBox']);
            Route::get('/performance/reviews', [PerformanceController::class, 'indexReviews']);
            Route::post('/performance/reviews', [PerformanceController::class, 'storeReview']);
            Route::put('/performance/reviews/{id}', [PerformanceController::class, 'updateReview']);

            // ── Recruitment ATS ───────────────────────────────────────────
            Route::get('/recruitment/jobs', [RecruitmentController::class, 'indexJobs']);
            Route::post('/recruitment/jobs', [RecruitmentController::class, 'storeJob']);
            Route::put('/recruitment/jobs/{id}', [RecruitmentController::class, 'updateJob']);
            Route::delete('/recruitment/jobs/{id}', [RecruitmentController::class, 'destroyJob']);
            Route::get('/recruitment/candidates', [RecruitmentController::class, 'indexCandidates']);
            Route::post('/recruitment/candidates', [RecruitmentController::class, 'storeCandidate']);
            Route::put('/recruitment/candidates/{id}', [RecruitmentController::class, 'updateCandidate']);
            Route::delete('/recruitment/candidates/{id}', [RecruitmentController::class, 'destroyCandidate']);
            // AI-assisted recruitment
            Route::post('/recruitment/candidates/parse-cv', [RecruitmentAiController::class, 'parseCv']);
            Route::post('/recruitment/candidates/{id}/issue-offer', [RecruitmentAiController::class, 'issueOffer']);

            // ── Learning & Development ────────────────────────────────────
            Route::get('/learning/courses', [LearningController::class, 'indexCourses']);
            Route::post('/learning/courses', [LearningController::class, 'storeCourse']);
            Route::put('/learning/courses/{id}', [LearningController::class, 'updateCourse']);
            Route::delete('/learning/courses/{id}', [LearningController::class, 'destroyCourse']);
            Route::get('/learning/enrollments', [LearningController::class, 'indexEnrollments']);
            Route::post('/learning/enrollments', [LearningController::class, 'storeEnrollment']);
            Route::put('/learning/enrollments/{id}', [LearningController::class, 'updateEnrollment']);
            Route::delete('/learning/enrollments/{id}', [LearningController::class, 'destroyEnrollment']);
            Route::get('/learning/certificates', [LearningController::class, 'indexCertificates']);
            Route::post('/learning/certificates', [LearningController::class, 'storeCertificate']);
            Route::delete('/learning/certificates/{id}', [LearningController::class, 'destroyCertificate']);

            // ── RBAC ──────────────────────────────────────────────────────
            Route::get('/rbac', [RbacController::class, 'index']);
            Route::post('/rbac/roles', [RbacController::class, 'storeRole']);
            Route::delete('/rbac/roles/{id}', [RbacController::class, 'destroyRole']);
            Route::post('/rbac/capabilities', [RbacController::class, 'storeCapability']);
            Route::delete('/rbac/capabilities/{id}', [RbacController::class, 'destroyCapability']);
            Route::post('/rbac/toggle', [RbacController::class, 'toggle']);
            Route::get('/rbac/enterprise/assignments', [EnterpriseRbacController::class, 'assignments']);
            Route::post('/rbac/enterprise/assignments', [EnterpriseRbacController::class, 'storeAssignment']);
            Route::post('/rbac/enterprise/assignments/{id}/revoke', [EnterpriseRbacController::class, 'revokeAssignment']);
            Route::post('/rbac/enterprise/delegations', [EnterpriseRbacController::class, 'storeDelegation']);
            Route::post('/rbac/enterprise/delegations/{id}/revoke', [EnterpriseRbacController::class, 'revokeDelegation']);
            Route::get('/rbac/enterprise/shadow-summary', [EnterpriseRbacController::class, 'shadowSummary']);

            // ── Leave Policy ──────────────────────────────────────────────
            Route::get('/leave-policy', [LeavePolicyController::class, 'index']);
            Route::post('/leave-policy/quotas', [LeavePolicyController::class, 'storeQuota']);
            Route::put('/leave-policy/quotas/{id}', [LeavePolicyController::class, 'updateQuota']);
            Route::delete('/leave-policy/quotas/{id}', [LeavePolicyController::class, 'destroyQuota']);
            Route::post('/leave-policy/holidays', [LeavePolicyController::class, 'storeHoliday']);
            Route::delete('/leave-policy/holidays/{id}', [LeavePolicyController::class, 'destroyHoliday']);
            Route::put('/leave-policy/globals', [LeavePolicyController::class, 'updateGlobals']);

            // ── Payroll Formula ───────────────────────────────────────────
            Route::get('/payroll-formula', [PayrollFormulaController::class, 'index']);
            Route::post('/payroll-formula', [PayrollFormulaController::class, 'store']);
            Route::put('/payroll-formula/{id}', [PayrollFormulaController::class, 'update']);
            Route::delete('/payroll-formula/{id}', [PayrollFormulaController::class, 'destroy']);

            // ── Workflow ──────────────────────────────────────────────────
            Route::get('/workflow', [WorkflowController::class, 'index']);
            Route::post('/workflow/steps', [WorkflowController::class, 'storeStep']);
            Route::put('/workflow/steps/{id}', [WorkflowController::class, 'updateStep']);
            Route::delete('/workflow/steps/{id}', [WorkflowController::class, 'destroyStep']);
            Route::post('/workflow/reorder', [WorkflowController::class, 'reorder']);

            // ── Notification Templates ────────────────────────────────────
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications', [NotificationController::class, 'store']);
            Route::put('/notifications/{id}', [NotificationController::class, 'update']);
            Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
            Route::post('/notifications/{id}/test', [NotificationController::class, 'testSend']);

            // ── Integrations (encrypted credentials) ──────────────────────
            Route::get('/integrations', [IntegrationController::class, 'index']);
            Route::post('/integrations', [IntegrationController::class, 'store']);
            Route::post('/integrations/{id}/connect', [IntegrationController::class, 'connect']);
            Route::post('/integrations/{id}/disconnect', [IntegrationController::class, 'disconnect']);
            Route::delete('/integrations/{id}', [IntegrationController::class, 'destroy']);

            // ── Backup ────────────────────────────────────────────────────
            Route::get('/backup', [BackupController::class, 'index']);
            Route::put('/backup/config', [BackupController::class, 'updateConfig']);
            Route::post('/backup/run', [BackupController::class, 'run']);
            Route::delete('/backup/snapshots/{id}', [BackupController::class, 'destroy']);

            // ── Theme Builder ─────────────────────────────────────────────
            Route::get('/theme', [ThemeController::class, 'show']);
            Route::post('/theme', [ThemeController::class, 'update']);

            // ── Master Data: Office Locations (CRUD) ──────────────────────
            Route::get('/master-data/office-locations', [OfficeLocationController::class, 'index']);
            Route::post('/master-data/office-locations', [OfficeLocationController::class, 'store']);
            Route::put('/master-data/office-locations/{id}', [OfficeLocationController::class, 'update']);
            Route::delete('/master-data/office-locations/{id}', [OfficeLocationController::class, 'destroy']);

            // ── Attendance Devices (hardware mesin absensi) ───────────────
            Route::get('/attendance-devices', [AttendanceDeviceController::class, 'index']);
            Route::post('/attendance-devices', [AttendanceDeviceController::class, 'store']);
            Route::put('/attendance-devices/{id}', [AttendanceDeviceController::class, 'update']);
            Route::delete('/attendance-devices/{id}', [AttendanceDeviceController::class, 'destroy']);
            Route::post('/attendance-devices/{id}/sync', [AttendanceDeviceController::class, 'sync']);
            Route::post('/attendance-devices/{id}/manual-pull', [HardwareController::class, 'manualPull']);

            // ── Workflow Engine describe (read-only) ──────────────────────
            Route::get('/workflow/describe', [WorkflowEngineController::class, 'describe']);
        });

        // ── Finance Module (isolated App\Finance namespace) ───────────────
        // ISO double-entry bookkeeping. Capability `finance.module.access`
        // (finance + superadmin only).
        Route::prefix('finance')->group(function () {
            Route::get('/dashboard', [FinanceDashboardController::class, 'index']);
            Route::get('/macro', [FinanceDashboardController::class, 'macro']);
            Route::get('/accounts', [AccountController::class, 'index']);
            Route::post('/accounts', [AccountController::class, 'store']);
            Route::post('/accounts/seed', [AccountController::class, 'seed']);
            Route::put('/accounts/{id}', [AccountController::class, 'update']);
            Route::delete('/accounts/{id}', [AccountController::class, 'destroy']);

            Route::get('/journals', [JournalController::class, 'index']);
            Route::post('/journals', [JournalController::class, 'store'])->middleware('idempotency');
            Route::get('/journals/{id}', [JournalController::class, 'show']);
            Route::post('/journals/{id}/post', [JournalController::class, 'post'])->middleware('idempotency');
            Route::post('/journals/{id}/reverse', [JournalController::class, 'reverse'])->middleware('idempotency');
            Route::delete('/journals/{id}', [JournalController::class, 'destroy']);

            // Financial Reports - Buku Besar, Trial Balance, Neraca, Laba Rugi
            Route::get('/reports/ledger/{accountId}', [ReportController::class, 'ledger']);
            Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance']);
            Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet']);
            Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss']);
            Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow']);
            Route::get('/reports/ar-aging', [ReportController::class, 'arAging']);
            Route::get('/reports/ap-aging', [ReportController::class, 'apAging']);
            Route::get('/reports/tax-ppn', [ReportController::class, 'taxPpn']);
            // Unduh laporan sebagai CSV. Memakai ulang ReportService yang sama.
            Route::get('/reports/export/{type}', [ReportController::class, 'export']);

            // Customers + Sales Invoices (auto-create journal on send/pay)
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::put('/customers/{id}', [CustomerController::class, 'update']);
            Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);

            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('idempotency');
            Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
            Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf']);
            Route::post('/invoices/{id}/send', [InvoiceController::class, 'send']);
            Route::post('/invoices/{id}/pay', [InvoiceController::class, 'pay'])->middleware('idempotency');
            Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel']);

            // Suppliers + Bills (Hutang / Accounts Payable)
            Route::get('/suppliers', [SupplierController::class, 'index']);
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
            Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);

            Route::get('/bills', [BillController::class, 'index']);
            Route::post('/bills', [BillController::class, 'store']);
            Route::get('/bills/{id}', [BillController::class, 'show']);
            Route::get('/bills/{id}/pdf', [BillController::class, 'pdf']);
            Route::post('/bills/{id}/receive', [BillController::class, 'receive']);
            Route::post('/bills/{id}/pay', [BillController::class, 'pay'])->middleware('idempotency');
        });

        // Treasury core: bank accounts, maker-checker payments, and cash ledger.
        Route::prefix('treasury')->middleware('module:treasury')->group(function () {
            Route::get('/bank-accounts', [TreasuryController::class, 'accounts']);
            Route::post('/bank-accounts', [TreasuryController::class, 'storeAccount'])->middleware('idempotency');
            Route::get('/payments', [TreasuryController::class, 'payments']);
            Route::post('/payments', [TreasuryController::class, 'storePayment'])->middleware('idempotency');
            Route::post('/payments/{id}/submit', [TreasuryController::class, 'submit'])->middleware('idempotency');
            Route::post('/payments/{id}/approve', [TreasuryController::class, 'approve'])->middleware('idempotency');
            Route::post('/payments/{id}/reject', [TreasuryController::class, 'reject'])->middleware('idempotency');
            Route::post('/payments/{id}/execute', [TreasuryController::class, 'execute'])->middleware('idempotency');
            Route::get('/cash-ledger', [TreasuryController::class, 'cashLedger']);
        });

        // ── CRM Module (Customer Relationship Management) ────────────────────
        // RBAC: crm.view (semua sales), crm.manage (create/edit), crm.admin
        // (delete + override owner). Auth via Sanctum + SetActiveCompany.
        Route::prefix('crm')->middleware('auth:sanctum')->group(function () {
            // Pipelines + stages (read-only - pipeline editor di-skip dulu)
            Route::get('/pipelines', [PipelineController::class, 'index']);
            Route::get('/pipelines/{id}/stages', [PipelineController::class, 'stages']);

            // Leads
            Route::get('/leads', [LeadController::class, 'index']);
            Route::post('/leads', [LeadController::class, 'store']);
            Route::get('/leads/{id}', [LeadController::class, 'show']);
            Route::put('/leads/{id}', [LeadController::class, 'update']);
            Route::delete('/leads/{id}', [LeadController::class, 'destroy']);
            Route::post('/leads/{id}/convert', [LeadController::class, 'convert']);

            // Companies
            Route::get('/companies', [App\Marketing\Http\Controllers\CompanyController::class, 'index']);
            Route::post('/companies', [App\Marketing\Http\Controllers\CompanyController::class, 'store']);
            Route::get('/companies/{id}', [App\Marketing\Http\Controllers\CompanyController::class, 'show']);
            Route::put('/companies/{id}', [App\Marketing\Http\Controllers\CompanyController::class, 'update']);
            Route::delete('/companies/{id}', [App\Marketing\Http\Controllers\CompanyController::class, 'destroy']);

            // Contacts
            Route::get('/contacts', [ContactController::class, 'index']);
            Route::post('/contacts', [ContactController::class, 'store']);
            Route::put('/contacts/{id}', [ContactController::class, 'update']);
            Route::delete('/contacts/{id}', [ContactController::class, 'destroy']);

            // Deals (pipeline core)
            Route::get('/deals', [DealController::class, 'index']);
            Route::get('/deals/board', [DealController::class, 'board']);
            Route::post('/deals', [DealController::class, 'store']);
            Route::get('/deals/{id}', [DealController::class, 'show']);
            Route::put('/deals/{id}', [DealController::class, 'update']);
            Route::post('/deals/{id}/move', [DealController::class, 'move']);
            Route::post('/deals/{id}/invoice', [DealController::class, 'issueInvoice']);
            Route::delete('/deals/{id}', [DealController::class, 'destroy']);

            // Activities (timeline log)
            Route::get('/activities', [ActivityController::class, 'index']);
            Route::post('/activities', [ActivityController::class, 'store']);
            Route::delete('/activities/{id}', [ActivityController::class, 'destroy']);

            // Reports
            Route::get('/reports/summary', [App\Marketing\Http\Controllers\ReportController::class, 'summary']);
            Route::get('/reports/funnel', [App\Marketing\Http\Controllers\ReportController::class, 'funnel']);

            // Web Forms (admin) - crm.manage to mutate, crm.view to read
            Route::get('/forms', [LeadFormController::class, 'index']);
            Route::post('/forms', [LeadFormController::class, 'store']);
            Route::get('/forms/owners', [LeadFormController::class, 'owners']);
            Route::get('/forms/{id}', [LeadFormController::class, 'show']);
            Route::put('/forms/{id}', [LeadFormController::class, 'update']);
            Route::delete('/forms/{id}', [LeadFormController::class, 'destroy']);
            Route::post('/forms/{id}/rotate-token', [LeadFormController::class, 'rotateToken']);
            Route::get('/forms/{id}/submissions', [LeadFormController::class, 'submissions']);

            // Kampanye email - dikirim penjadwal (crm:kampanye-kirim), batas harian di config/crm.php
            Route::get('/campaigns', [CampaignController::class, 'index']);
            Route::post('/campaigns', [CampaignController::class, 'store']);
            Route::get('/campaigns/quota', [CampaignController::class, 'quota']);
            Route::post('/campaigns/audience-preview', [CampaignController::class, 'audiencePreview']);
            Route::get('/campaigns/{id}', [CampaignController::class, 'show'])->whereNumber('id');
            Route::put('/campaigns/{id}', [CampaignController::class, 'update'])->whereNumber('id');
            Route::delete('/campaigns/{id}', [CampaignController::class, 'destroy'])->whereNumber('id');
            Route::get('/campaigns/{id}/render', [CampaignController::class, 'render'])->whereNumber('id');
            Route::get('/campaigns/{id}/recipients', [CampaignController::class, 'recipients'])->whereNumber('id');
            Route::post('/campaigns/{id}/test', [CampaignController::class, 'test'])->whereNumber('id')->middleware('throttle:10,1');
            Route::post('/campaigns/{id}/start', [CampaignController::class, 'start'])->whereNumber('id');
            Route::post('/campaigns/{id}/pause', [CampaignController::class, 'pause'])->whereNumber('id');
        });

    });
});

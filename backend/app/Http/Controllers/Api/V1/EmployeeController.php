<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AdminEmployeeResource;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\InviteTokenService;
use App\Services\ResignationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class EmployeeController extends ApiController
{
    public function __construct(
        private readonly EmployeeService $service,
        private readonly InviteTokenService $inviteTokens,
        private readonly ResignationService $resignations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'employees.manage', 'Employee management access required.');

        $employees = $this->service->paginateForAdmin($user, $request->only([
            'search',
            'division_id',
            'job_title_id',
            'education_id',
            'employment_status',
            'training_review_status',
            'include_inactive',
            'page',
            'per_page',
        ]));

        return $this->paginated($employees, AdminEmployeeResource::collection($employees->items()), [
            'references' => $this->service->referencesForAdmin($user),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'employees.manage', 'Employee management access required.');

        $employee = $this->service->findForAdmin($user, $id);

        return $this->success(new AdminEmployeeResource($employee));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'employees.manage', 'Employee management access required.');

        $validated = $request->validate($this->service->validationRules($user));
        [$employee, $inviteToken, $mailboxCreds] = $this->service->create($user, $validated);
        $inviteEmailSent = $this->sendInviteEmail($employee, $inviteToken);

        return $this->created([
            ...(new AdminEmployeeResource($employee))->toArray($request),
            'invite_token'      => $inviteToken,
            'invite_expires_at' => now()->addHours($this->inviteTokens->ttlHours())->toISOString(),
            'invite_email_sent' => $inviteEmailSent,
            'mailbox' => $mailboxCreds ? [
                'email'    => $mailboxCreds['email'],
                'password' => $mailboxCreds['password'],
                'webmail'  => 'https://webmail.ibereality.com',
                'note'     => 'Sampaikan credentials ini ke karyawan via WA/email pribadi. Mereka bisa ganti password setelah login pertama.',
            ] : null,
        ], 'Employee berhasil dibuat.' . ($mailboxCreds ? ' Mailbox ' . $mailboxCreds['email'] . ' aktif.' : ''));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'employees.manage', 'Employee management access required.');

        $employee = $this->service->findForAdmin($user, $id);
        $validated = $request->validate($this->service->validationRules($user, $employee));
        $updated = $this->service->update($user, $id, $validated);

        return $this->success(new AdminEmployeeResource($updated), 'Employee berhasil diperbarui.');
    }

    /**
     * POST /api/v1/admin/employees/{id}/regenerate-invite
     * Issue a fresh invite token for an existing employee (e.g. link expired).
     */
    public function regenerateInvite(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'employees.manage', 'Employee management access required.');

        $employee = $this->service->findForAdmin($user, $id);
        $inviteToken = $this->inviteTokens->generate($employee);
        $inviteEmailSent = $this->sendInviteEmail($employee, $inviteToken);

        return $this->success([
            'invite_token'      => $inviteToken,
            'invite_expires_at' => now()->addHours($this->inviteTokens->ttlHours())->toISOString(),
            'invite_email_sent' => $inviteEmailSent,
        ], 'Link undangan baru berhasil dibuat.');
    }

    private function sendInviteEmail(User $employee, string $inviteToken): bool
    {
        if (! $employee->email) {
            return false;
        }

        try {
            $html = view('emails.employee-invite', [
                'employeeName' => $employee->name,
                'employeeNumber' => $employee->employee_number,
                'inviteUrl' => $this->inviteTokens->activationUrl($inviteToken),
                'ttlHours' => $this->inviteTokens->ttlHours(),
            ])->render();

            Mail::html($html, function ($message) use ($employee): void {
                $message->to($employee->email)->subject('Aktivasi akun IBE Reality');
            });

            return true;
        } catch (\Throwable $exception) {
            Log::error('Employee invite email failed.', [
                'employee_id' => $employee->id,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * POST /api/v1/admin/employees/{id}/resign
     *
     * Tandai karyawan resign / di-terminated. Semua data lama (attendance,
     * payroll, leave history, kontrak) TETAP tersimpan - user hanya
     * kehilangan akses sistem. Token Sanctum + refresh chain langsung
     * di-revoke supaya sesi aktif di device manapun langsung mati.
     */
    public function resign(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $data = $request->validate([
            'resignation_type' => ['required', Rule::in(['resign', 'terminated', 'retired'])],
            'resigned_at'      => ['required', 'date'],
            'last_working_date'=> ['nullable', 'date', 'before_or_equal:resigned_at'],
            'reason'           => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = $this->service->findForAdmin($admin, $id);

        // Cegah admin tandai dirinya sendiri / cegah resign user yang sudah inactive
        if ($employee->id === $admin->id) {
            abort(422, 'Tidak bisa menandai diri sendiri resign.');
        }
        if ($employee->is_inactive) {
            abort(422, 'Karyawan ini sudah berstatus tidak aktif.');
        }

        $updated = $this->resignations->resign($employee, $admin, $data, $request);

        return $this->success(
            new AdminEmployeeResource($updated),
            'Karyawan ditandai resign. Semua sesi aktif telah dihentikan.'
        );
    }

    /**
     * POST /api/v1/admin/employees/{id}/reactivate
     *
     * Undo resign - kembalikan ke status sebelumnya. Token harus issue ulang
     * (user perlu login lagi). Berguna kalau:
     *   - Salah klik admin
     *   - Karyawan kembali bekerja (re-hire)
     */
    public function reactivate(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $data = $request->validate([
            'employment_status' => ['nullable', Rule::in(['permanent', 'probation', 'intern_campus', 'intern_hub', 'pkl_smk'])],
        ]);

        $employee = $this->service->findForAdmin($admin, $id);
        if (!$employee->is_inactive) {
            abort(422, 'Karyawan ini sudah aktif.');
        }

        $updated = $this->resignations->reactivate(
            $employee, $admin,
            $data['employment_status'] ?? 'permanent',
            $request
        );

        return $this->success(
            new AdminEmployeeResource($updated),
            'Karyawan diaktifkan kembali. Mereka bisa login setelah reset password.'
        );
    }
}

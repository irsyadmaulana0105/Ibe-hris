<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\Overtime;
use App\Models\Reimbursement;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Off-boarding workflow: tandai user resigned/terminated dengan metadata
 * audit + langsung revoke semua sesi aktif. Lawan dari reactivation flow
 * yang clear flag + kembalikan ke status sebelumnya.
 *
 * Data karyawan TETAP ada (attendance, payroll, leave history, dll). User
 * hanya kehilangan akses sistem - record-nya tetap bisa di-query untuk
 * audit/reporting/back-payroll.
 */
class ResignationService
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Tandai user resign/terminated.
     *
     * @param array{
     *   resignation_type: 'resign'|'terminated'|'retired',
     *   resigned_at: string,
     *   last_working_date?: string|null,
     *   reason?: string|null,
     * } $data
     */
    public function resign(User $user, User $actor, array $data, ?\Illuminate\Http\Request $request = null): User
    {
        return DB::transaction(function () use ($user, $actor, $data, $request) {
            $newStatus = match ($data['resignation_type']) {
                'terminated' => 'terminated',
                default      => 'resigned',  // resign + retired keduanya 'resigned' (status)
            };

            $user->update([
                'employment_status'   => $newStatus,
                'resigned_at'         => $data['resigned_at'],
                'last_working_date'   => $data['last_working_date'] ?? $data['resigned_at'],
                'resignation_type'    => $data['resignation_type'],
                'resignation_reason'  => $data['reason'] ?? null,
                'resigned_by'         => $actor->id,
            ]);

            // Kill all sessions - Sanctum tokens + refresh chain. User tidak
            // bisa lanjut pakai sistem dari device manapun mulai detik ini.
            $user->tokens()->delete();
            $this->refreshTokens->revokeAllForUser($user->id);

            // Auto-reject semua pending request yang tidak akan pernah
            // bisa di-approve setelah user resign. Tetap simpan record-nya
            // untuk audit history - hanya status yang berubah.
            $rejectReason = 'Otomatis ditolak karena karyawan resign/diberhentikan pada ' . $data['resigned_at'];
            $rejectedCounts = [];
            $rejectAt = Carbon::now();

            foreach ([
                Leave::class         => 'leaves',
                Overtime::class      => 'overtimes',
                SalaryAdvance::class => 'salary_advances',
                Reimbursement::class => 'reimbursements',
            ] as $modelClass => $label) {
                $count = $modelClass::where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->update([
                        'status'      => 'rejected',
                        'notes'       => $rejectReason,
                        'approved_by' => $actor->id,
                        'approved_at' => $rejectAt,
                    ]);
                if ($count > 0) $rejectedCounts[$label] = $count;
            }

            $this->auditLogs->log(
                action: 'employee.resigned',
                actor: $actor,
                entityType: 'user',
                entityId: $user->id,
                meta: [
                    'resignation_type'  => $data['resignation_type'],
                    'resigned_at'       => $data['resigned_at'],
                    'last_working_date' => $data['last_working_date'] ?? $data['resigned_at'],
                    'previous_status'   => $user->getOriginal('employment_status'),
                    'auto_rejected_pending' => $rejectedCounts,
                ],
                request: $request,
            );

            return $user->refresh();
        });
    }

    /**
     * Reactivate - undo resign kalau user kembali atau salah klik.
     * Restore ke employment_status sebelumnya (default 'permanent').
     */
    public function reactivate(User $user, User $actor, string $newStatus = 'permanent', ?\Illuminate\Http\Request $request = null): User
    {
        if (!in_array($newStatus, ['permanent', 'probation', 'intern_campus', 'intern_hub', 'pkl_smk'], true)) {
            throw new \InvalidArgumentException("Status tidak valid: {$newStatus}");
        }

        $previousData = [
            'previous_resigned_at'      => $user->resigned_at?->toDateString(),
            'previous_resignation_type' => $user->resignation_type,
        ];

        $user->update([
            'employment_status'   => $newStatus,
            'resigned_at'         => null,
            'last_working_date'   => null,
            'resignation_type'    => null,
            'resignation_reason'  => null,
            // resigned_by tetap di simpan sebagai history siapa pernah resign-kan
            // - bisa di-track kalau perlu audit reactivation
        ]);

        $this->auditLogs->log(
            action: 'employee.reactivated',
            actor: $actor,
            entityType: 'user',
            entityId: $user->id,
            meta: array_merge($previousData, ['new_status' => $newStatus]),
            request: $request,
        );

        return $user->refresh();
    }
}

<?php

namespace App\Console\Commands;

use App\Exceptions\PrivateAssetIntegrityException;
use App\Services\AttendanceReimbursementPrivateMediaCutover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CutoverAttendanceReimbursementPrivateMedia extends Command
{
    protected $signature = 'private-media:attendance-reimbursement-20260809
        {phase : plan|copy-private|remediate-missing|switch-private|purge-public|restore-public}
        {--tenant= : Read-only plan for one numeric company ID}
        {--apply : Required for every mutating phase}
        {--uat-confirmed : Required before purge-public}
        {--evidence= : Evidence token printed by plan for missing-reference tombstones}';

    protected $description = 'Safely cut Attendance and Reimbursement media between public and tenant-private storage.';

    public function handle(AttendanceReimbursementPrivateMediaCutover $cutover): int
    {
        $phase = (string) $this->argument('phase');
        if (! in_array($phase, [
            'plan', 'copy-private', 'remediate-missing', 'switch-private', 'purge-public', 'restore-public',
        ], true)) {
            $this->error('Invalid phase.');

            return self::INVALID;
        }

        $tenant = $this->option('tenant');
        if ($tenant !== null && (! ctype_digit((string) $tenant) || (int) $tenant < 1)) {
            $this->error('Tenant must be a positive numeric company ID.');

            return self::INVALID;
        }

        try {
            $report = $cutover->run(
                $phase,
                $tenant === null ? null : (int) $tenant,
                (bool) $this->option('uat-confirmed'),
                $this->option('evidence') === null ? null : (string) $this->option('evidence'),
                (bool) $this->option('apply'),
                true,
            );
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $failure) {
            Log::error('Attendance and reimbursement private-media cutover failed closed.', [
                'release' => AttendanceReimbursementPrivateMediaCutover::RELEASE,
                'phase' => $phase,
                'exception_type' => $failure::class,
                'reason' => $failure instanceof PrivateAssetIntegrityException
                    ? $failure->reason
                    : 'unexpected_failure',
            ]);
            $this->error('Cutover phase failed closed. Review application logs; no paths are printed.');

            return self::FAILURE;
        }
    }
}

<?php

namespace App\Jobs;

use App\Helpers;
use App\Models\Payroll;
use App\Services\PayrollDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPayrollSlipEmail
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $payrollId,
        public string $recipientEmail,
        public array $bccEmails = [],
        public ?string $requestedByEmail = null,
    ) {
    }

    public function handle(): void
    {
        try {
            $recipientEmail = trim($this->recipientEmail);
            if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                Log::warning('Payroll slip email skipped because recipient address is invalid.', [
                    'payroll_id' => $this->payrollId,
                    'recipient_email' => $this->recipientEmail,
                    'requested_by' => $this->requestedByEmail,
                ]);

                return;
            }

            /** @var Payroll $payroll */
            $payroll = Payroll::withoutGlobalScopes()
                ->with(['user.division', 'user.jobTitle', 'user.company'])
                ->findOrFail($this->payrollId);

            $bccEmails = Helpers::sanitizeEmailList($this->bccEmails, [$recipientEmail]);

            Log::info('Sending payroll slip email.', [
                'payroll_id' => $payroll->id,
                'employee_id' => $payroll->user_id,
                'to' => $recipientEmail,
                'bcc' => $bccEmails,
                'requested_by' => $this->requestedByEmail,
            ]);

            app(PayrollDocumentService::class)->sendSlipEmail(
                $payroll,
                $recipientEmail,
                $bccEmails
            );

            Log::info('Payroll slip email sent.', [
                'payroll_id' => $payroll->id,
                'employee_id' => $payroll->user_id,
                'to' => $recipientEmail,
                'bcc' => $bccEmails,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send payroll slip email.', [
                'payroll_id' => $this->payrollId,
                'recipient_email' => $this->recipientEmail,
                'bcc' => $this->bccEmails,
                'requested_by' => $this->requestedByEmail,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }
}

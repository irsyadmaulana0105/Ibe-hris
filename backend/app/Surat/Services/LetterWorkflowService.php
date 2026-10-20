<?php

namespace App\Surat\Services;

use App\Models\User;
use App\Surat\Models\Letter;
use App\Surat\Models\LetterRevision;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Workflow state machine untuk Letter.
 *
 * Wraps state transitions dengan:
 *   1. Validasi transition allowed (lihat Letter::TRANSITIONS)
 *   2. Update timestamp + actor untuk state baru
 *   3. Trigger side effect (e.g., generate nomor saat APPROVED, render PDF saat SIGNED)
 *   4. Write LetterRevision audit row
 */
class LetterWorkflowService
{
    public function __construct(
        private LetterNumberService $numberSvc,
        private LetterPdfService    $pdfSvc,
        private LetterEmailService  $emailSvc,
    ) {}

    public function submit(Letter $letter, User $actor, ?string $note = null): Letter
    {
        return $this->transition($letter, Letter::STATUS_REVIEW, $actor, $note, [
            'submitted_at' => now(),
        ]);
    }

    public function requestRevision(Letter $letter, User $reviewer, string $note): Letter
    {
        // Reset nomor kalau sempat ke-assign (case: APPROVED → DRAFT revert).
        // Counter di category TIDAK di-decrement (numbers must be monotonic;
        // audit gap di Audit Log).
        return $this->transition($letter, Letter::STATUS_DRAFT, $reviewer, $note, [
            'reviewer_id'        => $reviewer->id,
            'reviewed_at'        => now(),
            'letter_number'      => null,
            'letter_number_seq'  => null,
            'letter_number_year' => null,
        ]);
    }

    public function approve(Letter $letter, User $approver, ?string $note = null): Letter
    {
        return DB::transaction(function () use ($letter, $approver, $note) {
            // H4 fix: lock the letter row to serialize concurrent approve attempts.
            // Re-fetch fresh state under lock to prevent race where 2 approvers
            // both see status=review and both increment the counter.
            $fresh = Letter::query()->where('id', $letter->id)->lockForUpdate()->first();
            if (!$fresh) throw new \RuntimeException('Letter tidak ditemukan.');
            if ($fresh->status !== Letter::STATUS_REVIEW) {
                throw new \RuntimeException("Surat sudah berubah status menjadi {$fresh->status} oleh user lain.");
            }
            if ($fresh->letter_number !== null) {
                throw new \RuntimeException('Surat sudah punya nomor - race terdeteksi.');
            }

            // Generate nomor surat secara atomic
            [$number, $seq, $year] = $this->numberSvc->generate($fresh);

            // Kalau belum ada reviewer (e.g., approver bertindak sebagai
            // sekretaris + approver sekaligus - diizinkan oleh policy
            // selama bukan author), catat approver sebagai reviewer juga
            // supaya audit log honest.
            return $this->transition($fresh, Letter::STATUS_APPROVED, $approver, $note, [
                'reviewer_id'        => $fresh->reviewer_id ?: $approver->id,
                'reviewed_at'        => $fresh->reviewed_at ?: now(),
                'approver_id'        => $approver->id,
                'approved_at'        => now(),
                'letter_number'      => $number,
                'letter_number_seq'  => $seq,
                'letter_number_year' => $year,
            ]);
        });
    }

    public function sign(Letter $letter, User $actor, ?int $signerId = null, ?string $note = null): Letter
    {
        return DB::transaction(function () use ($letter, $actor, $signerId, $note) {
            $patch = ['signed_at' => now()];
            if ($signerId) $patch['signer_id'] = $signerId;

            $letter = $this->transition($letter, Letter::STATUS_SIGNED, $actor, $note, $patch);

            // Render PDF final + save path
            $pdfPath = $this->pdfSvc->renderToFile($letter);
            $letter->update([
                'pdf_path'     => $pdfPath,
                'pdf_checksum' => hash_file('sha256', storage_path('app/' . $pdfPath)) ?: null,
            ]);
            return $letter;
        });
    }

    public function send(Letter $letter, User $actor, ?string $note = null, bool $sendEmail = false): Letter
    {
        $letter = $this->transition($letter, Letter::STATUS_SENT, $actor, $note, [
            'sent_at' => now(),
        ]);
        if ($sendEmail) {
            try {
                $this->emailSvc->send($letter, $note);
            } catch (\Throwable $e) {
                // Email fail jangan rollback transition - surat udah dikirim secara
                // logis. Operator bisa retry via UI. Log + tetap return letter.
                \Log::warning('surat.workflow.send_email_failed', [
                    'letter_id' => $letter->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
        return $letter;
    }

    public function archive(Letter $letter, User $actor, ?string $note = null): Letter
    {
        return $this->transition($letter, Letter::STATUS_ARCHIVED, $actor, $note, [
            'archived_at' => now(),
        ]);
    }

    public function cancel(Letter $letter, User $actor, string $reason): Letter
    {
        return $this->transition($letter, Letter::STATUS_CANCELLED, $actor, $reason);
    }

    /**
     * Generic transition + audit row. Caller-facing methods di atas wrap
     * dengan side effect (nomor, PDF, dll).
     */
    private function transition(Letter $letter, string $to, User $actor, ?string $note, array $patch = []): Letter
    {
        if (!$letter->canTransitionTo($to)) {
            throw new InvalidArgumentException("Tidak bisa transition dari {$letter->status} ke {$to}");
        }

        $before = $letter->status;
        $letter->fill(array_merge(['status' => $to], $patch));
        $letter->save();

        $nextVersion = ($letter->revisions()->max('version') ?? 0) + 1;
        LetterRevision::create([
            'letter_id'         => $letter->id,
            'version'           => $nextVersion,
            'status_before'     => $before,
            'status_after'      => $to,
            'body_html_snapshot'=> in_array($to, [Letter::STATUS_APPROVED, Letter::STATUS_SIGNED], true) ? $letter->body_html : null,
            'body_data_snapshot'=> in_array($to, [Letter::STATUS_APPROVED, Letter::STATUS_SIGNED], true) ? $letter->body_data : null,
            'changed_by_id'     => $actor->id,
            'change_note'       => $note,
        ]);

        return $letter->refresh();
    }
}

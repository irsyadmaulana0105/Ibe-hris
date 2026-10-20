<?php

namespace App\Surat\Services;

use App\Surat\Models\Letter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Kirim surat (PDF) ke recipient via Stalwart SMTP.
 *
 * Dipakai opsional saat workflow transition SIGNED → SENT - kalau caller
 * pass send_email=true, otomatis kirim email dengan PDF attached.
 *
 * Hardening:
 *   - Recipient email harus valid (validation level controller).
 *   - PDF file dibaca dari storage (chroot-aware), bukan from URL.
 *   - Subject + body templates straightforward, attribusi via $signer.
 */
class LetterEmailService
{
    public function send(Letter $letter, ?string $customMessage = null): array
    {
        $to = $letter->recipient_email;
        if (!$to) {
            throw new \InvalidArgumentException('Recipient email kosong - set recipient_email dulu.');
        }
        if (!$letter->pdf_path || !Storage::disk('local')->exists($letter->pdf_path)) {
            throw new \RuntimeException('PDF surat belum di-render. Sign dulu sebelum kirim.');
        }

        $signerName = $letter->signer?->name ?? 'PT Integrasi Bisnis Eksekutif';
        $signerTitle = $letter->signer?->title ?? 'Direktur Utama';
        $companyName = 'PT Integrasi Bisnis Eksekutif (IBE Reality)';

        $subject = sprintf('[%s] %s - %s',
            $letter->letter_number ?? 'Surat',
            $letter->category?->name ?? 'Surat',
            $letter->subject,
        );

        $body = $customMessage ?: $this->defaultBody($letter, $signerName, $signerTitle, $companyName);
        $attachPath = Storage::disk('local')->path($letter->pdf_path);
        $attachName = ($letter->letter_number ?: $letter->public_id) . '.pdf';

        try {
            Mail::raw($body, function ($m) use ($to, $subject, $attachPath, $attachName, $letter) {
                $m->to($to)
                  ->subject($subject)
                  ->attach($attachPath, ['as' => $attachName, 'mime' => 'application/pdf']);
                if ($letter->author?->email) {
                    $m->replyTo($letter->author->email);
                }
            });
            Log::info('surat.email.sent', [
                'letter_id' => $letter->id,
                'number'    => $letter->letter_number,
                'to'        => $to,
            ]);
            return ['ok' => true, 'to' => $to, 'subject' => $subject];
        } catch (\Throwable $e) {
            Log::error('surat.email.send_failed', [
                'letter_id' => $letter->id,
                'to'        => $to,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function defaultBody(Letter $letter, string $signerName, string $signerTitle, string $companyName): string
    {
        $recipientName = $letter->recipient_name ?? 'Bapak/Ibu';
        $number = $letter->letter_number ?? '-';
        $subject = $letter->subject;

        return <<<TXT
Yth. {$recipientName},

Terlampir kami sampaikan {$letter->category?->name} dengan nomor {$number}
mengenai "{$subject}" untuk perhatian dan tindak lanjutnya.

Apabila ada pertanyaan, mohon dapat menghubungi kami kembali.

Hormat kami,
{$signerName}
{$signerTitle}
{$companyName}

- Email ini dikirim otomatis oleh Sistem Dokumen IBE Reality -
TXT;
    }
}

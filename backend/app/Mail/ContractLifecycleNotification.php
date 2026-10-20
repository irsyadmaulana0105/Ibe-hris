<?php

namespace App\Mail;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email notifikasi transisi state kontrak.
 * Recipient dan subject di-tune berdasarkan event type.
 */
class ContractLifecycleNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Contract $contract,
        public string $event,
        public string $recipientName,
        public ?string $actionUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->event) {
            'contract.ready_for_karyawan' => 'Kontrak Kerja Menunggu Tanda Tangan Anda',
            'contract.ready_for_direktur' => 'Kontrak Kerja Menunggu Tanda Tangan Direktur',
            'contract.materai_needed'     => 'Kontrak Siap Upload E-Materai',
            'contract.archived'           => 'Kontrak Anda Telah Diarsipkan',
            default                       => 'Update Kontrak: ' . $this->contract->contract_number,
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contract-lifecycle',
            with: [
                'contract'      => $this->contract,
                'event'         => $this->event,
                'recipientName' => $this->recipientName,
                'actionUrl'     => $this->actionUrl,
                'headline'      => $this->headline(),
                'body'          => $this->body(),
                'ctaLabel'      => $this->ctaLabel(),
            ],
        );
    }

    private function headline(): string
    {
        return match ($this->event) {
            'contract.ready_for_karyawan' => 'Kontrak Kerja Siap Anda Tanda Tangani',
            'contract.ready_for_direktur' => 'Kontrak Menunggu Persetujuan Anda',
            'contract.materai_needed'     => 'Kontrak Sudah Ditandatangani Semua Pihak',
            'contract.archived'           => 'Kontrak Kerja Anda Telah Final',
            default                       => 'Update Status Kontrak',
        };
    }

    private function body(): string
    {
        return match ($this->event) {
            'contract.ready_for_karyawan' => 'HRD telah memparaf kontrak Anda. Silakan buka portal untuk membaca isi dan menandatangani.',
            'contract.ready_for_direktur' => 'Karyawan telah menandatangani kontrak. Mohon Bapak/Ibu Direktur mereview dan menandatangani sebagai persetujuan final.',
            'contract.materai_needed'     => 'Kontrak telah ditandatangani oleh karyawan dan Direktur. Silakan download PDF, tempel e-materai, lalu upload versi finalnya.',
            'contract.archived'           => 'Kontrak Anda telah diarsipkan setelah proses e-materai selesai. Anda dapat mengunduh salinan resminya di portal.',
            default                       => 'Ada update pada kontrak ' . $this->contract->contract_number . '. Silakan cek portal untuk detail.',
        };
    }

    private function ctaLabel(): string
    {
        return match ($this->event) {
            'contract.ready_for_karyawan' => 'Buka & Tanda Tangan',
            'contract.ready_for_direktur' => 'Review & Tanda Tangan',
            'contract.materai_needed'     => 'Download PDF Kontrak',
            'contract.archived'           => 'Buka Kontrak',
            default                       => 'Buka Portal',
        };
    }
}

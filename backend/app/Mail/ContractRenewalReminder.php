<?php

namespace App\Mail;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractRenewalReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Contract $contract,
        public int $daysBeforeEnd,
        public string $recipientName,
        public bool $forHrd = false,
    ) {}

    public function envelope(): Envelope
    {
        $urgency = match (true) {
            $this->daysBeforeEnd <= 7 => '🚨 URGENT',
            $this->daysBeforeEnd <= 14 => '⚠️',
            default => '📅',
        };
        $subject = "{$urgency} Kontrak {$this->contract->contract_number} berakhir dalam {$this->daysBeforeEnd} hari";
        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contract-renewal-reminder',
            with: [
                'contract' => $this->contract,
                'daysBeforeEnd' => $this->daysBeforeEnd,
                'recipientName' => $this->recipientName,
                'forHrd' => $this->forHrd,
                'endDate' => $this->contract->end_date?->translatedFormat('l, j F Y'),
            ],
        );
    }
}

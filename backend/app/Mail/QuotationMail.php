<?php

namespace App\Mail;

use App\Models\Quotation;
use App\Services\QuotationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class QuotationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quotation $quote,
        public string $bodyText,
        public string $subjectLine,
        public string $format = 'both', // 'rab' | 'letter' | 'both'
        public ?string $publicLink = null,
    ) {}

    public function build()
    {
        $service = app(QuotationService::class);
        $sections = $service->groupedSections($this->quote);
        $company = [
            'name'    => 'PT INTEGRASI BISNIS EKSEKUTIF',
            'brand'   => 'IBE Reality',
            'address' => 'Surabaya, Jawa Timur',
            'email'   => 'info@ibereality.com',
            'website' => 'ibereality.com',
        ];

        $mail = $this->subject($this->subjectLine)
            ->view('emails.quotation', [
                'quote'      => $this->quote,
                'bodyText'   => $this->bodyText,
                'publicLink' => $this->publicLink,
                'company'    => $company,
            ]);

        // Attach RAB PDF
        if (in_array($this->format, ['rab', 'both'], true)) {
            $pdfRab = Pdf::loadView('pdf.rab', [
                'q'        => $this->quote,
                'sections' => $sections,
                'company'  => $company,
            ])->setPaper('A4', 'portrait');
            $rabName = strtoupper(str_replace('/', '-', $this->quote->quote_number)) . '-RAB.pdf';
            $mail->attachData($pdfRab->output(), $rabName, ['mime' => 'application/pdf']);
        }

        // Attach Surat Penawaran PDF
        if (in_array($this->format, ['letter', 'both'], true)) {
            $pdfLetter = Pdf::loadView('pdf.surat-penawaran', [
                'q'        => $this->quote,
                'sections' => $sections,
                'company'  => $company,
            ])->setPaper('A4', 'portrait');
            $letterName = strtoupper(str_replace('/', '-', $this->quote->quote_number)) . '-PENAWARAN.pdf';
            $mail->attachData($pdfLetter->output(), $letterName, ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}

<?php

namespace App\Mail;

use App\Helpers;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PayrollSlipMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $slip;
    public string $fileName;
    public string $employeeName;

    public function __construct(array $slip, string $fileName, string $employeeName)
    {
        $this->slip = $slip;
        $this->fileName = $fileName;
        $this->employeeName = $employeeName;
    }

    public function build()
    {
        Helpers::increasePdfMemoryLimit();

        $pdf = Pdf::loadView('admin.payroll.slip', $this->slip)
            ->setPaper('a4', 'portrait');

        return $this->subject('Slip Gaji Karyawan - ' . $this->employeeName)
            ->view('emails.payroll-slip', $this->slip)
            ->attachData($pdf->output(), $this->fileName, [
                'mime' => 'application/pdf',
            ]);
    }
}

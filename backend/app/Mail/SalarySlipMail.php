<?php

namespace App\Mail;

use App\Helpers;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Barryvdh\DomPDF\Facade\Pdf;

class SalarySlipMail extends Mailable
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

        $pdf = Pdf::loadView('admin.employees.salary-slip', $this->slip)
            ->setPaper('a4', 'portrait');
        return $this->subject('SLIP Gaji Karyawan - ' . $this->employeeName)
            ->view('emails.salary-slip', $this->slip)
            ->attachData($pdf->output(), $this->fileName, [
                'mime' => 'application/pdf',
            ]);
    }
}

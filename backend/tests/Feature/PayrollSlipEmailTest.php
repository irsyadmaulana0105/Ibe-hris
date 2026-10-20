<?php

use App\Jobs\SendPayrollSlipEmail;
use App\Mail\PayrollSlipMail;
use App\Models\Company;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

it('dispatches payroll slip email to employee with internal bcc recipients', function () {
    Config::set('mail.default', 'smtp');
    Config::set('mail.from.address', 'hrd@ibereality.com');
    Config::set('services.holiday_api.enabled', false);

    $company = Company::create([
        'name' => 'IBE Reality',
        // Bukan 'ibe-reality': slug itu sudah dipakai perusahaan bawaan hasil migrasi.
        'slug' => 'ibe-reality-slip',
        'finance_email' => 'finance@ibereality.test',
        'is_active' => true,
    ]);

    // Kirim slip gaji butuh payroll.pay/payroll.manage (peran Keuangan); HR (admin)
    // hanya payroll.submit.
    $requester = User::factory()->admin()->create([
        'company_id' => $company->id,
        'email' => 'hrd@ibereality.test',
        'group' => 'finance',
    ]);
    $token = $requester->createToken('test', ['admin', 'employee'])->plainTextToken;

    $employee = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'karyawan@ibereality.test',
    ]);

    $payroll = Payroll::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'user_id' => $employee->id,
        'period_start' => '2026-03-28',
        'period_end' => '2026-04-27',
        'month' => 4,
        'year' => 2026,
        'status' => 'approved',
    ]);

    Bus::fake();

    // Dulu lewat komponen Livewire admin yang sudah dihapus; kini endpoint API SPA.
    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/send-slip-email", [], bearerHeaders($token))
        ->assertOk();

    Bus::assertDispatchedAfterResponse(SendPayrollSlipEmail::class, function (SendPayrollSlipEmail $job) {
        return $job->recipientEmail === 'karyawan@ibereality.test'
            && $job->bccEmails === ['finance@ibereality.test', 'hrd@ibereality.test']
            && $job->requestedByEmail === 'hrd@ibereality.test';
    });
});

it('sends payroll slip email to employee and bccs internal recipients', function () {
    $company = Company::create([
        'name' => 'IBE Reality',
        'slug' => 'ibe-reality-mail',
        'finance_email' => 'finance@ibereality.test',
        'is_active' => true,
    ]);

    $employee = User::factory()->create([
        'company_id' => $company->id,
        'name' => 'Aji Sapta Pramulen',
        'email' => 'karyawan@ibereality.test',
    ]);

    $payroll = Payroll::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'user_id' => $employee->id,
        'payroll_number' => 'GJ/2026/04/99962',
        'period_start' => '2026-03-28',
        'period_end' => '2026-04-27',
        'month' => 4,
        'year' => 2026,
        'status' => 'approved',
    ]);

    Mail::fake();

    $job = new SendPayrollSlipEmail(
        $payroll->id,
        'karyawan@ibereality.test',
        ['finance@ibereality.test', 'finance@ibereality.test', 'hrd@ibereality.test'],
        'hrd@ibereality.test'
    );

    $job->handle();

    Mail::assertSent(PayrollSlipMail::class, function (PayrollSlipMail $mail) {
        return $mail->hasTo('karyawan@ibereality.test')
            && $mail->hasBcc('finance@ibereality.test')
            && $mail->hasBcc('hrd@ibereality.test');
    });
});

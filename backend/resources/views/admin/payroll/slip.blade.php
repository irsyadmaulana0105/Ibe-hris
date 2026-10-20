<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 11px;
      color: #1a1a1a;
      background: #fff;
    }

    .page { padding: 20px 24px; }

    /* ── Header ── */
    .header { display: flex; align-items: center; border-bottom: 2px solid #1e3a5f; padding-bottom: 10px; margin-bottom: 12px; }
    .header-logo { width: 50px; margin-right: 12px; }
    .header-info .company-name { font-size: 14px; font-weight: 700; color: #1e3a5f; }
    .header-info .company-address { font-size: 10px; color: #555; margin-top: 2px; }
    .header-title { margin-left: auto; text-align: right; }
    .header-title .doc-title { font-size: 15px; font-weight: 700; color: #1e3a5f; letter-spacing: 0.5px; }
    .header-title .doc-no { font-size: 10px; color: #777; margin-top: 2px; }

    /* ── Employee Info ── */
    .info-grid { display: table; width: 100%; margin-bottom: 12px; }
    .info-col { display: table-cell; width: 50%; vertical-align: top; }
    .info-table { width: 100%; border-collapse: collapse; }
    .info-table td { padding: 2px 4px; font-size: 11px; }
    .info-table .lbl { width: 38%; color: #555; }
    .info-table .sep { width: 4%; text-align: center; }
    .info-table .val { font-weight: 500; }

    /* ── Section titles ── */
    .section-title {
      background: #1e3a5f;
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      padding: 4px 8px;
      letter-spacing: 0.5px;
      margin-bottom: 0;
    }

    /* ── Earnings / Deductions tables ── */
    .two-col { display: table; width: 100%; border: 1px solid #ddd; margin-bottom: 10px; }
    .col-half { display: table-cell; width: 50%; vertical-align: top; }
    .col-half + .col-half { border-left: 1px solid #ddd; }

    .items-table { width: 100%; border-collapse: collapse; }
    .items-table td { padding: 3px 8px; font-size: 10.5px; }
    .items-table tr:nth-child(even) td { background: #f7f9fc; }
    .items-table .desc { color: #333; }
    .items-table .amt { text-align: right; font-family: monospace; white-space: nowrap; }
    .items-table .amt-zero { color: #bbb; }
    .items-table .total-row td { border-top: 1px solid #1e3a5f; font-weight: 700; padding-top: 5px; }
    .items-table .deduct { color: #c0392b; }

    /* ── Net total ── */
    .net-box {
      border: 2px solid #1e3a5f;
      border-radius: 4px;
      padding: 8px 14px;
      margin: 8px 0 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .net-label { font-size: 12px; font-weight: 700; color: #1e3a5f; }
    .net-amount { font-size: 16px; font-weight: 700; color: #1e3a5f; font-family: monospace; }
    .net-terbilang { font-size: 9px; color: #666; margin-top: 2px; font-style: italic; }

    /* ── Signatures ── */
    .sig-row { display: table; width: 100%; margin-top: 6px; }
    .sig-cell { display: table-cell; width: 33.33%; text-align: center; vertical-align: bottom; padding: 0 8px; }
    .sig-title { font-size: 10px; color: #555; margin-bottom: 4px; }
    .sig-img-wrap { height: 55px; display: flex; align-items: flex-end; justify-content: center; }
    .sig-img { max-height: 50px; max-width: 130px; }
    .sig-line { border-top: 1px solid #333; width: 80%; margin: 4px auto 0; }
    .sig-name { font-size: 10.5px; font-weight: 600; margin-top: 3px; }

    .footer-note {
      margin-top: 12px;
      border-top: 1px solid #eee;
      padding-top: 6px;
      font-size: 9px;
      color: #999;
      text-align: center;
    }

    .attendance-bar {
      background: #e8f0fe;
      border-radius: 3px;
      padding: 5px 10px;
      margin-bottom: 10px;
      display: flex;
      gap: 20px;
      font-size: 10px;
    }
    .attendance-bar .stat { display: inline-block; }
    .attendance-bar .stat-val { font-weight: 700; color: #1e3a5f; }
  </style>
</head>
<body>
  <div class="page">

    {{-- Header --}}
    <div class="header">
      <div class="header-info">
        <div class="company-name">{{ $companyName }}</div>
        @if ($companyAddress)
          <div class="company-address">{{ $companyAddress }}</div>
        @endif
      </div>
      <div class="header-title">
        <div class="doc-title">SLIP GAJI KARYAWAN</div>
        <div class="doc-no">No: {{ $payroll->payroll_number ?? '-' }}</div>
        <div class="doc-no">Periode: {{ $periodLabel }}</div>
      </div>
    </div>

    {{-- Employee Info --}}
    <div class="info-grid">
      <div class="info-col" style="padding-right:10px;">
        <table class="info-table">
          <tr>
            <td class="lbl">Nama Karyawan</td>
            <td class="sep">:</td>
            <td class="val">{{ $user->name }}</td>
          </tr>
          <tr>
            <td class="lbl">NIP</td>
            <td class="sep">:</td>
            <td class="val">{{ $user->nip ?? '-' }}</td>
          </tr>
          <tr>
            <td class="lbl">Status</td>
            <td class="sep">:</td>
            <td class="val">{{ \App\Models\User::EMPLOYMENT_STATUSES[$user->employment_status] ?? '-' }}</td>
          </tr>
          <tr>
            <td class="lbl">Tanggal Bergabung</td>
            <td class="sep">:</td>
            <td class="val">{{ $joinDate }}</td>
          </tr>
        </table>
      </div>
      <div class="info-col" style="padding-left:10px;">
        <table class="info-table">
          <tr>
            <td class="lbl">Divisi</td>
            <td class="sep">:</td>
            <td class="val">{{ $user->division?->name ?? '-' }}</td>
          </tr>
          <tr>
            <td class="lbl">Jabatan</td>
            <td class="sep">:</td>
            <td class="val">{{ $user->jobTitle?->name ?? '-' }}</td>
          </tr>
          <tr>
            <td class="lbl">Rekening</td>
            <td class="sep">:</td>
            <td class="val">{{ $user->bank_account ?? '-' }}</td>
          </tr>
          <tr>
            <td class="lbl">Kehadiran</td>
            <td class="sep">:</td>
            <td class="val">{{ $payroll->attendance_count }} hari ({{ $payroll->attendance_percentage }}%)</td>
          </tr>
        </table>
      </div>
    </div>

    {{-- Attendance summary bar --}}
    <div class="attendance-bar">
      <span class="stat">Hadir: <span class="stat-val">{{ $payroll->attendance_count }}</span></span>
      <span class="stat">Terlambat: <span class="stat-val">{{ $payroll->late_count }}</span></span>
      <span class="stat">Mangkir: <span class="stat-val">{{ $payroll->absent_count }}</span></span>
      <span class="stat">Izin/Sakit: <span class="stat-val">{{ $payroll->leave_count }}</span></span>
      <span class="stat">Lembur: <span class="stat-val">{{ $payroll->overtime_count }} jam</span></span>
    </div>

    {{-- Earnings + Deductions --}}
    <div class="two-col">
      {{-- Earnings --}}
      <div class="col-half">
        <div class="section-title">PENGHASILAN</div>
        <table class="items-table">
          <tr>
            @if($payroll->payroll_basis === 'daily')
              {{-- Training karyawan: tampil breakdown daily_rate × hari --}}
              <td class="desc">Gaji Training ({{ $payroll->attendance_count }} hari × Rp {{ number_format($payroll->daily_rate_snapshot ?? 0, 0, ',', '.') }})</td>
              <td class="amt">Rp {{ number_format($payroll->base_salary, 0, ',', '.') }}</td>
            @else
              <td class="desc">Gaji Pokok</td>
              <td class="amt">Rp {{ number_format($payroll->base_salary, 0, ',', '.') }}</td>
            @endif
          </tr>
          <tr>
            <td class="desc">Uang Makan & Transport ({{ $payroll->attendance_count }} hr)</td>
            <td class="amt {{ $payroll->meal_transport_total == 0 ? 'amt-zero' : '' }}">
              Rp {{ number_format($payroll->meal_transport_total, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <td class="desc">Lembur ({{ $payroll->overtime_count }} jam)</td>
            <td class="amt {{ $payroll->overtime_total == 0 ? 'amt-zero' : '' }}">
              Rp {{ number_format($payroll->overtime_total, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <td class="desc">Tunjangan Kehadiran</td>
            <td class="amt {{ $payroll->attendance_total == 0 ? 'amt-zero' : '' }}">
              Rp {{ number_format($payroll->attendance_total, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <td class="desc">Bonus</td>
            <td class="amt {{ $payroll->bonus_total == 0 ? 'amt-zero' : '' }}">
              Rp {{ number_format($payroll->bonus_total, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <td class="desc">THR</td>
            <td class="amt {{ $payroll->thr_total == 0 ? 'amt-zero' : '' }}">
              Rp {{ number_format($payroll->thr_total, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <td class="desc">Reimbursement</td>
            <td class="amt {{ $payroll->reimbursement_total == 0 ? 'amt-zero' : '' }}">
              Rp {{ number_format($payroll->reimbursement_total, 0, ',', '.') }}
            </td>
          </tr>
          @foreach ($customEarnings ?? [] as $ce)
            <tr>
              <td class="desc">
                {{ $ce['name'] }}
                @if (!empty($ce['skipped_by_threshold']))
                  <span style="color:#94a3b8; font-size:9px;">(tidak memenuhi syarat min hari)</span>
                @endif
              </td>
              <td class="amt {{ $ce['value'] == 0 ? 'amt-zero' : '' }}">
                Rp {{ number_format($ce['value'], 0, ',', '.') }}
              </td>
            </tr>
          @endforeach
          <tr class="total-row">
            <td class="desc" style="font-size:11px;">Total Penghasilan</td>
            <td class="amt" style="font-size:11px;">
              Rp {{ number_format($payroll->gross_total, 0, ',', '.') }}
            </td>
          </tr>
        </table>
      </div>

      {{-- Deductions --}}
      <div class="col-half">
        <div class="section-title">POTONGAN</div>
        <table class="items-table">
          <tr>
            <td class="desc">Terlambat ({{ $payroll->late_count }}×)</td>
            <td class="amt deduct {{ $payroll->late_total == 0 ? 'amt-zero' : '' }}">
              Rp {{ number_format($payroll->late_total, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <td class="desc">Mangkir ({{ $payroll->absent_count }}×)</td>
            <td class="amt deduct {{ $payroll->absent_total == 0 ? 'amt-zero' : '' }}">
              Rp {{ number_format($payroll->absent_total, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <td class="desc">Izin/Sakit ({{ $payroll->leave_count }}×)</td>
            <td class="amt deduct {{ $payroll->leave_total == 0 ? 'amt-zero' : '' }}">
              Rp {{ number_format($payroll->leave_total, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <td class="desc">Cicilan Kasbon</td>
            <td class="amt deduct {{ $payroll->salary_advance_payment == 0 ? 'amt-zero' : '' }}">
              Rp {{ number_format($payroll->salary_advance_payment, 0, ',', '.') }}
            </td>
          </tr>
          @foreach ($customDeductions ?? [] as $cd)
            <tr>
              <td class="desc">
                {{ $cd['name'] }}
                @if (!empty($cd['skipped_by_threshold']))
                  <span style="color:#94a3b8; font-size:9px;">(syarat min hari belum tercapai)</span>
                @endif
              </td>
              <td class="amt deduct {{ $cd['value'] == 0 ? 'amt-zero' : '' }}">
                Rp {{ number_format($cd['value'], 0, ',', '.') }}
              </td>
            </tr>
          @endforeach
          <tr class="total-row">
            <td class="desc" style="font-size:11px;">Total Potongan</td>
            <td class="amt deduct" style="font-size:11px;">
              Rp {{ number_format($payroll->deduction_total, 0, ',', '.') }}
            </td>
          </tr>

          {{-- Salary advance balance info --}}
          @if ($payroll->salary_advance_balance > 0)
          <tr>
            <td colspan="2" style="padding-top:10px; font-size:9.5px; color:#888;">
              Saldo Kasbon sebelumnya:
              Rp {{ number_format($payroll->salary_advance_balance, 0, ',', '.') }}<br>
              Sisa setelah bayar:
              Rp {{ number_format(max(0, $payroll->salary_advance_balance - $payroll->salary_advance_payment), 0, ',', '.') }}
            </td>
          </tr>
          @endif
        </table>
      </div>
    </div>

    {{-- Net total --}}
    <div class="net-box">
      <div>
        <div class="net-label">TOTAL GAJI BERSIH</div>
        <div class="net-terbilang">{{ $periodLabel }}</div>
      </div>
      <div style="text-align:right;">
        <div class="net-amount">Rp {{ number_format($payroll->net_total, 0, ',', '.') }}</div>
        <div class="net-terbilang">
          Status: {{ strtoupper($payroll->status) }}
        </div>
      </div>
    </div>

    {{-- Signatures --}}
    <div class="sig-row">
      <div class="sig-cell">
        <div class="sig-title">Penerima</div>
        <div class="sig-img-wrap"></div>
        <div class="sig-line"></div>
        <div class="sig-name">{{ $user->name }}</div>
      </div>
      <div class="sig-cell">
        <div class="sig-title">Mengetahui<br>Direktur HRD</div>
        <div class="sig-img-wrap">
          @if ($hrdSignaturePath)
            <img class="sig-img" src="{{ $hrdSignaturePath }}" alt="TTD HRD">
          @endif
        </div>
        <div class="sig-line"></div>
        <div class="sig-name">{{ $hrdName }}</div>
      </div>
      <div class="sig-cell">
        <div class="sig-title">Menyetujui<br>Direktur Keuangan</div>
        <div class="sig-img-wrap">
          @if ($financeSignaturePath)
            <img class="sig-img" src="{{ $financeSignaturePath }}" alt="TTD Keuangan">
          @endif
        </div>
        <div class="sig-line"></div>
        <div class="sig-name">{{ $financeName }}</div>
      </div>
    </div>

    <div class="footer-note">
      Dokumen ini dicetak secara otomatis oleh sistem. &bull;
      {{ $companyName }} &bull; {{ $periodLabel }}
    </div>

  </div>
</body>
</html>

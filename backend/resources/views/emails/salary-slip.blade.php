<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color:#111; font-size:14px;">
  <div style="border:1px solid #ddd; padding:16px;">
    <div style="text-align:center;">
      <div style="margin-bottom:6px;">
        <img src="{{ asset('images/logo.png') }}" alt="Logo" style="height:50px;">
      </div>
      <div style="font-weight:700;">{{ $companyName }}</div>
      @if ($companyAddress)
        <div style="color:#555; font-size:12px;">{{ $companyAddress }}</div>
      @endif
      <div style="margin-top:6px; font-weight:700;">SLIP GAJI KARYAWAN</div>
    </div>

    <div style="margin-top:12px; border-top:1px solid #ddd; padding-top:10px;">
      <table width="100%" cellpadding="4" cellspacing="0">
        <tr>
          <td width="35%">Bulan</td><td>: {{ $periodLabel }}</td>
          <td width="25%">Bagian</td><td>: {{ $user->division?->name ?? '-' }}</td>
        </tr>
        <tr>
          <td>Nama Karyawan</td><td>: {{ $user->name }}</td>
          <td>Jabatan</td><td>: {{ $user->jobTitle?->name ?? '-' }}</td>
        </tr>
        <tr>
          <td>Status</td><td>: {{ App\Models\User::EMPLOYMENT_STATUSES[$user->employment_status] ?? '-' }}</td>
          <td>Periode Masuk</td><td>: {{ $joinDate }}</td>
        </tr>
      </table>
    </div>

    <div style="margin-top:12px; border-top:1px solid #ddd; padding-top:10px;">
      <table width="100%" cellpadding="4" cellspacing="0">
        <tr>
          <td width="50%" valign="top">
            <strong>Income</strong>
            <table width="100%" cellpadding="4" cellspacing="0">
              <tr><td>Gaji Pokok</td><td align="right">Rp {{ number_format($salary, 0, ',', '.') }}</td></tr>
              <tr><td>Uang Bonus Absen dan Lain-Lain</td><td align="right">Rp {{ number_format($bonus, 0, ',', '.') }}</td></tr>
              <tr><td><strong>Jumlah</strong></td><td align="right"><strong>Rp {{ number_format($total, 0, ',', '.') }}</strong></td></tr>
            </table>
          </td>
          <td width="50%" valign="top">
            <strong>Keterangan</strong>
            <table width="100%" cellpadding="4" cellspacing="0">
              <tr><td>Nominal sesuai UMR</td><td align="right">Rp {{ number_format($salary, 0, ',', '.') }}</td></tr>
              <tr><td>Kehadiran</td><td align="right">{{ $attendanceCount }} hari</td></tr>
              <tr><td>Hari Libur</td><td align="right">{{ $holidayCount }} hari</td></tr>
            </table>
          </td>
        </tr>
      </table>
    </div>

    <div style="margin-top:16px; border-top:1px solid #ddd; padding-top:12px; text-align:center;">
      <table width="100%" cellpadding="4" cellspacing="0">
        <tr>
          <td>Mengetahui<br>Direktur HRD</td>
          <td>Menyetujui<br>Direktur Keuangan</td>
        </tr>
        <tr>
          <td style="height:60px;">
            @if ($hrdSignatureUrl)
              <img src="{{ $hrdSignatureUrl }}" alt="TTD HRD" style="height:50px;">
            @endif
          </td>
          <td style="height:60px;">
            @if ($financeSignatureUrl)
              <img src="{{ $financeSignatureUrl }}" alt="TTD Keuangan" style="height:50px;">
            @endif
          </td>
        </tr>
        <tr>
          <td>{{ $hrdName }}</td>
          <td>{{ $financeName }}</td>
        </tr>
      </table>
    </div>
  </div>
</body>
</html>

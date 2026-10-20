<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color:#111; font-size:14px; line-height:1.6;">
  <div style="max-width:640px; margin:0 auto; border:1px solid #e5e7eb; padding:20px; border-radius:12px;">
    <h2 style="margin:0 0 12px; font-size:20px; color:#0f172a;">Slip Gaji Karyawan</h2>

    <p style="margin:0 0 12px;">
      Halo {{ $user->name }},
    </p>

    <p style="margin:0 0 12px;">
      Slip gaji untuk periode <strong>{{ $periodLabel }}</strong> terlampir dalam email ini dalam format PDF.
    </p>

    <table style="width:100%; border-collapse:collapse; margin:16px 0;">
      <tr>
        <td style="padding:8px 0; color:#64748b;">Perusahaan</td>
        <td style="padding:8px 0; text-align:right;"><strong>{{ $companyName }}</strong></td>
      </tr>
      <tr>
        <td style="padding:8px 0; color:#64748b;">Divisi</td>
        <td style="padding:8px 0; text-align:right;"><strong>{{ $user->division?->name ?? '-' }}</strong></td>
      </tr>
      <tr>
        <td style="padding:8px 0; color:#64748b;">Jabatan</td>
        <td style="padding:8px 0; text-align:right;"><strong>{{ $user->jobTitle?->name ?? '-' }}</strong></td>
      </tr>
      <tr>
        <td style="padding:8px 0; color:#64748b;">Total Gaji Bersih</td>
        <td style="padding:8px 0; text-align:right; font-size:16px;"><strong>Rp {{ number_format($payroll->net_total, 0, ',', '.') }}</strong></td>
      </tr>
      <tr>
        <td style="padding:8px 0; color:#64748b;">Status Payroll</td>
        <td style="padding:8px 0; text-align:right;"><strong>{{ strtoupper($payroll->status) }}</strong></td>
      </tr>
    </table>

    <p style="margin:16px 0 0; color:#475569;">
      Jika ada pertanyaan terkait slip gaji ini, silakan hubungi tim HRD atau Keuangan.
    </p>
  </div>
</body>
</html>

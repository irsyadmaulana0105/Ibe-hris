<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Kontrak Akan Berakhir</title>
<style>
  body { font-family: 'Helvetica Neue', Arial, sans-serif; background:#f8fafc; margin:0; padding:24px; color:#1e293b; }
  .container { max-width: 560px; margin: 0 auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(15,23,42,.08); }
  .header { padding: 24px 32px; color:#fff; }
  .h-30 { background: linear-gradient(135deg,#f59e0b 0%,#eab308 100%); }
  .h-14 { background: linear-gradient(135deg,#ea580c 0%,#f59e0b 100%); }
  .h-7 { background: linear-gradient(135deg,#dc2626 0%,#ea580c 100%); }
  .header h1 { margin:0; font-size: 20px; font-weight: 700; }
  .body { padding: 24px 32px; line-height: 1.6; font-size: 14px; }
  .details { margin: 16px 0; padding: 14px 16px; background:#f1f5f9; border-radius:8px; font-size: 13px; }
  .details td:first-child { color:#64748b; padding-right: 12px; width: 160px; }
  .footer { text-align:center; padding: 16px 32px 24px; font-size:11px; color:#94a3b8; }
  .cta { display: inline-block; margin: 16px 0; padding: 12px 24px; background:#0f766e; color:#fff !important; text-decoration:none; border-radius:8px; font-weight:600; }
</style>
</head>
<body>
  <div class="container">
    <div class="header {{ $daysBeforeEnd <= 7 ? 'h-7' : ($daysBeforeEnd <= 14 ? 'h-14' : 'h-30') }}">
      <h1>Kontrak Akan Berakhir dalam {{ $daysBeforeEnd }} Hari</h1>
    </div>
    <div class="body">
      <p>Halo <strong>{{ $recipientName }}</strong>,</p>
      @if($forHrd)
        <p>Ini reminder untuk HRD: kontrak berikut akan berakhir dalam <strong>{{ $daysBeforeEnd }} hari</strong>. Mohon disiapkan perpanjangan kontrak atau proses off-boarding sesuai kebijakan perusahaan.</p>
      @else
        <p>Kami informasikan bahwa kontrak kerja Anda akan berakhir dalam <strong>{{ $daysBeforeEnd }} hari</strong> ke depan. HRD akan menghubungi Anda untuk pembahasan perpanjangan atau proses selanjutnya.</p>
      @endif

      <table class="details" cellspacing="0">
        <tr><td>Nomor Kontrak</td><td><strong>{{ $contract->contract_number }}</strong></td></tr>
        <tr><td>Karyawan</td><td>{{ $contract->user?->name ?? '-' }}</td></tr>
        <tr><td>Jenis Kontrak</td><td>{{ $contract->contract_type }}</td></tr>
        <tr><td>Mulai</td><td>{{ optional($contract->start_date)->format('d M Y') }}</td></tr>
        <tr><td>Berakhir</td><td style="color:#dc2626;font-weight:700;">{{ $endDate }}</td></tr>
      </table>

      @if($forHrd)
        <p style="margin-top:16px;">
          <a href="{{ config('app.url') }}/admin/contracts" class="cta">Buka Kelola Kontrak</a>
        </p>
      @endif

      <p style="color:#64748b; font-size:12px; margin-top:24px;">
        Reminder ini dikirim otomatis oleh sistem HR IBE Reality.
      </p>
    </div>
    <div class="footer">
      IBE Reality - Sistem Kontrak Kerja Digital<br>
      Email otomatis, jangan reply.
    </div>
  </div>
</body>
</html>

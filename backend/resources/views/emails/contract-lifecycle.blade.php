<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>{{ $headline }}</title>
<style>
  body { font-family: 'Helvetica Neue', Arial, sans-serif; background:#f8fafc; margin:0; padding:24px; color:#1e293b; }
  .container { max-width: 560px; margin: 0 auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(15,23,42,.08); }
  .header { background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%); color:#fff; padding: 28px 32px; }
  .header h1 { margin:0; font-size: 20px; font-weight: 700; }
  .body { padding: 24px 32px; line-height: 1.6; font-size: 14px; }
  .body p { margin: 8px 0; }
  .cta { display: inline-block; margin: 20px 0 12px; padding: 12px 24px; background:#0f766e; color:#ffffff !important; text-decoration:none; border-radius:8px; font-weight:600; font-size: 14px; }
  .details { margin: 16px 0; padding: 14px 16px; background:#f1f5f9; border-radius:8px; font-size: 13px; }
  .details td:first-child { color:#64748b; padding-right: 12px; width: 140px; }
  .footer { text-align:center; padding: 16px 32px 24px; font-size:11px; color:#94a3b8; }
</style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>{{ $headline }}</h1>
    </div>
    <div class="body">
      <p>Halo <strong>{{ $recipientName }}</strong>,</p>
      <p>{{ $body }}</p>

      <table class="details" cellspacing="0">
        <tr><td>Nomor Kontrak</td><td><strong>{{ $contract->contract_number }}</strong></td></tr>
        <tr><td>Jenis</td><td>{{ $contract->contract_type }}</td></tr>
        <tr><td>Tanggal Terbit</td><td>{{ optional($contract->issued_at)->format('d M Y') }}</td></tr>
      </table>

      @if($actionUrl)
        <p style="margin-top:20px;">
          <a href="{{ $actionUrl }}" class="cta">{{ $ctaLabel }}</a>
        </p>
      @endif

      <p style="color:#64748b; font-size:12px; margin-top:24px;">
        Kalau Anda menerima email ini di luar konteks, mohon abaikan atau hubungi HR.
      </p>
    </div>
    <div class="footer">
      IBE Reality - Sistem Kontrak Kerja Digital<br>
      Email otomatis, jangan reply.
    </div>
  </div>
</body>
</html>

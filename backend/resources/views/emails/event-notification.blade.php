<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>{{ $eventLabel }}</title>
</head>
<body style="margin:0; padding:0; background:#f7f9fc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color:#0f172a;">
  <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="padding:40px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="background:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 4px 24px rgba(15,23,42,.06);">
          <tr>
            <td style="background:linear-gradient(135deg,#1a7a8c,#0d5a6a); padding:24px; color:#ffffff;">
              <div style="font-size:11px; letter-spacing:.12em; text-transform:uppercase; opacity:.7;">IBE Reality · HRIS</div>
              <h1 style="margin:8px 0 0; font-size:22px; font-weight:800;">{{ $eventLabel }}</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 32px 16px;">
              <p style="margin:0 0 12px; font-size:14px;">Halo <strong>{{ $recipientName }}</strong>,</p>
              <div style="font-size:14px; line-height:1.7; white-space:pre-wrap;">{!! nl2br(e($body)) !!}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 28px;">
              <a href="https://attendance.ibereality.com" style="display:inline-block; background:#1a7a8c; color:#ffffff; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:13px;">
                Buka Portal Karyawan →
              </a>
            </td>
          </tr>
          <tr>
            <td style="background:#f7f9fc; padding:16px 32px; font-size:11px; color:#94a3b8; border-top:1px solid #e5e9ef;">
              Email otomatis dari IBE Reality HRIS. Jangan reply email ini.<br>
              © {{ date('Y') }} PT Integrasi Bisnis Eksekutif
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>

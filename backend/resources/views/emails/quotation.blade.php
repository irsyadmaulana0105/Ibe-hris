<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>{{ $quote->quote_number }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #1f2937; max-width: 600px; margin: 0 auto; padding: 20px;">
  <div style="border-bottom: 3px double #0f766e; padding-bottom: 12px; margin-bottom: 20px;">
    <div style="font-size: 20px; font-weight: 800; color: #0f766e;">{{ $company['brand'] }}</div>
    <div style="font-size: 12px; color: #64748b;">{{ $company['name'] }} · {{ $company['email'] }}</div>
  </div>

  <div style="background: #f0fdfa; padding: 12px; border-left: 4px solid #0f766e; border-radius: 4px; margin-bottom: 16px;">
    <div style="font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.1em;">No. Penawaran</div>
    <div style="font-family: monospace; font-weight: 700; font-size: 16px; color: #0f766e;">{{ $quote->quote_number }}</div>
  </div>

  <div style="white-space: pre-line; margin-bottom: 16px;">{{ $bodyText }}</div>

  <table style="width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px;">
    <tr>
      <td style="padding: 6px; border-bottom: 1px solid #e2e8f0; color: #64748b;">Judul</td>
      <td style="padding: 6px; border-bottom: 1px solid #e2e8f0;"><strong>{{ $quote->title }}</strong></td>
    </tr>
    @if ($quote->project_name)
      <tr>
        <td style="padding: 6px; border-bottom: 1px solid #e2e8f0; color: #64748b;">Proyek</td>
        <td style="padding: 6px; border-bottom: 1px solid #e2e8f0;">{{ $quote->project_name }}</td>
      </tr>
    @endif
    <tr>
      <td style="padding: 6px; border-bottom: 1px solid #e2e8f0; color: #64748b;">Tanggal</td>
      <td style="padding: 6px; border-bottom: 1px solid #e2e8f0;">{{ \Carbon\Carbon::parse($quote->quote_date)->translatedFormat('d F Y') }}</td>
    </tr>
    @if ($quote->valid_until)
      <tr>
        <td style="padding: 6px; border-bottom: 1px solid #e2e8f0; color: #64748b;">Berlaku s.d.</td>
        <td style="padding: 6px; border-bottom: 1px solid #e2e8f0;"><strong>{{ \Carbon\Carbon::parse($quote->valid_until)->translatedFormat('d F Y') }}</strong></td>
      </tr>
    @endif
    <tr>
      <td style="padding: 6px; color: #64748b;">Total</td>
      <td style="padding: 6px; font-family: monospace; font-weight: 800; color: #0f766e; font-size: 18px;">
        Rp {{ number_format($quote->total, 0, ',', '.') }}
      </td>
    </tr>
  </table>

  @if ($publicLink)
    <div style="text-align: center; margin: 24px 0;">
      <a href="{{ $publicLink }}" style="display: inline-block; background: #0f766e; color: white; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: 600;">
        Lihat & Setujui Online
      </a>
      <div style="font-size: 11px; color: #64748b; margin-top: 6px;">
        Atau buka link: <a href="{{ $publicLink }}">{{ $publicLink }}</a>
      </div>
    </div>
  @endif

  <p style="color: #64748b; font-size: 12px; margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    Dokumen RAB dan Surat Penawaran lengkap terlampir di email ini (PDF).<br>
    Apabila ada pertanyaan, silakan reply email ini atau hubungi kami di {{ $company['email'] }}.
  </p>

  <div style="margin-top: 24px; font-size: 11px; color: #94a3b8; text-align: center;">
    {{ $company['brand'] }} - {{ $company['website'] }}
  </div>
</body>
</html>

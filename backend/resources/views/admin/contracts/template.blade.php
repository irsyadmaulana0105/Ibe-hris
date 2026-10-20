<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Preview Kontrak - {{ $tplTitle }}</title>
  <style>
    @page { size: A4; margin: 0; }
    body {
      font-family: {{ $letterheadStyle === 'formal' ? "'Times New Roman', 'DejaVu Serif', serif" : "'DejaVu Sans', sans-serif" }};
      color: #000000;
      font-size: 11pt;
      line-height: 1.55;
      margin: 0;
      padding: 45px 55px 40px;
    }

    /* KOP SURAT */
    .letterhead {
      width: 100%;
      margin-bottom: 20px;
      @if($letterheadStyle === 'formal')
      border-bottom: 2px solid #000000;
      padding-bottom: 3px;
      @else
      border-bottom: 3px solid #0f766e;
      padding-bottom: 3px;
      @endif
    }
    .letterhead-inner { display: table; width: 100%; }
    .letterhead-logo {
      display: table-cell;
      width: 110px;
      vertical-align: middle;
      text-align: center;
    }
    .letterhead-logo img { max-width: 100px; max-height: 100px; }
    .letterhead-info { display: table-cell; vertical-align: middle; padding-left: 20px; }
    .letterhead-name {
      @if($letterheadStyle === 'formal')
      font-size: 20pt;
      font-weight: 700;
      color: #000000;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      @else
      font-size: 22pt;
      font-weight: 800;
      color: #0f766e;
      letter-spacing: 0.01em;
      @endif
      margin: 0 0 6px;
      line-height: 1.15;
    }
    .letterhead-addr { font-size: 9pt; color: #000000; line-height: 1.4; }
    .letterhead-addr div { margin: 1px 0; }
    @if($letterheadStyle === 'modern')
    .letterhead-accent { height: 1px; background: #94a3b8; margin-top: 2px; width: 100%; }
    @endif

    /* CONTENT - dari body_html template */
    .contract-body {
      text-align: justify;
      color: #000000;
    }
    .contract-body h1 {
      text-align: center;
      font-size: 14pt;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin: 18px 0 6px;
      text-decoration: underline;
    }
    .contract-body h2 {
      font-size: 12pt;
      font-weight: 700;
      margin: 14px 0 6px;
      text-transform: uppercase;
    }
    .contract-body h3 { font-size: 11pt; font-weight: 700; margin: 10px 0 4px; }
    .contract-body p { margin: 0 0 8px; }
    .contract-body ul, .contract-body ol { margin: 6px 0 10px 20px; padding-left: 8px; }
    .contract-body li { margin: 2px 0; }
    .contract-body table { width: 100%; margin: 8px 0; border-collapse: collapse; }
    .contract-body table td { padding: 3px 6px; vertical-align: top; }
    .contract-body strong { font-weight: 700; }

    /* SIGNATURE BLOCK - keep signature block bersatu dengan closing paragraph,
       jangan sampai TTD terlepas dari isi kontrak di halaman baru. */
    .signature-wrapper {
      width: 100%;
      margin-top: 18px;
      page-break-inside: avoid;
      page-break-before: avoid;
    }
    .signature-table { width: 100%; border-collapse: collapse; page-break-inside: avoid; }
    .signature-cell {
      width: 50%;
      vertical-align: top;
      text-align: center;
      padding: 0 10px;
      page-break-inside: avoid;
    }
    .place-date { font-size: 10pt; margin-bottom: 4px; }
    .signature-role {
      font-size: 10pt;
      font-weight: 600;
      margin-bottom: 6px;
    }
    .stamp-container {
      height: 110px;
      position: relative;
      overflow: visible;
      text-align: center;
      page-break-inside: avoid;
    }
    .stamp-img { height: 95px; width: auto; opacity: 0.75; display: inline-block; vertical-align: top; }
    .signature-img { position: absolute; left: 50%; top: 12px; width: 130px; height: 75px; margin-left: -65px; }
    .signature-name {
      font-weight: 700;
      text-decoration: underline;
      font-size: 11pt;
      margin: 2px 0 2px;
      color: #000000;
    }
    .signature-title { font-size: 10pt; color: #000000; }
    .hrd-paraf { margin-top: 8px; text-align: right; font-size: 8pt; color: #475569; }
    .hrd-paraf img { max-width: 80px; max-height: 40px; vertical-align: middle; margin-left: 6px; }

    /* WATERMARK PREVIEW - customizable per company.
       Dompdf render rgba lebih tebal dari expected, jadi hard-cap opacity
       max 6% supaya readable. Font size dikecilkan supaya tidak overpower body text.
       Default warna slate-gray (netral) alih-alih merah. */
    @php
      $watermarkText = $company?->contract_watermark_text ?? 'PREVIEW';
      $watermarkColor = $company?->contract_watermark_color ?? '#94a3b8';
      $storedOpacity = (int) ($company?->contract_watermark_opacity ?? 5);
      $watermarkOpacity = min(max($storedOpacity, 1), 6) / 100;
      $hex = ltrim($watermarkColor, '#');
      $r = hexdec(substr($hex, 0, 2) ?: '94');
      $g = hexdec(substr($hex, 2, 2) ?: 'a3');
      $b = hexdec(substr($hex, 4, 2) ?: 'b8');
      $watermarkRgba = "rgba({$r},{$g},{$b},{$watermarkOpacity})";
    @endphp
    .watermark {
      position: fixed;
      top: 42%;
      left: 0;
      right: 0;
      text-align: center;
      font-size: 60pt;
      color: {{ $watermarkRgba }};
      font-weight: 900;
      transform: rotate(-25deg);
      z-index: -1;
      letter-spacing: 0.15em;
    }

    /* FOOTER */
    .contract-footer {
      position: fixed;
      bottom: 15px;
      left: 55px;
      right: 55px;
      font-size: 8pt;
      color: #6b7280;
      border-top: 1px solid #d1d5db;
      padding-top: 6px;
      text-align: center;
    }
  </style>
</head>
<body>

  @if($isPreview)
    <div class="watermark">{{ $watermarkText }}</div>
  @endif

  {{-- ═══════════════════ KOP SURAT ═══════════════════ --}}
  <div class="letterhead">
    <div class="letterhead-inner">
      <div class="letterhead-logo">
        @if($companyLogoDataUri)
          <img src="{{ $companyLogoDataUri }}" alt="Logo">
        @endif
      </div>
      <div class="letterhead-info">
        <div class="letterhead-name">{{ $company?->name ?? 'PT IBE Reality' }}</div>
        <div class="letterhead-addr">
          @php
            $streetLine = trim($company?->address ?? '');
            $cityLine = trim(implode(', ', array_filter([
              $company?->district,
              $company?->regency,
              $company?->province,
              $company?->postal_code,
            ])));
          @endphp
          @if($streetLine)<div>{{ $streetLine }}</div>@endif
          @if($cityLine)<div>{{ $cityLine }}</div>@endif
          <div>
            @if($company?->phone) Telp: {{ $company->phone }} @endif
            @if($company?->email) @if($company?->phone) | @endif Email: {{ $company->email }} @endif
          </div>
          @if($company?->website)<div>Website: {{ $company->website }}</div>@endif
          @if($company?->npwp)<div>NPWP: {{ $company->npwp }}</div>@endif
        </div>
      </div>
    </div>
    @if($letterheadStyle === 'modern')
      <div class="letterhead-accent"></div>
    @endif
  </div>

  {{-- ═══════════════════ ISI KONTRAK (dari body_html) ═══════════════════ --}}
  <div class="contract-body">
    {!! $renderedHtml !!}
  </div>

  {{-- ═══════════════════ TTD 2 KOLOM ═══════════════════ --}}
  <div class="signature-wrapper">
    <table class="signature-table">
      <tr>
        <td class="signature-cell">
          <div class="place-date">{{ $placeDate }}</div>
          <div class="signature-role">PIHAK PERTAMA</div>
          <div class="stamp-container">
            @if($stampDataUri)
              <img class="stamp-img" src="{{ $stampDataUri }}" alt="Stempel">
            @endif
            @if($signatureDataUri)
              <img class="signature-img" src="{{ $signatureDataUri }}" alt="TTD">
            @endif
          </div>
          <div class="signature-name">{{ $signerName }}</div>
          <div class="signature-title">{{ $signerTitle }}</div>
        </td>
        <td class="signature-cell">
          <div class="place-date">{{ $placeDate }}</div>
          <div class="signature-role">PIHAK KEDUA</div>
          <div class="stamp-container">
            @if($employeeSignatureDataUri)
              <img class="signature-img" src="{{ $employeeSignatureDataUri }}" alt="TTD Karyawan">
            @endif
          </div>
          <div class="signature-name">{{ $employeeName }}</div>
          <div class="signature-title">Karyawan</div>
        </td>
      </tr>
    </table>
    @if($hrdSignatureDataUri)
      <div class="hrd-paraf">Paraf HRD <img src="{{ $hrdSignatureDataUri }}" alt="Paraf HRD"></div>
    @endif
  </div>

  {{-- ═══════════════════ FOOTER ═══════════════════ --}}
  <div class="contract-footer">
    {{ $tplTitle }} · {{ $company?->name ?? 'PT IBE Reality' }} · {{ now()->format('d M Y H:i') }} WIB
    @if($isPreview) · <strong style="color: #ef4444;">DOKUMEN PREVIEW</strong> @endif
  </div>

</body>
</html>

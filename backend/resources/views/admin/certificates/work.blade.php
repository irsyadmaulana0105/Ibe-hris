<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Surat Keterangan {{ $isFormer ? 'Pernah Bekerja' : 'Kerja' }} - {{ $user->name }}</title>
  <style>
    @page { size: A4; margin: 0; }
    body {
      font-family: {{ $letterheadStyle === 'formal' ? "'Times New Roman', 'DejaVu Serif', serif" : "'DejaVu Sans', sans-serif" }};
      color: #000000;
      font-size: 12pt;
      line-height: 1.6;
      margin: 0;
      padding: 55px 60px 40px;
    }

    /* ═══════════════════ KOP SURAT ═══════════════════ */
    .letterhead {
      width: 100%;
      margin-bottom: 20px;
      /* Style A: single line 2px hitam tebal | Style B: double garis tipis-tebal teal
         Padding-bottom 3px supaya garis mepet ke baris terakhir address (biar
         terlihat kop compact & professional). */
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
    .letterhead-info {
      display: table-cell;
      vertical-align: middle;
      padding-left: 20px;
    }
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
    .letterhead-addr {
      font-size: 10pt;
      color: #000000;
      line-height: 1.45;
    }
    .letterhead-addr div { margin: 1px 0; }

    /* Extra thin accent line di bawah border utama (Modern style) */
    @if($letterheadStyle === 'modern')
    .letterhead-accent {
      height: 1px;
      background: #94a3b8;
      margin-top: 2px;
      width: 100%;
    }
    @endif

    /* ═══════════════════ JUDUL + NOMOR ═══════════════════ */
    .doc-title { text-align: center; margin: 22px 0 6px; }
    .doc-title h1 {
      font-size: 15pt;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin: 0;
      text-decoration: underline;
      color: #000000;
    }
    .doc-number {
      text-align: center;
      font-size: 11pt;
      color: #000000;
      margin-bottom: 22px;
    }

    /* ═══════════════════ ISI ═══════════════════ */
    .content { text-align: justify; color: #000000; }
    .content p { margin: 0 0 12px; }
    .info-table { width: 100%; margin: 12px 0; border-collapse: collapse; }
    .info-table td { padding: 3px 0; vertical-align: top; color: #000000; }
    .info-table td:first-child { width: 35%; font-weight: 600; }
    .info-table td:nth-child(2) { width: 5%; text-align: center; }

    /* ═══════════════════ TTD + STEMPEL ═══════════════════ */
    /* Layout: right-aligned box, place-date → stempel behind + TTD overlap →
       nama + jabatan. page-break-inside: avoid supaya nama & jabatan gak
       terpisah antar page (fix orphan "Direktur Utama"). */
    .signature-wrapper {
      width: 100%;
      margin-top: 32px;
    }
    .signature-holder {
      float: right;
      width: 300px;
      text-align: center;
      color: #000000;
      /* CRITICAL: paksa seluruh signature block (place-date, stempel, ttd,
         nama, jabatan) tetap di 1 page - no split allowed. */
      page-break-inside: avoid;
    }
    .place-date {
      font-size: 11pt;
      margin-bottom: 4px;
    }
    /* Container tinggi 120px, text-align center supaya stempel inline-block
       ke-center otomatis tanpa harus tau lebar. */
    .stamp-container {
      height: 120px;
      position: relative;
      overflow: visible;
      text-align: center;
      page-break-inside: avoid;
    }
    /* Stempel - HEIGHT fixed, WIDTH auto (preserve aspect ratio).
       Rectangular stamp (persegi panjang) gak dipaksa persegi. */
    .stamp-img {
      height: 100px;
      width: auto;
      opacity: 0.75;
      display: inline-block;
      vertical-align: top;
    }
    /* Signature overlap stempel dari atas - pakai absolute + margin negatif
       untuk center horizontally (assume TTD ~140px lebar). */
    .signature-img {
      position: absolute;
      left: 50%;
      top: 15px;
      width: 140px;
      height: 80px;
      margin-left: -70px;
    }
    .signature-name {
      font-weight: 700;
      text-decoration: underline;
      font-size: 12pt;
      margin: 2px 0 2px;
      color: #000000;
    }
    .signature-title {
      font-size: 11pt;
      color: #000000;
    }
    .clearfix { clear: both; }

    /* ═══════════════════ FOOTER VERIFY ═══════════════════ */
    .verify-footer {
      position: fixed;
      bottom: 20px;
      left: 60px;
      right: 60px;
      font-size: 8pt;
      color: #6b7280;
      border-top: 1px solid #d1d5db;
      padding-top: 8px;
      text-align: center;
    }
  </style>
</head>
<body>

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
            // Format alamat multi-line dari fields yang ada.
            // Fallback ke company->address kalau field per bagian kosong.
            $streetLine = trim($company?->address ?? '');
            $cityLine = trim(implode(', ', array_filter([
              $company?->district,
              $company?->regency,
              $company?->province,
              $company?->postal_code,
            ])));
          @endphp
          @if($streetLine)
            <div>{{ $streetLine }}</div>
          @endif
          @if($cityLine)
            <div>{{ $cityLine }}</div>
          @endif
          <div>
            @if($company?->phone) Telp: {{ $company->phone }} @endif
            @if($company?->email) @if($company?->phone) | @endif Email: {{ $company->email }} @endif
          </div>
          @if($company?->website)
            <div>Website: {{ $company->website }}</div>
          @endif
          @if($company?->npwp)
            <div>NPWP: {{ $company->npwp }}</div>
          @endif
        </div>
      </div>
    </div>
    @if($letterheadStyle === 'modern')
      <div class="letterhead-accent"></div>
    @endif
  </div>

  {{-- ═══════════════════ JUDUL + NOMOR ═══════════════════ --}}
  <div class="doc-title">
    <h1>Surat Keterangan {{ $isFormer ? 'Pernah Bekerja' : 'Kerja' }}</h1>
  </div>
  <div class="doc-number">
    Nomor: <strong>{{ $cert->cert_number }}</strong>
  </div>

  {{-- ═══════════════════ ISI ═══════════════════ --}}
  <div class="content">
    <p>Yang bertanda tangan di bawah ini:</p>

    <table class="info-table">
      <tr><td>Nama</td><td>:</td><td>{{ $cert->signer_name }}</td></tr>
      <tr><td>Jabatan</td><td>:</td><td>{{ $cert->signer_title }}</td></tr>
      <tr><td>Perusahaan</td><td>:</td><td>{{ $company?->name ?? 'PT IBE Reality' }}</td></tr>
    </table>

    <p>Dengan ini menerangkan bahwa:</p>

    <table class="info-table">
      <tr><td>Nama</td><td>:</td><td><strong>{{ $user->name }}</strong></td></tr>
      @if($user->nip)
      <tr><td>NIP</td><td>:</td><td>{{ $user->nip }}</td></tr>
      @endif
      @if($user->ktp_number)
      <tr><td>NIK KTP</td><td>:</td><td>{{ $user->ktp_number }}</td></tr>
      @endif
      <tr><td>Jabatan {{ $isFormer ? 'Terakhir' : '' }}</td><td>:</td><td>{{ $jobTitle }}</td></tr>
      <tr><td>Divisi {{ $isFormer ? 'Terakhir' : '' }}</td><td>:</td><td>{{ $division }}</td></tr>
      <tr><td>Tanggal Bergabung</td><td>:</td><td>{{ $joinDateFormatted }}</td></tr>
      @if($isFormer && $resignedDateFormatted)
      <tr><td>Tanggal Berakhir</td><td>:</td><td>{{ $resignedDateFormatted }}</td></tr>
      @endif
    </table>

    @if($isFormer)
      <p>
        Adalah benar <strong>pernah bekerja</strong> di {{ $company?->name ?? 'PT IBE Reality' }}
        sebagai <strong>{{ $jobTitle }}</strong> pada periode
        <strong>{{ $joinDateFormatted }}</strong> sampai dengan
        <strong>{{ $resignedDateFormatted ?? $joinDateFormatted }}</strong>.
        Yang bersangkutan telah menyelesaikan masa kerjanya dengan baik.
      </p>
    @else
      <p>
        Adalah benar karyawan {{ $company?->name ?? 'PT IBE Reality' }} yang saat ini
        masih aktif bekerja pada perusahaan kami.
      </p>
    @endif

    @if($cert->purpose)
    <p>
      Surat keterangan {{ $isFormer ? 'pernah bekerja' : 'kerja' }} ini dibuat untuk keperluan
      <strong>{{ $cert->purpose }}</strong>.
    </p>
    @else
    <p>
      Demikian surat keterangan {{ $isFormer ? 'pernah bekerja' : 'kerja' }} ini kami buat,
      agar dapat dipergunakan sebagaimana mestinya.
    </p>
    @endif
  </div>

  {{-- ═══════════════════ TTD + STEMPEL ═══════════════════ --}}
  <div class="signature-wrapper">
    <div class="signature-holder">
      <div class="place-date">
        {{ $company?->city ?? 'Jakarta' }}, {{ $issuedDateFormatted }}
      </div>
      <div class="stamp-container">
        @if($stampDataUri)
          <img class="stamp-img" src="{{ $stampDataUri }}" alt="Stempel">
        @endif
        @if($signatureDataUri)
          <img class="signature-img" src="{{ $signatureDataUri }}" alt="Signature">
        @endif
      </div>
      <div class="signature-name">{{ $cert->signer_name }}</div>
      <div class="signature-title">{{ $cert->signer_title }}</div>
    </div>
    <div class="clearfix"></div>
  </div>

  {{-- ═══════════════════ FOOTER VERIFY ═══════════════════ --}}
  <div class="verify-footer">
    Dokumen ini diterbitkan secara elektronik. Verifikasi keaslian di:
    <br>
    <strong>{{ $verifyUrl }}</strong>
  </div>

</body>
</html>

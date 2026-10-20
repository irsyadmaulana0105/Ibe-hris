<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Kontrak Masa Percobaan - {{ $name }}</title>
  <style>
    @page { margin: 28mm 22mm 25mm 22mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.55; color: #0f172a; }
    h1 { font-size: 14px; text-align: center; margin: 0 0 4px; letter-spacing: .02em; }
    h2 { font-size: 12px; text-align: center; margin: 16px 0 8px; font-weight: bold; }
    .header { display: block; padding: 12px 0; border-bottom: 3px solid #ca8a04; margin-bottom: 14px; }
    .header-row { display: table; width: 100%; }
    .header-logo { display: table-cell; width: 88px; vertical-align: top; }
    .header-text { display: table-cell; vertical-align: top; padding-left: 14px; }
    .header-text .name { font-size: 16px; font-weight: bold; color: #854d0e; }
    .header-text .addr { font-size: 9px; color: #475569; margin-top: 2px; }
    .stamp { display: inline-block; padding: 2px 10px; background: #fef3c7; color: #854d0e; border: 1px solid #fcd34d; border-radius: 3px; font-size: 9px; font-weight: bold; letter-spacing: .08em; }
    .pasal { margin-top: 14px; page-break-inside: avoid; }
    .pasal-title { text-align: center; font-weight: bold; font-size: 12px; margin-bottom: 6px; }
    ol { margin: 4px 0 4px 18px; padding: 0; }
    ol li { margin: 4px 0; }
    .sign-table { width: 100%; margin-top: 28px; border-collapse: collapse; }
    .sign-table th, .sign-table td { border: 1px solid #94a3b8; padding: 6px 10px; vertical-align: top; font-size: 10px; }
    .sign-table th { background: #fef3c7; font-weight: bold; text-align: left; }
    .sign-box { height: 100px; text-align: center; }
    .sign-box img { max-height: 90px; }
    .red { color: #b91c1c; }
    .info-box { background: #fefce8; border-left: 4px solid #ca8a04; padding: 8px 12px; margin: 10px 0; font-size: 10px; }
    p { margin: 6px 0; }
    strong { font-weight: bold; }
  </style>
</head>
<body>
  <div class="header">
    <div class="header-row">
      <div class="header-logo">
        @if(!empty($logoData))
          <img src="{{ $logoData }}" alt="logo" style="max-width: 80px; max-height: 80px;">
        @else
          <svg width="80" height="80" viewBox="0 0 62 68">
            <path d="M31 4 C 31 4, 52 30, 52 46 C 52 58, 42 64, 31 64 C 20 64, 10 58, 10 46 C 10 30, 31 4, 31 4 Z" fill="#854d0e"/>
            <circle cx="36" cy="40" r="8" fill="#fff"/>
          </svg>
        @endif
      </div>
      <div class="header-text">
        <div class="name">{{ strtoupper($company->name ?? 'PT. INTEGRASI BISNIS EKSEKUTIF') }}</div>
        <div class="addr">{{ $company->address ?? 'Desa Ketimang No 43, RT8/RW2, Kecamatan Wonoayu, Kabupaten Sidoarjo, Jawa Timur' }}</div>
        <div class="addr">Email: {{ $company->email ?? 'admin@ibereality.com' }} · Website: ibereality.com</div>
        <div style="margin-top: 4px;"><span class="stamp">PERJANJIAN MASA PERCOBAAN</span></div>
      </div>
    </div>
  </div>

  <h1>SURAT PERJANJIAN KERJA</h1>
  <h1>MASA PERCOBAAN (PROBATION)</h1>
  <p style="text-align:center; font-size:10px; color:#64748b;">No: {{ $contractNumber }}</p>

  <p>Perjanjian Kerja Masa Percobaan ini ("Perjanjian") dibuat pada hari {{ $dayName }}, tanggal {{ $issuedDateLabel }}, oleh dan antara:</p>
  <ol>
    <li><strong>{{ $company->name ?? 'PT Integrasi Bisnis Eksekutif' }}</strong>, selanjutnya disebut <strong>"Perusahaan"</strong>, berkedudukan di {{ $company->address ?? 'Sidoarjo, Jawa Timur' }}, yang dalam hal ini diwakili oleh <strong>Muhammad Aldy Iyik Be</strong>, selaku Direktur Utama.</li>
    <li><strong>{{ $name }}</strong>, selanjutnya disebut <strong>"Calon Karyawan"</strong>, bertempat tinggal di {{ $address ?: 'alamat sesuai KTP' }}, pemegang KTP Nomor <strong>{{ $nik ?: 'belum diisi' }}</strong>.</li>
  </ol>

  <div class="info-box">
    <strong>Dasar Hukum:</strong> Pasal 60 UU No. 13 Tahun 2003 jo. UU Cipta Kerja No. 6 Tahun 2023 - masa percobaan kerja maksimal 3 (tiga) bulan, wajib dicantumkan dalam perjanjian kerja tertulis, dan upah selama masa percobaan tidak boleh kurang dari Upah Minimum yang berlaku.
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 1<br>JABATAN DAN POSISI</div>
    <ol>
      <li>Calon Karyawan diterima untuk menempati posisi <strong>{{ $jobTitle ?? '-' }}</strong>@if($divisionName) di Divisi <strong>{{ $divisionName }}</strong>@endif.</li>
      <li>Calon Karyawan bertanggung jawab langsung kepada atasan langsung yang ditunjuk Perusahaan dan wajib mengikuti SOP, kebijakan internal, serta arahan manajemen.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 2<br>JANGKA WAKTU MASA PERCOBAAN</div>
    <ol>
      <li>Masa percobaan berlaku selama <strong>3 (tiga) bulan</strong>, terhitung sejak <strong>{{ $startDateLabel }}</strong> sampai dengan <strong>{{ $endDateLabel ?? '-' }}</strong>.</li>
      <li>Selama masa percobaan, Perusahaan berhak mengevaluasi kinerja, disiplin, dan kesesuaian Calon Karyawan dengan budaya perusahaan.</li>
      <li>Apabila kinerja memenuhi standar Perusahaan, Calon Karyawan akan diangkat menjadi <strong>Karyawan Tetap (PKWTT)</strong> melalui Surat Pengangkatan baru.</li>
      <li>Apabila kinerja tidak memenuhi standar, Perusahaan berhak <strong>mengakhiri perjanjian tanpa pesangon</strong> sesuai ketentuan UU Ketenagakerjaan.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 3<br>UPAH DAN TUNJANGAN</div>
    <ol>
      <li>Perusahaan membayar upah pokok sebesar <strong>Rp {{ $monthlyAmount ? number_format($monthlyAmount, 0, ',', '.') : '________________' }}</strong> per bulan, dibayarkan pada akhir setiap bulan via transfer bank.</li>
      <li>Upah selama masa percobaan tidak boleh kurang dari Upah Minimum Kabupaten/Kota (UMK) yang berlaku.</li>
      <li>Tunjangan, BPJS Kesehatan, dan BPJS Ketenagakerjaan diberikan sesuai ketentuan perusahaan dan peraturan yang berlaku.</li>
      <li>Hak cuti tahunan diberikan <strong>secara proporsional (pro-rata)</strong> berdasarkan masa kerja efektif.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 4<br>WAKTU KERJA, KETERLAMBATAN, DAN DISIPLIN</div>
    <ol>
      <li>Jam kerja mengikuti ketentuan Perusahaan: <strong>Senin–Jumat, 08:00–17:00</strong>, atau sesuai shift yang ditetapkan.</li>
      <li>Calon Karyawan wajib hadir tepat waktu, melakukan presensi via aplikasi absensi resmi Perusahaan, dan mengikuti aturan kedisiplinan.</li>
      <li><strong>Toleransi keterlambatan ≤ 10 menit</strong> dari jam mulai shift; clock-in lewat batas tersebut akan dicatat sistem sebagai status TERLAMBAT.</li>
      <li>Keterlambatan dikenai potongan sanksi administratif sesuai <strong>skema potongan bertingkat (tier menit)</strong> yang ditetapkan dalam kebijakan internal Perusahaan dan dapat ditinjau Calon Karyawan melalui dashboard absensi pribadi.</li>
      <li>Pelanggaran berulang (terlambat, mangkir, indisipliner) menjadi dasar evaluasi negatif yang dapat mempengaruhi keputusan pengangkatan menjadi Karyawan Tetap.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 5<br>KEWAJIBAN KERAHASIAAN</div>
    <ol>
      <li>Calon Karyawan wajib menjaga kerahasiaan seluruh informasi internal Perusahaan, baik selama masa percobaan maupun setelah berakhirnya hubungan kerja.</li>
      <li>Pelanggaran ketentuan kerahasiaan dapat berakibat pemutusan kontrak dan tuntutan hukum perdata/pidana.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 6<br>PEMUTUSAN HUBUNGAN KERJA SELAMA MASA PERCOBAAN</div>
    <ol>
      <li>Para Pihak dapat mengakhiri Perjanjian ini dengan <strong>pemberitahuan tertulis minimal 7 (tujuh) hari kalender</strong> sebelumnya.</li>
      <li>Apabila Perusahaan mengakhiri Perjanjian karena hasil evaluasi tidak memuaskan, Calon Karyawan <strong>tidak berhak atas pesangon</strong>.</li>
      <li>Setelah masa percobaan berakhir dan tidak dilanjutkan, gaji dan hak yang sudah terbit tetap dibayarkan.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 7<br>PENUTUP</div>
    <ol>
      <li>Perjanjian ini dibuat 2 (dua) rangkap, masing-masing untuk Perusahaan dan Calon Karyawan, mempunyai kekuatan hukum yang sama.</li>
      <li>Hal-hal yang belum diatur dalam Perjanjian ini akan diatur kemudian berdasarkan musyawarah dan ketentuan peraturan perundang-undangan yang berlaku.</li>
      <li>Setelah masa percobaan berakhir dan kinerja memenuhi standar, akan diterbitkan PKWTT (Perjanjian Kerja Waktu Tidak Tertentu).</li>
    </ol>
  </div>

  <table class="sign-table">
    <tr>
      <th style="width:50%">PERUSAHAAN</th>
      <th style="width:50%">CALON KARYAWAN</th>
    </tr>
    <tr>
      <td class="sign-box">
        @if(!empty($signatureDataUri))
          <em style="font-size:9px; color:#64748b;">(ditandatangani secara digital oleh sistem)</em>
        @endif
        <p><strong>Muhammad Aldy Iyik Be</strong><br>Direktur Utama</p>
      </td>
      <td class="sign-box">
        @if(!empty($signatureDataUri))
          <img src="{{ $signatureDataUri }}" alt="signature">
        @endif
        <p><strong>{{ $name }}</strong><br>Calon Karyawan</p>
      </td>
    </tr>
  </table>

  @if($signedAt)
    <p style="margin-top: 12px; font-size: 9px; color: #64748b; text-align: center;">
      Ditandatangani pada {{ $signedDateLabel }} pukul {{ $signedTimeLabel }} WIB melalui sistem digital terpercaya.
      @if($signedIp) IP: {{ $signedIp }}. @endif
    </p>
  @endif
</body>
</html>

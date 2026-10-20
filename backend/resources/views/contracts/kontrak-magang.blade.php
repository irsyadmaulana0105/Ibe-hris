<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Perjanjian Magang - {{ $name }}</title>
  <style>
    @page { margin: 28mm 22mm 25mm 22mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.55; color: #0f172a; }
    h1 { font-size: 14px; text-align: center; margin: 0 0 4px; letter-spacing: .02em; }
    h2 { font-size: 12px; text-align: center; margin: 16px 0 8px; font-weight: bold; }
    .header { display: block; padding: 12px 0; border-bottom: 3px solid #7c3aed; margin-bottom: 14px; }
    .header-row { display: table; width: 100%; }
    .header-logo { display: table-cell; width: 88px; vertical-align: top; }
    .header-text { display: table-cell; vertical-align: top; padding-left: 14px; }
    .header-text .name { font-size: 16px; font-weight: bold; color: #5b21b6; }
    .header-text .addr { font-size: 9px; color: #475569; margin-top: 2px; }
    .stamp { display: inline-block; padding: 2px 10px; background: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd; border-radius: 3px; font-size: 9px; font-weight: bold; letter-spacing: .08em; }
    .pasal { margin-top: 14px; page-break-inside: avoid; }
    .pasal-title { text-align: center; font-weight: bold; font-size: 12px; margin-bottom: 6px; }
    ol { margin: 4px 0 4px 18px; padding: 0; }
    ol li { margin: 4px 0; }
    .sign-table { width: 100%; margin-top: 28px; border-collapse: collapse; }
    .sign-table th, .sign-table td { border: 1px solid #94a3b8; padding: 6px 10px; vertical-align: top; font-size: 10px; }
    .sign-table th { background: #ede9fe; font-weight: bold; text-align: left; }
    .sign-box { height: 100px; text-align: center; }
    .sign-box img { max-height: 90px; }
    .info-box { background: #f5f3ff; border-left: 4px solid #7c3aed; padding: 8px 12px; margin: 10px 0; font-size: 10px; }
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
            <path d="M31 4 C 31 4, 52 30, 52 46 C 52 58, 42 64, 31 64 C 20 64, 10 58, 10 46 C 10 30, 31 4, 31 4 Z" fill="#5b21b6"/>
            <circle cx="36" cy="40" r="8" fill="#fff"/>
          </svg>
        @endif
      </div>
      <div class="header-text">
        <div class="name">{{ strtoupper($company->name ?? 'PT. INTEGRASI BISNIS EKSEKUTIF') }}</div>
        <div class="addr">{{ $company->address ?? 'Desa Ketimang No 43, RT8/RW2, Kecamatan Wonoayu, Kabupaten Sidoarjo, Jawa Timur' }}</div>
        <div class="addr">Email: {{ $company->email ?? 'admin@ibereality.com' }} · Website: ibereality.com</div>
        <div style="margin-top: 4px;"><span class="stamp">PERJANJIAN MAGANG / INTERNSHIP</span></div>
      </div>
    </div>
  </div>

  <h1>SURAT PERJANJIAN PROGRAM MAGANG</h1>
  <p style="text-align:center; font-size:10px; color:#64748b;">No: {{ $contractNumber }}</p>

  <p>Perjanjian Magang ini ("Perjanjian") dibuat pada hari {{ $dayName }}, tanggal {{ $issuedDateLabel }}, oleh dan antara:</p>
  <ol>
    <li><strong>{{ $company->name ?? 'PT Integrasi Bisnis Eksekutif' }}</strong>, selanjutnya disebut <strong>"Pihak Pertama / Perusahaan"</strong>, berkedudukan di {{ $company->address ?? 'Sidoarjo, Jawa Timur' }}, diwakili oleh <strong>Muhammad Aldy Iyik Be</strong> selaku Direktur Utama.</li>
    <li><strong>{{ $name }}</strong>, selanjutnya disebut <strong>"Pihak Kedua / Peserta Magang"</strong>, bertempat tinggal di {{ $address ?: 'alamat sesuai KTP/KTM' }}, pemegang KTP/KTM Nomor <strong>{{ $nik ?: 'belum diisi' }}</strong>.</li>
  </ol>

  <div class="info-box">
    <strong>Dasar Hukum:</strong> UU No. 13 Tahun 2003 Pasal 21-22 jo. Permenaker No. 6 Tahun 2020 tentang Penyelenggaraan Pemagangan di Dalam Negeri. Magang BUKAN hubungan kerja formal - uang saku yang diberikan adalah bantuan biaya hidup, bukan upah/gaji.
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 1<br>TUJUAN DAN POSISI MAGANG</div>
    <ol>
      <li>Tujuan magang adalah memberikan <strong>pengalaman kerja praktis</strong>, transfer pengetahuan, dan pengembangan kompetensi Peserta Magang sesuai bidang yang diminati.</li>
      <li>Peserta Magang ditempatkan di posisi <strong>{{ $jobTitle ?? '-' }}</strong>@if($divisionName) di Divisi <strong>{{ $divisionName }}</strong>@endif.</li>
      <li>Peserta Magang akan dibimbing oleh mentor/pembimbing yang ditunjuk Perusahaan.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 2<br>JANGKA WAKTU MAGANG</div>
    <ol>
      <li>Program magang berlaku terhitung sejak <strong>{{ $startDateLabel }}</strong> sampai dengan <strong>{{ $endDateLabel ?? '-' }}</strong>.</li>
      <li>Jangka waktu magang dapat diperpanjang dengan kesepakatan tertulis dari kedua belah pihak, dengan batas maksimal sesuai Permenaker 6/2020 (maks. 12 bulan).</li>
      <li>Peserta Magang dapat mengakhiri magang sebelum waktunya dengan pemberitahuan tertulis minimal 7 (tujuh) hari sebelumnya.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 3<br>HAK PESERTA MAGANG</div>
    <ol>
      <li>Mendapatkan bimbingan dari mentor sesuai program magang.</li>
      <li>Mendapatkan <strong>uang saku</strong>@if($monthlyAmount) sebesar <strong>Rp {{ number_format($monthlyAmount, 0, ',', '.') }}</strong> per bulan@else sesuai kebijakan Perusahaan@endif sebagai bantuan biaya transportasi dan makan. Uang saku ini BUKAN upah/gaji.</li>
      <li>Mendapatkan fasilitas kerja yang memadai (alat kerja, akses sistem, ruang kerja).</li>
      <li>Mendapatkan perlindungan kecelakaan kerja dan asuransi kesehatan sesuai program magang.</li>
      <li>Mendapatkan <strong>Sertifikat Magang</strong> dan/atau Surat Keterangan Magang setelah menyelesaikan program dengan baik.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 4<br>KEWAJIBAN PESERTA MAGANG</div>
    <ol>
      <li>Mematuhi tata tertib, kebijakan, dan SOP Perusahaan.</li>
      <li>Hadir tepat waktu sesuai jadwal yang ditetapkan, melakukan presensi via aplikasi absensi resmi.</li>
      <li>Menjalankan tugas dan instruksi dari pembimbing dengan penuh tanggung jawab.</li>
      <li>Menjaga <strong>kerahasiaan</strong> informasi internal Perusahaan, baik selama maupun setelah masa magang berakhir.</li>
      <li>Membuat laporan magang berkala (mingguan/bulanan) sesuai permintaan pembimbing.</li>
      <li>Mengganti kerugian apabila menyebabkan kerusakan fasilitas Perusahaan akibat kelalaian.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 5<br>WAKTU MAGANG</div>
    <ol>
      <li>Jam magang mengikuti kebijakan Perusahaan: <strong>Senin–Jumat, 08:00–17:00</strong>, dengan istirahat 1 jam.</li>
      <li>Tidak ada lembur wajib. Apabila Peserta Magang bekerja di luar jam standar, Perusahaan dapat memberikan uang tambahan secara sukarela.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 6<br>SIFAT MAGANG (BUKAN HUBUNGAN KERJA)</div>
    <ol>
      <li>Program magang ini <strong>BUKAN hubungan kerja</strong> sebagaimana dimaksud dalam UU Ketenagakerjaan, sehingga Peserta Magang TIDAK memiliki hak-hak karyawan tetap (pesangon, JHT, dll).</li>
      <li>Setelah masa magang berakhir, Peserta Magang TIDAK otomatis menjadi karyawan. Pengangkatan menjadi karyawan akan ditentukan terpisah melalui proses rekrutmen formal.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 7<br>PEMUTUSAN MAGANG</div>
    <ol>
      <li>Perusahaan berhak mengakhiri program magang sewaktu-waktu apabila Peserta Magang melanggar ketentuan, melakukan tindakan indisipliner, atau menyebabkan kerugian Perusahaan.</li>
      <li>Peserta Magang dapat mengakhiri program magang dengan pemberitahuan tertulis 7 hari sebelumnya.</li>
      <li>Apabila magang berakhir sebelum jangka waktu selesai, uang saku dibayarkan secara pro-rata sesuai hari kerja aktual.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 8<br>PENUTUP</div>
    <ol>
      <li>Perjanjian ini dibuat dalam 2 (dua) rangkap, masing-masing untuk Perusahaan dan Peserta Magang, mempunyai kekuatan hukum yang sama.</li>
      <li>Hal-hal yang belum diatur akan diselesaikan secara musyawarah dengan mengacu pada peraturan perundang-undangan yang berlaku.</li>
    </ol>
  </div>

  <table class="sign-table">
    <tr>
      <th style="width:50%">PIHAK PERTAMA (Perusahaan)</th>
      <th style="width:50%">PIHAK KEDUA (Peserta Magang)</th>
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
        <p><strong>{{ $name }}</strong><br>Peserta Magang</p>
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

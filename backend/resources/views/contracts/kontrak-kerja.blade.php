<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Kontrak Kerja - {{ $name }}</title>
  <style>
    @page { margin: 28mm 22mm 25mm 22mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.55; color: #0f172a; }
    h1 { font-size: 14px; text-align: center; margin: 0 0 4px; letter-spacing: .02em; }
    h2 { font-size: 12px; text-align: center; margin: 16px 0 8px; font-weight: bold; }
    .header { display: block; padding: 12px 0; border-bottom: 3px solid #0f6677; margin-bottom: 14px; }
    .header-row { display: table; width: 100%; }
    .header-logo { display: table-cell; width: 88px; vertical-align: top; }
    .header-text { display: table-cell; vertical-align: top; padding-left: 14px; }
    .header-text .name { font-size: 16px; font-weight: bold; color: #054955; }
    .header-text .addr { font-size: 9px; color: #475569; margin-top: 2px; }
    .pasal { margin-top: 14px; page-break-inside: avoid; }
    .pasal-title { text-align: center; font-weight: bold; font-size: 12px; margin-bottom: 6px; }
    ol { margin: 4px 0 4px 18px; padding: 0; }
    ol li { margin: 4px 0; }
    .sign-table { width: 100%; margin-top: 30px; border-collapse: collapse; }
    .sign-table th, .sign-table td { border: 1px solid #94a3b8; padding: 6px 10px; vertical-align: top; font-size: 10px; }
    .sign-table th { background: #f1f5f9; font-weight: bold; text-align: left; }
    .sign-box { height: 100px; text-align: center; }
    .sign-box img { max-height: 90px; }
    .red { color: #b91c1c; }
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
          {{-- Fallback SVG kalau company belum upload logo di Settings → Company --}}
          <svg width="80" height="80" viewBox="0 0 62 68">
            <path d="M31 4 C 31 4, 52 30, 52 46 C 52 58, 42 64, 31 64 C 20 64, 10 58, 10 46 C 10 30, 31 4, 31 4 Z" fill="#054955"/>
            <circle cx="36" cy="40" r="8" fill="#fff"/>
          </svg>
        @endif
      </div>
      <div class="header-text">
        <div class="name">{{ strtoupper($company->name ?? 'PT. INTEGRASI BISNIS EKSEKUTIF') }}</div>
        <div class="addr">{{ $company->address ?? 'Desa Ketimang No 43, RT8/RW2, Kecamatan Wonoayu, Kabupaten Sidoarjo, Jawa Timur' }}</div>
        <div class="addr">Email: {{ $company->email ?? 'admin@ibereality.com' }} · Website: ibereality.com</div>
      </div>
    </div>
  </div>

  <h1>KONTRAK KERJA FINAL</h1>
  <p>Perjanjian Kerja ini dibuat dan ditandatangani pada hari {{ $dayName }}, tanggal {{ $issuedDateLabel }}, oleh dan antara:</p>
  <ol>
    <li><strong>PT Integrasi Bisnis Eksekutif</strong>, selanjutnya disebut <strong>"Perusahaan"</strong>, berkedudukan di Jl. Raya Pilang no 43 RT8 RW2 Desa Ketimang, Kecamatan Wonoayu, Kabupaten Sidoarjo, Jawa Timur, yang dalam hal ini diwakili oleh <strong>Muhammad Aldy Iyik Be</strong>, selaku Direktur Utama, bertindak untuk dan atas nama Perusahaan.</li>
    <li><strong>{{ $name }}</strong>, selanjutnya disebut <strong>"Karyawan"</strong>, bertempat tinggal di {{ $address ?: 'alamat sesuai KTP' }}, pemegang KTP Nomor <strong>{{ $nik ?: 'belum diisi' }}</strong>.</li>
  </ol>
  <p>Para Pihak dengan ini sepakat untuk mengikatkan diri dalam Perjanjian Kerja dengan ketentuan sebagai berikut:</p>

  <div class="pasal">
    <div class="pasal-title">PASAL 1<br>STATUS KEPEGAWAIAN DAN MASA PERCOBAAN</div>
    <ol>
      <li>Karyawan dipekerjakan oleh Perusahaan dengan <strong>Perjanjian Kerja Waktu Tidak Tertentu (PKWTT)</strong>. Status PKWTT berlaku sejak hari pertama bekerja, namun Karyawan wajib menjalani masa percobaan (probation) maksimal 3 (tiga) bulan sesuai Pasal 60 UU No. 13 Tahun 2003.</li>
      <li>Selama masa percobaan, Perusahaan wajib membayar tidak kurang dari Upah Minimum yang berlaku, dan kewajiban Karyawan sama seperti Karyawan tetap kecuali hak khusus untuk Karyawan tetap.</li>
      <li>Evaluasi kinerja periodik menjadi dasar kelulusan masa percobaan. Apabila tidak memenuhi standar, Perusahaan berhak mengakhiri hubungan kerja tanpa kewajiban pesangon, kecuali hak normatif sampai tanggal pemutusan.</li>
      <li>Apabila lulus, Karyawan dikonfirmasi tertulis sebagai Karyawan tetap/organik dan diikutsertakan dalam jaminan sosial nasional sesuai ketentuan.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 2<br>MASA PROBATION & EVALUASI</div>
    <ol>
      <li>Masa percobaan minimal 1 bulan, maksimal 3 bulan. Evaluasi dilakukan akhir bulan atau saat dibutuhkan.</li>
      <li>Kriteria evaluasi: kualitas hasil kerja, kuantitas/target, kedisiplinan (kehadiran, ketepatan jam kerja), perilaku & komunikasi, kepatuhan SOP & K3.</li>
      <li>Tindak lanjut: peringatan tertulis + improvement plan, perpanjangan masa percobaan, atau pemutusan hubungan kerja dengan pemberitahuan tertulis minimal 3 hari kalender sebelumnya.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 3<br>GAJI, BONUS & PEMBAYARAN</div>
    <ol>
      <li>Gaji pokok masa percobaan: <strong>Rp 2.400.000,-</strong> per bulan (tidak boleh kurang dari UMK).</li>
      <li>Gaji pokok setelah lulus probation: <strong>Rp 4.800.000,-</strong> per bulan.</li>
      <li>Bonus kehadiran <strong>Rp 200.000,-</strong> per bulan jika hadir minimal 25 hari kerja (jam 08.00–16.00, toleransi keterlambatan ≤ 10 menit, maks 3× per bulan).</li>
      <li>Bonus project <strong>1% dari Total Nilai Project</strong>, dibayar setelah klien lunas 100% dan kontribusi diverifikasi Project Lead + HR. Jika multi-orang, dibagi proporsional sesuai Surat Penugasan Project.</li>
      <li>Pembayaran via transfer ke rekening <strong>BCA</strong> milik Karyawan setiap akhir bulan kerja.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 4<br>JAM KERJA, KETIDAKHADIRAN & KETERLAMBATAN</div>
    <ol>
      <li>Jam kerja normal: <strong>08.00–16.00, Senin–Jumat</strong>.</li>
      <li><strong>Toleransi keterlambatan ≤ 10 menit</strong> dari jam mulai shift; clock-in lewat batas tersebut dicatat sistem sebagai TERLAMBAT.</li>
      <li>Maksimum <strong>3× telat per bulan</strong> tanpa pembatalan bonus kehadiran. Telat ke-4 dan seterusnya <strong>otomatis menghapus bonus kehadiran Rp 200.000,-</strong> bulan tersebut.</li>
      <li>Setiap kejadian terlambat dikenai potongan sanksi administratif sesuai <strong>skema potongan bertingkat (tier menit)</strong> dalam kebijakan internal Perusahaan, dapat ditinjau Karyawan via dashboard absensi pribadi.</li>
      <li>Ketidakhadiran tanpa pemberitahuan (NCNS) 2 hari berturut-turut = pelanggaran berat, dapat berujung PHK.</li>
      <li>Izin/sakit wajib disampaikan via prosedur Perusahaan; sakit ≥ 1 hari wajib surat dokter.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 5<br>REPORTING, DOKUMENTASI & EVALUASI</div>
    <ol>
      <li>Laporan harian: aktivitas/tugas, hasil/output, kendala, rencana berikutnya - via Google Sheet / form internal.</li>
      <li>Laporan mingguan / akhir proyek dilengkapi bukti kerja (foto, file, kode, dokumentasi).</li>
      <li>Instruksi kerja resmi via tertulis (email/platform tugas). Instruksi lisan harus dikonfirmasi tertulis.</li>
      <li>Evaluasi kinerja setiap 6 bulan setelah lulus probation, atau saat dibutuhkan.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 6<br>NON-DISCLOSURE AGREEMENT (NDA)</div>
    <ol>
      <li>Informasi Rahasia meliputi data klien, harga, kode sumber, rencana bisnis, dokumen finansial, teknologi, data personalia, dan seluruh dokumentasi/komunikasi internal.</li>
      <li>Karyawan wajib menjaga kerahasiaan selama bekerja dan <strong>2 (dua) tahun setelah</strong> berakhirnya hubungan kerja. Untuk rahasia dagang, kewajiban berlaku sampai informasi menjadi publik secara sah.</li>
      <li>Hak Perusahaan atas pelanggaran: ganti rugi materiil/immateriil, injunctive relief, pembatalan kontrak, gugatan perdata/pidana.</li>
      <li>Seluruh hasil kerja, kode, desain, arsip, dan dokumentasi yang dibuat dalam ruang lingkup pekerjaan adalah <strong>milik Perusahaan sepenuhnya</strong>.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 7<br>LARANGAN MERANGKAP KERJA & KONFLIK KEPENTINGAN</div>
    <ol>
      <li>Dilarang merangkap kerja yang: mengganggu kinerja, menimbulkan konflik kepentingan (bekerja untuk pesaing/klien/pemasok), memanfaatkan aset Perusahaan, atau merugikan reputasi.</li>
      <li>Pekerjaan sampingan tanpa konflik wajib izin tertulis ke HRD sebelum mulai.</li>
      <li>Pelanggaran → peringatan tertulis, pembatalan bonus, hingga PHK jika menimbulkan kerugian/konflik serius.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 8<br>PELANGGARAN, SANKSI & PROSEDUR</div>
    <ol>
      <li>Setiap dugaan pelanggaran disiplin diperiksa internal; Karyawan berhak memberikan klarifikasi tertulis.</li>
      <li>Sanksi proporsional: teguran lisan → peringatan tertulis (tahap 1, 2, lanjutan) → pemotongan bonus → skorsing → PHK.</li>
      <li>Pelanggaran berat (pencurian, pembocoran informasi rahasia, pemalsuan dokumen, kekerasan): sanksi hingga PHK + tindakan hukum.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 9<br>PENGHENTIAN HUBUNGAN KERJA</div>
    <ol>
      <li>Selama masa probation: pemberitahuan tertulis ≥ 3 hari kalender, tanpa pesangon, namun hak normatif tetap dibayarkan.</li>
      <li>Setelah probation: pemutusan sesuai UU No. 13/2003 jo. UU Cipta Kerja & PP No. 35/2021.</li>
      <li>Resign atas inisiatif Karyawan: pemberitahuan tertulis ≥ 30 hari kalender, dengan kewajiban handover.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 10<br>PENYELESAIAN SENGKETA</div>
    <ol>
      <li>Penyelesaian internal bertahap: Atasan Langsung → HRD → Manajemen.</li>
      <li>Jika gagal: perundingan bipartit, mediasi/konsiliasi di Disnaker, atau arbitrase; PHI sebagai upaya akhir.</li>
    </ol>
  </div>

  <div class="pasal">
    <div class="pasal-title">PASAL 11<br>PERNYATAAN & TANDA TANGAN</div>
    <p>Dengan menandatangani Perjanjian Kerja ini, Karyawan menyatakan telah membaca, memahami, dan menyetujui seluruh ketentuan, termasuk NDA, larangan merangkap kerja, disiplin kerja, dan ketentuan lainnya.</p>

    <table class="sign-table">
      <tr>
        <th style="width:50%">PIHAK PERUSAHAAN<br><span style="font-weight:normal">Direktur Utama PT INTEGRASI BISNIS EKSEKUTIF</span></th>
        <th>PIHAK KARYAWAN</th>
      </tr>
      <tr>
        <td class="sign-box">
          <em style="color:#94a3b8; font-size:9px;">(tanda tangan basah + materai Rp 10.000)</em>
        </td>
        <td class="sign-box">
          @if (!empty($signatureDataUri))
            <img src="{{ $signatureDataUri }}" alt="Tanda tangan {{ $name }}">
          @else
            <em style="color:#94a3b8; font-size:9px;">- belum ditandatangani -</em>
          @endif
        </td>
      </tr>
      <tr>
        <td><strong>( Muhammad Aldy Iyik Be )</strong></td>
        <td>
          <strong>( {{ $name }} )</strong><br>
          No. KTP: {{ $nik ?: '-' }}
        </td>
      </tr>
      <tr>
        <td>Tanggal: {{ $issuedDateLabel }}</td>
        <td>Tanggal: {{ $signedDateLabel ?: '- / - / ---' }}</td>
      </tr>
    </table>

    @if (!empty($signedAt))
      <p style="margin-top:14px; font-size:9px; color:#64748b; text-align:center;">
        Dokumen ini ditandatangani secara elektronik pada {{ $signedDateLabel }} {{ $signedTimeLabel }} WIB
        dari IP {{ $signedIp }}. Nomor kontrak: {{ $contractNumber }}.
      </p>
    @endif
  </div>
</body>
</html>

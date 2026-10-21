# Temuan dan Status

Daftar masalah yang ditemukan saat paket ini dijalankan, diperiksa halaman demi halaman, dan diuji, per 21 Oktober 2026. Dokumen ini memisahkan yang **sudah diperbaiki** dari yang **masih terbuka** supaya tim tidak memperbaiki hal yang sama dua kali.

Cara pemeriksaan: aplikasi dijalankan dengan data contoh, 34 halaman dibuka sebagai admin dan karyawan (lebar desktop dan ponsel), galat konsol dan respons API dicatat, kontras tema diukur, dan alur absen diuji lewat API. Pengisian formulir, alur wajah sampai absen berhasil (mesin wajah tidak dijalankan), payroll, dan modul ERP di luar SDM **belum** diperiksa.

## Ringkasan

| ID | Keparahan | Masalah | Status |
|---|---|---|---|
| T1 | Tinggi | Batas lokasi kantor bisa dilewati dengan tidak mengirim koordinat | Diperbaiki, dengan uji |
| T2 | Sedang | Setelah login, pengguna terlempar kembali ke halaman login | Diperbaiki |
| T3 | Rendah | Kode QR di Edit Profil tidak tampil saat lokal dan memakai layanan luar | Terbuka |
| T4 | Rendah | Pesan validasi dari server berbahasa Inggris | Diperbaiki untuk setup lokal |
| T5 | Rendah | Data contoh lokal tanpa lokasi kantor, geofence tidak teruji | Terbuka (catatan) |
| T6 | Rendah | Logo bawaan dimuat dari host produksi | Terbuka |
| T7 | Sedang | Kontras tema: sidebar tema terang nyaris tak terbaca; tombol gelap menyatu di tema malam | Diperbaiki (patch tersendiri) |
| T8 | Rendah | Foto hasil jepretan tidak dicerminkan, berbeda dari pratinjau kamera | Diperbaiki |
| T9 | Rendah | `npm run lint` tidak berfungsi | Terbuka |
| T10 | Rendah | Empat uji `InviteTokenApiTest` memakai kata sandi yang tercatat bocor | Diperbaiki |
| T11 | Rendah | Satu uji (`HardwarePushSecurityTest`) membutuhkan PHP 8.4 | Terbuka (lingkungan) |
| T12 | Rendah | Komentar umur token tidak sesuai konfigurasi | Terbuka |
| T13 | Sedang | Kamera gagal dengan "backend 'wasm' has not yet been initialized" bila WebGL tidak tersedia | Diperbaiki, direproduksi dan diuji |

## Rincian

### T1. Geofence dilewati bila koordinat tidak dikirim (tinggi)

- **Letak:** `AttendanceService::checkGeofence`, dipakai oleh `AttendanceController::clockIn` dan `clockOut`.
- **Gejala:** pada perusahaan yang punya lokasi kantor aktif, absen dari jarak 667 km ditolak, tetapi absen tanpa `latitude`/`longitude` (atau hanya salah satunya) lolos sampai pengecekan wajah.
- **Dampak:** karyawan yang memanggil API langsung dapat absen dari luar kantor selama wajahnya cocok. Verifikasi wajah tetap berlaku, tetapi aturan lokasi tidak berfungsi.
- **Perbaikan:** bila ada kantor aktif dan koordinat tidak lengkap, absen ditolak (422, "Lokasi Anda tidak terbaca. Izinkan akses lokasi (GPS) lalu coba lagi."). Perusahaan tanpa kantor aktif dan mode `mobile_field` tidak berubah.
- **Uji:** lima uji ditambahkan di `AttendanceApiTest.php`. Tiga di antaranya gagal pada kode lama dan lulus setelah perbaikan.
- **Perlu dikonfirmasi tim IBE:** apakah ada klien (misalnya aplikasi ponsel lain) yang sengaja absen tanpa koordinat. Bila ada, perubahan ini akan menolaknya.

### T2. Login lokal berakhir di halaman login (sedang)

- **Letak:** `LoginPage.tsx` menuju `/platforms` secara bawaan, sedangkan `App.tsx` mengalihkan `/platforms` kembali ke `/login`.
- **Perbaikan:** tujuan bawaan menjadi `/dashboard`. Alur SSO tidak disentuh.
- **Jalan pintas pada versi lama:** buka `http://localhost:5173/dashboard` setelah login, atau `.../login?next=/dashboard`.

### T3. Kode QR di Edit Profil (rendah, terbuka)

- Lihat [ARCHITECTURE.md](ARCHITECTURE.md) bagian 6 butir 9. Gambar diblokir peramban saat lokal (`Cross-Origin-Resource-Policy: same-site`), dan pembuatannya bergantung pada layanan luar serta alamat produksi yang tertanam.
- **Saran:** buat QR di sisi klien atau server tanpa layanan luar, dan jadikan alamat verifikasi sebagai pengaturan, bukan teks tetap. Ini keputusan desain, jadi belum diubah.

### T4. Locale (rendah)

- **Gejala:** respons validasi seperti "The selfie base64 field is required." muncul dalam bahasa Inggris.
- **Penyebab:** `APP_LOCALE=en`; berkas `lang/id` sudah ada dan terjemahannya berfungsi (diperiksa).
- **Perbaikan:** `setup-lokal.ps1` menulis `APP_LOCALE=id` untuk instalasi baru. Untuk `.env` yang sudah ada, ubah barisnya sendiri. `.env.example` sengaja belum diubah karena nilai produksi tidak diketahui.

### T5. Tidak ada lokasi kantor pada data contoh (rendah, catatan)

Seeder lokal tidak membuat `office_locations`, jadi geofence tidak aktif saat dicoba lokal. Menambahkannya akan memaksa penguji berada di koordinat kantor itu, jadi dibiarkan; tambah manual bila ingin menguji.

### T6. Logo dari host produksi (rendah, terbuka)

`config/branding.php` menunjuk ke `attachments.ibereality.com`. Aplikasi lokal mengakses host itu untuk gambar. Bila ingin lokal sepenuhnya terisolasi, ganti lewat `DEFAULT_LOGO_URL`, `DEFAULT_LOGO_DARK_URL`, dan `DEFAULT_FAVICON_URL`.

### T7. Kontras tema (sedang)

- **Sidebar tema terang:** teks menu (`#334155`) berada di atas latar hijau tua dengan rasio kontras sekitar 1,1 sampai 1,5:1 (minimum yang layak 4,5:1). Penyebab: aturan `.sidebar` dengan `!important` mewajibkan latar gelap di semua tema, tetapi warna teks tema terang masih untuk sidebar putih.
- **Tema malam:** tombol `btn-dark` hampir menyatu dengan kartu gelap; label "Cakupan Saat Ini" berkontras 3,55:1.
- **Perbaikan:** blok CSS di akhir `globals.css` dan satu perubahan kelas di `AdminWorkspacePage.tsx`. Disampaikan sebagai patch `kontras-tema.patch`.
- **Hasil ukur tema malam (sebelum perbaikan):** teks navbar dan sidebar sudah 8:1 sampai 12:1, jadi masalah kontras navbar yang dilaporkan sebenarnya ada pada tema terang.

### T8. Cermin pada foto hasil jepretan (rendah)

Pratinjau kamera sudah dicerminkan, tetapi foto hasil jepretan yang ditampilkan sebelum dikirim tidak. Kini keduanya dicerminkan, hanya sebagai tampilan; data yang dikirim ke server tidak berubah (lihat [ARCHITECTURE.md](ARCHITECTURE.md) bagian 4).

### T9 sampai T12. Catatan lama

- **T9:** tidak ada `eslint.config.js`, sehingga `npm run lint` gagal. Menambahkannya kemungkinan memerlukan `typescript-eslint` dan `globals` sebagai devDependencies.
- **T10 (diperbaiki):** empat uji memakai kata sandi `newpassword123` dan kawan-kawannya yang ditolak aplikasi karena tercatat bocor, sehingga gagal bila mesin bisa menjangkau API pemeriksaan. Kata sandi uji kini unik (`InviteTokenApiTest.php`). Temuan tambahan: uji "token dipakai ulang" dan "token lama" hanya memeriksa status 422, sehingga bisa lulus karena kata sandinya ditolak, bukan karena tokennya. Keduanya kini juga memeriksa galat pada `token`.
- **T11:** `HardwarePushSecurityTest` memakai fungsi yang hanya ada di PHP 8.4. Lulus di PHP 8.4.
- **T12:** komentar di `routes/console.php` menyebut token 8 jam, sedangkan konfigurasi bawaannya 60 menit. Anggap konfigurasi sebagai acuan.

### T13. Kamera gagal karena mesin TensorFlow belum siap (sedang)

- **Gejala:** halaman Daftar Wajah atau Absen menampilkan "Tidak dapat mengakses kamera: The highest priority backend 'wasm' has not yet been initialized. Make sure to await tf.ready() or await tf.setBackend() before calling other methods".
- **Penyebab:** bundel face-api mendaftarkan mesin `cpu` (prioritas 1), `webgl` (2), dan `wasm` (2). Bila WebGL tidak bisa dipakai peramban (GPU diblokir, sesi desktop jarak jauh, mesin virtual), TensorFlow memilih `wasm` yang inisialisasinya asinkron, sedangkan kode memuat model tanpa menunggu `tf.ready()`.
- **Reproduksi:** peramban tanpa WebGL membuka halaman Daftar Wajah dengan kamera palsu; galat yang sama muncul.
- **Perbaikan:** fungsi baru `prepareFaceApiBackend` (`src/utils/faceApiBackend.ts`) memilih mesin secara eksplisit (webgl, lalu cpu), menunggu `tf.ready()`, dan dipanggil sebelum model dimuat di `FaceSetupPage` dan `AttendancePage`. Mesin `wasm` sengaja dilewati karena memerlukan berkas `.wasm` tambahan.
- **Hasil:** kamera menyala pada skenario WebGL diblokir maupun normal; `tsc` dan `npm run build` lolos.
- **Catatan:** pada mesin dengan `cpu`, deteksi wajah lebih lambat daripada `webgl`, sehingga gambar bisa terasa tersendat.

## Hasil uji otomatis terakhir

481 lulus, 1 dilewati, 1 gagal. Satu kegagalan itu adalah T11 (`HardwarePushSecurityTest`, butuh PHP 8.4), karena lingkungan penguji (PHP 8.3), bukan karena kode. Uji absensi: 17 lulus. Uji undangan: 10 lulus.

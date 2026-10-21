# IBE HRIS / Absensi

Kode sumber **HRIS IBE Reality**: API Laravel multi-tenant untuk absensi berbasis wajah, karyawan, cuti, lembur, kasbon, reimbursement, dan payroll, beserta aplikasi absensi React untuk karyawan dan admin HR. Backend yang sama juga memuat modul ERP lain (keuangan, CRM, produksi, gudang, persuratan) yang berbagi akun dan hak akses.

Paket ini berasal dari branch `main` per 20 Oktober 2026 dan berjalan **sepenuhnya lokal** dengan SQLite dan data contoh. Tidak ada koneksi ke server IBE.

| | |
|---|---|
| Backend | Laravel 13, PHP 8.4, Sanctum, Fortify/Jetstream, DomPDF, Pest |
| Frontend | React 19, Vite 6, TypeScript 5.8, TanStack Query, React Router 7 |
| Pengenal wajah | Node.js 22 + `@vladmandic/human` (proses terpisah, port 3899) |
| Basis data | SQLite (lokal), ± 200 tabel, 214 migrasi |
| Uji | Pest, 478 uji API |

## Dokumentasi

| Dokumen | Isi |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Komponen sistem, alur permintaan, autentikasi, hak akses, alur absensi dan wajah, payroll, penerapan, jebakan |
| [docs/DATA_MODEL.md](docs/DATA_MODEL.md) | Diagram relasi, tabel inti SDM, katalog modul lain, data sensitif, cara mengubah skema |
| [docs/VIBE_CODING.md](docs/VIBE_CODING.md) | Apa yang aman dikerjakan, resep tugas, templat perintah untuk asisten AI, daftar periksa |
| [docs/TEMUAN.md](docs/TEMUAN.md) | Masalah yang ditemukan saat paket dijalankan dan diuji, mana yang sudah diperbaiki dan mana yang masih terbuka |
| [backend/docs/REST_WEBHOOK_API.md](backend/docs/REST_WEBHOOK_API.md) | Kontrak REST JSON dan webhook keluar |
| [backend/README.md](backend/README.md) | Panduan backend dan penerapan VPS (konteks produksi) |
| [apps/attendance/README.md](apps/attendance/README.md) | Panduan aplikasi absensi |
| [CLAUDE.md](CLAUDE.md) | Ringkasan aturan dan peta kode untuk asisten AI |

## Struktur

```
IBE-HRIS-dev/
├── setup-lokal.ps1      Setup otomatis (Windows)
├── docs/                Arsitektur, model data, panduan vibe coding
├── backend/             API HRIS + modul ERP
│   └── ml-face/         Face-engine (Node, pengenal wajah)
└── apps/attendance/     Aplikasi absensi (karyawan + menu admin HR)
```

## 1. Yang perlu dipasang

| Alat | Versi | Catatan |
|---|---|---|
| PHP | **8.4** | Wajib 8.4. `composer.json` menyebut `^8.3`, tetapi dependensi terkunci menuntut PHP >= 8.4.0 saat dijalankan. Paling mudah: [Laravel Herd](https://herd.laravel.com) atau `winget install PHP.PHP.8.4`. |
| Ekstensi PHP | pdo_sqlite, mbstring, intl, gd, zip, fileinfo, bcmath, curl, openssl | Aktifkan di `php.ini` bila belum. |
| Composer | 2.x | https://getcomposer.org/download/ |
| Node.js | 22 LTS | https://nodejs.org |

> `setup-lokal.ps1` hanya memeriksa PHP 8.3 ke atas, jadi PHP 8.3 lolos pemeriksaan skrip tetapi aplikasi gagal berjalan dengan pesan "Your Composer dependencies require a PHP version >= 8.4.0". Gunakan 8.4.

## 2. Setup (sekali)

### Windows

```powershell
powershell -ExecutionPolicy Bypass -File .\setup-lokal.ps1
```

Skrip ini menjalankan `composer install`, membuat `backend/.env` lokal, migrasi SQLite, mengisi data contoh, lalu `npm install` untuk face-engine dan aplikasi absensi. Sandi akun contoh dibuat **acak** dan ditulis ke `KREDENSIAL-LOKAL.txt` (berkas ini sudah masuk `.gitignore`; jangan dibagikan).

### Mac / Linux (langkah manual yang setara)

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate

# Nilai lokal di .env (ubah barisnya bila sudah ada, atau tambahkan di akhir berkas)
#   APP_ENV=local
#   APP_URL=http://127.0.0.1:8000
#   FRONTEND_URL=http://localhost:5173
#   QUEUE_CONNECTION=sync
#   MAIL_MAILER=log
#   HOLIDAY_API_ENABLED=false
#   IBE_ALLOW_DEMO_ADMIN_SEEDING=YES-I-UNDERSTAND
#   IBE_DEMO_SUPERADMIN_PASSWORD=<sandi acak, minimal 20 karakter>
#   IBE_DEMO_ADMIN_PASSWORD=<sandi acak, minimal 20 karakter>
#   IBE_DEMO_EMPLOYEE_PASSWORD=<sandi acak, minimal 20 karakter>

touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --class=LocalHrisDevSeeder --force
php artisan storage:link
npm install

cd ../apps/attendance
npm install
printf 'VITE_API_BASE_URL=http://127.0.0.1:8000\nVITE_REQUIRE_SSO=false\n' > .env.local
```

Seeder menolak berjalan bila `IBE_ALLOW_DEMO_ADMIN_SEEDING` tidak diset atau sandi demo kurang dari 20 karakter. Itu disengaja agar data contoh tidak pernah terbuat di server sungguhan.

### Akun contoh

| Akun | Untuk |
|---|---|
| `superadmin@example.com` | Semua menu |
| `admin@example.com` | Admin HR: persetujuan wajah, rekap absensi, karyawan |
| `karyawan@example.com` | Mencoba absen masuk/pulang, daftar wajah, cuti, lembur |

## 3. Menjalankan

Buka tiga terminal:

```powershell
cd backend;          php artisan serve --host=127.0.0.1 --port=8000   # API
cd backend;          node ml-face/server.cjs                          # face-engine :3899
cd apps\attendance;  npm run dev                                      # http://localhost:5173
```

Di Mac/Linux, ganti `;` dan `\` dengan baris baru dan `/`.

Alur mencoba absen wajah:

1. Login sebagai **karyawan** → menu **Daftar Wajah** → ambil foto.
2. Login sebagai **admin** (jendela samaran atau peramban lain) → **Approval Wajah** → Setujui.
3. Kembali ke karyawan → **Absen Masuk**.

Kamera hanya jalan di `localhost` atau HTTPS. Geofence tidak aktif selama belum ada lokasi kantor, sehingga absen bisa dilakukan dari mana saja. Setelah ada lokasi kantor aktif, absen harus mengirim koordinat yang berada dalam radius kantor; tanpa koordinat, absen ditolak.

Cek kesehatan face-engine: `curl http://127.0.0.1:3899/health`.

## 4. Apa yang bisa dilakukan sistem ini

**Karyawan:** absen masuk dan pulang dengan selfie, verifikasi wajah, dan geofence; riwayat absensi; pengajuan cuti, lembur, kasbon, reimbursement, perjalanan dinas, dan kunjungan klien; slip gaji; kartu identitas dan surat keterangan kerja; tanda tangan kontrak digital.

**Admin HR dan Finance:** kelola karyawan dan master data (divisi, jabatan, shift, lokasi kantor); persetujuan wajah; rekap dan ekspor absensi; jadwal shift; persetujuan pengajuan; payroll dua tahap (HR mengajukan, Finance menyetujui dan membayar); potongan keterlambatan bertingkat; audit log; peran dan hak akses; siaran pengumuman.

**Modul tambahan di backend:** keuangan dan treasury, CRM dan kampanye email, penjualan dan pengadaan, produksi, gudang dan aset tetap, persuratan, proyek dan anggaran proyek, kinerja, rekrutmen dan pembelajaran, webhook keluar. Setiap modul dapat diaktifkan per perusahaan lewat sistem lisensi modul.

Rincian dan batasannya ada di [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). Contoh pekerjaan yang aman dikerjakan ada di [docs/VIBE_CODING.md](docs/VIBE_CODING.md).

## 5. Peta kode absensi

| Bagian | Lokasi |
|---|---|
| Rute API | `backend/routes/api.php` (cari `attendances`, `face-setup`, `face-approvals`) |
| Absen masuk/pulang | `backend/app/Http/Controllers/Api/V1/AttendanceController.php` |
| Logika absensi (shift, telat, geofence, rekap) | `backend/app/Services/AttendanceService.php` |
| Verifikasi wajah dan skor curiga | `backend/app/Services/FaceVerificationService.php`, `config/face.php` |
| Klien face-engine | `backend/app/Services/FaceEngineClient.php`, `app/Services/Face/` |
| Pendaftaran dan persetujuan wajah | `AuthController::faceSetup`, `FaceApprovalService`, `FaceApprovalController` |
| Face-engine (deteksi, liveness, antispoof) | `backend/ml-face/server.cjs` |
| Model | `backend/app/Models/Attendance.php`, `User.php`, `Shift.php`, `OfficeLocation.php` |
| Payroll | `backend/app/Services/PayrollService.php` |
| Halaman absen karyawan | `apps/attendance/src/pages/AttendancePage.tsx`, `FaceSetupPage.tsx` |
| Halaman admin HR | `apps/attendance/src/pages/admin/` |
| Panggilan API dari aplikasi | `apps/attendance/src/api/` |

Pola kode: **Controller tipis → Service → Model.** Logika bisnis diletakkan di `app/Services/`, bukan di controller.

## 6. Uji otomatis

```powershell
cd backend
php -d memory_limit=1G vendor/bin/pest --group=api                  # semua uji API (~1 menit)
php -d memory_limit=1G vendor/bin/pest --filter=AttendanceApiTest   # satu berkas
cd ..\apps\attendance; npx tsc -b                                   # cek tipe TypeScript
cd apps\attendance; npm run build                                   # pastikan build produksi berhasil
```

Skrip `npm run lint` di aplikasi absensi saat ini belum berfungsi (ESLint 9 tidak menemukan berkas konfigurasi `eslint.config.js`), jadi pemeriksaan otomatis sisi frontend hanya `tsc` dan `build`.

`memory_limit=1G` diperlukan karena batas bawaan PHP di Windows (128 MB) tidak cukup untuk seluruh suite. Hasil terakhir (21 Oktober 2026, setelah lima uji geofence ditambahkan): 477 lulus, 1 dilewati (uji konfigurasi server yang tidak ikut paket), dan 5 gagal karena lingkungan penguji, bukan karena kode:

- Empat uji di `InviteTokenApiTest` memakai kata sandi `newpassword123` dan sejenisnya, sedangkan aplikasi menolak kata sandi yang pernah bocor lewat pemeriksaan ke API publik. Pada mesin yang bisa menjangkau API tersebut, keempatnya gagal. Abaikan, atau jalankan uji tanpa internet.
- Satu uji di `HardwarePushSecurityTest` memakai fungsi yang baru ada di PHP 8.4, jadi gagal di PHP 8.3 dan lulus di 8.4.

Daftar lengkap temuan dan statusnya ada di [docs/TEMUAN.md](docs/TEMUAN.md).

Setiap perubahan absensi wajib disertai uji di `backend/tests/Feature/Api/`. Lihat `AttendanceApiTest.php` dan `FaceAuditTest.php` sebagai contoh.

## 7. Aturan penting

- **Jangan arahkan ke server produksi.** `VITE_API_BASE_URL` harus `http://127.0.0.1:8000`, bukan `app-erp.ibereality.com` (berisi data karyawan asli).
- **Jangan ubah alur login atau SSO portal.** Di laptop login langsung (SSO dimatikan lewat `VITE_REQUIRE_SSO=false`); di server login lewat portal.
- **Jangan menyimpan sandi, token, atau `.env`** ke dalam kode, git, maupun obrolan. `KREDENSIAL-LOKAL.txt` hanya untuk laptop Anda.
- **Data wajah adalah data biometrik.** Jangan pakai foto orang lain tanpa izin; untuk uji pakai wajah sendiri. Jangan komit `storage/` atau berkas SQLite.
- Kode di sini adalah `main`; versi di server sedikit berbeda pada beberapa bagian (terutama penyimpanan media dan foto). Karena itu **perubahan tidak dipasang langsung ke server**: kirim balik untuk ditinjau dan diuji lebih dulu.
- Folder `backend/public/admin-v2` hanya berisi bundel panel admin yang sudah jadi. Sumbernya tidak ada di paket ini.

## 8. Alur kerja git dan mengirim perubahan

Repositori ini sudah berisi commit awal. Kerjakan setiap perubahan di cabang tersendiri:

```bash
git switch -c fitur/nama-singkat
# ... ubah, uji ...
git add -A
git commit -m "fitur: deskripsi singkat"
```

Untuk mengirim perubahan ke tim IBE sebagai patch (cocok bila mereka belum memakai repositori ini):

```bash
git format-patch <commit-awal>..HEAD -o patch-hris
```

Zip folder `patch-hris` dan kirim bersama penjelasan singkat apa yang diubah dan cara mengujinya. `.gitignore` di akar sudah menutup `.env`, `KREDENSIAL-LOKAL.txt`, berkas SQLite, `node_modules/`, dan `vendor/`.

## 9. Pemecahan masalah singkat

| Gejala | Penyebab dan solusi |
|---|---|
| "Your Composer dependencies require a PHP version >= 8.4.0" | PHP Anda di bawah 8.4. Pasang 8.4. |
| "Layanan verifikasi wajah sedang tidak tersedia" | Face-engine belum jalan. Jalankan `node ml-face/server.cjs` di `backend`. |
| "Pendaftaran wajah Anda masih menunggu persetujuan admin" | Setujui lewat menu Approval Wajah sebagai admin. |
| Kamera tidak menyala | Buka lewat `http://localhost:5173`, bukan alamat IP. Beri izin kamera. |
| Seeder menolak berjalan | Set `IBE_ALLOW_DEMO_ADMIN_SEEDING=YES-I-UNDERSTAND` dan tiga sandi demo (minimal 20 karakter) di `backend/.env`. |
| Build frontend gagal dengan pesan "Expected exactly two third-party browser fallbacks" | Pemeriksaan di `vite.config.ts` mendeteksi perubahan jumlah URL cadangan `http://localhost` pada bundel, misalnya setelah menambah atau memperbarui pustaka. Telaah penyebabnya dengan tim sebelum mengubah angka pemeriksaan. |
| Uji kehabisan memori | Jalankan Pest dengan `php -d memory_limit=1G`. |
| Skrip setup berhenti dengan "Ekstensi PHP 'intl' belum aktif" (atau gd, zip, dst.) | Ekstensi belum dinyalakan di `php.ini`. Jalankan `php --ini` untuk melihat berkas mana yang dibaca, hapus tanda `;` di depan `extension=intl` (dan ekstensi lain yang diminta), pastikan `extension_dir` menunjuk ke folder `ext` milik PHP itu, lalu buka PowerShell baru. Periksa dengan `php -r "var_dump(extension_loaded('intl'));"`. Bila `php_intl.dll` tidak ada di folder `ext`, pasang PHP 8.4 Thread Safe (zip) dari windows.php.net atau Laravel Herd. |
| Setelah login, layar kembali ke halaman login | Bug versi lama (tujuan bawaan `/platforms` dialihkan ke `/login`), sudah diperbaiki. Pada versi lama, buka `http://localhost:5173/dashboard` setelah login. |
| Pesan galat validasi berbahasa Inggris | Tambahkan `APP_LOCALE=id` di `backend/.env` yang sudah ada (skrip setup hanya menulisnya untuk instalasi baru), lalu `php artisan config:clear`. |
| Kamera: "Requested device not found" | Peramban tidak menemukan kamera: komputer tanpa webcam (misalnya PC desktop atau desktop jarak jauh), kamera dimatikan di Settings → Privacy & security → Camera, atau sedang dipakai aplikasi lain. |
| Kamera: "backend 'wasm' has not yet been initialized" | Bug versi lama bila WebGL tidak tersedia; sudah diperbaiki ([docs/TEMUAN.md](docs/TEMUAN.md) T13). Perbarui ke versi terbaru. |
| "Lokasi Anda tidak terbaca" saat absen | Ada lokasi kantor aktif, tetapi koordinat tidak terkirim. Izinkan akses lokasi di peramban. |
| Kode QR di Edit Profil tidak tampil | Diketahui, hanya saat lokal; lihat [docs/TEMUAN.md](docs/TEMUAN.md) T3. |

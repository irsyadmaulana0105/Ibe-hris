# IBE HRIS / Absensi — paket pengembangan

Paket ini berisi kode **backend HRIS** (Laravel 13, PHP 8.4) dan **aplikasi absensi**
(React 19 + Vite) agar bisa dijalankan dan diubah di laptop sendiri. Semua berjalan
**lokal** dengan database SQLite dan data contoh — tidak ada koneksi ke server IBE.

Versi kode: branch `main` per 20 Okt 2026.

```
IBE-HRIS-dev/
├── setup-lokal.ps1      ← setup otomatis (Windows)
├── backend/             ← API HRIS: absensi, karyawan, cuti, lembur, reimburse, payroll
│   └── ml-face/         ← face-engine (Node, pengenal wajah)
└── apps/attendance/     ← aplikasi absensi (karyawan + menu admin HR)
```

## 1. Yang perlu dipasang

| Alat | Versi | Catatan |
|---|---|---|
| PHP | 8.4 (min. 8.3) | Paling mudah: [Laravel Herd](https://herd.laravel.com) atau `winget install PHP.PHP.8.4` |
| Ekstensi PHP | pdo_sqlite, mbstring, intl, gd, zip, fileinfo, bcmath, curl, openssl | Aktifkan di `php.ini` bila belum |
| Composer | 2.x | https://getcomposer.org/download/ |
| Node.js | 22 LTS | https://nodejs.org |

## 2. Setup (sekali)

```powershell
powershell -ExecutionPolicy Bypass -File .\setup-lokal.ps1
```

Skrip ini: `composer install`, membuat `backend/.env` lokal, migrasi database SQLite,
mengisi data contoh, lalu `npm install` untuk face-engine dan aplikasi absensi.
Sandi akun contoh dibuat **acak** dan ditulis ke `KREDENSIAL-LOKAL.txt`.

Akun yang dibuat (hanya ada di laptop Anda):

| Akun | Untuk |
|---|---|
| `superadmin@example.com` | Semua menu |
| `admin@example.com` | Admin HR: persetujuan wajah, rekap absensi, karyawan |
| `karyawan@example.com` | Mencoba absen masuk/pulang, daftar wajah, cuti, lembur |

Mac/Linux: jalankan langkah yang sama secara manual — lihat isi `setup-lokal.ps1`
(urutannya: `composer install` → salin `.env.example` ke `.env` + ubah nilai lokal →
`php artisan key:generate` → `touch database/database.sqlite` → `php artisan migrate` →
`php artisan db:seed --class=LocalHrisDevSeeder` → `npm install` di `backend` dan
`apps/attendance`).

## 3. Menjalankan

Buka 3 terminal:

```powershell
cd backend;          php artisan serve --host=127.0.0.1 --port=8000   # API
cd backend;          node ml-face/server.cjs                          # face-engine :3899
cd apps\attendance;  npm run dev                                      # http://localhost:5173
```

Alur mencoba absen wajah:
1. Login sebagai **karyawan** → menu **Daftar Wajah** → ambil foto.
2. Login sebagai **admin** (jendela samaran/peramban lain) → **Approval Wajah** → Setujui.
3. Kembali ke karyawan → **Absen Masuk**.

Kamera hanya jalan di `localhost` atau HTTPS. Geofence tidak aktif selama belum ada
lokasi kantor yang dibuat, jadi absen bisa dari mana saja.

## 4. Peta kode absensi

| Bagian | Lokasi |
|---|---|
| Rute API | `backend/routes/api.php` (cari `attendances`, `face-setup`, `face-approvals`) |
| Absen masuk/pulang | `backend/app/Http/Controllers/Api/V1/AttendanceController.php` |
| Logika absensi (shift, telat, geofence, rekap) | `backend/app/Services/AttendanceService.php` |
| Verifikasi wajah & skor curiga | `backend/app/Services/FaceVerificationService.php`, `config/face.php` |
| Klien face-engine | `backend/app/Services/FaceEngineClient.php`, `app/Services/Face/` |
| Pendaftaran & persetujuan wajah | `AuthController::faceSetup`, `FaceApprovalService`, `FaceApprovalController` |
| Face-engine (deteksi, liveness, antispoof) | `backend/ml-face/server.cjs` |
| Model | `backend/app/Models/Attendance.php`, `User.php`, `Shift.php`, `OfficeLocation.php` |
| Halaman absen karyawan | `apps/attendance/src/pages/AttendancePage.tsx`, `FaceSetupPage.tsx` |
| Halaman admin HR | `apps/attendance/src/pages/admin/` (`AdminAttendancePage`, `AdminFacePage`, …) |
| Panggilan API dari aplikasi | `apps/attendance/src/api/` |

Pola kode: **Controller tipis → Service → Model**. Logika bisnis taruh di `app/Services/`,
bukan di controller.

## 5. Uji otomatis

```powershell
cd backend
php -d memory_limit=1G vendor/bin/pest --group=api                  # semua uji API (~1 menit)
php -d memory_limit=1G vendor/bin/pest --filter=AttendanceApiTest   # satu berkas
cd ..\apps\attendance; npx tsc -b                                   # cek tipe TypeScript
```

`memory_limit=1G` perlu karena batas bawaan PHP di Windows (128 MB) tidak cukup untuk
seluruh suite. Hasil yang diharapkan pada paket ini: 477 lulus, 1 dilewati (uji
konfigurasi server yang tidak ikut paket).

Setiap perubahan absensi wajib disertai uji di `backend/tests/Feature/Api/`.
Lihat `AttendanceApiTest.php` dan `FaceAuditTest.php` sebagai contoh.

## 6. Aturan penting

- **Jangan arahkan ke server produksi.** `VITE_API_BASE_URL` harus
  `http://127.0.0.1:8000`, bukan `app-erp.ibereality.com` (berisi data karyawan asli).
- **Jangan ubah alur login/SSO portal.** Di laptop login langsung (SSO dimatikan lewat
  `VITE_REQUIRE_SSO=false`); di server login lewat portal.
- **Jangan menyimpan sandi, token, atau `.env`** ke dalam kode / git / chat.
  `KREDENSIAL-LOKAL.txt` hanya untuk laptop Anda.
- **Data wajah = data biometrik.** Jangan pakai foto orang lain tanpa izin; untuk uji
  pakai wajah sendiri.
- Kode di sini adalah `main`; versi yang berjalan di server sedikit berbeda di beberapa
  bagian (terutama penyimpanan media/foto). Karena itu **perubahan tidak dipasang
  langsung ke server** — kirim balik untuk ditinjau dan diuji dulu.

## 7. Mengirim perubahan

Sebelum mulai mengubah apa pun, jadikan folder ini repo git supaya perubahan
terlacak:

```powershell
git init; git add -A; git commit -m "awal: paket HRIS 20 Okt 2026"
```

`.env`, `vendor/`, `node_modules/` dan database lokal sudah diabaikan oleh
`.gitignore` di `backend/` dan `apps/attendance/`. Setelah selesai, kirim patch:

```powershell
git format-patch <commit-awal>..HEAD -o patch-hris
```

lalu zip folder `patch-hris` dan kirim ke tim IBE, beserta penjelasan singkat apa
yang diubah dan cara mengujinya.

# CLAUDE.md

Konteks untuk asisten AI yang bekerja di repositori ini. Dokumen lengkap ada di `docs/`; berkas ini hanya ringkasan aturan dan peta.

## Apa ini

HRIS IBE Reality: API Laravel 13 (PHP 8.4, multi-tenant per perusahaan) di `backend/`, aplikasi React 19 + Vite + TypeScript di `apps/attendance/`, dan layanan pengenal wajah Node di `backend/ml-face/`. Backend juga memuat modul ERP lain (Keuangan, Marketing/CRM, Production, Produksi, Warehouse, Surat). Berjalan lokal dengan SQLite dan data contoh.

Baca sesuai kebutuhan: `docs/ARCHITECTURE.md` (alur dan hak akses), `docs/DATA_MODEL.md` (tabel), `docs/VIBE_CODING.md` (resep tugas).

## Aturan yang tidak boleh dilanggar

1. Jangan arahkan aplikasi ke produksi. `VITE_API_BASE_URL` harus `http://127.0.0.1:8000`; jangan pernah isi `app-erp.ibereality.com`.
2. Jangan ubah alur login atau SSO portal.
3. Jangan menulis kata sandi, token, atau isi `.env` ke kode, komit, atau keluaran. `KREDENSIAL-LOKAL.txt` tidak boleh masuk komit.
4. Data wajah adalah biometrik: jangan komit foto, `storage/`, atau berkas SQLite; jangan menaruh descriptor wajah di log atau respons.
5. Jangan mengedit migrasi lama. Selalu buat migrasi baru.
6. Setiap perubahan absensi harus disertai uji di `backend/tests/Feature/Api/`.
7. Jangan mengubah ambang atau mode verifikasi wajah (`config/face.php`) tanpa diminta.
8. Perubahan tidak dipasang ke server. Tugas selesai berarti diff siap ditinjau dan diuji, bukan dideploy.

## Konvensi kode

- Pola: **Controller tipis → Service → Model.** Logika bisnis di `backend/app/Services/`. Validasi inline di controller (belum ada kelas `FormRequest`). Keluaran JSON lewat `app/Http/Resources/`; respons sukses memakai `data` dan `message`.
- Multi-tenant: model bertenant memakai `CompanyScope`/`BelongsToCompany`. Kueri yang menembus scope (`withoutGlobalScopes()`) harus menyaring `company_id` secara eksplisit.
- `users.id` adalah ULID. Referensi ke pengguna di migrasi baru: `foreignUlid`.
- Hak akses ada dua lapis yang harus sinkron: `User::capabilities()` (lama) dan RBAC enterprise (`config/enterprise_roles.php`, mode `ENTERPRISE_RBAC_MODE`). Rute admin memakai middleware `admin`, `enterprise.capability:<baru>,<lama>`, dan `module:<kunci>`. Penjagaan frontend (`AdminRoute`) hanya tampilan.
- Rute yang mengubah data keuangan atau alur kerja memakai middleware `idempotency`.
- Perubahan kolom ENUM di migrasi dibungkus `if (DB::getDriverName() !== 'sqlite')`. SQLite lokal tidak menegakkan ENUM, jadi uji nilai status juga secara logika.
- Frontend: halaman dimuat `lazy` di `src/App.tsx`; satu modul API per domain di `src/api/`; alias `@/` = `src/`; data server lewat TanStack Query. Bahasa antarmuka dan pesan galat: Indonesia.
- Komentar dan teks pengguna ditulis dalam bahasa Indonesia yang jelas; ikuti gaya berkas di sekitarnya.
- Geofence gagal-tertutup: bila ada lokasi kantor aktif, koordinat yang hilang ditolak, bukan dilewati. Jangan mengembalikan perilaku lama di `AttendanceService::checkGeofence`.
- Cermin kamera hanya tampilan (CSS). Jangan membalik gambar yang dikirim ke server; itu mengubah data pencocokan wajah.
- Tema: sidebar selalu hijau tua di kedua tema. Setiap warna teks baru harus diukur kontrasnya (≥ 4,5:1) di tema terang dan malam.

## Perintah

```bash
# Backend (dari backend/)
php artisan serve --host=127.0.0.1 --port=8000
node ml-face/server.cjs                                   # face-engine :3899
php -d memory_limit=1G vendor/bin/pest --group=api        # semua uji API
php -d memory_limit=1G vendor/bin/pest --filter=AttendanceApiTest
php artisan migrate --force && php artisan db:seed --class=LocalHrisDevSeeder --force

# Frontend (dari apps/attendance/)
npm run dev          # http://localhost:5173
npx tsc -b           # cek tipe
npm run build        # build produksi
```

`npm run lint` belum berfungsi (tidak ada `eslint.config.js`). Satu uji bisa gagal karena lingkungan, bukan regresi: `HardwarePushSecurityTest` (butuh PHP 8.4). Kata sandi di uji harus unik, bukan yang umum seperti `password123`: aplikasi menolak kata sandi yang tercatat bocor (pemeriksaan ke API publik), dan uji akan gagal bila ada internet. Daftar temuan dan statusnya ada di `docs/TEMUAN.md`; cek di sana sebelum memperbaiki sesuatu yang mungkin sudah tercatat.

## Peta cepat

| Butuh | Lihat |
|---|---|
| Rute API | `backend/routes/api.php`, `routes/api/*.php`, `routes/private_media.php` |
| Absensi (shift, telat, geofence) | `app/Services/AttendanceService.php` |
| Wajah | `FaceVerificationService`, `FaceApprovalService`, `FaceEngineClient`, `config/face.php`, `ml-face/server.cjs` |
| Payroll | `PayrollService`, `PayrollFormulaEvaluator`, `AdminPayrollService` |
| Hak akses | `User.php` (`capabilities`), `EnterpriseAuthorizationService`, `app/Http/Middleware/` |
| Lisensi modul | `ModuleEntitlementService`, `config/saas_modules.php` |
| Tabel dan skema | `backend/database/migrations/`, `docs/DATA_MODEL.md` |
| Masalah yang sudah diketahui | `docs/TEMUAN.md` |
| Halaman React | `apps/attendance/src/pages/` (karyawan), `pages/admin/` (admin) |
| Contoh pola pengajuan | Cuti: `LeaveController`, `LeaveService`, `LeaveApiTest`, `LeavePage.tsx`, `AdminLeavePage.tsx` |

## Hal yang bukan bagian paket ini

`backend/public/admin-v2` (panel admin) hanya bundel jadi tanpa sumber. Portal SSO, server email, R2, Peruri, dan konfigurasi server tidak tersedia. Jangan menebak isinya; tanyakan.

## Cara bekerja

- Untuk perubahan lebih dari tiga berkas, tunjukkan rencana (daftar berkas) dan tunggu persetujuan.
- Tiru pola berkas yang sudah ada; jangan memperkenalkan pustaka atau arsitektur baru tanpa alasan yang disebutkan.
- Jangan merapikan kode di luar tugas.
- Jalankan uji yang relevan dan laporkan hasil sebenarnya, termasuk yang gagal.
- Bila ragu apakah sesuatu menyentuh gaji, biometrik, atau hak akses, berhenti dan tanyakan.

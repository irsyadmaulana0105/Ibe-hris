# Panduan Vibe Coding untuk IBE HRIS

Panduan ini membantu Anda mengembangkan HRIS bersama asisten AI (misalnya Claude Code) dengan cara yang cepat tetapi tidak merusak sistem yang menyimpan data karyawan. Bacalah bagian 1 dan 2 lebih dulu, lalu gunakan bagian 3 sampai 5 sebagai contekan saat bekerja.

## 1. Apa yang bisa Anda lakukan dengan paket ini

Paket ini adalah salinan lengkap kode backend dan aplikasi absensi, berjalan lokal dengan SQLite dan data contoh. Berikut pemetaan jenis pekerjaan berdasarkan tingkat risikonya.

### Aman dan cocok untuk dimulai

| Pekerjaan | Lokasi utama |
|---|---|
| Mengubah tampilan, teks, label, urutan kolom, filter, dan validasi sisi klien | `apps/attendance/src/pages/`, `components/` |
| Menambah halaman atau menu baru yang memakai API yang sudah ada | `apps/attendance/src/App.tsx`, `src/api/`, `src/pages/` |
| Menambah ekspor CSV atau rekap baru dari data yang sudah ada | `AttendanceService::exportAdminRecap` sebagai contoh |
| Menambah kolom data karyawan atau master data baru | migrasi + `User.php` + `UserResource` + form di `AdminEmployeePage.tsx` |
| Memperbaiki pesan galat dan teks Indonesia | controller, service, `lang/` |
| Menulis uji tambahan | `backend/tests/Feature/Api/` |

### Perlu ketelitian (selalu minta uji dan tinjauan)

| Pekerjaan | Mengapa berisiko |
|---|---|
| Jenis pengajuan baru (seperti cuti atau lembur) | Menyentuh rute, hak akses, notifikasi, dan sering payroll. Salin pola `Leave`. |
| Aturan absensi: shift, toleransi telat, geofence | Mempengaruhi gaji seluruh karyawan. Ada di `AttendanceService`. |
| Perhitungan payroll dan potongan | Uang sungguhan. Ada di `PayrollService` dan `PayrollFormulaEvaluator`. |
| Kapabilitas dan peran baru | Ada dua sistem hak akses yang harus tetap sinkron (lihat ARCHITECTURE bagian 3.5). |
| Migrasi pada tabel `users`, `attendances`, `payrolls` | Tabel besar dan sensitif; ENUM berperilaku beda antara SQLite dan MySQL. |
| Verifikasi wajah, ambang kecocokan, mode liveness | Data biometrik dan keputusan keamanan. Jangan ubah ambang tanpa data nyata. |

### Di luar jangkauan paket ini

- Panel admin `public/admin-v2` (hanya bundel jadi, sumbernya tidak disertakan).
- Portal SSO, server email (Stalwart), Cloudflare R2, Peruri, dan konfigurasi server produksi.
- Data karyawan sungguhan. Semua pengujian memakai data contoh.
- Memasang perubahan ke server. Perubahan dikirim kembali untuk ditinjau dan diuji tim IBE dahulu.

## 2. Aturan main yang tidak boleh dilanggar

Aturan ini juga tercantum di README dan `CLAUDE.md`, sehingga asisten AI ikut membacanya.

1. **Jangan arahkan aplikasi ke produksi.** `VITE_API_BASE_URL` harus `http://127.0.0.1:8000`.
2. **Jangan ubah alur login atau SSO portal.**
3. **Jangan menaruh kata sandi, token, atau `.env` ke kode, git, maupun obrolan.** Berkas `KREDENSIAL-LOKAL.txt` dan `backend/.env` tidak boleh masuk repo.
4. **Wajah adalah data biometrik.** Uji dengan wajah sendiri. Jangan komit foto, `storage/`, atau berkas SQLite.
5. **Setiap perubahan absensi wajib disertai uji** di `backend/tests/Feature/Api/`.
6. **Jangan mengedit migrasi lama.** Buat migrasi baru.

## 3. Alur kerja yang disarankan

```
1. Buat cabang       git switch -c fitur/nama-singkat
2. Jelaskan tugas    gunakan templat di bagian 5; sebutkan berkas contoh yang harus ditiru
3. Jalankan          3 terminal: API, face-engine, frontend (lihat README)
4. Coba sendiri      login sebagai karyawan, lalu sebagai admin
5. Uji otomatis      pest --filter=NamaTest   dan   npx tsc -b
6. Tinjau diff       git diff, pastikan hanya berkas yang relevan berubah
7. Komit kecil       satu perubahan logis per komit
```

Beberapa kebiasaan yang membuat vibe coding di proyek sebesar ini tetap aman:

- **Beri asisten contoh, bukan hanya tujuan.** Kalimat seperti "buat pengajuan WFH, tiru persis pola Cuti di `LeaveController`, `LeaveService`, `LeavePage.tsx`" menghasilkan kode yang selaras dengan gaya proyek.
- **Minta rencana dulu untuk perubahan lebih dari tiga berkas**, baru setujui, lalu minta dikerjakan.
- **Selalu minta asisten menjalankan uji** dan melaporkan hasil sebenarnya, bukan perkiraan.
- **Batasi cakupan.** "Ubah hanya `AdminLeavePage.tsx`" lebih baik daripada "perbaiki halaman cuti".
- **Jangan biarkan asisten "merapikan" kode di luar tugas.** Perubahan acak sulit ditinjau tim IBE.

## 4. Resep tugas umum

### 4.1 Menambah kolom pada data karyawan

1. Migrasi baru: `php artisan make:migration add_xxx_to_users_table`. Gunakan `Schema::hasColumn` agar aman dijalankan ulang.
2. Tambahkan nama kolom ke `$fillable` di `app/Models/User.php` (dan `$hidden` bila sensitif).
3. Tampilkan lewat `app/Http/Resources/UserResource.php` atau `AdminEmployeeResource.php`.
4. Terima dan validasi di `EmployeeController` / `EmployeeService`.
5. Tambahkan tipe di `apps/attendance/src/types/index.ts` dan field di form `AdminEmployeePage.tsx`.
6. Uji di `EmployeeApiTest.php`.

### 4.2 Menambah jenis pengajuan (contoh: WFH)

Tiru Cuti. Berkas yang menjadi cetakannya:

| Lapisan | Berkas contoh |
|---|---|
| Migrasi dan model | `database/migrations/*_create_leaves_table.php`, `app/Models/Leave.php` |
| Rute | `routes/api.php` (cari `/leaves` untuk sisi karyawan dan `admin/leaves` untuk persetujuan) |
| Controller dan service | `LeaveController.php`, `app/Services/LeaveService.php`, `LeaveResource.php` |
| Hak akses | kapabilitas `leave.manage` di `User::capabilities()`; pembatasan `permanent` di rute |
| Frontend | `src/api/leave.ts`, `pages/employee/LeavePage.tsx`, `pages/admin/AdminLeavePage.tsx`, rute di `App.tsx` |
| Uji | `tests/Feature/Api/LeaveApiTest.php` |

Putuskan sejak awal: apakah hanya karyawan tetap, siapa yang menyetujui, apakah memengaruhi payroll, dan apakah perlu notifikasi.

### 4.3 Menambah halaman admin

1. Tambahkan fungsi API di `src/api/admin.ts` (atau berkas domain baru).
2. Buat `pages/admin/AdminXxxPage.tsx` memakai komponen `AdminPageHero`, `AdminTableCard`, `AdminSummaryCard`, `Pagination`.
3. Daftarkan rute di `App.tsx` dengan `<AdminRoute capability="...">`.
4. Pastikan endpoint backend memakai middleware `admin` dan kapabilitas yang sama. Penjagaan frontend saja tidak cukup.

### 4.4 Mengubah aturan keterlambatan atau shift

Logika ada di `AttendanceService::clockIn` (status `present`/`late`/`off_shift`) dan pencarian shift di `getShiftForUser`. Potongan keterlambatan dihitung di `PayrollService::calcLateTotalWithTiers`. Ubah keduanya secara konsisten, lalu perbarui `AttendanceApiTest` dan `PayrollApiTest`. Beritahu tim IBE karena aturan ini terkait pasal kontrak kerja.

### 4.5 Menambah kapabilitas baru

1. Tambahkan string kapabilitas ke peran yang berhak di `User::capabilities()`.
2. Bila RBAC enterprise dipakai, tambahkan juga ke katalog peran di `config/enterprise_roles.php` (kapabilitas dokumen ada di `app/Support/EnterpriseDocumentRbacV1.php`), lalu provisikan ulang lewat `EnterpriseRbacProvisioningService` / `EnterpriseRbacSeeder` agar tabel `rbac_*` terisi.
3. Pasang pada rute: `->middleware('enterprise.capability:domain.aksi,kapabilitas.lama')`.
4. Cerminkan di frontend dengan `capability="..."` pada `AdminRoute`.
5. Tulis uji hak akses (lihat `RoleScenarioMatrixApiTest`, `EnterpriseAuthorizationPolicyTest`).

### 4.6 Menyelidiki masalah absen wajah

| Gejala | Periksa |
|---|---|
| "Layanan verifikasi wajah sedang tidak tersedia" | Face-engine hidup? `curl http://127.0.0.1:3899/health` |
| "Menunggu persetujuan admin" | Login admin, buka Approval Wajah, setujui. |
| "Wajah tidak cocok" | Ambang `FACE_MATCH_SIMILARITY_THRESHOLD`, pencahayaan, jumlah wajah di foto. |
| Absen tidak mau masuk karena lokasi | Ada `office_locations` aktif? Tanpa lokasi kantor, geofence tidak aktif. |
| Status `off_shift` | Jam absen di luar jendela shift ±60 menit, atau shift salah. |

## 5. Templat perintah untuk asisten AI

Salin, lalu isi bagian dalam tanda kurung.

**Fitur baru**

```
Baca CLAUDE.md dan docs/ARCHITECTURE.md terlebih dahulu.
Tugas: [deskripsi fitur dalam satu atau dua kalimat].
Tiru pola dari: [berkas atau fitur contoh].
Batasan: hanya ubah [daftar berkas/folder]; jangan ubah alur login, payroll, atau verifikasi wajah.
Sebelum menulis kode, tunjukkan rencana berupa daftar berkas yang akan diubah, lalu tunggu persetujuan saya.
Setelah selesai, jalankan uji yang relevan dan laporkan hasil sebenarnya.
```

**Perbaikan bug**

```
Gejala: [apa yang terjadi, di halaman mana, sebagai peran apa].
Harapan: [perilaku yang benar].
Temukan akar masalahnya dan jelaskan sebelum mengubah apa pun.
Perbaikan harus minimal, disertai satu uji yang gagal sebelum dan lulus sesudah perbaikan.
```

**Tinjauan sebelum mengirim**

```
Tinjau git diff saya terhadap commit awal. Cari: pelanggaran aturan di CLAUDE.md,
kebocoran data sensitif (wajah, gaji, kata sandi), rute tanpa middleware hak akses,
kueri yang melewati CompanyScope, migrasi yang mengubah ENUM tanpa penjagaan SQLite,
dan uji yang hilang. Laporkan temuan berurutan dari yang paling serius.
```

**Memahami kode**

```
Jelaskan bagaimana [alur, misalnya "pengajuan lembur sampai masuk slip gaji"] bekerja
di kode ini. Sebutkan berkas dan fungsi yang terlibat berurutan, dan tandai bagian yang
tidak pasti daripada menebak.
```

## 6. Daftar periksa sebelum mengirim perubahan

- [ ] `php -d memory_limit=1G vendor/bin/pest --group=api` lulus (hasil dasar paket: 477 lulus, 1 dilewati).
- [ ] `npx tsc -b` di `apps/attendance` bersih, dan `npm run build` berhasil. (`npm run lint` saat ini tidak bisa dipakai, lihat bagian 7.)
- [ ] Tidak ada berkas `.env`, `*.sqlite`, `KREDENSIAL-LOKAL.txt`, foto, atau `storage/` di `git status`.
- [ ] Rute baru punya middleware hak akses di backend, bukan hanya di frontend.
- [ ] Kueri baru tetap melewati `CompanyScope` (atau menyaring `company_id` secara eksplisit).
- [ ] Migrasi baru, bukan hasil mengedit migrasi lama.
- [ ] Uji menyertai setiap perubahan absensi.
- [ ] Catatan singkat apa yang diubah dan cara mengujinya, siap dikirim ke tim IBE.

## 7. Gagasan pengembangan yang realistis

Daftar ini berasal dari pengamatan terhadap kode, bukan permintaan resmi tim IBE. Sebelum mengerjakannya, pastikan prioritasnya dengan mereka.

- **Perbaiki lint frontend.** Skrip `npm run lint` gagal karena ESLint 9 tidak menemukan `eslint.config.js` (proyek belum dimigrasikan dari format lama). Menambahkan konfigurasi flat akan mengaktifkan kembali pemeriksaan gaya dan aturan hooks.
- **Uji untuk aplikasi React.** `package.json` belum memiliki kerangka uji frontend; satu-satunya pemeriksaan otomatis adalah `tsc`. Menambah Vitest dan Testing Library untuk `client.ts` dan halaman absensi akan menaikkan keyakinan perubahan.
- **Dasbor tanda wajah untuk HR.** Skor liveness dan antispoof sudah disimpan di `attendances.face_check_in/out` dengan mode `shadow`. Halaman ringkasan yang menampilkan sebaran skor akan membantu menentukan ambang sebelum beralih ke `enforce`.
- **Rekap absensi yang lebih kaya.** Ekspor sudah ada; grafik tren keterlambatan per divisi dapat dibangun di atas `AttendanceService`.
- **Kalender shift massal.** `AdminShiftSchedulePage` dapat diperluas dengan penyalinan jadwal antarminggu.
- **Dokumentasi API.** Spesifikasi OpenAPI untuk `/api/v1` yang digenerate dari rute akan memudahkan integrasi.
- **Seeder data contoh yang lebih kaya.** Menambah beberapa karyawan, shift, dan riwayat absen contoh mempercepat pengembangan tampilan.

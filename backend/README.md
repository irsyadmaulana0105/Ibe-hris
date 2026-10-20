# Backend Guide

## Ringkasan

Backend ini memakai:

- Laravel 13 untuk API dan panel backend
- Vite untuk aset Blade/Livewire di `resources/`
- Node.js face engine gratis berbasis `@vladmandic/human`
- Verifikasi wajah berbasis face descriptor saat pendaftaran dan absensi

Alur validasi wajah saat ini:

1. User daftar wajah, backend meminta descriptor ke face engine.
2. Descriptor disimpan ke `face_descriptor_pending`.
3. Admin menyetujui data wajah.
4. Descriptor dipindah ke `face_descriptor`.
5. Saat absen, backend membandingkan selfie baru dengan descriptor yang sudah disetujui.

Jika user belum terdaftar, masih pending approval, selfie kosong, atau similarity di bawah threshold, absensi akan ditolak.

## Kebutuhan Server

- PHP 8.4
- Composer 2+
- Node.js 20+ dan npm
- Database yang sesuai dengan `.env`
- Web server seperti Nginx/Apache untuk production

## Struktur Penting

- `app/Services/FaceEngineClient.php`: client Laravel ke engine wajah
- `app/Services/FaceVerificationService.php`: validasi wajah untuk absensi
- `app/Http/Controllers/Api/V1/AuthController.php`: pendaftaran descriptor wajah
- `app/Http/Controllers/Api/V1/AttendanceController.php`: validasi wajah saat check-in/check-out
- `app/Http/Controllers/Api/V1/FaceApprovalController.php`: approval descriptor oleh admin
- `ml-face/server.cjs`: service Node.js untuk extract dan compare descriptor
- `config/face.php`: konfigurasi engine wajah

## Setup Lokal

1. Install dependency PHP.

```bash
composer install
```

2. Install dependency Node.

```bash
npm ci
```

3. Siapkan environment.

```bash
cp .env.example .env
php artisan key:generate
```

4. Atur database di `.env`, lalu jalankan migration.

```bash
php artisan migrate
```

5. Buat symlink storage bila diperlukan.

```bash
php artisan storage:link
```

## Menjalankan Saat Development

Jalankan backend Laravel:

```bash
php artisan serve
```

Jalankan watcher backend sekaligus face engine:

```bash
npm run dev
```

Command di atas akan menjalankan:

- watcher Vite backend
- face engine Human di `http://127.0.0.1:3899`

Kalau Anda hanya butuh watcher aset tanpa face engine:

```bash
npm run dev:vite
```

Kalau ingin menyalakan face engine saja:

```bash
npm run face:engine
```

Cek health engine:

```bash
curl http://127.0.0.1:3899/health
```

Jika status `ok`, engine siap dipakai.

## Build Backend

Build backend hanya untuk aset Laravel, bukan untuk PHP application dan bukan untuk face engine.

```bash
npm run build
```

Output build akan masuk ke `public/build/`.

Catatan penting:

- `public/build/` tidak masuk git.
- Jika deploy memakai `git pull`, maka `npm run build` harus dijalankan lagi di server.

## Deploy Production

Urutan deploy yang aman:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan storage:link
```

Kalau deploy lewat release artifact, Anda bisa build aset di local atau CI lalu upload hasil `public/build/`. Tapi kalau deploy dengan `git pull`, build aset tetap perlu dijalankan di VPS.

## Menjalankan Face Engine di Production

Face engine tidak ikut hidup otomatis hanya karena Laravel berjalan. Engine harus dijalankan sebagai process terpisah.

Command runtime:

```bash
npm run face:engine
```

### Opsi systemd

Contoh service `/etc/systemd/system/absen-face-engine.service`:

```ini
[Unit]
Description=Absen Face Engine
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/www/absen/backend
ExecStart=/usr/bin/npm run face:engine
Restart=always
RestartSec=5
User=www-data
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

Lalu jalankan:

```bash
sudo systemctl daemon-reload
sudo systemctl enable absen-face-engine
sudo systemctl start absen-face-engine
sudo systemctl status absen-face-engine
```

## Environment Penting

Tambahkan atau sesuaikan nilai berikut di `.env`:

```env
APP_URL=https://app-erp.ibereality.com
FRONTEND_URL=https://portal.ibereality.com

MAIL_MAILER=smtp
MAIL_HOST=smtp.hostanda.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=noreply@ibereality.com
MAIL_PASSWORD=isi_password_email
MAIL_TIMEOUT=20
MAIL_FROM_ADDRESS=noreply@ibereality.com
MAIL_FROM_NAME="${APP_NAME}"
PDF_MEMORY_LIMIT=256M
PDF_IMAGE_MAX_BYTES=3145728
PDF_IMAGE_MAX_PIXELS=6000000

FACE_ENGINE_URL=http://127.0.0.1:3899
FACE_ENGINE_TIMEOUT=20
FACE_MATCH_SIMILARITY_THRESHOLD=0.60
FACE_ENGINE_MAX_IMAGE_SIDE=320

HOLIDAY_API_ENABLED=true
HOLIDAY_API_TIMEOUT=8
```

Keterangan:

- `MAIL_MAILER`: gunakan `smtp` di production jika ingin email benar-benar terkirim
- `MAIL_ENCRYPTION`: biasanya `tls` untuk port `587`, atau `ssl` untuk port `465`
- `MAIL_TIMEOUT`: batasi waktu tunggu koneksi SMTP supaya request tidak terlalu lama
- jika `MAIL_MAILER=log`, email hanya ditulis ke log dan tidak dikirim ke inbox
- `PDF_MEMORY_LIMIT`: batas memori saat render slip PDF, berguna jika ada logo atau tanda tangan berukuran besar
- `PDF_IMAGE_MAX_BYTES`: batas ukuran file gambar logo/TTD yang boleh diproses untuk PDF
- `PDF_IMAGE_MAX_PIXELS`: batas total pixel gambar logo/TTD sebelum otomatis di-skip agar PDF tidak jebol memori
- `FACE_ENGINE_URL`: alamat engine yang dipanggil Laravel
- `FACE_ENGINE_TIMEOUT`: timeout request Laravel ke engine
- `FACE_MATCH_SIMILARITY_THRESHOLD`: ambang similarity match wajah
- `FACE_ENGINE_MAX_IMAGE_SIDE`: batas resize gambar di engine supaya hemat CPU dan RAM
- `HOLIDAY_API_ENABLED`: aktif/nonaktif ambil hari libur nasional dari API
- `HOLIDAY_API_TIMEOUT`: timeout request backend ke API hari libur

Variabel opsional untuk engine:

```env
FACE_ENGINE_HOST=127.0.0.1
FACE_ENGINE_PORT=3899
```

Saran untuk VPS kecil:

- biarkan engine di `127.0.0.1`
- pakai `FACE_ENGINE_MAX_IMAGE_SIDE=320`
- jangan naikkan threshold terlalu agresif tanpa testing
- jalankan engine sebagai service yang auto-restart
- aktifkan SMTP production supaya fitur kirim slip gaji benar-benar bisa jalan

## Endpoint Face Engine

Face engine menyediakan endpoint lokal:

- `GET /health`
- `POST /extract`
- `POST /compare`

Endpoint ini dipakai internal oleh Laravel. Sebaiknya jangan diekspos langsung ke publik kalau tidak diperlukan.

## Alur Verifikasi Wajah

### Pendaftaran wajah

1. Frontend mengirim `selfie_base64` ke backend.
2. Backend meminta descriptor ke face engine.
3. Descriptor disimpan ke `face_descriptor_pending`.
4. Admin menyetujui descriptor tersebut.
5. Descriptor aktif disimpan ke `face_descriptor`.

### Absensi

1. User mengirim selfie absensi.
2. Backend memastikan user sudah punya `face_descriptor`.
3. Jika masih pending approval, absensi ditolak.
4. Backend mengirim selfie baru ke engine untuk dihitung descriptor-nya.
5. Engine membandingkan descriptor baru dengan descriptor terdaftar.
6. Jika similarity di bawah threshold, absensi ditolak.

## Error yang Paling Sering Muncul

`Wajah Anda belum terdaftar. Silakan daftar wajah terlebih dahulu sebelum absen.`

- User belum punya descriptor aktif.

`Pendaftaran wajah Anda masih menunggu persetujuan admin. Anda belum bisa absen.`

- User sudah daftar, tapi admin belum approve.

`Wajah tidak cocok dengan data terdaftar. Absen ditolak.`

- Descriptor hasil selfie absensi tidak lolos threshold.

`Layanan verifikasi wajah sedang tidak tersedia. Jalankan engine Human terlebih dahulu.`

- Laravel tidak bisa menghubungi face engine.

`Wajah tidak terdeteksi. Pastikan wajah terlihat jelas di kamera.`

- Gambar terlalu gelap, blur, atau crop gagal.

`Terdeteksi lebih dari satu wajah. Pastikan hanya satu orang di kamera.`

- Ada lebih dari satu wajah pada frame yang dikirim.

## Troubleshooting Cepat

### Engine tidak bisa diakses

1. Pastikan process `npm run face:engine` hidup.
2. Pastikan `FACE_ENGINE_URL` sesuai dengan host dan port engine.
3. Cek health endpoint:

```bash
curl http://127.0.0.1:3899/health
```

### Setelah `git pull`, tampilan backend rusak

Biasanya aset belum dibuild ulang.

```bash
npm ci
npm run build
php artisan optimize
```

### User tetap tidak bisa absen setelah daftar wajah

Periksa hal berikut:

1. Apakah descriptor masih ada di `face_descriptor_pending` dan belum dipindahkan ke `face_descriptor`.
2. Apakah face engine sedang berjalan.
3. Apakah threshold terlalu ketat.
4. Apakah selfie absensi terlalu blur, gelap, atau berisi lebih dari satu wajah.

## Checklist Setelah Pull di VPS

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
sudo systemctl restart absen-face-engine
```

Kalau Anda ingin, panduan ini bisa dipecah lagi menjadi dua file terpisah:

- `README.md` untuk setup umum
- `DEPLOYMENT.md` untuk langkah deploy VPS

# Dokumentasi

Mulai dari [README utama](../README.md) untuk setup dan cara menjalankan. Dokumen di folder ini menjelaskan isi sistem lebih dalam.

| Dokumen | Dibaca saat | Isi |
|---|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Anda ingin memahami bagaimana sistem bekerja sebelum mengubahnya | Komponen, alur permintaan, autentikasi, empat lapis hak akses, alur absensi dan verifikasi wajah, payroll, penyimpanan berkas privat, penerapan, jebakan |
| [DATA_MODEL.md](DATA_MODEL.md) | Anda menambah kolom atau tabel, atau mencari di mana data disimpan | Konvensi, diagram relasi, tabel inti SDM, katalog modul lain, klasifikasi data sensitif, resep mengubah skema |
| [VIBE_CODING.md](VIBE_CODING.md) | Anda mulai bekerja bersama asisten AI | Pekerjaan yang aman, resep tugas umum, templat perintah, daftar periksa |
| [TEMUAN.md](TEMUAN.md) | Anda ingin tahu masalah yang sudah ditemukan, mana yang sudah diperbaiki, dan mana yang masih terbuka | Daftar temuan T1 sampai T13 dengan keparahan, penyebab, perbaikan, dan status |
| [../backend/docs/REST_WEBHOOK_API.md](../backend/docs/REST_WEBHOOK_API.md) | Anda membuat integrasi | Kontrak REST JSON, idempotensi, webhook bertanda tangan |
| [../backend/README.md](../backend/README.md) | Anda ingin tahu bagaimana backend dipasang di VPS | Setup, build, face-engine sebagai layanan, variabel lingkungan, troubleshooting |
| [../apps/attendance/README.md](../apps/attendance/README.md) | Anda mengerjakan aplikasi React | Teknologi, daftar rute, variabel lingkungan, target penerapan |

## Merawat dokumentasi

Dokumen ini disusun dari pembacaan kode pada `main` per 20 Oktober 2026. Perbarui bagian yang relevan dalam komit yang sama ketika Anda:

- menambah atau mengubah tabel dan status penting → `DATA_MODEL.md`
- mengubah alur login, middleware, atau hak akses → `ARCHITECTURE.md` bagian 3.1, 3.4, 3.5
- mengubah aturan absensi, wajah, atau payroll → `ARCHITECTURE.md` bagian 3.6 sampai 3.8
- menemukan perintah setup yang berubah → `README.md` dan `CLAUDE.md`

Beberapa rincian di `backend/README.md` dan `apps/attendance/README.md` berasal dari dokumen lama dan bisa berbeda dari kode (misalnya nama rute di README aplikasi). Bila berbeda, kode yang benar.

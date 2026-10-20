# Modul Produksi

Modul ini menampung layanan produksi dan skeleton Vendor Tracking Realtime. Detail domain dan data model mengacu ke `docs/MODULARIZATION_PHASE1.md` bagian 8.2.

## Status vendor tracking

Migration, model, token middleware, controller skeleton, dan route placeholder sudah tersedia. ERP sebelumnya sudah memiliki tabel dan endpoint `work_orders` untuk manufacturing, sehingga vendor tracking memakai tabel `vendor_work_orders` dan endpoint internal `/api/v1/vendor-work-orders` agar lifecycle manufacturing tidak berubah.

Full CRUD, upload foto, notifikasi, dan broadcast sengaja belum diimplementasikan. Keputusan arsitektur berikut harus direview terlebih dahulu:

- Realtime: SSE direkomendasikan untuk MVP karena one-way, ringan, dan tidak menambah service. Reverb tetap alternatif bila interaksi dua arah dibutuhkan.
- Portal vendor: pilih SPA terisolasi pada domain khusus atau halaman publik yang di-embed. Tidak ada SPA baru pada patch ini.

Route publik menggunakan magic token dan mengembalikan 404 untuk token invalid, expired, atau revoked. Sebelum produksi, token perlu dibuat dengan entropy tinggi dan kebijakan rotasi/audit yang disetujui.

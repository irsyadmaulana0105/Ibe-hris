<?php

return [

    /*
    | Kampanye email CRM.
    |
    | Dikirim lewat mail server sendiri (Stalwart, JMAP) sebagai mailbox bersama
    | `pengirim`. Batas harian sengaja kecil: IP server dipakai juga untuk email
    | operasional (slip gaji, persetujuan, webmail), jadi reputasinya tidak boleh
    | dikorbankan untuk email massal.
    |
    | transport: 'jmap' (produksi) | 'log' (uji/lokal - hanya ditulis ke log).
    | Ganti transport cukup lewat env, tanpa mengubah kode kampanye.
    */
    'kampanye' => [
        'transport' => env('CRM_KAMPANYE_TRANSPORT', 'jmap'),
        'pengirim' => env('CRM_KAMPANYE_PENGIRIM', 'marketing@ibereality.com'),
        'jmap_base' => env('CRM_KAMPANYE_JMAP_BASE', 'https://mail.ibereality.com'),

        // Total semua kampanye, semua perusahaan, per hari kalender WIB.
        'batas_harian' => (int) env('CRM_KAMPANYE_BATAS_HARIAN', 50),
        // Per eksekusi penjadwal (tiap 10 menit) - menyebar kiriman sepanjang hari.
        'per_putaran' => (int) env('CRM_KAMPANYE_PER_PUTARAN', 2),
        // Kirim uji ke alamat sendiri, per perusahaan per hari.
        'batas_uji_harian' => (int) env('CRM_KAMPANYE_BATAS_UJI', 10),

        // Jendela kirim (WIB, jam kerja Senin-Jumat). Email B2B di luar jam
        // kerja lebih sering diabaikan atau ditandai spam.
        'jam_mulai' => (int) env('CRM_KAMPANYE_JAM_MULAI', 8),
        'jam_selesai' => (int) env('CRM_KAMPANYE_JAM_SELESAI', 17),
        'hari_kerja_saja' => (bool) env('CRM_KAMPANYE_HARI_KERJA', true),
    ],
];
